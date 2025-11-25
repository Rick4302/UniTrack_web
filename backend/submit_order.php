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
    if (isset($_FILES['discountImage']) || isset($_POST['orderData'])) {
        $orderData = json_decode($_POST['orderData'], true);
        $discountImageData = null;
        
        if (isset($_FILES['discountImage']) && $_FILES['discountImage']['error'] === UPLOAD_ERR_OK) {
            $discountImageData = file_get_contents($_FILES['discountImage']['tmp_name']);
        }
    } else {
        $input = file_get_contents('php://input');
        $orderData = json_decode($input, true);
        $discountImageData = null;
    }
    
    if (!$orderData) {
        throw new Exception("Invalid order data");
    }
    
    $cartItems = $orderData['cartItems'] ?? [];
    $paymentType = $orderData['paymentType'] ?? 'cash';
    $studentID = trim($orderData['studentID'] ?? '');
    $finalAmount = floatval($orderData['finalAmount'] ?? 0);
    $discountAmount = floatval($orderData['discountAmount'] ?? 0);
    $hasDiscount = intval($orderData['hasDiscount'] ?? 0);
    
    // DEBUG: Log what we received
    error_log("Received StudentID: " . $studentID);
    error_log("StudentID type: " . gettype($studentID));
    error_log("StudentID length: " . strlen($studentID));
    
    if (empty($cartItems) || empty($studentID)) {
        throw new Exception("Invalid cart items or student ID. StudentID received: '" . $studentID . "'");
    }
    
    // Check if student exists - with better error message
    $checkStudent = $conn->prepare("SELECT StudentID FROM studentusers WHERE StudentID = ?");
    $checkStudent->bind_param("s", $studentID);
    $checkStudent->execute();
    $result = $checkStudent->get_result();
    
    if ($result->num_rows === 0) {
        // DEBUG: Show what students exist
        $allStudents = $conn->query("SELECT StudentID FROM studentusers");
        $studentList = [];
        while ($row = $allStudents->fetch_assoc()) {
            $studentList[] = $row['StudentID'];
        }
        
        throw new Exception("Student ID not found. Looking for: '" . $studentID . "'. Available students: " . implode(", ", $studentList));
    }
    $checkStudent->close();
    
    $conn->begin_transaction();
    
    $orderIDs = [];
    
    foreach ($cartItems as $item) {
        $inventoryID = intval($item['inventoryID'] ?? 0);
        $quantity = intval($item['quantity'] ?? 1);
        $totalAmount = floatval($item['price'] ?? 0) * $quantity;
        
        $itemDiscountAmount = ($discountAmount > 0) ? ($totalAmount / $finalAmount) * $discountAmount : 0;
        $itemFinalAmount = $totalAmount - $itemDiscountAmount;
        
        // Check inventory
        $checkQuery = "SELECT Quantity FROM inventory WHERE InventoryID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("i", $inventoryID);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Item not found in inventory");
        }
        
        $row = $result->fetch_assoc();
        if ($row['Quantity'] < $quantity) {
            throw new Exception("Insufficient stock for item");
        }
        $checkStmt->close();
        
        // Insert order
        $insertQuery = "
            INSERT INTO orders (
                StudentID, 
                InventoryID, 
                Quantity, 
                TotalAmount, 
                DiscountAmount, 
                FinalAmount, 
                PaymentType, 
                Status, 
                HasDiscount, 
                DiscountIdImage,
                OrderDate
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
        ";
        
        $stmt = $conn->prepare($insertQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "siidddsib",
            $studentID,
            $inventoryID,
            $quantity,
            $totalAmount,
            $itemDiscountAmount,
            $itemFinalAmount,
            $paymentType,
            $hasDiscount,
            $discountImageData
        );
        
        if ($discountImageData !== null) {
            $stmt->send_long_data(8, $discountImageData);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert order: " . $stmt->error);
        }
        
        $orderIDs[] = $conn->insert_id;
        $stmt->close();
        
        // Update inventory
        $updateQuery = "UPDATE inventory SET Quantity = Quantity - ? WHERE InventoryID = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ii", $quantity, $inventoryID);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update inventory");
        }
        $updateStmt->close();
    }
    
    $conn->commit();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Order(s) placed successfully',
        'orderID' => $orderIDs[0],
        'orderIDs' => $orderIDs
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

if (isset($conn)) {
    $conn->close();
}
?>