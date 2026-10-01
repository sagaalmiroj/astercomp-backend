<?php

// ============================================================
// ASTER SMART COMPOST API
// INGEST ENDPOINT — CONTINUOUS INDEXING VERSION
// ============================================================
//
// Replaces your current data-ingest endpoint (the one ESP32
// calls to submit a sensor reading). The ONLY structural change
// vs. whatever you have now is: sample_index is generated from
// node_counters (locked row, continuous, never resets), instead
// of being reset per day.
//
// IMPORTANT: Adjust the "READ INCOMING SENSOR VALUES" section
// below to match whatever parameter names your ESP32 firmware
// actually sends (GET or POST). Everything else can stay as-is.
//
// ============================================================


// ============================================================
// CORS (copy from your existing endpoint if different)
// ============================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
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
// DATABASE ENVIRONMENT (same variables as history.php)
// ============================================================

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");

if ($DB_HOST === false || $DB_PORT === false || $DB_NAME === false || $DB_USER === false || $DB_PASS === false) {
    responseJson(500, ["success" => false, "message" => "Database environment variables are incomplete"]);
}

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);

if ($conn->connect_error) {
    responseJson(500, ["success" => false, "message" => "Database connection failed", "error" => $conn->connect_error]);
}

if (!$conn->set_charset("utf8mb4")) {
    $conn->close();
    responseJson(500, ["success" => false, "message" => "Failed to configure database character set"]);
}


// ============================================================
// READ INCOMING SENSOR VALUES
// ⚠ ADJUST THESE to match your ESP32's actual parameter names
// ============================================================

$nodeId      = filter_var($_REQUEST['node_id']      ?? null, FILTER_VALIDATE_INT);
$temperature = filter_var($_REQUEST['temperature']   ?? null, FILTER_VALIDATE_FLOAT);
$moisture    = filter_var($_REQUEST['moisture']      ?? null, FILTER_VALIDATE_FLOAT);
$soilAdc     = filter_var($_REQUEST['soil_adc']      ?? null, FILTER_VALIDATE_INT);
$mq4Adc      = filter_var($_REQUEST['mq4_adc']       ?? null, FILTER_VALIDATE_INT);
$tempStatus  = $_REQUEST['temp_status']  ?? 'LOW';
$mq4Status   = $_REQUEST['mq4_status']   ?? 'LOW';

if ($nodeId === false || $nodeId === null || $nodeId < 1 || $nodeId > 5) {
    $conn->close();
    responseJson(400, ["success" => false, "message" => "node_id must be an integer between 1 and 5"]);
}

if ($temperature === false || $temperature === null) {
    $conn->close();
    responseJson(400, ["success" => false, "message" => "temperature is required and must be numeric"]);
}


// ============================================================
// CONSTANTS
// ============================================================

const AGGREGATION_WINDOW = 100; // 100 raw samples -> 1 aggregated record


// ============================================================
// TRANSACTION: lock node_counters row, compute next continuous
// index, insert raw row, optionally insert aggregated row
// ============================================================

$conn->begin_transaction();

