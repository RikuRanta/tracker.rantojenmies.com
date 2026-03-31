<?php
/**
 * Tile proxy for Traficom WMTS nautical chart tiles.
 *
 * Features:
 * - Eliminates CORS errors by serving tiles from the same origin
 * - Disk caches fetched tiles at: tile-cache/<layer>/<zoom>/<col>/<row>.png
 * - Cache TTL: 180 days
 * - Retries failed requests up to TILE_PROXY_RETRIES times
 * - Graceful degradation: returns transparent PNG on persistent failure
 *
 * Performance optimizations:
 * 1. Optional X-Sendfile on cache hits (requires mod_xsendfile on Apache)
 *    Disabled by default. Enable with env var TILE_PROXY_USE_XSENDFILE=1.
 *    Without this env var, cache hits are served with readfile().
 *
 * 2. Browser caching: Cache-Control: public, max-age=86400 (24 hours)
 *
 * 3. WMTS layer/zoom/col/row path structure allows Apache direct file serving
 *    without PHP (if using RewriteCond -f checks in .htaccess)
 *
 * Allowed upstream: only julkinen.traficom.fi (strict allowlist)
 */

define('TILE_PROXY_OFFLINE', true);       // true = serve only cached tiles, never contact upstream
define('TILE_PROXY_ALLOWED_HOST', 'julkinen.traficom.fi');
define('TILE_PROXY_CONNECT_TIMEOUT', 1);  // Fail fast during upstream outages
define('TILE_PROXY_TIMEOUT', 2);          // Keep worker slots free under load
define('TILE_PROXY_RETRIES', 1);          // Single upstream attempt for predictable latency
define('TILE_CACHE_DIR', __DIR__ . '/tile-cache');
define('TILE_CACHE_TTL', 180 * 24 * 3600); // 180 days

// 1×1 transparent PNG returned when all upstream attempts fail,
// so the map shows an empty tile rather than a broken-image icon.
define('TILE_PROXY_EMPTY_PNG', base64_decode(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQ' .
	'AABjkB6QAAAABJRU5ErkJggg=='
));

/* ── Helper functions ──────────────────────────────────────────────────── */

/**
 * Sanitize layer name for filesystem use (lowercase, replace spaces/special chars).
 */
function sanitize_layer_name($name) {
	$name = strtolower(trim($name));
	$name = preg_replace('/[^a-z0-9_-]/', '_', $name);
	$name = preg_replace('/_+/', '_', $name);
	return trim($name, '_');
}

/**
 * Get system CA bundle path (cached per process to avoid repeated file_exists checks).
 */
function get_ca_bundle_path() {
	static $caBundle = null;
	if ($caBundle !== null) {
		return $caBundle;
	}

	$candidates = [
		'/etc/ssl/certs/ca-certificates.crt',     // Debian/Ubuntu
		'/etc/pki/tls/certs/ca-bundle.crt',       // RHEL/CentOS
		'/etc/ssl/ca-bundle.pem',                 // OpenSUSE
		'/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
	];

	foreach ($candidates as $candidate) {
		if (file_exists($candidate)) {
			return $caBundle = $candidate;
		}
	}

	return $caBundle = false;
}

/**
 * Parse WMTS query params to extract layer, zoom, col, row.
 * Returns array with keys: layer, zoom, col, row (or null on parse error).
 */
function parse_wmts_params($url) {
	$parsed = parse_url($url);
	if (!isset($parsed['query'])) return null;
	
	parse_str($parsed['query'], $params);

	// Normalize key casing because upstream/client may use different conventions
	// (e.g. LAYER vs layer, TILEMATRIX vs TileMatrix).
	$normalized = [];
	foreach ($params as $key => $value) {
		$normalized[strtolower($key)] = $value;
	}
	
	$layer = isset($normalized['layer']) ? $normalized['layer'] : null;
	$matrixId = isset($normalized['tilematrix']) ? $normalized['tilematrix'] : null;
	$col = isset($normalized['tilecol']) ? $normalized['tilecol'] : null;
	$row = isset($normalized['tilerow']) ? $normalized['tilerow'] : null;
	
	if (!$layer || !$matrixId || !$col || !$row) return null;
	
	// Extract zoom from matrix ID (e.g., "WGS84_Pseudo-Mercator:9" -> 9)
	$parts = explode(':', $matrixId);
	$zoom = end($parts);
	
	if (!is_numeric($col) || !is_numeric($row) || !is_numeric($zoom)) return null;
	
	return [
		'layer' => sanitize_layer_name($layer),
		'zoom' => (int)$zoom,
		'col' => (int)$col,
		'row' => (int)$row,
	];
}

/* ── Input validation ──────────────────────────────────────────────────── */

$requestStartTime = microtime(true);

