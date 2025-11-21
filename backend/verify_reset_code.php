<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

include 'db_connect.php';

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? "");
$code = trim($data['code'] ?? "");

if (empty($email) || empty($code)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and code are required."]);
    exit;
}

// Verify session
if (!isset($_SESSION['reset_email']) || $_SESSION['reset_email'] !== $email) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Invalid reset session."]);
    exit;
}

// Check reset code and expiry
$stmt = $conn->prepare("
    SELECT Id, ResetToken, ResetTokenExpiry 
    FROM adminusers 
    WHERE Email = ?
");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "User not found."]);
    exit;
}

// Check if token exists
if (empty($user['ResetToken'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No reset code found. Please request a new one."]);
    exit;
}

// Check if token expired
$expiry = strtotime($user['ResetTokenExpiry']);
if (time() > $expiry) {
    // Clear expired token
    $stmt = $conn->prepare("UPDATE adminusers SET ResetToken = NULL, ResetTokenExpiry = NULL WHERE Email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
    
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_code_sent']);
    
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Reset code has expired. Please request a new one."]);
    exit;
}

// Verify code
if ($code !== $user['ResetToken']) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid reset code."]);
    exit;
}

// Code is valid - set session flag
$_SESSION['reset_code_verified'] = true;
$_SESSION['reset_user_id'] = $user['Id'];

http_response_code(200);
echo json_encode([
    "status" => "success", 
    "message" => "Code verified successfully.",
    "userId" => $user['Id']
]);

$conn->close();
?>