<?php
date_default_timezone_set('Europe/London');

// --- NEW: DATABASE CONNECTION FOR DYNAMIC VESSEL LIST ---
$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$active_vessels = [];
if (!$conn->connect_error) {
    $result = $conn->query("SELECT DISTINCT device_id FROM boat_logs WHERE device_id IS NOT NULL AND device_id != '' ORDER BY device_id ASC");
    if ($result) {
        while($row = $result->fetch_assoc()) {
            $active_vessels[] = $row['device_id'];
        }
    }
}
if (empty($active_vessels)) $active_vessels = ['YOUR_BOAT_ID']; // Failsafe

// --- READ PERSISTENT DISPLAY CONFIGURATIONS (ACROSS ALL COMPUTERS) ---
$settings_file = 'ui_settings.json';
if (file_exists($settings_file)) {
    $ui_settings = json_decode(file_get_contents($settings_file), true);
    $active_theme = $ui_settings['theme'] ?? 'default';
} else {
    $active_theme = 'default';
}

// --- 1. DETERMINE ACTIVE VESSEL CONTEXT ---
$vessel = $_GET['device_id'] ?? 'YOUR_BOAT_ID';
$json_file = ($vessel === 'YOUR_BOAT_ID') ? 'boat_data.json' : "boat_data_{$vessel}.json";
$control_file = ($vessel === 'YOUR_BOAT_ID') ? 'control_buffer.json' : "control_buffer_{$vessel}.json";

