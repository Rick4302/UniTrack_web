<?php

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "unitrack_admindb";
$port       = 3307;

// Suppress display errors to prevent breaking JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = new mysqli($servername, $username, $password, $dbname, $port);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    // Log the error instead of echoing
    error_log("Database connection failed: " . $conn->connect_error);
    // Don't echo here - let the calling script handle it
    die(); // Just stop execution
}
?>