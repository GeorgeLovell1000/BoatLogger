<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/London');

// Verified Core Database Credentials
$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die(json_encode(["error" => "MySQL Connection Failed: " . $conn->connect_error]));
}

// --- NEW: FETCH DYNAMIC VESSEL LIST ---
$active_vessels = [];
$v_result = $conn->query("SELECT DISTINCT device_id FROM boat_logs WHERE device_id IS NOT NULL AND device_id != '' ORDER BY device_id ASC");
if ($v_result) {
    while($row = $v_result->fetch_assoc()) {
        $active_vessels[] = $row['device_id'];
    }
}
if (empty($active_vessels)) $active_vessels = ['YOUR_BOAT_ID'];

// ============================================================================
// --- BACKGROUND JSON APIs ---
// ============================================================================

// 1. JOURNEY EXTRACTOR API
if (isset($_GET['api']) && $_GET['api'] === 'journeys') {
    header('Content-Type: application/json');
    $device_id = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'YOUR_BOAT_ID';
    
    $query = "SELECT timestamp FROM boat_logs WHERE device_id = '$device_id' AND mode = 'travelling' ORDER BY timestamp ASC";
    $result = $conn->query($query);
    
    $journeys = [];
    $current_journey = null;
    $last_time = null;
    
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $t = strtotime($row['timestamp']);
            if ($last_time === null || ($t - $last_time) > 3600) {
                if ($current_journey) {
                    $current_journey['end'] = date('Y-m-d H:i:s', $last_time + 60);
                    $journeys[] = $current_journey;
                }
                $current_journey = ['start' => date('Y-m-d H:i:s', $t - 60)]; 
            }
            $last_time = $t;
        }
        if ($current_journey) {
             $current_journey['end'] = date('Y-m-d H:i:s', $last_time + 60);
             $journeys[] = $current_journey;
        }
    }
    
    echo json_encode(array_reverse($journeys));
    $conn->close();
    exit;
}

