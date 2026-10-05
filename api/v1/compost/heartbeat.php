<?php

// ============================================================
// ASTER SMART COMPOST API
// HEARTBEAT ENDPOINT
// ============================================================
//
// Endpoint:
// POST /api/v1/compost/heartbeat.php
//
// Fungsi:
// 1. Menerima heartbeat dari ESP32 Node 1-5.
// 2. Validasi API Key.
// 3. Validasi node_id.
// 4. Membaca last_seen dari raw_data.
// 5. Menentukan status ONLINE/OFFLINE.
//
// CATATAN:
// - Endpoint ini TIDAK menggunakan tabel node_status.
// - Status node pada ASTERCOMP sekarang ditentukan berdasarkan
//   raw_data.recorded_at.
// - latest.php menggunakan timeout 30 detik.
// - Heartbeat tidak disimpan ke database karena raw_data sudah
//   menjadi indikator aktivitas node.
//
// ============================================================


// ============================================================
// TIMEZONE
// ============================================================

date_default_timezone_set("Asia/Jakarta");


// ============================================================
// CORS
// ============================================================

$allowedOrigin = "https://astercompv1.up.railway.app";

header(
    "Access-Control-Allow-Origin: " . $allowedOrigin
);

header(
    "Access-Control-Allow-Methods: POST, OPTIONS"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"
);

header(
    "Access-Control-Max-Age: 86400"
);

header(
    "Vary: Origin"
);

header(
    "Content-Type: application/json; charset=utf-8"
);


// ============================================================
// RESPONSE HELPER
// ============================================================

function responseJson(
    int $httpCode,
    array $data
): void {

    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ============================================================
// PREFLIGHT
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(204);

    exit;
}


// ============================================================
// ONLY POST
// ============================================================

if (
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {

    responseJson(
        405,
        [
            "success" => false,

            "message" =>
                "Method not allowed",

            "allowed_method" =>
                "POST"
        ]
    );
}


// ============================================================
// ENVIRONMENT
// ============================================================

$API_KEY =
    getenv("API_KEY");

$DB_HOST =
    getenv("MYSQLHOST");

$DB_PORT =
    getenv("MYSQLPORT");

$DB_NAME =
    getenv("MYSQLDATABASE");

$DB_USER =
    getenv("MYSQLUSER");

$DB_PASS =
    getenv("MYSQLPASSWORD");


// ============================================================
// CHECK API KEY ENVIRONMENT
// ============================================================

if (
    $API_KEY === false ||
    trim($API_KEY) === ""
) {

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "API_KEY environment variable is not configured"
        ]
    );
}


// ============================================================
// CHECK DATABASE ENVIRONMENT
// ============================================================

if (
    $DB_HOST === false ||
    $DB_PORT === false ||
    $DB_NAME === false ||
    $DB_USER === false ||
    $DB_PASS === false
) {

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Database environment variables are incomplete"
        ]
    );
}


// ============================================================
// AUTHORIZATION HEADER
// ============================================================

$authorization = "";


// ------------------------------------------------------------
// METHOD 1
// ------------------------------------------------------------

if (
    isset($_SERVER["HTTP_AUTHORIZATION"])
) {

    $authorization =
        trim(
            $_SERVER["HTTP_AUTHORIZATION"]
        );
}


// ------------------------------------------------------------
// METHOD 2
// ------------------------------------------------------------

if (
    $authorization === "" &&
    function_exists("getallheaders")
) {

    $headers =
        getallheaders();

    foreach (
        $headers as $key => $value
    ) {

        if (
            strtolower($key) === "authorization"
        ) {

            $authorization =
                trim($value);

            break;
        }
    }
}


// ============================================================
// EXTRACT BEARER TOKEN
// ============================================================

$receivedKey = "";

if (
    strncasecmp(
        $authorization,
        "Bearer ",
        7
    ) === 0
) {

    $receivedKey =
        trim(
            substr(
                $authorization,
                7
            )
        );
}


// ============================================================
// VALIDATE API KEY
// ============================================================

if (
    $receivedKey === ""
) {

    responseJson(
        401,
        [
            "success" => false,

            "message" =>
                "Authorization header missing"
        ]
    );
}


if (
    !hash_equals(
        $API_KEY,
        $receivedKey
    )
) {

    responseJson(
        401,
        [
            "success" => false,

            "message" =>
                "Invalid API key"
        ]
    );
}


// ============================================================
// CONTENT TYPE
// ============================================================

$contentType =
    $_SERVER["CONTENT_TYPE"] ?? "";


