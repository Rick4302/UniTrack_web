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

include 'db_connect.php';

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? "");

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Please enter your email address."]);
    exit;
}

// Check if email exists in database
$stmt = $conn->prepare("SELECT Id FROM adminusers WHERE Email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Email not found in our system."]);
    exit;
}

// Generate 6-digit reset code (matching C# implementation)
$resetCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

// Update reset token in database
$stmt = $conn->prepare("UPDATE adminusers SET ResetToken = ?, ResetTokenExpiry = ? WHERE Email = ?");
$stmt->bind_param("sss", $resetCode, $expiry, $email);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to generate reset code."]);
    $stmt->close();
    exit;
}
$stmt->close();

// Store in session for verification
$_SESSION['reset_email'] = $email;
$_SESSION['reset_code_sent'] = true;

// Send email using PHPMailer
$mail = new PHPMailer(true);

try {
    // SMTP configuration
    $mail->isSMTP();
    $mail->Host = "smtp.gmail.com";
    $mail->SMTPAuth = true;
    $mail->Username = "unitracksti@gmail.com";
    $mail->Password = "jybm ipxb hjvi tsdj"; // Use your actual app password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Email content
    $mail->setFrom("unitracksti@gmail.com", "UniTrack");
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "Your Password Reset Code - UniTrack";
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #4169E1;'>Password Reset Request</h2>
            <p>You have requested to reset your password for your UniTrack admin account.</p>
            <div style='background-color: #f4f4f4; padding: 20px; text-align: center; margin: 20px 0;'>
                <h1 style='color: #4169E1; font-size: 36px; margin: 0;'>{$resetCode}</h1>
            </div>
            <p><strong>This code will expire in 10 minutes.</strong></p>
            <p>If you did not request this password reset, please ignore this email.</p>
            <hr style='border: 1px solid #ddd; margin: 20px 0;'>
            <p style='color: #666; font-size: 12px;'>This is an automated message from UniTrack. Please do not reply to this email.</p>
        </div>
    ";
    $mail->AltBody = "Your password reset code is: {$resetCode}. It expires in 10 minutes.";

    $mail->send();

    http_response_code(200);
    echo json_encode([
        "status" => "success", 
        "message" => "Password reset code sent. Please check your email.",
        "email" => $email
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "Failed to send email: " . $mail->ErrorInfo
    ]);
}

$conn->close();
?>