// --- INLINE BACKEND CONTROLLER: SAVES DYNAMIC DOWN-LINK ACTIONS ASYNCHRONOUSLY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($_GET['action'] === 'save_control' && $input) {
        
        // Pull down existing buffer file if it exists to preserve non-targeted sleep configurations
        $existing_buffer = [];
        if (file_exists($control_file)) {
            $existing_buffer = json_decode(file_get_contents($control_file), true);
        }

        $control_data = [
            'current_status' => $input['status'] ?? 'moored',
            'anchor_lat' => floatval($input['lat'] ?? 0.00000),
            'anchor_lng' => floatval($input['lng'] ?? 0.00000),
            'geofence_meters' => floatval($input['radius'] ?? 20.0),
            'updated_at' => date("Y-m-d H:i:s"),
            'updated_at_unix' => time(), // <--- NEW: Raw timestamp for LWW evaluation
            
            // Pass through explicitly assigned dynamic sleep loops or fall back onto historical buffers
            'mooring_sleep_sec'    => ($input['status'] === 'moored')     ? intval($input['sleep_sec']) : ($existing_buffer['mooring_sleep_sec'] ?? 300),
            'travelling_sleep_sec' => ($input['status'] === 'travelling') ? intval($input['sleep_sec']) : ($existing_buffer['travelling_sleep_sec'] ?? 60),
            'anchor_sleep_sec'     => ($input['status'] === 'anchored')   ? intval($input['sleep_sec']) : ($existing_buffer['anchor_sleep_sec'] ?? 120)
        ];
        
        file_put_contents($control_file, json_encode($control_data, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'message' => 'Downlink control buffer register updated safely.']);
        exit;
    }
    
    // Server-side save intercept for the dynamic display engine profile
    if ($_GET['action'] === 'save_theme' && $input && isset($input['theme'])) {
        file_put_contents($settings_file, json_encode(['theme' => $input['theme']], JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'message' => 'Display profile orientation updated globally.']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Payload processing failure.']);
    exit;
}

// Load baseline live location metrics
if (file_exists($json_file)) {
    $boat = json_decode(file_get_contents($json_file), true);
} else {
    $boat = ['lat'=>0.00000, 'lng'=>0.00000, 'status'=>'moored', 'temp'=>0, 'hum'=>0, 'voltage'=>'13.20', 'link'=>'none', 'last_seen'=>date("Y-m-d H:i:s"), 'mooring_sleep_sec'=>300, 'travelling_sleep_sec'=>60, 'anchor_sleep_sec'=>120];
}

// Pre-populate system controls using current active values or historical down-link logs
if (file_exists($control_file)) {
    $current_control = json_decode(file_get_contents($control_file), true);
} else {
    $current_control = [
        'current_status' => $boat['status'] ?? 'moored',
        'anchor_lat' => $boat['lat'] ?? 0.00000,
        'anchor_lng' => $boat['lng'] ?? 0.00000,
        'geofence_meters' => 20.0,
        'mooring_sleep_sec' => $boat['mooring_sleep_sec'] ?? 300,
        'travelling_sleep_sec' => $boat['travelling_sleep_sec'] ?? 60,
        'anchor_sleep_sec' => $boat['anchor_sleep_sec'] ?? 120
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BoatLog Now</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <style>
        /* --- CORE STYLING MATRIX ARCHITECTURE MATRIX --- */
        body[data-theme="default"] {
            --bg-dark: #1a1a2e;
            --panel-bg: #16213e;
            --accent: #0f3460;
            --text: #e94560;
            --white: #ffffff;
            --green: #2ecc71;
            --amber: #f39c12;
        }
        body[data-theme="night"] {
            --bg-dark: #000000;
            --panel-bg: #090909;
            --accent: #151515;
            --text: #ff2a2a;
            --white: #cc0000;
            --green: #1ebd59;
            --amber: #d3830c;
        }
        body[data-theme="high-contrast"] {
            --bg-dark: #000000;
            --panel-bg: #1a1a1a;
            --accent: #333333;
            --text: #ffffff;
            --white: #ffffff;
            --green: #ffffff;
            --amber: #ffffff;
        }
        body[data-theme="ocean"] {
            --bg-dark: #0b132b;
            --panel-bg: #1c2541;
            --accent: #3a506b;
            --text: #48cae4;
            --white: #ffffff;
            --green: #52b788;
            --amber: #ee9b00;
        }
        body[data-theme="terminal"] {
            --bg-dark: #020202;
            --panel-bg: #0d0d0d;
            --accent: #1a1a1a;
            --text: #39ff14;
            --white: #39ff14;
            --green: #39ff14;
            --amber: #39ff14;
        }

        body, html { margin: 0; padding: 0; height: 100%; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg-dark); color: var(--white); transition: background 0.3s, color 0.3s; }
        
        #layout { display: grid; grid-template-columns: 1fr 400px; height: 100vh; }
        #map { height: 100%; width: 100%; }
        
        #sidebar { 
            background: var(--panel-bg); 
            padding: 25px; 
            box-shadow: -5px 0 15px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            gap: 18px;
            overflow-y: auto;
            transition: background 0.3s;
        }

        h1 { font-size: 1.4rem; margin: 0; color: var(--text); border-bottom: 2px solid var(--accent); padding-bottom: 10px; letter-spacing: 1px; }
        h2 { font-size: 0.9rem; margin: 0 0 10px 0; color: var(--text); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px dashed var(--accent); padding-bottom: 5px;}

        .card { 
            background: var(--accent); 
            padding: 15px; 
            border-radius: 10px; 
            border-left: 4px solid var(--text);
            position: relative;
            transition: background 0.3s, border-color 0.3s;
        }
        
        .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #b3b3b3; }
        .value { font-size: 1.2rem; font-weight: bold; display: block; margin-top: 5px; }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            background: #e67e22; 
            margin-top: 5px;
            text-transform: uppercase;
            font-weight: bold;
        }

        .heartbeat { font-size: 1.4rem; color: var(--green); }

        .nudge-container { display: grid; grid-template-columns: repeat(3, 1fr); width: 150px; margin: 15px auto 5px auto; gap: 5px; }
        .nudge-btn { background: #222b45; color: white; border: 1px solid var(--panel-bg); font-size: 1.2rem; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: bold; position: relative; transition: all 0.2s; touch-action: manipulation;}
        .nudge-btn:hover { background: var(--text); }
        
        .picker-armed { background: var(--amber) !important; color: #000 !important; box-shadow: 0 0 12px var(--amber); }

        .fence-control { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; background: #222b45; padding: 8px 12px; border-radius: 6px; }
        .fence-btn { background: var(--panel-bg); color: white; border: none; font-size: 1.3rem; width: 35px; height: 35px; border-radius: 50%; cursor: pointer; font-weight: bold; display: flex; align-items: center; justify-content: center; touch-action: manipulation;}
        .fence-btn:hover { background: var(--text); }
        .fence-val { font-size: 1.2rem; font-weight: bold; color: var(--white); }

        #geofence-sliders { transition: all 0.25s ease; }

        button.action-master {
            background: var(--text);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            width: 100%;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            margin-top: 5px;
            transition: all 0.3s ease;
        }
        button.action-master:disabled {
            background: #4a4a5a !important;
            opacity: 0.35 !important;
            cursor: not-allowed !important;
            box-shadow: none !important;
            animation: none !important;
        }

        .btn-group { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-top: 10px; }
        .btn-group button { background: #222b45; border: 1px solid var(--panel-bg); color: white; padding: 10px 5px; font-size: 0.85rem; border-radius: 4px; cursor: pointer; font-weight: bold; transition: all 0.2s; }
        .btn-group button.active { background: var(--text); border-color: var(--text); }

        @keyframes flash-red-glow {
            0% { box-shadow: 0 0 4px rgba(233, 69, 96, 0.4); filter: brightness(1); }
            50% { box-shadow: 0 0 20px rgba(233, 69, 96, 1); filter: brightness(1.2); }
            100% { box-shadow: 0 0 4px rgba(233, 69, 96, 0.4); filter: brightness(1); }
        }
        @keyframes flash-green-glow {
            0% { box-shadow: 0 0 4px rgba(46, 204, 113, 0.4); filter: brightness(1); }
            50% { box-shadow: 0 0 18px rgba(46, 204, 113, 1); filter: brightness(1.3); background: var(--green); }
            100% { box-shadow: 0 0 4px rgba(46, 204, 113, 0.4); filter: brightness(1); }
        }
        .flashing-save { animation: flash-red-glow 1.2s infinite ease-in-out !important; }
        .flashing-snap { animation: flash-green-glow 1.2s infinite ease-in-out !important; }

        .leaflet-popup-content-wrapper { background: var(--panel-bg); color: white; border-radius: 8px; }
        .leaflet-popup-tip { background: var(--panel-bg); }
        
        /* --- NEW: WEB ALARM UI STYLES --- */
        .auth-btn {
            background: #4a4a5a; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: all 0.3s; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .auth-btn.granted { background: var(--green); color: #000; box-shadow: 0 0 10px var(--green); }
        .auth-btn.denied { background: var(--text); color: white; }
        
        @keyframes strobe-bg { 
            0% { background: rgba(255,0,0,0.95); } 
            50% { background: rgba(150,0,0,0.95); } 
            100% { background: rgba(255,0,0,0.95); } 
        }
        @keyframes pulse-text { 
            0% { transform: scale(1); } 
            50% { transform: scale(1.05); } 
            100% { transform: scale(1); } 
        }        
        
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($active_theme); ?>">

<div id="layout">
    <div id="map"></div>

    <div id="sidebar">
        <h1>BoatLog Now</h1>

        <div id="alarm-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,0,0,0.95); z-index:9999; flex-direction:column; justify-content:center; align-items:center; color:white; animation: strobe-bg 1s infinite;">
            <h1 style="font-size:3.5rem; margin-bottom:10px; color:white; border:none; animation: pulse-text 1s infinite; text-align:center;">🚨 ALARM 🚨</h1>
            <p style="font-size:1.2rem; margin-bottom:40px; font-weight:bold; text-align:center;">Vessel has breached the safe perimeter!</p>
            <button onclick="acknowledgeAlarm()" style="padding:20px 40px; font-size:1.2rem; font-weight:bold; background:#1a1a2e; color:white; border:2px solid white; border-radius:10px; cursor:pointer; box-shadow: 0 10px 20px rgba(0,0,0,0.5);">ACKNOWLEDGE & SILENCE</button>
        </div>
		
        <div class="card" style="margin-bottom: 15px; padding: 10px 15px;">
            <span class="label">Active Vessel</span>
            <select id="activeVessel" onchange="switchVessel(this.value)" style="width:100%; padding:10px; background:#222b45; color:white; border:1px solid var(--panel-bg); border-radius:6px; font-weight:bold; font-size:1rem; cursor:pointer; outline:none; margin-top:5px; text-transform: uppercase;">
                <?php foreach ($active_vessels as $v): ?>
                    <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($vessel === $v) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst($v)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>        
		
        
        <div class="card">
            <span class="label">Last Log Update</span>
            <span id="heartbeat-value" class="value heartbeat">Initializing...</span>
            <span id="heartbeat-recorded">Recorded: --:--:--</span>
        </div>

        <div class="card">
            <span class="label">Operational Tracking Mode</span>
            <div class="btn-group">
                <button id="mode-moored" class="<?php echo ($current_control['current_status'] === 'moored') ? 'active' : ''; ?>" onclick="selectVesselMode('moored')">Moored</button>
                <button id="mode-travelling" class="<?php echo ($current_control['current_status'] === 'travelling') ? 'active' : ''; ?>" onclick="selectVesselMode('travelling')">Travelling</button>
                <button id="mode-anchored" class="<?php echo ($current_control['current_status'] === 'anchored') ? 'active' : ''; ?>" onclick="selectVesselMode('anchored')">Anchored</button>            
            </div>
            <div style="margin-top:12px; border-top:1px dashed rgba(255,255,255,0.1); padding-top:8px;">
                <span class="label">Active Data Link Status: </span><span id="status-value" style="font-weight:bold;">--</span>
                <div><span id="link-badge" class="badge">via --</span></div>
            </div>
        </div>

        <div class="card" id="geofence-control-panel">
            <h2>Alarm Parameters</h2>
            
            <div id="geofence-sliders">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span class="label">Fence Lat</span>
                        <span id="ctrl-lat-display" style="font-family:monospace; display:block; font-weight:bold; font-size:0.95rem; margin-top:2px;">--</span>
                    </div>
                    <div style="text-align:right;">
                        <span class="label">Fence Lng</span>
                        <span id="ctrl-lng-display" style="font-family:monospace; display:block; font-weight:bold; font-size:0.95rem; margin-top:2px;">--</span>
                    </div>
                </div>

                <div class="nudge-container">
                    <div></div>
                    <button class="nudge-btn" onclick="nudgeAnchor('N')">↑</button>
                    <div></div>
                    <button class="nudge-btn" onclick="nudgeAnchor('W')">←</button>
                    <button id="snap-button" class="nudge-btn" style="background:var(--panel-bg); font-size:0.75rem; padding:10px 1px; color:var(--white);" onclick="snapFenceToBoat()">Snap</button>
                    <button class="nudge-btn" onclick="nudgeAnchor('E')">→</button>
                    <div></div>
                    <button class="nudge-btn" onclick="nudgeAnchor('S')">↓</button> 
                    <button id="map-picker-btn" class="nudge-btn" title="Click anywhere on the map to set geofence position" onclick="toggleMapPicker()">⌖</button>
                </div>
                <p style="margin:2px 0 10px 0; font-size:0.65rem; text-align:center; color:#aaa; font-style:italic;">1 Click = Exactly 1 Meter Adjustment Vector</p>

			<span class="label">Alarm Limit (Radius)</span>
                <div class="fence-control">
                    <button class="fence-btn" onclick="adjustRadius(-1)">-</button>
                    <span class="fence-val"><span id="ctrl-radius-display">20</span>m</span>
                    <button class="fence-btn" onclick="adjustRadius(1)">+</button>
                </div>
            </div> <span class="label" style="display:block; margin-top:15px;">Logger Cycle Sleep Interval</span>
            <select id="ctrl-sleep-display" onchange="adjustSleepWindow(this.value)" style="width:100%; padding:10px; background:#222b45; color:white; border:1px solid var(--panel-bg); border-radius:6px; font-weight:bold; font-size:0.9rem; cursor:pointer; outline:none; margin-top:6px; box-sizing:border-box;">
                <option value="60">1 Minute</option>
                <option value="120">2 Minutes</option>
                <option value="180">3 Minutes</option>
                <option value="240">4 Minutes</option>
                <option value="300">5 Minutes</option>
                <option value="600">10 Minutes</option>
                <option value="1200">20 Minutes</option>
            </select>
            
            <button id="save-boat-btn" class="action-master" style="background:#27ae60; margin-top:15px;" onclick="commitControlBuffer()" disabled>Save changes to boat</button>
        </div>

		<div class="card">
            <span class="label">CURRENT AND RECENT LOCATIONS</span>
            <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                <span id="lat-value" class="value" style="margin-top: 0;">-- N</span>
                <span id="lng-value" class="value" style="margin-top: 0;">-- W</span>
            </div>
            <button onclick="zoomToBoat()" style="width:100%; margin-top:8px; padding:8px;">Center Map on Boat</button>
            
            <span class="label" style="display:block; margin-top:15px;">Historical Tracer Window</span>
            <div class="btn-group">
                <button id="btn-1h" onclick="changeHistoryWindow(1)">1 Hour</button>
                <button id="btn-8h" class="active" onclick="changeHistoryWindow(8)">8 Hours</button>
                <button id="btn-24h" onclick="changeHistoryWindow(24)">24 Hours</button>
            </div>
            
            <button onclick="window.open('viewlogs.php?device_id=' + (localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID'), '_blank')" 
                    style="width:100%; margin-top:10px; padding:8px; background: #222b45; color: white; border: 1px solid var(--panel-bg); font-weight: bold; border-radius: 4px; cursor: pointer; transition: background 0.2s;" 
                    onmouseover="this.style.background='var(--text)'" 
                    onmouseout="this.style.background='#222b45'">
                View Logs
            </button>
        </div>

        <div class="card">
            <h2>Vessel Status</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 10px;">
                <div>
                    <span class="label">Cabin Temperature</span>
                    <span id="ui-cabin-temp" class="value">--.-°C</span>
                </div>
                <div style="text-align: right;">
                    <span class="label">Relative Humidity</span>
                    <span id="ui-cabin-hum" class="value">--%</span>
                </div>
            </div>

            <div id="pressure-container" style="display: none; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 10px;">
                <div>
                    <span class="label">Atmospheric Pressure</span>
                    <span id="ui-pressure" class="value">-- hPa</span>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px;">
                <div>
                    <span class="label">Battery Voltage</span>
                    <span id="logger-voltage-display" class="value">-- V</span>
                </div>
                <div style="text-align: right;">
                    <span class="label">State of Charge (SoC)</span>
                    <span id="battery-soc" class="value" style="color: var(--white);">--%</span>
                </div>
            </div>
            <div style="margin-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 8px;">
                <span class="label">Battery Condition: </span>
                <span id="battery-condition" style="font-weight: bold; color: var(--white);">Initializing...</span>
            </div>
        </div>

		<div class="card">
			<h2>System Diagnostics</h2>
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 5px;">
				<div style="background: #1a2646; padding: 12px; border-radius: 6px; border: 1px solid #0f3460;">
					<div style="font-size: 0.75rem; color: #8a99ad; text-transform: uppercase; letter-spacing: 0.5px;">Uptime</div>
					<div id="ui-uptime" style="font-size: 1.15rem; font-weight: bold; color: #ffffff; font-family: monospace; margin-top: 5px;">0d 0h 0m</div>
				</div>
				<div style="background: #1a2646; padding: 12px; border-radius: 6px; border: 1px solid #0f3460;">
					<div style="font-size: 0.75rem; color: #8a99ad; text-transform: uppercase; letter-spacing: 0.5px;">Log Success %</div>
					<div id="ui-rate" style="font-size: 1.15rem; font-weight: bold; color: #27ae60; font-family: monospace; margin-top: 5px;">100.0%</div>
				</div>
				<div style="background: #1a2646; padding: 12px; border-radius: 6px; border: 1px solid #0f3460;">
					<div style="font-size: 0.75rem; color: #8a99ad; text-transform: uppercase; letter-spacing: 0.5px;">Logs Received</div>
					<div id="ui-success" style="font-size: 1.15rem; font-weight: bold; color: #ffffff; font-family: monospace; margin-top: 5px;">0</div>
				</div>
				<div style="background: #1a2646; padding: 12px; border-radius: 6px; border: 1px solid #0f3460;">
					<div style="font-size: 0.75rem; color: #8a99ad; text-transform: uppercase; letter-spacing: 0.5px;">Logs Missed</div>
					<div id="ui-fail" style="font-size: 1.15rem; font-weight: bold; color: #e94560; font-family: monospace; margin-top: 5px;">0</div>
				</div>
				<div style="background: #1a2646; padding: 12px; border-radius: 6px; border: 1px solid #0f3460; grid-column: span 2;">
					<div style="font-size: 0.75rem; color: #8a99ad; text-transform: uppercase; letter-spacing: 0.5px;">Modem Temp</div>
					<div id="ui-modem-temp" style="font-size: 1.15rem; font-weight: bold; color: #ffffff; font-family: monospace; margin-top: 5px;">--.-°C</div>
				</div>
			</div>
		</div>

        <div class="card">
            <h2>Display Customization</h2>
            <select id="theme-selector" onchange="executeThemeShift(this.value)" style="width:100%; padding:10px; background:#222b45; color:white; border:1px solid var(--panel-bg); border-radius:6px; font-weight:bold; font-size:0.9rem; cursor:pointer; outline:none;">
                <option value="default" <?php echo ($active_theme === 'default') ? 'selected' : ''; ?>>Default (Blue & Red)</option>
                <option value="night" <?php echo ($active_theme === 'night') ? 'selected' : ''; ?>>Night Tactical (Deep Black & Red)</option>
                <option value="high-contrast" <?php echo ($active_theme === 'high-contrast') ? 'selected' : ''; ?>>High Contrast (Monochrome)</option>
                <option value="ocean" <?php echo ($active_theme === 'ocean') ? 'selected' : ''; ?>>Deep Ocean (Navy & Cyan)</option>
                <option value="terminal" <?php echo ($active_theme === 'terminal') ? 'selected' : ''; ?>>Retro Terminal (Matrix Green)</option>
            </select>
            <button id="alert-auth-btn" class="auth-btn" onclick="requestAlertPermission()">Authorise Alerts</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    
// --- ALARM SYSTEM STATE ---
    var isAlarmActive = false;
    var alarmAcknowledgedForCurrentEvent = false;
    var audioCtx = null;
    var sirenInterval = null;
    var sirenStep = 0;
        
    var lat = <?php echo $boat['lat']; ?>;
    var lng = <?php echo $boat['lng']; ?>;
    var boatStatus = "<?php echo strtolower($boat['status']); ?>";
    var lastSeenTimestamp = "<?php echo $boat['last_seen']; ?>";
    
    var ctrlLat = <?php echo $current_control['anchor_lat']; ?>;
    var ctrlLng = <?php echo $current_control['anchor_lng']; ?>;
    var ctrlRadius = <?php echo $current_control['geofence_meters']; ?>;
    var ctrlMode = "<?php echo $current_control['current_status']; ?>";
    
    // Core persistent registers holding current isolation times derived from local configuration
    var mooringSleepSec = <?php echo $current_control['mooring_sleep_sec'] ?? 300; ?>;
    var travellingSleepSec = <?php echo $current_control['travelling_sleep_sec'] ?? 60; ?>;
    var anchorSleepSec = <?php echo $current_control['anchor_sleep_sec'] ?? 120; ?>;
    
    // --- AUTHENTICATION FIREWALL CONFIGURATION MATRIX ---
    // Pre-calculated local browser SHA-256 target token derived from secret string 'YOUR_UI_PASSWORD'
	const SECURITY_HASH_TARGET = "YOUR_SHA256_UI_PASSWORD_HASH";
    const DEFAULT_MOORING_LAT = 0.00000;
    const DEFAULT_MOORING_LNG = 0.00000;

    var isMapPicking = false;
    var activeHistoryHours = 8;

    // STATE CONTROLLERS: Decouple hardware loop timing from user interface interactions
    var isEditing = false;
    var isChangesQueued = false;

    // --- CONFIGURE MAP TILES ---
    var lightMap = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' });
    var satelliteMap = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { 
        attribution: 'Esri',
        maxZoom: 21,
        maxNativeZoom: 18
    });

    var map = L.map('map', { center: [lat, lng], zoom: 17, layers: [satelliteMap] });
    L.control.layers({"Satellite Imagery": satelliteMap, "Standard Light Map": lightMap}).addTo(map);

    var marker = L.marker([lat, lng]).addTo(map).bindPopup("<b>Boat Location</b>")//.openPopup();
    
    // --- GEOFENCE VECTOR OVERLAYS ---
    var safetyCircle = L.circle([ctrlLat, ctrlLng], { 
        color: (ctrlMode === "anchored") ? "#2ecc71" : "#3498db", 
        fillColor: (ctrlMode === "anchored") ? "#2ecc71" : "#3498db", 
        fillOpacity: 0.04, 
        radius: ctrlRadius,
        weight: 2
    }).addTo(map);
    
    var stagedCircle = L.circle([ctrlLat, ctrlLng], {
        color: "#f39c12", 
        fillColor: "#f39c12",
        fillOpacity: 0.08,
        radius: ctrlRadius,
        weight: 2,
        dashArray: "6, 9"
    }).addTo(map);
    
    var historyLayerGroup = L.layerGroup().addTo(map);

    map.on('click', function(e) {
        if (!isMapPicking) return; 
        
        ctrlLat = e.latlng.lat;
        ctrlLng = e.latlng.lng;
        
        isEditing = true;
        isChangesQueued = false;
        triggerSaveEngagement(true);
        updateControlUI();
        updateMapCircles();
        toggleMapPicker();
    });

    updateControlUI();
    evalInitialMapRendering();

    function zoomToBoat() { map.flyTo([lat, lng], 19); }

    function toggleMapPicker() {
        if (ctrlMode === "travelling") return;
        
        isMapPicking = !isMapPicking;
        var pickerBtn = document.getElementById('map-picker-btn');
        
        if (isMapPicking) {
            pickerBtn.classList.add('picker-armed');
            map.getContainer().style.cursor = 'crosshair'; 
        } else {
            pickerBtn.classList.remove('picker-armed');
            map.getContainer().style.cursor = ''; 
        }
    }

    function updateMapCircles() {
        if (ctrlMode === "travelling") {
            stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 });
        } else {
            stagedCircle.setLatLng([ctrlLat, ctrlLng]);
            stagedCircle.setRadius(ctrlRadius);
            stagedCircle.setStyle({ opacity: 1, fillOpacity: 0.08, color: "#f39c12", fillColor: "#f39c12" });
        }
    }

    function selectVesselMode(newMode) {
        ctrlMode = newMode;
        isEditing = true;
        isChangesQueued = false;
        
        document.getElementById('mode-moored').classList.remove('active');
        document.getElementById('mode-travelling').classList.remove('active');
        document.getElementById('mode-anchored').classList.remove('active');
        document.getElementById('mode-' + newMode).classList.add('active');
        
        document.getElementById('snap-button').classList.remove('flashing-snap');
        if (isMapPicking) toggleMapPicker(); 

        if (ctrlMode === "moored") {
            ctrlLat = DEFAULT_MOORING_LAT;
            ctrlLng = DEFAULT_MOORING_LNG;
            document.getElementById('geofence-sliders').style.opacity = 1.0;
            document.getElementById('geofence-sliders').style.pointerEvents = 'auto';
            triggerSaveEngagement(true); 
            fitMapToEncompassContext();   
            
        } else if (ctrlMode === "anchored") {
            document.getElementById('geofence-sliders').style.opacity = 1.0;
            document.getElementById('geofence-sliders').style.pointerEvents = 'auto';
            document.getElementById('snap-button').classList.add('flashing-snap'); 
            triggerSaveEngagement(true);  
            fitMapToEncompassContext();   
            
        } else if (ctrlMode === "travelling") {
            document.getElementById('geofence-sliders').style.opacity = 0.25;
            document.getElementById('geofence-sliders').style.pointerEvents = 'none';
            triggerSaveEngagement(true); 
            map.flyTo([lat, lng], 17);
        }
        
        updateControlUI();
        updateMapCircles();
    }

    function snapFenceToBoat() {
        if (ctrlMode === "travelling") return; 
        
        ctrlLat = lat;
        ctrlLng = lng;
        isEditing = true;
        isChangesQueued = false;
        
        document.getElementById('snap-button').classList.remove('flashing-snap'); 
        triggerSaveEngagement(true);
        updateControlUI();
        updateMapCircles();
        map.flyTo([ctrlLat, ctrlLng], 19);
    }

    function nudgeAnchor(direction) {
        if (ctrlMode === "travelling") return; 
        
        const latOffsetPerMeter = 0.000009;
        const lngOffsetPerMeter = 0.0000162;
        
        if (direction === 'N') ctrlLat += latOffsetPerMeter;
        if (direction === 'S') ctrlLat -= latOffsetPerMeter;
        if (direction === 'E') ctrlLng += lngOffsetPerMeter;
        if (direction === 'W') ctrlLng -= lngOffsetPerMeter;
        
        isEditing = true;
        isChangesQueued = false;
        triggerSaveEngagement(true);
        updateControlUI();
        updateMapCircles();
    }

    function adjustRadius(delta) {
        if (ctrlMode === "travelling") return;
        ctrlRadius += delta;
        if (ctrlRadius < 5) ctrlRadius = 5; 
        if (ctrlRadius > 250) ctrlRadius = 250;
        
        isEditing = true;
        isChangesQueued = false;
        triggerSaveEngagement(true);
        updateControlUI();
        updateMapCircles();
    }

    // NEW: TARGETED TEMPORAL SLEEP ASSIGNMENT FOR THE DYNAMIC SELECTION LAYER
    function adjustSleepWindow(secondsValue) {
        let secInt = parseInt(secondsValue);
        if (ctrlMode === "moored") mooringSleepSec = secInt;
        else if (ctrlMode === "travelling") travellingSleepSec = secInt;
        else if (ctrlMode === "anchored") anchorSleepSec = secInt;
        
        isEditing = true;
        isChangesQueued = false;
        triggerSaveEngagement(true);
    }

    function triggerSaveEngagement(needsSave) {
        var btn = document.getElementById('save-boat-btn');
        if (needsSave) {
            btn.removeAttribute('disabled');
            btn.classList.add('flashing-save');
            btn.innerText = "Save changes to boat"; 
        } else {
            btn.setAttribute('disabled', 'true');
            btn.classList.remove('flashing-save');
        }
    }

    function fitMapToEncompassContext() {
        var points = [L.latLng(lat, lng), L.latLng(ctrlLat, ctrlLng)];
        if (safetyCircle && safetyCircle.getLatLng) { points.push(safetyCircle.getLatLng()); }
        var bounds = L.latLngBounds(points);
        map.fitBounds(bounds, { padding: [60, 60], maxZoom: 18 });
    }

    function evalInitialMapRendering() {
        if (ctrlMode === "travelling") {
            safetyCircle.setStyle({ opacity: 0, fillOpacity: 0 });
            stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 });
            document.getElementById('geofence-sliders').style.opacity = 0.25;
            document.getElementById('geofence-sliders').style.pointerEvents = 'none';
        } else {
            safetyCircle.setLatLng([ctrlLat, ctrlLng]);
            safetyCircle.setRadius(ctrlRadius);
            safetyCircle.setStyle({ opacity: 1, fillOpacity: 0.04, color: (ctrlMode === "anchored") ? "#2ecc71" : "#3498db" });
            stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 }); 
            fitMapToEncompassContext();
        }
    }

    function updateControlUI() {
        document.getElementById('ctrl-lat-display').innerText = ctrlLat.toFixed(5) + "° N";
        document.getElementById('ctrl-lng-display').innerText = Math.abs(ctrlLng).toFixed(5) + "° W";
        document.getElementById('ctrl-radius-display').innerText = ctrlRadius;
        
        // Update the dropdown selector position based on whichever profile timeline is selected
        let activeSleepSec = mooringSleepSec;
        if (ctrlMode === "travelling") activeSleepSec = travellingSleepSec;
        else if (ctrlMode === "anchored") activeSleepSec = anchorSleepSec;
        document.getElementById('ctrl-sleep-display').value = activeSleepSec;
    }

	async function commitControlBuffer() {
        var btn = document.getElementById('save-boat-btn');
        let cleanedAttempt = "";
        const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';

        // 1. Check if this specific browser already holds a valid authorization pass
        let isAlreadyAuthorized = localStorage.getItem('YOUR_BOAT_ID_authorized') === 'true';

        if (isAlreadyAuthorized) {
            // Browser is recognized! Pull down the saved validation credential automatically
            cleanedAttempt = "xYOUR_UI_PASSWORDy"; 
            console.log("🔒 Browser token recognized. Skipping passcode challenge stage.");
        } else {
            // Browser is unrecognized. Open the authorization challenge gate
            let passwordAttempt = prompt("Enter Mission Control Authorization Password:");
            if (passwordAttempt === null) return; // User selected cancel, drop out gracefully

            cleanedAttempt = String(passwordAttempt).trim();
        }

        try {
            btn.innerText = "Sync Staging..."; 
            
            // Map accurate target window variables prior to package compression 
            let targetSleepSec = mooringSleepSec;
            if (ctrlMode === "travelling") targetSleepSec = travellingSleepSec;
            else if (ctrlMode === "anchored") targetSleepSec = anchorSleepSec;

            let payload = { 
                status: ctrlMode, 
                lat: ctrlLat, 
                lng: ctrlLng, 
                radius: ctrlRadius,
                sleep_sec: targetSleepSec,
                password: cleanedAttempt // Transmit token verification down to the backend firewall
            };
            
            let response = await fetch('index.php?action=save_control&device_id=' + vessel, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            let result = await response.json();
            
            if (result.status === 'success') {
                // SUCCESS: If the password was typed manually, save the authorization pass now!
                if (!isAlreadyAuthorized) {
                    localStorage.setItem('YOUR_BOAT_ID_authorized', 'true');
                    console.log("✅ Credentials verified by backend. Storing persistent browser pass.");
                }

                isEditing = false;
                isChangesQueued = true;
                btn.setAttribute('disabled', 'true');
                btn.classList.remove('flashing-save');
                btn.innerText = "Changes Queued"; 
                document.getElementById('snap-button').classList.remove('flashing-snap');
            } else {
                // FAILURE: The password was wrong (or the background token expired)
                alert("❌ Unauthorized Access Denied - " + (result.message || "Token Mismatch."));
                
                // Wipe any corrupted passes out of the browser memory just to be safe
                localStorage.removeItem('YOUR_BOAT_ID_authorized');
                
                btn.innerText = "Save changes to boat";
                triggerSaveEngagement(true);
            }
        } catch (err) {
            alert("Connection drop error saving registers.");
        }
    }

    // --- ASYNCHRONOUS UI PERFORMANCE THEME SYSTEM DRIVER ---
    async function executeThemeShift(themeName) {
        document.body.setAttribute('data-theme', themeName);
        try {
            const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            await fetch('index.php?action=save_theme&device_id=' + vessel, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: themeName })
            });
        } catch (e) {
            console.log("Global theme persistence pipeline dropped a packet:", e);
        }
    }

    function runHeartbeatClock() {
        if (!lastSeenTimestamp) return;
        let lastSeenTime = new Date(lastSeenTimestamp.replace(' ', 'T')).getTime(); 
        let diffSeconds = Math.floor((Date.now() - lastSeenTime) / 1000);
        let el = document.getElementById('heartbeat-value');
        
        el.style.color = (diffSeconds > 600) ? '#e74c3c' : '#2ecc71';
        if (diffSeconds < 1) { el.innerText = "just now"; return; }

        let intervals = [{s:31536000, l:'year'}, {s:2592000, l:'month'}, {s:86400, l:'day'}, {s:3600, l:'hour'}, {s:60, l:'minute'}, {s:1, l:'second'}];
        for (let i = 0; i < intervals.length; i++) {
            let val = Math.floor(diffSeconds / intervals[i].s);
            if (val >= 1) { el.innerText = val + ' ' + intervals[i].l + (val > 1 ? 's' : '') + ' ago'; break; }
        }
    }

    async function syncAllData() {
        await fetchLatestData();
        await fetchHistoricalTracer();
    }

    function evaluateLiFePO4Parameters(vol) {
        var soc = 0; var statusMsg = "Unknown"; var statusColor = "var(--white)";
        if (isNaN(vol) || vol <= 5.0) return { soc: "--", text: "Sensor Offline", color: "#666" };
        if (vol >= 13.60) { soc = 100; statusMsg = "Fully Charged / Floating"; statusColor = "var(--green)"; }
        else if (vol >= 13.40) { soc = 90 + ((vol - 13.40) / 0.20) * 10; statusMsg = "Optimal Operating Range"; statusColor = "var(--green)"; }
        else if (vol >= 13.30) { soc = 70 + ((vol - 13.30) / 0.10) * 20; statusMsg = "Optimal Operating Range"; statusColor = "var(--green)"; }
        else if (vol >= 13.20) { soc = 40 + ((vol - 13.20) / 0.10) * 30; statusMsg = "Healthy Nominal State"; statusColor = "var(--green)"; }
        else if (vol >= 13.10) { soc = 30 + ((vol - 13.10) / 0.10) * 10; statusMsg = "Healthy Nominal State"; statusColor = "var(--green)"; }
        else if (vol >= 13.00) { soc = 10 + ((vol - 13.00) / 0.10) * 20; statusMsg = "Discharged / Under Load"; statusColor = "var(--amber)"; }
        else if (vol >= 12.80) { soc = 5 + ((vol - 12.80) / 0.20) * 5; statusMsg = "Low Capacity Warning"; statusColor = "var(--amber)"; }
        else { soc = 0; statusMsg = "CRITICAL DISCHARGE - Action Required!"; statusColor = "var(--text)"; }
        return { soc: Math.round(soc) + "%", text: statusMsg, color: statusColor };
    }

    async function fetchLatestData() {
        try {
            const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            let dataUrl = (vessel === 'YOUR_BOAT_ID') ? 'boat_data.json' : 'boat_data_' + vessel + '.json';
            let response = await fetch(dataUrl + '?nocache=' + Date.now());
            
            if (!response.ok) {
                // If test data isn't generated yet, fail gracefully
                return;
            }
            
            let data = await response.json();
            
            lat = parseFloat(data.lat);
            lng = parseFloat(data.lng);
            boatStatus = data.status.toLowerCase();
            lastSeenTimestamp = data.last_seen;

            // --- NEW: ALARM TRIGGER EVALUATION ---
            if (boatStatus === "alarm") {
                if (!isAlarmActive && !alarmAcknowledgedForCurrentEvent) {
                    startLocalAlarm();
                }
            } else {
                // If the boat returns to 'anchored' or 'travelling', reset the tripwire
                if (isAlarmActive) silenceLocalAlarm();
                alarmAcknowledgedForCurrentEvent = false; 
            }

            let lookupStatus = (boatStatus === "alarm") ? "anchored" : boatStatus;

            var liveFenceLat = data.fence_lat ? parseFloat(data.fence_lat) : DEFAULT_MOORING_LAT;
            var liveFenceLng = data.fence_lng ? parseFloat(data.fence_lng) : DEFAULT_MOORING_LNG;
            var liveFenceRadius = data.fence_radius ? parseFloat(data.fence_radius) : 20.0;

            if (lookupStatus === "travelling") {
                safetyCircle.setStyle({ opacity: 0, fillOpacity: 0 });
            } else {
                var liveColor = (lookupStatus === "anchored") ? "#2ecc71" : "#3498db";
                safetyCircle.setLatLng([liveFenceLat, liveFenceLng]);
                safetyCircle.setRadius(liveFenceRadius);
                safetyCircle.setStyle({ opacity: 1, fillOpacity: 0.04, color: liveColor, fillColor: liveColor });
            }

            var saveBtn = document.getElementById('save-boat-btn');
            
            if (!isEditing && !isChangesQueued) {
                document.getElementById('mode-moored').classList.remove('active');
                document.getElementById('mode-anchored').classList.remove('active');
                document.getElementById('mode-travelling').classList.remove('active');
                
                let activeModeBtn = document.getElementById('mode-' + lookupStatus);
                if (activeModeBtn) activeModeBtn.classList.add('active');

                ctrlMode = lookupStatus;
                ctrlLat = liveFenceLat;
                ctrlLng = liveFenceLng;
                ctrlRadius = liveFenceRadius;
                
                // Track dynamic background metrics arriving from live server data profiles
                if (data.mooring_sleep_sec) mooringSleepSec = parseInt(data.mooring_sleep_sec);
                if (data.travelling_sleep_sec) travellingSleepSec = parseInt(data.travelling_sleep_sec);
                if (data.anchor_sleep_sec) anchorSleepSec = parseInt(data.anchor_sleep_sec);

                updateControlUI();
                stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 }); 
                
                if (ctrlMode === "travelling") {
                    document.getElementById('geofence-sliders').style.opacity = 0.25;
                    document.getElementById('geofence-sliders').style.pointerEvents = 'none';
                } else {
                    document.getElementById('geofence-sliders').style.opacity = 1.0;
                    document.getElementById('geofence-sliders').style.pointerEvents = 'auto';
                }
            } else if (isChangesQueued) {
                var matchLat = Math.abs(liveFenceLat - ctrlLat) < 0.00001;
                var matchLng = Math.abs(liveFenceLng - ctrlLng) < 0.00001;
                var matchRad = Math.abs(liveFenceRadius - ctrlRadius) < 0.1;
                var matchMode = (lookupStatus === ctrlMode);

                let expectedSleepSec = mooringSleepSec;
                if (ctrlMode === "travelling") expectedSleepSec = travellingSleepSec;
                else if (ctrlMode === "anchored") expectedSleepSec = anchorSleepSec;
                
                let currentServerSleep = (ctrlMode === "moored") ? data.mooring_sleep_sec : ((ctrlMode === "travelling") ? data.travelling_sleep_sec : data.anchor_sleep_sec);
                let matchSleep = parseInt(currentServerSleep) === expectedSleepSec;

                // 1. SUCCESS STATE: The boat's live math perfectly matches our requested math
                if (matchLat && matchLng && matchRad && matchMode && matchSleep) {
                    isChangesQueued = false;
                    
                    saveBtn.classList.remove('flashing-save');
                    saveBtn.classList.add('flashing-snap'); 
                    saveBtn.innerText = "Changes Confirmed ✓";
                    
                    setTimeout(() => {
                        saveBtn.classList.remove('flashing-snap');
                        saveBtn.innerText = "Save changes to boat";
                    }, 4000);
                    
                    stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 });
                } else {
                    // 2. INTELLIGENT WAITING / FAILURE STATES
                    let lastSeenTime = new Date(lastSeenTimestamp.replace(' ', 'T')).getTime(); 
                    let diffSeconds = Math.floor((Date.now() - lastSeenTime) / 1000);
                    
                    // The boat is currently sleeping based on its OLD setting, not the newly requested one!
                    let activeBoatSleep = parseInt((ctrlMode === "moored") ? data.mooring_sleep_sec : ((ctrlMode === "travelling") ? data.travelling_sleep_sec : data.anchor_sleep_sec));
                    
                    // Hardware Buffer: ~90 seconds for ESP32 Boot + GPS Lock + Cell Handshake + 1-Sec Proof Reboot
                    let expectedCycleTime = activeBoatSleep + 90; 

                    if (!matchMode && lookupStatus !== "offline") {
                        // The physical hardware switch was flipped on the boat, overriding the web command
                        isChangesQueued = false;
                        saveBtn.innerText = "Overridden at Helm ✗";
                        setTimeout(() => { saveBtn.innerText = "Save changes to boat"; }, 4000);
                        stagedCircle.setStyle({ opacity: 0, fillOpacity: 0 });
                    } 
                    else if (diffSeconds <= expectedCycleTime) {
                        // WINDOW 1: Standard Expected Execution Window
                        let remaining = expectedCycleTime - diffSeconds;
                        let mins = Math.floor(remaining / 60);
                        let secs = remaining % 60;
                        let timeStr = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
                        
                        saveBtn.innerText = `Syncing (Expected ~${timeStr})`;
                        updateMapCircles();
                    }
                    else if (diffSeconds <= (expectedCycleTime + activeBoatSleep)) {
                        // WINDOW 2: Running Late (It missed its primary window, but hasn't missed a second full cycle)
                        // This happens if the modem was rejected by a tower and is running its retry penalty
                        saveBtn.innerText = "Syncing (Running Late...)";
                        updateMapCircles();
                    }
                    else {
                        // WINDOW 3: Cycle Skipped / Offline
                        saveBtn.innerText = "Queued (Cycle Missed / Offline)";
                        updateMapCircles();
                    }
                }
            }
            document.getElementById('status-value').innerText = data.status.toUpperCase();
            document.getElementById('lat-value').innerText = lat.toFixed(5) + " N";
            document.getElementById('lng-value').innerText = Math.abs(lng).toFixed(5) + " W";
            document.getElementById('heartbeat-recorded').innerText = "Recorded: " + lastSeenTimestamp.split(' ')[1];

            var rawVoltage = parseFloat(data.voltage); 
            var metrics = evaluateLiFePO4Parameters(rawVoltage);

            document.getElementById('logger-voltage-display').innerText = isNaN(rawVoltage) ? "-- V" : rawVoltage.toFixed(2) + "V";
            var socEl = document.getElementById('battery-soc');
            var condEl = document.getElementById('battery-condition');
            socEl.innerText = metrics.soc;
            socEl.style.color = metrics.color;
            condEl.innerText = metrics.text;
            condEl.style.color = metrics.color;

            let badge = document.getElementById('link-badge');
            badge.innerText = "via " + data.link.toUpperCase();
            badge.style.background = (data.link.toLowerCase() === 'wifi' || data.link.toLowerCase() === 'browser_test') ? '#27ae60' : '#e67e22';

            let newLatLng = new L.LatLng(lat, lng);
            marker.setLatLng(newLatLng);
            marker.getPopup().setContent("<b>Boat Location</b><br>Status: " + data.status.toUpperCase());

			if (data.uptime !== undefined) {
                let totalSeconds = parseInt(data.uptime);
                
                // --- MICROPYTHON ROLLOVER PATCH ---
                // If the 30-bit tick counter wraps to a negative number, unwrap it!
                // We add exactly 2^30 milliseconds (1,073,741 seconds) to restore the true timeline.
                if (totalSeconds < 0) {
                    totalSeconds += 1073741;
                }
                
                let days = Math.floor(totalSeconds / (3600 * 24));
                let hours = Math.floor((totalSeconds % (3600 * 24)) / 3600);
                let minutes = Math.floor((totalSeconds % 3600) / 60);
                document.getElementById('ui-uptime').innerText = `${days}d ${hours}h ${minutes}m`;
            }

            let sLogs = parseInt(data.success_logs || 0);
            let fLogs = parseInt(data.failed_logs || 0);
            document.getElementById('ui-success').innerText = sLogs;
            document.getElementById('ui-fail').innerText = fLogs;

            // --- 1. Map SHT40 Cabin Climate Metrics to the Vessel Status Panel ---
            let cabinTemp = parseFloat(data.temp);
            let cabinHum  = parseFloat(data.hum);
            document.getElementById('ui-cabin-temp').innerText = isNaN(cabinTemp) ? "--.-°C" : cabinTemp.toFixed(1) + "°C";
            document.getElementById('ui-cabin-hum').innerText  = isNaN(cabinHum)  ? "--%" : Math.round(cabinHum) + "%";

            // --- NEW: Map Conditional Barometric Pressure ---
            let cabinPressure = parseFloat(data.pressure);
            let pressureBox = document.getElementById('pressure-container');
            if (!isNaN(cabinPressure) && cabinPressure > 0) {
                document.getElementById('ui-pressure').innerText = cabinPressure.toFixed(1) + " hPa";
                pressureBox.style.display = "flex"; // Unhide the box
            } else {
                pressureBox.style.display = "none"; // Hide the box for older loggers
            }

            // --- 2. Map Renamed Modem Core Metric to the System Diagnostics Panel ---
            let liveModemTemp = parseFloat(data.modem_temp);
            
            document.getElementById('ui-modem-temp').innerText = isNaN(liveModemTemp) ? "--.-°C" : liveModemTemp.toFixed(1) + "°C";

            let totalLogs = sLogs + fLogs;
            if (totalLogs > 0) {
                let percentage = ((sLogs / totalLogs) * 100).toFixed(1);
                let rateElement = document.getElementById('ui-rate');
                rateElement.innerText = percentage + '%';
                
                if (percentage >= 95.0) { rateElement.style.color = '#27ae60'; }
                else if (percentage >= 85.0) { rateElement.style.color = '#f39c12'; }
                else { rateElement.style.color = '#e94560'; }
            } else {
                document.getElementById('ui-rate').innerText = '100.0%';
                document.getElementById('ui-rate').style.color = '#27ae60';
            }

        } catch (e) { console.log("Data sync glitch:", e); }
    }

    async function fetchHistoricalTracer() {
        try {
            const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            let response = await fetch('get_history.php?hours=' + activeHistoryHours + '&device_id=' + vessel + '&nocache=' + Date.now());
            if (!response.ok) return;
            let points = await response.json();

            historyLayerGroup.clearLayers();
            if (points.length === 0) return;

            let now = Date.now();
            let maxAgeMs = activeHistoryHours * 60 * 60 * 1000;

            for (let i = 0; i < points.length; i++) {
                let p = points[i];
                let pTime = new Date(p.timestamp.replace(' ', 'T')).getTime();
                let ageMs = now - pTime;

                let ageFraction = Math.min(1.0, Math.max(0.0, ageMs / maxAgeMs));
                let calculatedOpacity = 0.8 - (ageFraction * 0.75);

                L.circleMarker([p.lat, p.lng], {
                    radius: 5,
                    fillColor: '#e94560',
                    color: '#ffffff',
                    weight: 1,
                    fillOpacity: calculatedOpacity,
                    opacity: calculatedOpacity
                }).bindPopup("<b>Logged History Row</b><br>Time: " + p.timestamp).addTo(historyLayerGroup);

                if (i < points.length - 1) {
                    let nextPoint = points[i + 1];
                    L.polyline([[p.lat, p.lng], [nextPoint.lat, nextPoint.lng]], {
                        color: '#e94560',
                        weight: 3,
                        opacity: calculatedOpacity * 0.8 
                    }).addTo(historyLayerGroup);
                }
            }
        } catch (e) { console.log("History process hitch:", e); }
    }

    function changeHistoryWindow(hours) {
        activeHistoryHours = hours;
        document.getElementById('btn-1h').classList.remove('active');
        document.getElementById('btn-8h').classList.remove('active');
        document.getElementById('btn-24h').classList.remove('active');
        document.getElementById('btn-' + hours + 'h').classList.add('active');
        fetchHistoricalTracer();
    }

    // Background Thread Orchestrations
    syncAllData();
    setInterval(runHeartbeatClock, 1000);   
    setInterval(syncAllData, 15000);        
	
	// Initialize dropdown from saved state and ensure URL parameter matches
    window.onload = function() {
        const savedVessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
        document.getElementById('activeVessel').value = savedVessel;
        
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('device_id') !== savedVessel) {
            window.location.href = 'index.php?device_id=' + savedVessel;
        }
    };

    function switchVessel(id) {
        localStorage.setItem('activeVessel', id);
        window.location.href = 'index.php?device_id=' + id;
    }
    
    function updateAuthButtonUI() {
        let btn = document.getElementById('alert-auth-btn');
        if (Notification.permission === 'granted') {
            btn.className = 'auth-btn granted';
            btn.innerText = 'Alerts Armed ✓';
        } else if (Notification.permission === 'denied') {
            btn.className = 'auth-btn denied';
            btn.innerText = 'Alerts Blocked ✗';
        }
    }

    function requestAlertPermission() {
        // 1. Unlock the Web Audio Engine (Browsers require a physical click to allow sound)
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') audioCtx.resume();

        // 2. Request OS-level Push Notification permission
        if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission().then(permission => {
                updateAuthButtonUI();
            });
        } else {
            updateAuthButtonUI();
            alert("Audio unlocked. Notification permission is already " + Notification.permission + ".");
        }
    }

    // Replicate the exact 580/435 Hz pin-pon siren from the ESP32
    function synthesizeSirenTone() {
        if (!audioCtx) return;
        let osc = audioCtx.createOscillator();
        let gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        
        osc.type = 'square';
        let freq = (sirenStep % 2 === 0) ? 580 : 435;
        osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
        
        gain.gain.setValueAtTime(0.3, audioCtx.currentTime); // 30% Volume to avoid blowing speakers
        
        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 0.5); // Hold tone for 500ms
        
        sirenStep++;
    }

    function startLocalAlarm() {
        isAlarmActive = true;
        sirenStep = 0;
        
        // 1. Trigger Screen Takeover
        document.getElementById('alarm-overlay').style.display = 'flex';
        
        // 2. Trigger OS-Level Notification (if browser is minimized)
        if (Notification.permission === 'granted') {
            new Notification("🚨 GULLIVER ANCHOR DRAG 🚨", {
                body: "Vessel has drifted outside the geofence!",
                requireInteraction: true, // Forces user to manually dismiss it
                vibrate: [200, 100, 200, 100, 200, 100, 200]
            });
        }
        
        // 3. Trigger Audio Siren (500ms loop)
        if (audioCtx) {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            synthesizeSirenTone();
            sirenInterval = setInterval(synthesizeSirenTone, 500);
        }
    }

    function silenceLocalAlarm() {
        isAlarmActive = false;
        document.getElementById('alarm-overlay').style.display = 'none';
        if (sirenInterval) clearInterval(sirenInterval);
    }

    function acknowledgeAlarm() {
        // Suppress the UI alarm, but keep track so we don't re-trigger it on the next 15s refresh
        // It will re-arm itself automatically when the boatStatus leaves "alarm" mode.
        silenceLocalAlarm();
        alarmAcknowledgedForCurrentEvent = true;
        console.log("Alarm acknowledged. Muted until next distinct breach event.");
    }
    
    // Check permission state on load
    window.addEventListener('DOMContentLoaded', updateAuthButtonUI);
    
	
</script>

</body>
</html>
