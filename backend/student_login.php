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
$email = $data["email"] ?? "";
$password = $data["password"] ?? "";
$remember = $data["remember"] ?? false;

if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "Email is required."]);
    exit;
}

// Fetch student user (single query from studentusers table)
$stmt = $conn->prepare("
    SELECT StudentID, PasswordHash, PasswordSalt, FailedAttempts, IsLocked 
    FROM studentusers 
    WHERE Email=?
");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "No student account found with this email. Please sign up first."]);
    exit;
}

if ($user["IsLocked"]) {
    echo json_encode(["status" => "error", "message" => "Account is locked. Please reset your password."]);
    exit;
}

// Two paths:
// 1) OTP-based: session contains otp_verified true and matching pending_email
// 2) Password-based: password provided in request

$otp_ok = false;
if (!empty($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true &&
    !empty($_SESSION['pending_email']) && $_SESSION['pending_email'] === $email) {
    $otp_ok = true;
}

// If no password AND not OTP verified -> error
if (empty($password) && !$otp_ok) {
    echo json_encode(["status" => "error", "message" => "Password missing and OTP not verified."]);
    exit;
}

// If password provided, verify it:
if (!empty($password) && !$otp_ok) {
    $salt = base64_decode($user["PasswordSalt"] ?? "");
    $hash = base64_decode($user["PasswordHash"] ?? "");

    // If password hash / salt are missing in DB, reject (security)
    if ($salt === false || $hash === false || $salt === "" || $hash === "") {
        echo json_encode(["status" => "error", "message" => "Password login not available for this account. Use OTP."]);
        exit;
    }

    $computedHash = hash_pbkdf2("sha1", $password, $salt, 10000, 20, true);

    if ($computedHash !== $hash) {
        // Increase failed attempts
        $failed = (int)$user["FailedAttempts"] + 1;
        $stmt = $conn->prepare("UPDATE studentusers SET FailedAttempts=? WHERE StudentID=?");
        $stmt->bind_param("ii", $failed, $user["StudentID"]);
        $stmt->execute();
        $stmt->close();

        if ($failed >= 3) {
            $stmt = $conn->prepare("UPDATE studentusers SET IsLocked=1 WHERE StudentID=?");
            $stmt->bind_param("i", $user["StudentID"]);
            $stmt->execute();
            $stmt->close();
            echo json_encode(["status" => "error", "message" => "Account locked after 3 failed attempts."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid password. " . (3 - $failed) . " attempts remaining."]);
        }
        exit;
    }
}

// If we reach here, either password was correct OR OTP verified
// Reset failed attempts
$stmt = $conn->prepare("UPDATE studentusers SET FailedAttempts=0 WHERE StudentID=?");
$stmt->bind_param("i", $user["StudentID"]);
$stmt->execute();
$stmt->close();

// Remember-me token if requested
if ($remember) {
    $token = bin2hex(random_bytes(16));
    $expiry = date("Y-m-d H:i:s", strtotime("+7 days"));

    $stmt = $conn->prepare("UPDATE studentusers SET RememberMeToken=?, TokenExpiry=? WHERE StudentID=?");
    $stmt->bind_param("ssi", $token, $expiry, $user["StudentID"]);
    $stmt->execute();
    $stmt->close();

    setcookie("remember_me", $token, time() + 604800, "/");
}

// Save session and clear otp flags
$_SESSION["email"] = $email;
$_SESSION["studentID"] = $user["StudentID"];
unset($_SESSION['pending_otp']);
unset($_SESSION['otp_expiry']);
unset($_SESSION['pending_email']);
unset($_SESSION['otp_verified']);

// Log session creation for debugging
error_log("=== STUDENT LOGIN SUCCESS ===");
error_log("Session ID: " . session_id());
error_log("Email stored in session: " . $_SESSION["email"]);
error_log("Student ID: " . $_SESSION["studentID"]);

echo json_encode(["status" => "success", "message" => "Login successful.", "userId" => $user["StudentID"]]);
exit;