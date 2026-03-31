<?php

/**
 * @author 
 * @copyright 2012
 */
 
class Events {


	public function UpsertPathEngineHours($imei, $path_Id, $engineHourMeter) {

		$yhteys = new Mysql();

		/* Haetaan matkan tiedot, jotta voidaan kohdistaa tapahtuma oikeaan ajankohtaan */
		$sql = $yhteys->prepare("SELECT Id, Start, End, StartPlace_Id, EndPlace_Id FROM Path WHERE Id=:path_Id AND Imei=:imei LIMIT 1;");
		$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);

		try {
			$sql->execute();
			$path = $sql->fetchAll();

			if (count($path) === 0) return 0;

			$path = $path[0];
			$timestamp = $path['End'] ? $path['End'] : ($path['Start'] ? $path['Start'] : date('Y-m-d H:i:s'));
			$place_Id = $path['EndPlace_Id'] ? $path['EndPlace_Id'] : $path['StartPlace_Id'];
			$info = 'Moottoritunnit (matka '.$path_Id.')';
			$description = 'Manuaalinen moottorituntimittarin päivitys';

			/* Päivitetään olemassa oleva matkaan sidottu moottoritapahtuma */
			$sql_upd = $yhteys->prepare("
				UPDATE Events
				SET EngineHourMeter=:hours,
					Description=:description
				WHERE Imei=:imei
					AND Type='EngineHours'
					AND Info=:info
					AND Timestamp=:timestamp;");
			$sql_upd->bindParam(':hours', $engineHourMeter, PDO::PARAM_STR);
			$sql_upd->bindParam(':description', $description, PDO::PARAM_STR);
			$sql_upd->bindParam(':imei', $imei, PDO::PARAM_STR);
			$sql_upd->bindParam(':info', $info, PDO::PARAM_STR);
			$sql_upd->bindParam(':timestamp', $timestamp, PDO::PARAM_STR);
			$sql_upd->execute();

			if ($sql_upd->rowCount() > 0) return 1;

			/* Luodaan uusi tapahtuma, jos vastaavaa ei vielä löydy */
			$eventData = [];
			$eventData['timestamp'] = $timestamp;
			$eventData['info'] = $info;
			$eventData['description'] = $description;
			$eventData['amount'] = null;
			$eventData['price'] = null;
			$eventData['type'] = 'EngineHours';
			$eventData['lat'] = null;
			$eventData['lon'] = null;
			$eventData['placeid'] = $place_Id ? $place_Id : null;
			$eventData['hours'] = $engineHourMeter;

			$event_Id = $this->NewEvent($imei, $eventData);
			$this->GenerateKml($event_Id);

			return $event_Id;

		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}

	
    public function NewEvent($imei, $data = []) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("INSERT Events (Imei, Timestamp, Guid, Info, Description, Amount, Price, Type, Lat, Lon, Place_Id, EngineHourMeter) SELECT :imei, :timestamp, uuid(), :info, :description, :amount, :price, :type, :lat, :lon, :placeid, :hours");
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':timestamp', $data['timestamp'], PDO::PARAM_STR);		
        $sql->bindParam(':info', $data['info'], PDO::PARAM_STR);
        $sql->bindParam(':description', $data['description'], PDO::PARAM_STR);
        $sql->bindParam(':amount', $data['amount'], PDO::PARAM_STR);
        $sql->bindParam(':price', $data['price'], PDO::PARAM_STR);
        $sql->bindParam(':type', $data['type'], PDO::PARAM_STR);
        $sql->bindParam(':lat', $data['lat'], PDO::PARAM_STR);
        $sql->bindParam(':lon', $data['lon'], PDO::PARAM_STR);
		$sql->bindParam(':placeid', $data['placeid'], PDO::PARAM_INT);
		$sql->bindParam(':hours', $data['hours'], PDO::PARAM_STR);

        try {
			/* Luodaan uusi matka */
			$sql->execute();
			$event_Id = $yhteys->lastInsertId();
			
            return $event_Id;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }

	
    public function RedoKml($imei) {	
	
		$events = $this->ListEvents($imei);
		foreach ($events as $event) $this->GenerateKml($event['Id'], $imei);
		
		return True;
		
	}
	

