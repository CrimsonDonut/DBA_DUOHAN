<?php

require __DIR__ . '/_common.php';

$pdo = connect();

/* ------------------------------------------------------------------ *
 * Table list (taken from the database, audit table kept read-only)
 * ------------------------------------------------------------------ */
$tableLabels = [
    'borrowers'           => 'Borrowers',
    'equipment'           => 'Equipment',
    'borrow_transactions' => 'Transactions',
    'borrow_items'        => 'Borrow Items',
];
$hiddenTables = ['borrow_items_audit']; // written by triggers only; see Reports

function getExistingTables(PDO $pdo, array $hidden, array $preferredOrder): array
{
    $stmt = $pdo->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    );
    $tables = array_values(array_diff($stmt->fetchAll(PDO::FETCH_COLUMN, 0), $hidden));

    usort($tables, function ($a, $b) use ($preferredOrder) {
        $pa = array_search($a, $preferredOrder, true);
        $pb = array_search($b, $preferredOrder, true);
        $pa = $pa === false ? 99 : $pa;
        $pb = $pb === false ? 99 : $pb;
        return $pa === $pb ? strcmp($a, $b) : $pa <=> $pb;
    });

    return $tables;
}

$allowedTables = getExistingTables($pdo, $hiddenTables, array_keys($tableLabels));
if (empty($allowedTables)) {
    die('No tables found in the public schema.');
}

$currentTable = $_GET['table'] ?? $allowedTables[0];
if (!in_array($currentTable, $allowedTables, true)) {
    $currentTable = $allowedTables[0];
}

/* ------------------------------------------------------------------ *
 * Metadata helpers
 * ------------------------------------------------------------------ */
function quoteIdent(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function getTableMetadata(PDO $pdo, string $table): array
{
    $columnStmt = $pdo->prepare(
        "SELECT column_name, data_type, is_nullable, column_default
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = :t
         ORDER BY ordinal_position"
    );
    $columnStmt->execute([':t' => $table]);
    $columns = $columnStmt->fetchAll(PDO::FETCH_ASSOC);

    $pkStmt = $pdo->prepare(
        "SELECT kcu.column_name
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
          AND tc.table_name = kcu.table_name
         WHERE tc.constraint_type = 'PRIMARY KEY'
           AND tc.table_schema = 'public'
           AND tc.table_name = :t
         ORDER BY kcu.ordinal_position
         LIMIT 1"
    );
    $pkStmt->execute([':t' => $table]);

    return ['primaryKey' => $pkStmt->fetchColumn() ?: null, 'columns' => $columns];
}

/** Single-column foreign keys of a table: column => [ref_table, ref_column]. */
function getForeignKeys(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT a.attname AS column_name, rc.relname AS ref_table, ra.attname AS ref_column
         FROM pg_constraint c
         JOIN pg_class cl ON cl.oid = c.conrelid
         JOIN pg_namespace n ON n.oid = cl.relnamespace
         JOIN pg_class rc ON rc.oid = c.confrelid
         JOIN pg_attribute a  ON a.attrelid  = c.conrelid  AND a.attnum  = c.conkey[1]
         JOIN pg_attribute ra ON ra.attrelid = c.confrelid AND ra.attnum = c.confkey[1]
         WHERE c.contype = 'f' AND n.nspname = 'public'
           AND cl.relname = :t AND array_length(c.conkey, 1) = 1"
    );
    $stmt->execute([':t' => $table]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['column_name']] = [$row['ref_table'], $row['ref_column']];
    }
    return $map;
}

/** Dropdown choices for a foreign-key column. */
function getForeignKeyOptions(PDO $pdo, string $refTable, string $refColumn): array
{
    $labels = [
        'borrowers'           => "borrower_no || ' - ' || full_name",
        'equipment'           => "property_no || ' - ' || equipment_name || ' (' || quantity_available || ' available)'",
        'borrow_transactions' => "'Transaction #' || borrow_id || ' (' || status || ', due ' || due_date || ')'",
    ];

    $valueSql = quoteIdent($refColumn);
    $labelSql = $labels[$refTable] ?? ($valueSql . '::text');

    return $pdo->query(
        'SELECT ' . $valueSql . ' AS value, ' . $labelSql . ' AS label
         FROM ' . quoteIdent($refTable) . ' ORDER BY ' . $valueSql
    )->fetchAll(PDO::FETCH_ASSOC);
}

