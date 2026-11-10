<?php
/*
 * TEMPORARY diagnostic page. Open http://localhost/<project>/public/connection_test.php
 * It shows WHY the database connection fails. Delete it after the problem is solved.
 * The password is never printed.
 */

require __DIR__ . '/../config/db.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read host/port the same way config/db.php does (env variable, else the default in the file). */
function configValue(string $envName, string $key, string $fallback): string
{
    $env = getenv($envName);
    if ($env !== false && $env !== '') {
        return $env;
    }
    $source = @file_get_contents(__DIR__ . '/../config/db.php');
    if ($source !== false && preg_match("/'" . $key . "'\s*=>\s*getenv\('" . $envName . "'\)\s*\?:\s*'([^']*)'/", $source, $m)) {
        return $m[1];
    }
    return $fallback;
}

function hintFor(string $message): string
{
    $m = strtolower($message);

    if (strpos($m, 'could not find driver') !== false) {
        return 'PHP has no PostgreSQL driver. In XAMPP open php.ini (XAMPP Control Panel > Apache > Config > PHP (php.ini)), remove the ";" before extension=pdo_pgsql and extension=pgsql, save, then Stop and Start Apache.';
    }
    if (strpos($m, 'no pg_hba.conf entry') !== false) {
        return 'The server was reached, but its pg_hba.conf has no matching line. The message shows the client IP the server saw: that exact IP (or a range covering it), your database name and user must be on a "host" line in pg_hba.conf ON THE FRIEND\'S LAPTOP. Then run SELECT pg_reload_conf(); there.';
    }
    if (strpos($m, 'password authentication failed') !== false) {
        return 'Network and pg_hba.conf are fine. The user/password in config/db.php is wrong for the FRIEND\'S server. Use the password of that user on his PostgreSQL, not the one from your own laptop.';
    }
    if (strpos($m, 'does not exist') !== false && strpos($m, 'database') !== false) {
        return 'Connected, but that database name does not exist on the friend\'s server. Names are case-sensitive: compare the dbname in config/db.php with his pgAdmin exactly.';
    }
    if (strpos($m, 'connection refused') !== false) {
        return 'The laptop answered but PostgreSQL is not accepting connections on that IP/port. Most likely listen_addresses on the friend\'s laptop is still \'localhost\' (it needs \'*\' or his IP, followed by a full service RESTART), or the port is not 5432.';
    }
    if (strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false) {
        return 'Packets are not getting through. Check: (1) the Windows Firewall rule for port 5432 on the friend\'s laptop applies to the Public profile (hotspot networks are usually Public); (2) his IP has not changed; (3) the hotspot is not isolating clients.';
    }
    if (strpos($m, 'could not translate host name') !== false || strpos($m, 'name or service not known') !== false) {
        return 'The host value in config/db.php is not a valid address.';
    }
    if (strpos($m, 'no route to host') !== false || strpos($m, 'network is unreachable') !== false) {
        return 'This computer cannot reach that IP at all. Check both laptops are on the same hotspot and the IP is current.';
    }
    return 'See the message above. Compare it with the checklist.';
}

$host = configValue('DB_HOST', 'host', 'localhost');
$port = configValue('DB_PORT', 'port', '5432');
$dbname = configValue('DB_NAME', 'dbname', '?');
$user = configValue('DB_USER', 'user', '?');

$rows = [];

/* 1. PHP driver */
$drivers = PDO::getAvailableDrivers();
$hasDriver = in_array('pgsql', $drivers, true);
$rows[] = [
    'PHP PostgreSQL driver (pdo_pgsql)',
    $hasDriver ? 'OK' : 'MISSING',
    'PHP ' . PHP_VERSION . '; php.ini: ' . (php_ini_loaded_file() ?: 'none'),
    $hasDriver ? '' : hintFor('could not find driver'),
];

/* 2. Config in use (password never shown) */
$rows[] = ['Config in use', 'INFO', "host=$host  port=$port  dbname=$dbname  user=$user", ''];

/* 3. Raw TCP test to host:port (separates network problems from login problems) */
$tcpOk = false;
$errno = 0;
$errstr = '';
$socket = @fsockopen($host, (int) $port, $errno, $errstr, 4);
if ($socket) {
    fclose($socket);
    $tcpOk = true;
    $rows[] = ["TCP connection to $host:$port", 'OK', 'Something is listening and reachable.', ''];
} else {
    $detail = $errstr !== '' ? $errstr : 'no response within 4 seconds';
    $rows[] = [
        "TCP connection to $host:$port",
        'FAILED',
        $detail . ($errno ? " (code $errno)" : ''),
        hintFor($errstr !== '' ? $errstr : 'timed out'),
    ];
}

/* 4. Real PDO connection using the project's own getPdo() */
if ($hasDriver) {
    try {
        $pdo = getPdo();
        $info = $pdo->query(
            "SELECT current_database() AS db, current_user AS usr,
                    COALESCE(host(inet_server_addr()), 'local socket') AS server_ip,
                    COALESCE(host(inet_client_addr()), '-') AS client_ip,
                    split_part(version(), ',', 1) AS version"
        )->fetch(PDO::FETCH_ASSOC);
        $rows[] = [
            'Login with getPdo()',
            'OK',
            'database=' . $info['db'] . '  user=' . $info['usr'] . '  server=' . $info['server_ip']
                . '  seen-as-client=' . $info['client_ip'] . '  (' . $info['version'] . ')',
            '',
        ];
    } catch (Throwable $ex) {
        $rows[] = ['Login with getPdo()', 'FAILED', $ex->getMessage(), hintFor($ex->getMessage())];
    }
} else {
    $rows[] = ['Login with getPdo()', 'SKIPPED', 'Fix the missing driver first.', ''];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connection test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 30px auto; padding: 0 20px; color: #2b2b2b; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
        .OK { color: #1b7a3d; font-weight: bold; }
        .FAILED, .MISSING { color: #b32020; font-weight: bold; }
        .INFO, .SKIPPED { color: #666; font-weight: bold; }
        .hint { background: #fff6e0; border-left: 4px solid #e0a800; padding: 8px 10px; margin-top: 6px; }
        code { background: #f3f3f3; padding: 1px 4px; }
    </style>
</head>
<body>
    <h1>Database connection test</h1>
    <p>Delete <code>connection_test.php</code> once the connection works.</p>
    <table>
        <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($rows as [$check, $status, $detail, $hint]): ?>
            <tr>
                <td><?= e($check) ?></td>
                <td class="<?= e($status) ?>"><?= e($status) ?></td>
                <td>
                    <?= e($detail) ?>
                    <?php if ($hint !== ''): ?><div class="hint"><?= e($hint) ?></div><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
