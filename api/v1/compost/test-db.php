<?php

header('Content-Type: application/json');

echo json_encode([
    'test' => true,
    'file' => 'test-db.php',
    'php_version' => PHP_VERSION
]);
