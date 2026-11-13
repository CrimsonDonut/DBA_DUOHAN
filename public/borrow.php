<?php

require __DIR__ . '/_common.php';

$pdo = connect();

$borrowers = $pdo->query(
    'SELECT borrower_id, borrower_no, full_name FROM borrowers ORDER BY full_name'
)->fetchAll(PDO::FETCH_ASSOC);

$equipment = $pdo->query(
    'SELECT equipment_id, property_no, equipment_name, quantity_available
     FROM equipment ORDER BY equipment_name'
)->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ---------- validate input ---------- */
    $borrowerId = filter_var($_POST['borrower_id'] ?? '', FILTER_VALIDATE_INT);
    $borrowDate = trim((string) ($_POST['borrow_date'] ?? ''));
    $dueDate = trim((string) ($_POST['due_date'] ?? ''));

    if ($borrowerId === false) {
        $errors[] = 'Select a borrower.';
    }
    if (!isValidDate($borrowDate)) {
        $errors[] = 'Borrow date must be a valid date.';
    }
    if (!isValidDate($dueDate)) {
        $errors[] = 'Due date must be a valid date.';
    }
    if (isValidDate($borrowDate) && isValidDate($dueDate) && $dueDate < $borrowDate) {
        $errors[] = 'Due date cannot be earlier than the borrow date.';
    }

    $equipmentIds = is_array($_POST['equipment_id'] ?? null) ? $_POST['equipment_id'] : [];
    $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $items = [];
    $seen = [];

    foreach ($equipmentIds as $index => $rawEquipment) {
        $rawEquipment = trim((string) $rawEquipment);
        $rawQuantity = trim((string) ($quantities[$index] ?? ''));

        if ($rawEquipment === '' && $rawQuantity === '') {
            continue; // empty row
        }

        $equipmentId = filter_var($rawEquipment, FILTER_VALIDATE_INT);
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);
        $line = count($items) + 1;

        if ($equipmentId === false) {
            $errors[] = 'Item ' . $line . ': select equipment.';
            continue;
        }
        if ($quantity === false || $quantity < 1) {
            $errors[] = 'Item ' . $line . ': quantity must be a whole number of at least 1.';
            continue;
        }
        if (isset($seen[$equipmentId])) {
            $errors[] = 'Item ' . $line . ': the same equipment is selected more than once.';
            continue;
        }

        $seen[$equipmentId] = true;
        $items[] = [$equipmentId, $quantity];
    }

    if (empty($items) && empty($errors)) {
        $errors[] = 'Add at least one equipment item.';
    }

    /* ---------- save header + items as ONE transaction ---------- */
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $header = $pdo->prepare(
                "INSERT INTO borrow_transactions (borrower_id, borrow_date, due_date, status)
                 VALUES (?, ?, ?, 'Active') RETURNING borrow_id"
            );
            $header->execute([$borrowerId, $borrowDate, $dueDate]);
            $borrowId = (int) $header->fetchColumn();

            $itemStmt = $pdo->prepare(
                'INSERT INTO borrow_items (borrow_id, equipment_id, quantity) VALUES (?, ?, ?)'
            );
            foreach ($items as [$equipmentId, $quantity]) {
                $itemStmt->execute([$borrowId, $equipmentId, $quantity]);
            }

            if (!empty($_POST['simulate_failure'])) {
                throw new RuntimeException('Simulated failure after saving the header and items.');
            }

            $pdo->commit();
            flash('success', 'Borrowing transaction #' . $borrowId . ' saved with ' . count($items) . ' item(s).');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $reason = $e instanceof PDOException ? dbErrorMessage($e) : $e->getMessage();
            if ($e instanceof PDOException) {
                error_log('Borrow transaction failed: ' . $e->getMessage());
            }
            $errors[] = 'Nothing was saved (transaction rolled back). ' . $reason;
        }
    }
}

/* Re-display submitted rows after an error; otherwise start with one blank row. */
$rowsToShow = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['equipment_id']) && is_array($_POST['equipment_id'])) {
    foreach ($_POST['equipment_id'] as $index => $value) {
        $rowsToShow[] = [(string) $value, (string) (($_POST['quantity'][$index] ?? ''))];
    }
}
if (empty($rowsToShow)) {
    $rowsToShow[] = ['', '1'];
}