try {

    // ------------------------------------------------------
    // Lock this node's counter row (blocks concurrent ingests
    // for the SAME node only; other nodes are unaffected)
    // ------------------------------------------------------
    $lockStmt = $conn->prepare(
        "SELECT last_sample_index, last_aggregated_index
         FROM node_counters
         WHERE node_id = ?
         FOR UPDATE"
    );
    $lockStmt->bind_param("i", $nodeId);
    $lockStmt->execute();
    $counterRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$counterRow) {
        // Counter row missing for this node — create it now
        $initStmt = $conn->prepare(
            "INSERT INTO node_counters (node_id, last_sample_index, last_aggregated_index)
             VALUES (?, 0, 0)"
        );
        $initStmt->bind_param("i", $nodeId);
        $initStmt->execute();
        $initStmt->close();
        $counterRow = ["last_sample_index" => 0, "last_aggregated_index" => 0];
    }

    // ------------------------------------------------------
    // THE FIX: next index = last known index + 1.
    // This is continuous across days because it is never
    // reset to 1 — it always continues from node_counters.
    // ------------------------------------------------------
    $nextSampleIndex = (int)$counterRow["last_sample_index"] + 1;

    // ------------------------------------------------------
    // Insert the RAW sample
    // ------------------------------------------------------
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
        $soilAdc,
        $mq4Adc,
        $tempStatus,
        $mq4Status
    );
    $insertRaw->execute();
    $insertRaw->close();

    // ------------------------------------------------------
    // Every AGGREGATION_WINDOW raw samples, compute + insert
    // one aggregated record (also continuous, never resets)
    // ------------------------------------------------------
    $nextAggregatedIndex = (int)$counterRow["last_aggregated_index"];

    if ($nextSampleIndex % AGGREGATION_WINDOW === 0) {

        $rangeStart = $nextSampleIndex - AGGREGATION_WINDOW + 1;
        $rangeEnd   = $nextSampleIndex;

        $aggStmt = $conn->prepare(
            "SELECT AVG(temperature) AS temp_avg,
                    AVG(moisture)    AS moist_avg,
                    AVG(soil_adc)    AS soil_avg,
                    AVG(mq4_adc)     AS mq4_avg,
                    COUNT(*)         AS sample_count
             FROM compost_raw
             WHERE node_id = ? AND sample_index BETWEEN ? AND ?"
        );
        $aggStmt->bind_param("iii", $nodeId, $rangeStart, $rangeEnd);
        $aggStmt->execute();
        $aggData = $aggStmt->get_result()->fetch_assoc();
        $aggStmt->close();

        $nextAggregatedIndex = (int)$counterRow["last_aggregated_index"] + 1;
        $ch4Index = (int)round($aggData["mq4_avg"]);
        $compostStatus = $tempStatus; // adjust if you have separate compost-status logic
        $aggTempAvg  = (float)$aggData["temp_avg"];
        $aggMoistAvg = (float)$aggData["moist_avg"];
        $aggSoilAvg  = (int)round($aggData["soil_avg"]);
        $aggMq4Avg   = (int)round($aggData["mq4_avg"]);
        $sampleCount = (int)$aggData["sample_count"];
        $dailySample = $nextAggregatedIndex; // simple running counter; adjust if you track per-day differently

        $insertAgg = $conn->prepare(
            "INSERT INTO compost_data
                (node_id, sample_index, temperature, moisture, soil_adc, mq4_adc,
                 ch4_index, temp_status, compost_status, sample_count, daily_sample, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        // Column order:  node_id, sample_index, temperature, moisture, soil_adc, mq4_adc,
        //                ch4_index, temp_status, compost_status, sample_count, daily_sample
        // Type string:   i          i             d            d          i          i
        //                i          s             s              i             i
        $insertAgg->bind_param(
            "iiddiiissii",
            $nodeId,
            $nextAggregatedIndex,
            $aggTempAvg,
            $aggMoistAvg,
            $aggSoilAvg,
            $aggMq4Avg,
            $ch4Index,
            $tempStatus,
            $compostStatus,
            $sampleCount,
            $dailySample
        );
        $insertAgg->execute();
        $insertAgg->close();
    }

    // ------------------------------------------------------
    // Persist the new counters (single source of truth)
    // ------------------------------------------------------
    $updateCounters = $conn->prepare(
        "UPDATE node_counters
         SET last_sample_index = ?, last_aggregated_index = ?
         WHERE node_id = ?"
    );
    $updateCounters->bind_param("iii", $nextSampleIndex, $nextAggregatedIndex, $nodeId);
    $updateCounters->execute();
    $updateCounters->close();

    $conn->commit();

    responseJson(200, [
        "success" => true,
        "message" => "Sample recorded",
        "node_id" => $nodeId,
        "sample_index" => $nextSampleIndex,
        "aggregated" => ($nextSampleIndex % AGGREGATION_WINDOW === 0)
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    responseJson(500, [
        "success" => false,
        "message" => "Failed to record sample",
        "error" => $e->getMessage()
    ]);
}
