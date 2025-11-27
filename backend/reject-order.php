<?php
include 'config.php';
header('Content-Type: application/json');

$response = [];

try {
    if (!isset($_POST['orderID'])) {
        throw new Exception("Missing orderID");
    }

    $orderID = intval($_POST['orderID']);
    $newStatus = 6; // Rejected

    $statusText = [
        0 => "Pending",
        1 => "Pending Verification",
        2 => "Ready",
        3 => "Paid",
        4 => "Approved",
        5 => "Completed",
        6 => "Rejected"
    ][$newStatus];

    // Update order
    $stmt = $con->prepare("UPDATE orders SET status = ? WHERE orderID = ?");
    $stmt->bind_param("ii", $newStatus, $orderID);
    $stmt->execute();

    // Insert history record
    $hist = $con->prepare("
        INSERT INTO orderhistory(orderID, OrderAt, StudentID, UniformSetName, NewStatus)
        SELECT orderID, OrderAt, StudentID, UniformSetName, ?
        FROM orders WHERE orderID = ?
    ");
    $hist->bind_param("si", $statusText, $orderID);
    $hist->execute();

    $response["success"] = true;
    $response["message"] = "Order rejected.";

} catch (Exception $e) {
    $response["success"] = false;
    $response["message"] = $e->getMessage();
}

echo json_encode($response);
?>