$recent = $pdo->query(
    "SELECT t.borrow_id,
            b.borrower_no,
            b.full_name,
            t.borrow_date,
            t.due_date,
            t.status,
            string_agg(e.equipment_name || ' x' || i.quantity, ', ' ORDER BY e.equipment_name) AS items
     FROM borrow_transactions AS t
     JOIN borrowers AS b ON b.borrower_id = t.borrower_id
     LEFT JOIN borrow_items AS i ON i.borrow_id = t.borrow_id
     LEFT JOIN equipment AS e ON e.equipment_id = i.equipment_id
     GROUP BY t.borrow_id, b.borrower_no, b.full_name, t.borrow_date, t.due_date, t.status
     ORDER BY t.borrow_id DESC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM borrow_transactions WHERE status IN ('Active', 'Overdue')) AS open_loans,
        (SELECT COUNT(*) FROM borrow_transactions
          WHERE status = 'Overdue' OR (status = 'Active' AND due_date < CURRENT_DATE)) AS overdue_loans,
        (SELECT COALESCE(SUM(i.quantity), 0)
           FROM borrow_items AS i
           JOIN borrow_transactions AS t ON t.borrow_id = i.borrow_id
          WHERE t.status IN ('Active', 'Overdue')) AS units_out,
        (SELECT COUNT(*) FROM equipment WHERE quantity_available <= 3) AS low_stock_items"
)->fetch(PDO::FETCH_ASSOC);

$gear = $pdo->query(
    "SELECT e.equipment_id, e.property_no, e.equipment_name, e.quantity_available,
            COALESCE(SUM(i.quantity) FILTER (WHERE t.status IN ('Active', 'Overdue')), 0)::int AS out_qty
     FROM equipment AS e
     LEFT JOIN borrow_items AS i ON i.equipment_id = e.equipment_id
     LEFT JOIN borrow_transactions AS t ON t.borrow_id = i.borrow_id
     GROUP BY e.equipment_id, e.property_no, e.equipment_name, e.quantity_available
     ORDER BY e.equipment_name"
)->fetchAll(PDO::FETCH_ASSOC);

$selectedBorrower = $_POST['borrower_id'] ?? '';
$borrowDateValue = $_POST['borrow_date'] ?? date('Y-m-d');
$dueDateValue = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));

function equipmentOptions(array $equipment, string $selected): string
{
    $html = '<option value="">-- select equipment --</option>';
    foreach ($equipment as $item) {
        $isSelected = (string) $item['equipment_id'] === $selected ? ' selected' : '';
        $label = $item['property_no'] . ' - ' . $item['equipment_name']
            . ' (' . $item['quantity_available'] . ' available)';
        $html .= '<option value="' . h($item['equipment_id']) . '"' . $isSelected . '>' . h($label) . '</option>';
    }
    return $html;
}

