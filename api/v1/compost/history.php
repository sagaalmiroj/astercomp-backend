<?php

// ============================================================
// ASTER SMART COMPOST API
// HISTORY ENDPOINT (FINAL — paginated, frontend-compatible)
// ============================================================
//
// Endpoint:
// GET /api/v1/compost/history.php
//
// Query Parameters:
//   type      (REQUIRED) "raw" | "aggregated"
//   node_id   (optional) 1-5, or "all" / omitted = all nodes
//   day       (optional) 1+, or "all" / omitted = all days
//   page      (optional) default 1
//   limit     (optional) default 50, max 500
//
// Example:
//   ?type=raw&node_id=1&day=5&page=1&limit=50
//   ?type=aggregated&node_id=all&page=2&limit=100
//
// Response:
// {
//   "success": true,
//   "data": [...],
//   "page": 1,
//   "limit": 50,
//   "total": 2847,
//   "total_pages": 57,
//   "available_days": [1,2,3,4,5,6,7]
// }
//
// ============================================================


// ============================================================
// CORS
// ============================================================

$allowedOrigin =
    "https://aster-smart-compost-frontend-production.up.railway.app";

header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");


// ============================================================
// OPTIONS / PREFLIGHT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}


// ============================================================
// ONLY GET
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "GET"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// TIMEZONE
// ============================================================

date_default_timezone_set("Asia/Jakarta");


// ============================================================
// RESPONSE HELPER
// ============================================================

function responseJson(int $httpCode, array $data) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// DATABASE ENVIRONMENT
// ============================================================

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if ($DB_HOST === false || $DB_PORT === false || $DB_NAME === false || $DB_USER === false || $DB_PASS === false) {
    responseJson(500, [
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ]);
}


// ============================================================
// DATABASE CONNECTION
// ============================================================

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);

if ($conn->connect_error) {
    responseJson(500, [
        "success" => false,
        "message" => "Database connection failed",
        "error" => $conn->connect_error
    ]);
}

if (!$conn->set_charset("utf8mb4")) {
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to configure database character set"
    ]);
}


// ============================================================
// UTC → WIB
// ============================================================

function utcToWIB($datetime) {
    if ($datetime === null || $datetime === "") return null;
    try {
        $date = new DateTime($datetime, new DateTimeZone("UTC"));
        $date->setTimezone(new DateTimeZone("Asia/Jakarta"));
        return $date->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return null;
    }
}


// ============================================================
// QUERY PARAMETERS
// ============================================================

// ---- TYPE (REQUIRED) ----
$type = strtolower(trim($_GET["type"] ?? ""));
$allowedTypes = ["raw", "aggregated"];

if (!in_array($type, $allowedTypes, true)) {
    $conn->close();
    responseJson(400, [
        "success" => false,
        "message" => "type parameter is required and must be 'raw' or 'aggregated'",
        "allowed_types" => $allowedTypes
    ]);
}

// ---- NODE ID (OPTIONAL — "all" or omitted means no filter) ----
$nodeId = null;

if (isset($_GET["node_id"]) && $_GET["node_id"] !== "" && strtolower($_GET["node_id"]) !== "all") {
    $nodeId = filter_var($_GET["node_id"], FILTER_VALIDATE_INT);
    if ($nodeId === false || $nodeId < 1 || $nodeId > 5) {
        $conn->close();
        responseJson(400, [
            "success" => false,
            "message" => "node_id must be an integer between 1 and 5, or 'all'"
        ]);
    }
}

// ---- DAY (OPTIONAL — "all" or omitted means no filter) ----
// NOTE: there is no day_number column in the database. Day is computed
// on-the-fly by grouping distinct calendar dates of recorded_at.
$dayFilter = null;

if (isset($_GET["day"]) && $_GET["day"] !== "" && strtolower($_GET["day"]) !== "all") {
    $dayFilter = filter_var($_GET["day"], FILTER_VALIDATE_INT);
    if ($dayFilter === false || $dayFilter < 1) {
        $conn->close();
        responseJson(400, [
            "success" => false,
            "message" => "day must be a positive integer, or 'all'"
        ]);
    }
}

// ---- PAGE (OPTIONAL) ----
$page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) $page = 1;

