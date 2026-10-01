<?php

header("Content-Type: application/json; charset=UTF-8");


// ==================================================
// TIMEZONE
// ==================================================

date_default_timezone_set("Asia/Jakarta");


// ==================================================
// DATABASE CONFIG
// ==================================================

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");


// ==================================================
// VALIDATE DATABASE CONFIG
// ==================================================

if (
    !$DB_HOST ||
    !$DB_PORT ||
    !$DB_NAME ||
    !$DB_USER ||
    !$DB_PASS
) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ]);

    exit;
}


// ==================================================
// DATABASE CONNECTION
// ==================================================

$conn = new mysqli(
    $DB_HOST,
    $DB_USER,
    $DB_PASS,
    $DB_NAME,
    (int)$DB_PORT
);


// ==================================================
// CHECK CONNECTION
// ==================================================

if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed",
        "error" => $conn->connect_error
    ]);

    exit;
}


// ==================================================
// CHARACTER SET
// ==================================================

$conn->set_charset("utf8mb4");


// ==================================================
// CREATE NODE STATUS TABLE
// ==================================================

$sql = "
CREATE TABLE IF NOT EXISTS node_status (

    node_id INT NOT NULL,

    status ENUM(
        'ONLINE',
        'OFFLINE'
    ) NOT NULL DEFAULT 'OFFLINE',

    last_seen DATETIME NULL,

    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (node_id)

)
";


if (!$conn->query($sql)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to create node_status table",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}


// ==================================================
// INITIALIZE NODE 1-5
// ==================================================
//
// Hanya membuat node jika belum ada.
//
// Data status node yang sudah ada TIDAK diubah.
//

$insertSQL = "
INSERT INTO node_status
(
    node_id,
    status
)
VALUES
    (1, 'OFFLINE'),
    (2, 'OFFLINE'),
    (3, 'OFFLINE'),
    (4, 'OFFLINE'),
    (5, 'OFFLINE')

ON DUPLICATE KEY UPDATE
    node_id = node_id
";


if (!$conn->query($insertSQL)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to initialize nodes",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}


// ==================================================
// READ NODE STATUS
// ==================================================

$result = $conn->query("
    SELECT
        node_id,
        status,
        last_seen,
        updated_at
    FROM node_status
    ORDER BY node_id ASC
");


if (!$result) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to read node_status",
        "error" => $conn->error
    ]);

    $conn->close();

    exit;
}


// ==================================================
// BUILD RESPONSE
// ==================================================

$nodes = [];

while ($row = $result->fetch_assoc()) {

    $nodes[] = [
        "node_id" =>
            (int)$row["node_id"],

        "status" =>
            $row["status"],

        "last_seen" =>
            $row["last_seen"],

        "updated_at" =>
            $row["updated_at"]
    ];
}


// ==================================================
// RESPONSE
// ==================================================

echo json_encode(
    [
        "success" => true,

        "message" =>
            "node_status table verified successfully",

        "nodes" =>
            $nodes
    ],
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE
);


// ==================================================
// CLOSE
// ==================================================

$conn->close();

?>
