<?php

// ==================================================
// RESET.PHP  (REVISED — supports partial or full reset)
// ==================================================
//
// Endpoint:
// POST /api/v1/compost/reset.php
//
// Body (JSON, optional):
//   {}                          -> reset ALL nodes (unchanged default behavior)
//   {"node_ids": [1,3]}         -> reset ONLY node 1 and node 3
//   {"node_ids": [1,2,3,4,5]}   -> same effect as resetting all, but goes
//                                  through the "specific nodes" code path
//
// Fungsi:
// Menghapus DATA MONITORING untuk node yang dipilih
// (atau seluruh node kalau node_ids tidak dikirim):
//
// 1. compost_raw
// 2. compost_data
// 3. node_status
// 4. node_counters (RESET ke 0, bukan dihapus — lihat catatan)
//
// Setelah reset:
// - History RAW node terkait terhapus
// - History AGGREGATED node terkait terhapus
// - Heartbeat/status node terkait terhapus
// - AUTO_INCREMENT di-reset (HANYA saat reset SEMUA node —
//   lihat catatan di bagian AUTO_INCREMENT)
// - sample_index & aggregated_index kontinu milik node
//   terkait ikut kembali ke 0, supaya insert berikutnya
//   untuk node itu mulai dari #1 lagi
//
// CATATAN PENTING:
// compost_raw.sample_index dan compost_data.sample_index
// (aggregated_index) TIDAK memakai AUTO_INCREMENT bawaan
// MySQL — nilainya dihasilkan oleh raw.php dan data.php
// dari tabel node_counters (supaya kontinu, tidak pernah
// reset per hari). Karena itu, mereset compost_raw dan
// compost_data saja TIDAK CUKUP: node_counters tetap
// menyimpan angka terakhir sebelum reset, sehingga insert
// berikutnya akan melanjutkan dari situ (bukan mulai dari
// 1) walau tabel datanya sendiri sudah kosong untuk node
// itu. Bagian di bawah menangani ini per-node.
//
// ==================================================


// ==================================================
// TIMEZONE
// ==================================================

date_default_timezone_set("Asia/Jakarta");


// ==================================================
// CORS
// ==================================================

$allowedOrigin =
    "https://aster-smart-compost-frontend-production.up.railway.app";


header(
    "Access-Control-Allow-Origin: " .
    $allowedOrigin
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


// ==================================================
// PREFLIGHT
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(204);

    exit;
}


