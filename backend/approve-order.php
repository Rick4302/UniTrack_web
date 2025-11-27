<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

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
    
    // Get order details
    $getOrderQuery = "SELECT InventoryID, Quantity, Status FROM orders WHERE OrderID = ?";
    $getStmt = $conn->prepare($getOrderQuery);
    $getStmt->bind_param("i", $orderId);
    $getStmt->execute();
    $orderResult = $getStmt->get_result();
    
    if ($orderResult->num_rows === 0) {
        throw new Exception("Order not found");
    }
    
    $order = $orderResult->fetch_assoc();
    $inventoryID = $order['InventoryID'];
    $quantity = $order['Quantity'];
    $oldStatus = $order['Status'];
    $getStmt->close();
    
    // Status mapping
    $statusMap = [
        'Pending' => 0,
        'Pending Verification' => 1,
        'Paid' => 2,
        'Ready' => 3,
        'Completed' => 4,
        'Cancelled' => 5,
        'Rejected' => 6
    ];
    
    $reverseStatusMap = array_flip($statusMap);
    
    if ($action === 'approve') {
        // Status 2 = Paid/Approved
        $newStatus = 2;
        $statusText = 'Paid';
        
        // Update order status
        $updateQuery = "UPDATE orders SET Status = ?, ProcessedDate = NOW() WHERE OrderID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ii", $newStatus, $orderId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order status");
        }
        $updateStmt->close();
        
        // Deduct from inventory
        $inventoryQuery = "UPDATE inventory SET Quantity = Quantity - ? WHERE InventoryID = ? AND Quantity >= ?";
        $invStmt = $conn->prepare($inventoryQuery);
        $invStmt->bind_param("iii", $quantity, $inventoryID, $quantity);
        
        if (!$invStmt->execute() || $invStmt->affected_rows === 0) {
            throw new Exception("Insufficient inventory or inventory update failed");
        }
        $invStmt->close();
        
        // Move to orderhistory
        $historyQuery = "INSERT INTO orderhistory (
            ChangeDate, 
            ChangedBy, 
            NewStatus, 
            Notes, 
            OrderID, 
            PreviousStatus
        ) VALUES (NOW(), ?, ?, ?, ?, ?)";
        
        $historyStmt = $conn->prepare($historyQuery);
        
        if (!$historyStmt) {
            throw new Exception("History prepare failed: " . $conn->error);
        }
        
        $changedBy = "Admin";
        $notes = "Order approved";
        $previousStatusText = isset($reverseStatusMap[$oldStatus]) ? $reverseStatusMap[$oldStatus] : "Unknown";
        
        $historyStmt->bind_param("sssis", 
            $changedBy,
            $statusText,
            $notes,
            $orderId,
            $previousStatusText
        );
        
        if (!$historyStmt->execute()) {
            throw new Exception("Failed to create history record: " . $historyStmt->error);
        }
        
        $historyStmt->close();
        
        // Delete from orders table
        $deleteQuery = "DELETE FROM orders WHERE OrderID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        
        if (!$deleteStmt) {
            throw new Exception("Delete prepare failed: " . $conn->error);
        }
        
        $deleteStmt->bind_param("i", $orderId);
        
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete order: " . $deleteStmt->error);
        }
        
        $deleteStmt->close();
        
        $conn->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Order approved, inventory updated, and moved to history',
            'orderId' => $orderId
        ]);
        
    } else if ($action === 'reject') {
        // Status 6 = Rejected
        $newStatus = 6;
        $statusText = 'Rejected';
        
        // Update order status with rejection reason
        $updateQuery = "UPDATE orders SET Status = ?, RejectionReason = ?, ProcessedDate = NOW() WHERE OrderID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("isi", $newStatus, $rejectionReason, $orderId);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update order status");
        }
        $updateStmt->close();
        
        // Move to orderhistory
        $historyQuery = "INSERT INTO orderhistory (
            ChangeDate, 
            ChangedBy, 
            NewStatus, 
            Notes, 
            OrderID, 
            PreviousStatus
        ) VALUES (NOW(), ?, ?, ?, ?, ?)";
        
        $historyStmt = $conn->prepare($historyQuery);
        
        if (!$historyStmt) {
            throw new Exception("History prepare failed: " . $conn->error);
        }
        
        $changedBy = "Admin";
        $notes = "Order rejected: " . $rejectionReason;
        $previousStatusText = isset($reverseStatusMap[$oldStatus]) ? $reverseStatusMap[$oldStatus] : "Unknown";
        
        $historyStmt->bind_param("sssis", 
            $changedBy,
            $statusText,
            $notes,
            $orderId,
            $previousStatusText
        );
        
        if (!$historyStmt->execute()) {
            throw new Exception("Failed to create history record: " . $historyStmt->error);
        }
        
        $historyStmt->close();
        
        // Delete from orders table
        $deleteQuery = "DELETE FROM orders WHERE OrderID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        
        if (!$deleteStmt) {
            throw new Exception("Delete prepare failed: " . $conn->error);
        }
        
        $deleteStmt->bind_param("i", $orderId);
        
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete order: " . $deleteStmt->error);
        }
        
        $deleteStmt->close();
        
        $conn->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Order rejected and moved to history',
            'orderId' => $orderId
        ]);
    }
    
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