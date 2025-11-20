<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");  // or your exact frontend origin
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");

// DB CONNECT
$conn = new mysqli("localhost", "root", "", "unitrack_admindb", 3307);

if ($conn->connect_error) {
    die("DB_ERROR");
}

$username = trim($_POST['username']);
$email = trim($_POST['email']);
$password = $_POST['password'];

// Verify email matches OTP email
if ($email !== $_SESSION['pending_email']) {
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
    echo "EXISTS";
    exit;
}

// Create PBKDF2 hash (same as C#)
$salt = random_bytes(16);
$hash = hash_pbkdf2("sha1", $password, $salt, 10000, 20, true);

$salt_b64 = base64_encode($salt);
$hash_b64 = base64_encode($hash);

// Insert into MySQL
$stmt = $conn->prepare("
    INSERT INTO adminusers (Username, Email, PasswordHash, PasswordSalt, IsActive)
    VALUES (?, ?, ?, ?, 1)
");
$stmt->bind_param("ssss", $username, $email, $hash_b64, $salt_b64);

if ($stmt->execute()) {
    error_log("User inserted: " . $email);
    echo "SUCCESS";
} else {
    error_log("Insert failed: " . $stmt->error);
    echo "INSERT_FAIL";
}

$stmt->close();
$conn->close();
?>
