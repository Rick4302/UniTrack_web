<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $errstr,
        'details' => [
            'file' => basename($errfile),
            'line' => $errline
        ]
    ]);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Fatal error: ' . $error['message']
        ]);
    }
});

include 'db_connect.php';

try {
    // Validate required files
    if (!isset($_FILES['gcashReceipt'])) {
        throw new Exception("GCash receipt is required");
    }
    
    if ($_FILES['gcashReceipt']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error uploading GCash receipt");
    }
    
    // Get GCash receipt data
    $gcashReceiptData = file_get_contents($_FILES['gcashReceipt']['tmp_name']);
    
    // Get discount image if provided
    $discountImageData = null;
    if (isset($_FILES['discountImage']) && $_FILES['discountImage']['error'] === UPLOAD_ERR_OK) {
        $discountImageData = file_get_contents($_FILES['discountImage']['tmp_name']);
    }
    
    // Parse order data
    if (!isset($_POST['orderData'])) {
        throw new Exception("Missing orderData in POST request");
    }
    
    $orderData = json_decode($_POST['orderData'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON in orderData: " . json_last_error_msg());
    }
    
    if (!$orderData) {
        throw new Exception("Invalid order data");
    }
    
   $cartItems = $orderData['cartItems'] ?? [];
$studentID = trim($orderData['studentID'] ?? '');
$totalAmount = floatval($orderData['totalAmount'] ?? 0);
$finalAmount = floatval($orderData['finalAmount'] ?? 0);
$discountAmount = floatval($orderData['discountAmount'] ?? 0);
$hasDiscount = intval($orderData['hasDiscount'] ?? 0);
    

if ($totalAmount <= 0) {
    foreach ($cartItems as $item) {
        $itemPrice = floatval($item['price'] ?? 0);
        $itemQty = intval($item['quantity'] ?? 1);
        $totalAmount += ($itemPrice * $itemQty);
    }
}
    // Validate required fields
    if (empty($cartItems)) {
        throw new Exception("Cart is empty");
    }
    
    if (empty($studentID)) {
        throw new Exception("Student ID is required");
    }
    
    if ($finalAmount <= 0) {
        throw new Exception("Invalid final amount");
    }
    
    // Check if student exists and get their course
    $checkStudent = $conn->prepare("SELECT StudentID, Course FROM studentusers WHERE StudentID = ?");
    if (!$checkStudent) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $checkStudent->bind_param("s", $studentID);
    $checkStudent->execute();
    $result = $checkStudent->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Student ID not found: '" . $studentID . "'");
    }
    
    $studentData = $result->fetch_assoc();
    $studentCourse = $studentData['Course'];
    $checkStudent->close();
    
    $conn->begin_transaction();
    
    $orderIDs = [];
    
  foreach ($cartItems as $index => $item) {
    $inventoryID = intval($item['inventoryID'] ?? 0);
    $quantity = intval($item['quantity'] ?? 1);
    $itemTotalAmount = floatval($item['price'] ?? 0) * $quantity;
    
    if ($inventoryID <= 0) {
        throw new Exception("Invalid inventory ID for item at index $index");
    }
    
    if ($quantity <= 0) {
        throw new Exception("Invalid quantity for item at index $index");
    }
    
    // Calculate discount proportionally
    if ($discountAmount > 0 && $totalAmount > 0) {
        $itemProportion = $itemTotalAmount / $totalAmount;
        $itemDiscountAmount = round($itemProportion * $discountAmount, 2);
    } else {
        $itemDiscountAmount = 0;
    }
    
    $itemFinalAmount = $itemTotalAmount - $itemDiscountAmount;
    
   
        
        // Check inventory exists and has sufficient stock
        $checkQuery = "SELECT Quantity FROM inventory WHERE InventoryID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        if (!$checkStmt) {
            throw new Exception("Database prepare failed: " . $conn->error);
        }
        
        $checkStmt->bind_param("i", $inventoryID);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Item not found in inventory (ID: $inventoryID)");
        }
        
        $row = $result->fetch_assoc();
        if ($row['Quantity'] < $quantity) {
            throw new Exception("Insufficient stock for inventory ID: $inventoryID");
        }
        $checkStmt->close();
        
$insertQuery = "
    INSERT INTO orders (
        StudentID,
        Course,
        InventoryID, 
        Quantity, 
        TotalAmount, 
        DiscountAmount, 
        FinalAmount, 
        PaymentType, 
        Status, 
        HasDiscount, 
        DiscountIdImage,
        GCashReceipt,
        OrderDate
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'gcash', 1, ?, ?, ?, NOW())
";

$stmt = $conn->prepare($insertQuery);
if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
}

// Bind only the non-blob parameters (8 parameters)

// Bind ALL 10 parameters including blobs as 'b' type
$stmt->bind_param(
    "ssiidddiss",  // 10 parameters: s,s,i,i,d,d,d,i,s,s (treating blobs as strings)
    $studentID,
    $studentCourse,
    $inventoryID,
    $quantity,
    $totalAmount,
    $itemDiscountAmount,
    $itemFinalAmount,
    $hasDiscount,
    $discountImageData,
    $gcashReceiptData
);
        if ($discountImageData !== null) {
    $stmt->send_long_data(8, $discountImageData);  // 9th parameter (DiscountIdImage)
}
$stmt->send_long_data(9, $gcashReceiptData);
        if ($gcashReceiptData !== null) {
            $stmt->send_long_data(9, $gcashReceiptData);
        }
        
        if (!$stmt->execute()) {
    throw new Exception("Failed to insert order: " . $stmt->error);
}
        
        $orderIDs[] = $conn->insert_id;
        $stmt->close();
    }
    
    $conn->commit();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'GCash order(s) placed successfully and pending verification',
        'orderID' => $orderIDs[0],
        'orderIDs' => $orderIDs,
        'course' => $studentCourse
    ]);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}