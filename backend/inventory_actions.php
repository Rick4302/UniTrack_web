<?php
// inventory_actions.php - Add, Update, Delete inventory items

header("Access-Control-Allow-Origin: http://localhost");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

$data = json_decode(file_get_contents("php://input"), true);
$action = $data['action'] ?? '';

$courseMapping = [
    "ARTS AND SCIENCES" => "ARTS AND SCIENCES",
    "BUSINESS MANAGEMENT" => "BSBM",
    "HOSPITALITY MANAGEMENT" => "BSHM",
    "ICT & ENGINEERING" => "ICT & ENGINEERING",
    "JHS & SHS" => "JHS/SHS",
    "TOURISM MANAGEMENT" => "BSTM",
    "GENERAL UNIFORMS" => "GENERAL UNIFORMS"
];

switch ($action) {
    case 'add':
        addItem($conn, $data, $courseMapping);
        break;
    case 'update':
        updateItem($conn, $data, $courseMapping);
        break;
    case 'delete':
        deleteItem($conn, $data);
        break;
    case 'get_single':
        getSingleItem($conn, $data);
        break;
    default:
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

function addItem($conn, $data, $courseMapping) {
    $itemName = trim($data['itemName'] ?? '');
    $course = $data['course'] ?? null;
    $size = $data['size'] ?? null;
    $quantity = (int)($data['quantity'] ?? 0);
    $description = trim($data['description'] ?? '');

    if (empty($itemName)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Item name is required"]);
        return;
    }

    // Convert display name to database code
    $courseCode = null;
    if (!empty($course) && isset($courseMapping[$course])) {
        $courseCode = $courseMapping[$course];
    }

    $stmt = $conn->prepare("INSERT INTO inventory (ItemName, Course, Size, Quantity, Description, DateAdded) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssis", $itemName, $courseCode, $size, $quantity, $description);

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        echo json_encode([
            "status" => "success",
            "message" => "Item added successfully",
            "inventoryId" => $newId,
            "lowStockWarning" => $quantity <= 10 && $quantity > 0,
            "outOfStock" => $quantity == 0
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to add item: " . $stmt->error]);
    }
    $stmt->close();
}

function updateItem($conn, $data, $courseMapping) {
    $inventoryId = isset($data['inventoryId']) ? intval($data['inventoryId']) : 0;
    $itemName = trim($data['itemName'] ?? '');
    $course = $data['course'] ?? null;
    $size = $data['size'] ?? null;
    $quantity = isset($data['quantity']) ? intval($data['quantity']) : 0;
    $description = trim($data['description'] ?? '');

    // Debug logging (remove in production)
    error_log("Update request - ID: $inventoryId, Name: $itemName");

    if ($inventoryId <= 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid inventory ID: " . $inventoryId]);
        return;
    }

    if (empty($itemName)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Item name is required"]);
        return;
    }

    // Convert display name to database code (or use as-is if already a code)
    $courseCode = null;
    if (!empty($course)) {
        if (isset($courseMapping[$course])) {
            $courseCode = $courseMapping[$course];
        } else {
            // Check if it's already a database code
            if (in_array($course, $courseMapping)) {
                $courseCode = $course;
            }
        }
    }

    $stmt = $conn->prepare("UPDATE inventory SET ItemName = ?, Course = ?, Size = ?, Quantity = ?, Description = ? WHERE InventoryID = ?");
    $stmt->bind_param("sssisi", $itemName, $courseCode, $size, $quantity, $description, $inventoryId);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "Item updated successfully",
            "lowStockWarning" => $quantity <= 10 && $quantity > 0,
            "outOfStock" => $quantity == 0
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to update item: " . $stmt->error]);
    }
    $stmt->close();
}

function deleteItem($conn, $data) {
    $inventoryId = (int)($data['inventoryId'] ?? 0);

    if ($inventoryId <= 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid inventory ID"]);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM inventory WHERE InventoryID = ?");
    $stmt->bind_param("i", $inventoryId);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Item deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to delete item: " . $stmt->error]);
    }
    $stmt->close();
}

function getSingleItem($conn, $data) {
    $inventoryId = (int)($data['inventoryId'] ?? 0);

    if ($inventoryId <= 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid inventory ID"]);
        return;
    }

    $stmt = $conn->prepare("SELECT * FROM inventory WHERE InventoryID = ?");
    $stmt->bind_param("i", $inventoryId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(["status" => "success", "data" => $row]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Item not found"]);
    }
    $stmt->close();
}

$conn->close();
?>