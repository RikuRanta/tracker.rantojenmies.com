<?php
 
$_debug = strtolower((string)getenv('TRACKER_DEBUG')) === 'true';
ini_set('error_reporting', $_debug ? E_ALL : (E_ERROR | E_WARNING));
ini_set('display_errors', $_debug ? '1' : '0');
ini_set('include_path', $_GLOBALS['dir']);
set_time_limit (0);
ob_start();

require_once('Mysql.php');
require_once('API.php');
require_once('Data.php');
require_once('Path.php');
require_once('Place.php');
require_once('Events.php');

/**
  * GPS Trackerin avoin rajapinta
  *
  */
	  
class TrackerAPI extends API {
	
    protected $user;
	
	protected $imei = null;

	protected $testmode;

	protected $path_Id;
	
	protected $place_Id;

	protected $uri;

	private function base64url_decode($data) {
		$remainder = strlen($data) % 4;
		if ($remainder) $data .= str_repeat('=', 4 - $remainder);
		return base64_decode(strtr($data, '-_', '+/'));
	}

	private function isValidAppToken($token, $imei) {
		if (!is_string($token) || trim($token) === '') return false;
		if (!is_string($imei) || trim($imei) === '') return false;

		$secret = getenv('TRACKER_SHARED_SECRET');
		if ($secret === false || trim($secret) === '') return false;

		$parts = explode('.', $token);
		if (count($parts) !== 2) return false;

		$payloadEncoded = $parts[0];
		$signatureEncoded = $parts[1];
		$signature = $this->base64url_decode($signatureEncoded);
		if ($signature === false) return false;

		$expectedSignature = hash_hmac('sha256', $payloadEncoded, $secret, true);
		if (!hash_equals($expectedSignature, $signature)) return false;

		$payloadJson = $this->base64url_decode($payloadEncoded);
		if ($payloadJson === false) return false;
		$payload = json_decode($payloadJson, true);
		if (!is_array($payload)) return false;

		if (!isset($payload['imei']) || (string)$payload['imei'] !== (string)$imei) return false;
		if (!isset($payload['iat']) || !isset($payload['exp'])) return false;

		$now = time();
		$iat = (int)$payload['iat'];
		$exp = (int)$payload['exp'];

		if ($exp < $now) return false;
		if ($iat > ($now + 60)) return false;

		return true;
	}
	
    public function __construct($request, $origin) {
		
		parent::__construct($request);
				
		if (isset($this->endpoint) && $this->endpoint != 'cache') {
		
			if (!array_key_exists('X-Api-Token', $this->headers)) { 
				throw new Exception('No API token provided', 401); 
			} 
			else if (!array_key_exists('X-Api-Imei', $this->headers)) {
				throw new Exception('No IMEI found', 400);
			} 
			else if (!$this->isValidAppToken($this->headers['X-Api-Token'], $this->headers['X-Api-Imei'])) {
				throw new Exception('Invalid API token', 401);
			}
			
			$this->imei = $this->headers['X-Api-Imei'];
			$this->path_Id = isset($this->headers['X-Api-Pathid']) && $this->headers['X-Api-Pathid'] != '' ? $this->headers['X-Api-Pathid'] : null;
			$this->place_Id = isset($this->headers['X-Api-Placeid']) && $this->headers['X-Api-Placeid'] != '' ? $this->headers['X-Api-Placeid'] : null;
			$this->testmode = isset($this->headers['X-Api-Testmode']) && strtolower($this->headers['X-Api-Testmode']) == "true" ? $this->headers['X-Api-Testmode'] : False;
			$this->uri = $_SERVER['HTTP_HOST'];

		}
		
	}


	/**
	 * Endpoint: init
	 * 
	 */

	protected function init() {
		
		$path = new Path();
		$place = new Place();
		$events = new Events();
		$output = null;
		
		/** Tietojen haku (GET) */
		if ($this->method == 'GET') {
						
			$output['paths'] = $path->ListPaths($this->imei, $this->uri);
			$output['places'] = $place->ListPlaces($this->imei, $this->uri);
			$output['events'] = $events->ListEvents($this->imei, $this->uri);
			$output['live'] = $path->GetLastPositionUrl($this->imei, $this->uri);
				
		}	
		else { 
			throw new Exception('Only accepts GET requests', 405); 
		}

		return $output;
	}

	
	/**
	 * Endpoint: path
	 * 
	 */

