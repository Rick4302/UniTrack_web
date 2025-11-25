<?php
// get_available_sizes.php - Fetch available sizes for a specific item

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

$itemName = isset($_GET['itemName']) ? trim($_GET['itemName']) : '';
$course = isset($_GET['course']) ? trim($_GET['course']) : '';
$checkStockOnly = isset($_GET['checkStockOnly']) ? $_GET['checkStockOnly'] === 'true' : false;

// Debug logging
error_log("ItemName received: '$itemName'");
error_log("Course received: '$course'");
error_log("CheckStockOnly: " . ($checkStockOnly ? 'true' : 'false'));

if (empty($itemName)) {
    echo json_encode([
        "status" => "error",
        "message" => "Item name is required"
    ]);
    exit;
}

// If only checking stock (for items without sizes like necktie)
if ($checkStockOnly) {
    // Get the inventoryID and stock for items without size variation
    $query = "SELECT InventoryID, Quantity FROM inventory 
              WHERE ItemName LIKE ? AND Quantity > 0";
    
    $params = ['%' . $itemName . '%'];
    $types = "s";
    
    if (!empty($course)) {
        $query .= " AND Course = ?";
        $params[] = $course;
        $types .= "s";
    }
    
    $query .= " LIMIT 1"; // Get first available item
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        echo json_encode([
            "status" => "success",
            "hasStock" => true,
            "inventoryID" => intval($row['InventoryID']),  // ← CRITICAL: Added this
            "quantity" => intval($row['Quantity']),
            "itemName" => $itemName
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "hasStock" => false,
            "itemName" => $itemName
        ]);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

// Original code for getting sizes - UPDATED to include InventoryID
$query = "SELECT InventoryID, Size, Quantity FROM inventory 
          WHERE ItemName LIKE ? 
          AND Quantity > 0";

$params = ['%' . $itemName . '%'];
$types = "s";

// If course is provided, add it to the filter
if (!empty($course)) {
    $query .= " AND Course = ?";
    $params[] = $course;
    $types .= "s";
}

// Order sizes properly
$query .= " ORDER BY FIELD(Size, 'S', 'M', 'L', 'XL', '2XL', '3XL')";

$stmt = $conn->prepare($query);

if (!$stmt) {
    error_log("Query prepare error: " . $conn->error);
    echo json_encode([
        "status" => "error",
        "message" => "Database query error",
        "debug" => $conn->error
    ]);
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();

if ($stmt->error) {
    error_log("Query execute error: " . $stmt->error);
    echo json_encode([
        "status" => "error",
        "message" => "Database execution error",
        "debug" => $stmt->error
    ]);
    exit;
}

$result = $stmt->get_result();
$availableSizes = [];

while ($row = $result->fetch_assoc()) {
    $availableSizes[] = [
        'inventoryID' => intval($row['InventoryID']),  // ← CRITICAL: Added this
        'size' => $row['Size'],
        'quantity' => intval($row['Quantity'])
    ];
}

error_log("Found " . count($availableSizes) . " sizes");

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "itemName" => $itemName,
    "course" => $course,
    "availableSizes" => $availableSizes,
    "totalSizes" => count($availableSizes)
]);
?>