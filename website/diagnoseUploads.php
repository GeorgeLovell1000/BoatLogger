<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/London');

$db_host = '127.0.0.1';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Database Connection Failed."); }

$device_id = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'YOUR_BOAT_ID';
// Pull the last 7 days of data to analyze recent gaps
$start_date = date('Y-m-d H:i:s', strtotime('-7 days'));

$query = "SELECT timestamp, mode, connection_type FROM boat_logs 
          WHERE device_id = '$device_id' AND timestamp >= '$start_date' 
          ORDER BY timestamp ASC";
$result = $conn->query($query);

$logs = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnostic Deck: Gap Analysis</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; margin: 0; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1e293b; padding-bottom: 15px; margin-bottom: 20px; }
        h1 { color: #f43f5e; margin: 0; font-size: 1.5rem; }
        a.back-btn { background: #334155; color: #e2e8f0; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold; }
        a.back-btn:hover { background: #475569; }
        
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-container { background: #1e293b; padding: 15px; border-radius: 8px; position: relative; height: 350px; }
        .full-width { grid-column: 1 / -1; }
        
        table { border-collapse: collapse; width: 100%; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #334155; }
        th { background-color: #f43f5e; color: #0f172a; text-transform: uppercase; font-size: 0.85rem; }
        tr:nth-child(even) { background-color: #0f172a; }
        tr:hover { background-color: #334155; }
        
        @media (max-width: 768px) { .charts-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div class="header">
        <h1>Network Gap & Failure Diagnostics (Last 7 Days)</h1>
        <a href="viewlogs.php" class="back-btn">&larr; Back to Main Deck</a>
    </div>

    <div class="charts-grid">
        <div class="chart-container">
            <canvas id="histogramChart"></canvas>
        </div>
        <div class="chart-container">
            <canvas id="timelineChart"></canvas>
        </div>
    </div>

    <h2 style="color: #94a3b8; font-size: 1.2rem; margin-top: 30px;">Identified Data Gaps (>15 Minutes)</h2>
    <table id="gapTable">
        <tr>
            <th>Last Successful Log</th>
            <th>Next Successful Log</th>
            <th>Total Gap (Minutes)</th>
            <th>Estimated Missed Uploads</th>
            <th>Vessel Mode</th>
        </tr>
    </table>

    <script>
        const rawData = <?php echo json_encode($logs); ?>;
        
        // 1. Process Data into Time Deltas
        const gaps = [];
        const timelineLabels = [];
        const timelineData = [];
        
        // Histogram Bins (in minutes)
        const bins = { "0-6 (Normal)": 0, "7-12 (1 Miss)": 0, "13-25 (2-3 Misses)": 0, "26-60 (Long Outage)": 0, "60+ (Offline)": 0 };

        for(let i = 1; i < rawData.length; i++) {
            const t1 = new Date(rawData[i-1].timestamp.replace(' ', 'T'));
            const t2 = new Date(rawData[i].timestamp.replace(' ', 'T'));
            const diffMins = (t2 - t1) / 60000;
            
            if (diffMins > 0) {
                timelineLabels.push(rawData[i].timestamp);
                timelineData.push(diffMins);
                
                // Categorize for Histogram
                if (diffMins <= 6) bins["0-6 (Normal)"]++;
                else if (diffMins <= 12) bins["7-12 (1 Miss)"]++;
                else if (diffMins <= 25) bins["13-25 (2-3 Misses)"]++;
                else if (diffMins <= 60) bins["26-60 (Long Outage)"]++;
                else bins["60+ (Offline)"]++;

                // Log significant anomalies to the table
                if (diffMins > 15) {
                    let estimatedMissed = Math.floor(diffMins / 5) - 1; // Assuming a rough 5 min baseline
                    gaps.push({
                        start: rawData[i-1].timestamp,
                        end: rawData[i].timestamp,
                        duration: diffMins.toFixed(1),
                        missed: estimatedMissed > 0 ? estimatedMissed : "Unknown",
                        mode: rawData[i-1].mode
                    });
                }
            }
        }

        // 2. Populate the Table
        const table = document.getElementById('gapTable');
        // Sort gaps by duration (longest first)
        gaps.sort((a, b) => b.duration - a.duration).forEach(gap => {
            const row = table.insertRow();
            row.innerHTML = `<td>${gap.start}</td><td>${gap.end}</td><td style="color:#f43f5e; font-weight:bold;">${gap.duration}</td><td>${gap.missed}</td><td>${gap.mode.toUpperCase()}</td>`;
        });

        // 3. Render Chart.js
        Chart.defaults.color = '#94a3b8';
        
        // Histogram Chart
        new Chart(document.getElementById('histogramChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: Object.keys(bins),
                datasets: [{
                    label: 'Frequency of Time Deltas',
                    data: Object.values(bins),
                    backgroundColor: ['#10b981', '#facc15', '#f59e0b', '#ef4444', '#991b1b']
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Time Between Uploads (Histogram)', color: '#e2e8f0' } },
                scales: { y: { type: 'logarithmic', title: { display: true, text: 'Count (Log Scale)' } } } // Log scale because normal pings will vastly outnumber errors
            }
        });

        // Timeline Spikes Chart
        new Chart(document.getElementById('timelineChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: timelineLabels,
                datasets: [{
                    label: 'Minutes Since Previous Upload',
                    data: timelineData,
                    borderColor: '#f43f5e',
                    backgroundColor: 'rgba(244, 63, 94, 0.1)',
                    fill: true,
                    tension: 0.1,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Timeline of Network Outages', color: '#e2e8f0' } },
                scales: { 
                    x: { ticks: { maxTicksLimit: 10 } },
                    y: { title: { display: true, text: 'Gap Duration (Minutes)' } }
                }
            }
        });
    </script>
</body>
</html>