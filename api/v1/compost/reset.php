<?php

// ============================================================
// ASTER SMART COMPOST API - RESET ENDPOINT
// POST /api/v1/compost/reset.php
//
// Body JSON:
//   {}                          -> reset semua node
//   {"node_ids":[1,3]}          -> reset node 1 dan 3 saja
//   {"node_ids":[1,2,3,4,5]}    -> reset semua node
//
// Tabel: raw_data, aggregated_data
//
// REVISI: "remaining" kini juga berisi compost_raw, compost_data dan
//         node_status (selalu 0) karena index.html memverifikasi
//         ketiga key tersebut. Key lama (raw_data, aggregated_data)
//         tetap ada.
// ============================================================

date_default_timezone_set("Asia/Jakarta");

// ---------- CORS ----------
$allowedOrigin = "https://astercompv1.up.railway.app";

header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "POST"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function responseJson(int $httpCode, array $data): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- PARSE JSON BODY ----------
$rawBody = file_get_contents("php://input");
$body = json_decode($rawBody ?: "", true);

// Body kosong / bukan JSON valid = full reset
if (!is_array($body)) {
    $body = [];
}

// ---------- NODE SCOPE ----------
// null = semua node, array = node tertentu
$nodeIds = null;

if (array_key_exists("node_ids", $body) && $body["node_ids"] !== null) {

    if (!is_array($body["node_ids"])) {
        responseJson(400, [
            "success" => false,
            "message" => "node_ids must be an array of integers between 1 and 5"
        ]);
    }

    $cleanIds = [];

    foreach ($body["node_ids"] as $rawId) {

        $id = filter_var($rawId, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1 || $id > 5) {
            responseJson(400, [
                "success" => false,
                "message" => "node_ids must only contain integers between 1 and 5"
            ]);
        }

        if (!in_array($id, $cleanIds, true)) {
            $cleanIds[] = $id;
        }
    }

    if (count($cleanIds) === 0) {
        responseJson(400, [
            "success" => false,
            "message" => "node_ids was provided but contained no valid node IDs"
        ]);
    }

    sort($cleanIds);

    // Kelima node dipilih = full reset
    $nodeIds = (count($cleanIds) === 5) ? null : $cleanIds;
}

$isFullReset = ($nodeIds === null);

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

// ---------- NODE WHERE ----------
$whereNodeClause = "";

if (!$isFullReset) {
    $nodeIdList = implode(",", array_map("intval", $nodeIds));
    $whereNodeClause = "WHERE node_id IN ($nodeIdList)";
}

// Helper: COUNT(*) dengan scope node yang sama
function countRows(mysqli $conn, string $table, string $where): int
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM $table $where");

    if (!$result) {
        throw new Exception("Failed to count $table: " . $conn->error);
    }

    $total = intval($result->fetch_assoc()["total"]);
    $result->free();

    return $total;
}

// ---------- TRANSACTION ----------
$conn->begin_transaction();

try {

    $rawBefore = countRows($conn, "raw_data", $whereNodeClause);
    $aggregatedBefore = countRows($conn, "aggregated_data", $whereNodeClause);

    if (!$conn->query("DELETE FROM raw_data $whereNodeClause")) {
        throw new Exception("Failed to delete raw_data: " . $conn->error);
    }

    if (!$conn->query("DELETE FROM aggregated_data $whereNodeClause")) {
        throw new Exception("Failed to delete aggregated_data: " . $conn->error);
    }

    // Full reset: ID mulai lagi dari 1.
    // Partial reset: AUTO_INCREMENT tidak disentuh (data node lain masih ada).
    if ($isFullReset) {

        if (!$conn->query("ALTER TABLE raw_data AUTO_INCREMENT = 1")) {
            throw new Exception("Failed to reset raw_data AUTO_INCREMENT: " . $conn->error);
        }

        if (!$conn->query("ALTER TABLE aggregated_data AUTO_INCREMENT = 1")) {
            throw new Exception("Failed to reset aggregated_data AUTO_INCREMENT: " . $conn->error);
        }
    }

    $conn->commit();

    // ---------- VERIFY ----------
    $rawAfter = countRows($conn, "raw_data", $whereNodeClause);
    $aggregatedAfter = countRows($conn, "aggregated_data", $whereNodeClause);

    if ($rawAfter !== 0 || $aggregatedAfter !== 0) {
        throw new Exception("Reset verification failed");
    }

    $conn->close();

    responseJson(200, [

        "success" => true,

        "message" => $isFullReset
            ? "All compost monitoring history has been completely cleared"
            : "Compost monitoring history has been cleared for the selected node(s)",

        "server_time" => date("Y-m-d H:i:s"),

        "timezone" => "Asia/Jakarta",

        "scope" => [
            "all_nodes" => $isFullReset,
            "node_ids" => $isFullReset ? [1, 2, 3, 4, 5] : $nodeIds
        ],

        "deleted" => [
            "raw_data" => $rawBefore,
            "aggregated_data" => $aggregatedBefore
        ],

        "remaining" => [

            "raw_data" => $rawAfter,
            "aggregated_data" => $aggregatedAfter,

            // REVISI: nama key yang diverifikasi index.html
            "compost_raw" => $rawAfter,
            "compost_data" => $aggregatedAfter,
            "node_status" => 0
        ],

        "database_cleared" => true,

        "_debug_version" => "reset-v3-2026-10-05"
    ]);

} catch (Throwable $e) {

    $conn->rollback();

    $errorMessage = $e->getMessage();

    $conn->close();

    responseJson(500, [
        "success" => false,
        "message" => "Reset failed",
        "error" => $errorMessage,
        "database_cleared" => false
    ]);
}

?>
