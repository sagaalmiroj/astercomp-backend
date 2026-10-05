<?php

// ==================================================
// LATEST.PHP
// GET /api/v1/compost/latest.php
// Tables: raw_data, aggregated_data  |  Nodes: 1-5
//
// REVISI: aggregated.sample_index ditambahkan (alias daily_sample)
//         karena index.html membaca agg.sample_index.
// ==================================================

date_default_timezone_set("Asia/Jakarta");

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

// ---------- CONFIG ----------
$HEARTBEAT_TIMEOUT = 30;

// ---------- DATABASE ENV ----------
$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if (
    $DB_HOST === false || $DB_PORT === false || $DB_NAME === false ||
    $DB_USER === false || $DB_PASS === false
) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database environment variables are incomplete"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- DATABASE CONNECTION ----------
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset("utf8mb4");

// ---------- UTC -> WIB ----------
function utcToWIB($datetime)
{
    if ($datetime === null || $datetime === "") {
        return null;
    }

    try {
        $date = new DateTime($datetime, new DateTimeZone("UTC"));
        $date->setTimezone(new DateTimeZone("Asia/Jakarta"));
        return $date->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return null;
    }
}

// ---------- INITIAL NODE STRUCTURE ----------
$nodes = [];

for ($i = 1; $i <= 5; $i++) {
    $nodes["node_" . $i] = [
        "node_id" => $i,
        "status" => "OFFLINE",
        "last_seen" => null,
        "raw" => null,
        "aggregated" => null
    ];
}

// ---------- LATEST RAW (MAX(id) per node) ----------
$sqlRaw = "
SELECT
    r.id, r.node_id, r.recorded_at, r.sample_index,
    r.temperature, r.moisture, r.soil_adc, r.mq4_adc,
    r.temp_status, r.mq4_status
FROM raw_data r
INNER JOIN
(
    SELECT node_id, MAX(id) AS latest_id
    FROM raw_data
    WHERE node_id BETWEEN 1 AND 5
    GROUP BY node_id
) latest
ON r.node_id = latest.node_id AND r.id = latest.latest_id
ORDER BY r.node_id ASC
";

$resultRaw = $conn->query($sqlRaw);

if (!$resultRaw) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to retrieve latest raw data",
        "mysql_error" => $conn->error
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

while ($row = $resultRaw->fetch_assoc()) {

    $nodeId = intval($row["node_id"]);

    if ($nodeId < 1 || $nodeId > 5) {
        continue;
    }

    $nodes["node_" . $nodeId]["raw"] = [
        "id" => intval($row["id"]),
        "recorded_at" => utcToWIB($row["recorded_at"]),
        "sample_index" => intval($row["sample_index"]),
        "temperature" => floatval($row["temperature"]),
        "moisture" => floatval($row["moisture"]),
        "soil_adc" => intval($row["soil_adc"]),
        "mq4_adc" => intval($row["mq4_adc"]),
        "temp_status" => $row["temp_status"],
        "mq4_status" => $row["mq4_status"]
    ];
}

// ---------- LATEST AGGREGATED (MAX(id) per node) ----------
$sqlAggregated = "
SELECT
    c.id, c.node_id, c.recorded_at, c.daily_sample,
    c.temperature, c.moisture, c.soil_adc, c.mq4_adc,
    c.ch4_index, c.compost_status, c.sample_count, c.next_update_at
FROM aggregated_data c
INNER JOIN
(
    SELECT node_id, MAX(id) AS latest_id
    FROM aggregated_data
    WHERE node_id BETWEEN 1 AND 5
    GROUP BY node_id
) latest
ON c.node_id = latest.node_id AND c.id = latest.latest_id
ORDER BY c.node_id ASC
";

$resultAggregated = $conn->query($sqlAggregated);

if (!$resultAggregated) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to retrieve latest aggregated data",
        "mysql_error" => $conn->error
    ], JSON_UNESCAPED_UNICODE);
    $resultRaw->free();
    $conn->close();
    exit;
}

while ($row = $resultAggregated->fetch_assoc()) {

    $nodeId = intval($row["node_id"]);

    if ($nodeId < 1 || $nodeId > 5) {
        continue;
    }

    $nodes["node_" . $nodeId]["aggregated"] = [
        "id" => intval($row["id"]),
        "recorded_at" => utcToWIB($row["recorded_at"]),
        "daily_sample" => intval($row["daily_sample"]),

        // REVISI: dibaca oleh index.html (modal detail node)
        "sample_index" => intval($row["daily_sample"]),

        "temperature" => floatval($row["temperature"]),
        "moisture" => floatval($row["moisture"]),
        "soil_adc" => floatval($row["soil_adc"]),
        "mq4_adc" => floatval($row["mq4_adc"]),
        "ch4_index" => $row["ch4_index"] !== null ? floatval($row["ch4_index"]) : null,
        "compost_status" => $row["compost_status"],
        "sample_count" => intval($row["sample_count"]),
        "next_update_at" => utcToWIB($row["next_update_at"])
    ];
}

// ---------- NODE STATUS ----------
// last_seen = raw_data.recorded_at terbaru (WIB).
// ONLINE jika selisih <= HEARTBEAT_TIMEOUT detik.
$currentTimestamp = time();

for ($i = 1; $i <= 5; $i++) {

    $nodeKey = "node_" . $i;
    $lastSeen = null;

    if (isset($nodes[$nodeKey]["raw"]) && $nodes[$nodeKey]["raw"] !== null) {
        $lastSeen = $nodes[$nodeKey]["raw"]["recorded_at"];
    }

    $nodes[$nodeKey]["last_seen"] = $lastSeen;

    if ($lastSeen !== null) {

        $lastSeenTimestamp = strtotime($lastSeen);

        if ($lastSeenTimestamp !== false) {

            $elapsed = $currentTimestamp - $lastSeenTimestamp;

            $nodes[$nodeKey]["status"] =
                ($elapsed >= 0 && $elapsed <= $HEARTBEAT_TIMEOUT)
                    ? "ONLINE"
                    : "OFFLINE";
        }
    }
}

// ---------- RESPONSE ----------
echo json_encode([
    "success" => true,
    "message" => "Latest compost monitoring data retrieved successfully",
    "server_time" => date("Y-m-d H:i:s"),
    "timezone" => "Asia/Jakarta",
    "heartbeat_timeout" => $HEARTBEAT_TIMEOUT,
    "nodes" => $nodes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$resultRaw->free();
$resultAggregated->free();
$conn->close();

?>