// ==================================================
// ONLY POST
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] !== "POST"
) {

    http_response_code(405);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Method not allowed",

            "allowed_method" =>
                "POST"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// PARSE REQUEST BODY — OPTIONAL node_ids
// ==================================================
//
// Body is optional. If empty, missing, not valid JSON,
// or node_ids is missing/empty/null -> full reset (all
// nodes), matching the original behavior exactly.
//
// ==================================================

$rawBody =
    file_get_contents("php://input");

$body =
    json_decode(
        $rawBody === false ? "" : $rawBody,
        true
    );

if (
    !is_array($body)
) {

    $body = [];
}


$nodeIds = null; // null = full reset (all nodes)

if (
    array_key_exists("node_ids", $body) &&
    $body["node_ids"] !== null
) {

    if (
        !is_array($body["node_ids"])
    ) {

        http_response_code(400);

        echo json_encode(
            [
                "success" => false,

                "message" =>
                    "node_ids must be an array of integers between 1 and 5"
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }


    $cleanIds = [];

    foreach (
        $body["node_ids"] as $rawId
    ) {

        $id =
            filter_var(
                $rawId,
                FILTER_VALIDATE_INT
            );

        if (
            $id === false ||
            $id < 1 ||
            $id > 5
        ) {

            http_response_code(400);

            echo json_encode(
                [
                    "success" => false,

                    "message" =>
                        "node_ids must only contain integers between 1 and 5"
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        if (
            !in_array($id, $cleanIds, true)
        ) {

            $cleanIds[] = $id;
        }
    }


    if (
        count($cleanIds) === 0
    ) {

        http_response_code(400);

        echo json_encode(
            [
                "success" => false,

                "message" =>
                    "node_ids was provided but contained no valid node IDs"
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }


    sort($cleanIds);

    // If every node (1-5) was explicitly selected, treat it
    // as a full reset so AUTO_INCREMENT still gets reset too.
    if (
        count($cleanIds) === 5
    ) {

        $nodeIds = null;

    } else {

        $nodeIds = $cleanIds;
    }
}


$isFullReset = ($nodeIds === null);


// ==================================================
// DATABASE ENVIRONMENT
// ==================================================

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
// VALIDATE DATABASE ENVIRONMENT
// ==================================================

if (
    $DB_HOST === false ||
    $DB_PORT === false ||
    $DB_NAME === false ||
    $DB_USER === false ||
    $DB_PASS === false
) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Database environment variables are incomplete"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
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


// ==================================================
// CHECK CONNECTION
// ==================================================

if (
    $conn->connect_error
) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Database connection failed"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CHARACTER SET
// ==================================================

$conn->set_charset(
    "utf8mb4"
);


// ==================================================
// ENSURE node_counters TABLE EXISTS
// ==================================================
//
// Self-healing — matches raw.php/data.php's behavior.
// If this table was somehow never created, reset.php
// should not fail; it just creates it (empty/zeroed).
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


// ==================================================
// BUILD THE node_id IN (...) CLAUSE (only used when
// $isFullReset is false)
// ==================================================

$nodeIdList = $isFullReset ? "" : implode(",", $nodeIds);
$whereNodeClause = $isFullReset ? "" : ("WHERE node_id IN ($nodeIdList)");


// ==================================================
// START TRANSACTION
// ==================================================

$conn->begin_transaction();


try {


    // ==================================================
    // COUNT DATA BEFORE DELETE (scoped to selected nodes)
    // ==================================================

    $countRaw =
        $conn->query(
            "SELECT COUNT(*) AS total FROM compost_raw $whereNodeClause"
        );

    if (!$countRaw) {

        throw new Exception(
            "Failed to count compost_raw"
        );
    }

    $rawBefore =
        intval(
            $countRaw
                ->fetch_assoc()["total"]
        );

    $countRaw->free();


    // ==================================================
    // COUNT AGGREGATED BEFORE DELETE
    // ==================================================

    $countAggregated =
        $conn->query(
            "SELECT COUNT(*) AS total FROM compost_data $whereNodeClause"
        );

    if (!$countAggregated) {

        throw new Exception(
            "Failed to count compost_data"
        );
    }

    $aggregatedBefore =
        intval(
            $countAggregated
                ->fetch_assoc()["total"]
        );

    $countAggregated->free();


    // ==================================================
    // COUNT HEARTBEAT BEFORE DELETE
    // ==================================================

    $countHeartbeat =
        $conn->query(
            "SELECT COUNT(*) AS total FROM node_status $whereNodeClause"
        );

    if (!$countHeartbeat) {

        throw new Exception(
            "Failed to count node_status"
        );
    }

    $heartbeatBefore =
        intval(
            $countHeartbeat
                ->fetch_assoc()["total"]
        );

    $countHeartbeat->free();


    // ==================================================
    // DELETE RAW
    // ==================================================

    if (
        !$conn->query(
            "DELETE FROM compost_raw $whereNodeClause"
        )
    ) {

        throw new Exception(
            "Failed to delete compost_raw: " .
            $conn->error
        );
    }


    // ==================================================
    // DELETE AGGREGATED
    // ==================================================

    if (
        !$conn->query(
            "DELETE FROM compost_data $whereNodeClause"
        )
    ) {

        throw new Exception(
            "Failed to delete compost_data: " .
            $conn->error
        );
    }


    // ==================================================
    // DELETE HEARTBEAT
    // ==================================================

    if (
        !$conn->query(
            "DELETE FROM node_status $whereNodeClause"
        )
    ) {

        throw new Exception(
            "Failed to delete node_status: " .
            $conn->error
        );
    }


    // ==================================================
    // RESET AUTO_INCREMENT
    // ==================================================
    //
    // Only safe/meaningful for a FULL reset. If only some
    // nodes are cleared, rows from the untouched nodes
    // still exist with their own ids — forcing
    // AUTO_INCREMENT back to 1 would be a no-op at best
    // (MySQL silently keeps it at max(id)+1 anyway) so we
    // skip it entirely for a partial reset to avoid a
    // pointless ALTER TABLE on a live table.
    //
    // ==================================================

    if ($isFullReset) {

        if (
            !$conn->query(
                "ALTER TABLE compost_raw AUTO_INCREMENT = 1"
            )
        ) {

            throw new Exception(
                "Failed to reset compost_raw AUTO_INCREMENT"
            );
        }


        if (
            !$conn->query(
                "ALTER TABLE compost_data AUTO_INCREMENT = 1"
            )
        ) {

            throw new Exception(
                "Failed to reset compost_data AUTO_INCREMENT"
            );
        }
    }


    // ==================================================
    // RESET node_counters  (scoped to selected nodes)
    // ==================================================
    //
    // This is what raw.php and data.php read from to
    // generate sample_index / aggregated_index. Without
    // resetting this too, both would keep counting up
    // from wherever they left off (e.g. #106) even though
    // compost_raw / compost_data are now empty for that
    // node.
    //
    // ==================================================

    if (
        !$conn->query(
            "UPDATE node_counters SET last_sample_index = 0, last_aggregated_index = 0 $whereNodeClause"
        )
    ) {

        throw new Exception(
            "Failed to reset node_counters: " .
            $conn->error
        );
    }


    // ==================================================
    // ENSURE SELECTED NODES HAVE A COUNTER ROW
    // ==================================================
    //
    // In case a node has never sent data yet and so has
    // no row at all — insert one at 0 so future self-heal
    // logic in raw.php/data.php isn't needed for it.
    //
    // ==================================================

    $seedNodeIds = $isFullReset ? [1,2,3,4,5] : $nodeIds;
    $seedValues = implode(
        ", ",
        array_map(
            fn($id) => "($id,0,0)",
            $seedNodeIds
        )
    );

    if (
        !$conn->query(
            "INSERT IGNORE INTO node_counters (node_id, last_sample_index, last_aggregated_index)
             VALUES $seedValues"
        )
    ) {

        throw new Exception(
            "Failed to seed node_counters: " .
            $conn->error
        );
    }


    // ==================================================
    // COMMIT
    // ==================================================

    $conn->commit();


    // ==================================================
    // VERIFY AFTER DELETE (scoped to selected nodes)
    // ==================================================

    $verifyRaw =
        $conn->query(
            "SELECT COUNT(*) AS total FROM compost_raw $whereNodeClause"
        );

    $verifyAggregated =
        $conn->query(
            "SELECT COUNT(*) AS total FROM compost_data $whereNodeClause"
        );

    $verifyHeartbeat =
        $conn->query(
            "SELECT COUNT(*) AS total FROM node_status $whereNodeClause"
        );

    $verifyCounters =
        $conn->query(
            "SELECT COALESCE(SUM(last_sample_index),0) AS raw_sum, COALESCE(SUM(last_aggregated_index),0) AS agg_sum
             FROM node_counters $whereNodeClause"
        );


    if (
        !$verifyRaw ||
        !$verifyAggregated ||
        !$verifyHeartbeat ||
        !$verifyCounters
    ) {

        throw new Exception(
            "Verification query failed"
        );
    }


    $rawAfter =
        intval(
            $verifyRaw
                ->fetch_assoc()["total"]
        );


    $aggregatedAfter =
        intval(
            $verifyAggregated
                ->fetch_assoc()["total"]
        );


    $heartbeatAfter =
        intval(
            $verifyHeartbeat
                ->fetch_assoc()["total"]
        );


    $countersRow =
        $verifyCounters->fetch_assoc();

    $countersRawSum =
        intval(
            $countersRow["raw_sum"]
        );

    $countersAggSum =
        intval(
            $countersRow["agg_sum"]
        );


    $verifyRaw->free();

    $verifyAggregated->free();

    $verifyHeartbeat->free();

    $verifyCounters->free();


    // ==================================================
    // FINAL VALIDATION
    // ==================================================

    if (
        $rawAfter !== 0 ||
        $aggregatedAfter !== 0 ||
        $heartbeatAfter !== 0 ||
        $countersRawSum !== 0 ||
        $countersAggSum !== 0
    ) {

        throw new Exception(
            "Reset verification failed"
        );
    }


    // ==================================================
    // SUCCESS RESPONSE
    // ==================================================

    http_response_code(200);

    echo json_encode(
        [

            "success" =>
                true,

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
                    $isFullReset ? [1,2,3,4,5] : $nodeIds

            ],

            "deleted" => [

                "compost_raw" =>
                    $rawBefore,

                "compost_data" =>
                    $aggregatedBefore,

                "node_status" =>
                    $heartbeatBefore

            ],

            "remaining" => [

                "compost_raw" =>
                    $rawAfter,

                "compost_data" =>
                    $aggregatedAfter,

                "node_status" =>
                    $heartbeatAfter

            ],

            "counters_reset" =>
                true,

            "database_cleared" =>
                true

        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    );

}


// ==================================================
// ERROR
// ==================================================

catch (
    Exception $e
) {


    // ==================================================
    // ROLLBACK
    // ==================================================

    $conn->rollback();


    http_response_code(500);


    echo json_encode(
        [

            "success" =>
                false,

            "message" =>
                "Reset failed",

            "error" =>
                $e->getMessage(),

            "database_cleared" =>
                false

        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    );

}


// ==================================================
// CLOSE
// ==================================================

$conn->close();

?>
