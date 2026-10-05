<?php

// ============================================================
// ASTER SMART COMPOST API
// EXPORT ENDPOINT
// CSV / ZIP
// ============================================================
//
// Endpoint:
// GET /api/v1/compost/export.php
//
// Query Parameters:
//   type      (REQUIRED) "raw" | "aggregated" | "both"
//   node_id   (optional) 1-5, or "all"
//   day       (optional) 1+, or "all"
//
// Examples:
//   ?type=raw
//   ?type=raw&node_id=1
//   ?type=aggregated
//   ?type=aggregated&node_id=1
//   ?type=both
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


// ============================================================
// OPTIONS
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}


// ============================================================
// ONLY GET
// ============================================================

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    header("Content-Type: application/json; charset=utf-8");

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
// JSON ERROR
// ============================================================

function jsonError(int $httpCode, string $message): void
{
    header("Content-Type: application/json; charset=utf-8");

    http_response_code($httpCode);

    echo json_encode([
        "success" => false,
        "message" => $message
    ], JSON_UNESCAPED_UNICODE);

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
    jsonError(
        500,
        "Database environment variables are incomplete"
    );
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

    jsonError(
        500,
        "Database connection failed: " . $conn->connect_error
    );
}


if (!$conn->set_charset("utf8mb4")) {

    $conn->close();

    jsonError(
        500,
        "Failed to configure database character set"
    );
}


// ============================================================
// UTC → WIB
// ============================================================

function utcToWIB($datetime): string
{
    if ($datetime === null || $datetime === "") {
        return "";
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

        return "";
    }
}


// ============================================================
// QUERY PARAMETERS
// ============================================================

// ------------------------------------------------------------
// TYPE
// ------------------------------------------------------------

$type = strtolower(
    trim($_GET["type"] ?? "")
);

if (!in_array(
    $type,
    ["raw", "aggregated", "both"],
    true
)) {

    $conn->close();

    jsonError(
        400,
        "type parameter is required and must be 'raw', 'aggregated', or 'both'"
    );
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

        jsonError(
            400,
            "node_id must be an integer between 1 and 5, or 'all'"
        );
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

    if (
        $dayFilter === false ||
        $dayFilter < 1
    ) {

        $conn->close();

        jsonError(
            400,
            "day must be a positive integer, or 'all'"
        );
    }
}


// ============================================================
// BUILD DAY NUMBER MAP
// ============================================================
//
// Day number dihitung dari tanggal kalender berdasarkan
// waktu WIB.
//
// Contoh:
//
// UTC 2026-10-05 08:08:39
//          ↓
// WIB 2026-10-05 15:08:39
//
// ============================================================

function buildDayNumberMap(
    mysqli $conn,
    string $table,
    ?int $nodeId
): array {

    $whereClause = "";

    if ($nodeId !== null) {

        $whereClause =
            "WHERE node_id = " . intval($nodeId);
    }


    $sql = "
        SELECT recorded_at
        FROM $table
        $whereClause
        ORDER BY recorded_at ASC, id ASC
    ";


    $result = $conn->query($sql);

    $map = [];

    if (!$result) {
        return $map;
    }


    $counter = 1;
    $lastDate = null;


    while ($row = $result->fetch_assoc()) {

        $wibDate = utcToWIB(
            $row["recorded_at"]
        );

        if ($wibDate === "") {
            continue;
        }


        $dateOnly = substr(
            $wibDate,
            0,
            10
        );


        if ($dateOnly !== $lastDate) {

            if (!isset($map[$dateOnly])) {

                $map[$dateOnly] = $counter;

                $counter++;
            }

            $lastDate = $dateOnly;
        }
    }


    $result->free();

    return $map;
}


// ============================================================
// STREAM TABLE TO CSV
// ============================================================

const CHUNK_SIZE = 1000;