renderHeader('New Borrowing', 'borrow');
?>
    <h1>New Borrowing</h1>
    <p class="subtitle">Check out gym gear to a borrower. The transaction and every item on it are saved together, or not at all.</p>

    <?php renderFlash($errors); ?>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">Open loans</div>
            <div class="stat-value"><?= h($stats['open_loans']) ?></div>
            <div class="stat-hint">Active or overdue</div>
        </div>
        <div class="stat">
            <div class="stat-label">Units out</div>
            <div class="stat-value"><?= h($stats['units_out']) ?></div>
            <div class="stat-hint">Pieces currently borrowed</div>
        </div>
        <div class="stat <?= (int) $stats['overdue_loans'] > 0 ? 'alert' : '' ?>">
            <div class="stat-label">Overdue</div>
            <div class="stat-value"><?= h($stats['overdue_loans']) ?></div>
            <div class="stat-hint">Past the due date</div>
        </div>
        <div class="stat <?= (int) $stats['low_stock_items'] > 0 ? 'warn' : '' ?>">
            <div class="stat-label">Low stock</div>
            <div class="stat-value"><?= h($stats['low_stock_items']) ?></div>
            <div class="stat-hint">Items with 3 or fewer left</div>
        </div>
    </div>

    <section>
        <h2>Borrowing form</h2>
        <form method="post" id="borrow-form">
            <div class="grid-2">
                <div class="field">
                    <label for="borrower_id">Borrower</label>
                    <select id="borrower_id" name="borrower_id" required>
                        <option value="">-- select borrower --</option>
                        <?php foreach ($borrowers as $borrower): ?>
                            <option value="<?= h($borrower['borrower_id']) ?>" <?= (string) $borrower['borrower_id'] === (string) $selectedBorrower ? 'selected' : '' ?>>
                                <?= h($borrower['borrower_no'] . ' - ' . $borrower['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div></div>
                <div class="field">
                    <label for="borrow_date">Borrow date</label>
                    <input id="borrow_date" type="date" name="borrow_date" value="<?= h($borrowDateValue) ?>" required>
                </div>
                <div class="field">
                    <label for="due_date">Due date</label>
                    <input id="due_date" type="date" name="due_date" value="<?= h($dueDateValue) ?>" required>
                </div>
            </div>

            <h3>Equipment items</h3>
            <div id="item-rows">
                <?php foreach ($rowsToShow as [$equipmentValue, $quantityValue]): ?>
                    <div class="item-row">
                        <div>
                            <label>Equipment</label>
                            <select name="equipment_id[]"><?= equipmentOptions($equipment, $equipmentValue) ?></select>
                        </div>
                        <div>
                            <label>Quantity</label>
                            <input type="number" name="quantity[]" min="1" step="1" value="<?= h($quantityValue) ?>">
                        </div>
                        <button type="button" class="secondary remove-row">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="secondary" id="add-row">+ Add another item</button></p>

            <div class="field">
                <label class="inline-check">
                    <input type="checkbox" name="simulate_failure" value="1" <?= !empty($_POST['simulate_failure']) ? 'checked' : '' ?>>
                    Demo: force a failure after saving, to show the transaction is rolled back
                </label>
            </div>

            <button type="submit">Save Borrowing</button>
        </form>
    </section>

    <section>
        <h2>Gear availability</h2>
        <?php if (empty($gear)): ?>
            <p class="empty">No equipment registered yet.</p>
        <?php else: ?>
        <div class="gear-grid">
            <?php foreach ($gear as $item): ?>
                <?php
                    $left = (int) $item['quantity_available'];
                    $level = $left === 0 ? 'Out of Stock' : ($left <= 3 ? 'Low Stock' : 'Available');
                ?>
                <div class="gear">
                    <div>
                        <div class="gear-name"><?= h($item['equipment_name']) ?></div>
                        <div class="gear-code"><?= h($item['property_no']) ?></div>
                    </div>
                    <div class="gear-count"><?= $left ?><small>available</small></div>
                    <div class="gear-foot">
                        <span><?= (int) $item['out_qty'] ?> out on loan</span>
                        <?= badge($level) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section>
        <h2>Latest transactions</h2>
        <?php if (empty($recent)): ?>
            <p class="empty">No transactions yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead>
                <tr><th>ID</th><th>Borrower</th><th>Items</th><th>Borrowed</th><th>Due</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= h($row['borrow_id']) ?></td>
                        <td><?= h($row['borrower_no'] . ' - ' . $row['full_name']) ?></td>
                        <td><?= h($row['items'] ?? '(no items)') ?></td>
                        <td><?= h($row['borrow_date']) ?></td>
                        <td><?= h($row['due_date']) ?></td>
                        <td><?= badge((string) $row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>

    <template id="row-template">
        <div class="item-row">
            <div>
                <label>Equipment</label>
                <select name="equipment_id[]"><?= equipmentOptions($equipment, '') ?></select>
            </div>
            <div>
                <label>Quantity</label>
                <input type="number" name="quantity[]" min="1" step="1" value="1">
            </div>
            <button type="button" class="secondary remove-row">Remove</button>
        </div>
    </template>

    <script>
        const rows = document.getElementById('item-rows');
        const template = document.getElementById('row-template');

        document.getElementById('add-row').addEventListener('click', () => {
            rows.appendChild(template.content.cloneNode(true));
        });

        rows.addEventListener('click', (event) => {
            if (!event.target.classList.contains('remove-row')) return;
            if (rows.querySelectorAll('.item-row').length > 1) {
                event.target.closest('.item-row').remove();
            }
        });
    </script>
<?php renderFooter($pdo);
