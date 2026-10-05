<?php

// ==================================================
// LATEST.PHP
// ==================================================
//
// Endpoint:
// GET /api/v1/compost/latest.php
//
// Fungsi:
// 1. Mengambil RAW DATA terbaru setiap node.
// 2. Mengambil AGGREGATED DATA terbaru setiap node.
// 3. Menentukan status ONLINE/OFFLINE berdasarkan last_seen.
// 4. Mengembalikan last_seen.
// 5. Mengembalikan server_time.
//
// Node:
// NODE 1 - NODE 5
//
// Endpoint ini:
// - READ ONLY
// - TIDAK mengubah database
// - TIDAK menghapus database
//
// Setelah RESET ALL HISTORY:
// - raw = null
// - aggregated = null
// - last_seen = null
// - status = OFFLINE
//
// ==================================================


// ==================================================
// TIMEZONE
// ==================================================

date_default_timezone_set(
    "Asia/Jakarta"
);


// ==================================================
// CORS
// ==================================================

$allowedOrigin =
    "https://astercompv1.up.railway.app/";


header(
    "Access-Control-Allow-Origin: " .
    $allowedOrigin
);

header(
    "Access-Control-Allow-Methods: GET, OPTIONS"
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
// OPTIONS / PREFLIGHT
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "OPTIONS"
) {

    http_response_code(204);

    exit;
}


// ==================================================
// ONLY GET
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] !== "GET"
) {

    http_response_code(405);

    header(
        "Allow: GET, OPTIONS"
    );

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Method not allowed",

            "allowed_method" =>
                "GET"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CONFIGURATION
// ==================================================
//
// Node dianggap ONLINE apabila last_seen
// tidak lebih dari 30 detik.
//
// ==================================================

$HEARTBEAT_TIMEOUT = 30;


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
// CHECK DATABASE CONNECTION
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
// UTC -> WIB
// ==================================================

function utcToWIB(
    $datetime
) {

    if (
        $datetime === null ||
        $datetime === ""
    ) {

        return null;
    }


    try {

        $date =
            new DateTime(
                $datetime,
                new DateTimeZone("UTC")
            );


        $date->setTimezone(
            new DateTimeZone("Asia/Jakarta")
        );


        return $date->format(
            "Y-m-d H:i:s"
        );

    }

    catch (
        Exception $e
    ) {

        return null;
    }
}


// ==================================================
// INITIAL NODE STRUCTURE
// ==================================================
//
// Struktur ini SELALU dibuat terlebih dahulu.
//
// Jadi walaupun database kosong setelah RESET:
//
// node_1
// node_2
// node_3
// node_4
// node_5
//
// tetap dikirim ke frontend.
//
// ==================================================

$nodes = [];


for (
    $i = 1;
    $i <= 5;
    $i++
) {

    $nodes[
        "node_" . $i
    ] = [

        "node_id" =>
            $i,

        "status" =>
            "OFFLINE",

        "last_seen" =>
            null,

        "raw" =>
            null,

        "aggregated" =>
            null
    ];
}


// ==================================================
// GET LATEST RAW DATA
// ==================================================
//
// Setiap node hanya mengambil satu record
// RAW paling baru.
//
// Penentu record:
// MAX(id)
//
// ==================================================

$sqlRaw = "

SELECT

    r.id,

    r.node_id,

    r.recorded_at,

    r.sample_index,

    r.temperature,

    r.moisture,

    r.soil_adc,

    r.mq4_adc,

    r.temp_status,

    r.mq4_status

FROM compost_raw r

INNER JOIN
(

    SELECT

        node_id,

        MAX(id) AS latest_id

    FROM compost_raw

    WHERE node_id BETWEEN 1 AND 5

    GROUP BY node_id

) latest

ON

    r.node_id = latest.node_id

    AND

    r.id = latest.latest_id

ORDER BY

    r.node_id ASC

";


// ==================================================
// EXECUTE RAW QUERY
// ==================================================

$resultRaw =
    $conn->query(
        $sqlRaw
    );


if (
    !$resultRaw
) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Failed to retrieve latest raw data"
        ],
        JSON_UNESCAPED_UNICODE
    );

    $conn->close();

    exit;
}


// ==================================================
// PROCESS RAW DATA
// ==================================================

while (
    $row =
        $resultRaw->fetch_assoc()
) {

    $nodeId =
        intval(
            $row["node_id"]
        );


    if (
        $nodeId < 1 ||
        $nodeId > 5
    ) {

        continue;
    }


    $nodes[
        "node_" . $nodeId
    ]["raw"] = [

        "id" =>
            intval(
                $row["id"]
            ),

        "recorded_at" =>
            utcToWIB(
                $row["recorded_at"]
            ),

        "sample_index" =>
            intval(
                $row["sample_index"]
            ),

        "temperature" =>
            floatval(
                $row["temperature"]
            ),

        "moisture" =>
            floatval(
                $row["moisture"]
            ),

        "soil_adc" =>
            intval(
                $row["soil_adc"]
            ),

        "mq4_adc" =>
            intval(
                $row["mq4_adc"]
            ),

        "temp_status" =>
            $row["temp_status"],

        "mq4_status" =>
            $row["mq4_status"]
    ];
}


// ==================================================
// GET LATEST AGGREGATED DATA
// ==================================================
//
// Sumber:
// compost_data
//
// Setiap node hanya mengambil satu record
// aggregated paling baru.
//
// ==================================================

$sqlAggregated = "