function streamTableCsv(
    mysqli $conn,
    string $table,
    string $type,
    ?int $nodeId,
    ?int $dayFilter,
    $out
): int {


    // --------------------------------------------------------
    // DAY MAP
    // --------------------------------------------------------

    $dayNumberMap = buildDayNumberMap(
        $conn,
        $table,
        $nodeId
    );


    // --------------------------------------------------------
    // SELECT COLUMNS
    // --------------------------------------------------------

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


        $header = [
            "day_number",
            "recorded_at",
            "node_id",
            "sample_index",
            "temperature",
            "moisture",
            "soil_adc",
            "mq4_adc",
            "temp_status",
            "mq4_status"
        ];

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


        $header = [
            "day_number",
            "recorded_at",
            "node_id",
            "daily_sample",
            "temperature",
            "moisture",
            "soil_adc",
            "mq4_adc",
            "ch4_index",
            "compost_status",
            "sample_count",
            "next_update_at"
        ];
    }


    // --------------------------------------------------------
    // CSV HEADER
    // --------------------------------------------------------

    fputcsv(
        $out,
        $header
    );


    // --------------------------------------------------------
    // WHERE
    // --------------------------------------------------------

    $whereClause = "";

    if ($nodeId !== null) {

        $whereClause =
            "WHERE node_id = " . intval($nodeId);
    }


    // --------------------------------------------------------
    // STREAM DATA
    // --------------------------------------------------------

    $exported = 0;
    $offset = 0;


    while (true) {

        $sql = "
            SELECT
                $selectColumns
            FROM $table
            $whereClause
            ORDER BY recorded_at ASC, id ASC
            LIMIT " . CHUNK_SIZE . "
            OFFSET " . intval($offset);


        $result = $conn->query($sql);


        if (!$result) {

            break;
        }


        $rowsInChunk = 0;


        while ($row = $result->fetch_assoc()) {

            $rowsInChunk++;


            // ------------------------------------------------
            // WIB DATE
            // ------------------------------------------------

            $wibDateTime = utcToWIB(
                $row["recorded_at"]
            );


            if ($wibDateTime === "") {

                continue;
            }


            $recordedDate = substr(
                $wibDateTime,
                0,
                10
            );


            $dayNumber =
                $dayNumberMap[$recordedDate] ?? 1;


            // ------------------------------------------------
            // DAY FILTER
            // ------------------------------------------------

            if (
                $dayFilter !== null &&
                $dayNumber !== $dayFilter
            ) {

                continue;
            }


            // =================================================
            // RAW
            // =================================================

            if ($type === "raw") {

                $line = [

                    $dayNumber,

                    $wibDateTime,

                    intval($row["node_id"]),

                    intval($row["sample_index"]),

                    floatval($row["temperature"]),

                    floatval($row["moisture"]),

                    intval($row["soil_adc"]),

                    intval($row["mq4_adc"]),

                    $row["temp_status"],

                    $row["mq4_status"]
                ];


            // =================================================
            // AGGREGATED
            // =================================================

            } else {

                $line = [

                    $dayNumber,

                    $wibDateTime,

                    intval($row["node_id"]),

                    intval($row["daily_sample"]),

                    floatval($row["temperature"]),

                    floatval($row["moisture"]),

                    floatval($row["soil_adc"]),

                    floatval($row["mq4_adc"]),

                    $row["ch4_index"] !== null
                        ? floatval($row["ch4_index"])
                        : "",

                    $row["compost_status"] ?? "",

                    intval($row["sample_count"]),

                    $row["next_update_at"] !== null
                        ? utcToWIB($row["next_update_at"])
                        : ""
                ];
            }


            fputcsv(
                $out,
                $line
            );


            $exported++;
        }


        $result->free();


        $offset += CHUNK_SIZE;


        if ($rowsInChunk < CHUNK_SIZE) {

            break;
        }
    }


    return $exported;
}


// ============================================================
// EXECUTE EXPORT
// ============================================================

$dateStr = date("Y-m-d");