$fixedOptions = [
    'status'        => ['Active', 'Returned', 'Overdue', 'Cancelled'],
    'borrower_type' => ['Student', 'Faculty', 'Staff', 'Researcher'],
];

/* ------------------------------------------------------------------ *
 * Validation
 * ------------------------------------------------------------------ */
function labelFor(string $column): string
{
    return ucfirst(str_replace('_', ' ', $column));
}

/** Returns an error message, or null when the value has an acceptable format. */
function validateFormat(array $column, string $raw): ?string
{
    $label = labelFor($column['column_name']);

    if (in_array($column['data_type'], ['integer', 'smallint', 'bigint'], true)) {
        return preg_match('/^-?\d+$/', $raw) ? null : $label . ' must be a whole number.';
    }
    if ($column['data_type'] === 'date') {
        return isValidDate($raw) ? null : $label . ' must be a valid date (YYYY-MM-DD).';
    }
    if (mb_strlen($raw) > 255) {
        return $label . ' is too long.';
    }
    return null;
}

/**
 * Prepare one POST value for each editable column.
 * Returns [values, errors]; omitted columns (use DB default) are left out of $values.
 */
function collectValues(array $editableColumns, array $post, bool $isCreate): array
{
    $values = [];
    $errors = [];

    foreach ($editableColumns as $column) {
        $name = $column['column_name'];
        $raw = isset($post[$name]) && is_scalar($post[$name]) ? trim((string) $post[$name]) : '';
        $nullable = $column['is_nullable'] === 'YES';
        $hasDefault = $column['column_default'] !== null;

        if ($raw === '') {
            if ($isCreate && $hasDefault) {
                continue;                       // let PostgreSQL apply the default
            }
            if ($nullable) {
                $values[$name] = null;
                continue;
            }
            $errors[] = labelFor($name) . ' is required.';
            continue;
        }

        $formatError = validateFormat($column, $raw);
        if ($formatError !== null) {
            $errors[] = $formatError;
            continue;
        }
        $values[$name] = $raw;
    }

    return [$values, $errors];
}

function redirectToTable(string $table): void
{
    header('Location: ' . $_SERVER['PHP_SELF'] . '?table=' . urlencode($table));
    exit;
}

