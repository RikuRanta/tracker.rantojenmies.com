
	/* Näytetään latausikkuna */
	Show('c-loading');

	var layers = [];
	var navionics = null;
	var uri;

	var CAPABILITIES_TTL_MS = 30 * 60 * 1000;
	var PROJECTION_CODE = 'EPSG:3857';
	var TRAFICOM_MIN_ZOOM = 9;
	var TRAFICOM_DETAIL_MIN_ZOOM = 12;
	var TRAFICOM_EXTRA_DETAIL_MIN_ZOOM = 14;
	var MASKS_GLOBAL_KEY = '__MAP_MASKS_DATA__';
	var activeMasks = [];

	var COUNTRY_PROVIDERS = [
		{
			id: 'sweden',
			label: 'Ruotsi',
			bboxLonLat: [10.5, 55.0, 20.5, 69.5],
			type: 'wms',
			wmsUrl: 'https://geokatalog.sjofartsverket.se/MapService/wms.axd/HelcomBakgrund',
			wmsParams: {
				LAYERS: 'HelcomKarta',
				TILED: true,
				VERSION: '1.1.1',
				FORMAT: 'image/png',
				TRANSPARENT: true,
				SRS: PROJECTION_CODE
			}
		}
	];

	var PROVIDERS = {
		traficom: {
			id: 'traficom',
			capabilitiesUrl: 'https://julkinen.traficom.fi/rasteripalvelu/wmts?request=getcapabilities',
			matrixSet: 'WGS84_Pseudo-Mercator',
			approxCoverageBboxLonLat: [17.0, 58.0, 33.5, 70.5],
			detailPreferredExact: ['Traficom:Merikarttasarjat public'],
			detailPreferredPatterns: [/Merikarttasarjat\s+public/i, /Merikarttasarja\s+[A-Z]\s+public/i, /Merikartat/i, /public/i],
			coastalPreferredExact: ['Traficom:Rannikkokartat public', 'Traficom:Veneilykartat public'],
			coastalPreferredPatterns: [/Rannikkokartat\s+public/i, /Veneilykartat\s+public/i, /Rannikko/i, /Veneily/i, /public/i],
			overviewPreferredExact: ['Traficom:Yleiskartat 100k public', 'Traficom:Yleiskartat 250k public'],
			overviewPreferredPatterns: [/Yleiskartat\s+100k\s+public/i, /Yleiskartat\s+250k\s+public/i, /public/i]
		}
	};

	/* Apufunktio (muuntaa muuttujan boolean-muotoon) */
	function getBool(val){
		var num = +val;
		return !isNaN(num) ? !!num : !!String(val).toLowerCase().replace(!!0,'');
	}

	function pickBestLayer(layerNames, provider) {
		var preferredExactNames = provider.detailPreferredExact || [];
		for (var i = 0; i < preferredExactNames.length; i++) {
			if (layerNames.indexOf(preferredExactNames[i]) !== -1) return preferredExactNames[i];
		}
		var preferredPatterns = provider.detailPreferredPatterns || [];
		for (var j = 0; j < preferredPatterns.length; j++) {
			for (var k = 0; k < layerNames.length; k++) {
				if (preferredPatterns[j].test(layerNames[k])) return layerNames[k];
			}
		}
		return layerNames[0];
	}

	function pickPreferredLayer(layerNames, exactNames, patterns, excludedNames) {
		exactNames = exactNames || [];
		patterns = patterns || [];
		excludedNames = excludedNames || [];

		for (var i = 0; i < exactNames.length; i++) {
			if (excludedNames.indexOf(exactNames[i]) === -1 && layerNames.indexOf(exactNames[i]) !== -1) return exactNames[i];
		}

		for (var j = 0; j < patterns.length; j++) {
			for (var k = 0; k < layerNames.length; k++) {
				if (excludedNames.indexOf(layerNames[k]) === -1 && patterns[j].test(layerNames[k])) return layerNames[k];
			}
		}

		return null;
	}

	function loadMasksFromGlobalData() {
		var data = window[MASKS_GLOBAL_KEY];
		if (!data || !Array.isArray(data.masks)) return;

		activeMasks = [];
		for (var i = 0; i < data.masks.length; i++) {
			var mask = data.masks[i];
			if (!mask || !Array.isArray(mask.coordinates) || mask.coordinates.length < 3) continue;
			activeMasks.push({
				id: 'mask-' + i,
				visible: true,
				coordinates: mask.coordinates
			});
		}
	}

	function isPointInPolygon(point, polygon) {
		var x = point[0];
		var y = point[1];
		var inside = false;

		for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
			var xi = polygon[i][0];
			var yi = polygon[i][1];
			var xj = polygon[j][0];
			var yj = polygon[j][1];
			var intersect = ((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / ((yj - yi) || 1e-12) + xi);
			if (intersect) inside = !inside;
		}

		return inside;
	}

	/* OpenLayers-adapteri vanhan navionics-rajapinnan tilalle */
	(function initOpenLayersMap() {
		var readyCallbacks = [];
		var mapInstance = null;
		var mapView = null;
		var olModules = null;
		var traficomWideOverviewLayer = null;
		var traficomDetailLayer = null;
		var traficomExtraDetailLayer = null;

		// Track last applied zoom to avoid remasking during pans (only on zoom changes)
		var lastMaskApplyZoom = -1;

		function setUnmaskedTileLoad(source) {
			if (!source || typeof source.setTileLoadFunction !== 'function') return;
			source.setTileLoadFunction(function(tile, src) {
				tile.getImage().src = src;
			});
		}

		function applyPolygonMask(layersToMask, wgs84PolygonData) {
			if (!wgs84PolygonData || wgs84PolygonData.length === 0 || !olModules) return;

			var polygons = Array.isArray(wgs84PolygonData[0]) ? wgs84PolygonData : [wgs84PolygonData];
			var projectedPolygons = [];
			for (var p = 0; p < polygons.length; p++) {
				var poly = polygons[p];
				var projected = [];
				for (var c = 0; c < poly.length; c++) {
					projected.push(olModules.fromLonLat(poly[c], PROJECTION_CODE));
				}
				projectedPolygons.push(projected);
			}

			var polygonBounds = [];
			for (var b = 0; b < projectedPolygons.length; b++) {
				var minX = Number.POSITIVE_INFINITY;
				var minY = Number.POSITIVE_INFINITY;
				var maxX = Number.NEGATIVE_INFINITY;
				var maxY = Number.NEGATIVE_INFINITY;
				for (var q = 0; q < projectedPolygons[b].length; q++) {
					var pt = projectedPolygons[b][q];
					minX = Math.min(minX, pt[0]);
					minY = Math.min(minY, pt[1]);
					maxX = Math.max(maxX, pt[0]);
					maxY = Math.max(maxY, pt[1]);
				}
				polygonBounds.push([minX, minY, maxX, maxY]);
			}

			var extentsIntersect = function(a, b) {
				return a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1];
			};

			var isInsideAnyPolygon = function(point) {
				for (var pi = 0; pi < projectedPolygons.length; pi++) {
					if (isPointInPolygon(point, projectedPolygons[pi])) return true;
				}
				return false;
			};

			var EPSILON = 1e-9;

			var orientation = function(ax, ay, bx, by, cx, cy) {
				var value = (by - ay) * (cx - bx) - (bx - ax) * (cy - by);
				if (Math.abs(value) < EPSILON) return 0;
				return value > 0 ? 1 : 2;
			};

			var onSegment = function(ax, ay, bx, by, cx, cy) {
				return bx <= Math.max(ax, cx) + EPSILON &&
					bx + EPSILON >= Math.min(ax, cx) &&
					by <= Math.max(ay, cy) + EPSILON &&
					by + EPSILON >= Math.min(ay, cy);
			};

			var segmentsIntersect = function(p1, q1, p2, q2) {
				var o1 = orientation(p1[0], p1[1], q1[0], q1[1], p2[0], p2[1]);
				var o2 = orientation(p1[0], p1[1], q1[0], q1[1], q2[0], q2[1]);
				var o3 = orientation(p2[0], p2[1], q2[0], q2[1], p1[0], p1[1]);
				var o4 = orientation(p2[0], p2[1], q2[0], q2[1], q1[0], q1[1]);

				if (o1 !== o2 && o3 !== o4) return true;
				if (o1 === 0 && onSegment(p1[0], p1[1], p2[0], p2[1], q1[0], q1[1])) return true;
				if (o2 === 0 && onSegment(p1[0], p1[1], q2[0], q2[1], q1[0], q1[1])) return true;
				if (o3 === 0 && onSegment(p2[0], p2[1], p1[0], p1[1], q2[0], q2[1])) return true;
				if (o4 === 0 && onSegment(p2[0], p2[1], q1[0], q1[1], q2[0], q2[1])) return true;

				return false;
			};

			var doesPolygonIntersectTileBoundary = function(polygon, tileExtent) {
				var minX = tileExtent[0], minY = tileExtent[1], maxX = tileExtent[2], maxY = tileExtent[3];
				var tileEdges = [
					[[minX, minY], [maxX, minY]],
					[[maxX, minY], [maxX, maxY]],
					[[maxX, maxY], [minX, maxY]],
					[[minX, maxY], [minX, minY]]
				];

				for (var iEdge = 0; iEdge < polygon.length; iEdge++) {
					var a = polygon[iEdge];
					var bpt = polygon[(iEdge + 1) % polygon.length];
					for (var e = 0; e < tileEdges.length; e++) {
						if (segmentsIntersect(a, bpt, tileEdges[e][0], tileEdges[e][1])) return true;
					}
				}

				return false;
			};

			var classifyTileAgainstMasks = function(tileExtent) {
				var center = [(tileExtent[0] + tileExtent[2]) / 2, (tileExtent[1] + tileExtent[3]) / 2];
				var fullyInsideAnyMask = false;

				for (var m = 0; m < projectedPolygons.length; m++) {
					if (!extentsIntersect(tileExtent, polygonBounds[m])) continue;
					if (doesPolygonIntersectTileBoundary(projectedPolygons[m], tileExtent)) return 'boundary';
					if (isPointInPolygon(center, projectedPolygons[m])) fullyInsideAnyMask = true;
				}

				return fullyInsideAnyMask ? 'inside' : 'outside';
			};

			for (var li = 0; li < layersToMask.length; li++) {
				(function(layer) {
					if (!layer || typeof layer.getSource !== 'function') return;
					var source = layer.getSource();
					if (!source || typeof source.setTileLoadFunction !== 'function') return;

					var tileGrid = (typeof source.getTileGrid === 'function' && source.getTileGrid()) ||
						(typeof source.getTileGridForProjection === 'function' && source.getTileGridForProjection(olModules.getProjection(PROJECTION_CODE)));

					source.setTileLoadFunction(function(tile, src) {
						var image = tile.getImage();
						try {
							var tileCoord = tile.getTileCoord();
							if (!tileGrid || !tileCoord || tileCoord.length < 3) {
								image.src = src;
								return;
							}

							var tileExtent = tileGrid.getTileCoordExtent(tileCoord);

							var maybeIntersectsMask = false;
							for (var bi = 0; bi < polygonBounds.length; bi++) {
								if (extentsIntersect(tileExtent, polygonBounds[bi])) {
									maybeIntersectsMask = true;
									break;
								}
							}
							if (!maybeIntersectsMask) {
								image.src = src;
								return;
							}

							var tileClass = classifyTileAgainstMasks(tileExtent);
							if (tileClass === 'inside') {
								image.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
								return;
							}
							if (tileClass === 'outside') {
								image.src = src;
								return;
							}

							var fetchUrl = src;
							if (typeof tileProxyUrl === 'string' && tileProxyUrl !== '') {
								var normalizedProxyBase = tileProxyUrl.split('?')[0];
								var srcIsAlreadyProxy = String(src).indexOf(normalizedProxyBase) === 0;
								if (!srcIsAlreadyProxy) {
									fetchUrl = tileProxyUrl + '?url=' + encodeURIComponent(src);
								}
							}

							fetch(fetchUrl)
								.then(function(response) {
									if (!response.ok) throw new Error('Tile fetch failed: HTTP ' + response.status);
									return response.blob();
								})
								.then(function(blob) { return createImageBitmap(blob); })
								.then(function(bitmap) {
									var canvas = document.createElement('canvas');
									canvas.width = bitmap.width;
									canvas.height = bitmap.height;
									var ctx = canvas.getContext('2d', {willReadFrequently: true});
									if (!ctx) {
										image.src = src;
										return;
									}

									ctx.drawImage(bitmap, 0, 0);
									var imageData;
									try {
										imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
									} catch (e2) {
										image.src = src;
										return;
									}

									var data = imageData.data;
									var width = canvas.width;
									var height = canvas.height;
									var spanX = tileExtent[2] - tileExtent[0];
									var spanY = tileExtent[3] - tileExtent[1];
									var changed = false;

									for (var yy = 0; yy < height; yy++) {
										var mapY = tileExtent[3] - ((yy + 0.5) / height) * spanY;
										for (var xx = 0; xx < width; xx++) {
											var mapX = tileExtent[0] + ((xx + 0.5) / width) * spanX;
											if (isInsideAnyPolygon([mapX, mapY])) {
												var offset = (yy * width + xx) * 4;
												if (data[offset + 3] !== 0) {
													data[offset + 3] = 0;
													changed = true;
												}
											}
										}
									}

									if (changed) {
										ctx.putImageData(imageData, 0, 0);
										image.src = canvas.toDataURL('image/png');
									} else {
										image.src = src;
									}
								})
								.catch(function() {
									image.src = src;
								});
							return;
						} catch (e) {
							// Fall through to unmasked rendering on any per-tile error.
						}

						image.src = src;
					});
				})(layersToMask[li]);
			}
		}

		function applyCurrentMasksToTraficom() {
			var layersToMask = [];
			if (traficomWideOverviewLayer) layersToMask.push(traficomWideOverviewLayer);
			if (traficomDetailLayer) layersToMask.push(traficomDetailLayer);
			if (traficomExtraDetailLayer) layersToMask.push(traficomExtraDetailLayer);
			if (!layersToMask.length) return;

			var visibleMasks = [];
			for (var i = 0; i < activeMasks.length; i++) {
				if (activeMasks[i].visible !== false) visibleMasks.push(activeMasks[i]);
			}

			if (!visibleMasks.length) {
				for (var j = 0; j < layersToMask.length; j++) {
					setUnmaskedTileLoad(layersToMask[j].getSource());
				}
				return;
			}

			var maskCoordinates = [];
			for (var k = 0; k < visibleMasks.length; k++) {
				maskCoordinates.push(visibleMasks[k].coordinates);
			}

			applyPolygonMask(layersToMask, maskCoordinates);
		}

		function runReadyCallbacks() {
			for (var i = 0; i < readyCallbacks.length; i++) {
				readyCallbacks[i]();
			}
			readyCallbacks = [];
		}

		function initApiDataWhenReady() {
			var maxWaitMs = 5000;
			var start = Date.now();

			function tryStart() {
				var hasRefresh = typeof refreshApiData === 'function';
				var hasUrl = typeof url === 'string' && url !== '';
				var hasHeaders = typeof headers === 'object' && headers !== null;
				if (hasRefresh && hasUrl && hasHeaders) {
					refreshApiData();
					return;
				}

				if (Date.now() - start >= maxWaitMs) {
					console.warn('Init timeout: refreshApiData/url/headers not ready, skipping initial data refresh');
					return;
				}

				setTimeout(tryStart, 50);
			}

			tryStart();
		}

		function createCompatibilityHandle(layer) {
			return {
				layer: layer,
				j: {
					visible: true
				}
			};
		}

		function getCapabilitiesCacheKey(provider) {
			return provider.id + '-wmts-capabilities-v1';
		}

		function readCapabilitiesFromCache(provider) {
			try {
				var raw = localStorage.getItem(getCapabilitiesCacheKey(provider));
				if (!raw) return null;
				var parsed = JSON.parse(raw);
				if (!parsed || !parsed.xmlText || !parsed.savedAt) return null;
				var isFresh = (Date.now() - Number(parsed.savedAt)) < CAPABILITIES_TTL_MS;
				return isFresh ? parsed.xmlText : null;
			} catch (e) {
				return null;
			}
		}

		function saveCapabilitiesToCache(provider, xmlText) {
			try {
				localStorage.setItem(getCapabilitiesCacheKey(provider), JSON.stringify({
					xmlText: xmlText,
					savedAt: Date.now()
				}));
			} catch (e) {
				return;
			}
		}

		function loadCapabilities(provider) {
			var xmlText = readCapabilitiesFromCache(provider);
			var fromCache = !!xmlText;

			var fetchPromise = xmlText ? Promise.resolve(xmlText) : fetch(provider.capabilitiesUrl, {credentials: 'omit'}).then(function(res) {
				if (!res.ok) throw new Error('Capabilities-haku epäonnistui: HTTP ' + res.status);
				return res.text();
			}).then(function(text) {
				saveCapabilitiesToCache(provider, text);
				fromCache = false;
				return text;
			});

			return fetchPromise.then(function(text) {
				var parser = new olModules.WMTSCapabilities();
				var capabilities = parser.read(text);
				var layerDefs = (capabilities && capabilities.Contents && capabilities.Contents.Layer) ? capabilities.Contents.Layer : [];
				var layerNames = [];
				for (var i = 0; i < layerDefs.length; i++) {
					if (layerDefs[i] && layerDefs[i].Identifier) layerNames.push(layerDefs[i].Identifier);
				}

				if (!layerNames.length) throw new Error('Capabilities-vastauksesta ei löytynyt karttatasoja.');

				var detailLayer = pickBestLayer(layerNames, provider);
				var extraDetailLayer = pickPreferredLayer(layerNames, provider.coastalPreferredExact, provider.coastalPreferredPatterns, [detailLayer]);
				var overviewLayer = pickPreferredLayer(layerNames, provider.overviewPreferredExact, provider.overviewPreferredPatterns, [detailLayer, extraDetailLayer]);
				var wideOverviewLayer = pickPreferredLayer(layerNames, (provider.overviewPreferredExact || []).slice(1), provider.overviewPreferredPatterns, [detailLayer, extraDetailLayer, overviewLayer]);

				return {
					capabilities: capabilities,
					detailLayer: detailLayer,
					extraDetailLayer: extraDetailLayer,
					overviewLayer: overviewLayer,
					wideOverviewLayer: wideOverviewLayer,
					fromCache: fromCache
				};
			});
		}

		function createWmtsLayer(capabilities, layerName, zIndex, provider) {
			var projection = olModules.getProjection(PROJECTION_CODE);
			if (!projection) throw new Error('Tuntematon projektio: ' + PROJECTION_CODE);

			var options = olModules.optionsFromCapabilities(capabilities, {
				layer: layerName,
				matrixSet: provider.matrixSet,
				projection: projection
			});

			if (!options) throw new Error('WMTS-optioita ei saatu tasolle ' + layerName + '.');

			options.crossOrigin = 'anonymous';
			options.wrapX = false;
			var source = new olModules.WMTS(options);

			if (typeof tileProxyUrl === 'string' && tileProxyUrl !== '') {
				var originalUrlFn = source.getTileUrlFunction();
				source.setTileUrlFunction(function(tileCoord, pixelRatio, projection) {
					var url = originalUrlFn(tileCoord, pixelRatio, projection);
					if (!url) return url;
					return tileProxyUrl + '?url=' + encodeURIComponent(url);
				});
			}

			return new olModules.TileLayer({
				source: source,
				zIndex: zIndex
			});
		}

		function createBalticBackgroundLayers() {
			var topoBase = new olModules.TileLayer({
				source: new olModules.XYZ({
					url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
					crossOrigin: 'anonymous',
					maxZoom: 19
				}),
				zIndex: -30,
				opacity: 1,
				visible: true
			});

			var countryLayers = [];
			for (var i = 0; i < COUNTRY_PROVIDERS.length; i++) {
				var provider = COUNTRY_PROVIDERS[i];
				if (provider.type !== 'wms') continue;

				var wmsSource = new olModules.TileWMS({
					url: provider.wmsUrl,
					params: provider.wmsParams,
					crossOrigin: 'anonymous'
				});

				countryLayers.push(new olModules.TileLayer({
					source: wmsSource,
					zIndex: -25,
					visible: false,
					opacity: 1
				}));
			}

			return {
				topoBase: topoBase,
				countryLayers: countryLayers
			};
		}

		function isInsideArea(lon, lat, bboxLonLat) {
			var minLon = bboxLonLat[0];
			var minLat = bboxLonLat[1];
			var maxLon = bboxLonLat[2];
			var maxLat = bboxLonLat[3];
			return lon >= minLon && lon <= maxLon && lat >= minLat && lat <= maxLat;
		}

		function setupAdaptiveBackgrounds(background, onCountryProviderChange) {
			var topoBase = background.topoBase;
			var countryLayers = background.countryLayers;
			var lastVisibilityState = null; // Cache to avoid redundant setVisible calls

			function updateBackgrounds() {
				var center = mapView.getCenter();
				if (!center) return;

				var lonLat = olModules.toLonLat(center, PROJECTION_CODE);
				var lon = lonLat[0];
				var lat = lonLat[1];
				var zoom = mapView.getZoom() || 0;
				var showSwedenFallback = false;

				var activeProvider = null;
				for (var j = 0; j < COUNTRY_PROVIDERS.length; j++) {
					if (isInsideArea(lon, lat, COUNTRY_PROVIDERS[j].bboxLonLat)) {
						activeProvider = COUNTRY_PROVIDERS[j];
						break;
					}
				}

				if (activeProvider && activeProvider.id === 'sweden' && zoom >= TRAFICOM_MIN_ZOOM) {
					showSwedenFallback = true;
				}

				// Keep topo always visible as a stable base layer.
				var visibilityState = showSwedenFallback ? 'topo+fallback' : 'topo';
				if (lastVisibilityState !== visibilityState) {
					topoBase.setVisible(true);
					for (var i = 0; i < countryLayers.length; i++) {
						countryLayers[i].setVisible(showSwedenFallback);
					}
					lastVisibilityState = visibilityState;
				}

				if (typeof onCountryProviderChange === 'function') {
					onCountryProviderChange(activeProvider ? activeProvider.id : null, zoom);
				}
			}

			updateBackgrounds();
			mapInstance.on('moveend', updateBackgrounds);
		}

		navionics = window.navionics = {
			onMapReady: function(cb) {
				if (mapInstance) cb();
				else readyCallbacks.push(cb);
			},
			setSafeCenter: function(lon, lat) {
				if (!mapView || !olModules) return;
				mapView.setCenter(olModules.fromLonLat([Number(lon), Number(lat)], PROJECTION_CODE));
			},
			setZoom: function(zoom) {
				if (!mapView) return;
				mapView.setZoom(Number(zoom));
			},
			hideBalloons: function() {
				return;
			},
			removeLayer: function(handle) {
				if (!mapInstance || !handle || !handle.layer) return;
				mapInstance.removeLayer(handle.layer);
				if (handle.j) handle.j.visible = false;
			},
			loadKml: function(kmlUrl, fitPath) {
				if (!mapInstance || !olModules) return null;
				if (!kmlUrl) return null;
				var source = new olModules.VectorSource({
					url: kmlUrl,
					format: new olModules.KML({extractStyles: true, showPointNames: false}),
					crossOrigin: 'anonymous'
				});
				var layer = new olModules.VectorLayer({
					source: source,
					zIndex: 30
				});

				mapInstance.addLayer(layer);

				source.on('featuresloadend', function() {
					if (fitPath !== true) return;
					var extent = source.getExtent();
					if (!extent || !isFinite(extent[0]) || !isFinite(extent[1]) || !isFinite(extent[2]) || !isFinite(extent[3])) return;
					mapView.fit(extent, {padding: [40, 40, 40, 40], duration: 250, maxZoom: 13});
				});

				source.on('featuresloaderror', function() {
					console.warn('KML featuresloaderror for URL:', kmlUrl);
				});

				return createCompatibilityHandle(layer);
			}
		};

		Promise.all([
			import('https://esm.sh/ol@10.6.1/Map.js'),
			import('https://esm.sh/ol@10.6.1/View.js'),
			import('https://esm.sh/ol@10.6.1/layer/Tile.js'),
			import('https://esm.sh/ol@10.6.1/layer/Vector.js'),
			import('https://esm.sh/ol@10.6.1/source/XYZ.js'),
			import('https://esm.sh/ol@10.6.1/source/TileWMS.js'),
			import('https://esm.sh/ol@10.6.1/source/WMTS.js'),
			import('https://esm.sh/ol@10.6.1/source/Vector.js'),
			import('https://esm.sh/ol@10.6.1/format/KML.js'),
			import('https://esm.sh/ol@10.6.1/format/WMTSCapabilities.js'),
			import('https://esm.sh/ol@10.6.1/proj.js')
		]).then(function(modules) {
			olModules = {
				Map: modules[0].default,
				View: modules[1].default,
				TileLayer: modules[2].default,
				VectorLayer: modules[3].default,
				XYZ: modules[4].default,
				TileWMS: modules[5].default,
				WMTS: modules[6].default,
				optionsFromCapabilities: modules[6].optionsFromCapabilities,
				VectorSource: modules[7].default,
				KML: modules[8].default,
				WMTSCapabilities: modules[9].default,
				fromLonLat: modules[10].fromLonLat,
				toLonLat: modules[10].toLonLat,
				getProjection: modules[10].get,
				transformExtent: modules[10].transformExtent
			};

			mapView = new olModules.View({
				projection: PROJECTION_CODE,
				center: olModules.fromLonLat([22.0, 60.0], PROJECTION_CODE),
				zoom: 7,
				maxZoom: 18
			});

			mapInstance = new olModules.Map({
				target: 'map',
				layers: [],
				view: mapView
			});

			var background = createBalticBackgroundLayers();
			loadMasksFromGlobalData();
			mapInstance.addLayer(background.topoBase);
			for (var i = 0; i < background.countryLayers.length; i++) {
				mapInstance.addLayer(background.countryLayers[i]);
			}

			setupAdaptiveBackgrounds(background, function(countryId, zoom) {
				var currentZoom = zoom || 0;
				var showOverview = currentZoom >= TRAFICOM_MIN_ZOOM;
				var showDetail = currentZoom >= TRAFICOM_DETAIL_MIN_ZOOM;
				var showExtraDetail = currentZoom >= TRAFICOM_EXTRA_DETAIL_MIN_ZOOM;

				if (traficomWideOverviewLayer) traficomWideOverviewLayer.setVisible(showOverview);
				if (traficomDetailLayer) traficomDetailLayer.setVisible(showDetail);
				if (traficomExtraDetailLayer) traficomExtraDetailLayer.setVisible(showExtraDetail);
				
				// Only reapply masks if zoom actually changed (not on pans)
				// This prevents flickering during fast scrolling/panning
				if (Math.abs(currentZoom - lastMaskApplyZoom) >= 1) {
					lastMaskApplyZoom = currentZoom;
					applyCurrentMasksToTraficom();
				}
			});

			Hide('c-loading');
			runReadyCallbacks();
			initApiDataWhenReady();

			var provider = PROVIDERS.traficom;
			return loadCapabilities(provider).then(function(loaded) {
				var capabilities = loaded.capabilities;
				var detailLayer = loaded.detailLayer;
				var extraDetailLayer = loaded.extraDetailLayer;
				var wideOverviewLayer = loaded.wideOverviewLayer;

				var traficomExtent = olModules.transformExtent(provider.approxCoverageBboxLonLat, 'EPSG:4326', PROJECTION_CODE);

				if (wideOverviewLayer) {
					traficomWideOverviewLayer = createWmtsLayer(capabilities, wideOverviewLayer, 0, provider);
					traficomWideOverviewLayer.setExtent(traficomExtent);
					traficomWideOverviewLayer.setVisible(false);
					mapInstance.addLayer(traficomWideOverviewLayer);
					applyCurrentMasksToTraficom();
				}

				if (detailLayer) {
					traficomDetailLayer = createWmtsLayer(capabilities, detailLayer, 10, provider);
					traficomDetailLayer.setVisible(false);
					mapInstance.addLayer(traficomDetailLayer);
					applyCurrentMasksToTraficom();
				}

				if (extraDetailLayer) {
					traficomExtraDetailLayer = createWmtsLayer(capabilities, extraDetailLayer, 20, provider);
					traficomExtraDetailLayer.setVisible(false);
					mapInstance.addLayer(traficomExtraDetailLayer);
					applyCurrentMasksToTraficom();
				}

				mapInstance.dispatchEvent('moveend');
			}).catch(function(err) {
				console.warn('Traficom-taustakerroksen lataus epäonnistui', err);
			});
		}).catch(function() {
			Hide('c-loading');
			alert('Kartan lataaminen epäonnistui.');
		});
	})();

	/* Ladataan reitti */
	function viewKml(uri, fitPath) {

		fitPath = typeof fitPath !== 'undefined' && fitPath == 'Summary' ? true : fitPath;
		fitPath = typeof fitPath !== 'undefined' ? getBool(fitPath) : false;

		/* Näytetään latausikkuna */
		Show('c-loading');

		/* Piilotetaan edellinen reitti */
		if (layers.length > 0) {
			var layer = layers[0];
			navionics.removeLayer(layer);
			layers.splice(0, layers.length);
		}

		/* Näytetään reitti */
		var newLayer = navionics.loadKml(uri, fitPath);
		if (newLayer) layers.push(newLayer);

		/* Piilotetaan latausikkuna, kun reitti on ladattu */
		Hide('c-loading');

		return false;

	}
