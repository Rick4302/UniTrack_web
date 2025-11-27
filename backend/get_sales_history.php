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
    // Query to get completed, ready, cancelled, and rejected orders
    $query = "
        SELECT 
            o.OrderID,
            o.StudentID,
            o.OrderDate,
            o.TotalAmount,
            o.DiscountAmount,
            o.FinalAmount,
            o.PaymentType,
            o.ProcessedDate,
            o.RejectionReason,
            o.GCashReceipt,
            o.DiscountIdImage,
            o.Quantity,
            CASE 
                WHEN o.Status = 0 THEN 'Pending'
                WHEN o.Status = 1 THEN 'Pending Verification'
                WHEN o.Status = 2 THEN 'Paid'
                WHEN o.Status = 3 THEN 'Ready'
                WHEN o.Status = 4 THEN 'Completed'
                WHEN o.Status = 5 THEN 'Cancelled'
                WHEN o.Status = 6 THEN 'Rejected'
                ELSE 'Unknown'
            END as Status,
            i.ItemName,
            i.Size,
            CASE 
                WHEN o.PaymentType = 'GCash' AND o.GCashReceipt IS NOT NULL THEN 1
                ELSE 0
            END as HasGCashReceipt,
            CASE 
                WHEN o.DiscountAmount > 0 THEN 1
                ELSE 0
            END as HasDiscount,
            CASE 
                WHEN o.DiscountAmount > 0 AND o.DiscountIdImage IS NOT NULL THEN 1
                ELSE 0
            END as HasDiscountImage
        FROM orders o
        LEFT JOIN inventory i ON o.InventoryID = i.InventoryID
        WHERE o.Status IN (2, 3, 4, 5, 6)
        ORDER BY o.ProcessedDate DESC, o.OrderID DESC
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        // Format the transaction data
        $transactions[] = [
            'OrderID' => intval($row['OrderID']),
            'StudentID' => $row['StudentID'],
            'StudentName' => 'Student #' . $row['StudentID'],
            'ItemName' => $row['ItemName'] ?? 'Unknown Item',
            'Size' => $row['Size'] ?? 'N/A',
            'Quantity' => intval($row['Quantity']),
            'TotalAmount' => floatval($row['TotalAmount']),
            'DiscountAmount' => floatval($row['DiscountAmount'] ?? 0),
            'FinalAmount' => floatval($row['FinalAmount']),
            'PaymentType' => $row['PaymentType'] ?? 'Cash',
            'Status' => $row['Status'],
            'OrderDate' => $row['OrderDate'],
            'ProcessedDate' => $row['ProcessedDate'],
            'RejectionReason' => $row['RejectionReason'],
            'HasDiscount' => intval($row['HasDiscount']),
            'HasGCashReceipt' => intval($row['HasGCashReceipt']),
            'HasDiscountImage' => intval($row['HasDiscountImage'])
        ];
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $transactions,
        'count' => count($transactions)
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