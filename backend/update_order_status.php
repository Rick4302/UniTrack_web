<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
    
    if (!isset($statusMap[$statusText])) {
        throw new Exception("Invalid status: " . $statusText);
    }
    
    $statusNum = $statusMap[$statusText];
    
    // Update order status
    $query = "UPDATE orders SET Status = ?, ProcessedDate = NOW() WHERE OrderID = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $statusNum, $orderId);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("Order not found or status unchanged");
    }
    
    $stmt->close();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Order status updated to ' . $statusText,
        'orderId' => $orderId
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>