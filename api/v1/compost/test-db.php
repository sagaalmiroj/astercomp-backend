<?php

header('Content-Type: application/json; charset=utf-8');

$result = [
    'success' => false,
    'php_version' => PHP_VERSION,
    'mysqli_loaded' => extension_loaded('mysqli'),
    'mysql_env' => [
        'MYSQLHOST' => !empty(getenv('MYSQLHOST')),
        'MYSQLPORT' => !empty(getenv('MYSQLPORT')),
        'MYSQLDATABASE' => !empty(getenv('MYSQLDATABASE')),
        'MYSQLUSER' => !empty(getenv('MYSQLUSER')),
        'MYSQLPASSWORD' => !empty(getenv('MYSQLPASSWORD'))
    ]
];

if (!extension_loaded('mysqli')) {
    $result['error'] = 'mysqli extension TIDAK AKTIF';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$host = getenv('MYSQLHOST');
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$database = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');

if (!$host || !$database || !$user || !$password) {
    $result['error'] = 'Environment variable MySQL TIDAK LENGKAP';
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
        $result['error'] = 'KONEKSI MYSQL GAGAL';
        $result['mysql_error'] = $conn->connect_error;

        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }

    $result['success'] = true;
    $result['mysql_connected'] = true;
    $result['mysql_server'] = $conn->server_info;

    $conn->close();

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Throwable $e) {

    $result['error'] = 'ERROR SAAT KONEKSI MYSQL';
    $result['message'] = $e->getMessage();

    echo json_encode($result, JSON_PRETTY_PRINT);
}