try {


    // ========================================================
    // EXPORT BOTH → ZIP
    // ========================================================

    if ($type === "both") {


        // ----------------------------------------------------
        // CHECK ZIP EXTENSION
        // ----------------------------------------------------

        if (!class_exists("ZipArchive")) {

            $conn->close();

            jsonError(
                500,
                "ZipArchive extension is not available on this server"
            );
        }


        // ----------------------------------------------------
        // TEMP ZIP
        // ----------------------------------------------------

        $tmpZip = tempnam(
            sys_get_temp_dir(),
            "astercomp_export_"
        );


        if ($tmpZip === false) {

            $conn->close();

            jsonError(
                500,
                "Failed to create temporary ZIP file"
            );
        }


        // ----------------------------------------------------
        // CREATE ZIP
        // ----------------------------------------------------

        $zip = new ZipArchive();


        $zipResult = $zip->open(
            $tmpZip,
            ZipArchive::CREATE |
            ZipArchive::OVERWRITE
        );


        if ($zipResult !== true) {

            unlink($tmpZip);

            $conn->close();

            jsonError(
                500,
                "Failed to create ZIP archive"
            );
        }


        // ----------------------------------------------------
        // RAW CSV
        // ----------------------------------------------------

        $rawBuf = fopen(
            "php://temp",
            "r+"
        );


        if ($rawBuf === false) {

            $zip->close();
            unlink($tmpZip);
            $conn->close();

            jsonError(
                500,
                "Failed to create RAW CSV buffer"
            );
        }


        fwrite(
            $rawBuf,
            "\xEF\xBB\xBF"
        );


        $rawCount = streamTableCsv(
            $conn,
            "raw_data",
            "raw",
            $nodeId,
            $dayFilter,
            $rawBuf
        );


        rewind($rawBuf);


        $rawCsv = stream_get_contents(
            $rawBuf
        );


        fclose($rawBuf);


        $zip->addFromString(
            "ASTERCOMP_RAW_{$dateStr}.csv",
            $rawCsv
        );


        // ----------------------------------------------------
        // AGGREGATED CSV
        // ----------------------------------------------------

        $aggBuf = fopen(
            "php://temp",
            "r+"
        );


        if ($aggBuf === false) {

            $zip->close();
            unlink($tmpZip);
            $conn->close();

            jsonError(
                500,
                "Failed to create AGGREGATED CSV buffer"
            );
        }


        fwrite(
            $aggBuf,
            "\xEF\xBB\xBF"
        );


        $aggCount = streamTableCsv(
            $conn,
            "aggregated_data",
            "aggregated",
            $nodeId,
            $dayFilter,
            $aggBuf
        );


        rewind($aggBuf);


        $aggCsv = stream_get_contents(
            $aggBuf
        );


        fclose($aggBuf);


        $zip->addFromString(
            "ASTERCOMP_AGGREGATED_{$dateStr}.csv",
            $aggCsv
        );


        // ----------------------------------------------------
        // CLOSE ZIP
        // ----------------------------------------------------

        $zip->close();

        $conn->close();


        // ----------------------------------------------------
        // SEND ZIP
        // ----------------------------------------------------

        header(
            "Content-Type: application/zip"
        );

        header(
            'Content-Disposition: attachment; filename="ASTERCOMP_EXPORT_' .
            $dateStr .
            '.zip"'
        );

        header(
            "Content-Length: " .
            filesize($tmpZip)
        );


        readfile($tmpZip);

        unlink($tmpZip);

        exit;
    }


    // ========================================================
    // SINGLE CSV
    // ========================================================

    if ($type === "raw") {

        $table = "raw_data";

    } else {

        $table = "aggregated_data";
    }


    // --------------------------------------------------------
    // CSV HEADERS
    // --------------------------------------------------------

    header(
        "Content-Type: text/csv; charset=utf-8"
    );

    header(
        'Content-Disposition: attachment; filename="ASTERCOMP_' .
        strtoupper($type) .
        '_' .
        $dateStr .
        '.csv"'
    );


    // --------------------------------------------------------
    // OUTPUT
    // --------------------------------------------------------

    $out = fopen(
        "php://output",
        "w"
    );


    if ($out === false) {

        $conn->close();

        exit;
    }


    // UTF-8 BOM for Excel

    fwrite(
        $out,
        "\xEF\xBB\xBF"
    );


    streamTableCsv(
        $conn,
        $table,
        $type,
        $nodeId,
        $dayFilter,
        $out
    );


    fclose($out);

    $conn->close();

    exit;


} catch (Throwable $e) {


    if (isset($conn) && $conn instanceof mysqli) {

        $conn->close();
    }


    jsonError(
        500,
        "Export failed: " . $e->getMessage()
    );
}

?>
