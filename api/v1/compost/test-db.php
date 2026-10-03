<?php

header('Content-Type: application/json; charset=utf-8');

$host = getenv('MYSQLHOST');
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$database = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');

$result = [
    'php_version' => PHP_VERSION,
    'mysqli_loaded' => extension_loaded('mysqli'),

    'mysql_connection_target' => [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'user' => $user
    ]
];

if (!extension_loaded('mysqli')) {
    $result['error'] = 'mysqli tidak aktif';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

try {

    $conn = new mysqli(
        $host,
        $user,
        getenv('MYSQLPASSWORD'),
        $database,
        $port
    );

    if ($conn->connect_error) {
        $result['connected'] = false;
        $result['error'] = $conn->connect_error;

        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    $result['connected'] = true;
    $result['server_info'] = $conn->server_info;

    $conn->close();

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    $result['connected'] = false;
    $result['error'] = $e->getMessage();

    echo json_encode($result, JSON_PRETTY_PRINT);
}