    public function GenerateKml($id) {

        $yhteys = new Mysql();
		
		/* Haetaan pisteen tiedot kannasta */
        $sql = $yhteys->prepare("SELECT Id, Info, CONCAT(DATE_FORMAT(Timestamp, '%Y/%m/%d'), '/', LOWER(REPLACE(Info, ' ', '-'))) `NameUrl`, Description, Type, Lat, Lon FROM Events WHERE Id=:id;");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
		
        try {
			
            $sql->execute();
			$pisteet = $sql->fetchAll()[0];

			$ico_name = $pisteet['Type'] == 'Fuel' ? 'refuel.png' : 'gas-refil.png';
						
			/* Koostetaan kml-aineisto */
			$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>{$pisteet['Info']}</name><Placemark><name><![CDATA[<a onClick=\"activeEvent='{$pisteet['NameUrl']}'; initEvents();\" href=\"#\">{$pisteet['Info']}</a>]]></name><description>{$pisteet['Description']}</description><styleUrl>#placemark</styleUrl><Point><coordinates>{$pisteet['Lon']},{$pisteet['Lat']},0.00</coordinates></Point></Placemark><Style id='placemark'><styleUrl>#placemark</styleUrl><IconStyle><color>ffFFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/{$ico_name}</href></Icon></IconStyle><LabelStyle><scale>0.0</scale></LabelStyle></Style></Document></kml>";
			$kml = trim($kml);
						
			/* Tallennetaan aineisto kantaan */
			$sql = $yhteys->prepare("
				UPDATE Events 
				SET Kml=:kml
				  , NameUrl=:nameUrl
				WHERE Id=:id;
				");
			$sql->bindParam(':kml', $kml, PDO::PARAM_STR);
			$sql->bindParam(':nameUrl', $pisteet['NameUrl'], PDO::PARAM_STR);
			$sql->bindParam(':id', $id, PDO::PARAM_INT);
			
			$sql->execute();

            return $kml;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }

	
    public function GenerateSummaryKml($imei) {

        $yhteys = new Mysql();
		
		/* Haetaan pisteen tiedot kannasta */
        $sql = $yhteys->prepare("
				SELECT p.Id, p.Name, p.NameUrl, p.Description, p.Lat, p.Lon 
				FROM Places p
				WHERE 1=1
					AND p.Visible = 1
					AND p.Group = '0'
					AND (
						p.Id IN (SELECT StartPlace_Id FROM Path WHERE Imei=:imei AND Visible = 1 AND StartPlace_Id IS NOT NULL)
						OR p.Id IN (SELECT EndPlace_Id FROM Path WHERE Imei=:imei AND Visible = 1 AND EndPlace_Id IS NOT NULL)
					)
				");
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		
        try {
			
            $sql->execute();
			$places = $sql->fetchAll();
			
			$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>Kaikki satamat ja käyntikohteet</name>";
			
			foreach ($places as $place) {
				
				/* Koostetaan kml-aineisto */
				$kml .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$place['NameUrl']}'; initPlaces();\" href=\"#\">{$place['Name']}</a>]]></name><description>{$place['Description']}</description><styleUrl>#placemark</styleUrl><Point><coordinates>{$place['Lon']},{$place['Lat']},0.00</coordinates></Point></Placemark>";
				$kml = trim($kml);
				
			}
			$kml .= "<Style id='placemark'><styleUrl>#placemark</styleUrl><IconStyle><color>FFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/here-blue.png</href></Icon></IconStyle><LabelStyle><scale>0.0</scale></LabelStyle></Style></Document></kml>";
				
			/* Tallennetaan aineisto kantaan */
			$stmt = "UPDATE Places SET Kml=:kml WHERE `Group` = 'Summary' AND Group_Imei=:imei";
			$sql = $yhteys->prepare($stmt);
			$params[':kml'] = $kml;
			$params[':imei'] = $imei;
			
			$sql->execute($params);
			return True;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }		


    public function ListEvents($imei, $uri = null) {

		if (!is_string($uri) || trim($uri) === '') {
			$uri = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$uri = preg_replace('#^https?://#i', '', trim($uri));
		
        $yhteys = new Mysql();
		
		/* Haetaan laitteen kaikki matkat */
		$sql = $yhteys->prepare("
			SELECT 
				  e.Id
				, CONCAT('https://', :uri, '/cache/kml-events/', e.Guid, '/') AS `Url`
				, CONCAT(DATE_FORMAT(e.Timestamp, '%Y/%m/%d'), '/', LOWER(REPLACE(e.Info, ' ', '-'))) `NameUrl`
				, CONCAT(DAY(e.Timestamp), '.', MONTH(e.Timestamp), '. ', e.Info) `Name`
				, e.Timestamp
				, e.Type
				, e.Info
				, e.Description
				, e.Amount
				, e.Price
				, e.EngineHourMeter
				, e.Lat
				, e.Lon
			FROM Events e
			INNER JOIN Devices d ON e.Imei = d.Imei
			WHERE e.Imei=:imei
			ORDER BY e.`Timestamp`;
			");

		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':uri', $uri, PDO::PARAM_STR);
		
        try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
            return count($tiedot) > 0 ? $tiedot : NULL;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }
	
	
    public function GetKml($args) {
		
		/* Haetaan kml-tiedosto kannasta */
        $yhteys = new Mysql();
		
		/* Haetaanko guidin mukaan? */
		if (isset($args[0]) && strlen($args[0]) == 36) {

			$sql = $yhteys->prepare("SELECT Kml FROM Events WHERE Guid=:guid;");
			$sql->bindParam(':guid', $args[0], PDO::PARAM_STR);
			$kmlName = $args[0];
			
		}
		/* Haetaan url-osoitteen mukaan */
		else {
			
			$nameUrl = implode('/', $args);
			$kmlName = str_replace('/', '-', $nameUrl);
			$sql = $yhteys->prepare("SELECT Kml FROM Events WHERE NameUrl=:url;");
			$sql->bindParam(':url', $nameUrl, PDO::PARAM_STR);

		}
				
		
        try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
					
			if (count($tiedot) > 0) {
				$kml = $tiedot[0]['Kml'];
				Header('Content-type:text/xml');
				Header('Content-Disposition: attachment; filename="'.$kmlName.'.kml"');
				Header('Content-Length: '.strlen($kml));
				Header('Connection: close');
			}
			else {
				$kml = "Invalid guid!";
			}
			return $kml;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }
		
	
}
?>