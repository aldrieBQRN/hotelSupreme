<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "hotel_supreme";

// $servername = "sql104.infinityfree.com";
// $username = "if0_40637763";
// $password = "hotelsupreme15";
// $dbname = "if0_40637763_hotel_supreme";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
date_default_timezone_set('Asia/Manila');
?>