<?php

// ============================================================
// ASTER SMART COMPOST API
// HISTORY ENDPOINT
// ============================================================
//
// Endpoint:
// GET /api/v1/compost/history.php
//
// Query Parameters:
//   type      (REQUIRED) "raw" | "aggregated"
//   node_id   (optional) 1-5, or "all"
//   day       (optional) 1+, or "all"
//   page      (optional) default 1
//   limit     (optional) default 50, max 500
//
// Examples:
//   ?type=raw
//   ?type=raw&node_id=1
//   ?type=raw&node_id=1&page=1&limit=50
//   ?type=aggregated&node_id=all
//
// ============================================================


// ============================================================
// CORS
// ============================================================

$allowedOrigin = "https://astercompv1.up.railway.app";

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

function responseJson(int $httpCode, array $data): void
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

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


// ============================================================
// DATABASE CONNECTION
// ============================================================

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(
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

if (!$conn->set_charset("utf8mb4")) {
    $conn->close();

    responseJson(500, [
        "success" => false,
        "message" => "Failed to configure database character set"
    ]);
}


// ============================================================
// QUERY PARAMETERS
// ============================================================

// ------------------------------------------------------------
// TYPE
// ------------------------------------------------------------

$type = strtolower(trim($_GET["type"] ?? ""));

$allowedTypes = [
    "raw",
    "aggregated"
];

if (!in_array($type, $allowedTypes, true)) {

    $conn->close();

    responseJson(400, [
        "success" => false,
        "message" => "type parameter is required and must be 'raw' or 'aggregated'",
        "allowed_types" => $allowedTypes
    ]);
}


// ------------------------------------------------------------
// NODE ID
// ------------------------------------------------------------

$nodeId = null;

if (
    isset($_GET["node_id"]) &&
    $_GET["node_id"] !== "" &&
    strtolower(trim($_GET["node_id"])) !== "all"
) {

    $nodeId = filter_var(
        $_GET["node_id"],
        FILTER_VALIDATE_INT
    );

    if (
        $nodeId === false ||
        $nodeId < 1 ||
        $nodeId > 5
    ) {

        $conn->close();

        responseJson(400, [
            "success" => false,
            "message" => "node_id must be an integer between 1 and 5, or 'all'"
        ]);
    }
}


// ------------------------------------------------------------
// DAY
// ------------------------------------------------------------

$dayFilter = null;

if (
    isset($_GET["day"]) &&
    $_GET["day"] !== "" &&
    strtolower(trim($_GET["day"])) !== "all"
) {

    $dayFilter = filter_var(
        $_GET["day"],
        FILTER_VALIDATE_INT
    );

    if ($dayFilter === false || $dayFilter < 1) {

        $conn->close();

        responseJson(400, [
            "success" => false,
            "message" => "day must be a positive integer, or 'all'"
        ]);
    }
}


// ------------------------------------------------------------
// PAGE
// ------------------------------------------------------------

$page = filter_var(
    $_GET["page"] ?? 1,
    FILTER_VALIDATE_INT
);

if ($page === false || $page < 1) {
    $page = 1;
}


// ------------------------------------------------------------
// LIMIT
// ------------------------------------------------------------

$limit = filter_var(
    $_GET["limit"] ?? 50,
    FILTER_VALIDATE_INT
);

if ($limit === false || $limit < 1 || $limit > 500) {
    $limit = 50;
}


// ============================================================
// TABLE SELECTION
// ============================================================

if ($type === "raw") {

    $table = "raw_data";

} else {

    $table = "aggregated_data";
}


// ============================================================
// NODE WHERE CLAUSE
// ============================================================

$whereClause = "";

if ($nodeId !== null) {

    $whereClause = "WHERE node_id = " . intval($nodeId);
}


// ============================================================
// GET AVAILABLE DAYS
// ============================================================
//
// Day number dihitung berdasarkan tanggal kalender.
//
// Contoh:
// 2026-10-05 = day 1
// 2026-10-06 = day 2
// 2026-10-07 = day 3
//
// Jika node_id dipilih, perhitungan hari hanya berdasarkan
// data node tersebut.
// ============================================================

$daysMapSql = "
    SELECT DISTINCT DATE(recorded_at) AS date_only
    FROM $table
    $whereClause
    ORDER BY DATE(recorded_at) ASC
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

    $dateOnly = $row["date_only"];

    $dayNumberMap[$dateOnly] = $dayCounter;

    $availableDays[] = $dayCounter;

    $dayCounter++;
}