// 2. RAW TELEMETRY DATA API
if (isset($_GET['api']) && $_GET['api'] === 'data') {
    header('Content-Type: application/json');
    
    $start_date = isset($_GET['start']) ? $conn->real_escape_string($_GET['start']) : date('Y-m-d H:i:s', strtotime('-7 days'));
    $end_date = isset($_GET['end']) ? $conn->real_escape_string($_GET['end']) : date('Y-m-d H:i:s');
    $device_id = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'YOUR_BOAT_ID';

    // --- NEW: Added 'pressure' to the SQL SELECT query ---
    $query = "SELECT timestamp, connection_type, mode, lat, lng, voltage, temp, modem_temp, humidity, pressure, fence_lat, fence_lng, fence_radius 
              FROM boat_logs 
              WHERE device_id = '$device_id' AND timestamp >= '$start_date' AND timestamp <= '$end_date' 
              ORDER BY timestamp ASC";
              
    $result = $conn->query($query);
    $data = [];
    
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    echo json_encode($data);
    $conn->close();
    exit; 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>BoatLog Telemetry</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1e293b; padding-bottom: 15px; margin-bottom: 20px; }
        h1 { color: #38bdf8; margin: 0; font-size: 1.5rem; }
        
        .controls { background: #1e293b; padding: 15px; border-radius: 8px; display: flex; gap: 15px; align-items: flex-end; margin-bottom: 15px; flex-wrap: wrap; }
        .control-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; font-weight: bold; }
        input[type="datetime-local"], select { background: #0f172a; color: #e2e8f0; border: 1px solid #334155; padding: 8px; border-radius: 4px; }
        
        button.action-btn { background: #38bdf8; color: #0f172a; border: none; padding: 10px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        button.action-btn:hover { background: #0ea5e9; }

        .btn-group { display: flex; gap: 5px; height: 37px; }
        .range-btn { background: #0f172a; border: 1px solid #334155; color: #94a3b8; padding: 0 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: bold; transition: all 0.2s; }
        .range-btn:hover:not(.active) { background: #1e293b; color: #e2e8f0; }
        .range-btn.active { background: #38bdf8; color: #0f172a; border-color: #38bdf8; }

        .metrics-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
        .metric-card { background: #1e293b; padding: 8px 15px; border-radius: 6px; border-left: 4px solid #38bdf8; display: flex; align-items: center; gap: 8px; flex: 1 1 auto; justify-content: center; }
        .metric-label { font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; font-weight: bold; margin: 0; }
        .metric-value { font-size: 1.1rem; font-weight: bold; color: #f8fafc; margin: 0; }

        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .chart-container { background: #1e293b; padding: 15px; border-radius: 8px; position: relative; height: 300px; }
        .full-width { grid-column: 1 / -1; }
        
        #historyMap { 
            width: 100%; 
            aspect-ratio: 1 / 1; 
            max-height: 85vh; 
            z-index: 1; 
            border-radius: 8px; 
            border: 2px solid #334155; 
            margin-bottom: 20px; 
        }

        /* --- NEW: MAP LEGEND CSS --- */
		.info.legend {
            background: rgba(15, 23, 42, 0.9);
            padding: 6px 10px;        /* Reduced from 10px 14px */
            border-radius: 4px;       /* Slightly sharper corners */
            border: 1px solid #334155;
            color: #e2e8f0;
            font-size: 0.7rem;        /* Smaller text (was 0.85rem) */
            line-height: 1.3;         /* Tighter vertical spacing (was 1.6) */
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .info.legend i {
            width: 10px;              /* Smaller color swatches (was 14px) */
            height: 10px;             /* Smaller color swatches (was 14px) */
            float: left;
            margin-right: 6px;        /* Closer to the text */
            margin-top: 3px;
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            .charts-grid { grid-template-columns: 1fr; }
            .metrics-grid { flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>BoatLog Telemetry</h1>
    </div>

    <div class="controls">
        <div class="control-group" style="background:#0f172a; padding:10px; border-radius:6px; border:1px solid #334155;">
            <label style="color:#38bdf8;">Active Vessel</label>
            <select id="activeVessel" onchange="switchVessel(this.value)" style="border:none; outline:none; font-weight:bold; cursor:pointer; background:#0f172a; color:#fff;">
                <?php foreach ($active_vessels as $v): ?>
                    <option value="<?php echo htmlspecialchars($v); ?>">
                        <?php echo htmlspecialchars(ucfirst($v)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="control-group" style="background:#0f172a; padding:10px; border-radius:6px; border:1px solid #334155;">
            <label style="color:#10b981;">Recorded Journeys</label>
            <select id="journeySelect" onchange="loadSelectedJourney()" style="border:none; outline:none; font-weight:bold; cursor:pointer;">
                <option value="">-- Select a Journey --</option>
            </select>
        </div>

        <div class="control-group">
            <label>Quick Range</label>
            <div class="btn-group">
                <button class="range-btn" id="btn-1h" onclick="setTimeWindow(1, 'btn-1h')">1h</button>
                <button class="range-btn" id="btn-8h" onclick="setTimeWindow(8, 'btn-8h')">8h</button>
                <button class="range-btn" id="btn-24h" onclick="setTimeWindow(24, 'btn-24h')">24h</button>
                <button class="range-btn active" id="btn-168h" onclick="setTimeWindow(168, 'btn-168h')">7d</button>
            </div>
        </div>
        <div class="control-group">
            <label>Start Date</label>
            <input type="datetime-local" id="startDate" onchange="clearActiveButtons()">
        </div>
        <div class="control-group">
            <label>End Date</label>
            <input type="datetime-local" id="endDate" onchange="clearActiveButtons()">
        </div>
        <button class="action-btn" onclick="loadData()">Fetch Telemetry</button>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <span class="metric-label">Max Speed (Knots):</span>
            <span class="metric-value" id="maxSpeed">0.0</span>
        </div>
        <div class="metric-card" style="border-left-color: #facc15;">
            <span class="metric-label">Avg Speed (Knots):</span>
            <span class="metric-value" id="avgSpeed">0.0</span>
        </div>
        <div class="metric-card" style="border-left-color: #10b981;">
            <span class="metric-label">Distance Travelled (NM):</span>
            <span class="metric-value" id="totalDistanceNM">0.00</span>
        </div>
        <div class="metric-card" style="border-left-color: #8b5cf6;">
            <span class="metric-label">Time in Motion:</span>
            <span class="metric-value" id="totalTimeMoving">0h 0m</span>
        </div>
    </div>

    <div id="historyMap" class="full-width"></div>

    <div class="charts-grid">
        <div class="chart-container full-width">
            <canvas id="driftChart"></canvas>
        </div>
        <div class="chart-container full-width">
            <canvas id="tempChart"></canvas>
        </div>
        
        <div class="chart-container full-width" id="pressure-chart-container" style="display: none;">
            <canvas id="pressureChart"></canvas>
        </div>
        
        <div class="chart-container" style="height: 350px; display: flex; flex-direction: column;">
            <div style="flex-grow: 1; position: relative;"><canvas id="battChart"></canvas></div>
            <div class="metric-card" style="border-left-color: #f59e0b; margin-top: 15px; padding: 10px; justify-content: space-between;">
                <span class="metric-label">Min / Max SOC:</span>
                <span class="metric-value" id="minMaxSoc" style="font-size: 1rem;">0% / 0%</span>
            </div>
        </div>

        <div class="chart-container" style="height: 350px;">
            <canvas id="speedChart"></canvas>
        </div>
        
        <div class="chart-container full-width">
            <canvas id="humChart"></canvas>
        </div>
        
        <div class="chart-container full-width" style="height: auto; padding-bottom: 15px;">
            <div class="metric-card" style="border-left-color: #ef4444; justify-content: space-between; padding: 10px 15px; margin-bottom: 15px;">
                <span class="metric-label">Network Dropout Intensity (&lambda;):</span>
                <span class="metric-value" id="poissonLambda">0.0 <span style="font-size: 0.8rem; font-weight: normal; color: #94a3b8;">Failures/Hr</span></span>
            </div>
            <div style="height: 450px; position: relative;">
                <canvas id="locationChart"></canvas>
            </div>
            
            <div style="text-align: right; margin-top: 15px;">
                <a href="#" onclick="window.location.href='diagnoseUploads.php?device_id=' + (localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID')" class="action-btn" style="text-decoration: none; display: inline-block;">
                    🔍 Diagnose Missing Data & Gaps
                </a>
                <a href="#" onclick="window.location.href='viewlogslist.php?device_id=' + (localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID')" class="action-btn" style="text-decoration: none; display: inline-block; margin-left: 10px; background: #e94560; color: #fff;">
                    📋 View Raw Database List
                </a>                
            </div>
        </div>		
    </div>

    <script>
        let leafletMap, pathLayer, markerLayer;

        window.addEventListener('DOMContentLoaded', () => {
            const savedVessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            document.getElementById('activeVessel').value = savedVessel;
            
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('device_id') !== savedVessel) {
                window.location.href = 'viewlogs.php?device_id=' + savedVessel;
            } else {
                initMap();
                fetchJourneys();
                setTimeWindow(168, 'btn-168h');
            }
        });

        function switchVessel(id) {
            localStorage.setItem('activeVessel', id);
            window.location.href = 'viewlogs.php?device_id=' + id;
        }

        const formatForInput = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

        function setTimeWindow(hours, btnId) {
            let end = new Date();
            let start = new Date(end.getTime() - (hours * 60 * 60 * 1000));
            document.getElementById('startDate').value = formatForInput(start);
            document.getElementById('endDate').value = formatForInput(end);
            
            clearActiveButtons();
            if (document.getElementById(btnId)) document.getElementById(btnId).classList.add('active');
            document.getElementById('journeySelect').value = ""; 
            loadData();
        }

        function clearActiveButtons() {
            document.querySelectorAll('.range-btn').forEach(btn => btn.classList.remove('active'));
        }

        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371e3; 
            const f1 = lat1 * Math.PI/180, f2 = lat2 * Math.PI/180;
            const df = (lat2-lat1) * Math.PI/180, dl = (lon2-lon1) * Math.PI/180;
            const a = Math.sin(df/2) * Math.sin(df/2) + Math.cos(f1) * Math.cos(f2) * Math.sin(dl/2) * Math.sin(dl/2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        }

        function calculateBearing(lat1, lng1, lat2, lng2) {
            const toRad = Math.PI / 180;
            const toDeg = 180 / Math.PI;
            const l1 = lat1 * toRad, l2 = lat2 * toRad;
            const dl = (lng2 - lng1) * toRad;
            const y = Math.sin(dl) * Math.cos(l2);
            const x = Math.cos(l1) * Math.sin(l2) - Math.sin(l1) * Math.cos(l2) * Math.cos(dl);
            return (Math.atan2(y, x) * toDeg + 360) % 360;
        }

        // --- NEW: HELPER FUNCTION FOR CHEVRON COLORS ---
        function getSpeedColor(s) {
            return s > 8 ? '#ef4444' : // Red
                   s > 6 ? '#f59e0b' : // Orange
                   s > 4 ? '#facc15' : // Yellow
                   s > 2 ? '#10b981' : // Green
                           '#38bdf8';  // Blue
        }

        async function fetchJourneys() {
            const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            const cacheBuster = new Date().getTime();
            try {
                let response = await fetch(`viewlogs.php?api=journeys&device_id=${vessel}&cb=${cacheBuster}`);
                let journeys = await response.json();
                let select = document.getElementById('journeySelect');
                journeys.forEach(j => {
                    let opt = document.createElement('option');
                    let startStr = new Date(j.start.replace(' ', 'T')).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
                    opt.value = JSON.stringify(j);
                    opt.text = "Voyage: " + startStr;
                    select.appendChild(opt);
                });
            } catch (e) { console.error("Failed to load journeys."); }
        }

        function loadSelectedJourney() {
            const val = document.getElementById('journeySelect').value;
            if (!val) return;
            const j = JSON.parse(val);
            document.getElementById('startDate').value = j.start.replace(' ', 'T').slice(0, 16);
            document.getElementById('endDate').value = j.end.replace(' ', 'T').slice(0, 16);
            clearActiveButtons();
            loadData();
        }

        function initMap() {
            leafletMap = L.map('historyMap').setView([0.00000, 0.00000], 13);
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'Esri'
            }).addTo(leafletMap);
            pathLayer = L.polyline([], {color: '#38bdf8', weight: 3, opacity: 0.8}).addTo(leafletMap);
            markerLayer = L.layerGroup().addTo(leafletMap);

            // --- NEW: INJECT SPEED LEGEND INTO MAP ---
            let legend = L.control({position: 'bottomright'});
            legend.onAdd = function (map) {
                let div = L.DomUtil.create('div', 'info legend'),
                    grades = [0, 2, 4, 6, 8],
                    labels = ['<strong style="color:#38bdf8; letter-spacing:0.5px;">SPEED (KNOTS)</strong><br>'];

                for (let i = 0; i < grades.length; i++) {
                    labels.push(
                        '<i style="background:' + getSpeedColor(grades[i] + 0.1) + '"></i> ' +
                        grades[i] + (grades[i + 1] ? '&ndash;' + grades[i + 1] : '+')
                    );
                }
                div.innerHTML = labels.join('<br>');
                return div;
            };
            legend.addTo(leafletMap);
        }

        // --- NEW: ADDED pressureChart to the global tracking variables ---
        let tempChart, battChart, speedChart, humChart, locationChart, driftChart, pressureChart;

        async function loadData() {
            const startVal = document.getElementById('startDate').value.replace('T', ' ') + ':00';
            const endVal = document.getElementById('endDate').value.replace('T', ' ') + ':59';
            const vessel = localStorage.getItem('activeVessel') || 'YOUR_BOAT_ID';
            const cacheBuster = new Date().getTime();
            const url = `viewlogs.php?api=data&device_id=${vessel}&start=${startVal}&end=${endVal}&cb=${cacheBuster}`;

            try {
                const response = await fetch(url);
                const data = await response.json();
                processAndRender(data);
            } catch (e) {
                console.error("Data load failed:", e);
                alert("Failed to load telemetry data.");
            }
        }

        function processAndRender(data) {
            pathLayer.setLatLngs([]);
            markerLayer.clearLayers();

            if (!data || data.length === 0) {
                if (driftChart) driftChart.destroy();
                if (tempChart) tempChart.destroy();
                if (battChart) battChart.destroy();
                if (speedChart) speedChart.destroy();
                if (humChart) humChart.destroy();
                if (locationChart) locationChart.destroy();
                
                // UPDATE: WIPE ALL NEW CHARTS AND METRICS IF NO DATA
                if (pressureChart) pressureChart.destroy();
                document.getElementById('pressure-chart-container').style.display = 'none';
                
                document.getElementById('maxSpeed').innerText = '0.0';
                document.getElementById('avgSpeed').innerText = '0.0';
                document.getElementById('totalDistanceNM').innerText = '0.00';
                document.getElementById('totalTimeMoving').innerText = '0h 0m';
                document.getElementById('minMaxSoc').innerText = '0% / 0%';
                document.getElementById('poissonLambda').innerText = '0.0';
                return;
            }

            const timestamps = data.map(d => d.timestamp);
            const cabinTemps = data.map(d => parseFloat(d.temp));
            const modemTemps = data.map(d => parseFloat(d.modem_temp));
            const humidity = data.map(d => parseFloat(d.humidity));
            const batteryV = data.map(d => parseFloat(d.voltage));
            
            // --- NEW: Map Pressure Data and check for valid readings ---
            const pressureData = data.map(d => parseFloat(d.pressure));
            const hasValidPressure = pressureData.some(p => !isNaN(p) && p > 0);
            
            const socArray = batteryV.map(v => Math.max(0, Math.min(100, ((v - 11.5) / (12.8 - 11.5)) * 100)));
            let minSoc = 100;
            let maxSoc = 0;
            socArray.forEach(val => {
                if (val < minSoc) minSoc = val;
                if (val > maxSoc) maxSoc = val;
            });
            document.getElementById('minMaxSoc').innerText = `${Math.round(minSoc)}% / ${Math.round(maxSoc)}%`;

            // UPDATE: Include tracking variable for time in motion
            const speedData = [];
            const speedLabels = [];
            let maxSpeed = 0;
            let totalDistanceMeters = 0;
            let totalTravellingHours = 0;
            let gapFailures = 0;
            
            const driftData = [];
            const driftLabels = [];
            
            const locationDatasets = {};
            const colorPalette = ['#38bdf8', '#f59e0b', '#10b981', '#8b5cf6', '#f43f5e', '#14b8a6', '#facc15'];
            let colorIndex = 0;
            
            const mapCoords = [];

            for (let i = 1; i < data.length; i++) {
                const t1 = new Date(data[i-1].timestamp.replace(' ', 'T')).getTime();
                const t2 = new Date(data[i].timestamp.replace(' ', 'T')).getTime();
                const timeDiffHours = (t2 - t1) / 1000 / 3600;

                if (timeDiffHours > 0.5) gapFailures++;
                
                const lat1 = parseFloat(data[i-1].lat), lng1 = parseFloat(data[i-1].lng);
                const lat2 = parseFloat(data[i].lat), lng2 = parseFloat(data[i].lng);
                
                let knots = 0;
                let bearing = 0;

                // UPDATE: Accumulate travel hours within the loop
                if (timeDiffHours > 0) {
                    const distMeters = calculateDistance(lat1, lng1, lat2, lng2);
                    knots = (distMeters / 1852) / timeDiffHours;
                    bearing = calculateBearing(lat1, lng1, lat2, lng2);
                    
                    if (data[i].mode === 'travelling' && data[i-1].mode === 'travelling') {
                        totalDistanceMeters += distMeters;
                        totalTravellingHours += timeDiffHours;
                        
                        if (knots < 50) { 
                            speedData.push(knots);
                            speedLabels.push(data[i].timestamp);
                            if (knots > maxSpeed) maxSpeed = knots;
                        }
                    }
                }

                mapCoords.push([lat2, lng2]);
                
                if (knots > 0.5 && timeDiffHours < 1.0) {
                    // --- NEW: DYNAMIC CHEVRON COLOR BASED ON SPEED ---
                    const chevronColor = getSpeedColor(knots);
                    const svgIcon = L.divIcon({
                        className: 'custom-chevron',
                        html: `<svg width="18" height="18" viewBox="0 0 24 24" style="transform: rotate(${bearing}deg);"><path fill="${chevronColor}" stroke="#0f172a" stroke-width="1.5" d="M12 2L22 22L12 18L2 22Z" /></svg>`,
                        iconSize: [18, 18],
                        iconAnchor: [9, 9]
                    });
                    
                    L.marker([lat2, lng2], {icon: svgIcon})
                     .bindPopup(`<b>Time:</b> ${data[i].timestamp}<br><b>Speed:</b> ${knots.toFixed(1)} Knots<br><b>Heading:</b> ${Math.round(bearing)}°`)
                     .addTo(markerLayer);
                }
            }
            
            if (mapCoords.length > 0) {
                pathLayer.setLatLngs(mapCoords);
                leafletMap.fitBounds(pathLayer.getBounds(), {padding: [30, 30]});
            }

            data.forEach(d => {
                if (d.mode === 'anchored' && d.fence_lat !== null && d.fence_lng !== null) {
                    const driftMeters = calculateDistance(parseFloat(d.lat), parseFloat(d.lng), parseFloat(d.fence_lat), parseFloat(d.fence_lng));
                    driftData.push(driftMeters);
                    driftLabels.push(d.timestamp);
                }
                
                let cleanLink = d.connection_type || 'Unknown';
                if (cleanLink.includes('_SuspNetblock')) cleanLink = cleanLink.split('_')[0];
                
                if (!locationDatasets[cleanLink]) {
                    locationDatasets[cleanLink] = {
                        label: cleanLink, data: [],
                        backgroundColor: colorPalette[colorIndex % colorPalette.length],
                        borderColor: colorPalette[colorIndex % colorPalette.length],
                        pointRadius: 5, pointHoverRadius: 8
                    };
                    colorIndex++;
                }
                locationDatasets[cleanLink].data.push({ x: parseFloat(d.lng), y: parseFloat(d.lat) });
            });
            
            const scatterDataArray = Object.values(locationDatasets);            
            
            // UPDATE: Calculate Averages and Formatting
            const totalNM = totalDistanceMeters / 1852;
            const avgSpeedKnots = totalTravellingHours > 0 ? (totalNM / totalTravellingHours) : 0;
            const movingHrs = Math.floor(totalTravellingHours);
            const movingMins = Math.round((totalTravellingHours - movingHrs) * 60);

            document.getElementById('maxSpeed').innerText = maxSpeed.toFixed(1);
            document.getElementById('avgSpeed').innerText = avgSpeedKnots.toFixed(1);
            document.getElementById('totalDistanceNM').innerText = totalNM.toFixed(2);
            document.getElementById('totalTimeMoving').innerText = `${movingHrs}h ${movingMins}m`;

            const tStart = new Date(data[0].timestamp.replace(' ', 'T')).getTime();
            const tEnd = new Date(data[data.length-1].timestamp.replace(' ', 'T')).getTime();
            const hoursTotal = (tEnd - tStart) / (1000 * 3600);
            const lambda = hoursTotal > 0 ? (gapFailures / hoursTotal) : 0;
            document.getElementById('poissonLambda').innerText = lambda.toFixed(3);

            renderChart('driftChart', 'line', driftLabels, [{ label: 'Anchor Drift Distance (Meters)', data: driftData, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true, tension: 0.3 }]);
            renderChart('tempChart', 'line', timestamps, [{ label: 'Cabin Temp (°C)', data: cabinTemps, borderColor: '#38bdf8', tension: 0.4 }, { label: 'Modem Temp (°C)', data: modemTemps, borderColor: '#f43f5e', tension: 0.4 }]);
            
            // --- NEW: Render Pressure Chart dynamically ---
            if (hasValidPressure) {
                document.getElementById('pressure-chart-container').style.display = 'block';
                renderChart('pressureChart', 'line', timestamps, [{ label: 'Atmospheric Pressure (hPa)', data: pressureData, borderColor: '#14b8a6', backgroundColor: 'rgba(20, 184, 166, 0.1)', fill: true, tension: 0.4 }]);
            } else {
                document.getElementById('pressure-chart-container').style.display = 'none';
            }

            renderChart('battChart', 'line', timestamps, [{ label: 'Battery (V)', data: batteryV, borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, 0.1)', fill: true, tension: 0.2 }]);
            renderChart('speedChart', 'bar', speedLabels, [{ label: 'Speed (Knots)', data: speedData, backgroundColor: '#10b981' }]);
            renderChart('humChart', 'line', timestamps, [{ label: 'Relative Humidity (%)', data: humidity, borderColor: '#8b5cf6', backgroundColor: 'rgba(139, 92, 246, 0.1)', fill: true, tension: 0.4 }]);
            renderChart('locationChart', 'scatter', null, scatterDataArray);
        }

        function renderChart(canvasId, type, labels, datasets) {
            const ctx = document.getElementById(canvasId).getContext('2d');
            
            // --- NEW: Destroy Pressure Chart instance to prevent rendering overlaps ---
            if (canvasId === 'driftChart' && driftChart) driftChart.destroy();
            if (canvasId === 'tempChart' && tempChart) tempChart.destroy();
            if (canvasId === 'battChart' && battChart) battChart.destroy();
            if (canvasId === 'speedChart' && speedChart) speedChart.destroy();
            if (canvasId === 'humChart' && humChart) humChart.destroy();
            if (canvasId === 'locationChart' && locationChart) locationChart.destroy();
            if (canvasId === 'pressureChart' && pressureChart) pressureChart.destroy();

            Chart.defaults.color = '#94a3b8';
            
            let options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { grid: { color: '#334155' } },
                    y: { grid: { color: '#334155' } }
                }
            };
            
            if (type === 'line' || type === 'bar') {
                options.scales.x.ticks = { 
                    maxTicksLimit: 20,
                    maxRotation: 45,
                    minRotation: 45,
                    callback: function(value, index, values) {
                        const rawLabel = this.getLabelForValue(value);
                        if (!rawLabel) return '';
                        const date = new Date(rawLabel.replace(' ', 'T'));
                        const tStart = new Date(labels[0].replace(' ', 'T')).getTime();
                        const tEnd = new Date(labels[labels.length-1].replace(' ', 'T')).getTime();
                        const spanHours = (tEnd - tStart) / 3600000;
                        
                        if (spanHours <= 24) {
                            return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        } else if (spanHours <= 168) {
                            return date.toLocaleDateString([], {weekday: 'short'}) + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        } else {
                            return date.toLocaleDateString([], {day: '2-digit', month: 'short'});
                        }
                    }
                };
                options.elements = { point: { radius: 0, hitRadius: 10 } };
            } else if (type === 'scatter') {
                options.plugins.title = { display: true, text: 'Successful Upload Locations by Network Link', color: '#e2e8f0', font: { size: 16 } };
                options.scales.x.title = { display: true, text: 'Longitude', color: '#94a3b8' };
                options.scales.y.title = { display: true, text: 'Latitude', color: '#94a3b8' };
                options.elements = { point: { borderWidth: 1 } };
            }

            const chart = new Chart(ctx, {
                type: type,
                data: type === 'scatter' ? { datasets: datasets } : { labels: labels, datasets: datasets },
                options: options
            });

            // --- NEW: Map Pressure Chart instance back to the global tracker ---
            if (canvasId === 'driftChart') driftChart = chart;
            if (canvasId === 'tempChart') tempChart = chart;
            if (canvasId === 'battChart') battChart = chart;
            if (canvasId === 'speedChart') speedChart = chart;
            if (canvasId === 'humChart') humChart = chart;
            if (canvasId === 'locationChart') locationChart = chart;
            if (canvasId === 'pressureChart') pressureChart = chart;
        }
    </script>
</body>
</html>