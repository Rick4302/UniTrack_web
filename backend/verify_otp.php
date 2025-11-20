<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: text/plain");

// Debug - check this in your PHP error log
error_log("Session ID: " . session_id());
error_log("Pending OTP: " . ($_SESSION['pending_otp'] ?? 'NOT SET'));

$entered = $_POST['otp'] ?? "";

if (empty($entered)) {
    echo "OTP_INVALID";
    exit;
}

if (!isset($_SESSION['pending_otp']) || !isset($_SESSION['pending_email'])) {
    echo "NO_OTP";
    exit;
}

if (time() > ($_SESSION['otp_expiry'] ?? 0)) {
    unset($_SESSION['pending_otp'], $_SESSION['otp_expiry'], $_SESSION['pending_email']);
    echo "OTP_EXPIRED";
    exit;
}

if ((string)$entered === (string)$_SESSION['pending_otp']) {
    $_SESSION['otp_verified'] = true;
    echo "OTP_VALID";
} else {
    echo "OTP_INVALID";
}