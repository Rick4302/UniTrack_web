<?php
session_start();
header('Content-Type: application/json');

require_once '../db_connect.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['orderID']) || !isset($input['reason'])) {
        throw new Exception("Missing required parameters");
    }
    
    $orderID = intval($input['orderID']);
    $reason = trim($input['reason']);
    
    if (empty($reason)) {
        throw new Exception("Rejection reason is required");
    }
    
    // Get admin email from session
    $adminEmail = isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : 'Unknown';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get previous status
        $stmt = $conn->prepare("SELECT Status FROM orders WHERE OrderID = ?");
        $stmt->bind_param("i", $orderID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Order not found");
        }
        
        $order = $result->fetch_assoc();
        $previousStatus = $order['Status'];
        $stmt->close();
        
        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET Status = 'Rejected', 
                ProcessedBy = ?, 
                ProcessedDate = NOW(),
                RejectionReason = ?
            WHERE OrderID = ?
        ");
        $stmt->bind_param("ssi", $adminEmail, $reason, $orderID);
        $stmt->execute();
        $stmt->close();
        
        // Add to order history
        $notes = "Rejected: " . $reason;
        $stmt = $conn->prepare("
            INSERT INTO orderhistory (OrderID, PreviousStatus, NewStatus, ChangedBy, ChangeDate, Notes)
            VALUES (?, ?, 'Rejected', ?, NOW(), ?)
        ");
        $stmt->bind_param("isss", $orderID, $previousStatus, $adminEmail, $notes);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Order rejected successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>