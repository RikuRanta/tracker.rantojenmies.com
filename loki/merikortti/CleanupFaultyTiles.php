<?php

declare(strict_types=1);

const DEFAULT_MAX_BYTES = 16384;

$defaultRoot = __DIR__ . '/tile-cache';
$options = getopt('', ['delete', 'root:', 'max-bytes:', 'help']);

if (isset($options['help'])) {
	echo "Usage: php CleanupFaultyTiles.php [--delete] [--root=/path/to/tile-cache] [--max-bytes=16384]\n";
	echo "\n";
	echo "Without --delete, the script runs in dry-run mode and only prints suspicious tiles.\n";
	echo "Only PNG files up to --max-bytes are inspected. Larger files are skipped before GD decoding.\n";
	exit(0);
}

$deleteMode = isset($options['delete']);
$root = isset($options['root']) ? (string)$options['root'] : $defaultRoot;
$maxBytes = isset($options['max-bytes']) ? (int)$options['max-bytes'] : DEFAULT_MAX_BYTES;

if ($maxBytes <= 0) {
	fwrite(STDERR, "--max-bytes must be a positive integer.\n");
	exit(1);
}

if (!is_dir($root)) {
	fwrite(STDERR, "Tile cache directory not found: {$root}\n");
	exit(1);
}

if (!function_exists('imagecreatefrompng')) {
	fwrite(STDERR, "GD PNG support is required for this script.\n");
	exit(1);
}

function isSuspiciousTile(string $path): bool {
	$imageInfo = @getimagesize($path);
	if ($imageInfo === false) {
		return false;
	}

	$width = (int)$imageInfo[0];
	$height = (int)$imageInfo[1];
	$type = $imageInfo[2] ?? null;
	if ($width !== 256 || $height !== 256 || $type !== IMAGETYPE_PNG) {
		return false;
	}

	$image = @imagecreatefrompng($path);
	if ($image === false) {
		return false;
	}

	$step = 12;
	$bucketCounts = [];
	$totalSamples = 0;
	$dominantCount = 0;
	$edgeTransitions = 0;
	$transitionChecks = 0;
	$previousRow = [];

	for ($y = 0; $y < 256; $y += $step) {
		$rowBuckets = [];
		$previousBucket = null;

		for ($x = 0; $x < 256; $x += $step) {
			$rgba = imagecolorat($image, $x, $y);
			$alpha = ($rgba >> 24) & 0x7F;

			if ($alpha >= 120) {
				$bucket = 't';
			} else {
				$red = ($rgba >> 16) & 0xFF;
				$green = ($rgba >> 8) & 0xFF;
				$blue = $rgba & 0xFF;
				$bucket = sprintf('%02x%02x%02x', $red >> 4, $green >> 4, $blue >> 4);
			}

			$totalSamples++;
			$rowBuckets[] = $bucket;
			$bucketCounts[$bucket] = ($bucketCounts[$bucket] ?? 0) + 1;
			if ($bucketCounts[$bucket] > $dominantCount) {
				$dominantCount = $bucketCounts[$bucket];
			}

			if ($previousBucket !== null) {
				$transitionChecks++;
				if ($previousBucket !== $bucket) {
					$edgeTransitions++;
				}
			}

			$previousBucket = $bucket;
		}

		if (!empty($previousRow)) {
			$compareCount = min(count($previousRow), count($rowBuckets));
			for ($index = 0; $index < $compareCount; $index++) {
				$transitionChecks++;
				if ($previousRow[$index] !== $rowBuckets[$index]) {
					$edgeTransitions++;
				}
			}
		}

		$previousRow = $rowBuckets;
	}

	imagedestroy($image);

	if ($totalSamples === 0) {
		return false;
	}

	$dominantRatio = $dominantCount / $totalSamples;
	$uniqueBuckets = count($bucketCounts);
	$transitionRatio = $transitionChecks > 0 ? ($edgeTransitions / $transitionChecks) : 0.0;

	return $dominantRatio >= 0.82 && $uniqueBuckets <= 12 && $transitionRatio <= 0.12;
}

function shouldInspectTile(string $path, int $maxBytes): bool {
	$fileSize = @filesize($path);
	if ($fileSize === false) {
		return false;
	}

	return $fileSize <= $maxBytes;
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$checked = 0;
$inspected = 0;
$skippedBySize = 0;
$flagged = 0;
$deleted = 0;

foreach ($iterator as $fileInfo) {
	if (!$fileInfo->isFile()) {
		continue;
	}

	if (strtolower($fileInfo->getExtension()) !== 'png') {
		continue;
	}

	$path = $fileInfo->getPathname();
	$checked++;

	if (!shouldInspectTile($path, $maxBytes)) {
		$skippedBySize++;
		continue;
	}

	$inspected++;

	if (!isSuspiciousTile($path)) {
		continue;
	}

	$flagged++;
	if ($deleteMode) {
		if (@unlink($path)) {
			echo "deleted {$path}\n";
			$deleted++;
		} else {
			echo "failed {$path}\n";
		}
	} else {
		echo "suspect {$path}\n";
	}
}

echo "checked={$checked} inspected={$inspected} skipped_by_size={$skippedBySize} flagged={$flagged} deleted={$deleted} max_bytes={$maxBytes} mode=" . ($deleteMode ? 'delete' : 'dry-run') . "\n";