<?php

$_debug = strtolower((string)getenv('TRACKER_DEBUG')) === 'true';
error_reporting($_debug ? E_ALL : E_ERROR | E_WARNING);
ini_set('display_errors', $_debug ? '1' : '0');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$_GLOBALS['dir'] = dirname(__FILE__);                     
ini_set('include_path', $_GLOBALS['dir']);

require_once('../class/TrackerAPI.php');

// Requests from the same server don't have a HTTP_ORIGIN header
if (!array_key_exists('HTTP_ORIGIN', $_SERVER)) {
    $_SERVER['HTTP_ORIGIN'] = $_SERVER['SERVER_NAME'];
	}

try {
    $API = new TrackerAPI($_REQUEST['request'], $_SERVER['HTTP_ORIGIN']);
    echo $API->processAPI();
}	 
catch (Exception $e) {
    $status = (int)$e->getCode();
    if ($status < 100 || $status > 599) {
        $status = 500;
    }
    header("HTTP/1.1 " . $status);
    echo json_encode(Array("error" => $e->getMessage()));
}

?>