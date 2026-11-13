<?php

require __DIR__ . '/_common.php';

$pdo = connect();

/*
 * Every report is a fixed SQL statement run against the live database.
 * Only the Week 6 "Filtered Borrowing Records" report takes user input,
 * and it does so through bound parameters.
 */
$reports = [
    /* ------------------------------ WEEK 6 ------------------------------ */
    'w6_inventory' => [
        'group' => 'Week 6',
        'title' => 'Equipment Inventory Listing',
        'note'  => 'SELECT with column aliases; ORDER BY two columns (stock DESC, then name ASC).',
        'sql'   => 'SELECT equipment_name AS equipment,
                           property_no AS property_number,
                           quantity_available AS available_quantity
                    FROM equipment
                    ORDER BY quantity_available DESC, equipment_name ASC',
    ],
    'w6_filtered' => [
        'group' => 'Week 6',
        'title' => 'Filtered Borrowing Records',
        'note'  => 'WHERE with AND, OR, IN and date-range conditions. Use the filters below.',
        'sql'   => null, // built from the filter form
    ],
    'w6_distinct' => [
        'group' => 'Week 6',
        'title' => 'Distinct Borrower Types in Use',
        'note'  => 'DISTINCT: each borrower type that has at least one transaction, listed once.',
        'sql'   => 'SELECT DISTINCT b.borrower_type AS borrower_type_with_loans
                    FROM borrowers AS b
                    JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    ORDER BY b.borrower_type',
    ],
    'w6_btype' => [
        'group' => 'Week 6',
        'title' => 'Borrower Type Summary',
        'note'  => 'GROUP BY: number of borrowers in each category.',
        'sql'   => 'SELECT borrower_type, COUNT(*) AS borrowers
                    FROM borrowers
                    GROUP BY borrower_type
                    ORDER BY borrowers DESC, borrower_type',
    ],
    'w6_status' => [
        'group' => 'Week 6',
        'title' => 'Transaction Status Summary',
        'note'  => 'Number of transactions for every borrowing status (statuses with none show 0).',
        'sql'   => "SELECT s.status, COUNT(t.borrow_id) AS transactions
                    FROM (VALUES ('Active'), ('Returned'), ('Overdue'), ('Cancelled')) AS s(status)
                    LEFT JOIN borrow_transactions AS t ON t.status = s.status
                    GROUP BY s.status
                    ORDER BY transactions DESC, s.status",
    ],
    'w6_group_type' => [
        'group' => 'Week 6',
        'title' => 'Grouped Borrowing Analysis',
        'note'  => "WHERE removes Cancelled rows before grouping; HAVING keeps only groups with at least two transactions.",
        'sql'   => "SELECT b.borrower_type, COUNT(*) AS transactions
                    FROM borrow_transactions AS t
                    JOIN borrowers AS b ON b.borrower_id = t.borrower_id
                    WHERE t.status <> 'Cancelled'
                    GROUP BY b.borrower_type
                    HAVING COUNT(*) >= 2
                    ORDER BY transactions DESC, b.borrower_type",
    ],

    /* ------------------------------ WEEK 7 ------------------------------ */
    'w7_equipment_summary' => [
        'group' => 'Week 7',
        'title' => 'Equipment Borrowing Summary',
        'note'  => 'LEFT JOIN + aggregates per equipment item. COUNT(DISTINCT) avoids double-counting transactions.',
        'sql'   => 'SELECT e.property_no,
                           e.equipment_name,
                           COUNT(DISTINCT t.borrow_id) AS transaction_count,
                           COALESCE(SUM(i.quantity), 0) AS total_quantity_borrowed,
                           COALESCE(ROUND(AVG(i.quantity), 2), 0) AS average_per_borrowing,
                           MIN(i.quantity) AS smallest_borrowing,
                           MAX(i.quantity) AS largest_borrowing
                    FROM equipment AS e
                    LEFT JOIN borrow_items AS i ON i.equipment_id = e.equipment_id
                    LEFT JOIN borrow_transactions AS t ON t.borrow_id = i.borrow_id
                    GROUP BY e.equipment_id, e.property_no, e.equipment_name
                    ORDER BY total_quantity_borrowed DESC, e.equipment_name',
    ],
    'w7_complete' => [
        'group' => 'Week 7',
        'title' => 'Complete Borrowing Report',
        'note'  => 'INNER JOIN across borrowers, transactions, items and equipment; UPPER, INITCAP, TRIM and TO_CHAR for display.',
        'sql'   => "SELECT UPPER(b.borrower_no) AS borrower_number,
                           INITCAP(TRIM(b.full_name)) AS borrower_name,
                           e.equipment_name,
                           i.quantity AS borrowed_quantity,
                           TO_CHAR(t.borrow_date, 'Mon DD, YYYY') AS borrowed_on,
                           TO_CHAR(t.due_date, 'Mon DD, YYYY') AS due_on,
                           t.status
                    FROM borrow_transactions AS t
                    JOIN borrowers AS b ON b.borrower_id = t.borrower_id
                    JOIN borrow_items AS i ON i.borrow_id = t.borrow_id
                    JOIN equipment AS e ON e.equipment_id = i.equipment_id
                    ORDER BY t.borrow_id, e.equipment_name",
    ],
    'w7_no_tx' => [
        'group' => 'Week 7',
        'title' => 'Borrowers Without Transactions',
        'note'  => 'LEFT JOIN keeps every borrower; WHERE ... IS NULL finds those with no match.',
        'sql'   => "SELECT b.borrower_no,
                           CONCAT_WS(' - ', b.borrower_no, b.full_name) AS borrower,
                           b.borrower_type
                    FROM borrowers AS b
                    LEFT JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    WHERE t.borrow_id IS NULL
                    ORDER BY b.borrower_no",
    ],
    'w7_no_equip' => [
        'group' => 'Week 7',
        'title' => 'Equipment Without Borrowing History',
        'note'  => 'LEFT JOIN from equipment to borrow_items; equipment never referenced has NULL on the item side.',
        'sql'   => 'SELECT e.property_no, e.equipment_name, e.quantity_available
                    FROM equipment AS e
                    LEFT JOIN borrow_items AS i ON i.equipment_id = e.equipment_id
                    WHERE i.borrow_item_id IS NULL
                    ORDER BY e.property_no',
    ],
    'w7_union_all' => [
        'group' => 'Week 7',
        'title' => 'Combined Borrower Activity (UNION ALL)',
        'note'  => 'Set A: borrowers with an open loan. Set B: borrowers who borrowed on or after 2026-09-01. UNION ALL keeps duplicates.',
        'sql'   => "SELECT b.borrower_no, b.full_name
                    FROM borrowers AS b
                    JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    WHERE t.status IN ('Active', 'Overdue')
                    UNION ALL
                    SELECT b.borrower_no, b.full_name
                    FROM borrowers AS b
                    JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    WHERE t.borrow_date >= DATE '2026-09-01'
                    ORDER BY borrower_no",
    ],
    'w7_union' => [
        'group' => 'Week 7',
        'title' => 'Combined Borrower Activity (UNION)',
        'note'  => 'The same two sets with UNION, which removes duplicate rows.',
        'sql'   => "SELECT b.borrower_no, b.full_name
                    FROM borrowers AS b
                    JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    WHERE t.status IN ('Active', 'Overdue')
                    UNION
                    SELECT b.borrower_no, b.full_name
                    FROM borrowers AS b
                    JOIN borrow_transactions AS t ON t.borrower_id = b.borrower_id
                    WHERE t.borrow_date >= DATE '2026-09-01'
                    ORDER BY borrower_no",
    ],
    'w7_union_compare' => [
        'group' => 'Week 7',
        'title' => 'UNION vs UNION ALL (row counts)',
        'note'  => 'Verifies the difference: UNION ALL returns every row of both sets; UNION returns each distinct row once.',
        'sql'   => "SELECT 'UNION' AS operation, COUNT(*) AS rows_returned
                    FROM (
                        SELECT b.borrower_no FROM borrowers b
                          JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
                         WHERE t.status IN ('Active', 'Overdue')
                        UNION
                        SELECT b.borrower_no FROM borrowers b
                          JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
                         WHERE t.borrow_date >= DATE '2026-09-01'
                    ) AS u
                    UNION ALL
                    SELECT 'UNION ALL', COUNT(*)
                    FROM (
                        SELECT b.borrower_no FROM borrowers b
                          JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
                         WHERE t.status IN ('Active', 'Overdue')
                        UNION ALL
                        SELECT b.borrower_no FROM borrowers b
                          JOIN borrow_transactions t ON t.borrower_id = b.borrower_id
                         WHERE t.borrow_date >= DATE '2026-09-01'
                    ) AS ua",
    ],
    'w7_monthly' => [
        'group' => 'Week 7',
        'title' => 'Monthly Borrowing Report',
        'note'  => 'DATE_TRUNC groups by month; TO_CHAR formats the label.',
        'sql'   => "SELECT TRIM(TO_CHAR(DATE_TRUNC('month', t.borrow_date), 'Month YYYY')) AS month,
                           COUNT(DISTINCT t.borrow_id) AS transactions,
                           COALESCE(SUM(i.quantity), 0) AS total_items
                    FROM borrow_transactions AS t
                    LEFT JOIN borrow_items AS i ON i.borrow_id = t.borrow_id
                    GROUP BY DATE_TRUNC('month', t.borrow_date)
                    ORDER BY DATE_TRUNC('month', t.borrow_date)",
    ],
    'w7_overdue' => [
        'group' => 'Week 7',
        'title' => 'Overdue Records (date functions)',
        'note'  => 'CURRENT_DATE compared with due dates; EXTRACT and TO_CHAR for periods and labels.',
        'sql'   => "SELECT t.borrow_id,
                           b.borrower_no,
                           TO_CHAR(t.due_date, 'Mon DD, YYYY') AS due_on,
                           EXTRACT(MONTH FROM t.due_date)::int AS due_month,
                           CURRENT_DATE - t.due_date AS days_overdue,
                           t.status
                    FROM borrow_transactions AS t
                    JOIN borrowers AS b ON b.borrower_id = t.borrower_id
                    WHERE t.status = 'Overdue'
                       OR (t.status = 'Active' AND t.due_date < CURRENT_DATE)
                    ORDER BY days_overdue DESC, t.borrow_id",
    ],

    /* ------------------------------ WEEK 8 ------------------------------ */
    'w8_view_details' => [
        'group' => 'Week 8',
        'title' => 'View: vw_borrowing_details',
        'note'  => 'Reads the saved view (borrower, equipment, quantity, dates, status).',
        'sql'   => 'SELECT borrow_id, borrower_no, borrower_name, property_no, equipment_name,
                           borrowed_quantity, borrow_date, due_date, status
                    FROM vw_borrowing_details
                    ORDER BY borrow_id DESC, property_no',
    ],
    'w8_view_usage' => [
        'group' => 'Week 8',
        'title' => 'View: vw_equipment_usage',
        'note'  => 'Reads the saved view, which includes a CASE availability class.',
        'sql'   => 'SELECT property_no, equipment_name, quantity_available, total_transactions,
                           total_quantity_borrowed, quantity_out, availability_class
                    FROM vw_equipment_usage
                    ORDER BY property_no',
    ],
    'w8_math' => [
        'group' => 'Week 8',
        'title' => 'Mathematical Functions (ROUND, ABS, NULLIF)',
        'note'  => 'Reuses vw_equipment_usage. NULLIF avoids division by zero when stock is 0.',
        'sql'   => 'SELECT equipment_name,
                           quantity_available,
                           quantity_out,
                           ROUND(quantity_out * 100.0 / NULLIF(quantity_available, 0), 1) AS percent_out,
                           ABS(quantity_available - quantity_out) AS stock_gap
                    FROM vw_equipment_usage
                    ORDER BY percent_out DESC NULLS LAST, equipment_name',
    ],
    'w8_case' => [
        'group' => 'Week 8',
        'title' => 'CASE Expressions',
        'note'  => 'Conditions are ordered: Returned, Cancelled, Overdue, Due Soon (within 3 days), then On Loan.',
        'sql'   => "SELECT t.borrow_id,
                           b.full_name AS borrower,
                           t.due_date,
                           t.status,
                           CASE
                               WHEN t.status = 'Returned'  THEN 'Returned'
                               WHEN t.status = 'Cancelled' THEN 'Cancelled'
                               WHEN t.status = 'Overdue' OR t.due_date < CURRENT_DATE THEN 'Overdue'
                               WHEN t.due_date <= CURRENT_DATE + 3 THEN 'Due Soon'
                               ELSE 'On Loan'
                           END AS loan_class
                    FROM borrow_transactions AS t
                    JOIN borrowers AS b ON b.borrower_id = t.borrower_id
                    ORDER BY t.borrow_id",
    ],

    /* ------------------------------ WEEK 10 ----------------------------- */
    'w10_functions' => [
        'group' => 'Week 10',
        'title' => 'Functions: fn_total_borrowed and fn_check_equipment_availability',
        'note'  => 'fn_total_borrowed (SQL) and fn_check_equipment_availability (PL/pgSQL) called with different inputs.',
        'sql'   => "SELECT e.equipment_name,
                           e.quantity_available,
                           fn_total_borrowed(e.equipment_id) AS total_borrowed,
                           CASE WHEN fn_check_equipment_availability(e.equipment_id, 3)  THEN 'Yes' ELSE 'No' END AS can_borrow_3,
                           CASE WHEN fn_check_equipment_availability(e.equipment_id, 10) THEN 'Yes' ELSE 'No' END AS can_borrow_10
                    FROM equipment AS e
                    ORDER BY e.equipment_id",
    ],
    'w10_audit' => [
        'group' => 'Week 10',
        'title' => 'Borrow Items Audit (borrow_items_audit)',
        'note'  => 'Written automatically by trigger trg_borrow_items_audit. Latest 50 events.',
        'sql'   => "SELECT audit_id, borrow_item_id, operation,
                           old_borrow_id, new_borrow_id,
                           old_equipment_id, new_equipment_id,
                           old_quantity, new_quantity,
                           TO_CHAR(changed_at, 'Mon DD, YYYY HH24:MI:SS') AS changed_at
                    FROM borrow_items_audit
                    ORDER BY audit_id DESC
                    LIMIT 50",
    ],
];

