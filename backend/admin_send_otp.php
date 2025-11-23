<?php
session_start();
header("Access-Control-Allow-Origin: http://localhost");  
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

// Accept both form-encoded and JSON payloads
$email = "";
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $json = json_decode(file_get_contents("php://input"), true);
    $email = $json['email'] ?? "";
} else {
    // Works for both multipart/form-data and x-www-form-urlencoded
    $email = $_POST['email'] ?? "";
}

$email = trim($email);

// Validate email
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Please enter a valid email address."]);
    exit;
}

// Generate 6-digit OTP
$otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Reset previous OTP
unset($_SESSION['pending_otp']);
unset($_SESSION['otp_expiry']);
unset($_SESSION['pending_email']);

// Store OTP + email + expiry in session
$_SESSION['pending_otp'] = (string)$otp;
$_SESSION['otp_expiry'] = time() + 300; // 5 minutes
$_SESSION['pending_email'] = $email;

$mail = new PHPMailer(true);

try {
    // SMTP configuration
    $mail->isSMTP();
    $mail->Host = "smtp.gmail.com";
    $mail->SMTPAuth = true;
    $mail->Username = "unitracksti@gmail.com";
    $mail->Password = "jybm ipxb hjvi tsdj"; // Use app password (move to .env for production)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Email content
    $mail->setFrom("unitracksti@gmail.com", "UniTrack");
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your UniTrack Verification Code";
    
    // Professional HTML email template (Blue theme for Admin)
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #4169E1;'>Email Verification Required</h2>
            <p>Welcome to UniTrack Admin! To complete your account registration, please verify your email address.</p>
            <div style='background-color: #f4f4f4; padding: 20px; text-align: center; margin: 20px 0; border-left: 4px solid #4169E1;'>
                <p style='color: #666; margin: 0 0 10px 0; font-size: 14px;'>Your Verification Code:</p>
                <h1 style='color: #4169E1; font-size: 48px; margin: 0; letter-spacing: 5px;'>{$otp}</h1>
            </div>
            <p><strong>This code will expire in 5 minutes.</strong></p>
            <p>If you did not request this verification code, please ignore this email or contact us immediately.</p>
            <hr style='border: 1px solid #ddd; margin: 20px 0;'>
            <p style='color: #666; font-size: 12px;'>This is an automated message from UniTrack. Please do not reply to this email.</p>
        </div>
    ";
    
    $mail->AltBody = "Your verification code is: {$otp}. It expires in 5 minutes.";

    $mail->send();

    http_response_code(200);
    echo json_encode([
        "status" => "success", 
        "message" => "Verification code sent to your email.",
        "email" => $email
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "Failed to send email. Please try again."
    ]);
    error_log("OTP Email Error: " . $mail->ErrorInfo);
    exit;
}