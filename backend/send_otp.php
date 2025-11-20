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



// Accept both form-encoded and JSON payloads:
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

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email missing"]);
    exit;
}

// generate OTP
$otp = random_int(100000, 999999);

// reset previous OTP
unset($_SESSION['pending_otp']);
unset($_SESSION['otp_expiry']);
unset($_SESSION['pending_email']);

// store OTP + email + expiry in session
$_SESSION['pending_otp'] = (string)$otp;
$_SESSION['otp_expiry'] = time() + 300; // 5 minutes
$_SESSION['pending_email'] = $email;

$mail = new PHPMailer(true);

try {
    // debug off normally; enable only while troubleshooting
    // $mail->SMTPDebug = 2;
    // $mail->Debugoutput = 'html';

    $mail->isSMTP();
    $mail->Host = "smtp.gmail.com";
    $mail->SMTPAuth = true;
    $mail->Username = "unitracksti@gmail.com";
    $mail->Password = "jybm ipxb hjvi tsdj"; // replace
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom("unitracksti@gmail.com", "UniTrack");
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your UniTrack OTP Code";
    $mail->Body = "<h2>Your OTP Code is:</h2><h1>{$otp}</h1><p>This code is valid for 5 minutes.</p>";

    $mail->send();

    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "OTP sent"]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $mail->ErrorInfo]);
    exit;
}
