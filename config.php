<?php

// ==================================================
// ASTER SMART COMPOST API
// CONFIGURATION
// ==================================================
//
// File ini hanya berisi konfigurasi.
//
// TIDAK:
// - mengirim JSON response
// - membuat output
// - menjalankan exit()
// - membuat koneksi database
//
// Endpoint seperti raw.php, data.php, heartbeat.php,
// latest.php, history.php, dan reset.php menangani
// koneksi database masing-masing.
//
// ==================================================


// ==================================================
// TIMEZONE
// ==================================================

date_default_timezone_set("Asia/Jakarta");


// ==================================================
// DATABASE CONFIGURATION
// ==================================================
//
// Railway MySQL menyediakan:
//
// MYSQLHOST
// MYSQLPORT
// MYSQLDATABASE
// MYSQLUSER
// MYSQLPASSWORD
//
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
// API KEY
// ==================================================
//
// ESP32 mengirim:
//
// Authorization: Bearer API_KEY
//
// ==================================================

$API_KEY =
    getenv("API_KEY");


// ==================================================
// CONFIGURATION STATUS
// ==================================================

$configMissing = [];


// --------------------------------------------------
// DATABASE
// --------------------------------------------------

if (
    $DB_HOST === false ||
    $DB_HOST === ""
) {

    $configMissing[] =
        "MYSQLHOST";
}


if (
    $DB_PORT === false ||
    $DB_PORT === ""
) {

    $configMissing[] =
        "MYSQLPORT";
}


if (
    $DB_NAME === false ||
    $DB_NAME === ""
) {

    $configMissing[] =
        "MYSQLDATABASE";
}


if (
    $DB_USER === false ||
    $DB_USER === ""
) {

    $configMissing[] =
        "MYSQLUSER";
}


if (
    $DB_PASS === false ||
    $DB_PASS === ""
) {

    $configMissing[] =
        "MYSQLPASSWORD";
}


// --------------------------------------------------
// API KEY
// --------------------------------------------------

if (
    $API_KEY === false ||
    $API_KEY === ""
) {

    $configMissing[] =
        "API_KEY";
}


// ==================================================
// CONFIGURATION RESULT
// ==================================================

$configValid =
    empty($configMissing);

?>
