<?php

// ============================================================
// ASTER SMART COMPOST API
// EXPORT ENDPOINT (CSV / ZIP STREAMING)
// ============================================================
//
// Endpoint:
// GET /api/v1/compost/export.php
//
// Query Parameters:
//   type      (REQUIRED) "raw" | "aggregated" | "both"
//   node_id   (optional) 1-5, or "all" / omitted = all nodes
//   day       (optional) 1+, or "all" / omitted = all days
//
// type=raw | aggregated  -> streams a CSV file
// type=both               -> streams a ZIP with both CSVs inside
//
// Day is not a real database column — it is computed the same
// way as in history.php: distinct calendar dates of recorded_at,
// numbered sequentially per (optional) node filter.
//
// ============================================================


// ============================================================
// CORS
// ============================================================

$allowedOrigin =
    "https://astercompv1.up.railway.app/";

header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Content-Type: application/json; charset=utf-8");
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set("Asia/Jakarta");


// ============================================================
// JSON ERROR HELPER (only used before we start streaming output)
// ============================================================

function jsonError(int $httpCode, string $message) {
    header("Content-Type: application/json; charset=utf-8");
    http_response_code($httpCode);
    echo json_encode(["success" => false, "message" => $message], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// DATABASE CONNECTION (same env vars as history.php)
// ============================================================

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if ($DB_HOST === false || $DB_PORT === false || $DB_NAME === false || $DB_USER === false || $DB_PASS === false) {
    jsonError(500, "Database environment variables are incomplete");
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);

if ($conn->connect_error) {
    jsonError(500, "Database connection failed: " . $conn->connect_error);
}

if (!$conn->set_charset("utf8mb4")) {
    $conn->close();
    jsonError(500, "Failed to configure database character set");
}


// ============================================================
// UTC → WIB
// ============================================================

function utcToWIB($datetime) {
    if ($datetime === null || $datetime === "") return "";
    try {
        $date = new DateTime($datetime, new DateTimeZone("UTC"));
        $date->setTimezone(new DateTimeZone("Asia/Jakarta"));
        return $date->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return "";
    }
}


// ============================================================
// QUERY PARAMETERS
// ============================================================

$type = strtolower(trim($_GET["type"] ?? ""));
if (!in_array($type, ["raw", "aggregated", "both"], true)) {
    $conn->close();
    jsonError(400, "type parameter is required and must be 'raw', 'aggregated', or 'both'");
}

$nodeId = null;
if (isset($_GET["node_id"]) && $_GET["node_id"] !== "" && strtolower($_GET["node_id"]) !== "all") {
    $nodeId = filter_var($_GET["node_id"], FILTER_VALIDATE_INT);
    if ($nodeId === false || $nodeId < 1 || $nodeId > 5) {
        $conn->close();
        jsonError(400, "node_id must be an integer between 1 and 5, or 'all'");
    }
}

$dayFilter = null;
if (isset($_GET["day"]) && $_GET["day"] !== "" && strtolower($_GET["day"]) !== "all") {
    $dayFilter = filter_var($_GET["day"], FILTER_VALIDATE_INT);
    if ($dayFilter === false || $dayFilter < 1) {
        $conn->close();
        jsonError(400, "day must be a positive integer, or 'all'");
    }
}


// ============================================================
// COMPUTE DAY-NUMBER MAP FOR A TABLE (same logic as history.php)
// ============================================================

function buildDayNumberMap(mysqli $conn, string $table, ?int $nodeId): array {
    $whereClause = ($nodeId !== null) ? ("WHERE node_id = " . intval($nodeId)) : "";
    $sql = "SELECT DISTINCT DATE(recorded_at) AS date_only FROM $table $whereClause ORDER BY DATE(recorded_at) ASC";
    $result = $conn->query($sql);
    $map = [];
    $counter = 1;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[$row["date_only"]] = $counter;
            $counter++;
        }
        $result->free();
    }
    return $map;
}


// ============================================================
// STREAM ONE TABLE AS CSV ROWS INTO A FILE HANDLE
// Filters by node_id (SQL) and by computed day (PHP), in chunks
// of CHUNK_SIZE so memory stays bounded on large exports.
// ============================================================

const CHUNK_SIZE = 1000;

