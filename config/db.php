<?php

function getPdo(): PDO
{
    $config = [
        'host' => getenv('DB_HOST') ?: '192.168.0.103',
        'port' => getenv('DB_PORT') ?: '5432',
        'dbname' => getenv('DB_NAME') ?: 'Week5',
        'user' => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: 'ton0127',
    ];

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $config['host'],
        $config['port'],
        $config['dbname']
    );

    $pdo = new PDO($dsn, $config['user'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
