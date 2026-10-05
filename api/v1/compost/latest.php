<?php

// ==================================================
// LATEST.PHP
// ==================================================
// Endpoint:
// GET /api/v1/compost/latest.php
//
// Database:
// raw_data
// aggregated_data
//
// Node:
// NODE 1 - NODE 5
// ==================================================


// ==================================================
// TIMEZONE
// ==================================================

date_default_timezone_set("Asia/Jakarta");


// ==================================================
// CORS
// ==================================================

$allowedOrigin = "https://astercompv1.up.railway.app";

header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");


// ==================================================
// OPTIONS / PREFLIGHT
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}


// ==================================================
// ONLY GET
// ==================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode(
        [
            "success" => false,
            "message" => "Method not allowed",
            "allowed_method" => "GET"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CONFIGURATION
// ==================================================

$HEARTBEAT_TIMEOUT = 30;


// ==================================================
// DATABASE ENVIRONMENT
// ==================================================

$DB_HOST = getenv("MYSQLHOST");
$DB_PORT = getenv("MYSQLPORT");
$DB_NAME = getenv("MYSQLDATABASE");
$DB_USER = getenv("MYSQLUSER");
$DB_PASS = getenv("MYSQLPASSWORD");


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
            "message" => "Database environment variables are incomplete"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// DATABASE CONNECTION
// ==================================================

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(
    $DB_HOST,
    $DB_USER,
    $DB_PASS,
    $DB_NAME,
    (int)$DB_PORT
);


// ==================================================
// CHECK DATABASE CONNECTION
// ==================================================

if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,
            "message" => "Database connection failed"
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ==================================================
// CHARACTER SET
// ==================================================

$conn->set_charset("utf8mb4");


// ==================================================
// UTC -> WIB
// ==================================================

function utcToWIB($datetime)
{
    if ($datetime === null || $datetime === "") {
        return null;
    }

    try {

        $date = new DateTime(
            $datetime,
            new DateTimeZone("UTC")
        );

        $date->setTimezone(
            new DateTimeZone("Asia/Jakarta")
        );

        return $date->format("Y-m-d H:i:s");

    } catch (Exception $e) {

        return null;
    }
}


// ==================================================
// INITIAL NODE STRUCTURE
// ==================================================

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


// ==================================================
// GET LATEST RAW DATA
// ==================================================
// Tabel yang benar:
// raw_data
//
// Record terbaru ditentukan berdasarkan MAX(id)
// setiap node.
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

FROM raw_data r

INNER JOIN
(
    SELECT
        node_id,
        MAX(id) AS latest_id

    FROM raw_data

    WHERE node_id BETWEEN 1 AND 5

    GROUP BY node_id

) latest

ON
    r.node_id = latest.node_id
    AND r.id = latest.latest_id

ORDER BY
    r.node_id ASC

";

$resultRaw = $conn->query($sqlRaw);


// ==================================================
// CHECK RAW QUERY
// ==================================================

if (!$resultRaw) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,
            "message" => "Failed to retrieve latest raw data",
            "mysql_error" => $conn->error
        ],
        JSON_UNESCAPED_UNICODE
    );

    $conn->close();

    exit;
}


// ==================================================
// PROCESS RAW DATA
// ==================================================

while ($row = $resultRaw->fetch_assoc()) {

    $nodeId = intval($row["node_id"]);

    if ($nodeId < 1 || $nodeId > 5) {
        continue;
    }

    $nodes["node_" . $nodeId]["raw"] = [

        "id" => intval($row["id"]),

        "recorded_at" =>
            utcToWIB($row["recorded_at"]),

        "sample_index" =>
            intval($row["sample_index"]),

        "temperature" =>
            floatval($row["temperature"]),

        "moisture" =>
            floatval($row["moisture"]),

        "soil_adc" =>
            intval($row["soil_adc"]),

        "mq4_adc" =>
            intval($row["mq4_adc"]),

        "temp_status" =>
            $row["temp_status"],

        "mq4_status" =>
            $row["mq4_status"]
    ];
}


// ==================================================
// GET LATEST AGGREGATED DATA
// ==================================================
// Tabel yang benar:
// aggregated_data
//
// Kolom yang benar:
// daily_sample
//
// TIDAK menggunakan sample_index.
// ==================================================

$sqlAggregated = "

SELECT

    c.id,
    c.node_id,
    c.recorded_at,
    c.daily_sample,
    c.temperature,
    c.moisture,
    c.soil_adc,
    c.mq4_adc,
    c.ch4_index,
    c.compost_status,
    c.sample_count,
    c.next_update_at

FROM aggregated_data c

INNER JOIN
(
    SELECT
        node_id,
        MAX(id) AS latest_id

    FROM aggregated_data

    WHERE node_id BETWEEN 1 AND 5

    GROUP BY node_id

) latest

