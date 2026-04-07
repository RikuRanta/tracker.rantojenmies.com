<?php
declare(strict_types=1);

/**
 * Minimal Traficom tile proxy.
 *
 * Flow:
 * 1) Validate layer/z/col/row
 * 2) Serve cached tile if present
 * 3) Fetch from Traficom WMTS
 * 4) Save to disk cache
 * 5) Return tile body
 */

const TILE_CACHE_DIR = __DIR__ . '/tile-cache';
const TILE_PROXY_ALLOWED_HOST = 'julkinen.traficom.fi';
const TILE_PROXY_CONNECT_TIMEOUT = 3;
const TILE_PROXY_TIMEOUT = 20;
const TILE_BROWSER_MAX_AGE = 86400;

const TILE_PROXY_EMPTY_PNG =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQ' .
	'AABjkB6QAAAABJRU5ErkJggg==';

function layer_slug_to_wmts(string $slug): ?string {
	$known = [
		'traficom_yleiskartat_250k_public' => 'Traficom:Yleiskartat 250k public',
		'traficom_merikarttasarjat_public' => 'Traficom:Merikarttasarjat public',
		'traficom_rannikkokartat_public' => 'Traficom:Rannikkokartat public',
		'traficom_veneilykartat_public' => 'Traficom:Veneilykartat public',
		'traficom_satamakartat' => 'Traficom:Satamakartat',
	];

	return $known[$slug] ?? null;
}

function send_empty_png(string $cacheState): void {
	header('Content-Type: image/png');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	header('X-Tile-Cache: ' . $cacheState);
	echo base64_decode(TILE_PROXY_EMPTY_PNG);
	exit;
}

function send_png_file(string $path, string $cacheState): void {
	header('Content-Type: image/png');
	header('Cache-Control: public, max-age=' . TILE_BROWSER_MAX_AGE);
	header('X-Content-Type-Options: nosniff');
	header('X-Tile-Cache: ' . $cacheState);
	readfile($path);
	exit;
}

function send_png_body(string $body, string $cacheState): void {
	header('Content-Type: image/png');
	header('Cache-Control: public, max-age=' . TILE_BROWSER_MAX_AGE);
	header('X-Content-Type-Options: nosniff');
	header('X-Tile-Cache: ' . $cacheState);
	echo $body;
	exit;
}

function is_nearly_blank_png(string $body): bool {
	if (!function_exists('imagecreatefromstring')) {
		return false;
	}
	$img = @imagecreatefromstring($body);
	if ($img === false) {
		return false;
	}
	$w = imagesx($img);
	$h = imagesy($img);
	if ($w <= 0 || $h <= 0) {
		imagedestroy($img);
		return false;
	}
	$step = max(1, (int)floor(min($w, $h) / 24));
	$total = 0;
	$nonBlank = 0;
	for ($py = 0; $py < $h; $py += $step) {
		for ($px = 0; $px < $w; $px += $step) {
			$rgba  = imagecolorat($img, $px, $py);
			$alpha = ($rgba >> 24) & 0x7F;
			$red   = ($rgba >> 16) & 0xFF;
			$green = ($rgba >> 8)  & 0xFF;
			$blue  = $rgba         & 0xFF;
			$total++;
			if ($alpha < 120 && !($red >= 240 && $green >= 240 && $blue >= 240)) {
				$nonBlank++;
			}
		}
	}
	imagedestroy($img);
	return $total > 0 && ($nonBlank / $total) < 0.005;
}

$layer = isset($_GET['layer']) ? (string)$_GET['layer'] : '';
$z = isset($_GET['z']) ? (string)$_GET['z'] : '';
$col = isset($_GET['col']) ? (string)$_GET['col'] : '';
$row = isset($_GET['row']) ? (string)$_GET['row'] : '';

if (!preg_match('/^[a-z0-9_-]+$/', $layer)) {
	http_response_code(400);
	exit('Invalid layer');
}

if (!ctype_digit($z) || !ctype_digit($col) || !ctype_digit($row)) {
	http_response_code(400);
	exit('Invalid tile coordinates');
}

$zoom = (int)$z;
$tileCol = (int)$col;
$tileRow = (int)$row;

$wmtsLayer = layer_slug_to_wmts($layer);
if ($wmtsLayer === null) {
	http_response_code(400);
	exit('Unknown layer');
}

$cacheDir = TILE_CACHE_DIR . '/' . $layer . '/' . $zoom . '/' . $tileCol;
$cacheFile = $cacheDir . '/' . $tileRow . '.png';
$cacheOk = $cacheFile . '.ok';

function bool_text(bool $value): string {
	return $value ? 'yes' : 'no';
}

function emit_no_store_diag_headers(string $cacheDir, string $cacheFile, string $stage, string $errorMessage = ''): void {
	header('X-Tile-Write-Stage: ' . $stage);
	header('X-Tile-Cache-Dir: ' . $cacheDir);
	header('X-Tile-Cache-File: ' . $cacheFile);
	header('X-Tile-Cache-Root: ' . TILE_CACHE_DIR);
	$resolvedRoot = @realpath(TILE_CACHE_DIR);
	header('X-Tile-Cache-Root-Resolved: ' . ($resolvedRoot !== false ? $resolvedRoot : 'unresolved'));
	header('X-Tile-Cache-Root-Writable: ' . bool_text(is_writable(TILE_CACHE_DIR)));
	header('X-Tile-Cache-Dir-Exists: ' . bool_text(is_dir($cacheDir)));
	header('X-Tile-Cache-Dir-Writable: ' . bool_text(is_dir($cacheDir) && is_writable($cacheDir)));
	if ($errorMessage !== '') {
		header('X-Tile-Write-Error: ' . substr($errorMessage, 0, 220));
	}
	if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
		$euid = posix_geteuid();
		$user = posix_getpwuid($euid);
		if (is_array($user) && isset($user['name'])) {
			header('X-Tile-PHP-User: ' . (string)$user['name']);
		} else {
			header('X-Tile-PHP-EUID: ' . (string)$euid);
		}
	}
}

