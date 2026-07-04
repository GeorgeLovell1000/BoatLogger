<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/London');

echo "<html><head><title>History Database Test</title>
      <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #1a1a2e; color: #ffffff; padding: 30px; }
        h2 { color: #e94560; border-bottom: 2px solid #0f3460; padding-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; max-width: 900px; margin-top: 20px; background: #16213e; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #0f3460; }
        th { background-color: #e94560; color: white; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px; }
        tr:nth-child(even) { background-color: #1a2646; }
        tr:hover { background-color: #1f3160; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; }
        .wifi { background: #27ae60; color: white; }
        .cell { background: #e67e22; color: white; }
        .alarm { background: #c0392b; color: white; }
        .normal { background: #2980b9; color: white; }
        .vessel-select { padding: 8px 12px; background: #222b45; color: white; border: 1px solid #0f3460; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; text-transform: uppercase; }
      </style></head><body>";

echo "<h2>Raw Database History</h2>";

// Verified Core Database Credentials
$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("<p style='color: #e94560; font-weight: bold;'>MySQL Connection Failed: " . $conn->connect_error . "</p></body></html>");
}

// 1. Fetch all unique vessels for the dropdown
$active_vessels = [];
$vessel_query = $conn->query("SELECT DISTINCT device_id FROM boat_logs WHERE device_id IS NOT NULL AND device_id != '' ORDER BY device_id ASC");
if ($vessel_query) {
    while($row = $vessel_query->fetch_assoc()) {
        $active_vessels[] = $row['device_id'];
    }
}
if (empty($active_vessels)) $active_vessels = ['YOUR_BOAT_ID']; // Failsafe fallback

// 2. Determine which vessel is currently selected (defaults to the first one in the DB)
$selected_device = $_GET['device_id'] ?? $active_vessels[0];

// 3. Render the Dropdown Menu
echo "<div style='margin-bottom: 20px;'>
        <label for='vessel_selector' style='font-weight: bold; margin-right: 10px; color: #8a99ad; text-transform: uppercase; font-size: 0.85rem;'>Target Vessel:</label>
        <select id='vessel_selector' class='vessel-select' onchange=\"window.location.href='viewlogslist.php?device_id=' + this.value\">";
foreach ($active_vessels as $v) {
    $is_selected = ($v === $selected_device) ? "selected" : "";
    echo "<option value='" . htmlspecialchars($v) . "' $is_selected>" . htmlspecialchars(ucfirst($v)) . "</option>";
}
echo "  </select>
      </div>";

// 4. Pull last 50 positions securely using a prepared statement filtered by the selected device
$stmt = $conn->prepare("SELECT timestamp, connection_type, mode, lat, lng FROM boat_logs WHERE device_id = ? ORDER BY timestamp DESC LIMIT 50");
$stmt->bind_param("s", $selected_device);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>Timestamp</th><th>Connection Pathway</th><th>Vessel Mode</th><th>Latitude Vector</th><th>Longitude Vector</th></tr>";
    
    while($row = $result->fetch_assoc()) {
        $link_class = (strpos(strtolower($row['connection_type']), 'wifi') !== false) ? 'wifi' : 'cell';
        $mode_class = (strtolower($row['mode']) === 'alarm') ? 'alarm' : 'normal';
        
        echo "<tr>";
        echo "<td style='font-family: monospace;'>" . htmlspecialchars($row['timestamp']) . "</td>";
        echo "<td><span class='badge $link_class'>" . htmlspecialchars($row['connection_type']) . "</span></td>";
        echo "<td><span class='badge $mode_class'>" . htmlspecialchars($row['mode']) . "</span></td>";
        echo "<td style='font-family: monospace;'>" . htmlspecialchars($row['lat']) . "° N</td>";
        echo "<td style='font-family: monospace;'>" . htmlspecialchars($row['lng']) . "° W</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: #e67e22;'>No logs found in the database for vessel: <b>" . htmlspecialchars(ucfirst($selected_device)) . "</b></p>";
}

$stmt->close();
$conn->close();
echo "</body></html>";
?>
