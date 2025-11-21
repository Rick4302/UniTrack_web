<?php
session_start();
header('Content-Type: application/json');

require_once '../db_connect.php';

try {
    if (!isset($_GET['orderID'])) {
        throw new Exception("Order ID is required");
    }
    
    $orderID = intval($_GET['orderID']);
    
    $stmt = $conn->prepare("
        SELECT 
            DiscountIdImage, 
            HasDiscount, 
            DiscountAmount, 
            IFNULL(FinalAmount, TotalAmount) as FinalAmount 
        FROM orders 
        WHERE OrderID = ?
    ");
    $stmt->bind_param("i", $orderID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Order not found");
    }
    
    $row = $result->fetch_assoc();
    
    if ($row['DiscountIdImage'] === null) {
        throw new Exception("No discount ID attached for this order");
    }
    
    // Convert BLOB to base64
    $imageData = base64_encode($row['DiscountIdImage']);
    $imageSrc = 'data:image/jpeg;base64,' . $imageData;
    
    echo json_encode([
        'status' => 'success',
        'image' => $imageSrc,
        'hasDiscount' => $row['HasDiscount'] == 1,
        'discountAmount' => $row['DiscountAmount'],
        'finalAmount' => $row['FinalAmount']
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>