// Support two request modes:
// 1. Path-based: TileProxy.php?layer=...&z=...&col=...&row=... (from Apache rewrite, cache misses)
// 2. Legacy URL-based: TileProxy.php?url=... (direct calls from frontend)
$pathMode = isset($_GET['layer']) && isset($_GET['z']) && isset($_GET['col']) && isset($_GET['row']);

if ($pathMode) {
	$layer = $_GET['layer'];
	$z = $_GET['z'];
	$col = $_GET['col'];
	$row = $_GET['row'];

	if (!preg_match('/^[a-z0-9_-]+$/', $layer) || !is_numeric($z) || !is_numeric($col) || !is_numeric($row)) {
		http_response_code(400);
		exit('Invalid tile parameters.');
	}

	$wmtsParams = [
		'layer' => $layer,
		'zoom' => (int)$z,
		'col' => (int)$col,
		'row' => (int)$row,
	];

	// Build upstream URL from path params
	$tileUrl = 'https://' . TILE_PROXY_ALLOWED_HOST . '/rasteripalvelu/wmts'
		. '?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0'
		. '&LAYER=' . urlencode($layer)
		. '&STYLE=default&FORMAT=image%2Fpng'
		. '&TILEMATRIXSET=WGS84_Pseudo-Mercator'
		. '&TILEMATRIX=WGS84_Pseudo-Mercator%3A' . $z
		. '&TILEROW=' . $row
		. '&TILECOL=' . $col;
} else {
	$raw = isset($_GET['url']) ? $_GET['url'] : '';
	if ($raw === '') {
		http_response_code(400);
		exit('Missing url parameter.');
	}

	$tileUrl = filter_var($raw, FILTER_VALIDATE_URL);
	if ($tileUrl === false) {
		http_response_code(400);
		exit('Invalid url parameter.');
	}

	$parsed = parse_url($tileUrl);
	if (!$parsed ||
		!isset($parsed['scheme']) ||
		strtolower($parsed['scheme']) !== 'https' ||
		!isset($parsed['host']) ||
		strtolower($parsed['host']) !== TILE_PROXY_ALLOWED_HOST
	) {
		http_response_code(403);
		exit('URL not allowed.');
	}

	$wmtsParams = parse_wmts_params($tileUrl);
}

/* ── Cache lookup (URL-derived path structure) ──────────────────────────── */

$wmtsParams = parse_wmts_params($tileUrl);
if (!$wmtsParams) {
	// Fallback to hash-based cache for invalid/unparseable URLs
	$cacheDir = TILE_CACHE_DIR . '/fallback';
	$hash = hash('sha256', $tileUrl);
	$cacheData = $cacheDir . '/' . $hash . '.png';
} else {
	// Use WMTS params as cache path: tile-cache/<layer>/<zoom>/<col>/<row>.png
	$cacheDir = TILE_CACHE_DIR . '/' . $wmtsParams['layer'] . '/' . 
		$wmtsParams['zoom'] . '/' . $wmtsParams['col'];
	$cacheData = $cacheDir . '/' . $wmtsParams['row'] . '.png';
}

// Cache-first strategy: serve any cached tile immediately regardless of age.
// Nautical charts rarely change — a cached tile is always better than a 2s timeout.
// Upstream is only contacted on a true cache miss (no file on disk at all).
$cacheStat = @stat($cacheData);
$hasCacheFile = (bool)$cacheStat;

if ($hasCacheFile) {
	$cacheAge = time() - $cacheStat['mtime'];
	$isFresh = $cacheAge < TILE_CACHE_TTL;

	header('Content-Type: image/png');
	header('Cache-Control: public, max-age=86400');
	header('X-Content-Type-Options: nosniff');
	header('X-Tile-Cache: ' . ($isFresh ? 'hit' : 'stale'));
	if (!$isFresh) {
		header('X-Tile-Cache-Age: ' . $cacheAge);
	}

	// Use X-Sendfile only when explicitly enabled.
	$useXSendfile = getenv('TILE_PROXY_USE_XSENDFILE') === '1';
	if ($useXSendfile) {
		$realPath = realpath($cacheData);
		$cacheRoot = realpath(TILE_CACHE_DIR);
		if ($realPath && $cacheRoot && strpos($realPath, $cacheRoot) === 0) {
			header('X-Sendfile: ' . $realPath);
			exit;
		}
	}

	readfile($cacheData);
	exit;
}

/* ── Offline mode: no upstream contact ──────────────────────────────────── */

if (TILE_PROXY_OFFLINE) {
	header('Content-Type: image/png');
	header('Cache-Control: no-store');
	header('X-Tile-Cache: offline-miss');
	echo TILE_PROXY_EMPTY_PNG;
	exit;
}

/* ── Fetch tile (with retries) ─────────────────────────────────────────── */

$caBundle = get_ca_bundle_path();

