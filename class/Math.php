<?php

class Math {

	/**
	 * Calculates the great-circle distance between two points, with
	 * the Vincenty formula.
	 * @param float $latitudeFrom Latitude of start point in [deg decimal]
	 * @param float $longitudeFrom Longitude of start point in [deg decimal]
	 * @param float $latitudeTo Latitude of target point in [deg decimal]
	 * @param float $longitudeTo Longitude of target point in [deg decimal]
	 * @param float $earthRadius Mean earth radius in [m]
	 * @return float Distance between points in [m] (same as earthRadius)
	 */
	 
	public static function CalculateGCDB($edellinen, $uusi, $distanceOnly = false, $earthRadius = 6371000) {
		
		$latitudeFrom = $edellinen['Lat'];
		$longitudeFrom = $edellinen['Lon'];
		$latitudeTo = $uusi['Lat'];
		$longitudeTo = $uusi['Lon'];		
				
		if ($edellinen['Lat'] == $uusi['Lat'] && $edellinen['Lon'] == $uusi['Lon']) {
			
			$results['distance'] = 0;
			$results['bearing'] = 0;
			
		}
		else {
			
			// convert from degrees to radians
			$latFrom = deg2rad($latitudeFrom);
			$lonFrom = deg2rad($longitudeFrom);
			$latTo = deg2rad($latitudeTo);
			$lonTo = deg2rad($longitudeTo);
			
			$lonDelta = $lonTo - $lonFrom;
			$a = pow(cos($latTo) * sin($lonDelta), 2) + pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
			$b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

			$angle = atan2(sqrt($a), $b);
			$results['distance'] = $angle * $earthRadius;
			$results['bearing'] = !$distanceOnly ? self::Bearing($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo) : null;

		}
		
		return $results;
		
	}

	
	public static function Bearing($lat1, $lon1, $lat2, $lon2) {
		
		//difference in longitudinal coordinates
		$dLon = deg2rad($lon2) - deg2rad($lon1);

		//difference in the phi of latitudinal coordinates
		$dPhi = log(tan(deg2rad($lat2) / 2 + pi() / 4) / tan(deg2rad($lat1) / 2 + pi() / 4));

		//we need to recalculate $dLon if it is greater than pi
		if(abs($dLon) > pi()) {
			if($dLon > 0) {
			  $dLon = (2 * pi() - $dLon) * -1;
			}
			else {
			  $dLon = 2 * pi() + $dLon;
			}
		}
		//return the angle, normalized
		return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360;
		
	}	


	public static function SumTimes($time1 = "00:00:00", $time2 = "00:00:00"){
			$time2_arr = [];
			$time1 = $time1;
			$time2_arr = explode(":", $time2);
			//Hour
			if(isset($time2_arr[0]) && $time2_arr[0] != ""){
				$time1 = $time1." +".$time2_arr[0]." hours";
				$time1 = date("H:i:s", strtotime($time1));
			}
			//Minutes
			if(isset($time2_arr[1]) && $time2_arr[1] != ""){
				$time1 = $time1." +".$time2_arr[1]." minutes";
				$time1 = date("H:i:s", strtotime($time1));
			}
			//Seconds
			if(isset($time2_arr[2]) && $time2_arr[2] != ""){
				$time1 = $time1." +".$time2_arr[2]." seconds";
				$time1 = date("H:i:s", strtotime($time1));
			}

			return date("H:i:s ", strtotime($time1));
	}
	
}


?>