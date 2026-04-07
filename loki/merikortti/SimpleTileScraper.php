<?php

declare(strict_types=1);

/**
 * Simple offline tile scraper.
 *
 * Minimal usage:
 *   php SimpleTileScraper.php
 *
 * Optional:
 *   php SimpleTileScraper.php --layer=traficom_yleiskartat_250k_public --min-zoom=9 --max-zoom=12
 *   php SimpleTileScraper.php --layer=traficom_merikarttasarjat_public --min-zoom=12 --max-zoom=14
 *   php SimpleTileScraper.php --layer=traficom_rannikkokartat_public --min-zoom=14 --max-zoom=15
 *
 * Notes:
 * - Fetches directly from Traficom WMTS (no local auth issues).
 * - Writes cache files to tile-cache/<layer>/<z>/<x>/<y>.png
 * - Creates .ok sidecar marker for Apache fast path.
 */

const MASKS_PATH = __DIR__ . '/masks.json';
const CACHE_ROOT = __DIR__ . '/tile-cache';
const CONNECT_TIMEOUT = 2;
const REQUEST_TIMEOUT = 8;
const MAX_ATTEMPTS_PER_TILE = 3; // 1 initial try + 2 retries
const FAILED_LOG_PATH = CACHE_ROOT . '/failed-tiles.log';

const DEFAULT_LAYER = 'traficom_yleiskartat_250k_public';
const DEFAULT_MIN_ZOOM = 9;
const DEFAULT_MAX_ZOOM = 12;
const BLANK_BURST_THRESHOLD = 5;
const BLANK_BURST_BACKOFF_MS = 10000;

const LAYER_MAP = [
	'traficom_yleiskartat_250k_public' => 'Traficom:Yleiskartat 250k public',
	'traficom_merikarttasarjat_public' => 'Traficom:Merikarttasarjat public',
	'traficom_rannikkokartat_public' => 'Traficom:Rannikkokartat public',
];

$options = getopt('', ['layer:', 'min-zoom:', 'max-zoom:', 'delay-ms:', 'help']);

if (isset($options['help'])) {
	echo "Usage: php SimpleTileScraper.php [--layer=slug] [--min-zoom=N] [--max-zoom=N] [--delay-ms=N]\n";
	echo "Default: --layer=" . DEFAULT_LAYER . " --min-zoom=" . DEFAULT_MIN_ZOOM . " --max-zoom=" . DEFAULT_MAX_ZOOM . " --delay-ms=0\n";
	echo "Example: --delay-ms=500  (recommended when Traficom returns blank tiles)\n";
	exit(0);
}

$layerSlug = isset($options['layer']) ? trim((string)$options['layer']) : DEFAULT_LAYER;
$minZoom = isset($options['min-zoom']) ? (int)$options['min-zoom'] : DEFAULT_MIN_ZOOM;
$maxZoom = isset($options['max-zoom']) ? (int)$options['max-zoom'] : DEFAULT_MAX_ZOOM;
$delayMs = isset($options['delay-ms']) ? max(0, (int)$options['delay-ms']) : 0;

if (!isset(LAYER_MAP[$layerSlug])) {
	fwrite(STDERR, "Unknown layer slug: {$layerSlug}\n");
	fwrite(STDERR, "Allowed: " . implode(', ', array_keys(LAYER_MAP)) . "\n");
	exit(1);
}

if ($minZoom > $maxZoom) {
	$tmp = $minZoom;
	$minZoom = $maxZoom;
	$maxZoom = $tmp;
}

if (!is_file(MASKS_PATH)) {
	fwrite(STDERR, "masks.json not found: " . MASKS_PATH . "\n");
	exit(1);
}

$masksRaw = file_get_contents(MASKS_PATH);
if ($masksRaw === false) {
	fwrite(STDERR, "failed reading masks.json\n");
	exit(1);
}

$masksDecoded = json_decode($masksRaw, true);
if (!is_array($masksDecoded) || !isset($masksDecoded['masks']) || !is_array($masksDecoded['masks'])) {
	fwrite(STDERR, "invalid masks.json\n");
	exit(1);
}

