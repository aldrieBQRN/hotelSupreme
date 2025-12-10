<?php
session_start();
include 'db_connect.php';

// Ensure the response is JSON
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);

    // --- DEMO ACCOUNT LOGIC ---
    // Allows access without database record
    if ($username === 'demo' && $password === 'demo') {
        $_SESSION['user_id'] = 9999; // Dummy ID
        $_SESSION['username'] = 'Demo Admin';
        $_SESSION['role'] = 'Admin';
        $_SESSION['logged_in'] = true;
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    // --------------------------

    // Query to find the user
    $sql = "SELECT * FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        
        if ($row['password'] === $password) {
            // Login Success
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['logged_in'] = true;
            
            // Return success JSON
            echo json_encode(['status' => 'success']);
        } else {
            // Return error JSON
            echo json_encode(['status' => 'error', 'message' => 'Invalid password. Please try again.']);
        }
    } else {
        // Return error JSON
        echo json_encode(['status' => 'error', 'message' => 'User not found. Please check your username.']);
    }
    exit; // Stop script execution here
} else {
    // Redirect if accessed directly without POST
    header("Location: index.php");
    exit;
}
?>