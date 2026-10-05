<?php

// ==================================================
// ASTERCOMP V1 - AGGREGATED DATA INGEST ENDPOINT
// POST /api/v1/compost/data.php
//
// REVISI: bind_param "iiiddddis" -> "iiiddddds".
//         ch4_index (float) sebelumnya di-bind sebagai integer
//         sehingga nilainya terpotong.
// ==================================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: https://astercompv1.up.railway.app");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

date_default_timezone_set("Asia/Jakarta");

function responseJson(int $httpCode, array $data)
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// ---------- CONFIGURATION ----------
$API_KEY = getenv("API_KEY");

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if ($API_KEY === false || $API_KEY === "") {
    responseJson(500, [
        "success" => false,
        "message" => "API_KEY environment variable is not configured"
    ]);
}

if (
    $DB_HOST === false || $DB_PORT === false || $DB_NAME === false ||
    $DB_USER === false || $DB_PASS === false
) {
    responseJson(500, [
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ]);
}

// ---------- ONLY POST ----------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    responseJson(405, [
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "POST"
    ]);
}

// ---------- AUTHORIZATION ----------
$authorization = "";

if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
    $authorization = trim($_SERVER["HTTP_AUTHORIZATION"]);
}

if ($authorization === "" && function_exists("getallheaders")) {

    $headers = getallheaders();

    if (isset($headers["Authorization"])) {
        $authorization = trim($headers["Authorization"]);
    } elseif (isset($headers["authorization"])) {
        $authorization = trim($headers["authorization"]);
    }
}

$receivedKey = "";

if (strncasecmp($authorization, "Bearer ", 7) === 0) {
    $receivedKey = trim(substr($authorization, 7));
}

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

// ---------- CONTENT TYPE ----------
$contentType = $_SERVER["CONTENT_TYPE"] ?? "";

if (stripos($contentType, "application/json") === false) {
    responseJson(415, [
        "success" => false,
        "message" => "Content-Type must be application/json"
    ]);
}

// ---------- READ JSON ----------
$rawInput = file_get_contents("php://input");

if ($rawInput === false || trim($rawInput) === "") {
    responseJson(400, [
        "success" => false,
        "message" => "Request body is empty"
    ]);
}

$data = json_decode($rawInput, true);

if (!is_array($data)) {
    responseJson(400, [
        "success" => false,
        "message" => "Invalid JSON"
    ]);
}

// ---------- REQUIRED FIELDS ----------
$requiredFields = [
    "node_id",
    "temperature",
    "moisture",
    "soil_adc",
    "mq4_adc",
    "ch4_index",
    "compost_status",
    "sample_count",
    "daily_sample"
];

foreach ($requiredFields as $field) {
    if (!array_key_exists($field, $data)) {
        responseJson(400, [
            "success" => false,
            "message" => "Missing field: " . $field
        ]);
    }
}

// ---------- VALIDATION ----------
$nodeId = filter_var($data["node_id"], FILTER_VALIDATE_INT);

if ($nodeId === false || $nodeId < 1 || $nodeId > 5) {
    responseJson(400, [
        "success" => false,
        "message" => "node_id must be an integer between 1 and 5"
    ]);
}

$sampleCount = filter_var($data["sample_count"], FILTER_VALIDATE_INT);

if ($sampleCount === false || $sampleCount !== 100) {
    responseJson(400, [
        "success" => false,
        "message" => "sample_count must be exactly 100"
    ]);
}

$dailySample = filter_var($data["daily_sample"], FILTER_VALIDATE_INT);

if ($dailySample === false || $dailySample < 1) {
    responseJson(400, [
        "success" => false,
        "message" => "daily_sample must be a positive integer"
    ]);
}

$temperature = filter_var($data["temperature"], FILTER_VALIDATE_FLOAT);

if ($temperature === false || $temperature < -55 || $temperature > 125) {
    responseJson(400, [
        "success" => false,
        "message" => "Invalid temperature value"
    ]);
}

$moisture = filter_var($data["moisture"], FILTER_VALIDATE_FLOAT);

if ($moisture === false || $moisture < 0 || $moisture > 100) {
    responseJson(400, [
        "success" => false,
        "message" => "Moisture must be between 0 and 100"
    ]);
}

$soilADC = filter_var($data["soil_adc"], FILTER_VALIDATE_INT);

if ($soilADC === false || $soilADC < 0 || $soilADC > 4095) {
    responseJson(400, [
        "success" => false,
        "message" => "Invalid soil_adc value"
    ]);
}

$mq4ADC = filter_var($data["mq4_adc"], FILTER_VALIDATE_INT);

if ($mq4ADC === false || $mq4ADC < 0 || $mq4ADC > 4095) {
    responseJson(400, [
        "success" => false,
        "message" => "Invalid mq4_adc value"
    ]);
}

$ch4Index = filter_var($data["ch4_index"], FILTER_VALIDATE_FLOAT);

if ($ch4Index === false || $ch4Index < 0) {
    responseJson(400, [
        "success" => false,
        "message" => "Invalid ch4_index value"
    ]);
}

$compostStatus = trim((string)$data["compost_status"]);

if ($compostStatus === "") {
    responseJson(400, [
        "success" => false,
        "message" => "compost_status cannot be empty"
    ]);
}

// ---------- DATABASE CONNECTION ----------
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);

if ($conn->connect_error) {
    responseJson(500, [
        "success" => false,
        "message" => "Database connection failed",
        "error" => $conn->connect_error
    ]);
}

$conn->set_charset("utf8mb4");

// ---------- NEXT DAILY SAMPLE ----------
// daily_sample dibuat server agar tidak bergantung pada counter ESP32.
$stmt = $conn->prepare(
    "SELECT COALESCE(MAX(daily_sample), 0) + 1 AS next_daily_sample
     FROM aggregated_data
     WHERE node_id = ?"
);

if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to prepare daily sample query",
        "error" => $error
    ]);
}

$stmt->bind_param("i", $nodeId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to determine daily sample",
        "error" => $error
    ]);
}

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nextDailySample = (int)$row["next_daily_sample"];

// ---------- INSERT AGGREGATED DATA ----------
$sql = "
    INSERT INTO aggregated_data
    (
        node_id, daily_sample, sample_count, temperature, moisture,
        soil_adc, mq4_adc, ch4_index, compost_status, recorded_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    $error = $conn->error;
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "SQL prepare failed",
        "error" => $error
    ]);
}

// node_id i | daily_sample i | sample_count i | temperature d | moisture d |
// soil_adc d | mq4_adc d | ch4_index d (REVISI: sebelumnya i) | compost_status s
$stmt->bind_param(
    "iiiddddds",
    $nodeId,
    $nextDailySample,
    $sampleCount,
    $temperature,
    $moisture,
    $soilADC,
    $mq4ADC,
    $ch4Index,
    $compostStatus
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to insert aggregated data",
        "error" => $error
    ]);
}

$insertId = $stmt->insert_id;

$stmt->close();
$conn->close();

// ---------- SUCCESS ----------
responseJson(200, [
    "success" => true,
    "message" => "Aggregated compost data received successfully",
    "insert_id" => (int)$insertId,
    "node_id" => (int)$nodeId,
    "daily_sample" => (int)$nextDailySample,
    "sample_count" => (int)$sampleCount,
    "temperature" => (float)$temperature,
    "moisture" => (float)$moisture,
    "soil_adc" => (int)$soilADC,
    "mq4_adc" => (int)$mq4ADC,
    "ch4_index" => (float)$ch4Index,
    "compost_status" => $compostStatus,
    "recorded_at" => date("Y-m-d H:i:s")
]);

?>
