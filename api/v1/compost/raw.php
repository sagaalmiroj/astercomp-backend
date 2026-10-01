<?php

// ==================================================
// RAW.PHP
// ==================================================
//
// Endpoint:
// POST /api/v1/compost/raw.php
//
// Fungsi:
// 1. Menerima 1 RAW sensor reading dari ESP32.
// 2. Validasi API Key.
// 3. Validasi data sensor.
// 4. Menyimpan data ke compost_raw dengan sample_index
//    yang KONTINU (tidak pernah reset, tidak pernah
//    mentok di angka berapa pun) — di-generate oleh
//    SERVER, bukan dikirim oleh ESP32.
//
// ATURAN:
// - 1 request = 1 RAW sample.
// - sample_index DIHITUNG OTOMATIS oleh server (lihat
//   node_counters). ESP32 TIDAK perlu mengirim sample_index.
// - node_id = 1-5.
//
// CATATAN:
// - submit.php TIDAK mengubah node_status.
// - ONLINE/OFFLINE ditangani oleh heartbeat.php.
// - submit.php hanya menangani compost_raw.
// - Setiap AGGREGATION_WINDOW (100) raw sample yang
//   masuk lewat endpoint ini, TIDAK otomatis membuat
//   baris compost_data — itu tetap tugas data.php,
//   yang dipanggil terpisah oleh ESP32 setelah ESP32
//   selesai menghitung rata-rata 100 sample secara lokal.
//
// ==================================================


// ==================================================
// RESPONSE HEADER
// ==================================================

header(
    "Content-Type: application/json; charset=UTF-8"
);

date_default_timezone_set(
    "Asia/Jakarta"
);


// ==================================================
// RESPONSE HELPER
// ==================================================

function responseJson(
    int $httpCode,
    array $data
) {

    http_response_code(
        $httpCode
    );

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CONFIGURATION
// ==================================================

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


// ==================================================
// CHECK API KEY
// ==================================================

if (
    $API_KEY === false ||
    $API_KEY === ""
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


// ==================================================
// CHECK DATABASE ENVIRONMENT
// ==================================================

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


// ==================================================
// ONLY POST
// ==================================================

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


// ==================================================
// AUTHORIZATION HEADER
// ==================================================

$authorization = "";


// --------------------------------------------------
// METHOD 1
// --------------------------------------------------

if (
    isset(
        $_SERVER["HTTP_AUTHORIZATION"]
    )
) {

    $authorization =
        trim(
            $_SERVER["HTTP_AUTHORIZATION"]
        );
}


// --------------------------------------------------
// METHOD 2
// --------------------------------------------------

if (
    $authorization === "" &&
    function_exists("getallheaders")
) {

    $headers =
        getallheaders();


    if (
        isset(
            $headers["Authorization"]
        )
    ) {

        $authorization =
            trim(
                $headers["Authorization"]
            );
    }

    elseif (
        isset(
            $headers["authorization"]
        )
    ) {

        $authorization =
            trim(
                $headers["authorization"]
            );
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

    responseJson(
        415,
        [
            "success" => false,

            "message" =>
                "Content-Type must be application/json"
        ]
    );
}


// ==================================================
// READ REQUEST BODY
// ==================================================

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
                "Request body is empty"
        ]
    );
}


// ==================================================
// DECODE JSON
// ==================================================

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


// ==================================================
// REQUIRED FIELDS
// ==================================================
//
// NOTE: sample_index is NOT in this list on purpose.
// The server generates it (continuous, never resets),
// so ESP32 only needs to send the raw sensor values.
//
// ==================================================

$requiredFields = [

    "node_id",

    "temperature",

    "moisture",

    "soil_adc",

    "mq4_adc",

    "temp_status",

    "mq4_status"

];


foreach (
    $requiredFields as $field
) {

    if (
        !array_key_exists(
            $field,
            $data
        )
    ) {

        responseJson(
            400,
            [
                "success" => false,

                "message" =>
                    "Missing field: " . $field
            ]
        );
    }
}


// ==================================================
// NODE ID
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

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "node_id must be an integer between 1 and 5"
        ]
    );
}


// ==================================================
// TEMPERATURE
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

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Invalid temperature value"
        ]
    );
}


// ==================================================
// MOISTURE
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

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Moisture must be between 0 and 100"
        ]
    );
}


// ==================================================
// SOIL ADC
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

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Invalid soil_adc value"
        ]
    );
}