	protected function path() {
		
		$path = new Path();
		$output = null;
		
		/** Tietojen haku (GET) */
		if ($this->method == 'GET') {
						
			if ($this->verb) {
			
					switch ($this->verb) {
							
					/** 
					 * GET:/path/list
					 * Listataan laitteen kaikki matkat 
					 */
					case 'list':
						$output = $path->ListPaths($this->imei, $this->uri);
					break;	

					/** 
					 * GET:/path/live-status
					 * Palauttaa kml-tiedoston 
					 */
					case 'live-status':
						$output = $path->GetLastPositionUrl($this->imei, $this->uri);
					break;

					/**
					 * GET:/path/manual-points/<path-id>
					 * Listataan reitin manuaaliset pisteet
					 */
					case 'manual-points':
						$manualPathId = isset($this->args[0]) ? $this->args[0] : $this->path_Id;
						if (!is_numeric($manualPathId)) {
							throw new Exception('Provided path id is wrong type. Expecting numeric.', 400);
						}
						$output = $path->ListManualPoints($this->imei, $manualPathId);
					break;

					/**
					 * GET:/path/edit-points/<path-id>
					 * Listataan muokattavat reittipisteet
					 */
					case 'edit-points':
						$editPathId = isset($this->args[0]) ? $this->args[0] : $this->path_Id;
						if (!is_numeric($editPathId)) {
							throw new Exception('Provided path id is wrong type. Expecting numeric.', 400);
						}
						$output = $path->ListEditablePoints($this->imei, $editPathId);
					break;

					default:
						throw new Exception('Unknown path verb', 400);
					break;
					
				}
				
			}
			else {
				
				/** 
				 * GET:/path
				 * Yksittäinen matka
				 */
				$output = $path->GetPath($this->imei, $this->path_Id, $this->uri);
				
			}
			
		}	
		
		/** Tietojen muutokset (POST) */
		else if ($this->method == 'POST') {

			if ($this->verb) {
			
				switch ($this->verb) {
							
					/** 
					 * POST:/path/generate-kml
					 * Muodostetaan kml-aineisto uudestaan
					 */
					case 'generate-kml':
						$output['result'] = $this->path_Id ? $path->GenerateKml($this->path_Id, $this->imei) : $path->RedoKml($this->imei);
					break;

					/** 
					 * POST:/path/generate-group-kml
					 * Muodostetaan koontimatkojen kml-aineisto uudestaan
					 */
					case 'generate-group-kml':
						$output['result'] = $path->RedoGroupKml($this->imei, null, $this->path_Id);
					break;

					/**
					 * POST:/path/manual-point
					 * Lisää manuaalinen reittipiste
					 */
					case 'manual-point':
						$output['result'] = $path->AddManualPoint($this->imei, $this->path_Id, $this->file);
					break;

					/**
					 * POST:/path/manual-point-delete
					 * Poistaa manuaalisen reittipisteen
					 */
					case 'manual-point-delete':
						$output['result'] = $path->DeleteManualPoint($this->imei, $this->path_Id, $this->file);
					break;

					/**
					 * POST:/path/edit-point
					 * Päivitä yksittäisen reittipisteen sijainti
					 */
					case 'edit-point':
						$output['result'] = $path->UpdateEditablePoint($this->imei, $this->path_Id, $this->file);
					break;


					/** 
					 * POST:/path/<id>
					 * Tallennetaan matkan tiedot
					 */
					default:
						
						if (!is_numeric($this->verb)) {
							throw new Exception('Provided id is wrong type. Expecting numeric.', 400);
						}
						$this->path_Id = $this->verb;
						$output = $path->UpdatePathGeneral($this->path_Id, $this->imei, $this->file);
						
					break;


				}
				
				
			}
			else {

				throw new Exception('Verb missing', 400);

			}
					
		}
		
		else { 
			throw new Exception('Unsupported method', 405); 
		}

		return $output;
	}

	
	
	/**
	 * Endpoint: place
	 * 
	 */