$curlOpts = [
	CURLOPT_RETURNTRANSFER    => true,
	CURLOPT_FOLLOWLOCATION    => false,
	CURLOPT_CONNECTTIMEOUT    => TILE_PROXY_CONNECT_TIMEOUT,
	CURLOPT_TIMEOUT           => TILE_PROXY_TIMEOUT,
	CURLOPT_USERAGENT         => 'Mozilla/5.0 (compatible; TileProxy/1.0)',
	CURLOPT_HTTPHEADER        => ['Accept: image/png,image/*,*/*'],
	CURLOPT_SSL_VERIFYPEER    => true,
	CURLOPT_SSL_VERIFYHOST    => 2,
	CURLOPT_TCP_KEEPALIVE     => 1,            // Enable TCP keepalive for connection reuse
	CURLOPT_TCP_KEEPIDLE      => 60,           // Keepalive after 60s idle
	CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_2_0, // Prefer HTTP/2
	CURLOPT_SSL_SESSIONID_CACHE => 1,          // Cache SSL/TLS session to avoid handshake overhead
	CURLOPT_DNS_CACHE_TIMEOUT => 3600,         // Cache DNS lookups for 1 hour
];
if ($caBundle !== false) {
	$curlOpts[CURLOPT_CAINFO] = $caBundle;
}

$body        = false;
$httpStatus  = 0;
$curlErrno   = 0;
$curlError   = '';
$contentType = 'image/png';

for ($attempt = 1; $attempt <= TILE_PROXY_RETRIES; $attempt++) {
	$ch = curl_init($tileUrl);
	curl_setopt_array($ch, $curlOpts);

	$fetchStartTime = microtime(true);
	$result      = curl_exec($ch);
	$fetchTime   = microtime(true) - $fetchStartTime;
	$httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlErrno   = curl_errno($ch);
	$curlError   = curl_error($ch);
	$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/png';
	curl_close($ch);

	if ($curlErrno === 0 && $result !== false && $httpStatus >= 200 && $httpStatus < 300) {
		// Reject non-image responses (e.g. proxy error pages returned as 200 OK with text/html)
		$ct = strtolower($contentType);
		if (strpos($ct, 'image/') === false) {
			error_log('TileProxy: upstream returned non-image content-type: ' . $contentType . ' for URL: ' . $tileUrl);
			// Treat as failure — do not cache HTML error pages as tiles
			continue;
		}
		$body = $result;
		break;
	}

	if ($curlErrno !== 0) {
		error_log('TileProxy attempt ' . $attempt . '/' . TILE_PROXY_RETRIES .
			' cURL error ' . $curlErrno . ': ' . $curlError . ' for URL: ' . $tileUrl);
	}
}

if ($body === false) {
	// True cache miss + upstream failure — return transparent tile so map degrades gracefully.
	header('Content-Type: image/png');
	header('Cache-Control: no-store');
	header('X-Tile-Cache: error');
	echo TILE_PROXY_EMPTY_PNG;
	exit;
}

if ($httpStatus < 200 || $httpStatus >= 300) {
	http_response_code($httpStatus);
	exit('Upstream returned HTTP ' . $httpStatus);
}

// Strip any charset suffix – we're serving binary image data.
$mimeType = strtok($contentType, ';') ?: 'image/png';
if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
	$mimeType = 'image/png';
}

/* ── Write to cache ────────────────────────────────────────────────────── */

// Create cache dir only if necessary (avoid mkdir syscall on every cache miss).
if (!is_dir($cacheDir)) {
	@mkdir($cacheDir, 0755, true);
}

// Only cache responses that look like real image data (PNG magic: \x89PNG, JPEG: \xFF\xD8).
$isPng = strlen($body) > 8 && substr($body, 0, 4) === "\x89PNG";
$isJpeg = strlen($body) > 2 && substr($body, 0, 2) === "\xFF\xD8";
if ($isPng || $isJpeg) {
	// Write atomically: temp file + rename to avoid serving partial tiles.
	$tmpData = $cacheData . '.tmp.' . getmypid();
	if (file_put_contents($tmpData, $body) !== false) {
		@rename($tmpData, $cacheData);
	} else {
		@unlink($tmpData);
	}
} else {
	error_log('TileProxy: refusing to cache non-image body (' . strlen($body) . ' bytes) for URL: ' . $tileUrl);
}

/* ── Send to browser ───────────────────────────────────────────────────── */

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('X-Tile-Cache: miss');

// Add timing diagnostics if debug param present
if (isset($_GET['debug'])) {
	$totalTime = (microtime(true) - $requestStartTime) * 1000;
	header('X-Tile-Time-Total-Ms: ' . round($totalTime, 2));
	header('X-Tile-Time-Fetch-Ms: ' . round($fetchTime * 1000, 2));
	header('X-Tile-Time-Proxy-Ms: ' . round(($totalTime - ($fetchTime * 1000)), 2));
}

echo $body;