// ==================================================
// MQ4 ADC
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

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Invalid mq4_adc value"
        ]
    );
}


// ==================================================
// TEMPERATURE STATUS
// ==================================================

$tempStatus =
    trim(
        (string)$data["temp_status"]
    );


if (
    $tempStatus === ""
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "temp_status cannot be empty"
        ]
    );
}


// ==================================================
// MQ4 STATUS
// ==================================================

$mq4Status =
    trim(
        (string)$data["mq4_status"]
    );


if (
    $mq4Status === ""
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "mq4_status cannot be empty"
        ]
    );
}


// ==================================================
// DATABASE CONNECTION
// ==================================================

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


$conn->set_charset(
    "utf8mb4"
);


// ==================================================
// ENSURE node_counters TABLE EXISTS
// ==================================================
//
// Self-healing: if this table was never created (fresh
// deploy), create it now instead of failing.
//
// ==================================================

$conn->query(
    "CREATE TABLE IF NOT EXISTS node_counters (
        node_id INT NOT NULL PRIMARY KEY,
        last_sample_index BIGINT NOT NULL DEFAULT 0,
        last_aggregated_index BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);


// ==================================================
// TRANSACTION — LOCK COUNTER, COMPUTE CONTINUOUS INDEX,
// INSERT RAW ROW
// ==================================================
//
// THE FIX: sample_index is generated here from
// node_counters.last_sample_index + 1 — it NEVER resets,
// NEVER caps at any fixed number, and is safe under
// concurrent requests because of SELECT ... FOR UPDATE.
//
// ==================================================

$conn->begin_transaction();

try {

    $lockStmt = $conn->prepare(
        "SELECT last_sample_index
         FROM node_counters
         WHERE node_id = ?
         FOR UPDATE"
    );
    $lockStmt->bind_param("i", $nodeId);
    $lockStmt->execute();
    $counterRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$counterRow) {

        // ----------------------------------------------
        // Self-healing seed: if this node has existing
        // rows in compost_raw (e.g. from before this
        // endpoint existed), start counting from the
        // highest sample_index already stored — never
        // collide with existing data.
        // ----------------------------------------------

        $seedStmt = $conn->prepare(
            "SELECT COALESCE(MAX(sample_index), 0) AS max_idx
             FROM compost_raw
             WHERE node_id = ?"
        );
        $seedStmt->bind_param("i", $nodeId);
        $seedStmt->execute();
        $seedRow = $seedStmt->get_result()->fetch_assoc();
        $seedStmt->close();

        $startIndex = (int)$seedRow["max_idx"];

        $initStmt = $conn->prepare(
            "INSERT INTO node_counters (node_id, last_sample_index, last_aggregated_index)
             VALUES (?, ?, 0)
             ON DUPLICATE KEY UPDATE last_sample_index = last_sample_index"
        );
        $initStmt->bind_param("ii", $nodeId, $startIndex);
        $initStmt->execute();
        $initStmt->close();

        $counterRow = ["last_sample_index" => $startIndex];
    }

    $nextSampleIndex = (int)$counterRow["last_sample_index"] + 1;

    $insertRaw = $conn->prepare(
        "INSERT INTO compost_raw
            (node_id, sample_index, temperature, moisture, soil_adc, mq4_adc, temp_status, mq4_status, recorded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insertRaw->bind_param(
        "iiddiiss",
        $nodeId,
        $nextSampleIndex,
        $temperature,
        $moisture,
        $soilADC,
        $mq4ADC,
        $tempStatus,
        $mq4Status
    );
    $insertRaw->execute();
    $insertId = $insertRaw->insert_id;
    $insertRaw->close();

    $updateCounter = $conn->prepare(
        "UPDATE node_counters
         SET last_sample_index = ?
         WHERE node_id = ?"
    );
    $updateCounter->bind_param("ii", $nextSampleIndex, $nodeId);
    $updateCounter->execute();
    $updateCounter->close();

    $conn->commit();
    $conn->close();

    responseJson(
        200,
        [
            "success" => true,

            "message" =>
                "Raw compost data received successfully",

            "insert_id" =>
                (int)$insertId,

            "node_id" =>
                (int)$nodeId,

            "sample_index" =>
                $nextSampleIndex,

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
        ]
    );

} catch (Throwable $e) {

    $conn->rollback();
    $conn->close();

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Failed to insert raw data",

            "error" =>
                $e->getMessage()
        ]
    );
}

?>
