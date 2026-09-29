<?php

require __DIR__ . '/../config/db.php';

try {
    $pdo = getPdo();
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('The database is unavailable. Please contact the site administrator.');
}

function getExistingTables(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = 'public'
           AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    );

    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return array_values(array_filter(array_map('strval', $tables), function ($tableName) {
        return $tableName !== '';
    }));
}

$allowedTables = getExistingTables($pdo);
if (empty($allowedTables)) {
    $allowedTables = ['borrowers', 'equipment', 'transactions'];
}

$currentTable = $_GET['table'] ?? ($allowedTables[0] ?? 'borrowers');
if (!in_array($currentTable, $allowedTables, true)) {
    $currentTable = $allowedTables[0] ?? 'borrowers';
}

function getTableMetadata(PDO $pdo, string $tableName): array
{
    $safeTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($safeTableName === '') {
        return ['primaryKey' => null, 'columns' => []];
    }

    $columnStmt = $pdo->prepare(
        "SELECT column_name, data_type, is_nullable
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = :table_name
         ORDER BY ordinal_position"
    );
    $columnStmt->execute([':table_name' => $safeTableName]);
    $columns = $columnStmt->fetchAll(PDO::FETCH_ASSOC);

    $pkStmt = $pdo->prepare(
        "SELECT c.column_name
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
         JOIN information_schema.columns c
           ON c.table_schema = kcu.table_schema
          AND c.table_name = kcu.table_name
          AND c.ordinal_position = kcu.ordinal_position
         WHERE tc.constraint_type = 'PRIMARY KEY'
           AND tc.table_schema = 'public'
           AND tc.table_name = :table_name
         ORDER BY c.ordinal_position
         LIMIT 1"
    );
    $pkStmt->execute([':table_name' => $safeTableName]);
    $primaryKey = $pkStmt->fetchColumn();

    return ['primaryKey' => $primaryKey ?: null, 'columns' => $columns];
}

