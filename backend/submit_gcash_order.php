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
    // Get order data from request
    $orderDataJson = $_POST['orderData'] ?? null;
    if (!$orderDataJson) {
        throw new Exception("No order data provided");
    }

    $orderData = json_decode($orderDataJson, true);
    if (!$orderData) {
        throw new Exception("Invalid order data format");
    }

    // Validate required fields
    if (empty($orderData['cartItems']) || empty($orderData['studentID'])) {
        throw new Exception("Missing required order information");
    }

    // Handle GCash receipt file
    $gcashReceiptBlob = null;
    if (isset($_FILES['gcashReceipt']) && $_FILES['gcashReceipt']['error'] === UPLOAD_ERR_OK) {
        $receiptFile = $_FILES['gcashReceipt'];
        
        // Validate file
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $receiptFile['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception("Invalid file type. Only JPG, PNG, and PDF are allowed");
        }
        
        if ($receiptFile['size'] > 5 * 1024 * 1024) {
            throw new Exception("File size exceeds 5MB limit");
        }
        
        $gcashReceiptBlob = file_get_contents($receiptFile['tmp_name']);
    } else {
        throw new Exception("GCash receipt is required");
    }

    // Start transaction
    $conn->begin_transaction();

    // Create order record
    $studentID = $orderData['studentID'];
    $finalAmount = floatval($orderData['finalAmount']);
    $discountAmount = floatval($orderData['discountAmount'] ?? 0);
    $totalAmount = $finalAmount + $discountAmount;
    $hasDiscount = intval($orderData['hasDiscount'] ?? 0);
    
    // Store as lowercase string for consistency
    $paymentType = 'gcash';
    $status = 0; // Pending status
    
    $orderQuery = "
        INSERT INTO orders (
            StudentID, 
            TotalAmount, 
            DiscountAmount, 
            FinalAmount, 
            PaymentType, 
            Status, 
            OrderDate,
            GCashReceipt,
            HasDiscount
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ";
    
    $stmt = $conn->prepare($orderQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
 $null = null;

// Bind params
$stmt->bind_param(
    "sdddsibi",
    $studentID,
    $totalAmount,
    $discountAmount,
    $finalAmount,
    $paymentType,
    $status,
    $null,          // temporary for blob
    $hasDiscount
);

// Send BLOB
$stmt->send_long_data(6, $gcashReceiptBlob);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create order: " . $stmt->error);
    }
    
    $orderID = $stmt->insert_id;
    $stmt->close();
    
    // Handle discount image if provided
    if ($hasDiscount && isset($_FILES['discountImage']) && $_FILES['discountImage']['error'] === UPLOAD_ERR_OK) {
    $discountFile = $_FILES['discountImage'];
    $discountBlob = file_get_contents($discountFile['tmp_name']);
    
    $discountQuery = "UPDATE orders SET DiscountIdImage = ? WHERE OrderID = ?";
    $discountStmt = $conn->prepare($discountQuery);
    if ($discountStmt) {
        $null = null;
        $discountStmt->bind_param("bi", $null, $orderID);
        $discountStmt->send_long_data(0, $discountBlob);
        $discountStmt->execute();
        $discountStmt->close();
    }
}
    
    // Add cart items to order
    foreach ($orderData['cartItems'] as $item) {
        // Get inventory ID
        $inventoryQuery = "SELECT InventoryID FROM inventory WHERE ItemName = ? LIMIT 1";
        $invStmt = $conn->prepare($inventoryQuery);
        if (!$invStmt) {
            throw new Exception("Inventory query failed: " . $conn->error);
        }
        
        $invStmt->bind_param("s", $item['name']);
        $invStmt->execute();
        $invResult = $invStmt->get_result();
        
        if ($invResult->num_rows === 0) {
            throw new Exception("Item not found: " . $item['name']);
        }
        
        $invRow = $invResult->fetch_assoc();
        $inventoryID = $invRow['InventoryID'];
        $invStmt->close();
        
        // Update order with inventory and quantity
        $updateOrderQuery = "UPDATE orders SET InventoryID = ?, Quantity = ? WHERE OrderID = ?";
        $updateStmt = $conn->prepare($updateOrderQuery);
        $quantity = intval($item['quantity']);
        $updateStmt->bind_param("iii", $inventoryID, $quantity, $orderID);
        $updateStmt->execute();
        $updateStmt->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Log successful order creation
    error_log("GCash order created - OrderID: $orderID, StudentID: $studentID, Amount: $finalAmount");
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Order submitted successfully. Payment verification pending.',
        'orderID' => $orderID,
        'paymentStatus' => 'Pending Verification'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
    }
    
    error_log("GCash order error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>