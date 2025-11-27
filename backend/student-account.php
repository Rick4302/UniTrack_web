<?php
session_start();

// Include database connection
require_once 'db_connect.php';

$response = array();

// Handle different actions
$action = $_POST['action'] ?? '';
$studentEmail = $_SESSION['email'] ?? '';

// Debug: Log the incoming data
error_log("Action: " . $action);
error_log("Email: " . $studentEmail);

if (empty($action)) {
    echo json_encode([
        "success" => false,
        "message" => "No action specified"
    ]);
    exit();
}

if (!isset($_SESSION['email']) || empty($_SESSION['email'])) {
    echo json_encode([
        "success" => false,
        "message" => "Not logged in. Session missing."
    ]);
    exit();
}

if ($action === 'loadUserInfo') {
    // Fetch student information
    try {
        error_log("Attempting to load student: " . $studentEmail);
        
        $query = "SELECT StudentID, Email FROM studentusers WHERE Email = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $response['success'] = false;
            $response['message'] = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param("s", $studentEmail);
            
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                $response['success'] = false;
                $response['message'] = "Execute failed: " . $stmt->error;
            } else {
                $result = $stmt->get_result();
                error_log("Query executed. Rows found: " . $result->num_rows);
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    error_log("Student found: " . json_encode($row));
                    $response['success'] = true;
                    $response['studentId'] = $row['StudentID'];
                    $response['email'] = $row['Email'];
                } else {
                    error_log("No student found with email: " . $studentEmail);
                    $response['success'] = false;
                    $response['message'] = "Student information not found for: " . $studentEmail;
                }
            }
            
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        $response['success'] = false;
        $response['message'] = "Error: " . $e->getMessage();
    }
}

else if ($action === 'logout') {
    // Perform logout
    try {
        error_log("Logging out student: " . $studentEmail);
        
        // Clear RememberMeToken from database
        $query = "UPDATE studentusers SET RememberMeToken = NULL, ResetTokenExpiry = NULL WHERE Email = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            $response['success'] = false;
            $response['message'] = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param("s", $studentEmail);
            $stmt->execute();
            $stmt->close();
            
            // Destroy session
            session_destroy();
            
            $response['success'] = true;
            $response['message'] = "Logged out successfully.";
        }
    } catch (Exception $e) {
        error_log("Exception during logout: " . $e->getMessage());
        $response['success'] = false;
        $response['message'] = "Error during logout: " . $e->getMessage();
    }
}

if ($conn) {
    $conn->close();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>