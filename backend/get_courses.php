<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
include 'db_connect.php';

$result = $conn->query("SELECT DISTINCT Course FROM orders WHERE Course IS NOT NULL AND Course != '' ORDER BY Course ASC");
$courses = [];
while($row = $result->fetch_assoc()){
    $courses[] = $row['Course'];
}
echo json_encode([
    'status' => 'success',
    'courses' => $courses
]);
$conn->close();
?>