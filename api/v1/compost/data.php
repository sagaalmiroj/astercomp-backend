<?php

// ==================================================
// DATA.PHP  (REVISED — caps removed for continuous operation)
// ==================================================
//
// Endpoint:
// POST /api/v1/compost/data.php
//
// Fungsi:
// 1. Menerima 1 hasil agregasi dari ESP32.
// 2. Validasi API Key.
// 3. Validasi data agregasi.
// 4. Menyimpan data ke compost_data.
//
// ATURAN (REVISED):
// - 1 request = 1 hasil agregasi.
// - sample_count = 100 (unchanged — this is the averaging
//   window size, not a running count, so it stays fixed).
// - sample_index: any positive integer (continuous, no
//   longer capped at 100). This is what history.php exposes
//   as "aggregated_index" — it must NEVER stop incrementing,
//   the same way compost_raw.sample_index never resets.
// - daily_sample: any positive integer (continuous, no
//   longer capped at 10). Previously capped at 10 assuming
//   a fixed "10 aggregations per day" cycle — removed so
//   aggregation keeps recording for as long as the device
//   keeps sending data, regardless of day boundaries.
// - node_id = 1-5 (unchanged).
//
// WHY THIS CHANGED:
// The old 1-100 / 1-10 validation caps would silently start
// rejecting every request (HTTP 400) the moment the device's
// own counters passed those limits — exactly the freeze bug
// observed on compost_raw once sample_index exceeded what its
// ingest endpoint allowed. Removing the ceiling here prevents
// compost_data from hitting the same wall later.
//
// CATATAN:
// - data.php TIDAK mengubah node_status.
// - ONLINE/OFFLINE ditangani oleh heartbeat.php.
// - data.php hanya menangani compost_data.
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

$requiredFields = [

    "node_id",

    "temperature",

    "moisture",

    "soil_adc",

    "mq4_adc",

    "ch4_index",

    "temp_status",

    "compost_status",

    "sample_count",

    "sample_index",

    "daily_sample"

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
// SAMPLE COUNT
// ==================================================
//
// 1 DATA AGREGASI = 100 RAW SAMPLE
// (unchanged — this is a fixed averaging-window size,
// not a running counter, so it correctly stays == 100)
//
// ==================================================

$sampleCount =
    filter_var(
        $data["sample_count"],
        FILTER_VALIDATE_INT
    );


if (
    $sampleCount === false ||
    $sampleCount !== 100
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "sample_count must be exactly 100"
        ]
    );
}


// ==================================================
// SAMPLE INDEX  (REVISED — device value validated but
// NOT stored; server generates the real continuous
// value further below. See "SERVER-SIDE CONTINUOUS
// AGGREGATED INDEX" section.)
// ==================================================
//
// The ESP32 firmware currently sends dailySampleNumber
// (1-10, wraps every cycle) as this field — NOT a true
// continuous counter. Storing it directly would freeze
// this table the exact same way compost_raw froze once
// the device wraps back to 1. We still sanity-check the
// incoming value (so malformed payloads are rejected),
// but the actual stored sample_index is computed by the
// server from node_counters, ignoring this device value.
//
// ==================================================

$deviceSampleIndex =
    filter_var(
        $data["sample_index"],
        FILTER_VALIDATE_INT
    );


if (
    $deviceSampleIndex === false ||
    $deviceSampleIndex < 1
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "sample_index must be a positive integer"
        ]
    );
}


// ==================================================
// DAILY SAMPLE  (REVISED — no upper cap)
// ==================================================
//
// Kept as-sent by the device (1-10, wraps every day) —
// this is fine to store as-is since it is purely a
// display/reference label ("today's Nth aggregation"),
// not used as a uniqueness key by history.php or the
// dashboard. Only sample_index (aggregated_index) needs
// to be globally continuous.
//
// ==================================================

$dailySample =
    filter_var(
        $data["daily_sample"],
        FILTER_VALIDATE_INT
    );


