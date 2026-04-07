<?php

require_once('config.php');
require_once('php/General.php');

function base64url_encode($data) {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_tracker_api_token($imei) {
	$secret = getenv('TRACKER_SHARED_SECRET');
	if ($secret === false || trim($secret) === '') {
		throw new Exception('Missing TRACKER_SHARED_SECRET for API token signing.');
	}

	$iat = time();
	$payload = [
		'imei' => (string)$imei,
		'iat' => $iat,
		'exp' => $iat + 3600,
	];
	$payloadEncoded = base64url_encode(json_encode($payload));
	$signature = hash_hmac('sha256', $payloadEncoded, $secret, true);
	$signatureEncoded = base64url_encode($signature);

	return $payloadEncoded.'.'.$signatureEncoded;
}
	
$site_baseUrl = url();	
$tracker_apiUrl = 'https://tracker.rantojenmies.com';
$tracker_apiToken = create_tracker_api_token($config_imei);
$appJsVersion = @filemtime(__DIR__.'/merikortti/app.js');
if (!$appJsVersion) {
	$appJsVersion = time();
}

/* Defaultit */
$toiminnot = array(
	'apiUrl'=>$tracker_apiUrl.'/init', 
	'callback'=>'initLog', 
	'livePosition'=>'false',
	'activePath'=>'',
	'activePlace'=>'',
	'activeEvent'=>'',
	'siteFolder'=>$config_siteFolder
	);
	
if (isset($_GET['request'])) {
	
	$request = strtolower(rtrim(_cleanInputs($_GET['request']), '/'));
	$args = explode('/', $request);
		
	switch ($args[0]) {
		
		case 'sijainti-nyt':
			$toiminnot['livePosition'] = 'true';
			$toiminnot['callback'] = 'initLivePosition';
			break;
		
		case 'satamat':
			$toiminnot['activePlace'] = isset($args[1]) && $args[1] != '' ? $args[1] : '';
			$toiminnot['callback'] = 'initPlaces';
			break;

		case 'tapahtumat':
			$toiminnot['activeEvent'] = str_replace('tapahtumat/', '', $request);
			$toiminnot['callback'] = 'initEvents';
			break;
	
		default:
			$toiminnot['activePath'] = $request;
			break;
	}
	
}

$input_places_desc = '';
$input_places_name = '';
$button_places_kml = '';
$input_track_enginehours = '';
$button_group_kml = '';
$button_track_editmode = '';
$buttons_track_edit_savecancel = '';
$tracker_api_PostUrl = $tracker_apiUrl.'/init';

if ($kirjautunut) {
	
	$input_places_desc = '<img id="places-desc-edit" style="float:right; margin:5px;width:30px;height:30px;" src="'.$site_baseUrl.'/img/edit-white.png" /><input type="text" class="c-menu--input" style="display:none;" id="places-desc-input" value="" placeholder="Kohteen kuvaus" /><img id="places-desc-cancel" style="display:none;float:right; margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/cancel-white.png" /><img id="places-desc-save" style="display:none;float:right;margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/save-white.png" />';

	$input_places_name = '<img id="places-name-edit" style="display:none;float:right; margin:12px;width:30px;height:30px;" src="'.$site_baseUrl.'/img/edit-white.png" /><input type="text" class="c-menu--input" style="display:none;" id="places-name-input" value="" /><img id="places-name-cancel" style="display:none;float:right; margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/cancel-white.png" /><img id="places-name-save" style="display:none;float:right;margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/save-white.png" />';

	$button_places_kml = '<button id="places-summarykml-regen" class="loki-btn">Generoi kaikki satamat uudelleen</button>';

	$input_track_enginehours = '<img id="track-enginehours-edit" style="float:right; margin:10px;width:30px;height:30px;" src="'.$site_baseUrl.'/img/edit-white.png" /><input type="number" class="c-menu--input" style="display:none;" id="track-enginehours-input" value="" /placeholder="K&auml;ytt&ouml;tuntimittarin lukema" ><img id="track-enginehours-cancel" style="display:none;float:right; margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/cancel-white.png" /><img id="track-enginehours-save" style="display:none;float:right;margin:10px 5px 10px 0;width:30px;height:30px;" src="'.$site_baseUrl.'/img/save-white.png" />';

	$button_group_kml = '<button id="track-groupkml-regen" class="loki-btn">Generoi reitti uudelleen</button>';
	$button_track_editmode = '<button id="track-editmode-toggle" class="loki-btn">Aloita muokkaus</button>';
	$buttons_track_edit_savecancel = '<button id="track-edit-save" class="loki-btn loki-btn--primary" style="display:none;">Tallenna muutokset</button><button id="track-edit-cancel" class="loki-btn loki-btn--danger" style="display:none;">Peruuta muutokset</button>';

	$tracker_api_PostUrl = '';
}

$output = '<!DOCTYPE html>
<html>
<head>';

/* Ei Google Analytics -seurantaa kirjautuneille */
if (!$kirjautunut) {

	$output .= '<!-- Matomo Tag Manager -->
	<script>
	  var _mtm = window._mtm = window._mtm || [];
	  _mtm.push({\'mtm.startTime\': (new Date().getTime()), \'event\': \'mtm.Start\'});
	  (function() {
		var d=document, g=d.createElement(\'script\'), s=d.getElementsByTagName(\'script\')[0];
		g.async=true; g.src=\'https://www.rantojenmies.com/wp-content/plugins/matomo/app/../../../uploads/matomo/container_Y4e267KL.js\'; s.parentNode.insertBefore(g,s);
	  })();
	</script>
	<!-- End Matomo Tag Manager -->';	
}

$output .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta content="authenticity_token" name="csrf-param" />
<title>'.$config_siteName.'</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@v10.6.1/ol.css" >
<link href="https://fonts.googleapis.com/css?family=Lato&subset=latin,latin-ext" rel="stylesheet" type="text/css">
<link rel="stylesheet" href="'.$site_baseUrl.'/styles/styles.css" type="text/css">
<link rel="stylesheet" href="'.$site_baseUrl.'/styles/menu.css" type="text/css">
<script type="text/javascript" src="'.$site_baseUrl.'/js/jquery.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/jquery-dateFormat.min.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/menu.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/onReady.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/cors.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1" />
</head>

<body>

<div id="o-wrapper" class="o-wrapper">
   <div id="map"></div>
</div>

<nav id="c-menu--slide-bottom" class="c-menu c-menu--slide-bottom right">
  <div id="position-info"></div>
  <button class="c-menu__close">X Sulje</button>
</nav>

<!-- Menu alkaa -->
<div id="c-button--slide-left" class="c-button c-menu--open"></div>
<nav id="c-menu--slide-left" class="c-menu c-menu--slide-left">
  <button class="c-menu__close"><span id="menu-header">Loki</span> <span class="right">X</span></button>
  <ul class="c-menu__items">
  
	<!-- Loki (reitit) -->
	<li class="c-menu__item track"><a id="track-tracks" class="c-menu__link"><select id="path" class="loki-select"></select></a></li>	
	<li class="c-menu__item track"><a id="track-date" class="c-menu__link"></a></li>
	<li class="c-menu__item track"><a id="track-time" class="c-menu__link"></a></li>
	<li class="c-menu__item track"><a id="track-duration" class="c-menu__link"></a></li>
	<li class="c-menu__item track"><a id="track-distance" class="c-menu__link"></a></li>
	<li class="c-menu__item track"><a id="track-speed" class="c-menu__link"></a></li>
	<li class="c-menu__item track">'.$input_track_enginehours.'<a id="track-enginehours" class="c-menu__link"></a></li>
	<li class="c-menu__item track">'.$button_track_editmode.'</li>
	<li class="c-menu__item track">'.$buttons_track_edit_savecancel.'</li>
	<li class="c-menu__item track">'.$button_group_kml.'</li>
	<!-- <li class="c-menu__item track"><a id="track-sticky-link" class="c-menu__link"></a></li> -->

	<!-- Satamat -->
	<li class="c-menu__item places">'.$input_places_name.'<a id="places-places" class="c-menu__link"><select id="place" class="loki-select"></select></a></li>	
	<li class="c-menu__item places">'.$button_places_kml.'</li>
	<li class="c-menu__item places desc" style="display:none;">'.$input_places_desc.'<a id="places-desc" class="c-menu__link"></a></li>
	<li class="c-menu__item places visited" style="display:none;"><a id="places-visited" class="c-menu__link"></a></li>
	<li class="c-menu__item places visited-paths" style="display:none;"><a id="places-visited-paths" class="c-menu__link"></a></li>
	<!-- <li class="c-menu__item places"><a id="places-sticky-link" class="c-menu__link"></a></li> -->

	<!-- Tapahtumat -->
	<li class="c-menu__item events"><a id="events-events" class="c-menu__link"><select id="events" class="loki-select"></select></a></li>	
	<li class="c-menu__item events datetime" style="display:none;"><a id="events-datetime" class="c-menu__link"></a></li>
	<li class="c-menu__item events desc" style="display:none;"><a id="events-desc" class="c-menu__link"></a></li>
	<li class="c-menu__item events amount" style="display:none;"><a id="events-amount" class="c-menu__link"></a></li>
	<li class="c-menu__item events price" style="display:none;"><a id="events-price" class="c-menu__link"></a></li>
	<li class="c-menu__item events enginehours" style="display:none;"><a id="events-enginehours" class="c-menu__link"></a></li>
	
	<!-- Toiminnot -->
	<li class="c-menu__item actions-menu">
		<a id="actions-menu" class="c-menu__link">
			<div class="icons rantojenmies" id="rantojenmies" title="Takaisin blogiin"></div>
			<div class="icons track" id="track" title="Loki" data-callback="initLog"></div>
			<div class="icons places" id="places" title="Satamat" data-callback="initPlaces"></div>
			<div class="icons events" id="events" title="Tapahtumat" data-callback="initEvents"></div>
			<div class="icons user" id="user" title="Kirjaudu"></div>
			<div class="icons live-passive" id="live" title="Sijainti" data-disabled="true"></div>
		</a>
	</li>
  </ul>
</nav>
<!-- Menu päättyy -->

<div id="c-loading"><p><img id="loading" src="'.$site_baseUrl.'/img/loading-box.gif" /><br />Aligning Stars with the Moon...</p></div>
<div id="c-mask" class="c-mask"></div>

<script type="text/javascript">
	var livePosition = '.$toiminnot['livePosition'].';
	var livePositionEnabled = "unset";
	var refreshInterval = 30;
	var activePath = "'.$toiminnot['activePath'].'";
	var activePlace = "'.$toiminnot['activePlace'].'";
	var activeEvent = "'.$toiminnot['activeEvent'].'";
	const trackerUserAuthenticated = '.($kirjautunut ? 'true' : 'false').';
	const siteHeader = "'.$config_siteName.'";
	const siteFolder = "'.$config_siteFolder.'";
</script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/initMenu.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/js/initMap.js"></script>
<script type="text/javascript" src="'.$site_baseUrl.'/merikortti/masks-data.js"></script>
<script type="text/javascript">
	var callback = '.$toiminnot['callback'].'; 
	var url = "'.$toiminnot['apiUrl'].'";
	var apiUrl = "'.$tracker_apiUrl.'";
	var headers = {
		"X-Api-Token": "'.$tracker_apiToken.'", 
		"X-Api-Imei": "'.$config_imei.'"
	};	
</script>
<script type="module" src="'.$site_baseUrl.'/merikortti/app.js?v='.$appJsVersion.'"></script>

</body>
</html>';

echo $output;

?>