/* ------------------------------------------------------------------ *
 * POST actions: create / update / delete (all parameterized)
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tableName = $_POST['table'] ?? $currentTable;

    if (!in_array($tableName, $allowedTables, true)) {
        flash('error', 'Invalid table.');
        redirectToTable($currentTable);
    }

    $meta = getTableMetadata($pdo, $tableName);
    $primaryKey = $meta['primaryKey'];
    $tableTitle = $tableLabels[$tableName] ?? labelFor($tableName);

    if (!$primaryKey || !in_array($action, ['create', 'update', 'delete'], true)) {
        flash('error', 'Unsupported action for this table.');
        redirectToTable($tableName);
    }

    $editable = array_values(array_filter(
        $meta['columns'],
        function ($column) use ($primaryKey) {
            return $column['column_name'] !== $primaryKey;
        }
    ));

    try {
        if ($action === 'create') {
            [$values, $errors] = collectValues($editable, $_POST, true);
            if ($errors) {
                foreach ($errors as $error) {
                    flash('error', $error);
                }
                redirectToTable($tableName);
            }

            if (empty($values)) {
                $pdo->exec('INSERT INTO ' . quoteIdent($tableName) . ' DEFAULT VALUES');
            } else {
                $columnSql = implode(', ', array_map('quoteIdent', array_keys($values)));
                $marks = implode(', ', array_fill(0, count($values), '?'));
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . quoteIdent($tableName) . ' (' . $columnSql . ') VALUES (' . $marks . ')'
                );
                $stmt->execute(array_values($values));
            }
            flash('success', $tableTitle . ': record added.');

        } elseif ($action === 'update') {
            $id = filter_var($_POST[$primaryKey] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                flash('error', 'Choose a record to update with its Edit button first.');
                redirectToTable($tableName);
            }

            [$values, $errors] = collectValues($editable, $_POST, false);
            if ($errors) {
                foreach ($errors as $error) {
                    flash('error', $error);
                }
                redirectToTable($tableName);
            }

            $assignments = implode(', ', array_map(function ($name) {
                return quoteIdent($name) . ' = ?';
            }, array_keys($values)));
            $stmt = $pdo->prepare(
                'UPDATE ' . quoteIdent($tableName) . ' SET ' . $assignments
                . ' WHERE ' . quoteIdent($primaryKey) . ' = ?'
            );
            $stmt->execute(array_merge(array_values($values), [$id]));

            if ($stmt->rowCount() === 0) {
                flash('error', 'No record found with ID ' . $id . '.');
            } else {
                flash('success', $tableTitle . ': record ' . $id . ' updated.');
            }

        } else { // delete
            $id = filter_var($_POST[$primaryKey] ?? '', FILTER_VALIDATE_INT);
            if ($id === false) {
                flash('error', 'Invalid record ID.');
                redirectToTable($tableName);
            }

            $stmt = $pdo->prepare(
                'DELETE FROM ' . quoteIdent($tableName) . ' WHERE ' . quoteIdent($primaryKey) . ' = ?'
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                flash('error', 'No record found with ID ' . $id . '.');
            } else {
                flash('success', $tableTitle . ': record ' . $id . ' deleted.');
            }
        }
    } catch (PDOException $e) {
        error_log('CRUD error: ' . $e->getMessage());
        flash('error', dbErrorMessage($e));
    }

    redirectToTable($tableName);
}

/* ------------------------------------------------------------------ *
 * Read data for the page
 * ------------------------------------------------------------------ */
$meta = getTableMetadata($pdo, $currentTable);
$primaryKey = $meta['primaryKey'];
$columns = $meta['columns'];
$editableColumns = array_values(array_filter(
    $columns,
    function ($column) use ($primaryKey) {
        return $column['column_name'] !== $primaryKey;
    }
));

