<?php

/**
 * @author 
 * @copyright 2012
 */

require_once('Math.php');
 
class Data {

	protected $points = '';


	protected function GetProcessingChunkSize($test = false) {

		$default = $test ? 500 : 5000;
		$envKey = $test ? 'TRACKER_PROCESS_CHUNK_SIZE_TEST' : 'TRACKER_PROCESS_CHUNK_SIZE';
		$envVal = getenv($envKey);

		if ($envVal === false || trim((string)$envVal) === '') {
			return $default;
		}

		$chunk = (int)$envVal;
		if ($chunk < 100) $chunk = 100;
		if ($chunk > 50000) $chunk = 50000;

		return $chunk;

	}


	public function GetUnprocessedDataCount() {

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("SELECT COUNT(*) AS `Cnt` FROM Data WHERE Processed='0';");

		try {
			$sql->execute();
			$tiedot = $sql->fetchAll();
			return (int)$tiedot[0]['Cnt'];
		}
		catch (PDOException $e) {
			echo $e->getMessage();
			return 0;
		}

	}

	
    public function GetUnprocessedData($limit = null) {

        $yhteys = new Mysql();
		$stmt = "SELECT Id, Input FROM Data WHERE Processed='0' ORDER BY Id";
		if ($limit !== null) $stmt .= " LIMIT ".(int)$limit;
		$stmt .= ";";
        $sql = $yhteys->prepare($stmt);

        try {
            $sql->execute();
            $tiedot = $sql->fetchAll();
            return $tiedot;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }

	
    public function ResetUnprocessedStaging() {

        $yhteys = new Mysql();

		/* Palautetaan Staging-data takaisin jonoon (jos sellaista löytyy) */
		$sql_update = $yhteys->prepare("			
			UPDATE DataStaging s
			INNER JOIN Devices d ON s.Imei = d.Imei
			SET s.Processed = '0'
			WHERE s.Timestamp > d.LastUpdated
				AND d.DeleteNewer = '1';"); 

        try {
			$sql_update->execute();
			return 1;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }


	public function GetUnprocessedStagingCount() {

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("SELECT COUNT(*) AS `Cnt` FROM DataStaging WHERE Processed='0';");

		try {
			$sql->execute();
			$tiedot = $sql->fetchAll();
			return (int)$tiedot[0]['Cnt'];
		}
		catch (PDOException $e) {
			echo $e->getMessage();
			return 0;
		}

	}


    public function GetUnprocessedStaging($limit = null, $resetQueue = true) {

        $yhteys = new Mysql();

		if ($resetQueue) $this->ResetUnprocessedStaging();

		$stmt = "
			SELECT 
				  s.Id
				, s.Imei
				, s.Lat
				, s.Lon
				, s.Timestamp
				, s.Speed 
			FROM DataStaging s
			LEFT JOIN DataArchive a ON a.DataStaging_Id = s.Id
			WHERE s.Processed='0'
				AND a.Id IS NULL
			ORDER BY s.Imei, s.Timestamp";
		if ($limit !== null) $stmt .= " LIMIT ".(int)$limit;
		$stmt .= ";";
		$sql_select = $yhteys->prepare($stmt);

		try {
			$sql_select->execute();
			$tiedot = $sql_select->fetchAll();
			return $tiedot;
		}
		catch (PDOException $e) {
			echo $e->getMessage();
			return 0;
		}

    }


	public function GetPlaces() {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("SELECT Id, Name, NameUrl, Lat, Lon, Radius, `Rename` FROM Places;");

        try {
            $sql->execute();
            $tiedot = $sql->fetchAll();
            return $tiedot;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }

	
	/**
	* reverse geocoding via google maps api
	* convert lat/lon into a name
	*/
	function reverse_geocode($lat, $lon) {
		$url = "http://maps.google.com/maps/api/geocode/json?latlng=$lat,$lon&sensor=false";
		$data = json_decode(file_get_contents($url));
		if (!isset($data->results[0]->formatted_address)){
			return "Tuntematon paikka";
		}
		return $data->results[0]->formatted_address;
	}
	
	
    public function InsertData($data) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("INSERT Data (Input) SELECT :data;");
        $sql->bindParam(':data', $data, PDO::PARAM_STR);

        try {
            $sql->execute();
            return 1;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }

	
    public function InsertPlace($piste, $imei) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("INSERT Places (Name, NameUrl, Lon, Lat, Owner) SELECT :name, :nameUrl, :lon, :lat, :imei;");
        $sql->bindParam(':name', $piste['Name'], PDO::PARAM_STR);
        $sql->bindParam(':nameUrl', $piste['NameUrl'], PDO::PARAM_STR);		
        $sql->bindParam(':lon', $piste['Lon'], PDO::PARAM_STR);
        $sql->bindParam(':lat', $piste['Lat'], PDO::PARAM_STR);
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);

        try {
            $sql->execute();
            $new_Id = $yhteys->lastInsertId();
            return $new_Id;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }
	
	
    public function UpdateProcessedData($id, $info = null) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("UPDATE Data SET Processed='1', Info=:info WHERE Id=:id;");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
        $sql->bindParam(':info', $info, PDO::PARAM_STR);

        try {
            $sql->execute();
            return 1;
		}
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
		}            

    }	
 	
	
    public function UpdateProcessedStaging($id) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("UPDATE DataStaging SET Processed='1' WHERE Id=:id;");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);

