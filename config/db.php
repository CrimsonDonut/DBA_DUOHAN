<?php

function getPdo(): PDO
{
    $config = [
        'host' => getenv('DB_HOST') ?: '10.14.121.53', // IP ni Ryan
        'port' => getenv('DB_PORT') ?: '5432',
        'dbname' => getenv('DB_NAME') ?: 'Duo_Ton', // 
        'user' => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: '1234', // Password ni Ryan: 1234
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