	protected function place() {
		
		$place = new Place();
		$output = null;
		
		/** Tietojen haku (GET) */
		if ($this->method == 'GET') {
						
			if ($this->verb) {
			
				switch ($this->verb) {
							
					/** 
					 * GET:/place/list
					 * Listataan laitteen kaikki kohteet 
					 */
					case 'list':
						$output = $place->ListPlaces($this->imei, $this->uri);
					break;	

					}
				
			}
			else {
				
				/** 
				 * GET:/place
				 * Yksittäinen paikka
				 */
				// $output = $place->GetPlace($this->imei, $this->path_Id, $this->uri);
				
			}
			
		}	
		
		/** Tietojen muutokset (POST) */
		else if ($this->method == 'POST') {

			if ($this->verb) {
			
				switch ($this->verb) {
							
					/** 
					 * POST:/place/generate-summary-kml
					 * Muodostetaan kml-aineisto kaikista kohteista
					 */
					case 'generate-summary-kml':
						$output['result'] = $place->GenerateSummaryKml($this->imei);
					break;
					
					/** 
					 * POST:/place/<name-url>
					 * Päivitetään sataman tieto
					 */
					default:
						if (!isset($this->verb) || $this->verb === '') {
							throw new Exception('Place name missing', 400);
						}
						$output['result'] = $place->UpdateInfo($this->imei, $this->verb, $this->file);
					break;
					
				}
								
			}
			else {
				throw new Exception('Verb missing', 400); 
			}
					
		}
		
		else { 
			throw new Exception('Unsupported method', 405); 
		}

		return $output;
	}	
	


	/**
	 * Endpoint: events
	 * 
	 */

	protected function events() {
		
		$events = new Events();
		$output = null;
		
		/** Tietojen haku (GET) */
		if ($this->method == 'GET') {
						
			if ($this->verb) {
			
				switch ($this->verb) {
							
					/** 
					 * GET:/events/list
					 * Listataan kaikki tapahtumat
					 */
					case 'list':
						$output = $events->ListEvents($this->imei, $this->uri);
					break;	

					default:
						throw new Exception('Unknown events verb', 400);
					break;

					}
				
			}
			else {
				
				/** 
				 * GET:/place
				 * Yksittäinen paikka
				 */
				// $output = $place->GetPlace($this->imei, $this->path_Id, $this->uri);
				
			}
			
		}	
		
		else { 
			throw new Exception('Unsupported method', 405); 
		}

		return $output;
	}
	
	

	/**
	 * Endpoint: cache
	 * 
	 */

	protected function cache() {
		
		$path = new Path();
		$place = new Place();
		$events = new Events();
		$output = null;
		
		/** Tietojen haku (GET) */
		if ($this->method == 'GET') {
						
			if ($this->verb) {
			
				switch ($this->verb) {
							
					/** 
					 * GET:/cache/kml/<guid>
					 * Palauttaa kml-tiedoston 
					 */
					case 'kml':
						$this->noJson = True;
						$output = $path->GetKml($this->args);
					break;

					/** 
					 * GET:/cache/kml-events/<guid>
					 * Palauttaa tapahtuman kml-tiedoston 
					 */
					case 'kml-events':
						$this->noJson = True;
						$output = $events->GetKml($this->args);
					break;

					/** 
					 * GET:/cache/live/<guid>
					 * Palauttaa kml-tiedoston LIVE!-sijainnista
					 */
					case 'live':
						$this->noJson = True;
						if (!isset($this->args[0]) || $this->args[0] === '') {
							throw new Exception('Guid missing', 400);
						}
						$output = $path->GetLastPosition($this->args[0]);
					break;

					/** 
					 * GET:/cache/live-info/<guid>
					 * Palauttaa LIVE!-sijainnin tiedot
					 */
					case 'live-info':
						if (!isset($this->args[0]) || $this->args[0] === '') {
							throw new Exception('Guid missing', 400);
						}
						$output = $path->GetLastPositionInfo($this->args[0]);
					break;
					
					/** 
					 * GET:/cache/image/<suunta>
					 * Palauttaa kuvan
					 */
					case 'image':
						$this->noJson = True;
						$output = isset($this->args[0]) ? $path->GetImage($this->args[0]) : $path->GetImage();
					break;
					
					/** 
					 * GET:/cache/place/<name-url>
					 * Palauttaa sataman kml-tiedoston 
					 */
					case 'place':
						$this->noJson = True;
						if (!isset($this->args[0]) || $this->args[0] === '') {
							throw new Exception('Place name missing', 400);
						}
						$output = $place->GetKml($this->args[0]);
					break;
					
					
				}
				
			}
			else {
				throw new Exception('Verb missing', 400); 
			}
			
		}	
		
		else { 
			throw new Exception('Only accepts GET requests', 405); 
		}

		return $output;
	}
	
	
 }
?>