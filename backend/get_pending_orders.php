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
    // Query to get pending orders with student and inventory info
    $query = "
        SELECT 
            o.OrderID,
            o.StudentID,
            o.OrderDate,
            o.TotalAmount,
            o.DiscountAmount,
            o.FinalAmount,
            o.PaymentType,
            o.Status,
            o.Quantity,
            o.HasDiscount,
            o.GCashReceipt,
            o.DiscountIdImage,
            i.ItemName,
            i.Size
        FROM orders o
        LEFT JOIN inventory i ON o.InventoryID = i.InventoryID
        WHERE o.Status IN (0, 1)
        ORDER BY o.OrderDate DESC
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $orders = [];
    
    // Status mapping
    $statusMap = [
        0 => 'Pending',
        1 => 'Pending Verification',
        2 => 'Paid',
        3 => 'Ready',
        4 => 'Completed',
        5 => 'Cancelled'
    ];
    
    while ($row = $result->fetch_assoc()) {
        // Convert numeric status to string
        $statusNum = intval($row['Status']);
        $statusText = isset($statusMap[$statusNum]) ? $statusMap[$statusNum] : 'Unknown';
        
        // Properly check BLOB data existence
        $hasGCashReceipt = ($row['GCashReceipt'] !== null && strlen($row['GCashReceipt']) > 0);
        $hasDiscountImage = ($row['DiscountIdImage'] !== null && strlen($row['DiscountIdImage']) > 0);
        
        // Log for debugging
        error_log("Order {$row['OrderID']}: GCash=" . ($hasGCashReceipt ? 'YES' : 'NO') . 
                  ", Discount=" . ($hasDiscountImage ? 'YES' : 'NO'));
        
        // Format the order data
        $orders[] = [
            'OrderID' => intval($row['OrderID']),
            'StudentID' => $row['StudentID'],
            'StudentName' => 'Student #' . $row['StudentID'],
            'ItemName' => $row['ItemName'] ?? 'Unknown Item',
            'Size' => $row['Size'] ?? 'N/A',
            'Quantity' => intval($row['Quantity']),
            'TotalAmount' => floatval($row['TotalAmount']),
            'DiscountAmount' => floatval($row['DiscountAmount'] ?? 0),
            'FinalAmount' => floatval($row['FinalAmount']),
            'PaymentType' => $row['PaymentType'] ?? 'cash',
            'Status' => $statusText,
            'OrderDate' => $row['OrderDate'],
            'HasDiscount' => intval($row['HasDiscount']),
            'HasGCashReceipt' => $hasGCashReceipt,
            'HasDiscountImage' => $hasDiscountImage
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $orders,
        'count' => count($orders)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>