function clip_lat(float $lat): float {
	$max = 85.05112878;
	if ($lat > $max) return $max;
	if ($lat < -$max) return -$max;
	return $lat;
}

function lon_to_x(float $lon, int $z): int {
	$n = 1 << $z;
	$x = (int)floor((($lon + 180.0) / 360.0) * $n);
	if ($x < 0) return 0;
	if ($x >= $n) return $n - 1;
	return $x;
}

function lat_to_y(float $lat, int $z): int {
	$lat = clip_lat($lat);
	$latRad = deg2rad($lat);
	$n = 1 << $z;
	$y = (int)floor((1.0 - log(tan($latRad) + (1.0 / cos($latRad))) / M_PI) / 2.0 * $n);
	if ($y < 0) return 0;
	if ($y >= $n) return $n - 1;
	return $y;
}

function extract_bboxes(array $masks): array {
	$out = [];
	foreach ($masks as $mask) {
		if (!isset($mask['coordinates']) || !is_array($mask['coordinates']) || count($mask['coordinates']) < 3) {
			continue;
		}

		$minLon = INF;
		$maxLon = -INF;
		$minLat = INF;
		$maxLat = -INF;

		foreach ($mask['coordinates'] as $p) {
			if (!is_array($p) || count($p) < 2) {
				continue;
			}
			$lon = (float)$p[0];
			$lat = (float)$p[1];
			$minLon = min($minLon, $lon);
			$maxLon = max($maxLon, $lon);
			$minLat = min($minLat, $lat);
			$maxLat = max($maxLat, $lat);
		}

		if (is_finite($minLon) && is_finite($maxLon) && is_finite($minLat) && is_finite($maxLat)) {
			$out[] = [$minLon, $minLat, $maxLon, $maxLat];
		}
	}
	return $out;
}

function extract_polygons(array $masks): array {
	$out = [];
	foreach ($masks as $mask) {
		if (!isset($mask['coordinates']) || !is_array($mask['coordinates']) || count($mask['coordinates']) < 3) {
			continue;
		}

		$poly = [];
		foreach ($mask['coordinates'] as $p) {
			if (!is_array($p) || count($p) < 2) {
				continue;
			}
			$poly[] = [(float)$p[0], (float)$p[1]]; // [lon, lat]
		}

		if (count($poly) >= 3) {
			$out[] = $poly;
		}
	}
	return $out;
}

function point_in_polygon(float $lon, float $lat, array $polygon): bool {
	$inside = false;
	$n = count($polygon);
	if ($n < 3) {
		return false;
	}

	for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
		$xi = (float)$polygon[$i][0];
		$yi = (float)$polygon[$i][1];
		$xj = (float)$polygon[$j][0];
		$yj = (float)$polygon[$j][1];

		$intersects = (($yi > $lat) !== ($yj > $lat))
			&& ($lon < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi));
		if ($intersects) {
			$inside = !$inside;
		}
	}

	return $inside;
}

function tile_center_lon_lat(int $x, int $y, int $z): array {
	$n = 1 << $z;
	$lon = (($x + 0.5) / $n) * 360.0 - 180.0;
	$mercY = M_PI * (1.0 - 2.0 * (($y + 0.5) / $n));
	$lat = rad2deg(atan(sinh($mercY)));
	return [$lon, $lat];
}

function tile_center_in_any_mask(int $x, int $y, int $z, array $polygons): bool {
	[$lon, $lat] = tile_center_lon_lat($x, $y, $z);
	foreach ($polygons as $poly) {
		if (point_in_polygon($lon, $lat, $poly)) {
			return true;
		}
	}
	return false;
}

