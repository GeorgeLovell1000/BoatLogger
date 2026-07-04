<?php
// db_health.php
header('Content-Type: application/json');

$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$response = ["db_status" => "FAILED", "error" => ""];

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    if ($conn->connect_error) {
        $response["error"] = "Connection refused: " . $conn->connect_error;
    } else {
        // Run a tiny query just to prove data can be read
        $result = $conn->query("SELECT COUNT(*) as count FROM boat_logs");
        if ($result) {
            $row = $result->fetch_assoc();
            $response["db_status"] = "OK";
            $response["logs_count"] = $row['count'];
        } else {
            $response["error"] = "Query failed.";
        }
        $conn->close();
    }
} catch (Exception $e) {
    $response["error"] = $e->getMessage();
}

echo json_encode($response);
?>