function streamTableCsv(mysqli $conn, string $table, string $type, ?int $nodeId, ?int $dayFilter, $out): int {

    $dayNumberMap = buildDayNumberMap($conn, $table, $nodeId);

    $selectColumns = [
        "id", "node_id", "recorded_at", "sample_index",
        "temperature", "moisture", "soil_adc", "mq4_adc", "temp_status"
    ];
    if ($type === "aggregated") {
        $selectColumns = array_merge($selectColumns, ["ch4_index", "compost_status", "sample_count", "daily_sample"]);
        $header = ["day_number", "recorded_at", "node_id", "aggregated_index", "temperature", "moisture", "soil_adc", "mq4_adc", "ch4_index", "temp_status", "compost_status", "sample_count", "daily_sample"];
    } else {
        $selectColumns[] = "mq4_status";
        $header = ["day_number", "recorded_at", "node_id", "sample_index", "temperature", "moisture", "soil_adc", "mq4_adc", "temp_status", "mq4_status"];
    }
    $selectColumnsStr = implode(", ", $selectColumns);

    fputcsv($out, $header);

    $whereClause = ($nodeId !== null) ? ("WHERE node_id = " . intval($nodeId)) : "";
    $exported = 0;
    $offset = 0;

    while (true) {
        $sql = "SELECT $selectColumnsStr FROM $table $whereClause ORDER BY recorded_at ASC, id ASC LIMIT " . CHUNK_SIZE . " OFFSET " . intval($offset);
        $result = $conn->query($sql);
        if (!$result) break;

        $rowsInChunk = 0;
        while ($row = $result->fetch_assoc()) {
            $rowsInChunk++;

            $recordedDate = date('Y-m-d', strtotime($row["recorded_at"]));
            $dayNumber = $dayNumberMap[$recordedDate] ?? 1;

            if ($dayFilter !== null && $dayNumber !== $dayFilter) continue;

            if ($type === "aggregated") {
                $line = [
                    $dayNumber,
                    utcToWIB($row["recorded_at"]),
                    $row["node_id"],
                    $row["sample_index"], // aggregated_index
                    $row["temperature"],
                    $row["moisture"],
                    $row["soil_adc"],
                    $row["mq4_adc"],
                    $row["ch4_index"],
                    $row["temp_status"],
                    $row["compost_status"],
                    $row["sample_count"],
                    $row["daily_sample"]
                ];
            } else {
                $line = [
                    $dayNumber,
                    utcToWIB($row["recorded_at"]),
                    $row["node_id"],
                    $row["sample_index"],
                    $row["temperature"],
                    $row["moisture"],
                    $row["soil_adc"],
                    $row["mq4_adc"],
                    $row["temp_status"],
                    $row["mq4_status"]
                ];
            }

            fputcsv($out, $line);
            $exported++;
        }

        $result->free();
        $offset += CHUNK_SIZE;
        if ($rowsInChunk < CHUNK_SIZE) break; // last chunk reached
    }

    return $exported;
}


// ============================================================
// EXECUTE EXPORT
// ============================================================

$dateStr = date('Y-m-d');

try {

    if ($type === "both") {

        if (!class_exists('ZipArchive')) {
            $conn->close();
            jsonError(500, "ZipArchive extension is not available on this server");
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'astercomp_export_');
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::OVERWRITE);

        $rawBuf = fopen('php://temp', 'r+');
        streamTableCsv($conn, 'compost_raw', 'raw', $nodeId, $dayFilter, $rawBuf);
        rewind($rawBuf);
        $zip->addFromString("ASTERCOMP_RAW_{$dateStr}.csv", stream_get_contents($rawBuf));
        fclose($rawBuf);

        $aggBuf = fopen('php://temp', 'r+');
        streamTableCsv($conn, 'compost_data', 'aggregated', $nodeId, $dayFilter, $aggBuf);
        rewind($aggBuf);
        $zip->addFromString("ASTERCOMP_AGGREGATED_{$dateStr}.csv", stream_get_contents($aggBuf));
        fclose($aggBuf);

        $zip->close();
        $conn->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="ASTERCOMP_EXPORT_' . $dateStr . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);
        exit;
    }

    $table = ($type === "raw") ? "compost_raw" : "compost_data";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ASTERCOMP_' . strtoupper($type) . '_' . $dateStr . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    streamTableCsv($conn, $table, $type, $nodeId, $dayFilter, $out);
    fclose($out);
    $conn->close();
    exit;

} catch (Throwable $e) {
    $conn->close();
    jsonError(500, "Export failed: " . $e->getMessage());
}
