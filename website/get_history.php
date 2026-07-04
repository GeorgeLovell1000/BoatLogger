<?php
date_default_timezone_set('Europe/London');

$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die(json_encode(["error" => "Database Connection Failed"]));
}

$hours = isset($_GET['hours']) ? intval($_GET['hours']) : 8;

// --- NEW: CATCH THE SPECIFIC BOAT ID ---
$device_id = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'YOUR_BOAT_ID';

$time_threshold = date('Y-m-d H:i:s', time() - ($hours * 3600));

// --- NEW: FILTER BY DEVICE ID IN THE SQL QUERY ---
$query = "SELECT lat, lng, timestamp, mode FROM boat_logs 
          WHERE device_id = '$device_id' 
          AND timestamp >= '$time_threshold' 
          ORDER BY timestamp ASC";
          
$result = $conn->query($query);

$points = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $points[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($points);

$conn->close();
?>
