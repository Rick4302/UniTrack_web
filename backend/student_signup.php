<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");

include 'db_connect.php'; // This connects to unitrack_admindb database (both tables are here)

$studentId = trim($_POST['studentId'] ?? '');
$course = trim($_POST['course'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

error_log("=== STUDENT SIGNUP ATTEMPT ===");
error_log("StudentID: " . $studentId);
error_log("Course: " . $course);
error_log("Email: " . $email);

// Verify email matches OTP email
if ($email !== $_SESSION['pending_email']) {
    error_log("EMAIL MISMATCH in student signup");
    echo "EMAIL_MISMATCH";
    $conn->close();
    exit;
}

// Check if all fields are provided
if (empty($studentId) || empty($course) || empty($email) || empty($password)) {
    echo "MISSING_FIELDS";
    $conn->close();
    exit;
}

// Check if student already exists in studentusers table
$stmt = $conn->prepare("SELECT COUNT(*) FROM studentusers WHERE Email=? OR StudentID=?");
$stmt->bind_param("ss", $email, $studentId);
$stmt->execute();
$stmt->bind_result($student_exists);
$stmt->fetch();
$stmt->close();

if ($student_exists > 0) {
    error_log("Student account already exists: " . $email);
    echo "EXISTS";
    $conn->close();
    exit;
}

// Check if email exists in adminusers table
$stmt = $conn->prepare("SELECT COUNT(*) FROM adminusers WHERE Email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($admin_exists);
$stmt->fetch();
$stmt->close();

if ($admin_exists > 0) {
    error_log("Email already registered as admin account: " . $email);
    echo "EMAIL_REGISTERED_AS_ADMIN";
    $conn->close();
    exit;
}

// Create PBKDF2 hash (same as admin)
$salt = random_bytes(16);
$hash = hash_pbkdf2("sha1", $password, $salt, 10000, 20, true);

$salt_b64 = base64_encode($salt);
$hash_b64 = base64_encode($hash);

// Insert into studentusers table with Course
$stmt = $conn->prepare("
    INSERT INTO studentusers (StudentID, Course, Email, PasswordHash, PasswordSalt, IsActive)
    VALUES (?, ?, ?, ?, ?, 1)
");
$stmt->bind_param("sssss", $studentId, $course, $email, $hash_b64, $salt_b64);

if ($stmt->execute()) {
    error_log("Student account created successfully: " . $email);
    
    // Clear OTP session data
    unset($_SESSION['pending_otp']);
    unset($_SESSION['otp_expiry']);
    unset($_SESSION['pending_email']);
    unset($_SESSION['otp_verified']);
    
    echo "SUCCESS";
} else {
    error_log("Insert failed for student: " . $stmt->error);
    echo "INSERT_FAIL";
}

$stmt->close();
$conn->close();
exit;
?>