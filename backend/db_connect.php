<?php

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "unitrack_admindb"; // <<< FIXED
$port       = 3307;

$conn = new mysqli($servername, $username, $password, $dbname, $port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $conn->connect_error
    ]);
    exit;
}
?>
