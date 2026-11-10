<?php

require __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function connect(): PDO
{
    try {
        return getPdo();
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Database connection failed. Check config/db.php and that the PostgreSQL server accepts connections from this computer.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function renderFlash(array $extraErrors = []): void
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    foreach ($extraErrors as $error) {
        $messages[] = ['type' => 'error', 'message' => $error];
    }

    foreach ($messages as $item) {
        $type = $item['type'] === 'success' ? 'success' : 'error';
        echo '<div class="flash ' . $type . '" role="alert">' . h($item['message']) . '</div>';
    }
}

/** Turn a PDOException from PostgreSQL into a short, readable message. */
function dbErrorMessage(PDOException $e): string
{
    $state = (string) ($e->errorInfo[0] ?? '');
    $detail = (string) ($e->errorInfo[2] ?? $e->getMessage());
    $detail = preg_replace('/^ERROR:\s*/', '', $detail);
    $firstLine = trim((string) strtok($detail, "\n"));

    switch ($state) {
        case '23505':
            $label = 'Duplicate value';
            break;
        case '23503':
            $label = 'Invalid or in-use reference';
            break;
        case '23514':
            $label = 'Value not allowed';
            break;
        case '23502':
            $label = 'Required value missing';
            break;
        case 'P0001':
            $label = 'Rule violated';
            break;
        case '22P02':
        case '22007':
        case '22008':
        case '22003':
            $label = 'Invalid value format';
            break;
        default:
            $label = 'Database error';
    }

    return $label . ': ' . $firstLine;
}

function isValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function renderHeader(string $title, string $active): void
{
    $links = [
        'crud'    => ['crud_demo.php', 'Manage Records'],
        'borrow'  => ['borrow.php', 'New Borrowing'],
        'reports' => ['reports.php', 'Reports'],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="main-nav" aria-label="Main navigation">
        <?php foreach ($links as $key => [$href, $label]): ?>
            <a href="<?= h($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
<?php
}

function renderFooter(?PDO $pdo = null): void
{
    if ($pdo !== null) {
        try {
            $info = $pdo->query(
                "SELECT current_database() AS db,
                        COALESCE(host(inet_server_addr()), 'local socket') AS server_ip,
                        COALESCE(inet_server_port()::text, '-') AS server_port"
            )->fetch(PDO::FETCH_ASSOC);
            echo '<p class="conn-info">Connected to PostgreSQL server <strong>' . h($info['server_ip'])
                . ':' . h($info['server_port']) . '</strong>, database <strong>' . h($info['db']) . '</strong></p>';
        } catch (PDOException $e) {
            error_log('Connection info failed: ' . $e->getMessage());
        }
    }
    echo "</body>\n</html>\n";
}
