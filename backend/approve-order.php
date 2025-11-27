<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';
if (!isset($conn) || !$conn) { die(json_encode(['status' => 'error', 'message' => 'Database connection failed'])); }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer (adjust path if needed)
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['orderID']) || !isset($input['action'])) {
        throw new Exception("Missing orderID or action");
    }
    
    $orderId = intval($input['orderID']);
    $action = trim($input['action']);
    $rejectionReason = isset($input['rejectionReason']) ? trim($input['rejectionReason']) : null;
    
    // Validate action
    if (!in_array($action, ['approve', 'reject'])) {
        throw new Exception("Invalid action. Must be 'approve' or 'reject'");
    }
    
    // If rejecting, require reason
    if ($action === 'reject' && empty($rejectionReason)) {
        throw new Exception("Rejection reason is required");
    }
    
    $conn->begin_transaction();
if ($getStmt === false) {
    die("Prepare failed: " . $conn->error . "\nQuery: " . $getOrderQuery);
}
    // Get order details (including StudentID and InventoryID, Quantity, Status)
 $getOrderQuery = "
    SELECT o.InventoryID, o.Quantity, o.Status, o.StudentID, i.ItemName, o.FinalAmount
    FROM orders o
    JOIN inventory i ON o.InventoryID = i.InventoryID
    WHERE o.OrderID = ?