function is_nearly_blank_png(string $pngBody): bool {
	if (!function_exists('imagecreatefromstring')) {
		return false;
	}

	if (strlen($pngBody) < 8 || substr($pngBody, 0, 4) !== "\x89PNG") {
		return false;
	}

	$img = @imagecreatefromstring($pngBody);
	if ($img === false) {
		return false;
	}

	$w = imagesx($img);
	$h = imagesy($img);
	if ($w <= 0 || $h <= 0) {
		return false;
	}

	$step = max(1, (int)floor(min($w, $h) / 24));
	$total = 0;
	$nonBlank = 0;

	for ($py = 0; $py < $h; $py += $step) {
		for ($px = 0; $px < $w; $px += $step) {
			$rgba = imagecolorat($img, $px, $py);
			$alpha = ($rgba >> 24) & 0x7F;
			$red = ($rgba >> 16) & 0xFF;
			$green = ($rgba >> 8) & 0xFF;
			$blue = $rgba & 0xFF;

			$total++;
			$isVisible = $alpha < 120;
			$isLight = ($red >= 240 && $green >= 240 && $blue >= 240);
			if ($isVisible && !$isLight) {
				$nonBlank++;
			}
		}
	}

	if ($total === 0) {
		return false;
	}

	$detailRatio = $nonBlank / $total;
	return $detailRatio < 0.005;
}

$bboxes = extract_bboxes($masksDecoded['masks']);
if (!$bboxes) {
	fwrite(STDERR, "no valid mask bboxes\n");
	exit(1);
}

$polygons = extract_polygons($masksDecoded['masks']);
if (!$polygons) {
	fwrite(STDERR, "no valid mask polygons\n");
	exit(1);
}

$wmtsLayer = LAYER_MAP[$layerSlug];
$planned = 0;
foreach ($bboxes as $bbox) {
	for ($z = $minZoom; $z <= $maxZoom; $z++) {
		$xMin = lon_to_x((float)$bbox[0], $z);
		$xMax = lon_to_x((float)$bbox[2], $z);
		$yMin = lat_to_y((float)$bbox[3], $z);
		$yMax = lat_to_y((float)$bbox[1], $z);
		if ($xMin > $xMax) { $t = $xMin; $xMin = $xMax; $xMax = $t; }
		if ($yMin > $yMax) { $t = $yMin; $yMin = $yMax; $yMax = $t; }
		$planned += (($xMax - $xMin + 1) * ($yMax - $yMin + 1));
	}
}

echo "plan layer={$layerSlug} zoom={$minZoom}-{$maxZoom} approx_requests={$planned} delay_ms={$delayMs}\n";

$seen = [];
$ok = 0;
$failed = 0;
$skipped = 0;
$skippedOutsideMask = 0;
$written = 0;
$consecutiveBlanks = 0;

@file_put_contents(FAILED_LOG_PATH, "");