if (is_file($cacheFile)) {
	if (!is_file($cacheOk)) {
		@touch($cacheOk);
	}
	send_png_file($cacheFile, 'hit');
}

$tileUrl = 'https://' . TILE_PROXY_ALLOWED_HOST . '/rasteripalvelu/wmts'
	. '?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0'
	. '&LAYER=' . urlencode($wmtsLayer)
	. '&STYLE=default&FORMAT=image%2Fpng'
	. '&TILEMATRIXSET=WGS84_Pseudo-Mercator'
	. '&TILEMATRIX=WGS84_Pseudo-Mercator%3A' . $zoom
	. '&TILEROW=' . $tileRow
	. '&TILECOL=' . $tileCol;

$ch = curl_init($tileUrl);
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => false,
	CURLOPT_CONNECTTIMEOUT => TILE_PROXY_CONNECT_TIMEOUT,
	CURLOPT_TIMEOUT => TILE_PROXY_TIMEOUT,
	CURLOPT_USERAGENT => 'TileProxy/1.0',
	CURLOPT_HTTPHEADER => ['Accept: image/png,image/*,*/*'],
	CURLOPT_SSL_VERIFYPEER => true,
	CURLOPT_SSL_VERIFYHOST => 2,
]);

$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
$errno = curl_errno($ch);
$err = curl_error($ch);

if ($errno !== 0 || $body === false) {
	error_log('TileProxy cURL error ' . $errno . ': ' . $err . ' for URL: ' . $tileUrl);
	send_empty_png('error');
}

if ($status < 200 || $status >= 300) {
	error_log('TileProxy HTTP status ' . $status . ' for URL: ' . $tileUrl);
	send_empty_png('error');
}

if (stripos($contentType, 'image/') === false) {
	error_log('TileProxy non-image content-type: ' . $contentType . ' for URL: ' . $tileUrl);
	send_empty_png('error');
}

$isPng = is_string($body) && strlen($body) > 8 && substr($body, 0, 4) === "\x89PNG";
if (!$isPng) {
	error_log('TileProxy non-png body for URL: ' . $tileUrl);
	send_empty_png('error');
}

if (is_nearly_blank_png($body)) {
	error_log('TileProxy blank tile for URL: ' . $tileUrl);
	send_empty_png('blank');
}

if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
	$err = error_get_last();
	error_log(
		'TileProxy mkdir failed: dir=' . $cacheDir
		. ' cache_root=' . TILE_CACHE_DIR
		. ' cache_root_exists=' . bool_text(is_dir(TILE_CACHE_DIR))
		. ' cache_root_writable=' . bool_text(is_writable(TILE_CACHE_DIR))
		. ' error=' . (($err && isset($err['message'])) ? $err['message'] : 'unknown')
	);
	emit_no_store_diag_headers(
		$cacheDir,
		$cacheFile,
		'mkdir',
		($err && isset($err['message'])) ? (string)$err['message'] : 'unknown'
	);
	send_png_body($body, 'miss-no-store');
}

$tmpData = $cacheFile . '.tmp.' . getmypid();
$tmpOk = $cacheOk . '.tmp.' . getmypid();

if (@file_put_contents($tmpData, $body) === false) {
	$err = error_get_last();
	error_log(
		'TileProxy write tmp failed: tmp=' . $tmpData
		. ' dir_writable=' . bool_text(is_writable($cacheDir))
		. ' error=' . (($err && isset($err['message'])) ? $err['message'] : 'unknown')
	);
	@unlink($tmpData);
	@unlink($tmpOk);
	emit_no_store_diag_headers(
		$cacheDir,
		$cacheFile,
		'write-tmp',
		($err && isset($err['message'])) ? (string)$err['message'] : 'unknown'
	);
	send_png_body($body, 'miss-no-store');
}

if (!@rename($tmpData, $cacheFile)) {
	$err = error_get_last();
	error_log(
		'TileProxy rename data failed: from=' . $tmpData
		. ' to=' . $cacheFile
		. ' error=' . (($err && isset($err['message'])) ? $err['message'] : 'unknown')
	);
	@unlink($tmpData);
	@unlink($tmpOk);
	emit_no_store_diag_headers(
		$cacheDir,
		$cacheFile,
		'rename-data',
		($err && isset($err['message'])) ? (string)$err['message'] : 'unknown'
	);
	send_png_body($body, 'miss-no-store');
}


	if (@file_put_contents($tmpOk, "ok\n") !== false) {
		if (!@rename($tmpOk, $cacheOk)) {
			$err = error_get_last();
			error_log(
				'TileProxy rename marker failed: from=' . $tmpOk
				. ' to=' . $cacheOk
				. ' error=' . (($err && isset($err['message'])) ? $err['message'] : 'unknown')
			);
			@unlink($tmpOk);
			@touch($cacheOk);
		}
	} else {
		$err = error_get_last();
		error_log(
			'TileProxy write marker failed: tmp=' . $tmpOk
			. ' error=' . (($err && isset($err['message'])) ? $err['message'] : 'unknown')
		);
		@unlink($tmpOk);
		@touch($cacheOk);
	}
	send_png_body($body, 'miss-store');
