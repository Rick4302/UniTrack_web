<?php
// get_inventory.php - Fetch all inventory items with optional filters

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

$course = isset($_GET['course']) ? trim($_GET['course']) : '';
$size = isset($_GET['size']) ? trim($_GET['size']) : '';

// Course mapping (display name => database code)
$courseMapping = [
    "ARTS AND SCIENCES" => "ARTS AND SCIENCES",
    "BUSINESS MANAGEMENT" => "BSBM",
    "HOSPITALITY MANAGEMENT" => "BSHM",
    "ICT & ENGINEERING" => "ICT & ENGINEERING",
    "JHS & SHS" => "JHS/SHS",
    "TOURISM MANAGEMENT" => "BSTM",
    "GENERAL UNIFORMS" => "GENERAL UNIFORMS"
];

$query = "SELECT InventoryID, ItemName, Course, Size, Quantity, Description, DateAdded FROM inventory WHERE 1=1";
$params = [];
$types = "";

if (!empty($course) && $course !== 'All') {
    if (isset($courseMapping[$course])) {
        $query .= " AND TRIM(Course) = ?";
        $params[] = $courseMapping[$course];
        $types .= "s";
    }
}

if (!empty($size) && $size !== 'All') {
    $query .= " AND TRIM(Size) = ?";
    $params[] = $size;
    $types .= "s";
}

$query .= " ORDER BY ItemName ASC";

$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$inventory = [];
$lowStockItems = [];
$LOW_STOCK_THRESHOLD = 10;

while ($row = $result->fetch_assoc()) {
    // Reverse map course code to display name
    $courseDisplay = $row['Course'];
    foreach ($courseMapping as $display => $code) {
        if ($code === $row['Course']) {
            $courseDisplay = $display;
            break;
        }
    }
    
    $item = [
        'inventoryId' => $row['InventoryID'],
        'itemName' => $row['ItemName'],
        'course' => $row['Course'],
        'courseDisplay' => $courseDisplay,
        'size' => $row['Size'],
        'quantity' => (int)$row['Quantity'],
        'description' => $row['Description'],
        'dateAdded' => $row['DateAdded']
    ];
    
    $inventory[] = $item;
    
    // Track low stock items
    if ($item['quantity'] <= $LOW_STOCK_THRESHOLD) {
        $lowStockItems[] = $item;
    }
}

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "data" => $inventory,
    "lowStockItems" => $lowStockItems,
    "lowStockThreshold" => $LOW_STOCK_THRESHOLD,
    "totalItems" => count($inventory)
]);
?>