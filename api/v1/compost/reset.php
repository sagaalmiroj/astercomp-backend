<?php

// ============================================================
// ASTER SMART COMPOST API
// RESET ENDPOINT
// ============================================================
//
// Endpoint:
// POST /api/v1/compost/reset.php
//
// Body JSON:
//
// {} 
// -> reset semua node
//
// {"node_ids":[1,3]}
// -> reset node 1 dan 3 saja
//
// {"node_ids":[1,2,3,4,5]}
// -> reset semua node
//
// Database:
//   raw_data
//   aggregated_data
//
// Catatan:
//   Tidak menggunakan node_status karena tabel tersebut
//   tidak ada pada schema ASTERCOMP saat ini.
//   Status ONLINE/OFFLINE dihitung oleh latest.php
//   berdasarkan raw_data.recorded_at.
//
// ============================================================


// ============================================================
// TIMEZONE
// ============================================================

date_default_timezone_set("Asia/Jakarta");


// ============================================================
// CORS
// ============================================================

$allowedOrigin = "https://astercompv1.up.railway.app";

header(
    "Access-Control-Allow-Origin: " . $allowedOrigin
);

header(
    "Access-Control-Allow-Methods: POST, OPTIONS"
);

header(
    "Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"
);

header(
    "Access-Control-Max-Age: 86400"
);

header(
    "Vary: Origin"
);

header(
    "Content-Type: application/json; charset=utf-8"
);


// ============================================================
// PREFLIGHT
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {

    http_response_code(204);

    exit;
}


// ============================================================
// ONLY POST
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
        "allowed_method" => "POST"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


// ============================================================
// RESPONSE HELPER
// ============================================================

function responseJson(
    int $httpCode,
    array $data
): void {

    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ============================================================
// PARSE JSON BODY
// ============================================================

$rawBody = file_get_contents("php://input");

$body = json_decode(
    $rawBody ?: "",
    true
);


// Empty / invalid JSON = full reset
if (!is_array($body)) {
    $body = [];
}


// ============================================================
// NODE SCOPE
// ============================================================
//
// null = semua node
//
// array = node tertentu
//
// ============================================================

$nodeIds = null;


if (
    array_key_exists("node_ids", $body) &&
    $body["node_ids"] !== null
) {


    // --------------------------------------------------------
    // node_ids harus array
    // --------------------------------------------------------

    if (!is_array($body["node_ids"])) {

        responseJson(400, [
            "success" => false,
            "message" =>
                "node_ids must be an array of integers between 1 and 5"
        ]);
    }


    // --------------------------------------------------------
    // CLEAN NODE IDS
    // --------------------------------------------------------

    $cleanIds = [];


    foreach ($body["node_ids"] as $rawId) {

        $id = filter_var(
            $rawId,
            FILTER_VALIDATE_INT
        );


        if (
            $id === false ||
            $id < 1 ||
            $id > 5
        ) {

            responseJson(400, [
                "success" => false,
                "message" =>
                    "node_ids must only contain integers between 1 and 5"
            ]);
        }


        if (!in_array(
            $id,
            $cleanIds,
            true
        )) {

            $cleanIds[] = $id;
        }
    }


    // --------------------------------------------------------
    // EMPTY ARRAY
    // --------------------------------------------------------

    if (count($cleanIds) === 0) {

        responseJson(400, [
            "success" => false,
            "message" =>
                "node_ids was provided but contained no valid node IDs"
        ]);
    }


    sort($cleanIds);


    // --------------------------------------------------------
    // ALL FIVE NODES = FULL RESET
    // --------------------------------------------------------

    if (count($cleanIds) === 5) {

        $nodeIds = null;

    } else {

        $nodeIds = $cleanIds;
    }
}


// ============================================================
// RESET TYPE
// ============================================================

$isFullReset = ($nodeIds === null);


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
        "message" =>
            "Database environment variables are incomplete"
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
        "message" =>
            "Database connection failed",
        "error" =>
            $conn->connect_error
    ]);
}


if (!$conn->set_charset("utf8mb4")) {

    $conn->close();

    responseJson(500, [
        "success" => false,
        "message" =>
            "Failed to configure database character set"
    ]);
}


// ============================================================
// BUILD NODE WHERE
// ============================================================

$whereNodeClause = "";

if (!$isFullReset) {

    $nodeIdList = implode(
        ",",
        array_map(
            "intval",
            $nodeIds
        )
    );

    $whereNodeClause =
        "WHERE node_id IN ($nodeIdList)";
}


