<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['orderId']) || !isset($input['status'])) {
        throw new Exception("Missing orderId or status");
    }
    
    $orderId = intval($input['orderId']);
    $statusText = trim($input['status']);
    
    // Map text status to numeric
    $statusMap = [
        'Pending' => 0,
        'Pending Verification' => 1,
        'Paid' => 2,
        'Ready' => 3,
        'Completed' => 4,
        'Cancelled' => 5
    ];
    
    // Reverse map for numeric to text
    $reverseStatusMap = array_flip($statusMap);
    
    if (!isset($statusMap[$statusText])) {
        throw new Exception("Invalid status: " . $statusText);
    }
    
    $statusNum = $statusMap[$statusText];
    
    // Start transaction
    $conn->begin_transaction();
    
    // Get order details before updating
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
    
    // Update order status
    $updateQuery = "UPDATE orders SET Status = ?, ProcessedDate = NOW() WHERE OrderID = ?";
    $updateStmt = $conn->prepare($updateQuery);
    
    if (!$updateStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $updateStmt->bind_param("ii", $statusNum, $orderId);
    
    if (!$updateStmt->execute()) {
        throw new Exception("Execute failed: " . $updateStmt->error);
    }
    
    if ($updateStmt->affected_rows === 0) {
        throw new Exception("Order not found or status unchanged");
    }
    
    $updateStmt->close();
    
    // ✅ UPDATE INVENTORY based on status change
    $approvedStatuses = [2, 3, 4]; // Paid, Ready, Completed
    $cancelledStatus = 5;
    
    $wasApproved = in_array($oldStatus, $approvedStatuses);
    $nowApproved = in_array($statusNum, $approvedStatuses);
    $nowCancelled = ($statusNum === $cancelledStatus);
    
    if ($nowApproved && !$wasApproved) {
        // APPROVE: Reduce inventory
        $inventoryQuery = "UPDATE inventory SET Quantity = Quantity - ? WHERE InventoryID = ?";
        $invStmt = $conn->prepare($inventoryQuery);
        $invStmt->bind_param("ii", $quantity, $inventoryID);
        
        if (!$invStmt->execute()) {
            throw new Exception("Failed to update inventory: " . $invStmt->error);
        }
        
        // Check if inventory went negative
        $checkQuery = "SELECT Quantity FROM inventory WHERE InventoryID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("i", $inventoryID);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $currentQty = $checkResult->fetch_assoc()['Quantity'];
        $checkStmt->close();
        
        if ($currentQty < 0) {
            throw new Exception("Insufficient inventory to approve order");
        }
        
        $invStmt->close();
        
    } elseif ($nowCancelled && $wasApproved) {
        // CANCEL (from approved): Restore inventory
        $inventoryQuery = "UPDATE inventory SET Quantity = Quantity + ? WHERE InventoryID = ?";
        $invStmt = $conn->prepare($inventoryQuery);
        $invStmt->bind_param("ii", $quantity, $inventoryID);
        
        if (!$invStmt->execute()) {
            throw new Exception("Failed to restore inventory: " . $invStmt->error);
        }
        
        $invStmt->close();
    }
    
    // ✅ MOVE TO ORDERHISTORY if approved or cancelled
    $shouldMoveToHistory = in_array($statusNum, [2, 3, 4, 5]); // Paid, Ready, Completed, or Cancelled
    
    if ($shouldMoveToHistory) {
        // Insert into orderhistory
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
        
        // You can get ChangedBy from session or input if needed
        $changedBy = isset($input['changedBy']) ? $input['changedBy'] : "Admin";
        
        $notes = "Order " . ($statusNum === 5 ? "cancelled" : "approved as " . $statusText);
        
        // Convert old status number to text
        $previousStatusText = isset($reverseStatusMap[$oldStatus]) ? $reverseStatusMap[$oldStatus] : "Unknown";
        
        $historyStmt->bind_param("sssis", 
            $changedBy,
            $statusText,  // NewStatus as text
            $notes,
            $orderId,  // OrderID as integer
            $previousStatusText  // PreviousStatus as text
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
    }
    
    $conn->commit();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Order status updated to ' . $statusText . ($shouldMoveToHistory ? ' and moved to history' : ''),
        'orderId' => $orderId,
        'inventoryUpdated' => ($nowApproved && !$wasApproved) || ($nowCancelled && $wasApproved),
        'movedToHistory' => $shouldMoveToHistory
    ]);
    
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