function sanitizeValue(string $key, array $values)
{
    $value = $values[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
}

function formatLabel(?string $value, string $fallback = 'Record'): string
{
    $clean = trim((string) ($value ?? ''));
    $clean = str_replace('_', ' ', $clean);
    $clean = trim($clean);

    if ($clean === '') {
        $clean = $fallback;
    }

    return ucfirst($clean);
}

foreach ($allowedTables as $tableName) {
    $tableMeta[$tableName] = getTableMetadata($pdo, $tableName);
    $primaryKey = $tableMeta[$tableName]['primaryKey'];

    if ($primaryKey) {
        $tableMeta[$tableName]['readRows'] = $pdo->query(
            'SELECT * FROM ' . $tableName . ' ORDER BY ' . $primaryKey . ' DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $tableMeta[$tableName]['readRows'] = [];
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'create'
) {
    $tableName = $_POST['table'] ?? $currentTable;
    if (!in_array($tableName, $allowedTables, true)) {
        die('Invalid table.');
    }

    $meta = getTableMetadata($pdo, $tableName);
    $primaryKey = $meta['primaryKey'];
    $editableColumns = array_values(array_filter(
        $meta['columns'],
        function ($column) use ($primaryKey) {
            return $primaryKey === null || ($column['column_name'] ?? '') !== $primaryKey;
        }
    ));

    if (empty($editableColumns)) {
        $pdo->exec('INSERT INTO ' . $tableName . ' DEFAULT VALUES');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?table=' . urlencode($tableName));
        exit;
    }

    $columnNames = array_map(function ($column) {
        return $column['column_name'];
    }, $editableColumns);

    $placeholders = array_map(function ($column) {
        return ':' . $column['column_name'];
    }, $editableColumns);

    $stmt = $pdo->prepare(
        'INSERT INTO ' . $tableName . ' (' . implode(', ', $columnNames) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );

    $params = [];
    foreach ($editableColumns as $column) {
        $columnName = $column['column_name'];
        $params[':' . $columnName] = sanitizeValue($columnName, $_POST);
    }

    $stmt->execute($params);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?table=' . urlencode($tableName));
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update'
) {
    $tableName = $_POST['table'] ?? $currentTable;
    if (!in_array($tableName, $allowedTables, true)) {
        die('Invalid table.');
    }

    $meta = getTableMetadata($pdo, $tableName);
    $primaryKey = $meta['primaryKey'];
    if (!$primaryKey) {
        die('Table has no primary key.');
    }

    $editableColumns = array_values(array_filter(
        $meta['columns'],
        function ($column) use ($primaryKey) {
            return ($column['column_name'] ?? '') !== $primaryKey;
        }
    ));

    $assignments = array_map(function ($column) {
        return $column['column_name'] . ' = :' . $column['column_name'];
    }, $editableColumns);

    $stmt = $pdo->prepare(
        'UPDATE ' . $tableName . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $primaryKey . ' = :' . $primaryKey
    );

    $params = [];
    foreach ($editableColumns as $column) {
        $columnName = $column['column_name'];
        $params[':' . $columnName] = sanitizeValue($columnName, $_POST);
    }
    $params[':' . $primaryKey] = (int) ($_POST[$primaryKey] ?? 0);

    $stmt->execute($params);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?table=' . urlencode($tableName));
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    $tableName = $_POST['table'] ?? $currentTable;
    if (!in_array($tableName, $allowedTables, true)) {
        die('Invalid table.');
    }

    $meta = getTableMetadata($pdo, $tableName);
    $primaryKey = $meta['primaryKey'];
    if (!$primaryKey) {
        die('Table has no primary key.');
    }

    $stmt = $pdo->prepare('DELETE FROM ' . $tableName . ' WHERE ' . $primaryKey . ' = :' . $primaryKey);
    $stmt->execute([':' . $primaryKey => (int) ($_POST[$primaryKey] ?? 0)]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?table=' . urlencode($tableName));
    exit;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database CRUD Demo</title>
    <style>
        :root {
            --border: #e2e2e2;
            --text: #2b2b2b;
            --muted: #6b6b6b;
            --accent: #2f6f4f;
            --danger: #a33;
            --bg-soft: #fafafa;
        }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            color: var(--text);
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
            line-height: 1.5;
        }

        h1, h2, h3 { margin-bottom: 12px; }
        p.subtitle { color: var(--muted); margin-top: 0; margin-bottom: 28px; }

        .table-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }

        .table-tabs a {
            text-decoration: none;
            background: var(--bg-soft);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px 12px;
            color: var(--text);
        }

        .table-tabs a.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        section {
            margin-bottom: 40px;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
        }

        .field {
            margin-bottom: 12px;
        }

        label {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        input, textarea, select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text);
        }

        input:focus { outline: none; border-color: var(--accent); }

        button {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--accent);
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }

        button.secondary {
            background: transparent;
            color: var(--text);
            border-color: var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }

        tr:hover td { background: var(--bg-soft); }

        .row-delete form { display: inline; }
        .row-delete button {
            background: none;
            border: none;
            color: var(--danger);
            padding: 4px 6px;
            font-size: 13px;
        }

        .empty {
            color: var(--muted);
            padding: 16px 0;
        }
    </style>
</head>
<body>
    <h1>Database CRUD Demo</h1>
    <p class="subtitle">Manage records across the selected table in the project database.</p>

    <nav class="table-tabs" aria-label="Database tables">
        <?php foreach ($allowedTables as $tableName): ?>
            <a href="?table=<?= urlencode($tableName) ?>" class="<?= $tableName === $currentTable ? 'active' : '' ?>"><?= htmlspecialchars(ucfirst($tableName), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>

    <?php $selectedMeta = $tableMeta[$currentTable] ?? ['primaryKey' => null, 'columns' => [], 'readRows' => []]; ?>
    <?php $selectedPrimaryKey = $selectedMeta['primaryKey']; ?>
    <?php $editableColumns = array_values(array_filter($selectedMeta['columns'], function ($column) use ($selectedPrimaryKey) {
        return ($column['column_name'] ?? '') !== $selectedPrimaryKey;
    })); ?>

    <section>
        <h2>Add <?= htmlspecialchars(formatLabel($currentTable, 'Table'), ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="table" value="<?= htmlspecialchars($currentTable, ENT_QUOTES, 'UTF-8') ?>">

            <?php foreach ($editableColumns as $column): ?>
                <?php $columnName = $column['column_name']; ?>
                <div class="field">
                    <label for="create_<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(formatLabel($columnName, 'Field'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input id="create_<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>" type="text" name="<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            <?php endforeach; ?>

            <button type="submit">Add Record</button>
        </form>
    </section>

    <section>
        <h2>Records</h2>
        <?php if (empty($selectedMeta['readRows'])): ?>
            <p class="empty">No records found for <?= htmlspecialchars($currentTable, ENT_QUOTES, 'UTF-8') ?>.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <?php foreach ($selectedMeta['columns'] as $column): ?>
                        <th><?= htmlspecialchars(formatLabel($column['column_name'] ?? null, 'Field'), ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($selectedMeta['readRows'] as $row): ?>
                    <tr>
                        <?php foreach ($selectedMeta['columns'] as $column): ?>
                            <?php $key = $column['column_name']; ?>
                            <td><?= htmlspecialchars((string) ($row[$key] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endforeach; ?>
                        <td class="row-delete">
                            <form method="post">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="table" value="<?= htmlspecialchars($currentTable, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="<?= htmlspecialchars($selectedPrimaryKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= (int) ($row[$selectedPrimaryKey] ?? 0) ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Update <?= htmlspecialchars(formatLabel($currentTable, 'Table'), ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="table" value="<?= htmlspecialchars($currentTable, ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($selectedPrimaryKey): ?>
                <div class="field">
                    <label for="update_pk"><?= htmlspecialchars(formatLabel($selectedPrimaryKey, 'Id'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input id="update_pk" type="number" name="<?= htmlspecialchars($selectedPrimaryKey, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            <?php endif; ?>

            <?php foreach ($editableColumns as $column): ?>
                <?php $columnName = $column['column_name']; ?>
                <div class="field">
                    <label for="update_<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(formatLabel($columnName, 'Field'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input id="update_<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>" type="text" name="<?= htmlspecialchars($columnName, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="secondary">Update Record</button>
        </form>
    </section>
</body>
</html>
