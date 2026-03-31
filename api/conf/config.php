<?php
// kannan asetukset
$host = 'localhost';
$db = 'rm_tracker';
$port = 3306;
$charset = 'utf8mb4';
$db_user = getenv('TRACKER_DB_USER') ?: '';
$db_passwd = getenv('TRACKER_DB_PASSWORD') ?: '';

if ($db_user === '' || $db_passwd === '') {
	throw new Exception('Database credentials are not configured. Set TRACKER_DB_USER and TRACKER_DB_PASSWORD.');
}

$dsn = 'mysql:host='.$host.';port='.$port.';charset='.$charset.';dbname='.$db;

// muut asetukset
$kirjautumisKeksi = 'RMTracker';
?>