$rows = [];
if ($primaryKey) {
    $rows = $pdo->query(
        'SELECT * FROM ' . quoteIdent($currentTable) . ' ORDER BY ' . quoteIdent($primaryKey) . ' DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$foreignKeys = getForeignKeys($pdo, $currentTable);
$foreignOptions = [];
foreach ($foreignKeys as $columnName => [$refTable, $refColumn]) {
    $foreignOptions[$columnName] = getForeignKeyOptions($pdo, $refTable, $refColumn);
}

$foreignLabels = [];
foreach ($foreignOptions as $columnName => $options) {
    foreach ($options as $option) {
        $foreignLabels[$columnName][(string) $option['value']] = $option['label'];
    }
}

/** Form control for one column: dropdown for foreign keys / fixed lists, else typed input. */
function renderControl(array $column, array $foreignOptions, array $fixedOptions, string $prefix, bool $isCreate): string
{
    $name = $column['column_name'];
    $id = $prefix . $name;
    $required = $column['is_nullable'] === 'NO' && !($isCreate && $column['column_default'] !== null);
    $req = $required ? ' required' : '';

    if (isset($foreignOptions[$name])) {
        $html = '<select id="' . h($id) . '" name="' . h($name) . '"' . $req . '><option value="">-- select --</option>';
        foreach ($foreignOptions[$name] as $option) {
            $html .= '<option value="' . h($option['value']) . '">' . h($option['label']) . '</option>';
        }
        return $html . '</select>';
    }

    if (isset($fixedOptions[$name])) {
        $html = '<select id="' . h($id) . '" name="' . h($name) . '"' . $req . '>';
        $html .= '<option value="">' . ($isCreate && $column['column_default'] !== null ? '(default)' : '-- select --') . '</option>';
        foreach ($fixedOptions[$name] as $option) {
            $html .= '<option value="' . h($option) . '">' . h($option) . '</option>';
        }
        return $html . '</select>';
    }

    $type = 'text';
    $extra = '';
    if ($column['data_type'] === 'date') {
        $type = 'date';
    } elseif (in_array($column['data_type'], ['integer', 'smallint', 'bigint'], true)) {
        $type = 'number';
        $extra = ' step="1"';
    }

    return '<input id="' . h($id) . '" type="' . $type . '" name="' . h($name) . '"' . $extra . $req . '>';
}

renderHeader('Manage Records', 'crud');
?>
    <h1>Manage Records</h1>
    <p class="subtitle">Create, view, update and delete records in the PostgreSQL database.</p>

    <?php renderFlash(); ?>

    <nav class="tabs" aria-label="Database tables">
        <?php foreach ($allowedTables as $tableName): ?>
            <a href="?table=<?= h(urlencode($tableName)) ?>" class="<?= $tableName === $currentTable ? 'active' : '' ?>"><?= h($tableLabels[$tableName] ?? labelFor($tableName)) ?></a>
        <?php endforeach; ?>
    </nav>

    <section>
        <h2>Add <?= h($tableLabels[$currentTable] ?? labelFor($currentTable)) ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="table" value="<?= h($currentTable) ?>">
            <div class="grid-2">
                <?php foreach ($editableColumns as $column): ?>
                    <div class="field">
                        <label for="create_<?= h($column['column_name']) ?>"><?= h(labelFor($column['column_name'])) ?></label>
                        <?= renderControl($column, $foreignOptions, $fixedOptions, 'create_', true) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit">Add Record</button>
        </form>
    </section>

    <section>
        <h2>Records</h2>
        <?php if (empty($rows)): ?>
            <p class="empty">No records found.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th><?= h(labelFor($column['column_name'])) ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <?php
                                $cell = $row[$column['column_name']] ?? '';
                                $cellKey = $column['column_name'];
                                if (isset($foreignLabels[$cellKey][(string) $cell])) {
                                    $cell = $foreignLabels[$cellKey][(string) $cell];
                                }
                            ?>
                            <td><?= h($cell) ?></td>
                        <?php endforeach; ?>
                        <td class="row-actions">
                            <button type="button" class="secondary row-edit"
                                data-row="<?= h(json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">Edit</button>
                            <form method="post" onsubmit="return confirm('Delete this record?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="table" value="<?= h($currentTable) ?>">
                                <input type="hidden" name="<?= h($primaryKey) ?>" value="<?= (int) $row[$primaryKey] ?>">
                                <button type="submit" class="link-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Update <?= h($tableLabels[$currentTable] ?? labelFor($currentTable)) ?></h2>
        <p class="note">Click <strong>Edit</strong> on a row above to load it here.</p>
        <form method="post" id="update-form">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="table" value="<?= h($currentTable) ?>">

            <?php if ($primaryKey): ?>
                <div class="field">
                    <label for="update_pk"><?= h(labelFor($primaryKey)) ?></label>
                    <input id="update_pk" type="number" name="<?= h($primaryKey) ?>" readonly required>
                </div>
            <?php endif; ?>

            <div class="grid-2">
                <?php foreach ($editableColumns as $column): ?>
                    <div class="field">
                        <label for="update_<?= h($column['column_name']) ?>"><?= h(labelFor($column['column_name'])) ?></label>
                        <?= renderControl($column, $foreignOptions, $fixedOptions, 'update_', false) ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="secondary">Update Record</button>
        </form>
    </section>

    <script>
        const updateForm = document.getElementById('update-form');

        document.querySelectorAll('.row-edit').forEach((button) => {
            button.addEventListener('click', () => {
                const record = JSON.parse(button.dataset.row);

                Object.entries(record).forEach(([name, value]) => {
                    const field = updateForm.elements.namedItem(name);
                    if (field) field.value = value ?? '';
                });

                updateForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
    </script>
<?php renderFooter($pdo);