";
$getStmt = $conn->prepare($getOrderQuery);
if ($getStmt === false) {
    die("Prepare failed: " . $conn->error);
}
$getStmt->bind_param("i", $orderId);
$getStmt->execute();
$orderResult = $getStmt->get_result();
    
    if ($orderResult->num_rows === 0) {
        throw new Exception("Order not found");
    }
    
    $order = $orderResult->fetch_assoc();
    $inventoryID = isset($order['InventoryID']) ? $order['InventoryID'] : null;
    $quantity = isset($order['Quantity']) ? intval($order['Quantity']) : 0;
    $oldStatusNum = isset($order['Status']) ? intval($order['Status']) : 0;
    $studentID = isset($order['StudentID']) ? $order['StudentID'] : null;
    $itemName = isset($order['ItemName']) ? $order['ItemName'] : '';
    $finalAmount = isset($order['FinalAmount']) ? $order['FinalAmount'] : 0;
    $getStmt->close();
    
    // Status mapping
    $statusMap = [
        0 => 'Pending',
        1 => 'Pending Verification',
        2 => 'Paid',
        3 => 'Ready',
        4 => 'Completed',
        5 => 'Cancelled',
        6 => 'Rejected'
    ];
    
    // Get previous status text
    $previousStatusText = isset($statusMap[$oldStatusNum]) ? $statusMap[$oldStatusNum] : 'Unknown';
    
    if ($action === 'approve') {
        // Status 4 = Completed (archived) in your flow
        $newStatus = 4;  // Completed/Archived
        $newStatusText = 'Approved';
        
        // Update order status (don't delete)
        $updateQuery = "UPDATE orders SET Status = ?, ProcessedDate = NOW() WHERE OrderID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ii", $newStatus, $orderId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order status");
        }
        $updateStmt->close();
        
        // Deduct from inventory (ensure enough quantity)
        if ($inventoryID !== null) {
            $inventoryQuery = "UPDATE inventory SET Quantity = Quantity - ? WHERE InventoryID = ? AND Quantity >= ?";
            $invStmt = $conn->prepare($inventoryQuery);
            $invStmt->bind_param("iii", $quantity, $inventoryID, $quantity);
            
            if (!$invStmt->execute() || $invStmt->affected_rows === 0) {
                throw new Exception("Insufficient inventory or inventory update failed");
            }
            $invStmt->close();
        }
        
        // Insert into orderhistory
        $historyQuery = "INSERT INTO orderhistory (
            OrderID,
            ChangeDate, 
            ChangedBy, 
            NewStatus, 
            PreviousStatus,
            Notes
        ) VALUES (?, NOW(), ?, ?, ?, ?)";
        
        $historyStmt = $conn->prepare($historyQuery);
        if (!$historyStmt) {
            throw new Exception("History prepare failed: " . $conn->error);
        }
        
        $changedBy = "Admin";
        $notes = "Order approved";
        
        // bind_param types: i s s s s  -> but first param is orderId (int)
        $historyStmt->bind_param("issss", 
            $orderId,
            $changedBy,
            $newStatusText,
            $previousStatusText,
            $notes
        );
        
        if (!$historyStmt->execute()) {
            throw new Exception("Failed to create history record: " . $historyStmt->error);
        }
        
        $historyStmt->close();
        
        // Commit DB changes before sending email
        $conn->commit();
        
    } else if ($action === 'reject') {
        // Status 6 = Rejected
        $newStatus = 6;
        $newStatusText = 'Rejected';
        
        // Update order status with rejection reason (don't delete)
        $updateQuery = "UPDATE orders SET Status = ?, RejectionReason = ?, ProcessedDate = NOW() WHERE OrderID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("isi", $newStatus, $rejectionReason, $orderId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order status");
        }
        $updateStmt->close();
        
        // Insert into orderhistory
        $historyQuery = "INSERT INTO orderhistory (
            OrderID,
            ChangeDate, 
            ChangedBy, 
            NewStatus, 
            PreviousStatus,
            Notes
        ) VALUES (?, NOW(), ?, ?, ?, ?)";
        
        $historyStmt = $conn->prepare($historyQuery);
        if (!$historyStmt) {
            throw new Exception("History prepare failed: " . $conn->error);
        }
        
        $changedBy = "Admin";
        $notes = "Order rejected: " . $rejectionReason;
        
        $historyStmt->bind_param("issss", 
            $orderId,
            $changedBy,
            $newStatusText,
            $previousStatusText,
            $notes
        );
        
        if (!$historyStmt->execute()) {
            throw new Exception("Failed to create history record: " . $historyStmt->error);
        }
        
        $historyStmt->close();
        
        // Commit DB changes before sending email
        $conn->commit();
    }

    // At this point DB transaction committed successfully.
    // Look up student email (from studentusers table) to notify them.
    $studentEmail = null;
    if (!empty($studentID)) {
        $emailQuery = "SELECT Email FROM studentusers WHERE StudentID = ?";
        $emailStmt = $conn->prepare($emailQuery);
        $emailStmt->bind_param("s", $studentID);
        $emailStmt->execute();
        $emailRes = $emailStmt->get_result();
        if ($emailRes && $emailRes->num_rows > 0) {
            $row = $emailRes->fetch_assoc();
            $studentEmail = $row['Email'] ?? null;
        }
        $emailStmt->close();
    }

    // Prepare response base
    $response = [
        'status' => 'success',
        'message' => ($action === 'approve') ? 'Order approved, inventory updated, and moved to history' : 'Order rejected and moved to history',
        'orderId' => $orderId,
        'emailSent' => null,
        'emailError' => null
    ];

    // If we have an email address, send notification using PHPMailer
    if (!empty($studentEmail)) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();

            // SMTP config -- the recommended way is to use environment variables
            $smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $smtpUser = getenv('SMTP_USER') ?: 'unitracksti@gmail.com';
            $smtpPass = getenv('SMTP_PASS') ?: 'jybm ipxb hjvi tsdj'; // replace or set env
            $smtpPort = getenv('SMTP_PORT') ?: 587;

            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpPort;

            $mail->setFrom('unitracksti@gmail.com', 'UniTrack');
            $mail->addAddress($studentEmail);

            $mail->isHTML(true);

            if ($action === 'approve') {
                $mail->Subject = "Your UniTrack order #{$orderId} has been approved";
                $mail->Body = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
                      <h2 style='color:#4169E1;'>Order Approved</h2>
                      <p>Hi,</p>
                      <p>Your order <strong>#{$orderId}</strong> for <strong>" . htmlspecialchars($itemName) . "</strong> (Qty: {$quantity}) has been approved.</p>
                      <p>Total: <strong>₱" . number_format((float)$finalAmount,2) . "</strong></p>
                      <p>Please proceed to the proware for the pickup of your Uniform.</p>
                      <p>Thank you,<br>UniTrack</p>
                    </div>";
                $mail->AltBody = "Your order #{$orderId} has been approved. Total: ₱" . number_format((float)$finalAmount,2);
            } else {
                $mail->Subject = "Your UniTrack order #{$orderId} has been rejected";
                $mail->Body = "
                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>
                      <h2 style='color:#d9534f;'>Order Rejected</h2>
                      <p>Hi,</p>
                      <p>We're sorry to inform you that your order <strong>#{$orderId}</strong> for <strong>" . htmlspecialchars($itemName) . "</strong> has been rejected.</p>
                      <p><strong>Reason:</strong> " . htmlspecialchars($rejectionReason ?: 'Not specified') . "</p>
                      <p>If you need assistance, please proceed to the Proware.</p>
                      <p>Thank you,<br>UniTrack</p>
                    </div>";
                $mail->AltBody = "Your order #{$orderId} has been rejected. Reason: " . ($rejectionReason ?: 'Not specified');
            }

            $mail->send();
            $response['emailSent'] = true;
        } catch (Exception $e) {
            // Log error and continue — DB already committed
            error_log("Order Email Error (order {$orderId}): " . $mail->ErrorInfo);
            $response['emailSent'] = false;
            $response['emailError'] = $mail->ErrorInfo ?? $e->getMessage();
        }
    } else {
        // no email available
        $response['emailSent'] = false;
        $response['emailError'] = 'No student email on file';
    }

    echo json_encode($response);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>