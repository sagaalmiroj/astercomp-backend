<?php

header('Content-Type: application/json; charset=utf-8');

$result = [
    'success' => false,
    'php_version' => PHP_VERSION,
    'mysqli_loaded' => extension_loaded('mysqli'),
    'mysql' => [
        'host_exists' => !empty(getenv('MYSQLHOST')),
        'port_exists' => !empty(getenv('MYSQLPORT')),
        'database_exists' => !empty(getenv('MYSQLDATABASE')),
        'user_exists' => !empty(getenv('MYSQLUSER')),
        'password_exists' => !empty(getenv('MYSQLPASSWORD'))
    ]
];

if (!extension_loaded('mysqli')) {
    $result['error'] = 'mysqli extension belum aktif';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$host = getenv('MYSQLHOST');
$port = (int) (getenv('MYSQLPORT') ?: 3306);
$database = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');

if (!$host || !$database || !$user || !$password) {
    $result['error'] = 'Environment variable MySQL belum lengkap';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

try {
    $conn = new mysqli(
        $host,
        $user,
        $password,
        $database,
        $port
    );

    if ($conn->connect_error) {
        $result['error'] = 'Koneksi MySQL gagal';
        $result['mysql_error'] = $conn->connect_error;

        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    $result['success'] = true;
    $result['mysql']['connected'] = true;
    $result['mysql']['server_info'] = $conn->server_info;

    $conn->close();

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    $result['error'] = 'Exception saat koneksi MySQL';
    $result['message'] = $e->getMessage();

    echo json_encode($result, JSON_PRETTY_PRINT);
}
