<?php

/**
 * @author 
 * @copyright 2012
 */

require_once('Data.php');
require_once('Events.php');
 
class Path extends Data {

	
    public function NewPath($imei, $id, $timestamp) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("INSERT Path (Name, Imei, Start, Guid) SELECT 'Uusi reitti', :imei, :start, uuid();");
        $sql->bindParam(':start', $timestamp, PDO::PARAM_STR);
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);

        try {
			/* Luodaan uusi matka */
			$sql->execute();
			$path_Id = $yhteys->lastInsertId();
			
			/* Päivitetään matkan id pisteen tietoihin */
			$sql = $yhteys->prepare("UPDATE DataArchive Set Path_Id=:path_Id, Path_Info='Start' WHERE Id=:id;");
			$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
			$sql->bindParam(':id', $id, PDO::PARAM_INT);
			$sql->execute();
			
            return $path_Id;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }

	
	
    public function NewGroupPath($imei, $name, $start, $end) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("INSERT Path (Name, Imei, Start, End, Guid, Group) SELECT :name, :imei, :start, :end, uuid(), 1;");
        $sql->bindParam(':name', $name, PDO::PARAM_STR);
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);
        $sql->bindParam(':start', $start, PDO::PARAM_STR);
        $sql->bindParam(':end', $end, PDO::PARAM_STR);

        try {
			/* Luodaan uusi matka */
			$sql->execute();
			$path_Id = $yhteys->lastInsertId();
			
            return $path_Id;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }
	

	
    public function UpdatePath($path_Id, $id = NULL, $timestamp = NULL) {

        $yhteys = new Mysql();
        $sql = $yhteys->prepare("UPDATE Path Set End=:end WHERE Id=:id;");
        $sql->bindParam(':id', $path_Id, PDO::PARAM_INT);
        $sql->bindParam(':end', $timestamp, PDO::PARAM_STR);

        try {
			$sql->execute();
			
			/* Päivitetään matkan id pisteen tietoihin (jos pisteen id on annettu) */
			if ($id) {
				$sql = $yhteys->prepare("UPDATE DataArchive Set Path_Id=:path_Id WHERE Id=:id;");
				$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
				$sql->bindParam(':id', $id, PDO::PARAM_INT);
				$sql->execute();				
			}		
			
            return True;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }



    public function UpdatePathGeneral($path_Id, $imei, $data) {

		$json = json_decode($data);
		if (!$json || !is_object($json)) {
			throw new Exception('Invalid JSON payload');
		}

		$allowedKeys = array('EngineHourMeter');
		$filteredJson = new stdClass();
		foreach ($json as $key => $value) {
			if (in_array($key, $allowedKeys, true)) {
				$filteredJson->{$key} = $value;
			}
		}

		if (count((array)$filteredJson) === 0) {
			throw new Exception('No allowed fields to update');
		}

        $yhteys = new Mysql();
		$stmt = 'UPDATE Path SET ';
		foreach ($filteredJson as $key => $value) $stmt .= $key.'=:p_'.$key.', ';
		$stmt = substr($stmt, 0, -2).' ';	
		$stmt .= 'WHERE Id=:path_Id AND Imei=:imei';
		$sql = $yhteys->prepare($stmt);
		$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
			
		/* Parametrien dynaaminen bindaus */
		foreach ($filteredJson as $key => $value) $sql->bindValue(':p_'.$key, $value);
		
        try {
			$sql->execute();

			/* Tallennetaan manuaalinen moottorituntimittarin lukema myös tapahtumiin */
			if (isset($filteredJson->{'EngineHourMeter'}) && $filteredJson->{'EngineHourMeter'} !== '') {
				$events = new Events();
				$events->UpsertPathEngineHours($imei, $path_Id, $filteredJson->{'EngineHourMeter'});
			}

			$this->GenerateKml($path_Id, $imei);				

			/* Luodaan seuraavan matkan KML uudelleen, jotta sen EngineHours päivittyy */
			if (isset($filteredJson->{'EngineHourMeter'})) {
				$nextSql = $yhteys->prepare("SELECT Id FROM Path WHERE Imei=:imei AND `Group`=0 AND Visible=1 AND EngineHourMeter IS NOT NULL AND Id > :path_Id ORDER BY Id ASC LIMIT 1");
				$nextSql->bindParam(':imei', $imei, PDO::PARAM_STR);
				$nextSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
				$nextSql->execute();
				$nextPath = $nextSql->fetch(PDO::FETCH_ASSOC);
				if ($nextPath && !empty($nextPath['Id'])) {
					$this->GenerateKml((int)$nextPath['Id'], $imei);
				}
			}
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }


	public function ListManualPoints($imei, $path_Id) {

		if (!is_numeric($path_Id)) {
			throw new Exception('Provided path id is wrong type. Expecting numeric.', 400);
		}

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("SELECT mp.Id, mp.Path_Id, mp.Timestamp, mp.Lat, mp.Lon, mp.Distance, mp.Note FROM PathManualPoint mp INNER JOIN Path p ON p.Id = mp.Path_Id WHERE p.Imei=:imei AND mp.Path_Id=:path_Id AND mp.Deleted=0 ORDER BY mp.Timestamp, mp.Id;");
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);

		try {
			$sql->execute();
			$rows = $sql->fetchAll();
			return is_array($rows) ? $rows : array();
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}


	public function AddManualPoint($imei, $path_Id, $data) {

		$json = json_decode($data);
		if (!$json || !is_object($json)) {
			throw new Exception('Invalid JSON payload', 400);
		}

		if ((!isset($json->Lat) && !isset($json->lat)) || (!isset($json->Lon) && !isset($json->lon))) {
			throw new Exception('Lat and Lon are required', 400);
		}

		$lat = isset($json->Lat) ? $json->Lat : $json->lat;
		$lon = isset($json->Lon) ? $json->Lon : $json->lon;

		if (!is_numeric($lat) || !is_numeric($lon)) {
			throw new Exception('Lat and Lon must be numeric', 400);
		}

		if (!is_numeric($path_Id)) {
			if (isset($json->Path_Id) && is_numeric($json->Path_Id)) $path_Id = $json->Path_Id;
			else if (isset($json->path_Id) && is_numeric($json->path_Id)) $path_Id = $json->path_Id;
		}

		if (!is_numeric($path_Id)) {
			throw new Exception('Path id missing', 400);
		}

		$distance = isset($json->Distance) && is_numeric($json->Distance) ? $json->Distance : 0;
		$note = isset($json->Note) ? (string)$json->Note : null;

		$yhteys = new Mysql();

		$pathSql = $yhteys->prepare("SELECT Id, Imei, Start, End FROM Path WHERE Id=:path_Id AND Imei=:imei AND `Group`=0 LIMIT 1;");
		$pathSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$pathSql->bindParam(':imei', $imei, PDO::PARAM_STR);

		try {
			$pathSql->execute();
			$path = $pathSql->fetch(PDO::FETCH_ASSOC);
			if (!$path) throw new Exception('Path not found', 404);

			$timestamp = null;
			if (isset($json->Timestamp) && trim((string)$json->Timestamp) !== '') {
				$timestamp = trim((string)$json->Timestamp);
			}
			else {
				$nearestSql = $yhteys->prepare("SELECT Timestamp FROM DataArchive WHERE Path_Id=:path_Id ORDER BY (POW(Lat - :lat, 2) + POW(Lon - :lon, 2)) ASC LIMIT 1;");
				$nearestSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
				$nearestSql->bindParam(':lat', $lat, PDO::PARAM_STR);
				$nearestSql->bindParam(':lon', $lon, PDO::PARAM_STR);
				$nearestSql->execute();
				$nearest = $nearestSql->fetch(PDO::FETCH_ASSOC);
				if ($nearest && !empty($nearest['Timestamp'])) {
					$timestamp = $nearest['Timestamp'];
				}
			}

			if (!$timestamp) {
				$timestamp = !empty($path['End']) ? $path['End'] : $path['Start'];
			}

			$ins = $yhteys->prepare("INSERT PathManualPoint (Path_Id, Timestamp, Lat, Lon, Distance, Note) VALUES (:path_Id, :timestamp, :lat, :lon, :distance, :note);");
			$ins->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
			$ins->bindParam(':timestamp', $timestamp, PDO::PARAM_STR);
			$ins->bindParam(':lat', $lat, PDO::PARAM_STR);
			$ins->bindParam(':lon', $lon, PDO::PARAM_STR);
			$ins->bindParam(':distance', $distance, PDO::PARAM_STR);
			$ins->bindParam(':note', $note, PDO::PARAM_STR);
			$ins->execute();

			$this->GenerateKml($path_Id, $imei);

			return array(
				'Id' => (int)$yhteys->lastInsertId(),
				'Path_Id' => (int)$path_Id,
				'Timestamp' => $timestamp,
				'Lat' => (float)$lat,
				'Lon' => (float)$lon
			);
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}


	public function DeleteManualPoint($imei, $path_Id, $data) {

		$json = json_decode($data);
		if (!$json || !is_object($json)) {
			throw new Exception('Invalid JSON payload', 400);
		}

		if (!is_numeric($path_Id)) {
			if (isset($json->Path_Id) && is_numeric($json->Path_Id)) $path_Id = $json->Path_Id;
			else if (isset($json->path_Id) && is_numeric($json->path_Id)) $path_Id = $json->path_Id;
		}

		if (!is_numeric($path_Id)) {
			throw new Exception('Path id missing', 400);
		}

		$manualPointId = null;
		if (isset($json->Id) && is_numeric($json->Id)) $manualPointId = (int)$json->Id;
		if (!$manualPointId && isset($json->id) && is_numeric($json->id)) $manualPointId = (int)$json->id;
		if (!$manualPointId) {
			throw new Exception('Manual point id missing', 400);
		}
		if ($manualPointId >= 1000000000) {
			$manualPointId -= 1000000000;
		}

		$yhteys = new Mysql();

		$pathSql = $yhteys->prepare("SELECT Id FROM Path WHERE Id=:path_Id AND Imei=:imei AND `Group`=0 LIMIT 1;");
		$pathSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$pathSql->bindParam(':imei', $imei, PDO::PARAM_STR);

		try {
			$pathSql->execute();
			$path = $pathSql->fetch(PDO::FETCH_ASSOC);
			if (!$path) throw new Exception('Path not found', 404);

			$delSql = $yhteys->prepare("UPDATE PathManualPoint mp INNER JOIN Path p ON p.Id = mp.Path_Id SET mp.Deleted=1 WHERE mp.Id=:id AND mp.Path_Id=:path_Id AND mp.Deleted=0 AND p.Imei=:imei;");
			$delSql->bindParam(':id', $manualPointId, PDO::PARAM_INT);
			$delSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
			$delSql->bindParam(':imei', $imei, PDO::PARAM_STR);
			$delSql->execute();

			if ($delSql->rowCount() === 0) {
				throw new Exception('Manual point not found', 404);
			}

			$this->GenerateKml($path_Id, $imei);

			return array('deleted' => 1, 'Id' => $manualPointId, 'Path_Id' => (int)$path_Id);
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}


	public function ListEditablePoints($imei, $path_Id) {

		if (!is_numeric($path_Id)) {
			throw new Exception('Provided path id is wrong type. Expecting numeric.', 400);
		}

		$yhteys = new Mysql();
		$sql = $yhteys->prepare("
			SELECT ep.Id, ep.Source, ep.Timestamp, ep.Lat, ep.Lon, ep.PathInfo
			FROM (
				SELECT da.Id, 'archive' AS Source, da.Timestamp, da.Lat, da.Lon, da.Path_Info AS PathInfo, 0 AS SourceSort
				FROM DataArchive da
				INNER JOIN Path p ON p.Id = da.Path_Id
				WHERE p.Imei=:imei
				  AND da.Path_Id=:path_Id

				UNION ALL

				SELECT mp.Id AS Id, 'manual' AS Source, mp.Timestamp, mp.Lat, mp.Lon, NULL AS PathInfo, 1 AS SourceSort
				FROM PathManualPoint mp
				INNER JOIN Path p2 ON p2.Id = mp.Path_Id
				WHERE p2.Imei=:imei2
				  AND mp.Path_Id=:path_Id2
				  AND mp.Deleted=0
			) ep
			ORDER BY ep.Timestamp, ep.SourceSort, ep.Id;
		");
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$sql->bindParam(':imei2', $imei, PDO::PARAM_STR);
		$sql->bindParam(':path_Id2', $path_Id, PDO::PARAM_INT);

		try {
			$sql->execute();
			$rows = $sql->fetchAll();
			if (!is_array($rows)) return array();

			/* Käytä samaa sääntöä kuin GenerateKml: lopeta ensimmäiseen End-merkintään */
			$out = array();
			foreach ($rows as $row) {
				$out[] = $row;
				if (isset($row['PathInfo']) && $row['PathInfo'] === 'End') {
					break;
				}
			}

			return $out;
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}


	public function UpdateEditablePoint($imei, $path_Id, $data) {

		$json = json_decode($data);
		if (!$json || !is_object($json)) {
			throw new Exception('Invalid JSON payload', 400);
		}

		if (!is_numeric($path_Id)) {
			if (isset($json->Path_Id) && is_numeric($json->Path_Id)) $path_Id = $json->Path_Id;
			else if (isset($json->path_Id) && is_numeric($json->path_Id)) $path_Id = $json->path_Id;
		}

		if (!is_numeric($path_Id)) {
			throw new Exception('Path id missing', 400);
		}

		$pointId = null;
		if (isset($json->Id) && is_numeric($json->Id)) $pointId = (int)$json->Id;
		if (!$pointId && isset($json->id) && is_numeric($json->id)) $pointId = (int)$json->id;
		if (!$pointId) {
			throw new Exception('Point id missing', 400);
		}

		$source = isset($json->Source) ? strtolower((string)$json->Source) : (isset($json->source) ? strtolower((string)$json->source) : '');
		if ($source !== 'archive' && $source !== 'manual') {
			throw new Exception('Source must be archive or manual', 400);
		}
		if ($source === 'manual' && $pointId >= 1000000000) {
			$pointId -= 1000000000;
		}

		$lat = isset($json->Lat) ? $json->Lat : (isset($json->lat) ? $json->lat : null);
		$lon = isset($json->Lon) ? $json->Lon : (isset($json->lon) ? $json->lon : null);
		if (!is_numeric($lat) || !is_numeric($lon)) {
			throw new Exception('Lat and Lon must be numeric', 400);
		}

		$yhteys = new Mysql();

		$pathSql = $yhteys->prepare("SELECT Id FROM Path WHERE Id=:path_Id AND Imei=:imei AND `Group`=0 LIMIT 1;");
		$pathSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
		$pathSql->bindParam(':imei', $imei, PDO::PARAM_STR);

		try {
			$pathSql->execute();
			$path = $pathSql->fetch(PDO::FETCH_ASSOC);
			if (!$path) throw new Exception('Path not found', 404);

			if ($source === 'archive') {
				$exists = $yhteys->prepare("SELECT da.Id FROM DataArchive da INNER JOIN Path p ON p.Id = da.Path_Id WHERE da.Id=:id AND da.Path_Id=:path_Id AND p.Imei=:imei LIMIT 1;");
			}
			else {
				$exists = $yhteys->prepare("SELECT mp.Id FROM PathManualPoint mp INNER JOIN Path p ON p.Id = mp.Path_Id WHERE mp.Id=:id AND mp.Path_Id=:path_Id AND mp.Deleted=0 AND p.Imei=:imei LIMIT 1;");
			}
			$exists->bindParam(':id', $pointId, PDO::PARAM_INT);
			$exists->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
			$exists->bindParam(':imei', $imei, PDO::PARAM_STR);
			$exists->execute();
			$pointRow = $exists->fetch(PDO::FETCH_ASSOC);
			if (!$pointRow) {
				throw new Exception('Point not found', 404);
			}

			if ($source === 'archive') {
				$upd = $yhteys->prepare("UPDATE DataArchive da INNER JOIN Path p ON p.Id = da.Path_Id SET da.Lat=:lat, da.Lon=:lon WHERE da.Id=:id AND da.Path_Id=:path_Id AND p.Imei=:imei;");
			}
			else {
				$upd = $yhteys->prepare("UPDATE PathManualPoint mp INNER JOIN Path p ON p.Id = mp.Path_Id SET mp.Lat=:lat, mp.Lon=:lon WHERE mp.Id=:id AND mp.Path_Id=:path_Id AND mp.Deleted=0 AND p.Imei=:imei;");
			}

			$upd->bindParam(':lat', $lat, PDO::PARAM_STR);
			$upd->bindParam(':lon', $lon, PDO::PARAM_STR);
			$upd->bindParam(':id', $pointId, PDO::PARAM_INT);
			$upd->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
			$upd->bindParam(':imei', $imei, PDO::PARAM_STR);
			$upd->execute();

			$this->GenerateKml($path_Id, $imei);

			return array(
				'updated' => 1,
				'Id' => $pointId,
				'Source' => $source,
				'Path_Id' => (int)$path_Id,
				'Lat' => (float)$lat,
				'Lon' => (float)$lon
			);
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}

	
    public function EndPath($imei, $timestamp) {

        $yhteys = new Mysql();
		
		/* Päätetään matka */
		$sql = $yhteys->prepare("UPDATE DataArchive SET Path_Info='End' WHERE Imei=:imei AND Timestamp=:timestamp;");
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':timestamp', $timestamp, PDO::PARAM_STR);			

        try {
            $sql->execute();
			
			/* Päivitetään matkan Ready-tieto */
			$sql = $yhteys->prepare("UPDATE Path SET Ready=1 WHERE Imei=:imei AND End=:timestamp;");
			$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
			$sql->bindParam(':timestamp', $timestamp, PDO::PARAM_STR);
            $sql->execute();
			
            return True;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }	

	
    public function GetEndtime($id) {
		
		if ($id === NULL) return NULL;
		
        $yhteys = new Mysql();
		/* Haetaan matkan viimeisin ajankohta */
        $sql = $yhteys->prepare("SELECT End FROM Path WHERE Id=:id;");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
		
        try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
            return count($tiedot) > 0 ? $tiedot[0]['End'] : NULL;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }	

	
    public function GetUnfinished() {
		
        $yhteys = new Mysql();
		/* Haetaan matkan viimeisin ajankohta */
        $sql = $yhteys->prepare("
			SELECT p.Id, p.Imei, p.End, p.Group
			FROM Devices d
			INNER JOIN Path p ON d.Imei = p.Imei
			WHERE p.Ready = 0
				AND d.LastUpdated < DATE_ADD(NOW(),INTERVAL -10 MINUTE);
			");

		try {
			$sql->execute();
			$tiedot = $sql->fetchAll();
			return $tiedot;
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}  

	}


	public function RedoKml($imei) {
	
		$paths = $this->ListPaths($imei, '', true);
		if (is_array($paths) && count($paths) > 0) {
			foreach ($paths as $path) $this->GenerateKml($path['Id'], $imei);
		}
		
		/* Luodaan koontimatkat */
		$this->RedoGroupKml($imei);
		
		return True;
		
	}
	
	
	public function RedoGroupKml($imei, $fromTimestamp = null, $groupPathId = null) {	
	
		if ($groupPathId && is_numeric($groupPathId)) {
			$yhteys = new Mysql();
			$sql = $yhteys->prepare("SELECT Id FROM Path WHERE Id=:id AND Imei=:imei AND `Group`='1' LIMIT 1;");
			$sql->bindParam(':id', $groupPathId, PDO::PARAM_INT);
			$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
			try {
				$sql->execute();
				$groups = $sql->fetchAll();
			}
			catch (PDOException $e) {
				echo $e->getMessage();
				$groups = array();
			}
		}
		else if ($fromTimestamp) {
			$yhteys = new Mysql();
			$sql = $yhteys->prepare("SELECT Id FROM Path WHERE Imei=:imei AND `Group`='1' AND Visible='1' AND (`Start` >= :fromTimestamp OR IFNULL(`End`, `Start`) >= :fromTimestamp) ORDER BY `Start`;");
			$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
			$sql->bindParam(':fromTimestamp', $fromTimestamp, PDO::PARAM_STR);
			try {
				$sql->execute();
				$groups = $sql->fetchAll();
			}
			catch (PDOException $e) {
				echo $e->getMessage();
				$groups = array();
			}
		}
		else {
			$groups = $this->ListPaths($imei, '', false, true);
		}
		if (is_array($groups) && count($groups) > 0) {
			foreach ($groups as $group) $this->GenerateGroupKml($group['Id'], $imei);
		}
		
		return True;
		
	}


	public function RedoIncompletePathKml($imei, $fromTimestamp = null) {

		$yhteys = new Mysql();
		$stmt = "
			SELECT Id
			FROM Path
			WHERE Imei=:imei
				AND `Group`='0'
				AND (
					Kml IS NULL
					OR Name LIKE 'Uusi reitti%'
					OR StartPlace_Id IS NULL
					OR EndPlace_Id IS NULL
				)";

		if ($fromTimestamp) {
			$stmt .= "\n\t\t\t\tAND (`Start` >= :fromTimestamp OR IFNULL(`End`, `Start`) >= :fromTimestamp)";
		}

		$stmt .= "\n\t\t\tORDER BY `Start`;";
		$sql = $yhteys->prepare($stmt);
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		if ($fromTimestamp) $sql->bindParam(':fromTimestamp', $fromTimestamp, PDO::PARAM_STR);

		try {
			$sql->execute();
			$paths = $sql->fetchAll();
			if (is_array($paths) && count($paths) > 0) {
				foreach ($paths as $path) $this->GenerateKml($path['Id'], $imei);
			}
			return True;
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}


	public function GetImeisWithIncompletePathKml() {

		$yhteys = new Mysql();
		$sql = $yhteys->prepare(" 
			SELECT DISTINCT Imei
			FROM Path
			WHERE `Group`='0'
				AND (
					Kml IS NULL
					OR Name LIKE 'Uusi reitti%'
					OR StartPlace_Id IS NULL
					OR EndPlace_Id IS NULL
				)
			ORDER BY Imei;
		");

		try {
			$sql->execute();
			$rows = $sql->fetchAll();
			if (!is_array($rows) || count($rows) === 0) return array();
			$out = array();
			foreach ($rows as $row) if (!empty($row['Imei'])) $out[] = $row['Imei'];
			return $out;
		}
		catch (PDOException $e) {
			throw new Exception($e->getMessage());
		}

	}
	
		

    public function GenerateKml($id, $imei) {

        $yhteys = new Mysql();
		/* Haetaan matkan pisteet kannasta */
		$sql = $yhteys->prepare("
			SELECT p.Id, p.Lat, p.Lon, p.Distance, p.Timestamp, p.Path_Info
			FROM (
				SELECT
					da.Id AS Id,
					da.Lat,
					da.Lon,
					da.Distance,
					da.Timestamp,
					da.Path_Info,
					0 AS SourceSort
				FROM DataArchive da
				WHERE da.Path_Id=:id

				UNION ALL

				SELECT
					(1000000000 + mp.Id) AS Id,
					mp.Lat,
					mp.Lon,
					COALESCE(mp.Distance, 0) AS Distance,
					mp.Timestamp,
					NULL AS Path_Info,
					1 AS SourceSort
				FROM PathManualPoint mp
				WHERE mp.Path_Id=:id2
				  AND mp.Deleted=0
			) p
			ORDER BY p.Timestamp, p.SourceSort, p.Id;
		");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
		$sql->bindParam(':id2', $id, PDO::PARAM_INT);
		
        try {
            $sql->execute();
			$pisteet = $sql->fetchAll();

			if (!is_array($pisteet) || count($pisteet) === 0) {
				return True;
			}
			
			/* Muodostetaan koordinaattitietue */
			$coordinates = '';
			$distance = 0;	
			$kml_placemarks = '';			
			$startTime = null;
			$endTime = null;
			$startDate = '';
			$lastPiste = null;
			$previousPointForDistance = null;
			
			foreach ($pisteet as $piste) {
				$lastPiste = $piste;
				
				$coordinates .= "{$piste['Lon']},{$piste['Lat']},0.000000 ";
				if ($previousPointForDistance !== null) {
					$segment = Math::CalculateGCDB($previousPointForDistance, $piste, true);
					if (isset($segment['distance']) && is_numeric($segment['distance'])) {
						$distance += (float)$segment['distance'];
					}
				}
				$previousPointForDistance = array('Lat' => $piste['Lat'], 'Lon' => $piste['Lon']);

				if ($piste['Path_Info'] == 'Start') {
					$startPlace = $this->CheckPlace($id, $piste, $imei);
					$startTime = strtotime($piste['Timestamp']);
					$startKlo = date('G:i', $startTime);
					$startDate = date('j.n.y', $startTime);					
					$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$startPlace['NameUrl']}'; initPlaces();\" href=\"#\">{$startPlace['Name']}</a>]]></name><description>Lähtö klo $startKlo</description><styleUrl>#placemark-start</styleUrl><Point><coordinates>{$piste['Lon']},{$piste['Lat']},0.00</coordinates></Point></Placemark>";
				}
				else if ($piste['Path_Info'] == 'End') {
					$endPlace = $this->CheckPlace($id, $piste, $imei);
					$endTime = strtotime($piste['Timestamp']);
					$endKlo = date('G:i', $endTime);
					$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$endPlace['NameUrl']}'; initPlaces();\" href=\"#\">{$endPlace['Name']}</a>]]></name><description>Perillä klo $endKlo</description><styleUrl>#placemark-end</styleUrl><Point><coordinates>{$piste['Lon']},{$piste['Lat']},0.00</coordinates></Point></Placemark>";
					break;
				}
			}
						
			// Tulkitaan viimeinen piste viimeiseksi, ellei sellaista ole vielä määritetty
			if ($lastPiste && $lastPiste['Path_Info'] != 'End' && !isset($endPlace)) {
				$endPlace = $this->CheckPlace($id, $lastPiste, $imei);
				$endTime = strtotime($lastPiste['Timestamp']);
				$endKlo = date('G:i', $endTime);
				$startDate = date('j.n.y', $endTime);
				$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$endPlace['NameUrl']}'; initPlaces();\" href=\"#\">{$endPlace['Name']}</a>]]></name><description>Perillä klo $endKlo</description><styleUrl>#placemark-end</styleUrl><Point><coordinates>{$lastPiste['Lon']},{$lastPiste['Lat']},0.00</coordinates></Point></Placemark>";
				
			}

			/* Varmistus historiallisille riveille, joilta Start/End-merkinnät puuttuvat */
			if (!isset($startPlace)) {
				$startPlace = $this->CheckPlace($id, $pisteet[0], $imei);
			}
			if (!isset($endPlace) && $lastPiste) {
				$endPlace = $this->CheckPlace($id, $lastPiste, $imei);
			}
			if (!$startTime && isset($pisteet[0]['Timestamp'])) {
				$startTime = strtotime($pisteet[0]['Timestamp']);
			}
			if (!$endTime && $lastPiste && isset($lastPiste['Timestamp'])) {
				$endTime = strtotime($lastPiste['Timestamp']);
			}
			if ($startDate === '' && $startTime) {
				$startDate = date('j.n.y', $startTime);
			}
			
			/* Määritellään käyntipaikat */
			$newName = isset($startPlace['Name']) && isset($endPlace['Name']) ? $startPlace['Name'].' - '.$endPlace['Name'] : 'Uusi reitti!';
			$newNameUrl = isset($startPlace['NameUrl']) && isset($endPlace['NameUrl']) ? $startPlace['NameUrl'].'-'.$endPlace['NameUrl'] : 'uusi-reitti';
			
			$startPlace_Id = (isset($startPlace['Id']) && (int)$startPlace['Id'] > 0) ? (int)$startPlace['Id'] : NULL;
			$endPlace_Id = (isset($endPlace['Id']) && (int)$endPlace['Id'] > 0) ? (int)$endPlace['Id'] : NULL;
			
			/* Määritellään reitin url */
			$nameUrl = isset($startTime) ? date('Y', $startTime).'/'.date('n', $startTime).'/'.date('j', $startTime).'/'.$newNameUrl : '';
				
			$kml_styles = "<Style id='placemark-start'><styleUrl>#placemark-start</styleUrl><IconStyle><color>ffFFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/here-green-2.png</href></Icon></IconStyle><LabelStyle><scale>0.0</scale></LabelStyle></Style><Style id='placemark-end'><styleUrl>#placemark-end</styleUrl><IconStyle><color>ffFFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/here-red-2.png</href></Icon></IconStyle><LabelStyle><scale>1.1</scale></LabelStyle></Style>";
						
			/* Lasketaan matkan tiedot */
			$duration = isset($endTime) && isset($startTime) ? $endTime - $startTime : 0;
			$durationHours = gmdate("G:i", $duration);
			$distance = round($distance / 1000 / 1.852, 1);
			$speedAvg = $duration > 0 ? round($distance / $duration * 3600, 1) : 0;
			
			/* Asetetaan matka ei-näkyväksi, jos matkan pituus on vähemmän kuin 0,1 mailia */
			$visible = ($distance > 0.1 && $duration > 60) ? 1 : 0;
			
			$description = "$distance mailia, kesto $durationHours, $speedAvg solmua";
			
			/* Koostetaan kml-aineisto */
			$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>$newName</name><Style id='TrackStyle'><LineStyle><width>6.0</width><color>CC1400FF</color></LineStyle></Style><Placemark id='linestring1'><name>$newName ($startDate)</name><styleUrl>#TrackStyle</styleUrl><description>$description</description><LineString><altitudeMode>clampToGround</altitudeMode><coordinates>$coordinates</coordinates></LineString></Placemark>$kml_placemarks$kml_styles</Document></kml>";
			$kml = trim($kml);
			
			
			/* Tallennetaan aineisto kantaan */
			$sql = $yhteys->prepare("
				UPDATE Path p
				SET p.Kml=:kml
				  , p.KmlPoints=:points
				  , p.Name=:name
				  , p.NameUrl=:nameUrl
				  , p.Description=:description
				  , p.StartPlace_Id=:startId
				  , p.EndPlace_Id=:endId
				  , p.Duration=TIMEDIFF(p.End, p.Start)
				  , p.Distance=:distance
				  , p.Speed_Avg=CASE
						WHEN TIME_TO_SEC(TIMEDIFF(p.End, p.Start)) > 0
						THEN ROUND(((:distance / TIME_TO_SEC(TIMEDIFF(p.End, p.Start))) * 3600), 1)
						ELSE 0
					END
				  , p.Visible=:visible
				  , p.EngineHourMeter=COALESCE(
						(SELECT e.EngineHourMeter
						 FROM Events e
						 WHERE e.Imei = p.Imei
						   AND e.Type = 'EngineHours'
						   AND e.EngineHourMeter IS NOT NULL
						   AND e.Timestamp >= p.Start
						   AND (p.End IS NULL OR e.Timestamp <= p.End)
						 ORDER BY e.Timestamp DESC
						 LIMIT 1),
						p.EngineHourMeter
					)
				  , p.EngineHours=(
						COALESCE(
							(SELECT e.EngineHourMeter
							 FROM Events e
							 WHERE e.Imei = p.Imei
							   AND e.Type = 'EngineHours'
							   AND e.EngineHourMeter IS NOT NULL
							   AND e.Timestamp >= p.Start
							   AND (p.End IS NULL OR e.Timestamp <= p.End)
							 ORDER BY e.Timestamp DESC
							 LIMIT 1),
							p.EngineHourMeter
						)
						- (SELECT EngineHourMeter FROM Path WHERE Id < p.Id AND Imei = p.Imei AND Visible = 1 AND EngineHourMeter IS NOT NULL ORDER BY End DESC LIMIT 1)
					)
				WHERE p.Id=:id;
				");
			$sql->bindParam(':kml', $kml, PDO::PARAM_STR);
			$sql->bindParam(':points', $coordinates, PDO::PARAM_STR);
			$sql->bindParam(':distance', $distance, PDO::PARAM_STR);
			$sql->bindParam(':id', $id, PDO::PARAM_INT);
 			$sql->bindParam(':visible', $visible, PDO::PARAM_INT);
 			$sql->bindParam(':name', $newName, PDO::PARAM_STR);
			$sql->bindParam(':nameUrl', $nameUrl, PDO::PARAM_STR);
 			$sql->bindParam(':description', $description, PDO::PARAM_STR);
 			$sql->bindParam(':startId', $startPlace_Id, PDO::PARAM_INT);
 			$sql->bindParam(':endId', $endPlace_Id, PDO::PARAM_INT);
			
			$sql->execute();

            return True;

			/* Luodaan seuraavan matkan KML uudelleen, jotta sen EngineHours päivittyy tämän matkan uuden EHM-arvon mukaan */
			if (isset($filteredJson->{'EngineHourMeter'})) {
				$nextSql = $yhteys->prepare("SELECT Id FROM Path WHERE Imei=:imei AND `Group`=0 AND Visible=1 AND EngineHourMeter IS NOT NULL AND Id > :path_Id ORDER BY Id ASC LIMIT 1");
				$nextSql->bindParam(':imei', $imei, PDO::PARAM_STR);
				$nextSql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
				$nextSql->execute();
				$nextPath = $nextSql->fetch(PDO::FETCH_ASSOC);
				if ($nextPath && !empty($nextPath['Id'])) {
					$this->GenerateKml((int)$nextPath['Id'], $imei);
				}
			}
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }            

    }

	
    public function GenerateGroupKml($id, $imei) {

        $yhteys = new Mysql();

		/* Haetaan matkan pisteet kannasta */
        $sql = $yhteys->prepare("
				SELECT 
					  p.Name
					, p.NameUrl
					, s.Id	AS `StartPlace_Id`	
					, s.Name	AS `StartPlace`
					, s.NameUrl AS `StartPlaceUrl`
					, s.Lon AS `StartLon`
					, s.Lat AS `StartLat`
					, p.Start
					, e.Id	AS `EndPlace_Id`	
					, e.Name	AS `EndPlace`
					, e.NameUrl AS `EndPlaceUrl`	
					, e.Lon AS `EndLon`
					, e.Lat AS `EndLat`
					, p.End
					, p.KmlPoints
					, p.Duration
					, p.Distance
					, p.EngineHours
					, g.Name AS `GroupName`	
				FROM Path p
				LEFT JOIN Places s ON p.StartPlace_Id = s.Id
				LEFT JOIN Places e ON p.EndPlace_Id = e.Id
				, Path g
				WHERE p.Visible = 1
					AND p.Start >= g.Start
					AND p.End <= g.End
					AND g.Id=:id 
					AND g.Imei=:imei
					AND p.Imei=:imei_i2
					AND p.`Group` = 0
				ORDER BY p.Start;
				");
        $sql->bindParam(':id', $id, PDO::PARAM_INT);
        $sql->bindParam(':imei', $imei, PDO::PARAM_STR);
        $sql->bindParam(':imei_i2', $imei, PDO::PARAM_STR);
		
        try {
            $sql->execute();
			$paths = $sql->fetchAll();

			if (!is_array($paths) || count($paths) === 0) {
				return true;
			}
			
			/* Muodostetaan koordinaattitietue */
			$coordinates = '';
			$distance = 0;	
			$eng_hours = 0;	
			$kml_placemarks = '';			
			$duration = '0';
			
			foreach ($paths as $path) {
				
				$coordinates .= $path['KmlPoints'];
				$distance += $path['Distance'];
				$eng_hours += $path['EngineHours'];
				$duration += strtotime("1970-01-01 {$path['Duration']} UTC");
				$date = date('j.n.', strtotime($path['Start']));
				
				/* Onko viimeinen matka? */
				if ($path === end($paths))  {
					$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$path['StartPlaceUrl']}'; initPlaces();\" href=\"#\">{$path['StartPlace']} ($date)</a>]]></name><description></description><styleUrl>#placemark</styleUrl><Point><coordinates>{$path['StartLon']},{$path['StartLat']},0.00</coordinates></Point></Placemark>";	
					$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$path['EndPlaceUrl']}'; initPlaces();\" href=\"#\">{$path['EndPlace']} ($date)</a>]]></name><description></description><styleUrl>#placemark</styleUrl><Point><coordinates>{$path['EndLon']},{$path['EndLat']},0.00</coordinates></Point></Placemark>";
					$endDate = date('j.n.', strtotime($path['End']));
					$endPlace_Id = $path['EndPlace_Id'];
					break;
				}
				else {
					$kml_placemarks .= "<Placemark><name><![CDATA[<a onClick=\"activePlace='{$path['StartPlaceUrl']}'; initPlaces();\" href=\"#\">{$path['StartPlace']} ($date)</a>]]></name><description></description><styleUrl>#placemark</styleUrl><Point><coordinates>{$path['StartLon']},{$path['StartLat']},0.00</coordinates></Point></Placemark>";	
				}

			}
			
			$kml_styles = "<Style id='placemark'><styleUrl>#placemark</styleUrl><IconStyle><color>FFFFFF</color><scale>1.1</scale><Icon><href>https://www.rantojenmies.com/loki/img/here-blue.png</href></Icon></IconStyle><LabelStyle><scale>0.0</scale></LabelStyle></Style>";
			
			/* Lasketaan matkan tiedot */	
			$durationHours = floor($duration / 3600).':'.substr('0'.floor($duration / 60 % 60), -2).':'.substr('0'.floor($duration % 60), -2);			
			$distance = round($distance, 0);
			$speedAvg = $duration > 0 ? round($distance / $duration * 3600, 1) : 0;
			
			/* Asetetaan matka ei-näkyväksi, jos matkan pituus on vähemmän kuin 0,1 mailia */
			$visible = ($distance > 0.1 && $duration > 60) ? 1 : 0;
			$startDate = date('j.n.', strtotime($paths[0]['Start']));
			$startPlace_Id = $paths[0]['StartPlace_Id'];			
			$description = "$distance mailia, keskinopeus $speedAvg solmua"; //matka-aika $durationHours, 
			
			/* Koostetaan kml-aineisto */
			$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>{$paths[0]['GroupName']}</name><Style id='TrackStyle'><LineStyle><width>6.0</width><color>CC1400FF</color></LineStyle></Style><Placemark id='linestring1'><name>{$paths[0]['GroupName']} ($startDate - $endDate)</name><styleUrl>#TrackStyle</styleUrl><description>$description</description><LineString><altitudeMode>clampToGround</altitudeMode><coordinates>$coordinates</coordinates></LineString></Placemark>$kml_placemarks$kml_styles</Document></kml>";
			$kml = trim($kml);
			
			/* Määritellään reitin url */
			$removeChars = array('/å/', '/ä/', '/ö/', '/Å/', '/Ä/', '/Ö/');
			$replaceWith = array('a', 'a', 'o', 'A', 'A', 'O');
			$nameUrl = strtolower(preg_replace($removeChars, $replaceWith, str_replace(' ', '-', str_replace(',', '', $paths[0]['GroupName']))));
			
			/* Tallennetaan aineisto kantaan */
			$sql = $yhteys->prepare("
				UPDATE Path 
				SET Kml=:kml
				  , KmlPoints=:points
				  , Description=:description
				  , StartPlace_Id=:startId
				  , EndPlace_Id=:endId
				  , Duration=:duration
				  , Distance=:distance
				  , Speed_Avg=:speedAvg
				  , EngineHours=:eng_hours
				  , Visible=:visible
				  , Start=:startTimestamp
				  , End=:endTimestamp
				  , NameUrl=:nameUrl
				  , Ready=1
				WHERE Id=:id;
				");
			$sql->bindParam(':kml', $kml, PDO::PARAM_STR);
			$sql->bindParam(':points', $coordinates, PDO::PARAM_STR);
			$sql->bindParam(':distance', $distance, PDO::PARAM_STR);
			$sql->bindParam(':eng_hours', $eng_hours, PDO::PARAM_STR);
			$sql->bindParam(':duration', $durationHours, PDO::PARAM_STR);
 			$sql->bindParam(':speedAvg', $speedAvg, PDO::PARAM_STR);
			$sql->bindParam(':id', $id, PDO::PARAM_INT);
 			$sql->bindParam(':visible', $visible, PDO::PARAM_INT);		
 			$sql->bindParam(':description', $description, PDO::PARAM_STR);
 			$sql->bindParam(':startId', $startPlace_Id, PDO::PARAM_INT);
 			$sql->bindParam(':endId', $endPlace_Id, PDO::PARAM_INT);
 			$sql->bindParam(':startTimestamp', $paths[0]['Start'], PDO::PARAM_STR);
 			$sql->bindParam(':endTimestamp', end($paths)['End'], PDO::PARAM_STR);
 			$sql->bindParam(':nameUrl', $nameUrl, PDO::PARAM_STR);
			
			$sql->execute();

            return True;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }		


    public function ListPaths($imei, $uri = null, $noGroups = false, $onlyGroups = false) {

		if (!is_string($uri) || trim($uri) === '') {
			$uri = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$uri = preg_replace('#^https?://#i', '', trim($uri));
		
        $yhteys = new Mysql();
		
		if ($noGroups) {
			/* Haetaan laitteen kaikki matkat (ei koonteja) */
			$sql = $yhteys->prepare("
				SELECT 
					  Id
					, p.`Group`
					, CONCAT('https://', :uri, '/cache/kml/', p.Guid, '/') AS `Url`
					, CONCAT(DAY(p.Start), '.', MONTH(p.Start), '. ', p.Name) AS `Name`
					, p.NameUrl
					, p.Start
					, p.End
					, p.Ready
					, p.`Rename`
					, p.Visible
					, p.Distance
					, p.Duration
					, IFNULL(p.EngineHours, '') `EngineHours`
					, IFNULL(p.EngineHourMeter, '') `EngineHourMeter`			
					, ROUND(p.Speed_Avg, 1) AS `Speed_Avg`
				FROM Path p
				INNER JOIN Devices d ON p.Imei = d.Imei
				WHERE p.Imei=:imei
					AND p.`Group` = 0
					AND p.Visible = 1
				ORDER BY p.`Start`;
				");
			
		}
		else if ($onlyGroups) {
			/* Haetaan laitteen koontimatkat */
			$sql = $yhteys->prepare("SELECT Id FROM Path WHERE Imei=:imei AND `Group` = 1 AND Visible = 1");				
		}
		else {
			/* Haetaan laitteen kaikki matkat */
			$sql = $yhteys->prepare("
				SELECT 
					  Id
					, p.`Group`
					, CONCAT('https://', :uri, '/cache/kml/', p.Guid, '/') AS `Url`
					, CASE p.`Group` WHEN 1 THEN CONCAT('-- ', p.Name, ' (', DAY(p.Start), '.', MONTH(p.Start), '. - ', DAY(p.End), '.', MONTH(p.End), '.) --') ELSE CONCAT(DAY(p.Start), '.', MONTH(p.Start), '. ', p.Name) END AS `Name`
					, p.NameUrl
					, p.Start
					, p.End
					, p.Ready
					, p.`Rename`
					, p.Visible
					, p.Distance
					, p.Duration
					, IFNULL(p.EngineHours, '') `EngineHours`
					, IFNULL(p.EngineHourMeter, '') `EngineHourMeter`			
					, ROUND(p.Speed_Avg, 1) AS `Speed_Avg`
				FROM Path p
				INNER JOIN Devices d ON p.Imei = d.Imei
				WHERE p.Imei=:imei
					AND p.Visible = 1
				ORDER BY p.`Start`, p.`Group` DESC;
				");
		}
		
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		if (!$onlyGroups) $sql->bindParam(':uri', $uri, PDO::PARAM_STR);
		
        try {
            $sql->execute();
			$tiedot = $sql->fetchAll();
            return count($tiedot) > 0 ? $tiedot : NULL;
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }

	
	
    public function GetPath($imei, $path_Id = null, $uri = null) {

		if (!is_string($uri) || trim($uri) === '') {
			$uri = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$uri = preg_replace('#^https?://#i', '', trim($uri));
		
        $yhteys = new Mysql();
		
		/* Haetaan annetun matkan tiedot */
		$sql = $yhteys->prepare("
				SELECT CONCAT('https://', :uri, '/cache/kml/', Guid, '/') AS `Url`
				FROM Path 
				WHERE Imei=:imei
					AND Id=:path_Id;
				");
		$sql->bindParam(':path_Id', $path_Id, PDO::PARAM_INT);
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

			$sql = $yhteys->prepare("SELECT Kml FROM Path WHERE Guid=:guid;");
			$sql->bindParam(':guid', $args[0], PDO::PARAM_STR);
			$kmlName = $args[0];
			
		}
		/* Haetaan url-osoitteen mukaan */
		else {
			
			$nameUrl = implode('/', $args);
			$kmlName = str_replace('/', '-', $nameUrl);
			$sql = $yhteys->prepare("SELECT Kml FROM Path WHERE NameUrl=:url;");
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

	
    public function GetLastPositionUrl($imei, $uri) {

		if (!is_string($uri) || trim($uri) === '') {
			$uri = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$uri = preg_replace('#^https?://#i', '', trim($uri));
		
        $yhteys = new Mysql();
		
		/* Haetaan laitteen viimeisin sijainti (jos sellainen löytyy viimeisen 10 minuutin ajalta) */
        $sql = $yhteys->prepare("
			SELECT 
				  CONCAT('https://', :uri, '/cache/live/', d.Guid, '/') AS `UrlLast`
				, CONCAT('https://', :uri, '/cache/live-info/', d.Guid, '/') AS `UrlInfo`
				, CASE WHEN d.LastUpdated > DATE_ADD(NOW(),INTERVAL -10 MINUTE) THEN true ELSE false END `Live`
			FROM Devices d 
			INNER JOIN DataArchive a ON d.LastPosition_Id = a.Id 
			WHERE d.Imei=:imei;
			");
		$sql->bindParam(':imei', $imei, PDO::PARAM_STR);
		$sql->bindParam(':uri', $uri, PDO::PARAM_STR);
		
        try {
			
            $sql->execute();
			$tiedot = $sql->fetchAll();						
			return $tiedot;
		
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }


    public function GetLastPositionInfo($guid) {
		
        $yhteys = new Mysql();
		
		/* Haetaan matkan viimeisin ajankohta */
        $sql = $yhteys->prepare("
			SELECT 
				  CONCAT(
					'Sijainti päivitetty ', 
					DATE_FORMAT(a.Timestamp, '%e.%c.%Y klo %H:%i'), '. Nopeus ',
					IFNULL(a.Speed, 0.00), ' solmua, suunta ',
					IFNULL(a.Bearing, (SELECT Bearing FROM DataArchive WHERE Timestamp <= d.LastUpdated AND Imei = d.Imei AND Bearing IS NOT NULL ORDER BY Timestamp DESC LIMIT 1)), ' astetta.') AS `Info`
				, IFNULL(a.Bearing, (SELECT Bearing FROM DataArchive WHERE Timestamp <= d.LastUpdated AND Imei = d.Imei AND Bearing IS NOT NULL ORDER BY Timestamp DESC LIMIT 1)) AS `Bearing`
				, IFNULL(a.Speed, 0.00) AS `Speed`
				, a.Lon
				, a.Lat
				, CASE WHEN d.LastUpdated > DATE_ADD(NOW(),INTERVAL -10 MINUTE) THEN true ELSE false END `Live`
			FROM Devices d 
			INNER JOIN DataArchive a ON d.LastPosition_Id = a.Id 
			WHERE d.Guid=:guid;
			");
		$sql->bindParam(':guid', $guid, PDO::PARAM_STR);
				
        try {
			
            $sql->execute();
			$tiedot = $sql->fetchAll();						
			return $tiedot;
		
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }	

    public function GetLastPosition($guid) {
		
        $yhteys = new Mysql();
		
		/* Haetaan matkan viimeisin ajankohta */
        $sql = $yhteys->prepare("
			SELECT 
				  a.Lat
				, a.Lon
				, a.Timestamp
				, IFNULL(a.Bearing, (SELECT Bearing FROM DataArchive WHERE Timestamp <= d.LastUpdated AND Imei = d.Imei AND Bearing IS NOT NULL ORDER BY Timestamp DESC LIMIT 1)) AS `Bearing`
				, IFNULL(a.Speed, 0.00) AS `Speed`
			FROM Devices d 
			INNER JOIN DataArchive a ON d.LastPosition_Id = a.Id 
			WHERE d.Guid=:guid;
			");
		$sql->bindParam(':guid', $guid, PDO::PARAM_STR);
				
        try {
			
            $sql->execute();
			$tiedot = $sql->fetchAll();
						
			if (count($tiedot) > 0) {
				
				$time = date('j.n.Y H:i', strtotime($tiedot[0]['Timestamp']));
				
				/* Muodostetaan koordinaattitietue */	
				$newName = "Viimeisin sijainti";					
				$kml_placemarks = "<Placemark><name>{$tiedot[0]['Speed']} kn @ {$tiedot[0]['Bearing']} astetta</name><description>{$time}</description><styleUrl>#placemark-now</styleUrl><Point><coordinates>{$tiedot[0]['Lon']},{$tiedot[0]['Lat']},0.00</coordinates></Point></Placemark>";
				$kml_styles = "<Style id='placemark-now'><styleUrl>#placemark-now</styleUrl><IconStyle><color>ffFFFFFF</color><scale>1.1</scale><Icon><href>https://tracker.rantojenmies.com/cache/image/{$tiedot[0]['Bearing']}/</href></Icon></IconStyle><LabelStyle><scale>7</scale></LabelStyle></Style>";
				
				/* Koostetaan kml-aineisto */
				$kml = "<?xml version='1.0' encoding='UTF-8'?><kml xmlns='http://earth.google.com/kml/2.2'><Document><name>$newName</name>$kml_placemarks$kml_styles</Document></kml>";
				$kml = trim($kml);		
				
				Header('Content-Type:text/xml');
				Header('Content-Disposition: attachment; filename="'.$guid.'-'.$tiedot[0]['Timestamp'].'.kml"');
				Header('Content-Length: '.strlen($kml));
				Header('Connection: close');
			}
			else {
				$kml = "Position unknown.";
			}
			
			return $kml;
		
        }
        catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }  

    }
	
	
    public function GetImage($bearing = 0) {
		
		header('Content-type: image/png');
		$filename = 'https://tracker.rantojenmies.com/ico/here-boat.png';
		$rotang = 360-$bearing; // Rotation angle
		
		$source = imagecreatefrompng($filename);
		if ($source === false) {
			throw new Exception('Error opening file '.$filename);
		}
		// imagealphablending($source, false);
		// imagesavealpha($source, TRUE);

		$color = imagecolorallocatealpha($source, 0, 0, 0, 127);
		$rotation = imagerotate($source, $rotang, $color, 1);
		imagealphablending($rotation, false);
		imagecolortransparent($rotation, $color);
		imagesavealpha($rotation, TRUE);

		$output = imagepng($rotation);
		
		imagedestroy($source);
		imagedestroy($rotation);		
			
		return $output;
		
		

    }
		
	
}
?>