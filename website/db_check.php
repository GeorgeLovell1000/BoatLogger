<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>--- MARIADB HARDWARE CONNECTOR AUDIT ---</h3>";

echo "<b>Method 1 (Socket Link via 'localhost'):</b><br>";
$conn_local = @new mysqli("localhost", "YOUR_DB_USER", "YOUR_DB_PASSWORD", "YOUR_DB_NAME");
echo "Result: " . ($conn_local->connect_error ? "? FAILED (" . $conn_local->connect_error . ")" : "? SUCCESS!") . "<br><br>";

echo "<b>Method 2 (Network Link via '127.0.0.1'):</b><br>";
$conn_net = @new mysqli("127.0.0.1", "YOUR_DB_USER", "YOUR_DB_PASSWORD", "YOUR_DB_NAME");
echo "Result: " . ($conn_net->connect_error ? "? FAILED (" . $conn_net->connect_error . ")" : "? SUCCESS!") . "<br>";
