<?php

require __DIR__ . '/../config/db.php';

try {
    $pdo = getPdo();
} catch (PDOException $e) {
    die('Database connection failed.');
}

// ----------------------------------------------------
// CREATE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'create'
) {
    $stmt = $pdo->prepare(
        'INSERT INTO students_demo
            (student_no, student_name, email)
         VALUES
            (:student_no, :student_name, :email)'
    );

    $stmt->execute([
        ':student_no' => trim($_POST['student_no'] ?? ''),
        ':student_name' => trim($_POST['student_name'] ?? ''),
        ':email' => trim($_POST['email'] ?? '')
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----------------------------------------------------
// UPDATE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update'
) {
    $stmt = $pdo->prepare(
        'UPDATE students_demo
         SET student_no = :student_no,
             student_name = :student_name,
             email = :email
         WHERE student_id = :student_id'
    );

    $stmt->execute([
        ':student_no' => trim($_POST['student_no'] ?? ''),
        ':student_name' => trim($_POST['student_name'] ?? ''),
        ':email' => trim($_POST['email'] ?? ''),
        ':student_id' => (int)($_POST['student_id'] ?? 0)
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----------------------------------------------------
// DELETE
// ----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'delete'
) {
    $stmt = $pdo->prepare(
        'DELETE FROM students_demo
         WHERE student_id = :student_id'
    );

    $stmt->execute([
        ':student_id' => (int)($_POST['student_id'] ?? 0)
    ]);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----------------------------------------------------
// READ
// ----------------------------------------------------

$stmt = $pdo->query(
    'SELECT student_id, student_no, student_name, email
     FROM students_demo
     ORDER BY student_id DESC'
);

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Records</title>
    <style>
        :root {
            --border: #e2e2e2;
            --text: #2b2b2b;
            --muted: #6b6b6b;
            --accent: #2f6f4f;
            --danger: #a33;
            --bg-soft: #fafafa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--text);
            max-width: 760px;
            margin: 48px auto;
            padding: 0 20px;
            line-height: 1.5;
        }

        h1 {
            font-size: 22px;
            margin-bottom: 4px;
        }

        p.subtitle {
            color: var(--muted);
            margin-top: 0;
            margin-bottom: 32px;
            font-size: 14px;
        }

        section {
            margin-bottom: 40px;
        }

        h2 {
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 12px;
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

        input[type="text"],
        input[type="email"],
        input[type="number"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text);
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
        }

        button {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--accent);
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            cursor: pointer;
        }

        button:hover {
            opacity: 0.9;
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

        th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--muted);
            padding: 8px 10px;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }

        tr:hover td {
            background: var(--bg-soft);
        }

        .row-delete form {
            display: inline;
        }

        .row-delete button {
            background: none;
            border: none;
            color: var(--danger);
            padding: 4px 6px;
            font-size: 13px;
        }

        .row-delete button:hover {
            text-decoration: underline;
            opacity: 1;
        }

        .empty {
            color: var(--muted);
            font-size: 14px;
            padding: 16px 0;
        }
    </style>
</head>
<body>
    <h1>Student Records</h1>
    <p class="subtitle">Add, review, and update enrolled students.</p>

    <section>
        <h2>Add Student</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="field">
                <label for="create_student_no">Student No.</label>
                <input id="create_student_no" type="text" name="student_no" required>
            </div>
            <div class="field">
                <label for="create_student_name">Full Name</label>
                <input id="create_student_name" type="text" name="student_name" required>
            </div>
            <div class="field">
                <label for="create_email">Email</label>
                <input id="create_email" type="email" name="email">
            </div>
            <button type="submit">Add Student</button>
        </form>
    </section>

    <section>
        <h2>Records</h2>
        <?php if (empty($students)): ?>
            <p class="empty">No students yet — add one above to get started.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><?= (int)$student['student_id'] ?></td>
                    <td><?= htmlspecialchars($student['student_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($student['student_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($student['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="row-delete">
                        <form method="post">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= (int)$student['student_id'] ?>">
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
        <h2>Update Student</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <div class="field">
                <label for="update_student_id">Student ID</label>
                <input id="update_student_id" type="number" name="student_id" required>
            </div>
            <div class="field">
                <label for="update_student_no">Student No.</label>
                <input id="update_student_no" type="text" name="student_no" required>
            </div>
            <div class="field">
                <label for="update_student_name">Full Name</label>
                <input id="update_student_name" type="text" name="student_name" required>
            </div>
            <div class="field">
                <label for="update_email">Email</label>
                <input id="update_email" type="email" name="email">
            </div>
            <button type="submit" class="secondary">Update Student</button>
        </form>
    </section>
</body>
</html>