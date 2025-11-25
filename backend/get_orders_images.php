<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

// Get parameters
$orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : 0;
$imageType = isset($_GET['type']) ? trim($_GET['type']) : '';

// Log the request for debugging
error_log("Image request - OrderID: $orderId, Type: $imageType");

if (!$orderId || !$imageType) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    $query = "SELECT OrderID, GCashReceipt, DiscountIdImage FROM orders WHERE OrderID = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        error_log("Order not found: $orderId");
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Order not found']);
        exit;
    }
    
    $row = $result->fetch_assoc();
    $imageData = null;
    
    if ($imageType === 'gcash') {
        $imageData = $row['GCashReceipt'];
        error_log("GCash receipt - Is null: " . ($imageData === null ? 'yes' : 'no') . ", Length: " . (is_null($imageData) ? 0 : strlen($imageData)));
    } else if ($imageType === 'discount') {
        $imageData = $row['DiscountIdImage'];
        error_log("Discount image - Is null: " . ($imageData === null ? 'yes' : 'no') . ", Length: " . (is_null($imageData) ? 0 : strlen($imageData)));
    }
    
    if ($imageData === null || empty($imageData)) {
        error_log("Image data is empty for type: $imageType");
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Image not found or empty', 'type' => $imageType]);
        exit;
    }
    
    // Detect image type from the blob data
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($imageData);
    
    if (!$mimeType) {
        $mimeType = 'image/jpeg'; // Default fallback
    }
    
    error_log("Serving image - Type: $mimeType, Size: " . strlen($imageData) . " bytes");
    
    // Set appropriate headers
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($imageData));
    header('Cache-Control: max-age=3600');
    header('Content-Disposition: inline; filename="' . $imageType . '_' . $orderId . '.jpg"');
    
    // Output the image data
    echo $imageData;
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error in get_order_images.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>