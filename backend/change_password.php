<?php
// Set session cookie parameters BEFORE starting session
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session
session_start();

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db_connect.php';

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);
$prevPassword = $data['prevPassword'] ?? "";
$newPassword = $data['newPassword'] ?? "";
$userEmail = $_SESSION['email'] ?? null;

// Validate session - user must be logged in
if (!$userEmail) {
    http_response_code(401);
    echo json_encode([
        "status" => "error", 
        "message" => "Session expired. Please login again."
    ]);
    exit;
}

// Validate input
if (empty($prevPassword) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "Previous password and new password are required."
    ]);
    exit;
}

// Password validation
if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "New password must be at least 6 characters long."
    ]);
    exit;
}

// Check if new password is same as old password
if ($prevPassword === $newPassword) {
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "New password must be different from the previous password."
    ]);
    exit;
}

// Get current user's password hash and salt
$stmt = $conn->prepare("SELECT Id, PasswordHash, PasswordSalt FROM adminusers WHERE Email = ?");
$stmt->bind_param("s", $userEmail);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode([
        "status" => "error", 
        "message" => "User not found."
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// Verify previous password using PBKDF2
$stored_salt = base64_decode($user['PasswordSalt']);
$stored_hash = base64_decode($user['PasswordHash']);
$computed_hash = hash_pbkdf2("sha1", $prevPassword, $stored_salt, 10000, 20, true);

if (!hash_equals($stored_hash, $computed_hash)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "Previous password is incorrect."
    ]);
    $conn->close();
    exit;
}

// Generate new password hash using PBKDF2
$new_salt = random_bytes(16);
$new_hash = hash_pbkdf2("sha1", $newPassword, $new_salt, 10000, 20, true);

$salt_b64 = base64_encode($new_salt);
$hash_b64 = base64_encode($new_hash);

// Update password in database
$stmt = $conn->prepare("
    UPDATE adminusers 
    SET PasswordHash = ?, 
        PasswordSalt = ?,
        IsLocked = 0,
        FailedAttempts = 0
    WHERE Id = ?
");
$stmt->bind_param("ssi", $hash_b64, $salt_b64, $user['Id']);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    
    http_response_code(200);
    echo json_encode([
        "status" => "success", 
        "message" => "Password changed successfully!"
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