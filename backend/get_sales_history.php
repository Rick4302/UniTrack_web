<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

try {
    $statusMap = [
        0 => 'Pending',
        1 => 'Pending Verification',
        2 => 'Paid',
        3 => 'Ready',
        4 => 'Completed',
        5 => 'Cancelled',
        6 => 'Rejected'
    ];

    // Get & convert filters
    $dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : null;
    $dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;
    $course = isset($_GET['course']) ? $_GET['course'] : '';
    $paymentType = isset($_GET['paymentType']) ? $_GET['paymentType'] : '';

    function toMysqlDate($str) {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $str, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $str;
    }
    if ($dateFrom) $dateFrom = toMysqlDate($dateFrom);
    if ($dateTo)   $dateTo   = toMysqlDate($dateTo);

    // Build WHERE clause
    $where = [];
    $params = [];
    $types = '';

    // Only count histories marking as Approved, Paid, or Completed
    $where[] = "(LOWER(oh.NewStatus) = 'approved' OR LOWER(oh.NewStatus) = 'paid' OR LOWER(oh.NewStatus) = 'completed')";

    // Date filters use ChangeDate (not OrderDate!)
    if ($dateFrom) {
        $where[] = 'DATE(oh.ChangeDate) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo) {
        $where[] = 'DATE(oh.ChangeDate) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($course) {
        $where[] = 'o.Course = ?';
        $params[] = $course;
        $types .= 's';
    }
    if ($paymentType) {
        $where[] = 'o.PaymentType = ?';
        $params[] = $paymentType;
        $types .= 's';
    }

    $whereClause = '';
    if (count($where) > 0) {
        $whereClause = 'WHERE ' . implode(' AND ', $where);
    }

    $query = "
        SELECT 
            oh.HistoryID,
            oh.OrderID,
            oh.ChangeDate,
            oh.ChangedBy,
            oh.NewStatus,
            oh.PreviousStatus,
            oh.Notes,
            o.StudentID,
            o.Course,
            o.Quantity,
            o.OrderDate,
            o.TotalAmount,
            o.DiscountAmount,
            o.FinalAmount,
            o.PaymentType,
            o.HasDiscount,
            o.RejectionReason,
            (TRIM(IFNULL(o.GCashReceipt, '')) <> '') AS HasGCashReceipt,
            (TRIM(IFNULL(o.DiscountIdImage, '')) <> '') AS HasDiscountImage,
            COALESCE(i.ItemName, '') AS ItemName,
            COALESCE(i.Size, '') AS Size,
            o.Status AS StatusNumeric
        FROM orderhistory oh
        LEFT JOIN orders o ON oh.OrderID = o.OrderID
        LEFT JOIN inventory i ON o.InventoryID = i.InventoryID
        $whereClause
        ORDER BY oh.ChangeDate DESC
        LIMIT 1000
    ";

    error_log("Filters: dateFrom=$dateFrom, dateTo=$dateTo, course=$course, paymentType=$paymentType");
    error_log("SQL: $query");

    if (count($params) > 0) {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }
    }

    error_log("Rows returned: " . ($result ? $result->num_rows : 0));

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $newStatusRaw = trim((string)($row['NewStatus'] ?? ''));
        $statusNumeric = isset($row['StatusNumeric']) && $row['StatusNumeric'] !== '' ? intval($row['StatusNumeric']) : null;

        $canonical = 'Unknown';
        if ($statusNumeric !== null) {
            if (in_array($statusNumeric, [2, 4], true)) {
                $canonical = 'Approved';
            } elseif ($statusNumeric === 6) {
                $canonical = 'Rejected';
            } elseif ($statusNumeric === 0 || $statusNumeric === 1) {
                $canonical = 'Pending';
            } else {
                $canonical = $statusMap[$statusNumeric] ?? 'Unknown';
            }
        } else {
            $lower = strtolower($newStatusRaw);
            if ($lower === 'paid' || $lower === 'approved' || $lower === 'completed') {
                $canonical = 'Approved';
            } elseif ($lower === 'rejected' || $lower === 'cancelled' || $lower === 'canceled') {
                $canonical = 'Rejected';
            } elseif ($lower === 'pending' || $lower === 'pending verification') {
                $canonical = 'Pending';
            } else {
                $canonical = $newStatusRaw ?: 'Unknown';
            }
        }

        $transactions[] = [
            'HistoryID' => intval($row['HistoryID']),
            'OrderID' => $row['OrderID'] !== null ? intval($row['OrderID']) : null,
            'Status' => $canonical,
            'PreviousStatus' => $row['PreviousStatus'] ?? '',
            'ChangeDate' => $row['ChangeDate'],
            'OrderDate' => $row['OrderDate'],
            'StudentID' => $row['StudentID'] ?? '',
            'Course' => $row['Course'] ?? '',
            'ItemName' => $row['ItemName'] ?? '',
            'Size' => $row['Size'] ?? '',
            'Quantity' => isset($row['Quantity']) ? intval($row['Quantity']) : 0,
            'TotalAmount' => isset($row['TotalAmount']) ? floatval($row['TotalAmount']) : 0.0,
            'DiscountAmount' => isset($row['DiscountAmount']) ? floatval($row['DiscountAmount']) : 0.0,
            'FinalAmount' => isset($row['FinalAmount']) ? floatval($row['FinalAmount']) : 0.0,
            'PaymentType' => $row['PaymentType'] ?? '',
            'HasDiscount' => isset($row['HasDiscount']) ? intval($row['HasDiscount']) : 0,
            'HasGCashReceipt' => isset($row['HasGCashReceipt']) ? intval($row['HasGCashReceipt']) : 0,
            'HasDiscountImage' => isset($row['HasDiscountImage']) ? intval($row['HasDiscountImage']) : 0,
            'RejectionReason' => $row['RejectionReason'] ?? '',
            'Notes' => $row['Notes'] ?? ''
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $transactions,
        'count' => count($transactions)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
$conn->close();
?>