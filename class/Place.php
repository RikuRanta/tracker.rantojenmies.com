<?php

/**
 * @author 
 * @copyright 2012
 */

require_once('Data.php');
require_once('Path.php');

class Place extends Data {

	
    public function RedoKml($imei, $noGroup = false) {	
	
		$places = $this->ListPlaces($imei, null, $noGroup);
		foreach ($places as $place) $this->GenerateKml($place['Id']);
		return true;
		
	}
	

    public function GenerateKml($id) {

        $yhteys = new Mysql();
		
		/* Haetaan pisteen tiedot kannasta */
        $sql = $yhteys->prepare("SELECT Id, Name, NameUrl, Description, Lat, Lon FROM Places WHERE Id=:id;");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
		
        try {
			
            $sql->execute();
			$pisteet = $sql->fetchAll();

			/* Luodan url-osoite nimestä */
			$removeChars = array('/å/', '/ä/', '/ö/', '/Å/', '/Ä/', '/Ö/');
			$replaceWith = array('a', 'a', 'o', 'A', 'A', 'O');
			$nameUrl  = strtolower(preg_replace($removeChars, $replaceWith, str_replace(' ', '-', str_replace(',', '', $pisteet[0]['Name']))));			
						
			/* Koostetaan kml-aineisto */
			$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>{$pisteet[0]['Name']}</name><Placemark><name><![CDATA[<a onClick=\"activePlace='".$nameUrl."'; initPlaces();\" href=\"#\">{$pisteet[0]['Name']}</a>]]></name><description>{$pisteet[0]['Description']}</description><styleUrl>#placemark</styleUrl><Point><coordinates>{$pisteet[0]['Lon']},{$pisteet[0]['Lat']},0.00</coordinates></Point></Placemark><Style id='placemark'><styleUrl>#placemark</styleUrl><IconStyle><color>ffFFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/here-blue.png</href></Icon></IconStyle><LabelStyle><scale>0.0</scale></LabelStyle></Style></Document></kml>";
			$kml = trim($kml);
						
			/* Tallennetaan aineisto kantaan */
			$sql = $yhteys->prepare("
				UPDATE Places 
				SET Kml=:kml
				  , NameUrl=:nameUrl
				WHERE Id=:id;
				");
			$sql->bindParam(':kml', $kml, PDO::PARAM_STR);
			$sql->bindParam(':nameUrl', $nameUrl, PDO::PARAM_STR);
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
			/*
			$output['debug']['stmt'] = $stmt;
			$output['debug']['params'] = $params;
			*/
			return True;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }		

	
    public function GetKml($nameUrl) {
		
        $yhteys = new Mysql();
		/* Haetaan matkan viimeisin ajankohta */
        $sql = $yhteys->prepare("SELECT Kml FROM Places WHERE NameUrl=:nameUrl;");
        $sql->bindParam(':nameUrl', $nameUrl, PDO::PARAM_STR);
		
        try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
					
			if (count($tiedot) > 0) {
				$kml = $tiedot[0]['Kml'];
				Header('Content-type:text/xml');
				Header('Content-Disposition: attachment; filename="'.$nameUrl.'.kml"');
				Header('Content-Length: '.strlen($kml));
				Header('Connection: close');
			}
			else {
				$kml = "Invalid name url!";
			}
			return $kml;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }
	
	
    public function ListPlaces($imei, $uri = null, $noGroup = false) {

		if (!is_string($uri) || trim($uri) === '') {
			$uri = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$uri = preg_replace('#^https?://#i', '', trim($uri));
		
        $yhteys = new Mysql();
		
		/* Haetaan laitteen kaikki matkat */
		
		if ($noGroup) {
			
			$sql = $yhteys->prepare("
				SELECT 
					  p.Id
					, p.Name
					, p.Lat
					, p.Lon
					, p.Public
					, p.NameUrl
					, p.Description
					, CONCAT('https://', :uri, '/cache/place/', p.NameUrl, '/') AS `Url`
					, IFNULL((SELECT DATE_FORMAT(End, '%e.%c.%Y') FROM Path WHERE Imei=:imei AND EndPlace_Id = p.Id AND Visible = 1 AND `Group` = 0 ORDER BY End DESC LIMIT 1), '') `LastVisited`
					, IFNULL((SELECT GROUP_CONCAT(NameUrl ORDER BY NameUrl DESC SEPARATOR '|') FROM Path WHERE Imei=:imei AND EndPlace_Id = p.Id AND Visible = 1 AND `Group` = 0 GROUP BY EndPlace_Id), '') `Visited`
					, p.Group
				FROM Places p
				WHERE (p.Owner=:imei)
					AND p.Visible = 1
					AND p.Group = '0'
					AND (p.Id IN (SELECT StartPlace_Id FROM Path WHERE Visible = 1) OR p.Id IN (SELECT EndPlace_Id FROM Path WHERE Visible = 1))
				ORDER BY p.Name;
				");
				//  OR p.Public = 1
		}
		else {
			
			$sql = $yhteys->prepare("
				SELECT 
					  p.Id
					, p.Name
					, p.Lat
					, p.Lon
					, p.Public
					, p.NameUrl
					, p.Description
					, CONCAT('https://', :uri, '/cache/place/', p.NameUrl, '/') AS `Url`
					, IFNULL((SELECT DATE_FORMAT(End, '%e.%c.%Y') FROM Path WHERE Imei=:imei AND EndPlace_Id = p.Id AND Visible = 1 AND `Group` = 0 ORDER BY End DESC LIMIT 1), '') `LastVisited`
					, IFNULL((SELECT GROUP_CONCAT(NameUrl ORDER BY NameUrl DESC SEPARATOR '|') FROM Path WHERE Imei=:imei AND EndPlace_Id = p.Id AND Visible = 1 AND `Group` = 0 GROUP BY EndPlace_Id), '') `Visited`
					, p.Group
				FROM Places p
				WHERE (p.Owner=:imei)
					AND p.Visible = 1
					AND (p.Group = 1 OR (p.Id IN (SELECT StartPlace_Id FROM Path WHERE Visible = 1) OR p.Id IN (SELECT EndPlace_Id FROM Path WHERE Visible = 1)))
				ORDER BY p.Name;
				");
				// OR p.Public = 1
			
		}
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

	
	/* Päiviteään sataman tiedot */
    public function UpdateInfo($imei, $nameUrl, $data) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("
			UPDATE Places 
			SET Name=:name,
				NameUrl=:newUrl,
				Description=:desc, 
				LastEdited=NOW(), 
				LastEdited_Imei=:imei 
			WHERE NameUrl=:nameUrl 
				AND (Owner=:imei OR Public = 1);
			");
		
		$json = json_decode($data);
        $sql->bindParam(':name', $json->{'name'}, PDO::PARAM_STR);
        $sql->bindParam(':desc', $json->{'description'}, PDO::PARAM_STR);
        $sql->bindParam(':nameUrl', $nameUrl, PDO::PARAM_STR);		
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);

		$removeChars = array('/å/', '/ä/', '/ö/', '/Å/', '/Ä/', '/Ö/');
		$replaceWith = array('a', 'a', 'o', 'A', 'A', 'O');
		$newUrl = strtolower(preg_replace($removeChars, $replaceWith, str_replace(' ', '-', str_replace(',', '', $json->{'name'}))));					
        $sql->bindParam(':newUrl', $newUrl, PDO::PARAM_STR);		

        try {
            $sql->execute();
			$this->RedoKml($imei, true);
			$this->GenerateSummaryKml($imei);
			$path = new Path();
			$path->RedoKml($imei);
            return 1;
		}
        catch (PDOException $e) {
            echo $e->getMessage();
            return 0;
		}            

    }

	
}
?>