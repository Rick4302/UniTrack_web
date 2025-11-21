<?php
session_start();
header('Content-Type: application/json');

require_once '../db_connect.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['orderID']) || !isset($input['quantity'])) {
        throw new Exception("Missing required parameters");
    }
    
    $orderID = intval($input['orderID']);
    $quantity = intval($input['quantity']);
    
    // Get admin email from session
    $adminEmail = isset($_SESSION['admin_email']) ? $_SESSION['admin_email'] : 'Unknown';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Get order details
        $stmt = $conn->prepare("SELECT InventoryID, Status FROM orders WHERE OrderID = ?");
        $stmt->bind_param("i", $orderID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Order not found");
        }
        
        $order = $result->fetch_assoc();
        $inventoryID = $order['InventoryID'];
        $previousStatus = $order['Status'];
        $stmt->close();
        
        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET Status = 'Approved', 
                ProcessedBy = ?, 
                ProcessedDate = NOW()
            WHERE OrderID = ?
        ");
        $stmt->bind_param("si", $adminEmail, $orderID);
        $stmt->execute();
        $stmt->close();
        
        // Deduct from inventory
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET Quantity = Quantity - ? 
            WHERE InventoryID = ? AND Quantity >= ?
        ");
        $stmt->bind_param("iii", $quantity, $inventoryID, $quantity);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception("Insufficient inventory stock!");
        }
        $stmt->close();
        
        // Add to order history
        $notes = "Order approved by " . $adminEmail;
        $stmt = $conn->prepare("
            INSERT INTO orderhistory (OrderID, PreviousStatus, NewStatus, ChangedBy, ChangeDate, Notes)
            VALUES (?, ?, 'Approved', ?, NOW(), ?)
        ");
        $stmt->bind_param("isss", $orderID, $previousStatus, $adminEmail, $notes);
        $stmt->execute();
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Order approved successfully'
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