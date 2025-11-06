<?php
require 'db_connection.php';

$sql = "SELECT email, password FROM users WHERE role = 'Digitador' LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "Email: " . $row['email'] . "\n";
    echo "Password: " . $row['password'] . "\n";
} else {
    echo "No user with role 'Digitador' found.";
}

$conn->close();
?>