if (
    stripos(
        $contentType,
        "application/json"
    ) === false
) {

    responseJson(
        415,
        [
            "success" => false,

            "message" =>
                "Content-Type must be application/json"
        ]
    );
}


// ============================================================
// READ REQUEST BODY
// ============================================================

$rawData =
    file_get_contents(
        "php://input"
    );


if (
    $rawData === false ||
    trim($rawData) === ""
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Request body is empty",

            "example" => [
                "node_id" => 1
            ]
        ]
    );
}


// ============================================================
// DECODE JSON
// ============================================================

$data =
    json_decode(
        $rawData,
        true
    );


if (
    !is_array($data)
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Invalid JSON"
        ]
    );
}


// ============================================================
// REQUIRED FIELD
// ============================================================

if (
    !array_key_exists(
        "node_id",
        $data
    )
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Missing field: node_id",

            "example" => [
                "node_id" => 1
            ]
        ]
    );
}


// ============================================================
// VALIDATE NODE ID
// ============================================================

$nodeId =
    filter_var(
        $data["node_id"],
        FILTER_VALIDATE_INT
    );


if (
    $nodeId === false ||
    $nodeId < 1 ||
    $nodeId > 5
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "node_id must be an integer between 1 and 5"
        ]
    );
}


// ============================================================
// DATABASE CONNECTION
// ============================================================

mysqli_report(
    MYSQLI_REPORT_OFF
);


$conn =
    new mysqli(
        $DB_HOST,
        $DB_USER,
        $DB_PASS,
        $DB_NAME,
        (int)$DB_PORT
    );


if (
    $conn->connect_error
) {

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Database connection failed",

            "error" =>
                $conn->connect_error
        ]
    );
}


if (
    !$conn->set_charset("utf8mb4")
) {

    $conn->close();

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Failed to configure database character set"
        ]
    );
}


// ============================================================
// HEARTBEAT TIMEOUT
// ============================================================
//
// Harus sama dengan latest.php.
//
// ============================================================

$heartbeatTimeout = 30;


// ============================================================
// GET LATEST RAW DATA
// ============================================================

$sql = "

SELECT
    recorded_at

FROM raw_data

WHERE node_id = ?

ORDER BY recorded_at DESC
LIMIT 1

";


$stmt =
    $conn->prepare(
        $sql
    );


if (
    !$stmt
) {

    $error =
        $conn->error;

    $conn->close();

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "SQL prepare failed",

            "error" =>
                $error
        ]
    );
}


// ============================================================
// BIND NODE ID
// ============================================================

$stmt->bind_param(
    "i",
    $nodeId
);


// ============================================================
// EXECUTE
// ============================================================

if (
    !$stmt->execute()
) {

    $error =
        $stmt->error;

    $stmt->close();

    $conn->close();

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Failed to retrieve latest node data",

            "error" =>
                $error
        ]
    );
}


// ============================================================
// GET RECORDED_AT
// ============================================================

$lastSeen = null;


$stmt->bind_result(
    $lastSeen
);


$stmt->fetch();

$stmt->close();


// ============================================================
// DETERMINE NODE STATUS
// ============================================================

$status = "OFFLINE";

$ageSeconds = null;


if (
    $lastSeen !== null
) {

    $lastSeenTimestamp =
        strtotime($lastSeen);

    $serverTimestamp =
        time();


    if (
        $lastSeenTimestamp !== false
    ) {

        $ageSeconds =
            max(
                0,
                $serverTimestamp -
                $lastSeenTimestamp
            );


        if (
            $ageSeconds <= $heartbeatTimeout
        ) {

            $status = "ONLINE";
        }
    }
}


// ============================================================
// SERVER TIME
// ============================================================

$serverTime =
    date(
        "Y-m-d H:i:s"
    );


// ============================================================
// CLOSE DATABASE
// ============================================================

$conn->close();


// ============================================================
// SUCCESS RESPONSE
// ============================================================

responseJson(
    200,
    [

        "success" =>
            true,

        "message" =>
            "Heartbeat received successfully",

        "node_id" =>
            (int)$nodeId,

        "status" =>
            $status,

        "last_seen" =>
            $lastSeen,

        "age_seconds" =>
            $ageSeconds,

        "heartbeat_timeout" =>
            $heartbeatTimeout,

        "server_time" =>
            $serverTime,

        "timezone" =>
            "Asia/Jakarta",

        "_debug_version" =>
            "heartbeat-v2-2026-10-05"
    ]
);

?>