ON
    c.node_id = latest.node_id
    AND c.id = latest.latest_id

ORDER BY
    c.node_id ASC

";

$resultAggregated = $conn->query($sqlAggregated);


// ==================================================
// CHECK AGGREGATED QUERY
// ==================================================

if (!$resultAggregated) {

    http_response_code(500);

    echo json_encode(
        [
            "success" => false,
            "message" => "Failed to retrieve latest aggregated data",
            "mysql_error" => $conn->error
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

while ($row = $resultAggregated->fetch_assoc()) {

    $nodeId = intval($row["node_id"]);

    if ($nodeId < 1 || $nodeId > 5) {
        continue;
    }

    $nodes["node_" . $nodeId]["aggregated"] = [

        "id" =>
            intval($row["id"]),

        "recorded_at" =>
            utcToWIB($row["recorded_at"]),

        "daily_sample" =>
            intval($row["daily_sample"]),

        "temperature" =>
            floatval($row["temperature"]),

        "moisture" =>
            floatval($row["moisture"]),

        "soil_adc" =>
            floatval($row["soil_adc"]),

        "mq4_adc" =>
            floatval($row["mq4_adc"]),

        "ch4_index" =>
            $row["ch4_index"] !== null
                ? floatval($row["ch4_index"])
                : null,

        "compost_status" =>
            $row["compost_status"],

        "sample_count" =>
            intval($row["sample_count"]),

        "next_update_at" =>
            utcToWIB($row["next_update_at"])
    ];
}


// ==================================================
// DETERMINE NODE STATUS
// ==================================================
//
// Database saat ini tidak memiliki tabel node_status.
//
// Oleh karena itu last_seen menggunakan:
// raw_data.recorded_at terbaru.
//
// Jika data terbaru <= 30 detik:
// ONLINE
//
// Jika > 30 detik:
// OFFLINE
//
// Jika tidak ada data:
// OFFLINE
// ==================================================

$currentTimestamp = time();

for ($i = 1; $i <= 5; $i++) {

    $nodeKey = "node_" . $i;

    $lastSeen = null;

    if (
        isset($nodes[$nodeKey]["raw"]) &&
        $nodes[$nodeKey]["raw"] !== null
    ) {

        $lastSeen =
            $nodes[$nodeKey]["raw"]["recorded_at"];
    }


    // ==================================================
    // SAVE LAST SEEN
    // ==================================================

    $nodes[$nodeKey]["last_seen"] = $lastSeen;


    // ==================================================
    // DETERMINE ONLINE / OFFLINE
    // ==================================================

    if ($lastSeen !== null) {

        $lastSeenTimestamp = strtotime($lastSeen);

        if ($lastSeenTimestamp !== false) {

            $elapsed =
                $currentTimestamp - $lastSeenTimestamp;

            if (
                $elapsed >= 0 &&
                $elapsed <= $HEARTBEAT_TIMEOUT
            ) {

                $nodes[$nodeKey]["status"] = "ONLINE";

            } else {

                $nodes[$nodeKey]["status"] = "OFFLINE";
            }
        }
    }
}


// ==================================================
// SERVER TIME
// ==================================================

$serverTime =
    date("Y-m-d H:i:s");


// ==================================================
// RESPONSE
// ==================================================

$response = [

    "success" => true,

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


// ==================================================
// CLOSE DATABASE
// ==================================================

$conn->close();

?>
```

### Yang berubah penting

| Bagian lama                    | Bagian baru                    |
| ------------------------------ | ------------------------------ |
| `compost_raw`                  | `raw_data`                     |
| `compost_data`                 | `aggregated_data`              |
| `compost_data.sample_index`    | `aggregated_data.daily_sample` |
| `node_status`                  | tidak digunakan                |
| CORS `...up.railway.app/`      | `...up.railway.app`            |
| `last_seen` dari `node_status` | `raw_data.recorded_at`         |

Perubahan `raw_data` ini langsung memperbaiki query pertama yang saat ini gagal.

## Setelah mengganti file

**1. Commit dan push:**

```bash
git add api/v1/compost/latest.php
git commit -m "Fix latest API database tables"
git push origin main
```

**2. Tunggu Railway backend selesai deploy.**

**3. Jangan langsung buka frontend. Tes endpoint ini dulu:**

```text
https://astercomp.up.railway.app/api/v1/compost/latest.php
```

### Hasil yang kita harapkan

Kalau database masih kosong:

```json
{
    "success": true,
    "message": "Latest compost monitoring data retrieved successfully",
    "server_time": "....",
    "timezone": "Asia/Jakarta",
    "heartbeat_timeout": 30,
    "nodes": {
        "node_1": {
            "node_id": 1,
            "status": "OFFLINE",
            "last_seen": null,
            "raw": null,
            "aggregated": null
        }
    }
}
