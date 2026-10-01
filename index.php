<?php

header("Content-Type: application/json; charset=utf-8");

echo json_encode([
    "success" => true,
    "service" => "Aster Smart Compost API",
    "status" => "online",
    "version" => "1.0.0"
]);

?>
