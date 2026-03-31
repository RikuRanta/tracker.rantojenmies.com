<?php
 
$_debug = strtolower((string)getenv('TRACKER_DEBUG')) === 'true';
error_reporting($_debug ? E_ALL : E_ERROR | E_WARNING);
$_GLOBALS['dir'] = dirname(__FILE__);                     
ini_set('display_errors', $_debug ? '1' : '0');
ini_set('include_path', '/var/www/tracker.rantojenmies.com/');
set_time_limit (0);

require_once('class/Mysql.php');
require_once('class/Data.php');
require_once('class/Path.php');

$data = new Data();
$data_list = $data->ProcessData();
// if ($data_list['Prosessoitavaa'] > 0) 
$staging_list = $data->ProcessStaging();
$output['Dataa'] = $data_list['Prosessoitavaa'];

$touchedImeis = array();
$oldestTimestampByImei = array();
if (is_array($data_list) && isset($data_list['TouchedImeis']) && is_array($data_list['TouchedImeis'])) {
	foreach ($data_list['TouchedImeis'] as $imei) {
		if ($imei) $touchedImeis[$imei] = true;
	}
}
if (is_array($data_list) && isset($data_list['OldestTimestampByImei']) && is_array($data_list['OldestTimestampByImei'])) {
	foreach ($data_list['OldestTimestampByImei'] as $imei => $timestamp) {
		if ($imei && $timestamp && (!isset($oldestTimestampByImei[$imei]) || $timestamp < $oldestTimestampByImei[$imei])) {
			$oldestTimestampByImei[$imei] = $timestamp;
		}
	}
}
if (is_array($staging_list) && isset($staging_list['TouchedImeis']) && is_array($staging_list['TouchedImeis'])) {
	foreach ($staging_list['TouchedImeis'] as $imei) {
		if ($imei) $touchedImeis[$imei] = true;
	}
}
if (is_array($staging_list) && isset($staging_list['OldestTimestampByImei']) && is_array($staging_list['OldestTimestampByImei'])) {
	foreach ($staging_list['OldestTimestampByImei'] as $imei => $timestamp) {
		if ($imei && $timestamp && (!isset($oldestTimestampByImei[$imei]) || $timestamp < $oldestTimestampByImei[$imei])) {
			$oldestTimestampByImei[$imei] = $timestamp;
		}
	}
}

/* Päätetään keskeneräiset matkat */
$path = new Path();
$tiedot = $path->GetUnfinished();
$output['Matkoja'] = count($tiedot);	

if (count($tiedot) > 0) {
	
	foreach ($tiedot as $rivi) {
		if (!empty($rivi['Imei'])) $touchedImeis[$rivi['Imei']] = true;
		
		/* Luodaan koonti useasta matkasta */
		if ($rivi['Group']) {
			
			/* Generoidaan kml-tieto */
			$path->GenerateGroupKml($rivi['Id'], $rivi['Imei']);
	
		}
		
		/* Normaalin matkan päättäminen */
		else {
		
			/* Päätetään matka viimeiseen pisteeseen */
			$path->EndPath($rivi['Imei'], $rivi['End']);
			
			/* Generoidaan kml-tieto */
			$path->GenerateKml($rivi['Id'], $rivi['Imei']);
		
		}
		
	}
	
}

	if (count($touchedImeis) === 0) {
		$repairImeis = $path->GetImeisWithIncompletePathKml();
		if (is_array($repairImeis) && count($repairImeis) > 0) {
			foreach ($repairImeis as $imei) {
				if ($imei) $touchedImeis[$imei] = true;
			}
		}
	}

	/* Korjataan puuttuvat/rebuildaamatta jääneet normaalit matka-KML:t */
	if (count($touchedImeis) > 0) {
		foreach (array_keys($touchedImeis) as $imei) {
			$fromTimestamp = isset($oldestTimestampByImei[$imei]) ? $oldestTimestampByImei[$imei] : null;
			$path->RedoIncompletePathKml($imei, $fromTimestamp);
		}
	}

	/* Uudelleenrakennetaan koontimatkat vain kosketetuille IMEI-laitteille */
	if (count($touchedImeis) > 0) {
		foreach (array_keys($touchedImeis) as $imei) {
			$fromTimestamp = isset($oldestTimestampByImei[$imei]) ? $oldestTimestampByImei[$imei] : null;
			$path->RedoGroupKml($imei, $fromTimestamp);
		}
	}

print json_encode($output);
