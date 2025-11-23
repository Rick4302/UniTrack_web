<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");

// DB CONNECT
$conn = new mysqli("localhost", "root", "", "unitrack_admindb", 3307);

if ($conn->connect_error) {
    die("DB_ERROR");
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

error_log("=== ADMIN SIGNUP ATTEMPT ===");
error_log("Username: " . $username);
error_log("Email: " . $email);
error_log("Session pending_email: " . ($_SESSION['pending_email'] ?? 'NOT SET'));

// Verify email matches OTP email
if ($email !== $_SESSION['pending_email']) {
    error_log("EMAIL MISMATCH: Posted email ($email) vs Session email (" . ($_SESSION['pending_email'] ?? 'null') . ")");
    echo "EMAIL_MISMATCH";
    exit;
}

// Check if user exists
$stmt = $conn->prepare("SELECT COUNT(*) FROM adminusers WHERE Email=? OR Username=?");
$stmt->bind_param("ss", $email, $username);
$stmt->execute();
$stmt->bind_result($exists);
$stmt->fetch();
$stmt->close();

if ($exists > 0) {
    error_log("User already exists with email: " . $email . " or username: " . $username);
    echo "EXISTS";
    exit;
}

// Create PBKDF2 hash
$salt = random_bytes(16);
$hash = hash_pbkdf2("sha1", $password, $salt, 10000, 20, true);

$salt_b64 = base64_encode($salt);
$hash_b64 = base64_encode($hash);

error_log("Hash created successfully");
error_log("Salt (base64): " . $salt_b64);
error_log("Hash (base64): " . $hash_b64);

// Insert into MySQL
$stmt = $conn->prepare("
    INSERT INTO adminusers (Username, Email, PasswordHash, PasswordSalt, IsActive)
    VALUES (?, ?, ?, ?, 1)
");

if (!$stmt) {
    error_log("PREPARE ERROR: " . $conn->error);
    echo "PREPARE_FAIL";
    exit;
}

$stmt->bind_param("ssss", $username, $email, $hash_b64, $salt_b64);

if ($stmt->execute()) {
    error_log("User inserted successfully: " . $email);
    
    // Clear OTP session data
    unset($_SESSION['pending_otp']);
    unset($_SESSION['otp_expiry']);
    unset($_SESSION['pending_email']);
    unset($_SESSION['otp_verified']);
    
    echo "SUCCESS";
} else {
    error_log("EXECUTE ERROR: " . $stmt->error);
    error_log("Full error info: " . $conn->error);
    echo "INSERT_FAIL: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>