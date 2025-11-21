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
$newPassword = $data['password'] ?? "";

if (empty($email) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password are required."]);
    exit;
}

// Verify session - user must have verified code
if (!isset($_SESSION['reset_code_verified']) || 
    !isset($_SESSION['reset_email']) || 
    $_SESSION['reset_email'] !== $email) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized. Please verify your reset code first."]);
    exit;
}

// Password validation (basic)
if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Password must be at least 6 characters long."]);
    exit;
}

// Generate new password hash using PBKDF2 (matching C# implementation)
$salt = random_bytes(16);
$hash = hash_pbkdf2("sha1", $newPassword, $salt, 10000, 20, true);

$salt_b64 = base64_encode($salt);
$hash_b64 = base64_encode($hash);

// Update password and clear reset tokens
$stmt = $conn->prepare("
    UPDATE adminusers 
    SET PasswordHash = ?, 
        PasswordSalt = ?, 
        ResetToken = NULL, 
        ResetTokenExpiry = NULL,
        IsLocked = 0,
        FailedAttempts = 0
    WHERE Email = ?
");
$stmt->bind_param("sss", $hash_b64, $salt_b64, $email);

if ($stmt->execute()) {
    // Clear session variables
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_code_sent']);
    unset($_SESSION['reset_code_verified']);
    unset($_SESSION['reset_user_id']);
    
    $stmt->close();
    $conn->close();
    
    http_response_code(200);
    echo json_encode([
        "status" => "success", 
        "message" => "Password reset successfully. You can now login with your new password."
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "Failed to update password. Please try again."
    ]);
    $stmt->close();
    $conn->close();
}
?>