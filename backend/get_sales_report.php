<?php
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
    // Get filter parameters from query string
    $dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : null;
    $dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;
    $course = isset($_GET['course']) ? $_GET['course'] : null;
    $paymentType = isset($_GET['paymentType']) ? $_GET['paymentType'] : null;

    // Build the query - matching your exact table structure
    $query = "
        SELECT 
            oh.HistoryID,
            oh.OrderID,
            oh.ChangeDate,
            oh.NewStatus,
            oh.PreviousStatus,
            oh.ChangedBy,
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
            o.Status,
            COALESCE(i.ItemName, '') AS ItemName,
            COALESCE(i.Size, '') AS Size
        FROM orderhistory oh
        LEFT JOIN orders o ON oh.OrderID = o.OrderID
        LEFT JOIN inventory i ON o.InventoryID = i.InventoryID
        WHERE (
            LOWER(oh.NewStatus) = 'paid'
            OR LOWER(oh.NewStatus) = 'approved'
            OR LOWER(oh.NewStatus) = 'completed'
            OR oh.NewStatus = '2'
            OR oh.NewStatus = '4'
            OR o.Status IN (2, 4)
        )
    ";

    // Add date filters if provided - use ChangeDate from orderhistory
    if ($dateFrom && $dateTo) {
        $query .= " AND DATE(oh.ChangeDate) BETWEEN ? AND ?";
    } elseif ($dateFrom) {
        $query .= " AND DATE(oh.ChangeDate) >= ?";
    } elseif ($dateTo) {
        $query .= " AND DATE(oh.ChangeDate) <= ?";
    }

    // Add course filter if provided
    if ($course && $course !== '' && $course !== 'All Departments') {
        $query .= " AND o.Course = ?";
    }

    // Add payment type filter if provided
    if ($paymentType && $paymentType !== '' && $paymentType !== 'All Payments') {
        $query .= " AND o.PaymentType = ?";
    }

    $query .= " ORDER BY oh.ChangeDate DESC LIMIT 10000";

    // Prepare statement
    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }

    // Bind parameters dynamically
    $params = [];
    $types = '';

    // Add date parameters
    if ($dateFrom && $dateTo) {
        $params[] = $dateFrom;
        $params[] = $dateTo;
        $types .= 'ss';
    } elseif ($dateFrom) {
        $params[] = $dateFrom;
        $types .= 's';
    } elseif ($dateTo) {
        $params[] = $dateTo;
        $types .= 's';
    }

    // Add course parameter
    if ($course && $course !== '' && $course !== 'All Departments') {
        $params[] = $course;
        $types .= 's';
    }

    // Add payment type parameter
    if ($paymentType && $paymentType !== '' && $paymentType !== 'All Payments') {
        $params[] = $paymentType;
        $types .= 's';
    }

    // Bind parameters if any exist
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    // Execute query
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }

    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Failed to get result: " . $conn->error);
    }

    $sales = [];
    $rawSample = null;

    while ($row = $result->fetch_assoc()) {
        // Keep first row as sample for debugging
        if ($rawSample === null) {
            $rawSample = $row;
        }

        $sales[] = [
            'HistoryID' => intval($row['HistoryID']),
            'OrderID' => $row['OrderID'] !== null ? intval($row['OrderID']) : null,
            'StudentID' => $row['StudentID'] ?? '',
            'Course' => $row['Course'] ?? '',
            'ItemName' => $row['ItemName'] ?? '',
            'Size' => $row['Size'] ?? '',
            'Quantity' => isset($row['Quantity']) ? intval($row['Quantity']) : 0,
            'OrderDate' => $row['OrderDate'],
            'ChangeDate' => $row['ChangeDate'],
            'TotalAmount' => isset($row['TotalAmount']) ? floatval($row['TotalAmount']) : 0.0,
            'DiscountAmount' => isset($row['DiscountAmount']) ? floatval($row['DiscountAmount']) : 0.0,
            'FinalAmount' => isset($row['FinalAmount']) ? floatval($row['FinalAmount']) : 0.0,
            'PaymentType' => $row['PaymentType'] ?? '',
            'HasDiscount' => isset($row['HasDiscount']) ? intval($row['HasDiscount']) : 0,
            'NewStatus' => $row['NewStatus'] ?? '',
            'PreviousStatus' => $row['PreviousStatus'] ?? '',
            'ChangedBy' => $row['ChangedBy'] ?? '',
            'Notes' => $row['Notes'] ?? ''
        ];
    }

    $stmt->close();

    // Calculate summary statistics
    $totalRevenue = 0;
    $totalOrders = count($sales);
    $courseSales = [];

    foreach ($sales as $sale) {
        $totalRevenue += $sale['FinalAmount'];
        
        $courseName = $sale['Course'] ?: 'Unknown';
        if (!isset($courseSales[$courseName])) {
            $courseSales[$courseName] = 0;
        }
        $courseSales[$courseName] += $sale['FinalAmount'];
    }

    $avgOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

    // Find top course
    $topCourse = '';
    $topCourseRevenue = 0;
    foreach ($courseSales as $courseName => $revenue) {
        if ($revenue > $topCourseRevenue) {
            $topCourseRevenue = $revenue;
            $topCourse = $courseName;
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $sales,
        'count' => count($sales),
        'summary' => [
            'totalRevenue' => $totalRevenue,
            'totalOrders' => $totalOrders,
            'avgOrder' => $avgOrder,
            'topCourse' => $topCourse ?: '-',
            'topCourseRevenue' => $topCourseRevenue,
            'courseSales' => $courseSales
        ],
        'filters' => [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'course' => $course,
            'paymentType' => $paymentType
        ],
        'debug' => [
            'rowsReturned' => count($sales),
            'queryHadParams' => !empty($params),
            'filtersApplied' => [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'course' => $course,
                'paymentType' => $paymentType
            ],
            'sampleRow' => $rawSample
        ]
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