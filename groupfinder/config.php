<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'brscouts_digital';
$db_pass = 'qet!*jTxKvsek63S8oz5$LQ2';
$db_name = 'brscouts_wp347';

// Create a database connection
$link = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($link->connect_error) {
    die("Connection failed: " . $link->connect_error);
}
?>
