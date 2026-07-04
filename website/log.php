<?php
date_default_timezone_set('Europe/London');

// --- CONFIGURATION ---
$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';


$secret_key = "YOUR_PRIMARY_API_KEY";
// --- DUAL-KEY SECURITY CHECK ---
//$primary_key = "YOUR_PRIMARY_API_KEY";
$legacy_key  = "YOUR_LEGACY_API_KEY"; // TODO: Delete after updating boat this weekend

$incoming_key = $_REQUEST['key'] ?? '';

if ($incoming_key !== $secret_key && $incoming_key !== $legacy_key) {
    header('HTTP/1.0 403 Forbidden');
    die("Unauthorized - Key Mismatch, idiot");
}

//if ($incoming_key !== $primary_key && $incoming_key !== $legacy_key) {
//    header('HTTP/1.0 403 Forbidden');
//    die("Unauthorized - Key Mismatch, idiot");
//}



// --- DATABASE CONNECTION ---
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { 
    die("Database Connection failed: " . $conn->connect_error); 
}

// --- SINGLE SECURITY CHECK ---
if (!isset($_REQUEST['key']) || $_REQUEST['key'] !== $secret_key) {
    header('HTTP/1.0 403 Forbidden');
    die("Unauthorized - Key Mismatch");
}

// --- DATA PREPARATION ---
$device_id = $_REQUEST['device_id'] ?? 'YOUR_BOAT_ID';

$uptime  = $_REQUEST['uptime']  ?? 0;
$success = $_REQUEST['success'] ?? 0;
$fail    = $_REQUEST['fail']    ?? 0;
$lat  = $_REQUEST['lat']  ?? 0.00000;
$lng  = $_REQUEST['lng']  ?? 0.00000;
$mode = $_REQUEST['status'] ?? 'moored';
$link = $_REQUEST['link']   ?? 'unknown'; 
$v    = $_REQUEST['v']    ?? 0.0;
$t    = $_REQUEST['temp'] ?? 0.0;
$h    = $_REQUEST['hum']  ?? 0.0;
$p    = $_REQUEST['pressure'] ?? 0.0; 
$m_temp = $_REQUEST['m_temp'] ?? 0.0;
$is_delayed = isset($_REQUEST['delayed']) ? 1 : 0; 

// --- NEW: Capture exact atomic time from the boat ---
// (URL encoding uses '%20' for spaces, which PHP automatically decodes)
// --- NEW: Capture exact atomic time from the boat and convert to local time ---
// (URL encoding uses '%20' for spaces, which PHP automatically decodes)
if (isset($_REQUEST['time'])) {
    // 1. Explicitly define the incoming ESP32 string as UTC
    $boat_time = new DateTime($_REQUEST['time'], new DateTimeZone('UTC'));
    
    // 2. Convert it to the UK timezone (Automatically handles BST/GMT shifts)
    $boat_time->setTimezone(new DateTimeZone('Europe/London'));
    
    // 3. Format it back to a standard string for the database and JSON
    $log_time = $boat_time->format('Y-m-d H:i:s');
} else {
    // Fallback if the boat doesn't send a time
    $log_time = date('Y-m-d H:i:s'); 
}

// CLOSED-LOOP RECEIVERS
$flat = $_REQUEST['flat'] ?? 0.00000;
$flng = $_REQUEST['flng'] ?? 0.00000;
$frad = $_REQUEST['frad'] ?? 20.0;

// --- 1. INSERT INTO PERMANENT DATABASE ---
try {
    // --- NEW: Explicitly insert the timestamp rather than relying on MySQL defaults ---
    $stmt = $conn->prepare("INSERT INTO boat_logs (timestamp, device_id, connection_type, mode, lat, lng, voltage, temp, modem_temp, humidity, pressure, fence_lat, fence_lng, fence_radius, is_delayed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // The binding string now begins with 's' to account for the new $log_time string
    $stmt->bind_param("ssssddddddddddi", $log_time, $device_id, $link, $mode, $lat, $lng, $v, $t, $m_temp, $h, $p, $flat, $flng, $frad, $is_delayed);
    
    $stmt->execute();
    $stmt->close();
} catch (Exception $e) {
    // Log failure or handle silently
}

// --- ISOLATE JSON FILES BY DEVICE ---
$control_file = ($device_id === 'YOUR_BOAT_ID') ? 'control_buffer.json' : "control_buffer_{$device_id}.json";
$data_file = ($device_id === 'YOUR_BOAT_ID') ? 'boat_data.json' : "boat_data_{$device_id}.json";

// --- LOAD CONTROL CONFIG BUFFER ---
$control_data = file_exists($control_file) ? json_decode(file_get_contents($control_file), true) : [];

// --- 2. UPDATE JSON FILE FOR DASHBOARD ---
// --- NEW: Only overwrite the live dashboard if this is a fresh log, NOT an offline backlog! ---
if ($is_delayed === 0) {
    $dashboard_data = [
        'device_id' => $device_id, 
        'lat' => floatval($lat),
        'lng' => floatval($lng),
        'temp' => floatval($t),
        'modem_temp' => floatval($m_temp), 
        'hum' => floatval($h),
        'pressure' => floatval($p), 
        'voltage' => floatval($v), 
        'status' => $mode,
        'link' => $link,
        'last_seen' => $log_time, // Use the exact boat time for the dashboard heartbeat
        'fence_lat' => floatval($flat),     
        'fence_lng' => floatval($flng),     
        'fence_radius' => floatval($frad),
        'uptime' => intval($uptime),          
        'success_logs' => intval($success),   
        'failed_logs' => intval($fail),
        
        'mooring_sleep_sec'    => intval($control_data['mooring_sleep_sec'] ?? 300),
        'anchor_sleep_sec'     => intval($control_data['anchor_sleep_sec'] ?? 120),
        'travelling_sleep_sec' => intval($control_data['travelling_sleep_sec'] ?? 60)
    ];

    file_put_contents($data_file, json_encode($dashboard_data));
}

// --- 3. DYNAMIC COMMAND DOWNLINK PACKING ---
$response = [
    "status" => "success",
    "device_acknowledged" => $device_id, 
    "received_link" => $link
];

if (!empty($control_data)) {
    // --- FIX: Normalize the Epochs ---
    // Convert the server's 1970 Unix timestamp into the ESP32's 2000 Epoch
    // The exact difference is 946,684,800 seconds.
    $server_unix = intval($control_data['updated_at_unix'] ?? 0);
    $mpy_epoch = ($server_unix > 0) ? ($server_unix - 946684800) : 0;

    $response["commands"] = [
        "current_status" => $control_data['current_status'],
        "anchor_lat" => $control_data['anchor_lat'],
        "anchor_lng" => $control_data['anchor_lng'],
        "geofence_meters" => $control_data['geofence_meters'],
        "mooring_sleep_sec"    => intval($control_data['mooring_sleep_sec'] ?? 300),
        "anchor_sleep_sec"     => intval($control_data['anchor_sleep_sec'] ?? 120),
        "travelling_sleep_sec" => intval($control_data['travelling_sleep_sec'] ?? 60),
        "updated_at_unix"      => $mpy_epoch // <-- Packed and time-shifted for the ESP32!
    ];
}

header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
