<?php

// ==================================================
// ASTERCOMP V1
// RAW DATA INGEST ENDPOINT
// POST /api/v1/compost/raw.php
// ==================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: https://astercompv1.up.railway.app");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

date_default_timezone_set("Asia/Jakarta");


// ==================================================
// RESPONSE HELPER
// ==================================================

function responseJson(int $httpCode, array $data)
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CORS PREFLIGHT
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}


// ==================================================
// CONFIGURATION
// ==================================================

$API_KEY = getenv("API_KEY");

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");


// ==================================================
// CHECK API KEY CONFIGURATION
// ==================================================

if ($API_KEY === false || $API_KEY === "") {

    responseJson(500, [
        "success" => false,
        "message" => "API_KEY environment variable is not configured"
    ]);
}


// ==================================================
// CHECK DATABASE CONFIGURATION
// ==================================================

if (
    $DB_HOST === false ||
    $DB_PORT === false ||
    $DB_NAME === false ||
    $DB_USER === false ||
    $DB_PASS === false
) {

    responseJson(500, [
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ]);
}


// ==================================================
// ONLY POST
// ==================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    responseJson(405, [
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "POST"
    ]);
}


// ==================================================
// READ AUTHORIZATION HEADER
// ==================================================

$authorization = "";

if (isset($_SERVER["HTTP_AUTHORIZATION"])) {

    $authorization =
        trim($_SERVER["HTTP_AUTHORIZATION"]);
}


// Fallback untuk beberapa konfigurasi server
if (
    $authorization === "" &&
    function_exists("getallheaders")
) {

    $headers = getallheaders();

    if (isset($headers["Authorization"])) {

        $authorization =
            trim($headers["Authorization"]);

    } elseif (isset($headers["authorization"])) {

        $authorization =
            trim($headers["authorization"]);
    }
}


// ==================================================
// EXTRACT BEARER TOKEN
// ==================================================

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


// ==================================================
// API KEY VALIDATION
// ==================================================

if ($receivedKey === "") {

    responseJson(401, [
        "success" => false,
        "message" => "Authorization header missing"
    ]);
}


if (!hash_equals($API_KEY, $receivedKey)) {

    responseJson(401, [
        "success" => false,
        "message" => "Invalid API key"
    ]);
}


// ==================================================
// CONTENT TYPE
// ==================================================

$contentType =
    $_SERVER["CONTENT_TYPE"] ?? "";

if (
    stripos(
        $contentType,
        "application/json"
    ) === false
) {

    responseJson(415, [
        "success" => false,
        "message" => "Content-Type must be application/json"
    ]);
}


// ==================================================
// READ REQUEST BODY
// ==================================================

$rawInput =
    file_get_contents("php://input");

if (
    $rawInput === false ||
    trim($rawInput) === ""
) {

    responseJson(400, [
        "success" => false,
        "message" => "Request body is empty"
    ]);
}


// ==================================================
// DECODE JSON
// ==================================================

$data =
    json_decode(
        $rawInput,
        true
    );

if (!is_array($data)) {

    responseJson(400, [
        "success" => false,
        "message" => "Invalid JSON"
    ]);
}


// ==================================================
// REQUIRED FIELDS
// ==================================================

// ESP32 TIDAK PERLU mengirim sample_index.
// sample_index dibuat otomatis oleh server.

$requiredFields = [
    "node_id",
    "temperature",
    "moisture",
    "soil_adc",
    "mq4_adc",
    "temp_status",
    "mq4_status"
];

foreach ($requiredFields as $field) {

    if (!array_key_exists($field, $data)) {

        responseJson(400, [
            "success" => false,
            "message" => "Missing field: " . $field
        ]);
    }
}


// ==================================================
// VALIDATE NODE ID
// ==================================================

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

    responseJson(400, [
        "success" => false,
        "message" => "node_id must be an integer between 1 and 5"
    ]);
}


// ==================================================
// VALIDATE TEMPERATURE
// ==================================================

$temperature =
    filter_var(
        $data["temperature"],
        FILTER_VALIDATE_FLOAT
    );