SELECT

    c.id,

    c.node_id,

    c.recorded_at,

    c.sample_index,

    c.temperature,

    c.moisture,

    c.soil_adc,

    c.mq4_adc,

    c.ch4_index,

    c.temp_status,

    c.compost_status,

    c.sample_count,

    c.daily_sample

FROM compost_data c

INNER JOIN
(

    SELECT

        node_id,

        MAX(id) AS latest_id

    FROM compost_data

    WHERE node_id BETWEEN 1 AND 5

    GROUP BY node_id

) latest

ON

    c.node_id = latest.node_id

    AND

    c.id = latest.latest_id

ORDER BY

    c.node_id ASC

";


// ==================================================
// EXECUTE AGGREGATED QUERY
// ==================================================

$resultAggregated =
    $conn->query(
        $sqlAggregated
    );


if (
    !$resultAggregated
) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Failed to retrieve latest aggregated data"
        ],
        JSON_UNESCAPED_UNICODE
    );

    $resultRaw->free();

    $conn->close();

    exit;
}


// ==================================================
// PROCESS AGGREGATED DATA
// ==================================================

while (
    $row =
        $resultAggregated->fetch_assoc()
) {

    $nodeId =
        intval(
            $row["node_id"]
        );


    if (
        $nodeId < 1 ||
        $nodeId > 5
    ) {

        continue;
    }


    $nodes[
        "node_" . $nodeId
    ]["aggregated"] = [

        "id" =>
            intval(
                $row["id"]
            ),

        "recorded_at" =>
            utcToWIB(
                $row["recorded_at"]
            ),

        "sample_index" =>
            intval(
                $row["sample_index"]
            ),

        "temperature" =>
            floatval(
                $row["temperature"]
            ),

        "moisture" =>
            floatval(
                $row["moisture"]
            ),

        "soil_adc" =>
            intval(
                $row["soil_adc"]
            ),

        "mq4_adc" =>
            intval(
                $row["mq4_adc"]
            ),

        "ch4_index" =>
            intval(
                $row["ch4_index"]
            ),

        "temp_status" =>
            $row["temp_status"],

        "compost_status" =>
            $row["compost_status"],

        "sample_count" =>
            intval(
                $row["sample_count"]
            ),

        "daily_sample" =>
            intval(
                $row["daily_sample"]
            )
    ];
}


// ==================================================
// GET NODE STATUS
// ==================================================
//
// Sumber:
// node_status
//
// Status TIDAK dipercaya dari database.
//
// Status dihitung ulang menggunakan:
//
// current UTC time
//          -
// last_seen UTC
//
// <= 30 detik  = ONLINE
// >  30 detik  = OFFLINE
//
// ==================================================

$sqlStatus = "

SELECT

    node_id,

    last_seen

FROM node_status

WHERE node_id BETWEEN 1 AND 5

ORDER BY

    node_id ASC

";


// ==================================================
// EXECUTE STATUS QUERY
// ==================================================

$resultStatus =
    $conn->query(
        $sqlStatus
    );


if (
    !$resultStatus
) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,

            "message" =>
                "Failed to retrieve node status"
        ],
        JSON_UNESCAPED_UNICODE
    );

    $resultRaw->free();

    $resultAggregated->free();

    $conn->close();

    exit;
}


// ==================================================
// PROCESS NODE STATUS
// ==================================================

while (
    $row =
        $resultStatus->fetch_assoc()
) {

    $nodeId =
        intval(
            $row["node_id"]
        );


    if (
        $nodeId < 1 ||
        $nodeId > 5
    ) {

        continue;
    }


    $status =
        "OFFLINE";


    $lastSeen =
        $row["last_seen"];


    // ==================================================
    // LAST SEEN EXISTS
    // ==================================================

    if (
        $lastSeen !== null &&
        $lastSeen !== ""
    ) {

        $lastSeenDate =
            DateTime::createFromFormat(
                "Y-m-d H:i:s",
                $lastSeen,
                new DateTimeZone("UTC")
            );


        if (
            $lastSeenDate !== false
        ) {

            $lastSeenTimestamp =
                $lastSeenDate->getTimestamp();


            $currentTimestamp =
                time();


            $elapsed =
                $currentTimestamp -
                $lastSeenTimestamp;


            // ==================================================
            // ONLINE
            // ==================================================

            if (
                $elapsed >= 0 &&
                $elapsed <= $HEARTBEAT_TIMEOUT
            ) {

                $status =
                    "ONLINE";
            }
        }
    }


    // ==================================================
    // SAVE STATUS
    // ==================================================

    $nodes[
        "node_" . $nodeId
    ]["status"] =
        $status;


    // ==================================================
    // SAVE LAST SEEN
    // ==================================================

    $nodes[
        "node_" . $nodeId
    ]["last_seen"] =
        utcToWIB(
            $lastSeen
        );
}


// ==================================================
// SERVER TIME
// ==================================================

$serverTime =
    date(
        "Y-m-d H:i:s"
    );


// ==================================================
// RESPONSE
// ==================================================

$response = [

    "success" =>
        true,

    "message" =>
        "Latest compost monitoring data retrieved successfully",

    "server_time" =>
        $serverTime,

    "timezone" =>
        "Asia/Jakarta",

    "heartbeat_timeout" =>
        $HEARTBEAT_TIMEOUT,

    "nodes" =>
        $nodes
];


// ==================================================
// OUTPUT JSON
// ==================================================

echo json_encode(
    $response,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE
);


// ==================================================
// FREE RESULT
// ==================================================

$resultRaw->free();

$resultAggregated->free();

$resultStatus->free();


// ==================================================
// CLOSE DATABASE
// ==================================================

$conn->close();

?>
