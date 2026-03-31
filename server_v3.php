<?php

/* Määritellään paikallinen polku */
$_GLOBALS['dir'] = dirname(__FILE__);  

set_time_limit (0);             
$_debug = strtolower((string)getenv('TRACKER_DEBUG')) === 'true';
ini_set('display_errors', $_debug ? '1' : '0');
ini_set('include_path', $_GLOBALS['dir']);
error_reporting($_debug ? E_ALL : E_ERROR | E_WARNING);

try {
	require_once('class/Mysql.php');
	require_once('class/Data.php');

	/* Palvelimen tiedot */
	$address = '95.217.234.84';
	$port = 4096;
	$timeout = 90;
	$maxClients = (int)(getenv('TRACKER_SOCKET_MAX_CLIENTS') ?: 100);
	$maxFrameBytes = (int)(getenv('TRACKER_SOCKET_MAX_FRAME_BYTES') ?: 500);
	$maxRecordsPerFrame = (int)(getenv('TRACKER_SOCKET_MAX_RECORDS_PER_FRAME') ?: 25);
	if ($maxClients < 1) $maxClients = 1;
	if ($maxFrameBytes < 100) $maxFrameBytes = 100;
	if ($maxFrameBytes > 4096) $maxFrameBytes = 4096;
	if ($maxRecordsPerFrame < 1) $maxRecordsPerFrame = 1;
	if ($maxRecordsPerFrame > 100) $maxRecordsPerFrame = 100;

	/* Käynnistetään palvelin */
	$server = stream_socket_server("tcp://$address:$port", $errno, $errorMessage);

	if ($server === false) {
		throw new UnexpectedValueException("Could not bind to socket: $errorMessage");
	}
	else {
		echo "GPS Tracker Service started! Listening to port $port...\r\n";
	}

	$client_socks = array();
	$client_log = array();

	$closeClient = function($sock, $reason = '') use (&$client_socks, &$client_log) {
		$clientId = (int)$sock;
		$idx = array_search($sock, $client_socks, true);
		if ($idx !== false) unset($client_socks[$idx]);
		if (isset($client_log[$clientId])) unset($client_log[$clientId]);
		@fclose($sock);
		if ($reason !== '') echo "SERVER: $reason. Now there are total ".count($client_socks)." clients.\r\n";
	};

	/* Odotetaan yhteydenottoja */
	while (true) {
		
		$curtime = time();	

		/* Timeout-tarkistus */
		foreach($client_socks as $sock) {
			$clientId = (int)$sock;
			if (!isset($client_log[$clientId]['lastSent'])) {
				$closeClient($sock, "A client($clientId) had invalid state and was dropped");
				continue;
			}
			
			/* Suljetaan yhteys, mikäli viimeisimmästä lähetyksestä on liian pitkä aika */
			if (($curtime - $client_log[$clientId]['lastSent']) > $timeout) {
				$closeClient($sock, "A client($clientId) connection timed out");
				continue;
			}		
		}	

		/* Määritellään asiakkaat */
		$read_socks = $client_socks;
		$read_socks[] = $server;
		
		/* Kuunnellaan striimiä */
		if (false === stream_select($read_socks, $write, $except, 5)) {
			echo "SERVER: Something went wrong while selecting streams!\r\n";
			continue;
		}
		
		if (in_array($server, $read_socks)) {
			
			/* Uusi asiakas? Hyväksytään yhteys */
			$new_client = @stream_socket_accept($server);
			
			/* Lisätään asiakas seurattavien joukkoon */
			if ($new_client) {	
				if (count($client_socks) >= $maxClients) {
					@fclose($new_client);
					echo "SERVER: Connection refused (max clients reached).\r\n";
					$serverIdx = array_search($server, $read_socks, true);
					if ($serverIdx !== false) unset($read_socks[$serverIdx]);
					continue;
				}
				
				$client_socks[] = $new_client;
				stream_set_blocking($new_client, false);
				$clientId = (int)$new_client;
				$client_log[$clientId] = array(
					'lastSent' => time(),
					'data' => new Data(),
				);

				echo "SERVER: Connection accepted from ".stream_socket_get_name($new_client, true)."($clientId). ";
				echo "Now there are total ".count($client_socks)." clients.\r\n";
			}
			 
			/* Poistetaan palvelimen socket seurattavien joukosta */
			$serverIdx = array_search($server, $read_socks, true);
			if ($serverIdx !== false) unset($read_socks[$serverIdx]);
		}
			
		/* Käydään asiakkaiden striimit läpi */
		foreach($read_socks as $sock) {
			$clientId = (int)$sock;
			try {
				if (!isset($client_log[$clientId])) {
					$closeClient($sock, "Unknown client state ($clientId)");
					continue;
				}
						
				/* Luetaan tiedot */
				$buf = fread($sock, $maxFrameBytes);
				$client_log[$clientId]['lastSent'] = time();

				/* Jollei tietojen lukeminen onnistu, tulkitaan että yhteys asiakkaaseen on suljettu */
				if(!$buf) {
					$closeClient($sock, "A client($clientId) disconnected");
					continue;
				}

				/* Suojataan logi- ja parserikuormaa */
				if (strlen($buf) >= $maxFrameBytes) {
					$closeClient($sock, "Client($clientId) sent oversized frame");
					continue;
				}

				$buf = trim($buf);
				if ($buf === '') continue;
				$logBuf = preg_replace('/[^\x20-\x7E]/', '?', $buf);
				if (strlen($logBuf) > 300) $logBuf = substr($logBuf, 0, 300).'...';
				
				/* Näytetään viestin sisältö */
				echo "CLIENT($clientId): $logBuf\r\n";
				
				/* Tulkitaan viesti: laite ilmoittaa itsestään */
				if (substr($buf, 0, 2) == '##') {
					
					/* Lähetetään vastaukseksi latauspyyntö */
					$msg = "LOAD";
					$fwrite = fwrite($sock, $msg);
					echo "REPLY @$clientId: $msg\r\n";
					continue;
				}
				
				/* Tulkitaan viesti: tunnistetaan sijaintitieto */
				elseif (substr($buf, 0, 4) == 'imei') {

					/* Jos datassa tulee useampi sijaintitieto kerrallaan, niin pureksitaan se taulukkoon */
					$gps_data = explode(';', $buf);
					if (count($gps_data) > $maxRecordsPerFrame) {
						$closeClient($sock, "Client($clientId) exceeded max records per frame");
						continue;
					}

					foreach ($gps_data as $data) {
						/* Kirjoitetaan tieto kantaan */
						if ($data !== '' && strpos($data, 'imei') === 0) {
							$client_log[$clientId]['data']->InsertData($data);
						}
					}
					
					/* Lähetetään ON-viesti, jotta yhteys säilyy */
					$msg = "ON";
					$fwrite = fwrite($sock, $msg);
					echo "REPLY @$clientId: $msg\r\n";
					
					continue;
				}

				/* Tuntematon kehys -> suljetaan yhteys */
				$closeClient($sock, "Client($clientId) sent unknown frame type");
			}
			catch (Exception $clientEx) {
				$handle = fopen("/var/www/tracker.rantojenmies.com/log/tracker_service.log","a+");
				if ($handle) {
					$aika = date("Y-m-d H:i:s");
					$lokirivi = $aika.' - Client('.$clientId.') error: '.$clientEx->getMessage()."\n";
					fwrite($handle, $lokirivi);
					fclose($handle);
				}
				$closeClient($sock, "Client($clientId) dropped after processing error");
				continue;
			}
			
		}

	}
	fclose($server);
}
catch(Exception $e) {
	$handle = fopen("/var/www/tracker.rantojenmies.com/log/tracker_service.log","a+");
	$aika = date("Y-m-d H:i:s");	
	$lokirivi = $aika.' - Error: '.$e->getMessage().'
';	
	fwrite($handle,$lokirivi);
	fclose($handle);
	echo "SERVER: Fatal error logged, service stopped.\r\n";
}
?>