if (
    $temperature === false ||
    $temperature < -55 ||
    $temperature > 125
) {

    responseJson(400, [
        "success" => false,
        "message" => "Invalid temperature value"
    ]);
}


// ==================================================
// VALIDATE MOISTURE
// ==================================================

$moisture =
    filter_var(
        $data["moisture"],
        FILTER_VALIDATE_FLOAT
    );

if (
    $moisture === false ||
    $moisture < 0 ||
    $moisture > 100
) {

    responseJson(400, [
        "success" => false,
        "message" => "Moisture must be between 0 and 100"
    ]);
}


// ==================================================
// VALIDATE SOIL ADC
// ==================================================

$soilADC =
    filter_var(
        $data["soil_adc"],
        FILTER_VALIDATE_INT
    );

if (
    $soilADC === false ||
    $soilADC < 0 ||
    $soilADC > 4095
) {

    responseJson(400, [
        "success" => false,
        "message" => "Invalid soil_adc value"
    ]);
}


// ==================================================
// VALIDATE MQ4 ADC
// ==================================================

$mq4ADC =
    filter_var(
        $data["mq4_adc"],
        FILTER_VALIDATE_INT
    );

if (
    $mq4ADC === false ||
    $mq4ADC < 0 ||
    $mq4ADC > 4095
) {

    responseJson(400, [
        "success" => false,
        "message" => "Invalid mq4_adc value"
    ]);
}


// ==================================================
// VALIDATE TEMPERATURE STATUS
// ==================================================

$tempStatus =
    trim(
        (string)$data["temp_status"]
    );

if ($tempStatus === "") {

    responseJson(400, [
        "success" => false,
        "message" => "temp_status cannot be empty"
    ]);
}


// ==================================================
// VALIDATE MQ4 STATUS
// ==================================================

$mq4Status =
    trim(
        (string)$data["mq4_status"]
    );

if ($mq4Status === "") {

    responseJson(400, [
        "success" => false,
        "message" => "mq4_status cannot be empty"
    ]);
}


// ==================================================
// DATABASE CONNECTION
// ==================================================

mysqli_report(MYSQLI_REPORT_OFF);

$conn =
    new mysqli(
        $DB_HOST,
        $DB_USER,
        $DB_PASS,
        $DB_NAME,
        (int)$DB_PORT
    );

if ($conn->connect_error) {

    responseJson(500, [
        "success" => false,
        "message" => "Database connection failed",
        "error" => $conn->connect_error
    ]);
}


$conn->set_charset("utf8mb4");


// ==================================================
// TRANSACTION
// ==================================================

$conn->begin_transaction();