foreach ($bboxes as $bbox) {
	for ($z = $minZoom; $z <= $maxZoom; $z++) {
		$xMin = lon_to_x((float)$bbox[0], $z);
		$xMax = lon_to_x((float)$bbox[2], $z);
		$yMin = lat_to_y((float)$bbox[3], $z);
		$yMax = lat_to_y((float)$bbox[1], $z);
		if ($xMin > $xMax) { $t = $xMin; $xMin = $xMax; $xMax = $t; }
		if ($yMin > $yMax) { $t = $yMin; $yMin = $yMax; $yMax = $t; }

		for ($x = $xMin; $x <= $xMax; $x++) {
			for ($y = $yMin; $y <= $yMax; $y++) {
				if (!tile_center_in_any_mask($x, $y, $z, $polygons)) {
					$skippedOutsideMask++;
					continue;
				}

				$key = $z . '/' . $x . '/' . $y;
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;

				$dir = CACHE_ROOT . '/' . $layerSlug . '/' . $z . '/' . $x;
				$file = $dir . '/' . $y . '.png';
				$okMarker = $file . '.ok';

				if (is_file($file) && is_file($okMarker)) {
					$skipped++;
					continue;
				}

				$url = 'https://julkinen.traficom.fi/rasteripalvelu/wmts'
					. '?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0'
					. '&LAYER=' . urlencode($wmtsLayer)
					. '&STYLE=default&FORMAT=image%2Fpng'
					. '&TILEMATRIXSET=WGS84_Pseudo-Mercator'
					. '&TILEMATRIX=WGS84_Pseudo-Mercator%3A' . $z
					. '&TILEROW=' . $y
					. '&TILECOL=' . $x;

				$body = false;
				$status = 0;
				$errno = 0;
				$contentType = '';
				$failReason = 'unknown';

				for ($attempt = 1; $attempt <= MAX_ATTEMPTS_PER_TILE; $attempt++) {
					$ch = curl_init($url);
					curl_setopt_array($ch, [
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_FOLLOWLOCATION => false,
						CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
						CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
						CURLOPT_USERAGENT => 'SimpleTileScraper/1.0',
						CURLOPT_HTTPHEADER => ['Accept: image/png,image/*,*/*'],
						CURLOPT_SSL_VERIFYPEER => true,
						CURLOPT_SSL_VERIFYHOST => 2,
					]);

					$body = curl_exec($ch);
					$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
					$errno = curl_errno($ch);
					$contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');

					if ($errno !== 0 || $body === false) {
						$failReason = 'curl errno=' . $errno;
						continue;
					}

					if ($status < 200 || $status >= 300) {
						$failReason = 'http status=' . $status;
						continue;
					}

					if (stripos($contentType, 'image/') === false) {
						$failReason = 'content-type=' . $contentType;
						continue;
					}

					$isPng = is_string($body) && strlen($body) > 8 && substr($body, 0, 4) === "\x89PNG";
					if (!$isPng) {
						$failReason = 'non-png-body';
						continue;
					}

					if (is_nearly_blank_png((string)$body)) {
						$failReason = 'blank-png-body';
						continue;
					}

					// Success on this attempt.
					$failReason = '';
					break;
				}

				if ($failReason !== '') {
					$failed++;
					$logLine = date('c') . ' ' . $layerSlug . '/' . $z . '/' . $x . '/' . $y
						. ' ' . $failReason . "\n";
					@file_put_contents(FAILED_LOG_PATH, $logLine, FILE_APPEND);
						if ($failReason === 'blank-png-body') {
							$consecutiveBlanks++;
							if ($consecutiveBlanks >= BLANK_BURST_THRESHOLD) {
								echo "\nblank burst detected ({$consecutiveBlanks} consecutive), pausing " . (BLANK_BURST_BACKOFF_MS / 1000) . "s...\n";
								usleep(BLANK_BURST_BACKOFF_MS * 1000);
								$consecutiveBlanks = 0;
							}
						} else {
							$consecutiveBlanks = 0;
						}
						if ($delayMs > 0) {
							usleep($delayMs * 1000);
						}
						continue;
					}
					$consecutiveBlanks = 0;

				if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
					$failed++;
					continue;
				}

				$tmpFile = $file . '.tmp.' . getmypid();
				if (@file_put_contents($tmpFile, $body) === false) {
					@unlink($tmpFile);
					$failed++;
					continue;
				}

				if (!@rename($tmpFile, $file)) {
					@unlink($tmpFile);
					$failed++;
					continue;
				}

				@file_put_contents($okMarker, "ok\n");
				$written++;
				$ok++;
					if ($delayMs > 0) {
						usleep($delayMs * 1000);
					}
				if ((($ok + $failed + $skipped) % 200) === 0) {
					echo 'progress seen=' . ($ok + $failed + $skipped) . ' ok=' . $ok . ' fail=' . $failed . ' skipped=' . $skipped . ' outside=' . $skippedOutsideMask . "\r";
				}
			}
		}
	}
}

echo "\n";
echo 'done layer=' . $layerSlug . ' zoom=' . $minZoom . '-' . $maxZoom . ' written=' . $written . ' ok=' . $ok . ' fail=' . $failed . ' skipped=' . $skipped . ' outside=' . $skippedOutsideMask . "\n";
if ($failed > 0) {
	echo 'failed_log=' . FAILED_LOG_PATH . "\n";
}
exit(0);