// ---- LIMIT (OPTIONAL) ----
$limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT);
if ($limit === false || $limit < 1 || $limit > 500) $limit = 50;


// ============================================================
// TABLE SELECTION
// ============================================================

$table = ($type === "raw") ? "compost_raw" : "compost_data";


// ============================================================
// WHERE CLAUSE (node_id only — day is filtered in PHP below,
// since day_number does not exist as a column)
// ============================================================

$whereClause = ($nodeId !== null) ? ("WHERE node_id = " . intval($nodeId)) : "";


// ============================================================
// COMPUTE DAY NUMBERS FROM DISTINCT CALENDAR DATES
// ============================================================

$daysMapSql = "
    SELECT DISTINCT DATE(recorded_at) AS date_only
    FROM $table
    $whereClause
    ORDER BY DATE(recorded_at) ASC
";

$daysResult = $conn->query($daysMapSql);
$dayNumberMap = [];   // "YYYY-MM-DD" => sequential day number
$availableDays = [];
$dayCounter = 1;

if ($daysResult) {
    while ($row = $daysResult->fetch_assoc()) {
        $dayNumberMap[$row["date_only"]] = $dayCounter;
        $availableDays[] = $dayCounter;
        $dayCounter++;
    }
    $daysResult->free();
}


// ============================================================
// SELECT COLUMNS BASED ON TYPE
// ============================================================

$selectColumns = [
    "id", "node_id", "recorded_at", "sample_index",
    "temperature", "moisture", "soil_adc", "mq4_adc", "temp_status"
];

if ($type === "aggregated") {
    $selectColumns = array_merge($selectColumns, ["ch4_index", "compost_status", "sample_count", "daily_sample"]);
} else {
    $selectColumns[] = "mq4_status";
}

$selectColumnsStr = implode(", ", $selectColumns);


// ============================================================
// FETCH ALL MATCHING ROWS (node_id filter only), THEN FILTER
// BY COMPUTED DAY + PAGINATE IN PHP
// ============================================================

$sql = "
    SELECT $selectColumnsStr
    FROM $table
    $whereClause
    ORDER BY recorded_at DESC, id DESC
";

$result = $conn->query($sql);

if (!$result) {
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to retrieve history",
        "error" => $conn->error
    ]);
}

$allData = [];

while ($row = $result->fetch_assoc()) {

    $recordedDate = date('Y-m-d', strtotime($row["recorded_at"]));
    $dayNumber = $dayNumberMap[$recordedDate] ?? 1;

    if ($dayFilter !== null && $dayNumber !== $dayFilter) {
        continue;
    }

    $item = [
        "id" => intval($row["id"]),
        "node_id" => intval($row["node_id"]),
        "recorded_at" => utcToWIB($row["recorded_at"]),
        "sample_index" => intval($row["sample_index"]),
        "temperature" => floatval($row["temperature"]),
        "moisture" => floatval($row["moisture"]),
        "soil_adc" => intval($row["soil_adc"]),
        "mq4_adc" => intval($row["mq4_adc"]),
        "temp_status" => $row["temp_status"],
        "day_number" => intval($dayNumber)
    ];

    if ($type === "aggregated") {
        $item["ch4_index"] = intval($row["ch4_index"] ?? 0);
        $item["compost_status"] = $row["compost_status"] ?? "";
        $item["sample_count"] = intval($row["sample_count"] ?? 100);
        $item["daily_sample"] = intval($row["daily_sample"] ?? 0);
        $item["aggregated_index"] = intval($row["sample_index"]);
    } else {
        $item["mq4_status"] = $row["mq4_status"] ?? "";
    }

    $allData[] = $item;
}

$result->free();
$conn->close();


// ============================================================
// PAGINATE THE FILTERED RESULT SET
// ============================================================

$total = count($allData);
$totalPages = max(1, (int)ceil($total / $limit));

if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $limit;
$data = array_slice($allData, $offset, $limit);


// ============================================================
// RESPONSE
// ============================================================

echo json_encode([
    "success" => true,
    "_debug_version" => "history-v2-2026-08-31",
    "data" => $data,
    "page" => intval($page),
    "limit" => intval($limit),
    "total" => intval($total),
    "total_pages" => intval($totalPages),
    "available_days" => $availableDays
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