try {

    // --------------------------------------------------
    // LOCK RAW DATA COUNTER FOR THIS NODE
    // --------------------------------------------------
    //
    // Kita menggunakan MAX(sample_index) dari raw_data.
    //
    // FOR UPDATE mengunci baris hasil query selama
    // transaksi berjalan.
    //
    // Karena raw_data dapat kosong, kita gunakan
    // tabel counter khusus untuk locking.
    //
    // --------------------------------------------------

    $createCounterTable = $conn->query(
        "CREATE TABLE IF NOT EXISTS node_counters (
            node_id INT NOT NULL PRIMARY KEY,
            last_sample_index BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    if (!$createCounterTable) {
        throw new Exception(
            "Failed to create node_counters: " .
            $conn->error
        );
    }


    // --------------------------------------------------
    // INSERT COUNTER IF NODE DOES NOT EXIST
    // --------------------------------------------------

    $seedStmt =
        $conn->prepare(
            "INSERT INTO node_counters
                (node_id, last_sample_index)
             VALUES (?, 0)
             ON DUPLICATE KEY UPDATE
                node_id = node_id"
        );

    if (!$seedStmt) {
        throw new Exception(
            "Failed to prepare counter initialization: " .
            $conn->error
        );
    }

    $seedStmt->bind_param(
        "i",
        $nodeId
    );

    if (!$seedStmt->execute()) {
        throw new Exception(
            "Failed to initialize node counter: " .
            $seedStmt->error
        );
    }

    $seedStmt->close();


    // --------------------------------------------------
    // LOCK COUNTER
    // --------------------------------------------------

    $lockStmt =
        $conn->prepare(
            "SELECT last_sample_index
             FROM node_counters
             WHERE node_id = ?
             FOR UPDATE"
        );

    if (!$lockStmt) {
        throw new Exception(
            "Failed to prepare counter lock: " .
            $conn->error
        );
    }

    $lockStmt->bind_param(
        "i",
        $nodeId
    );

    if (!$lockStmt->execute()) {
        throw new Exception(
            "Failed to lock node counter: " .
            $lockStmt->error
        );
    }

    $result =
        $lockStmt->get_result();

    $counterRow =
        $result->fetch_assoc();

    $lockStmt->close();


    if (!$counterRow) {
        throw new Exception(
            "Node counter not found"
        );
    }


    $nextSampleIndex =
        ((int)$counterRow["last_sample_index"]) + 1;


    // --------------------------------------------------
    // INSERT RAW DATA
    // --------------------------------------------------

    $insertStmt =
        $conn->prepare(
            "INSERT INTO raw_data
                (
                    node_id,
                    sample_index,
                    temperature,
                    moisture,
                    soil_adc,
                    mq4_adc,
                    temp_status,
                    mq4_status,
                    recorded_at
                )
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

    if (!$insertStmt) {
        throw new Exception(
            "Failed to prepare raw insert: " .
            $conn->error
        );
    }


    $insertStmt->bind_param(
        "iiddii ss",
        $nodeId,
        $nextSampleIndex,
        $temperature,
        $moisture,
        $soilADC,
        $mq4ADC,
        $tempStatus,
        $mq4Status
    );

    // Perbaikan format bind_param:
    // i = node_id
    // i = sample_index
    // d = temperature
    // d = moisture
    // i = soil_adc
    // i = mq4_adc
    // s = temp_status
    // s = mq4_status

    if (!$insertStmt->execute()) {
        throw new Exception(
            "Failed to insert raw_data: " .
            $insertStmt->error
        );
    }


    $insertId =
        $insertStmt->insert_id;

    $insertStmt->close();


    // --------------------------------------------------
    // UPDATE COUNTER
    // --------------------------------------------------

    $updateStmt =
        $conn->prepare(
            "UPDATE node_counters
             SET last_sample_index = ?
             WHERE node_id = ?"
        );

    if (!$updateStmt) {
        throw new Exception(
            "Failed to prepare counter update: " .
            $conn->error
        );
    }

    $updateStmt->bind_param(
        "ii",
        $nextSampleIndex,
        $nodeId
    );

    if (!$updateStmt->execute()) {
        throw new Exception(
            "Failed to update node counter: " .
            $updateStmt->error
        );
    }

    $updateStmt->close();


    // --------------------------------------------------
    // COMMIT
    // --------------------------------------------------

    $conn->commit();

    $conn->close();


    // --------------------------------------------------
    // SUCCESS RESPONSE
    // --------------------------------------------------

    responseJson(200, [
        "success" => true,
        "message" => "Raw compost data received successfully",

        "insert_id" =>
            (int)$insertId,

        "node_id" =>
            (int)$nodeId,

        "sample_index" =>
            (int)$nextSampleIndex,

        "temperature" =>
            (float)$temperature,

        "moisture" =>
            (float)$moisture,

        "soil_adc" =>
            (int)$soilADC,

        "mq4_adc" =>
            (int)$mq4ADC,

        "temp_status" =>
            $tempStatus,

        "mq4_status" =>
            $mq4Status,

        "recorded_at" =>
            date("Y-m-d H:i:s")
    ]);

} catch (Throwable $e) {

    $conn->rollback();
    $conn->close();

    responseJson(500, [
        "success" => false,
        "message" => "Failed to insert raw data",
        "error" => $e->getMessage()
    ]);
}

?>