// ============================================================
// TRANSACTION
// ============================================================

$conn->begin_transaction();


try {


    // ========================================================
    // COUNT RAW BEFORE
    // ========================================================

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM raw_data
         $whereNodeClause"
    );


    if (!$result) {

        throw new Exception(
            "Failed to count raw_data: " .
            $conn->error
        );
    }


    $rawBefore = intval(
        $result->fetch_assoc()["total"]
    );


    $result->free();


    // ========================================================
    // COUNT AGGREGATED BEFORE
    // ========================================================

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM aggregated_data
         $whereNodeClause"
    );


    if (!$result) {

        throw new Exception(
            "Failed to count aggregated_data: " .
            $conn->error
        );
    }


    $aggregatedBefore = intval(
        $result->fetch_assoc()["total"]
    );


    $result->free();


    // ========================================================
    // DELETE RAW
    // ========================================================

    if (!$conn->query(
        "DELETE FROM raw_data
         $whereNodeClause"
    )) {

        throw new Exception(
            "Failed to delete raw_data: " .
            $conn->error
        );
    }


    // ========================================================
    // DELETE AGGREGATED
    // ========================================================

    if (!$conn->query(
        "DELETE FROM aggregated_data
         $whereNodeClause"
    )) {

        throw new Exception(
            "Failed to delete aggregated_data: " .
            $conn->error
        );
    }


    // ========================================================
    // RESET AUTO_INCREMENT
    // ========================================================
    //
    // Full reset:
    //   IDs dimulai kembali dari 1.
    //
    // Partial reset:
    //   AUTO_INCREMENT tidak disentuh karena data node lain
    //   masih ada.
    //
    // ========================================================

    if ($isFullReset) {


        if (!$conn->query(
            "ALTER TABLE raw_data AUTO_INCREMENT = 1"
        )) {

            throw new Exception(
                "Failed to reset raw_data AUTO_INCREMENT: " .
                $conn->error
            );
        }


        if (!$conn->query(
            "ALTER TABLE aggregated_data AUTO_INCREMENT = 1"
        )) {

            throw new Exception(
                "Failed to reset aggregated_data AUTO_INCREMENT: " .
                $conn->error
            );
        }
    }


    // ========================================================
    // COMMIT
    // ========================================================

    $conn->commit();


    // ========================================================
    // VERIFY RAW
    // ========================================================

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM raw_data
         $whereNodeClause"
    );


    if (!$result) {

        throw new Exception(
            "Failed to verify raw_data"
        );
    }


    $rawAfter = intval(
        $result->fetch_assoc()["total"]
    );


    $result->free();


    // ========================================================
    // VERIFY AGGREGATED
    // ========================================================

    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM aggregated_data
         $whereNodeClause"
    );


    if (!$result) {

        throw new Exception(
            "Failed to verify aggregated_data"
        );
    }


    $aggregatedAfter = intval(
        $result->fetch_assoc()["total"]
    );


    $result->free();


    // ========================================================
    // FINAL VALIDATION
    // ========================================================

    if (
        $rawAfter !== 0 ||
        $aggregatedAfter !== 0
    ) {

        throw new Exception(
            "Reset verification failed"
        );
    }


    // ========================================================
    // SUCCESS
    // ========================================================

    $conn->close();


    responseJson(200, [

        "success" => true,

        "message" =>
            $isFullReset
                ? "All compost monitoring history has been completely cleared"
                : "Compost monitoring history has been cleared for the selected node(s)",

        "server_time" =>
            date("Y-m-d H:i:s"),

        "timezone" =>
            "Asia/Jakarta",

        "scope" => [

            "all_nodes" =>
                $isFullReset,

            "node_ids" =>
                $isFullReset
                    ? [1, 2, 3, 4, 5]
                    : $nodeIds
        ],

        "deleted" => [

            "raw_data" =>
                $rawBefore,

            "aggregated_data" =>
                $aggregatedBefore
        ],

        "remaining" => [

            "raw_data" =>
                $rawAfter,

            "aggregated_data" =>
                $aggregatedAfter
        ],

        "database_cleared" =>
            true,

        "_debug_version" =>
            "reset-v2-2026-10-05"
    ]);
}


// ============================================================
// ERROR
// ============================================================

catch (Throwable $e) {

    $conn->rollback();

    $errorMessage = $e->getMessage();

    $conn->close();

    responseJson(500, [

        "success" => false,

        "message" =>
            "Reset failed",

        "error" =>
            $errorMessage,

        "database_cleared" =>
            false
    ]);
}

?>
