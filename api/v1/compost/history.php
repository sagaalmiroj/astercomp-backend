<?php

// ============================================================
// ASTER SMART COMPOST API - HISTORY ENDPOINT
// GET /api/v1/compost/history.php
//
// Query:
//   type      (REQUIRED) "raw" | "aggregated"
//   node_id   (optional) 1-5, or "all"
//   day       (optional) 1+, or "all"
//   page      (optional) default 1
//   limit     (optional) default 50, max 500
//
// REVISI:
//   1. aggregated item: tambah "aggregated_index" (= daily_sample)
//      karena index.html membaca a.aggregated_index.
//   2. recorded_at / next_update_at dikonversi UTC -> WIB
//      (sama seperti latest.php dan export.php).
//   3. day_number dihitung dari tanggal WIB (sama seperti export.php).
// ============================================================

// ---------- CORS ----------
$allowedOrigin = "https://astercompv1.up.railway.app";

header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "GET"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set("Asia/Jakarta");

// ---------- HELPERS ----------
function utcToWIB($datetime)
{
    if ($datetime === null || $datetime === "") {
        return $datetime;
    }

    try {
        $d = new DateTime($datetime, new DateTimeZone("UTC"));
        $d->setTimezone(new DateTimeZone("Asia/Jakarta"));
        return $d->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return $datetime;
    }
}

function responseJson(int $httpCode, array $data): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- DATABASE ----------
$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if (
    $DB_HOST === false || $DB_PORT === false || $DB_NAME === false ||
    $DB_USER === false || $DB_PASS === false
) {
    responseJson(500, [
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ]);
}

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

// ---------- QUERY PARAMETERS ----------

// type
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

// node_id
$nodeId = null;

if (
    isset($_GET["node_id"]) &&
    $_GET["node_id"] !== "" &&
    strtolower(trim($_GET["node_id"])) !== "all"
) {
    $nodeId = filter_var($_GET["node_id"], FILTER_VALIDATE_INT);

    if ($nodeId === false || $nodeId < 1 || $nodeId > 5) {
        $conn->close();
        responseJson(400, [
            "success" => false,
            "message" => "node_id must be an integer between 1 and 5, or 'all'"
        ]);
    }
}

// day
$dayFilter = null;

if (
    isset($_GET["day"]) &&
    $_GET["day"] !== "" &&
    strtolower(trim($_GET["day"])) !== "all"
) {
    $dayFilter = filter_var($_GET["day"], FILTER_VALIDATE_INT);

    if ($dayFilter === false || $dayFilter < 1) {
        $conn->close();
        responseJson(400, [
            "success" => false,
            "message" => "day must be a positive integer, or 'all'"
        ]);
    }
}

// page
$page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);

if ($page === false || $page < 1) {
    $page = 1;
}

// limit
$limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT);

if ($limit === false || $limit < 1 || $limit > 500) {
    $limit = 50;
}

// ---------- TABLE / WHERE ----------
$table = ($type === "raw") ? "raw_data" : "aggregated_data";

$whereClause = "";

if ($nodeId !== null) {
    $whereClause = "WHERE node_id = " . intval($nodeId);
}

// ---------- AVAILABLE DAYS ----------
// Day number = urutan tanggal kalender WIB (day 1 = tanggal data paling awal).
// Database menyimpan UTC, jadi dikonversi +07:00 sebelum diambil tanggalnya.
// Jika node_id dipilih, penomoran hanya berdasarkan data node tersebut.
$daysMapSql = "
    SELECT DISTINCT DATE(CONVERT_TZ(recorded_at, '+00:00', '+07:00')) AS date_only
    FROM $table
    $whereClause
    ORDER BY DATE(CONVERT_TZ(recorded_at, '+00:00', '+07:00')) ASC
";

$daysResult = $conn->query($daysMapSql);

if (!$daysResult) {
    $error = $conn->error;
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to retrieve available days",
        "error" => $error
    ]);
}

$dayNumberMap = [];
$availableDays = [];
$dayCounter = 1;

while ($row = $daysResult->fetch_assoc()) {
    $dayNumberMap[$row["date_only"]] = $dayCounter;
    $availableDays[] = $dayCounter;
    $dayCounter++;
}

$daysResult->free();

// ---------- SELECT COLUMNS ----------
if ($type === "raw") {

    $selectColumns = "
        id, node_id, sample_index, temperature, moisture,
        soil_adc, mq4_adc, temp_status, mq4_status, recorded_at
    ";

} else {

    $selectColumns = "
        id, node_id, daily_sample, sample_count, temperature, moisture,
        soil_adc, mq4_adc, ch4_index, compost_status, recorded_at, next_update_at
    ";
}

// ---------- FETCH ----------
$sql = "
    SELECT $selectColumns
    FROM $table
    $whereClause
    ORDER BY recorded_at DESC, id DESC
";

$result = $conn->query($sql);

if (!$result) {
    $error = $conn->error;
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to retrieve history",
        "error" => $error
    ]);
}

// ---------- BUILD DATA ----------
$allData = [];

while ($row = $result->fetch_assoc()) {

    // UTC (database) -> WIB
    $recordedAt = utcToWIB($row["recorded_at"]);

    $recordedDate = substr((string)$recordedAt, 0, 10);

    $dayNumber = $dayNumberMap[$recordedDate] ?? 1;

    if ($dayFilter !== null && $dayNumber !== $dayFilter) {
        continue;
    }

    if ($type === "raw") {

        $item = [
            "id" => intval($row["id"]),
            "node_id" => intval($row["node_id"]),
            "recorded_at" => $recordedAt,
            "sample_index" => intval($row["sample_index"]),
            "temperature" => floatval($row["temperature"]),
            "moisture" => floatval($row["moisture"]),
            "soil_adc" => intval($row["soil_adc"]),
            "mq4_adc" => intval($row["mq4_adc"]),
            "temp_status" => $row["temp_status"],
            "mq4_status" => $row["mq4_status"],
            "day_number" => intval($dayNumber)
        ];

    } else {

        $item = [
            "id" => intval($row["id"]),
            "node_id" => intval($row["node_id"]),
            "recorded_at" => $recordedAt,
            "daily_sample" => intval($row["daily_sample"]),

            // REVISI: dibaca oleh index.html (tabel Aggregated + Analytics)
            "aggregated_index" => intval($row["daily_sample"]),

            "sample_count" => intval($row["sample_count"]),
            "temperature" => floatval($row["temperature"]),
            "moisture" => floatval($row["moisture"]),
            "soil_adc" => floatval($row["soil_adc"]),
            "mq4_adc" => floatval($row["mq4_adc"]),
            "ch4_index" => $row["ch4_index"] !== null ? floatval($row["ch4_index"]) : null,
            "compost_status" => $row["compost_status"] ?? "",
            "next_update_at" => utcToWIB($row["next_update_at"]),
            "day_number" => intval($dayNumber)
        ];
    }

    $allData[] = $item;
}

$result->free();

// ---------- PAGINATION ----------
$total = count($allData);

$totalPages = max(1, (int)ceil($total / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;

$data = array_slice($allData, $offset, $limit);

$conn->close();

// ---------- RESPONSE ----------
echo json_encode([
    "success" => true,
    "_debug_version" => "history-v4-wib-aggindex",
    "type" => $type,
    "data" => $data,
    "page" => intval($page),
    "limit" => intval($limit),
    "total" => intval($total),
    "total_pages" => intval($totalPages),
    "available_days" => $availableDays
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>
