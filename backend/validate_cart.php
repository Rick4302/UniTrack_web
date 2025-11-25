<?php
// validate_cart.php - Validate cart items against inventory stock

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['cartItems']) || !is_array($input['cartItems'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid cart data"
    ]);
    exit;
}

$cartItems = $input['cartItems'];
$validationResults = [];
$allValid = true;

foreach ($cartItems as $item) {
    $itemName = isset($item['name']) ? trim($item['name']) : '';
    $sizeDescription = isset($item['description']) ? trim($item['description']) : '';
    $size = str_replace('Size: ', '', $sizeDescription);
    $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
    
    if (empty($itemName) || $quantity <= 0) {
        $validationResults[] = [
            'itemName' => $itemName,
            'size' => $size,
            'requested' => $quantity,
            'available' => 0,
            'valid' => false,
            'message' => 'Invalid cart item'
        ];
        $allValid = false;
        continue;
    }
    
    // Check if item has no size (like necktie)
    if (empty($size)) {
        // Query for items without specific size requirement
        // Sum all quantities for this item regardless of size
        $query = "SELECT SUM(Quantity) as totalQuantity FROM inventory 
                  WHERE TRIM(ItemName) = TRIM(?)";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $itemName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row && $row['totalQuantity'] > 0) {
            $availableQuantity = (int)$row['totalQuantity'];
            $isValid = $quantity <= $availableQuantity;
            
            $validationResults[] = [
                'itemName' => $itemName,
                'size' => 'N/A',
                'requested' => $quantity,
                'available' => $availableQuantity,
                'valid' => $isValid,
                'message' => $isValid ? 'Stock available' : "Only {$availableQuantity} available"
            ];
            
            if (!$isValid) {
                $allValid = false;
            }
        } else {
            $validationResults[] = [
                'itemName' => $itemName,
                'size' => 'N/A',
                'requested' => $quantity,
                'available' => 0,
                'valid' => false,
                'message' => 'Item not found in inventory'
            ];
            $allValid = false;
        }
        
        $stmt->close();
    } else {
        // Original logic for items with sizes
        $query = "SELECT Quantity FROM inventory 
                  WHERE TRIM(ItemName) = TRIM(?) 
                  AND TRIM(Size) = TRIM(?)
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ss', $itemName, $size);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            $availableQuantity = (int)$row['Quantity'];
            $isValid = $quantity <= $availableQuantity;
            
            $validationResults[] = [
                'itemName' => $itemName,
                'size' => $size,
                'requested' => $quantity,
                'available' => $availableQuantity,
                'valid' => $isValid,
                'message' => $isValid ? 'Stock available' : "Only {$availableQuantity} available"
            ];
            
            if (!$isValid) {
                $allValid = false;
            }
        } else {
            $validationResults[] = [
                'itemName' => $itemName,
                'size' => $size,
                'requested' => $quantity,
                'available' => 0,
                'valid' => false,
                'message' => 'Item not found in inventory'
            ];
            $allValid = false;
        }
        
        $stmt->close();
    }
}

$conn->close();

echo json_encode([
    "status" => "success",
    "allValid" => $allValid,
    "validationResults" => $validationResults
]);
?>