<?php
session_start();
header('Content-Type: application/json');

require_once '../db_connect.php';

try {
    $query = "
        SELECT 
            o.OrderID,
            o.StudentID,
            IFNULL(su.Email, 'Unknown') as StudentName,
            i.Course,
            i.ItemName,
            i.Size,
            o.Quantity,
            o.TotalAmount,
            o.PaymentType,
            o.OrderDate,
            o.GCashReceipt IS NOT NULL as HasReceiptImage,
            IFNULL(o.HasDiscount, 0) as HasDiscount,
            o.DiscountIdImage IS NOT NULL as HasDiscountIdImage,
            IFNULL(o.DiscountAmount, 0) as DiscountAmount,
            IFNULL(o.FinalAmount, o.TotalAmount) as FinalAmount,
            o.InventoryID
        FROM orders o
        LEFT JOIN studentusers su ON o.StudentID = su.StudentID
        LEFT JOIN inventory i ON o.InventoryID = i.InventoryID
        WHERE o.Status IN ('Pending', 'Pending Verification')
        ORDER BY o.OrderDate DESC
    ";
    
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $orders = [];
    
    while ($row = $result->fetch_assoc()) {
        // Add flags for frontend
        $row['GCashReceipt'] = $row['HasReceiptImage'] ? true : null;
        $row['DiscountIdImage'] = $row['HasDiscountIdImage'] ? true : null;
        
        unset($row['HasReceiptImage']);
        unset($row['HasDiscountIdImage']);
        
        $orders[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'orders' => $orders,
        'count' => count($orders)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>