/* ---------------------- filter form for the Week 6 report ---------------------- */
$statusChoices = [
    ''          => 'Any status',
    'Active'    => 'Active',
    'Returned'  => 'Returned',
    'Overdue'   => 'Overdue',
    'Cancelled' => 'Cancelled',
    'OPEN'      => 'Open (Active or Overdue)',
];
$typeChoices = ['' => 'Any type', 'Student' => 'Student', 'Faculty' => 'Faculty', 'Staff' => 'Staff', 'Researcher' => 'Researcher'];

function buildFilteredBorrowingQuery(array $get, array $statusChoices, array $typeChoices, array &$messages): array
{
    $where = [];
    $params = [];

    $status = (string) ($get['status'] ?? '');
    if ($status === 'OPEN') {
        $where[] = "t.status IN ('Active', 'Overdue')";
    } elseif ($status !== '' && isset($statusChoices[$status])) {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }

    $type = (string) ($get['borrower_type'] ?? '');
    if ($type !== '' && isset($typeChoices[$type])) {
        $where[] = 'b.borrower_type = :btype';
        $params[':btype'] = $type;
    }

    $from = trim((string) ($get['from'] ?? ''));
    if ($from !== '') {
        if (isValidDate($from)) {
            $where[] = 't.borrow_date >= :from_date';
            $params[':from_date'] = $from;
        } else {
            $messages[] = 'The "from" date was ignored because it is not a valid date.';
        }
    }

    $to = trim((string) ($get['to'] ?? ''));
    if ($to !== '') {
        if (isValidDate($to)) {
            $where[] = 't.borrow_date <= :to_date';
            $params[':to_date'] = $to;
        } else {
            $messages[] = 'The "to" date was ignored because it is not a valid date.';
        }
    }

    $search = trim((string) ($get['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(b.full_name ILIKE :q_name OR b.borrower_no ILIKE :q_no)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $params[':q_name'] = $like;
        $params[':q_no'] = $like;
    }

    $sql = 'SELECT t.borrow_id, b.borrower_no, b.full_name AS borrower_name, b.borrower_type,
                   t.borrow_date, t.due_date, t.status
            FROM borrow_transactions AS t
            JOIN borrowers AS b ON b.borrower_id = t.borrower_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.borrow_date DESC, t.borrow_id DESC';

    return [$sql, $params];
}

/* ------------------------------ run every report ------------------------------ */
function headerLabel(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

$results = [];
foreach ($reports as $key => $report) {
    $sql = $report['sql'];
    $params = [];
    $messages = [];

    if ($key === 'w6_filtered') {
        [$sql, $params] = buildFilteredBorrowingQuery($_GET, $statusChoices, $typeChoices, $messages);
    }

    $rows = [];
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Report ' . $key . ' failed: ' . $e->getMessage());
        $messages[] = 'This report could not be loaded. Make sure db/week07_10_update.sql has been run. '
            . dbErrorMessage($e);
    }

    $results[$key] = ['rows' => $rows, 'messages' => $messages];
}

$weeks = [];
foreach ($reports as $key => $report) {
    $weeks[$report['group']][] = $key;
}

renderHeader('Reports', 'reports');
?>
    <h1>Reports</h1>
    <p class="subtitle">
        Required reports, organized by week. Each one runs live against the PostgreSQL database.
        Jump to:
        <?php foreach (array_keys($weeks) as $index => $weekName): ?>
            <?= $index > 0 ? ' &middot; ' : '' ?><a href="#<?= h(strtolower(str_replace(' ', '-', $weekName))) ?>"><?= h($weekName) ?></a>
        <?php endforeach; ?>
    </p>

    <?php foreach ($weeks as $weekName => $keys): ?>
        <h2 id="<?= h(strtolower(str_replace(' ', '-', $weekName))) ?>" class="week-heading"><?= h($weekName) ?></h2>

        <?php foreach ($keys as $key): ?>
            <?php $report = $reports[$key]; $rows = $results[$key]['rows']; ?>
            <section id="<?= h($key) ?>">
                <h3><?= h($report['title']) ?></h3>
                <p class="note"><?= h($report['note']) ?></p>

                <?php if ($key === 'w6_filtered'): ?>
                    <form method="get" action="reports.php#w6_filtered">
                        <div class="filters">
                            <div>
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <?php foreach ($statusChoices as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= (string) ($_GET['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="borrower_type">Borrower type</label>
                                <select id="borrower_type" name="borrower_type">
                                    <?php foreach ($typeChoices as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= (string) ($_GET['borrower_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="from">Borrowed from</label>
                                <input id="from" type="date" name="from" value="<?= h($_GET['from'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="to">Borrowed to</label>
                                <input id="to" type="date" name="to" value="<?= h($_GET['to'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="q">Borrower name / number</label>
                                <input id="q" type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>">
                            </div>
                        </div>
                        <button type="submit">Apply filters</button>
                        <a href="reports.php#w6_filtered">Clear</a>
                    </form>
                    <br>
                <?php endif; ?>

                <?php foreach ($results[$key]['messages'] as $message): ?>
                    <div class="flash error" role="alert"><?= h($message) ?></div>
                <?php endforeach; ?>

                <?php if (empty($rows)): ?>
                    <p class="empty">No rows to show.</p>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead>
                            <tr>
                                <?php foreach (array_keys($rows[0]) as $column): ?>
                                    <th><?= h(headerLabel($column)) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $column => $value): ?>
                                        <td><?= renderCell((string) $column, $value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <p class="note"><?= count($rows) ?> row(s)</p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php renderFooter($pdo);
