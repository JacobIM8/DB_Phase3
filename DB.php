// this will intialize the connection between the database and the interface
<?php
$host = "127.0.0.1"; // Replace with your database host
$user = "root";
$pass = "";
$dbname = "shoeStore"; // Replace with your actual database name

// Create connection
$conn = mysqli_connect($host, $user, $pass, $dbname);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>