if (
    $dailySample === false ||
    $dailySample < 1
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "daily_sample must be a positive integer"
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
// CH4 INDEX
// ==================================================

$ch4Index =
    filter_var(
        $data["ch4_index"],
        FILTER_VALIDATE_INT
    );


if (
    $ch4Index === false ||
    $ch4Index < 0
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "Invalid ch4_index value"
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
// COMPOST STATUS
// ==================================================

$compostStatus =
    trim(
        (string)$data["compost_status"]
    );


if (
    $compostStatus === ""
) {

    responseJson(
        400,
        [
            "success" => false,

            "message" =>
                "compost_status cannot be empty"
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
// SERVER-SIDE CONTINUOUS AGGREGATED INDEX
// ==================================================
//
// The device's own $deviceSampleIndex (dailySampleNumber,
// 1-10, wraps every day) is NOT used as the stored
// sample_index. Instead the server computes a real
// continuous value here from node_counters, the same
// mechanism raw.php uses for compost_raw. This makes
// aggregated_index immune to the firmware's wrap-around,
// with zero firmware changes required.
//
// Self-healing: if node_counters has no row for this
// node yet, seed it from MAX(sample_index) already
// stored in compost_data so we never collide with
// existing rows (e.g. the aggregated_index=1,2 already
// recorded before this fix was deployed).
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

$conn->begin_transaction();

try {

    $lockStmt = $conn->prepare(
        "SELECT last_aggregated_index
         FROM node_counters
         WHERE node_id = ?
         FOR UPDATE"
    );
    $lockStmt->bind_param("i", $nodeId);
    $lockStmt->execute();
    $counterRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$counterRow) {

        $seedStmt = $conn->prepare(
            "SELECT COALESCE(MAX(sample_index), 0) AS max_idx
             FROM compost_data
             WHERE node_id = ?"
        );
        $seedStmt->bind_param("i", $nodeId);
        $seedStmt->execute();
        $seedRow = $seedStmt->get_result()->fetch_assoc();
        $seedStmt->close();

        $startIndex = (int)$seedRow["max_idx"];

        $initStmt = $conn->prepare(
            "INSERT INTO node_counters (node_id, last_sample_index, last_aggregated_index)
             VALUES (?, 0, ?)
             ON DUPLICATE KEY UPDATE last_aggregated_index = last_aggregated_index"
        );
        $initStmt->bind_param("ii", $nodeId, $startIndex);
        $initStmt->execute();
        $initStmt->close();

        $counterRow = ["last_aggregated_index" => $startIndex];
    }

    $sampleIndex = (int)$counterRow["last_aggregated_index"] + 1;

    $updateCounter = $conn->prepare(
        "UPDATE node_counters
         SET last_aggregated_index = ?
         WHERE node_id = ?"
    );
    $updateCounter->bind_param("ii", $sampleIndex, $nodeId);
    $updateCounter->execute();
    $updateCounter->close();

    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();
    $conn->close();

    responseJson(
        500,
        [
            "success" => false,

            "message" =>
                "Failed to generate continuous aggregated index",

            "error" =>
                $e->getMessage()
        ]
    );
}


// ==================================================
// INSERT AGGREGATED DATA
// ==================================================
//
// 1 REQUEST = 1 DATA AGREGASI
//
// ==================================================

$sql = "

INSERT INTO compost_data
(
    node_id,
    recorded_at,
    temperature,
    moisture,
    soil_adc,
    mq4_adc,
    ch4_index,
    temp_status,
    compost_status,
    sample_count,
    daily_sample,
    sample_index
)

VALUES
(
    ?,
    NOW(),
    ?,
    ?,
    ?,
    ?,
    ?,
    ?,
    ?,
    ?,
    ?,
    ?
)

";


// ==================================================
// PREPARE
// ==================================================

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


// ==================================================
// BIND PARAMETER
// ==================================================
//
// i = integer
// d = double
// s = string
//
// ==================================================

$stmt->bind_param(
    "iddiiissiii",
    $nodeId,
    $temperature,
    $moisture,
    $soilADC,
    $mq4ADC,
    $ch4Index,
    $tempStatus,
    $compostStatus,
    $sampleCount,
    $dailySample,
    $sampleIndex
);


// ==================================================
// EXECUTE
// ==================================================

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
                "Failed to insert aggregated data",

            "error" =>
                $error
        ]
    );
}


// ==================================================
// INSERT ID
// ==================================================

$insertId =
    $stmt->insert_id;


// ==================================================
// CLOSE
// ==================================================

$stmt->close();

$conn->close();


// ==================================================
// SUCCESS RESPONSE
// ==================================================

responseJson(
    200,
    [

        "success" =>
            true,

        "message" =>
            "Aggregated compost data received successfully",

        "insert_id" =>
            (int)$insertId,

        "node_id" =>
            (int)$nodeId,

        "sample_index" =>
            (int)$sampleIndex,

        "daily_sample" =>
            (int)$dailySample,

        "sample_count" =>
            (int)$sampleCount,

        "temperature" =>
            (float)$temperature,

        "moisture" =>
            (float)$moisture,

        "soil_adc" =>
            (int)$soilADC,

        "mq4_adc" =>
            (int)$mq4ADC,

        "ch4_index" =>
            (int)$ch4Index,

        "temp_status" =>
            $tempStatus,

        "compost_status" =>
            $compostStatus,

        "recorded_at" =>
            date(
                "Y-m-d H:i:s"
            )
    ]
);

?>