        try {
            $sql->execute();
            return 1;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }	


	public function UpdateProcessedStagingBatch($ids) {

		if (!is_array($ids) || count($ids) === 0) return 1;

		$ids = array_values(array_unique(array_map('intval', $ids)));
		$placeholders = implode(',', array_fill(0, count($ids), '?'));

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("UPDATE DataStaging SET Processed='1' WHERE Id IN ($placeholders);");

		try {
			$sql->execute($ids);
			return 1;
			}
		catch (PDOException $e) {
			echo $e->getMessage();
			return 0;
			}

	}	
	
	
	public function InsertStaging($data) {


		$stmt = "INSERT DataStaging (Data_Id, Imei, Lat, Lon, Lat_DMS, Lon_DMS, `Timestamp`, Speed) 
		SELECT :id, :imei, :lat, :lon, :lat_dms, :lon_dms, :timestamp, :speed
		ON DUPLICATE KEY UPDATE Imei=:imei, Lat=:lat, Lon=:lon, Lat_DMS=:lat_dms, Lon_DMS=:lon_dms, `Timestamp`=:timestamp, Speed=:speed";
		//  9.5.2020: Poistettu ajan muunnos DATE_ADD(:timestamp,INTERVAL 1 HOUR)

        $yhteys = new Mysql();
        $sql = $yhteys->prepare($stmt);
			
        $params[':id'] = $data['Id'];
        $params[':imei'] = $data['Imei'];
        $params[':lat'] = $data['Lat'];
        $params[':lon'] = $data['Lon'];
        $params[':lat_dms'] = $data['Lat_DMS'];
        $params[':lon_dms'] = $data['Lon_DMS'];
        $params[':timestamp'] = $data['Timestamp'];
        $params[':speed'] = $data['Speed'] ? $data['Speed'] : 0;

		/*
		$debug = $stmt;
		foreach ($params as $key=>$value) $debug = str_replace($key, "'".$value."'", $debug);
		print $debug;
		*/

        try {
            $sql->execute($params);
			$stg_Id = $yhteys->lastInsertId();
            return $stg_Id;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }

	
	public function InsertArchive($data) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("
			INSERT DataArchive (DataStaging_Id, Imei, Lat, Lon, Timestamp, Distance, Bearing, Speed, Speed_avg, Path_Id) 
			SELECT :id, :imei, :lat, :lon, :timestamp, :distance, :bearing, :speed, :speed_avg, :path_Id;");
			
        $sql->bindParam(':id', $data['Id'], PDO::PARAM_INT);
        $sql->bindParam(':imei', $data['Imei'], PDO::PARAM_STR);
        $sql->bindParam(':lat', $data['Lat'], PDO::PARAM_STR);
        $sql->bindParam(':lon', $data['Lon'], PDO::PARAM_STR);
        $sql->bindParam(':timestamp', $data['Timestamp'], PDO::PARAM_STR);
        $sql->bindParam(':distance', $data['Distance'], PDO::PARAM_STR);
        $sql->bindParam(':bearing', $data['Bearing'], PDO::PARAM_STR);
        $sql->bindParam(':speed_avg', $data['Speed_avg'], PDO::PARAM_STR);
        $sql->bindParam(':speed', $data['Speed'], PDO::PARAM_STR);
        $sql->bindParam(':path_Id', $data['Path_Id'], PDO::PARAM_INT);

        try {
            $sql->execute();
			$stg_Id = $yhteys->lastInsertId();
            return $stg_Id;
            }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
            }            

    }

	
    public function GetPrevious($data) {

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("
			SELECT 
				  Id
				, Lat
				, Lon
				, Timestamp 
				, Distance
				, Speed
				, Speed_avg
				, Path_Id
				, Path_Info
			FROM DataArchive 
			WHERE Imei=:imei 
				AND Timestamp <= :timestamp 
			ORDER BY Timestamp DESC, Id DESC 
			LIMIT 1;
			");
		$sql->bindParam(':imei', $data['Imei'], PDO::PARAM_STR);
		$sql->bindParam(':timestamp', $data['Timestamp'], PDO::PARAM_STR);
		
		try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
			return count($tiedot) > 0 ? $tiedot[0] : '';
        }
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
        }            

    }
	
	
	public function ProcessData($test = false) {

		$chunkSize = $this->GetProcessingChunkSize($test);
		$touchedImeis = array();
		$oldestTimestampByImei = array();

		/* Haetaan prosessoitavien tietojen määrä */
		$prosessoitavaa = $this->GetUnprocessedDataCount();

		$output = array();
		$output['Prosessoitavaa'] = $prosessoitavaa;
		
		/* Löytyikö? */
		if ($prosessoitavaa > 0) {

			try {

				do {

					/* Haetaan chunkki prosessoitavia tietoja */
					$tiedot = $this->GetUnprocessedData($chunkSize);
					$chunkCount = is_array($tiedot) ? count($tiedot) : 0;

					/* Käydään tiedot läpi */
					foreach ($tiedot as $rivi) {

						/* Pureskellaan laitteen lähettämä tieto */
						$record = explode(',', $rivi['Input']);

						/* Onko kyseessä validi sijaintitieto? */
						if ($record[6] == "A" && ($record[1] == 'tracker' || $record[1] == 'qt') && $record[2] != '') {

							unset($insert);
						
							$insert['Id'] = $rivi['Id'];
							$insert['Imei'] = substr($record[0], 5);
							$touchedImeis[$insert['Imei']] = true;
							$tstamp = date_create_from_format('ymdHis', $record[2]);
							$insert['Timestamp'] = date_format($tstamp, 'Y-m-d H:i:s');
							if (!isset($oldestTimestampByImei[$insert['Imei']]) || $insert['Timestamp'] < $oldestTimestampByImei[$insert['Imei']]) {
								$oldestTimestampByImei[$insert['Imei']] = $insert['Timestamp'];
							}

							/* Muunnetaan koordinaatit asteiksi */
							$lat_notatiton = strtolower($record[8]) == 's' ? '-' : '';
							$whole = substr($record[7], 0, 2)*1;
							$decimal = (substr($record[7], 2, 10)*1)/60;
							$insert['Lat_DMS'] = $record[7];
							$insert['Lat'] = $lat_notatiton.($whole+$decimal);
							
							$lon_notatiton = strtolower($record[10]) == 'w' ? '-' : '';
							$whole = substr($record[9], 0, 3)*1;
							$decimal = (substr($record[9], 3, 10)*1)/60;
							$insert['Lon_DMS'] = $record[9];
							$insert['Lon'] = $lon_notatiton.($whole+$decimal);

							$insert['Speed'] = $record[11];
						
							// print json_encode($lat_len);

							/* Kirjoitetaan pisteen tiedot kantaan */
							$this->InsertStaging($insert);
							$info = null;

						}
						else {
							$info = "Invalid data";
						}

						/* Merkataan tieto prosessoiduksi */
						$this->UpdateProcessedData($rivi['Id'], $info);

					}

				} while ($chunkCount > 0);

			} catch (Exception $e) {
				$output = $e->getMessage();
			}

		}        

		$output['TouchedImeis'] = array_keys($touchedImeis);
		$output['OldestTimestampByImei'] = $oldestTimestampByImei;

		return $output;
    }	


	
	public function ProcessStaging($test = false, $deferKml = true) {

		$chunkSize = $this->GetProcessingChunkSize($test);
		$touchedImeis = array();
		$oldestTimestampByImei = array();

		/* Palautetaan out-of-order staging-data takaisin jonoon */
		$this->ResetUnprocessedStaging();

		/* Haetaan prosessoitavien tietojen määrä */
		$prosessoitavaa = $this->GetUnprocessedStagingCount();
		
		$output = array();		
		$output['Prosessoitavaa'] = $prosessoitavaa;
		
		/* Löytyikö? */
		if ($prosessoitavaa > 0) {

			try {

				$path = new Path();
				$math = new Math();
				$kmlQueue = array();
				
				/* Määritellään matkan aikakatkaisu, oletuksena 2 tuntia */
				$path_timeout = 7200;

				do {
					/* Haetaan chunkki prosessoitavia staging-rivejä */
					$tiedot = $this->GetUnprocessedStaging($chunkSize, false);
					$chunkCount = is_array($tiedot) ? count($tiedot) : 0;
					$previousByImei = array();
					$pathLastTimestampByPathId = array();
					$processedIds = array();

					/* Käydään tiedot läpi */
					foreach ($tiedot as $insert) {
					$imei = $insert['Imei'];
					$touchedImeis[$imei] = true;
					if (!isset($oldestTimestampByImei[$imei]) || $insert['Timestamp'] < $oldestTimestampByImei[$imei]) {
						$oldestTimestampByImei[$imei] = $insert['Timestamp'];
					}
					
					/*
					 *  LASKENTA
					 */
					
					/* Haetaan edellisen reittipisteen tiedot vain kerran per IMEI/chunk */
					if (!array_key_exists($imei, $previousByImei)) {
						$previousByImei[$imei] = $this->GetPrevious($insert);
					}
					$edellinen = $previousByImei[$imei];
					
					/* Ensimmäinen rivi */
					if (!$edellinen) {
						/* Kirjoitetaan pisteen tiedot kantaan */
						$insert['Distance'] = 0;
						$insert['Bearing'] = NULL;
						$insert['Speed_avg'] = 0;
						$insert['Path_Id'] = NULL;
						$insert_Id = $this->InsertArchive($insert);

						$previousByImei[$imei] = array(
							'Id' => $insert_Id,
							'Lat' => $insert['Lat'],
							'Lon' => $insert['Lon'],
							'Timestamp' => $insert['Timestamp'],
							'Path_Id' => NULL,
						);
					}
					
					/* Lasketaan tiedot edelliseen pisteeseen nähden */
					else {
						
						/* Matkan id (jos NULL, uutta matkaa ei ole vielä aloitettu) */
						$path_Id = $edellinen['Path_Id'];
						
						/* Aikaero edelliseen reittipisteeseen */
						$edellinen_timediff = strtotime($insert['Timestamp']) - strtotime($edellinen['Timestamp']);
						
						/* Jos aikaero edelliseen pisteeseen on liian suuri, asetetaan reitti ei-aktiiviseksi */
						$track_active = $edellinen_timediff < $path_timeout ? True : False;
						
						/* Jollei matka etene, asetetaan reitti ei-aktiiviseksi */
						if ($edellinen['Lat'] == $insert['Lat'] && $edellinen['Lon'] == $insert['Lon']) $track_active = False;
						
						/* Haetaan matkan viimeisimmän liikken ajankohta (cachesta tai kannasta) */
						$path_lastTimestamp = NULL;
						if ($path_Id) {
							if (!array_key_exists($path_Id, $pathLastTimestampByPathId)) {
								$pathLastTimestampByPathId[$path_Id] = $path->GetEndtime($path_Id);
							}
							$path_lastTimestamp = $pathLastTimestampByPathId[$path_Id];
						}
						
						/* Aikaero matkan viimeisimmästä liikkeestä */
						$path_timediff = $path_lastTimestamp != NULL ? strtotime($insert['Timestamp']) - strtotime($path_lastTimestamp) : NULL;
						
						/* Vanhennetaan matka, jos aikaero edelliseen liikkeeseen on liian suuri */
						$path_alive = $path_timediff < $path_timeout ? True : False;
						
						/* Liitetään piste oletuksena keskeneräiseen matkaan tai jollei ole kesken, niin irralliseksi (NULL) */
						$insert['Path_Id'] = $path_Id;
						
						/* Lasketaan pisteiden välinen etäisyys ja suunta, jos reitti on aktiivinen */
						if ($track_active) {
							
							/* Tehdään laskelmat edelliseen pisteeseen nähden */
							$calculations = $math->CalculateGCDB($edellinen, $insert);

						}

						/* Määritellään pisteen etäisyys ja suunta */
						$insert['Distance'] = isset($calculations['distance']) ? round($calculations['distance'], 1) : 0;
						$insert['Bearing'] = isset($calculations['bearing']) ? $calculations['bearing'] : NULL;

						/* Nollataan pisteen laskelmat */
						unset($calculations);
						
						/* Lasketaan kuljettu matka ja keskinopeus */
						$nopeus_ms = $edellinen_timediff > 0 ? $insert['Distance'] / $edellinen_timediff : 0;
						$insert['Speed_avg'] = round($nopeus_ms * 3600 / 1000 / 1.852, 1);   // $nopeus_kmh = round($nopeus_ms * 3600 / 1000, 1);
	
						/* Matkan viimeisestä liikkeestä yli kaksi tuntia ja matka vielä päättämättä */
						if ($path_Id && !$path_alive) {
							
							/* Päätetään matka siihen ajankohtaan, jolloin matka on edellisen kerran edennyt */
							$path->EndPath($insert['Imei'], $path_lastTimestamp);

							/* Määritellään kml-tieto eräajon lopuksi tai heti */
							if ($deferKml) {
								$kmlQueue[$insert['Imei']][$path_Id] = true;
							}
							else {
								$path->GenerateKml($path_Id, $insert['Imei']);
							}
							
							/* Nollataan pisteen matkatieto */
							$insert['Path_Id'] = NULL;
							
						}						
	
						/* Kirjoitetaan reittipisteen tiedot arkistoon */
						$insert_Id = $this->InsertArchive($insert);
						
						/* Päivitetään aktiivisen reitin viimeisimmän liikkeen ajankohta, jos reitti on vielä aktiivinen */
						if ($insert['Path_Id'] && $track_active) {
							$path->UpdatePath($path_Id, NULL, $insert['Timestamp']);
							$pathLastTimestampByPathId[$path_Id] = $insert['Timestamp'];
						}
						
						/* Aloitetaan uusi matka */
						if (!$insert['Path_Id'] && $track_active && $insert['Distance'] > 0 && $insert['Speed'] > 0) {
							
							/* Aloitetaan matka ko. pisteestä, jos edellinen matka on juuri päättynyt */
							$path_Id = $path_Id ? $path->NewPath($insert['Imei'], $insert_Id, $insert['Timestamp']) : $path->NewPath($insert['Imei'], $edellinen['Id'], $edellinen['Timestamp']);
							
							/* Päivitetään reitin viimeisimmän liikkeen ajankohta */
							$path->UpdatePath($path_Id, $insert_Id, $insert['Timestamp']);
							$pathLastTimestampByPathId[$path_Id] = $insert['Timestamp'];

						}

						$previousByImei[$imei] = array(
							'Id' => $insert_Id,
							'Lat' => $insert['Lat'],
							'Lon' => $insert['Lon'],
							'Timestamp' => $insert['Timestamp'],
							'Path_Id' => $path_Id ? $path_Id : NULL,
						);

					}

					/* Kerätään id batched Processed-päivitykseen */
					$processedIds[] = (int)$insert['Id'];

					}

					/* Merkataan chunkin staging-rivit prosessoiduksi yhdellä kyselyllä */
					$this->UpdateProcessedStagingBatch($processedIds);

				} while ($chunkCount > 0);

				/* Generoidaan KML:t kerran per päättynyt matka eräajon jälkeen */
				if ($deferKml && count($kmlQueue) > 0) {
					foreach ($kmlQueue as $imei => $pathIds) {
						foreach (array_keys($pathIds) as $queuedPathId) {
							$path->GenerateKml($queuedPathId, $imei);
						}
					}
				}

			} catch (Exception $e) {
				$output = $e->getMessage();

				$myfile = fopen("/var/www/tracker.rantojenmies.com/log/error.log", "a");
				if ($myfile) {
					$txt = $e->getMessage();
					$line = $e->getLine();
					$filename = $e->getFile();
					$trace = $e->getTrace();
					fwrite($myfile, "\n". 'Error: '.$txt.' on line '.$line.' ('.$filename.'). Trace: '.json_encode($trace));
					fclose($myfile);
				}

			}

		}        

		$output['TouchedImeis'] = array_keys($touchedImeis);
		$output['OldestTimestampByImei'] = $oldestTimestampByImei;

		return $output;
    }	
	
	

    public function CheckPlace($path_Id, $piste, $imei) {
				
		$places = $this->GetPlaces();
		if (count($places) > 0) {
			
			$math = new Math();
			
			/* Käydään paikat läpi */
			foreach ($places as $rivi) {
				$calculations = $math->CalculateGCDB($rivi, $piste, true);
				if ($calculations['distance'] <= $rivi['Radius']) return $rivi;
			}
			
		}
		
		/* Jos on uusi paikka, lisätään kantaan */
		$piste['Name'] = 'Uusi kohde ('.$piste['Id'].')!'; /* $this->reverse_geocode($piste['Lat'], $piste['Lon']); // 'Tuntematon kohde ('.$piste['Id'].')'; */
		
		$removeChars = array('/å/', '/ä/', '/ö/', '/Å/', '/Ä/', '/Ö/', '/,/');
		$replaceWith = array('a', 'a', 'o', 'A', 'A', 'O', '-');
		$nameUrl = strtolower(preg_replace($removeChars, $replaceWith, str_replace(' ', '', $piste['Name'])));			
		$piste['NameUrl'] = $nameUrl;
		
		$piste['Id'] = $this->InsertPlace($piste, $imei);
		
		return $piste;
		
	}
	   
}
?>