$daysResult->free();


// ============================================================
// SELECT COLUMNS
// ============================================================

if ($type === "raw") {

    $selectColumns = "
        id,
        node_id,
        sample_index,
        temperature,
        moisture,
        soil_adc,
        mq4_adc,
        temp_status,
        mq4_status,
        recorded_at
    ";

} else {

    $selectColumns = "
        id,
        node_id,
        daily_sample,
        sample_count,
        temperature,
        moisture,
        soil_adc,
        mq4_adc,
        ch4_index,
        compost_status,
        recorded_at,
        next_update_at
    ";
}


// ============================================================
// FETCH DATA
// ============================================================

$sql = "
    SELECT
        $selectColumns
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


// ============================================================
// BUILD DATA
// ============================================================

$allData = [];

while ($row = $result->fetch_assoc()) {

    // --------------------------------------------------------
    // DATE
    // --------------------------------------------------------

    $recordedAt = $row["recorded_at"];

    $recordedDate = date(
        "Y-m-d",
        strtotime($recordedAt)
    );

    $dayNumber = $dayNumberMap[$recordedDate] ?? 1;


    // --------------------------------------------------------
    // DAY FILTER
    // --------------------------------------------------------

    if (
        $dayFilter !== null &&
        $dayNumber !== $dayFilter
    ) {
        continue;
    }


    // ========================================================
    // RAW
    // ========================================================

    if ($type === "raw") {

        $item = [
            "id" => intval($row["id"]),

            "node_id" => intval($row["node_id"]),

            "recorded_at" => $recordedAt,

            "sample_index" => intval(
                $row["sample_index"]
            ),

            "temperature" => floatval(
                $row["temperature"]
            ),

            "moisture" => floatval(
                $row["moisture"]
            ),

            "soil_adc" => intval(
                $row["soil_adc"]
            ),

            "mq4_adc" => intval(
                $row["mq4_adc"]
            ),

            "temp_status" => $row["temp_status"],

            "mq4_status" => $row["mq4_status"],

            "day_number" => intval($dayNumber)
        ];


    // ========================================================
    // AGGREGATED
    // ========================================================

    } else {

        $item = [
            "id" => intval($row["id"]),

            "node_id" => intval($row["node_id"]),

            "recorded_at" => $recordedAt,

            "daily_sample" => intval(
                $row["daily_sample"]
            ),

            "sample_count" => intval(
                $row["sample_count"]
            ),

            "temperature" => floatval(
                $row["temperature"]
            ),

            "moisture" => floatval(
                $row["moisture"]
            ),

            "soil_adc" => floatval(
                $row["soil_adc"]
            ),

            "mq4_adc" => floatval(
                $row["mq4_adc"]
            ),

            "ch4_index" => $row["ch4_index"] !== null
                ? floatval($row["ch4_index"])
                : null,

            "compost_status" => $row["compost_status"] ?? "",

            "next_update_at" => $row["next_update_at"],

            "day_number" => intval($dayNumber)
        ];
    }


    $allData[] = $item;
}

$result->free();


// ============================================================
// PAGINATION
// ============================================================

$total = count($allData);

$totalPages = max(
    1,
    (int)ceil($total / $limit)
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;

$data = array_slice(
    $allData,
    $offset,
    $limit
);


// ============================================================
// CLOSE CONNECTION
// ============================================================

$conn->close();


// ============================================================
// RESPONSE
// ============================================================

echo json_encode([
    "success" => true,

    "_debug_version" => "history-v3-2026-10-05",

    "type" => $type,

    "data" => $data,

    "page" => intval($page),

    "limit" => intval($limit),

    "total" => intval($total),

    "total_pages" => intval($totalPages),

    "available_days" => $availableDays

], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>
