;(async () => {
const [{default: Map}, {default: View}, {default: TileLayer}, wmtsModule, {default: XYZ}, {default: TileWMS}, {default: VectorSource}, {default: WMTSCapabilities}, {default: VectorLayer}, {default: Feature}, {default: LineString}, {default: Point}, {default: Polygon}, {default: KML}, {default: Overlay}, styleModule, controlModule, {Modify}, sphereModule, projModule] = await Promise.all([
  import('https://esm.sh/ol@10.6.1/Map.js'),
  import('https://esm.sh/ol@10.6.1/View.js'),
  import('https://esm.sh/ol@10.6.1/layer/Tile.js'),
  import('https://esm.sh/ol@10.6.1/source/WMTS.js'),
  import('https://esm.sh/ol@10.6.1/source/XYZ.js'),
  import('https://esm.sh/ol@10.6.1/source/TileWMS.js'),
  import('https://esm.sh/ol@10.6.1/source/Vector.js'),
  import('https://esm.sh/ol@10.6.1/format/WMTSCapabilities.js'),
  import('https://esm.sh/ol@10.6.1/layer/Vector.js'),
  import('https://esm.sh/ol@10.6.1/Feature.js'),
  import('https://esm.sh/ol@10.6.1/geom/LineString.js'),
  import('https://esm.sh/ol@10.6.1/geom/Point.js'),
  import('https://esm.sh/ol@10.6.1/geom/Polygon.js'),
  import('https://esm.sh/ol@10.6.1/format/KML.js'),
  import('https://esm.sh/ol@10.6.1/Overlay.js'),
  import('https://esm.sh/ol@10.6.1/style.js'),
  import('https://esm.sh/ol@10.6.1/control.js'),
  import('https://esm.sh/ol@10.6.1/interaction.js'),
  import('https://esm.sh/ol@10.6.1/sphere.js'),
  import('https://esm.sh/ol@10.6.1/proj.js')
]);

const WMTS = wmtsModule.default;
const {optionsFromCapabilities} = wmtsModule;
const {Circle: CircleStyle, Fill, Stroke, Style} = styleModule;
const {defaults: defaultControls, ScaleLine} = controlModule;
const {getDistance: getGeodesicDistance} = sphereModule;
const {fromLonLat, toLonLat, get: getProjection, transformExtent} = projModule;
const TILE_PROXY_URL = new URL('./TileProxy.php', import.meta.url).pathname;
const TILE_PATH_BASE = new URL('./tiles/', import.meta.url).pathname;

function sanitizeLayerName(name) {
  return name.toLowerCase().replace(/[^a-z0-9_-]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
}

const statusEl = document.getElementById('status');
const routePlanToggleEl = document.getElementById('routePlanToggle');
const routeDistanceEl = document.getElementById('routeDistance');
const routeUnitToggleEl = document.getElementById('routeUnitToggle');
const routeClearBtnEl = document.getElementById('routeClearBtn');
const routePanelEl = document.querySelector('.route-panel');
const routePanelCollapseToggleEl = document.getElementById('routePanelCollapseToggle');
const legacyMode = !statusEl;
const CAPABILITIES_TTL_MS = 30 * 60 * 1000;
const PROJECTION_CODE = 'EPSG:3857';
const TRAFICOM_MIN_ZOOM = 9;
const TRAFICOM_DETAIL_MIN_ZOOM = 12;
const TRAFICOM_EXTRA_DETAIL_MIN_ZOOM = 14;
const FALLBACK_CENTER = [22.245, 60.153];
const FALLBACK_ZOOM = 9;
const GEO_ZOOM = 12;
const ENABLE_TILE_PROXY_DEBUG = true;
const ENABLE_TRAFFICOM_MASKS = true;
const FORCE_PROXY_XYZ_FALLBACK = false;
const KEEP_BASEMAP_VISIBLE_DURING_OUTAGE = false;
const TRAFICOM_FAILURE_THRESHOLD = 8;
const TRAFICOM_COOLDOWN_MS = 120000;
const ENABLE_MASK_EDIT_UI = false; // Toggle for showing mask editing UI. The core mask functionality works regardless of this.
const COUNTRY_PROVIDERS = [
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
      SRS: 'EPSG:3857'
    }
  }
];
const PROVIDERS = {
  traficom: {
    id: 'traficom',
    capabilitiesUrl: 'https://julkinen.traficom.fi/rasteripalvelu/wmts?request=getcapabilities',
    matrixSet: 'WGS84_Pseudo-Mercator',
    approxCoverageBboxLonLat: [17.0, 58.0, 33.5, 70.5],
    detailPreferredExact: [
      'Traficom:Merikarttasarjat public',
    ],
    detailPreferredPatterns: [
      /Merikarttasarjat\s+public/i,
      /Merikarttasarja\s+[A-Z]\s+public/i,
      /Merikartat/i,
      /public/i
    ],
    coastalPreferredExact: [
      'Traficom:Rannikkokartat public',
      'Traficom:Veneilykartat public'
    ],
    coastalPreferredPatterns: [
      /Rannikkokartat\s+public/i,
      /Veneilykartat\s+public/i,
      /Rannikko/i,
      /Veneily/i,
      /public/i
    ],
    overviewPreferredExact: [
      'Traficom:Yleiskartat 100k public',
      'Traficom:Yleiskartat 250k public'
    ],
    overviewPreferredPatterns: [
      /Yleiskartat\s+100k\s+public/i,
      /Yleiskartat\s+250k\s+public/i,
      /public/i
    ]
  }
};

let map;
let scaleLineControl;
let routeUnit = 'nm';
let routeWaypointSource;
let routeLineFeature;
let routeModifyInteraction;
let doubleClickZoomInteraction;
let routePlanningEnabled = false;
let longPressTimerId = null;
let longPressTouchOrigin = null;
let suppressNextTouchSingleClick = false;
let statusHideTimerId = null;

const LONG_PRESS_MS = 1000;
const LONG_PRESS_MOVE_TOLERANCE_PX = 12;
const ROUTE_INDEX_PROP = 'routeIndex';
const ROUTE_STORAGE_KEY = 'route-waypoints-v1';
const ROUTE_PANEL_COLLAPSED_KEY = 'route-panel-collapsed-v1';

function setRoutePanelCollapsed(collapsed) {
  if (!routePanelEl || !routePanelCollapseToggleEl) {
    return;
  }

  const isCollapsed = Boolean(collapsed);
  routePanelEl.classList.toggle('collapsed', isCollapsed);
  routePanelCollapseToggleEl.textContent = isCollapsed ? 'Aloita reitin suunnittelu' : 'Piilota';
  routePanelCollapseToggleEl.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');

  try {
    localStorage.setItem(ROUTE_PANEL_COLLAPSED_KEY, isCollapsed ? '1' : '0');
  } catch {
    // localStorage might be unavailable (private mode or strict policies)
  }
}

function restoreRoutePanelCollapsedState() {
  if (!routePanelEl || !routePanelCollapseToggleEl) {
    return;
  }

  let collapsed = false;
  try {
    collapsed = localStorage.getItem(ROUTE_PANEL_COLLAPSED_KEY) === '1';
  } catch {
    collapsed = false;
  }

  setRoutePanelCollapsed(collapsed);
}

const routeLineLayer = new VectorLayer({
  source: new VectorSource(),
  zIndex: 40,
  style: new Style({
    stroke: new Stroke({
      color: '#0ea5e9',
      width: 3
    })
  })
});

const routeWaypointLayer = new VectorLayer({
  source: new VectorSource(),
  zIndex: 50,
  style: new Style({
    image: new CircleStyle({
      radius: 6,
      fill: new Fill({color: '#0f172a'}),
      stroke: new Stroke({color: '#ffffff', width: 2})
    })
  })
});

const maskAreaLayer = new VectorLayer({
  source: new VectorSource(),
  zIndex: 35,
  style: new Style({
    fill: new Fill({color: 'rgba(255, 0, 0, 0.1)'}),
    stroke: new Stroke({color: '#ff0000', width: 2}),
    image: new CircleStyle({
      radius: 5,
      fill: new Fill({color: '#ff0000'}),
      stroke: new Stroke({color: '#ffffff', width: 1.5})
    })
  })
});

let maskAreaPointsSource;
let maskAreaDrawingEnabled = false;
let currentMaskAreaFeature;
let maskAreaCoords = [];
let activeMasks = [];
let maskVisualizationLayer;
let maskVisualizationSource;
const MASK_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'];
let nextMaskColorIndex = 0;
let traficomWideOverviewLayer = null;
let traficomDetailLayer = null;
let traficomExtraDetailLayer = null;
const MASKS_GLOBAL_KEY = '__MAP_MASKS_DATA__';

const APP_BASE_PATH = (() => {
  const marker = '/merikortti/';
  const path = window.location.pathname;
  const idx = path.indexOf(marker);
  if (idx !== -1) {
    return path.slice(0, idx + marker.length);
  }
  return '/loki/merikortti/';
})();
const legacyKmlLayers = [];
let navionicsReady = false;
const navionicsReadyCallbacks = [];
let legacyPopupOverlay = null;
let legacyPopupTitleEl = null;
let legacyPopupContentEl = null;
let traficomFailureCount = 0;
let traficomCircuitOpenUntil = 0;
let trackEditModeEnabled = false;
let trackEditAddPointHandler = null;
let trackEditRemovePointHandler = null;
let trackEditMovePointHandler = null;
let trackEditPointSource = null;
let trackEditPointLayer = null;
let trackEditPointModifyInteraction = null;
let trackEditLineSource = null;
let trackEditLineLayer = null;

function getTrackEditPointsFromSource() {
  if (!trackEditPointSource) return [];

  const features = trackEditPointSource.getFeatures().slice().sort((a, b) => {
    const ai = Number(a.get('editOrder'));
    const bi = Number(b.get('editOrder'));
    if (Number.isNaN(ai) && Number.isNaN(bi)) return 0;
    if (Number.isNaN(ai)) return 1;
    if (Number.isNaN(bi)) return -1;
    return ai - bi;
  });

  return features.map(feature => {
    const geometry = feature.getGeometry();
    const coord = geometry ? geometry.getCoordinates() : null;
    const lonLat = coord ? toLonLat(coord, PROJECTION_CODE) : [null, null];
    return {
      Id: Number(feature.get('id')),
      Source: String(feature.get('source') || 'archive'),
      Timestamp: feature.get('timestamp') || null,
      PathInfo: feature.get('pathInfo') || null,
      Lon: Number(lonLat[0]),
      Lat: Number(lonLat[1])
    };
  });
}

function applyTrackEditLegacyLayerAppearance() {
  legacyKmlLayers.forEach(handle => {
    if (!handle || !handle.layer) return;
    if (typeof handle.layer.setVisible === 'function') {
      handle.layer.setVisible(!trackEditModeEnabled);
    }
    if (typeof handle.layer.setOpacity === 'function') {
      handle.layer.setOpacity(1);
    }
  });
}

function updateTrackEditPreviewLine(points) {
  if (!trackEditLineSource) return;

  trackEditLineSource.clear();
  if (!Array.isArray(points) || points.length < 2) return;

  const ordered = points.filter(point => point && isFinite(Number(point.Lon)) && isFinite(Number(point.Lat)));

  if (ordered.length < 2) return;

  const coordinates = ordered.map(point => fromLonLat([Number(point.Lon), Number(point.Lat)], PROJECTION_CODE));
  const lineFeature = new Feature({
    geometry: new LineString(coordinates)
  });
  trackEditLineSource.addFeature(lineFeature);
}

function ensureTrackEditPointTools() {
  if (!map) return;

  if (!trackEditPointSource) {
    trackEditPointSource = new VectorSource();
  }

  if (!trackEditPointLayer) {
    trackEditPointLayer = new VectorLayer({
      source: trackEditPointSource,
      zIndex: 85,
      style: feature => {
        const source = String(feature.get('source') || 'archive');
        const pathInfo = String(feature.get('pathInfo') || '');

        let fillColor = source === 'manual' ? '#22c55e' : '#f59e0b';
        if (pathInfo === 'Start') fillColor = '#16a34a';
        if (pathInfo === 'End') fillColor = '#dc2626';

        return new Style({
          image: new CircleStyle({
            radius: source === 'manual' ? 5 : 4,
            fill: new Fill({color: fillColor}),
            stroke: new Stroke({color: '#ffffff', width: 1.5})
          })
        });
      },
      visible: false
    });
    map.addLayer(trackEditPointLayer);
  }

  if (trackEditPointSource && !trackEditPointSource.__trackEditPreviewBound) {
    trackEditPointSource.on('changefeature', () => {
      if (!trackEditModeEnabled) return;
      updateTrackEditPreviewLine(getTrackEditPointsFromSource());
    });
    trackEditPointSource.on('addfeature', () => {
      if (!trackEditModeEnabled) return;
      updateTrackEditPreviewLine(getTrackEditPointsFromSource());
    });
    trackEditPointSource.on('removefeature', () => {
      if (!trackEditModeEnabled) return;
      updateTrackEditPreviewLine(getTrackEditPointsFromSource());
    });
    trackEditPointSource.__trackEditPreviewBound = true;
  }

  if (!trackEditLineSource) {
    trackEditLineSource = new VectorSource();
  }

  if (!trackEditLineLayer) {
    trackEditLineLayer = new VectorLayer({
      source: trackEditLineSource,
      zIndex: 82,
      style: new Style({
        stroke: new Stroke({
          color: '#2563eb',
          width: 4
        })
      }),
      visible: false
    });
    map.addLayer(trackEditLineLayer);
  }

  if (!trackEditPointModifyInteraction) {
    trackEditPointModifyInteraction = new Modify({source: trackEditPointSource});
    trackEditPointModifyInteraction.setActive(false);
    trackEditPointModifyInteraction.on('modifyend', event => {
      if (!trackEditModeEnabled || typeof trackEditMovePointHandler !== 'function') return;

      event.features.forEach(feature => {
        const geometry = feature.getGeometry();
        if (!geometry) return;
        const coordinates = geometry.getCoordinates();
        const lonLat = toLonLat(coordinates, PROJECTION_CODE);
        if (!lonLat || lonLat.length < 2) return;

        trackEditMovePointHandler({
          Id: Number(feature.get('id')),
          Source: String(feature.get('source') || 'archive'),
          PathInfo: String(feature.get('pathInfo') || ''),
          Lat: Number(lonLat[1]),
          Lon: Number(lonLat[0])
        });
      });
    });
    map.addInteraction(trackEditPointModifyInteraction);
  }
}

function isTraficomCircuitOpen() {
  return Date.now() < traficomCircuitOpenUntil;
}

function openTraficomCircuit(background) {
  traficomCircuitOpenUntil = Date.now() + TRAFICOM_COOLDOWN_MS;
  traficomFailureCount = 0;

  if (traficomWideOverviewLayer) {
    traficomWideOverviewLayer.setVisible(false);
  }
  if (traficomDetailLayer) {
    traficomDetailLayer.setVisible(false);
  }
  if (traficomExtraDetailLayer) {
    traficomExtraDetailLayer.setVisible(false);
  }

  if (background?.topoBase) {
    background.topoBase.setVisible(true);
  }

  const seconds = Math.round(TRAFICOM_COOLDOWN_MS / 1000);
  setStatus(`Traficom pois käytöstä hetkeksi (${seconds}s) palveluhäiriön vuoksi. Näytetään varakartta.`);
}

async function loadMasksFromDisk() {
  const globalMasksData = window[MASKS_GLOBAL_KEY];
  if (globalMasksData && Array.isArray(globalMasksData.masks)) {
    return globalMasksData;
  }

  try {
    const response = await fetch(`${APP_BASE_PATH}masks.json`, {cache: 'no-store'});
    if (!response.ok) {
      return {version: '1.0', masks: []};
    }

    const data = await response.json();
    if (!data || !Array.isArray(data.masks)) {
      return {version: '1.0', masks: []};
    }

    return data;
  } catch {
    if (location.protocol === 'file:') {
      console.warn('Maskit eivat latautuneet file://-tilassa. Kayta paikallista http-palvelinta tai pidä masks-data.js mukana.');
    }
    return {version: '1.0', masks: []};
  }
}

function setStatus(message, isError = false) {
  if (!statusEl) {
    return;
  }

  if (statusHideTimerId) {
    window.clearTimeout(statusHideTimerId);
    statusHideTimerId = null;
  }

  statusEl.textContent = message;
  statusEl.classList.remove('hidden');
  statusEl.classList.toggle('error', isError);
}

function hideStatusAfterDelay(delayMs = 2500) {
  if (!statusEl) {
    return;
  }

  if (statusHideTimerId) {
    window.clearTimeout(statusHideTimerId);
  }

  statusHideTimerId = window.setTimeout(() => {
    if (!statusEl.classList.contains('error')) {
      statusEl.classList.add('hidden');
    }
    statusHideTimerId = null;
  }, delayMs);
}

function getRouteCoordinates() {
  return routeWaypointLayer
    .getSource()
    .getFeatures()
    .slice()
    .sort((a, b) => {
      const ai = Number(a.get(ROUTE_INDEX_PROP));
      const bi = Number(b.get(ROUTE_INDEX_PROP));
      if (Number.isNaN(ai) && Number.isNaN(bi)) {
        return 0;
      }
      if (Number.isNaN(ai)) {
        return 1;
      }
      if (Number.isNaN(bi)) {
        return -1;
      }
      return ai - bi;
    })
    .map(feature => feature.getGeometry().getCoordinates());
}

function setRouteCoordinates(coords) {
  routeWaypointSource.clear();
  coords.forEach((coordinate, index) => {
    const feature = new Feature({
      geometry: new Point(coordinate)
    });
    feature.set(ROUTE_INDEX_PROP, index);
    routeWaypointSource.addFeature(feature);
  });
  saveRouteToStorage(coords);
  updateRouteLineAndDistance();
}

function saveRouteToStorage(projectedCoords) {
  try {
    if (!Array.isArray(projectedCoords) || projectedCoords.length === 0) {
      localStorage.removeItem(ROUTE_STORAGE_KEY);
      return;
    }

    const lonLatCoords = projectedCoords.map(coord => toLonLat(coord, PROJECTION_CODE));
    const payload = {
      version: '1.0',
      projection: 'EPSG:4326',
      coordinates: lonLatCoords,
      savedAt: new Date().toISOString()
    };

    localStorage.setItem(ROUTE_STORAGE_KEY, JSON.stringify(payload));
  } catch {
    // Ignore storage errors.
  }
}

function loadRouteFromStorage() {
  try {
    const raw = localStorage.getItem(ROUTE_STORAGE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    const coordinates = parsed?.coordinates;
    if (!Array.isArray(coordinates) || coordinates.length === 0) {
      return [];
    }

    return coordinates
      .filter(coord => Array.isArray(coord) && coord.length >= 2)
      .map(coord => fromLonLat([coord[0], coord[1]], PROJECTION_CODE));
  } catch {
    return [];
  }
}

function calculateRouteLengthMeters(coords) {
  let totalMeters = 0;
  for (let i = 1; i < coords.length; i += 1) {
    const a = toLonLat(coords[i - 1], PROJECTION_CODE);
    const b = toLonLat(coords[i], PROJECTION_CODE);
    totalMeters += getGeodesicDistance(a, b);
  }
  return totalMeters;
}

function updateRouteLineAndDistance() {
  if (!routeDistanceEl) {
    return;
  }

  const coords = getRouteCoordinates();
  if (routeClearBtnEl) {
    routeClearBtnEl.hidden = coords.length === 0;
  }
  if (!routeLineFeature) {
    routeLineFeature = new Feature(new LineString(coords));
    routeLineLayer.getSource().addFeature(routeLineFeature);
  } else {
    routeLineFeature.getGeometry().setCoordinates(coords);
  }

  const meters = calculateRouteLengthMeters(coords);
  const value = routeUnit === 'nm' ? (meters / 1852) : (meters / 1000);
  routeDistanceEl.textContent = `Reitti: ${value.toFixed(2)} ${routeUnit}`;
}

function setRouteUnits(nextUnit) {
  routeUnit = nextUnit === 'km' ? 'km' : 'nm';
  if (routeUnitToggleEl) {
    routeUnitToggleEl.textContent = `Yksikkö: ${routeUnit}`;
  }
  if (scaleLineControl) {
    scaleLineControl.setUnits(routeUnit === 'nm' ? 'nautical' : 'metric');
  }
  updateRouteLineAndDistance();
}

function isPixelOnRouteLine(pixel) {
  let hitRouteLine = false;
  map.forEachFeatureAtPixel(
    pixel,
    feature => {
      if (feature === routeLineFeature) {
        hitRouteLine = true;
        return true;
      }
      return false;
    },
    {layerFilter: layer => layer === routeLineLayer, hitTolerance: 8}
  );
  return hitRouteLine;
}

function findInsertIndexForCoordinate(coords, coordinate) {
  if (coords.length < 2) {
    return coords.length;
  }

  let bestInsertIndex = coords.length;
  let bestDistanceSq = Number.POSITIVE_INFINITY;

  for (let i = 0; i < coords.length - 1; i += 1) {
    const segment = new LineString([coords[i], coords[i + 1]]);
    const closest = segment.getClosestPoint(coordinate);
    const dx = closest[0] - coordinate[0];
    const dy = closest[1] - coordinate[1];
    const distanceSq = dx * dx + dy * dy;
    if (distanceSq < bestDistanceSq) {
      bestDistanceSq = distanceSq;
      bestInsertIndex = i + 1;
    }
  }

  return bestInsertIndex;
}

function addRouteWaypoint(coordinate, pixel = null) {
  const coords = getRouteCoordinates();
  if (coords.length < 2 || !pixel || !isPixelOnRouteLine(pixel)) {
    coords.push(coordinate);
  } else {
    const insertIndex = findInsertIndexForCoordinate(coords, coordinate);
    coords.splice(insertIndex, 0, coordinate);
  }
  setRouteCoordinates(coords);
}

function removeRouteWaypointAtPixel(pixel) {
  let removed = false;
  map.forEachFeatureAtPixel(
    pixel,
    feature => {
      if (feature && routeWaypointLayer.getSource().hasFeature(feature)) {
        routeWaypointLayer.getSource().removeFeature(feature);
        removed = true;
        return true;
      }
      return false;
    },
    {layerFilter: layer => layer === routeWaypointLayer}
  );

  if (removed) {
    setRouteCoordinates(getRouteCoordinates());
  }
}

function setRoutePlanningEnabled(enabled) {
  if (!routePlanToggleEl) {
    routePlanningEnabled = false;
    return;
  }

  routePlanningEnabled = Boolean(enabled);
  routePlanToggleEl.classList.toggle('active', routePlanningEnabled);
  routePlanToggleEl.textContent = routePlanningEnabled
    ? 'Reittisuunnittelu: päällä'
    : 'Aloita reitti';
  if (routeModifyInteraction) {
    routeModifyInteraction.setActive(routePlanningEnabled);
  }
  if (doubleClickZoomInteraction && typeof doubleClickZoomInteraction.setActive === 'function') {
    doubleClickZoomInteraction.setActive(!routePlanningEnabled && !trackEditModeEnabled);
  }
}

function clearLongPressTimer() {
  if (longPressTimerId) {
    window.clearTimeout(longPressTimerId);
  }
  longPressTimerId = null;
  longPressTouchOrigin = null;
}

function isTouchLikeEvent(event) {
  if (!event) {
    return false;
  }

  if (event.pointerType) {
    return event.pointerType === 'touch' || event.pointerType === 'pen';
  }

  return 'touches' in event || 'changedTouches' in event;
}

function setupRoutePlanning() {
  if (!routePlanToggleEl || !routeDistanceEl || !routeUnitToggleEl) {
    return;
  }

  routeWaypointSource = routeWaypointLayer.getSource();
  map.addLayer(routeLineLayer);
  map.addLayer(routeWaypointLayer);

  doubleClickZoomInteraction = map
    .getInteractions()
    .getArray()
    .find(interaction => interaction?.constructor?.name === 'DoubleClickZoom');

  routeModifyInteraction = new Modify({source: routeWaypointSource});
  map.addInteraction(routeModifyInteraction);

  routeWaypointSource.on('changefeature', updateRouteLineAndDistance);
  routeModifyInteraction.on('modifyend', () => {
    updateRouteLineAndDistance();
    saveRouteToStorage(getRouteCoordinates());
  });

  map.on('singleclick', event => {
    if (!routePlanningEnabled || !isTouchLikeEvent(event.originalEvent)) {
      return;
    }

    if (suppressNextTouchSingleClick) {
      suppressNextTouchSingleClick = false;
      return;
    }

    addRouteWaypoint(event.coordinate, event.pixel);
  });

  map.on('dblclick', event => {
    if (!routePlanningEnabled) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    addRouteWaypoint(event.coordinate, event.pixel);
  });

  map.getViewport().addEventListener('contextmenu', event => {
    if (!routePlanningEnabled) {
      return;
    }
    event.preventDefault();
    const pixel = map.getEventPixel(event);
    removeRouteWaypointAtPixel(pixel);
  });

  const viewport = map.getViewport();

  viewport.addEventListener('touchstart', event => {
    if (!routePlanningEnabled || event.touches.length !== 1) {
      return;
    }

    const touch = event.touches[0];
    longPressTouchOrigin = {
      clientX: touch.clientX,
      clientY: touch.clientY
    };

    clearLongPressTimer();
    longPressTouchOrigin = {
      clientX: touch.clientX,
      clientY: touch.clientY
    };
    longPressTimerId = window.setTimeout(() => {
      if (!routePlanningEnabled || !longPressTouchOrigin) {
        clearLongPressTimer();
        return;
      }

      const pixel = map.getEventPixel({
        clientX: longPressTouchOrigin.clientX,
        clientY: longPressTouchOrigin.clientY
      });
      const coordinate = map.getCoordinateFromPixel(pixel);
      suppressNextTouchSingleClick = true;
      addRouteWaypoint(coordinate, pixel);
      clearLongPressTimer();
    }, LONG_PRESS_MS);
  }, {passive: true});

  viewport.addEventListener('touchmove', event => {
    if (!longPressTimerId || !longPressTouchOrigin || event.touches.length !== 1) {
      return;
    }

    const touch = event.touches[0];
    const dx = touch.clientX - longPressTouchOrigin.clientX;
    const dy = touch.clientY - longPressTouchOrigin.clientY;
    if (Math.hypot(dx, dy) > LONG_PRESS_MOVE_TOLERANCE_PX) {
      clearLongPressTimer();
    }
  }, {passive: true});

  viewport.addEventListener('touchend', clearLongPressTimer, {passive: true});
  viewport.addEventListener('touchcancel', clearLongPressTimer, {passive: true});

  routeUnitToggleEl.addEventListener('click', () => {
    setRouteUnits(routeUnit === 'nm' ? 'km' : 'nm');
  });

  routePlanToggleEl.addEventListener('click', () => {
    setRoutePlanningEnabled(!routePlanningEnabled);
  });

  routeClearBtnEl?.addEventListener('click', () => {
    setRouteCoordinates([]);
    setStatus('Reitti tyhjennetty', false);
    hideStatusAfterDelay(1200);
  });

  routePanelCollapseToggleEl?.addEventListener('click', () => {
    setRoutePanelCollapsed(!routePanelEl?.classList.contains('collapsed'));
  });

  setRouteUnits('nm');
  restoreRoutePanelCollapsedState();
  const savedRoute = loadRouteFromStorage();
  if (savedRoute.length) {
    setRouteCoordinates(savedRoute);
  }
  setRoutePlanningEnabled(false);
}

function setupLegacyNavionicsBridge() {
  function ensureLegacyPopupStyles() {
    if (document.getElementById('legacy-kml-popup-style')) {
      return;
    }

    const styleEl = document.createElement('style');
    styleEl.id = 'legacy-kml-popup-style';
    styleEl.textContent = `
      .legacy-kml-balloon {
        position: relative;
        min-width: 170px;
        max-width: 320px;
        border-radius: 6px;
        border: 1px solid rgba(0, 0, 0, 0.15);
        background: linear-gradient(-90deg, #f5f5f5 75%, #1e90ff 25%);
        -webkit-filter: drop-shadow(0 0 13px rgba(60,60,60,.4));
        filter: drop-shadow(0 0 13px rgba(60,60,60,.4));
        font-family: Lato, Calibri, Verdana, Arial, sans-serif;
        letter-spacing: 0.2px;
        line-height: 1.25;
        color: #111827;
      }

      .legacy-kml-balloon::after {
        content: '';
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        bottom: -8px;
        border-left: 8px solid transparent;
        border-right: 8px solid transparent;
        border-top: 8px solid #f5f5f5;
      }

      .legacy-kml-balloon-strip {
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 26%;
        border-radius: 6px 0 0 6px;
        background: linear-gradient(180deg, #3b82f6 0%, #1e3a8a 100%);
      }

      .legacy-kml-balloon .jnc-balloon-title,
      .legacy-kml-balloon .jnc-balloon-content {
        margin-left: 30%;
        padding-right: 10px;
      }

      .legacy-kml-balloon .jnc-balloon-title {
        padding-top: 8px;
        font-weight: 800;
        font-size: 14px;
      }

      .legacy-kml-balloon .jnc-balloon-title a {
        color: #4338ca;
        text-decoration: underline;
      }

      .legacy-kml-balloon .jnc-balloon-content {
        padding-top: 2px;
        padding-bottom: 8px;
        color: #6b7280;
        font-weight: 700;
        font-size: 13px;
      }
    `;
    document.head.appendChild(styleEl);
  }

  function hideLegacyPopup() {
    if (legacyPopupOverlay) {
      legacyPopupOverlay.setPosition(undefined);
    }
  }

  function repairMojibakeText(value) {
    if (typeof value !== 'string' || value.length === 0) {
      return value;
    }

    if (!/[Ãâ]/.test(value)) {
      return value;
    }

    const replacements = {
      'Ã¥': 'å',
      'Ã…': 'Å',
      'Ã¤': 'ä',
      'Ã„': 'Ä',
      'Ã¶': 'ö',
      'Ã–': 'Ö',
      'Ã©': 'é',
      'Ã‰': 'É',
      'Ã¼': 'ü',
      'Ãœ': 'Ü',
      'Ã³': 'ó',
      'Ã“': 'Ó',
      'â€“': '-',
      'â€”': '-',
      'â€¦': '...',
      'â€œ': '"',
      'â€�': '"',
      'â€˜': "'",
      'â€™': "'"
    };

    return value.replace(/Ã¥|Ã…|Ã¤|Ã„|Ã¶|Ã–|Ã©|Ã‰|Ã¼|Ãœ|Ã³|Ã“|â€“|â€”|â€¦|â€œ|â€�|â€˜|â€™/g, match => replacements[match] || match);
  }

  function ensureLegacyPopup() {
    if (!map || legacyPopupOverlay) {
      return;
    }

    ensureLegacyPopupStyles();

    const popupEl = document.createElement('div');
    popupEl.className = 'jnc-balloon-container legacy-kml-balloon';

    const leftStripEl = document.createElement('div');
    leftStripEl.className = 'legacy-kml-balloon-strip';

    legacyPopupTitleEl = document.createElement('div');
    legacyPopupTitleEl.className = 'jnc-balloon-title';

    legacyPopupContentEl = document.createElement('div');
    legacyPopupContentEl.className = 'jnc-balloon-content';

    popupEl.appendChild(leftStripEl);
    popupEl.appendChild(legacyPopupTitleEl);
    popupEl.appendChild(legacyPopupContentEl);

    legacyPopupOverlay = new Overlay({
      element: popupEl,
      positioning: 'bottom-center',
      offset: [0, -14],
      stopEvent: true,
      autoPan: {
        animation: {duration: 180}
      }
    });

    map.addOverlay(legacyPopupOverlay);

    map.on('singleclick', event => {
      let popupName = '';
      let popupDescription = '';

      map.forEachFeatureAtPixel(
        event.pixel,
        (feature, hitLayer) => {
          if (!feature || !hitLayer) {
            return false;
          }

          const isLegacyKmlLayer = legacyKmlLayers.some(handle => handle && handle.layer === hitLayer);
          if (!isLegacyKmlLayer) {
            return false;
          }

          const name = feature.get('name');
          const description = feature.get('description');
          if (!name && !description) {
            return false;
          }

          popupName = repairMojibakeText(name || '');
          popupDescription = repairMojibakeText(description || '');
          return true;
        },
        {hitTolerance: 8}
      );

      if ((popupName || popupDescription) && legacyPopupContentEl) {
        if (legacyPopupTitleEl) {
          legacyPopupTitleEl.innerHTML = popupName;
        }
        legacyPopupContentEl.innerHTML = popupDescription;
        legacyPopupOverlay.setPosition(event.coordinate);
      } else {
        hideLegacyPopup();
      }
    });
  }

  function normalizeEndpointScaleInKml(kmlText) {
    if (typeof kmlText !== 'string' || kmlText.length === 0) {
      return kmlText;
    }

    const normalizedText = repairMojibakeText(kmlText);

    // Simple KML text transform requested by user before parsing into features.
    return normalizedText.replace(/<scale>\s*1\.1\s*<\/scale>/g, '<scale>2.2</scale>');
  }

  function runNavionicsReady() {
    ensureLegacyPopup();
    navionicsReady = true;
    navionicsReadyCallbacks.splice(0).forEach(cb => {
      try {
        cb();
      } catch {
        // Keep bridge resilient to callback errors.
      }
    });
  }

  window.navionics = {
    onMapReady(cb) {
      if (navionicsReady) {
        cb();
      } else {
        navionicsReadyCallbacks.push(cb);
      }
    },
    setSafeCenter(lon, lat) {
      if (!map) return;
      map.getView().setCenter(fromLonLat([Number(lon), Number(lat)], PROJECTION_CODE));
    },
    setZoom(zoom) {
      if (!map) return;
      map.getView().setZoom(Number(zoom));
    },
    hideBalloons() {
      hideLegacyPopup();
    },
    removeLayer(handle) {
      if (!map || !handle || !handle.layer) return;
      map.removeLayer(handle.layer);
      if (handle.j) {
        handle.j.visible = false;
      }
    },
    setTrackEditMode(enabled, handlers) {
      trackEditModeEnabled = Boolean(enabled);
      ensureTrackEditPointTools();
      if (typeof handlers === 'function') {
        trackEditAddPointHandler = handlers;
        trackEditRemovePointHandler = null;
        trackEditMovePointHandler = null;
      } else {
        trackEditAddPointHandler = handlers && typeof handlers.onAdd === 'function' ? handlers.onAdd : null;
        trackEditRemovePointHandler = handlers && typeof handlers.onRemove === 'function' ? handlers.onRemove : null;
        trackEditMovePointHandler = handlers && typeof handlers.onMove === 'function' ? handlers.onMove : null;
      }

      if (trackEditPointLayer) {
        trackEditPointLayer.setVisible(trackEditModeEnabled);
      }

      if (trackEditLineLayer) {
        trackEditLineLayer.setVisible(trackEditModeEnabled);
      }

      if (trackEditPointModifyInteraction) {
        trackEditPointModifyInteraction.setActive(trackEditModeEnabled);
      }

      if (doubleClickZoomInteraction && typeof doubleClickZoomInteraction.setActive === 'function') {
        doubleClickZoomInteraction.setActive(!routePlanningEnabled && !trackEditModeEnabled);
      }

      if (map && typeof map.getTargetElement === 'function') {
        const target = map.getTargetElement();
        if (target && target.style) {
          target.style.cursor = trackEditModeEnabled ? 'crosshair' : '';
        }
      }

      applyTrackEditLegacyLayerAppearance();
    },
    isTrackEditMode() {
      return Boolean(trackEditModeEnabled);
    },
    setTrackEditPoints(points) {
      ensureTrackEditPointTools();
      if (!trackEditPointSource) return;

      trackEditPointSource.clear();
      if (!Array.isArray(points)) return;

      const features = [];
      points.forEach((point, index) => {
        if (!point) return;
        if (!isFinite(Number(point.Lon)) || !isFinite(Number(point.Lat))) return;

        const feature = new Feature({
          geometry: new Point(fromLonLat([Number(point.Lon), Number(point.Lat)], PROJECTION_CODE))
        });
        feature.set('editOrder', index);
        feature.set('id', Number(point.Id));
        feature.set('source', String(point.Source || 'archive'));
        feature.set('pathInfo', point.PathInfo || null);
        feature.set('timestamp', point.Timestamp || null);
        features.push(feature);
      });

      if (features.length > 0) {
        trackEditPointSource.addFeatures(features);
      }

      updateTrackEditPreviewLine(points);
    },
    loadKml(kmlUrl, fitPath) {
      if (!map) return null;

      const kmlFormat = new KML({extractStyles: true, showPointNames: false});
      const source = new VectorSource({
        crossOrigin: 'anonymous'
      });
      const layer = new VectorLayer({
        source,
        zIndex: 60
      });

      map.addLayer(layer);

      fetch(kmlUrl, {cache: 'no-store'})
        .then(response => {
          if (!response.ok) {
            throw new Error(`KML fetch failed: HTTP ${response.status}`);
          }
          return response.text();
        })
        .then(kmlText => {
          const normalizedKml = normalizeEndpointScaleInKml(kmlText);
          const features = kmlFormat.readFeatures(normalizedKml, {
            featureProjection: PROJECTION_CODE
          });
          source.clear();
          source.addFeatures(features);

          if (fitPath !== true) return;
          const extent = source.getExtent();
          if (!extent || !isFinite(extent[0]) || !isFinite(extent[1]) || !isFinite(extent[2]) || !isFinite(extent[3])) return;
          map.getView().fit(extent, {padding: [40, 40, 40, 40], duration: 250, maxZoom: 13});
        })
        .catch(() => {
          // Keep UI resilient on temporary KML fetch issues.
        });

      return {layer, j: {visible: true}};
    }
  };

  window.viewKml = function(uri, fitPath) {
    const normalizedFit = fitPath === 'Summary' ? true : Boolean(fitPath);

    if (typeof window.Show === 'function') {
      window.Show('c-loading');
    }

    while (legacyKmlLayers.length > 0) {
      const layer = legacyKmlLayers.pop();
      window.navionics.removeLayer(layer);
    }

    const newLayer = window.navionics.loadKml(uri, normalizedFit);
    if (newLayer) {
      legacyKmlLayers.push(newLayer);
      applyTrackEditLegacyLayerAppearance();
    }

    if (typeof window.Hide === 'function') {
      window.Hide('c-loading');
    }

    return false;
  };

  return runNavionicsReady;
}

function setupMaskAreaDrawing(initialMaskData = {version: '1.0', masks: []}) {
  maskAreaPointsSource = maskAreaLayer.getSource();
  if (ENABLE_MASK_EDIT_UI) {
    map.addLayer(maskAreaLayer);
  }

  // Create visualization layer for mask polygons
  maskVisualizationSource = new VectorSource();
  maskVisualizationLayer = new VectorLayer({
    source: maskVisualizationSource,
    zIndex: 30,
    style: feature => {
      const color = feature.get('maskColor');
      // Convert 6-char hex (#RRGGBB) to 8-char hex with alpha (#RRGGBBAA)
      const colorWithAlpha = color + '55'; // 55 = ~34% opacity
      return new Style({
        fill: new Fill({color: colorWithAlpha}),
        stroke: new Stroke({color: color, width: 2}),
        zIndex: 30
      });
    }
  });
  map.addLayer(maskVisualizationLayer);

  // UI IDs for mask controls
  const maskAreaPanelEl = document.getElementById('maskAreaPanel');
  const maskModeToggleEl = document.getElementById('maskModeToggle');
  const maskAreaToggleEl = document.getElementById('maskAreaToggle');
  const maskAreasListEl = document.getElementById('maskAreasList');
  let maskEditMode = ENABLE_MASK_EDIT_UI;

  if (maskAreaPanelEl) {
    maskAreaPanelEl.hidden = !ENABLE_MASK_EDIT_UI;
  }

  function updateMaskModeUi() {
    if (maskModeToggleEl) {
      maskModeToggleEl.classList.toggle('view', !maskEditMode);
      maskModeToggleEl.textContent = maskEditMode ? 'Tila: muokkaus' : 'Tila: katselu';
    }
    if (maskAreaToggleEl) {
      maskAreaToggleEl.disabled = !maskEditMode;
    }
    if (maskVisualizationLayer) {
      maskVisualizationLayer.setVisible(maskEditMode);
    }

    if (!maskEditMode) {
      maskAreaDrawingEnabled = false;
      if (maskAreaToggleEl) {
        maskAreaToggleEl.classList.toggle('active', false);
        maskAreaToggleEl.textContent = 'Aloita piirto';
      }
      clearMaskAreaDrawing();
    }
  }

  function addMaskToVisualization(mask) {
    const polygonCoords = mask.projectedCoords;
    const feature = new Feature({
      geometry: new Polygon([polygonCoords])
    });
    feature.set('maskColor', mask.color);
    feature.set('maskId', mask.id);
    maskVisualizationSource.addFeature(feature);
  }

  function loadMasksFromData(data) {
    if (!data || !Array.isArray(data.masks)) {
      return;
    }

    data.masks.forEach((mask, index) => {
      if (!Array.isArray(mask.coordinates) || mask.coordinates.length < 3) {
        return;
      }

      const coords = mask.coordinates.slice();
      const first = coords[0];
      const last = coords[coords.length - 1];
      if (!first || !last || first[0] !== last[0] || first[1] !== last[1]) {
        coords.push(first);
      }

      const color = mask.color || MASK_COLORS[nextMaskColorIndex % MASK_COLORS.length];
      nextMaskColorIndex += 1;

      const hydratedMask = {
        id: `mask_seed_${index}_${Date.now()}`,
        name: mask.name || `Alue ${activeMasks.length + 1}`,
        coordinates: coords,
        projectedCoords: coords.map(coord => fromLonLat(coord, PROJECTION_CODE)),
        color,
        visible: true,
        createdAt: mask.createdAt || new Date().toISOString()
      };

      activeMasks.push(hydratedMask);
      addMaskToVisualization(hydratedMask);
    });
  }

  function updateMasksList() {
    if (!ENABLE_MASK_EDIT_UI || !maskAreasListEl) {
      applyCurrentMasksToTraficom();
      return;
    }

    maskAreasListEl.innerHTML = '';
    
    activeMasks.forEach((mask, index) => {
      const item = document.createElement('div');
      item.className = 'mask-item';
      item.dataset.maskId = mask.id;
      
      const info = document.createElement('div');
      info.style.display = 'grid';
      info.style.gridTemplateColumns = '20px 1fr';
      info.style.gap = '8px';
      info.style.alignItems = 'center';
      info.style.gridColumn = '1 / -1';
      
      const colorBox = document.createElement('div');
      colorBox.className = 'mask-item-color';
      colorBox.style.backgroundColor = mask.color;
      
      const textDiv = document.createElement('div');
      textDiv.className = 'mask-item-info';
      
      const nameSpan = document.createElement('div');
      nameSpan.className = 'mask-item-name';
      nameSpan.textContent = mask.name;
      
      const metaSpan = document.createElement('div');
      metaSpan.className = 'mask-item-meta';
      metaSpan.textContent = `${(mask.coordinates.length - 1)} pistettä`;
      
      textDiv.appendChild(nameSpan);
      textDiv.appendChild(metaSpan);
      info.appendChild(colorBox);
      info.appendChild(textDiv);
      
      const controls = document.createElement('div');
      controls.className = 'mask-item-controls';
      
      const visBtn = document.createElement('button');
      visBtn.className = `mask-item-btn toggle-vis ${!mask.visible ? 'hidden' : ''}`;
      visBtn.textContent = mask.visible ? '👁️ Näkyvä' : '👁️ Piilotettu';
      visBtn.addEventListener('click', () => {
        mask.visible = !mask.visible;
        const feature = maskVisualizationSource.getFeatures()
          .find(f => f.get('maskId') === mask.id);
        if (feature) {
          const alpha = mask.visible ? '55' : '00'; // 55 = ~34% opacity, 00 = transparent
          feature.setStyle(new Style({
            fill: new Fill({color: mask.color + alpha}),
            stroke: new Stroke({color: mask.color, width: 2}),
            zIndex: 30
          }));
        }
        updateMasksList();
      });
      
      const editBtn = document.createElement('button');
      editBtn.className = 'mask-item-btn edit';
      editBtn.textContent = '✎ Muokkaa';
      editBtn.addEventListener('click', () => {
        if (!maskAreaToggleEl) {
          return;
        }
        setStatus('Muokkaus: piirtä uusi versio alueelle', false);
        // Start drawing mode with existing mask visible for reference
        maskAreaDrawingEnabled = true;
        maskAreaToggleEl.classList.toggle('active', true);
        maskAreaToggleEl.textContent = 'Alueiden piirto: päällä (ESC sulkee)';
        // Could enhance: load existing coordinates and allow modification
        hideStatusAfterDelay(3000);
      });
      
      const deleteBtn = document.createElement('button');
      deleteBtn.className = 'mask-item-btn delete';
      deleteBtn.textContent = '🗑️ Poista';
      deleteBtn.addEventListener('click', () => {
        activeMasks = activeMasks.filter(m => m.id !== mask.id);
        const feature = maskVisualizationSource.getFeatures()
          .find(f => f.get('maskId') === mask.id);
        if (feature) {
          maskVisualizationSource.removeFeature(feature);
        }
        setStatus(`Alue poistettu`, false);
        updateMasksList();
        hideStatusAfterDelay(1500);
      });
      
      controls.appendChild(visBtn);
      if (maskEditMode) {
        controls.appendChild(editBtn);
        controls.appendChild(deleteBtn);
      }
      
      item.appendChild(info);
      item.appendChild(controls);
      maskAreasListEl.appendChild(item);
    });
    
    applyCurrentMasksToTraficom();
  }

  function clearMaskAreaDrawing() {
    maskAreaCoords = [];
    maskAreaPointsSource.clear();
    currentMaskAreaFeature = null;
  }

  function addMaskAreaPoint(coordinate) {
    maskAreaCoords.push(coordinate);
    const pointFeature = new Feature({
      geometry: new Point(coordinate)
    });
    maskAreaPointsSource.addFeature(pointFeature);

    if (maskAreaCoords.length > 1) {
      if (!currentMaskAreaFeature) {
        currentMaskAreaFeature = new Feature({
          geometry: new LineString(maskAreaCoords)
        });
        maskAreaPointsSource.addFeature(currentMaskAreaFeature);
      } else {
        currentMaskAreaFeature.getGeometry().setCoordinates(maskAreaCoords);
      }
    }
  }

  function finishMaskArea() {
    if (maskAreaCoords.length < 3) {
      setStatus('Alue vaatii vähintään 3 pistettä', true);
      return;
    }

    // Close the polygon
    const closedCoords = [...maskAreaCoords, maskAreaCoords[0]];
    
    // Convert to WGS84
    const wgs84Coords = closedCoords.map(coord => toLonLat(coord, PROJECTION_CODE));
    
    // Create new mask
    const maskId = 'mask_' + Date.now();
    const maskColor = MASK_COLORS[nextMaskColorIndex % MASK_COLORS.length];
    nextMaskColorIndex += 1;
    
    const newMask = {
      id: maskId,
      name: `Alue ${activeMasks.length + 1}`,
      coordinates: wgs84Coords,
      projectedCoords: closedCoords,
      color: maskColor,
      visible: true,
      createdAt: new Date().toISOString()
    };
    
    activeMasks.push(newMask);
    addMaskToVisualization(newMask);
    updateMasksList();

    console.log('Mask created:', newMask.name, newMask.id);
    setStatus(`Alue tallennettu: ${maskAreaCoords.length} pistettä`, false);
    clearMaskAreaDrawing();
    hideStatusAfterDelay(2000);
  }

  function removeMaskAreaPointAtPixel(pixel) {
    let removed = false;
    map.forEachFeatureAtPixel(
      pixel,
      feature => {
        if (feature && feature !== currentMaskAreaFeature && maskAreaPointsSource.hasFeature(feature)) {
          maskAreaPointsSource.removeFeature(feature);
          const index = maskAreaCoords.findIndex(coord => 
            coord[0] === feature.getGeometry().getCoordinates()[0] &&
            coord[1] === feature.getGeometry().getCoordinates()[1]
          );
          if (index > -1) {
            maskAreaCoords.splice(index, 1);
          }
          removed = true;
          return true;
        }
      },
      {layerFilter: layer => layer === maskAreaLayer}
    );

    if (removed && maskAreaCoords.length > 1) {
      currentMaskAreaFeature.getGeometry().setCoordinates(maskAreaCoords);
    } else if (removed && maskAreaCoords.length <= 1) {
      currentMaskAreaFeature = null;
    }
  }

  if (ENABLE_MASK_EDIT_UI) {
    // Add click listener for mask area points
    map.on('click', event => {
      if (!maskAreaDrawingEnabled) {
        return;
      }
      addMaskAreaPoint(event.coordinate);
    });

    // Right-click context menu
    map.getViewport().addEventListener('contextmenu', event => {
      if (!maskAreaDrawingEnabled) {
        return;
      }
      event.preventDefault();
      const pixel = map.getEventPixel(event);

      // Check if clicking on existing point to delete
      let foundPoint = false;
      map.forEachFeatureAtPixel(
        pixel,
        feature => {
          if (feature && feature !== currentMaskAreaFeature) {
            foundPoint = true;
          }
        },
        {layerFilter: layer => layer === maskAreaLayer}
      );

      if (foundPoint) {
        removeMaskAreaPointAtPixel(pixel);
      } else {
        // Right-click on empty space finishes the area
        finishMaskArea();
      }
    });

    // UI controls
    maskModeToggleEl?.addEventListener('click', () => {
      maskEditMode = !maskEditMode;
      updateMaskModeUi();
      updateMasksList();
    });

    maskAreaToggleEl?.addEventListener('click', () => {
      maskAreaDrawingEnabled = !maskAreaDrawingEnabled;
      maskAreaToggleEl.classList.toggle('active', maskAreaDrawingEnabled);
      maskAreaToggleEl.textContent = maskAreaDrawingEnabled
        ? 'Piirto: päällä (ESC)'
        : 'Aloita piirto';
      if (!maskAreaDrawingEnabled) {
        clearMaskAreaDrawing();
      }
    });

    // Save button
    const maskAreaSaveEl = document.getElementById('maskAreaSave');
    maskAreaSaveEl?.addEventListener('click', () => {
      if (activeMasks.length === 0) {
        setStatus('Ei alueita tallennettavaksi', true);
        hideStatusAfterDelay(1500);
        return;
      }

      const dataStr = JSON.stringify({
        version: '1.0',
        exportedAt: new Date().toISOString(),
        projection: 'EPSG:4326',
        masks: activeMasks.map(m => ({
          name: m.name,
          coordinates: m.coordinates,
          color: m.color,
          createdAt: m.createdAt
        }))
      }, null, 2);

      const blob = new Blob([dataStr], {type: 'application/json'});
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `masks-${new Date().toISOString().split('T')[0]}.json`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      setStatus(`${activeMasks.length} alue(tta) tallennettu tiedostoon`, false);
      hideStatusAfterDelay(2000);
    });

    // ESC key to cancel
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && maskAreaDrawingEnabled) {
        maskAreaDrawingEnabled = false;
        maskAreaToggleEl.classList.toggle('active', false);
        maskAreaToggleEl.textContent = 'Aloita piirto';
        clearMaskAreaDrawing();
        setStatus('Alueiden piirto peruutettu', false);
        hideStatusAfterDelay(1500);
      }
    });
  }
  
  // Seed initial masks from disk and render immediately.
  loadMasksFromData(initialMaskData);
  updateMaskModeUi();
  updateMasksList();
}

function pickBestLayer(layerNames, provider) {
  const preferredExactNames = provider.detailPreferredExact || [];

  for (const exactName of preferredExactNames) {
    if (layerNames.includes(exactName)) {
      return exactName;
    }
  }

  const preferredPatterns = provider.detailPreferredPatterns || [];

  for (const pattern of preferredPatterns) {
    const match = layerNames.find(name => pattern.test(name));
    if (match) {
      return match;
    }
  }

  return layerNames[0];
}

function pickPreferredLayer(layerNames, exactNames = [], patterns = [], excludedNames = []) {
  for (const exactName of exactNames) {
    if (!excludedNames.includes(exactName) && layerNames.includes(exactName)) {
      return exactName;
    }
  }

  for (const pattern of patterns) {
    const match = layerNames.find(name => !excludedNames.includes(name) && pattern.test(name));
    if (match) {
      return match;
    }
  }

  return null;
}

function getBrowserPosition() {
  return new Promise(resolve => {
    if (!navigator.geolocation) {
      resolve({lon: FALLBACK_CENTER[0], lat: FALLBACK_CENTER[1], fromBrowser: false});
      return;
    }

    navigator.geolocation.getCurrentPosition(
      pos => resolve({
        lon: pos.coords.longitude,
        lat: pos.coords.latitude,
        fromBrowser: true
      }),
      () => resolve({lon: FALLBACK_CENTER[0], lat: FALLBACK_CENTER[1], fromBrowser: false}),
      {enableHighAccuracy: false, timeout: 8000, maximumAge: 300000}
    );
  });
}

function createMap(centerLonLat, zoom) {
  scaleLineControl = new ScaleLine({units: 'nautical'});
  map = new Map({
    target: 'map',
    layers: [],
    controls: defaultControls().extend([scaleLineControl]),
    view: new View({
      projection: PROJECTION_CODE,
      center: fromLonLat(centerLonLat, PROJECTION_CODE),
      zoom,
      maxZoom: 18
    })
  });
  map.getViewport().style.backgroundColor = '#ffffff';

  map.on('dblclick', event => {
    if (!trackEditModeEnabled || typeof trackEditAddPointHandler !== 'function') {
      return;
    }

    if (routePlanningEnabled) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    const lonLat = toLonLat(event.coordinate, PROJECTION_CODE);
    if (!lonLat || lonLat.length < 2) {
      return;
    }

    trackEditAddPointHandler(lonLat[0], lonLat[1]);
  });

  map.getViewport().addEventListener('contextmenu', event => {
    if (!trackEditModeEnabled || typeof trackEditRemovePointHandler !== 'function') {
      return;
    }

    if (routePlanningEnabled) {
      return;
    }

    event.preventDefault();
    const pixel = map.getEventPixel(event);
    const coordinate = map.getCoordinateFromPixel(pixel);
    const lonLat = toLonLat(coordinate, PROJECTION_CODE);
    if (!lonLat || lonLat.length < 2) {
      return;
    }

    trackEditRemovePointHandler(lonLat[0], lonLat[1]);
  });
}

function createBalticBackgroundLayers() {
  const topoBase = new TileLayer({
    source: new XYZ({
      url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
      crossOrigin: 'anonymous',
      maxZoom: 19
    }),
    zIndex: -30,
    opacity: 1,
    visible: false
  });

  const countryLayers = COUNTRY_PROVIDERS.map(provider => {
    if (provider.type === 'wms') {
      const wmsSource = new TileWMS({
        url: provider.wmsUrl,
        params: provider.wmsParams,
        crossOrigin: 'anonymous'
      });

      if (provider.id === 'sweden') {
        return new TileLayer({
          source: wmsSource,
          zIndex: -25,
          visible: false,
          opacity: 1
        });
      }

      return new TileLayer({
        source: wmsSource,
        zIndex: -25,
        visible: false,
        opacity: 1
      });
    }

    return null;
  }).filter(Boolean);

  return {
    topoBase,
    countryLayers
  };
}

function isInsideArea(lon, lat, bboxLonLat) {
  const [minLon, minLat, maxLon, maxLat] = bboxLonLat;
  return lon >= minLon && lon <= maxLon && lat >= minLat && lat <= maxLat;
}

function setupAdaptiveBackgrounds(background, onCountryProviderChange) {
  const {topoBase, countryLayers} = background;

  const updateBackgrounds = () => {
    const center = map.getView().getCenter();
    if (!center) {
      return;
    }

    const [lon, lat] = toLonLat(center, PROJECTION_CODE);
    const zoom = map.getView().getZoom() ?? 0;
    const showBaseOnly = zoom < TRAFICOM_MIN_ZOOM;
    const showCountryFallback = zoom >= TRAFICOM_MIN_ZOOM;
    const activeProvider = COUNTRY_PROVIDERS.find(provider => isInsideArea(lon, lat, provider.bboxLonLat)) || null;

    topoBase.setVisible(KEEP_BASEMAP_VISIBLE_DURING_OUTAGE || showBaseOnly);
    topoBase.setOpacity(showBaseOnly ? 1 : 0.95);
    
    // Only show country layers if user is within their geographic bounds.
    // This prevents unnecessary tile requests from outside providers (e.g., Sweden WMS when viewing Finland).
    countryLayers.forEach((layer, index) => {
      if (index < COUNTRY_PROVIDERS.length) {
        const provider = COUNTRY_PROVIDERS[index];
        const userInBounds = isInsideArea(lon, lat, provider.bboxLonLat);
        layer.setVisible(showCountryFallback && userInBounds);
      }
    });

    if (typeof onCountryProviderChange === 'function') {
      onCountryProviderChange(activeProvider ? activeProvider.id : null, zoom);
    }

    if (!isTraficomCircuitOpen()) {
      traficomFailureCount = 0;
    }
  };

  const zoomEl = document.getElementById('zoomIndicator');
  function updateZoomIndicator() {
    if (zoomEl) {
      const z = map.getView().getZoom();
      zoomEl.textContent = z != null ? `z ${z.toFixed(2)}` : 'z –';
    }
  }

  updateBackgrounds();
  updateZoomIndicator();
  map.on('moveend', () => {
    updateBackgrounds();
    updateZoomIndicator();
  });
}

function attachTileErrorFallback(layer, background) {
  if (!layer || !background || !background.topoBase) {
    return;
  }

  const source = layer.getSource && layer.getSource();
  if (!source || typeof source.on !== 'function') {
    return;
  }

  source.on('tileloaderror', () => {
    traficomFailureCount += 1;
    background.topoBase.setVisible(true);
    setStatus('Traficom-palvelu häiriössä, näytetään varakartta.');

    if (traficomFailureCount >= TRAFICOM_FAILURE_THRESHOLD && !isTraficomCircuitOpen()) {
      openTraficomCircuit(background);
    }
  });
}

async function registerServiceWorker() {
  if (location.protocol !== 'http:' && location.protocol !== 'https:') {
    return;
  }

  if (!('serviceWorker' in navigator)) {
    return;
  }

  try {
    await navigator.serviceWorker.register(`${APP_BASE_PATH}sw.js`);
  } catch (err) {
    console.warn('Service Worker -rekisterointi epäonnistui', err);
  }
}

function getCapabilitiesCacheKey(provider) {
  return `${provider.id}-wmts-capabilities-v1`;
}

function readCapabilitiesFromCache(provider, options = {}) {
  const ignoreTtl = Boolean(options && options.ignoreTtl);

  try {
    const raw = localStorage.getItem(getCapabilitiesCacheKey(provider));
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw);
    if (!parsed || !parsed.xmlText || !parsed.savedAt) {
      return null;
    }

    if (ignoreTtl) {
      return parsed.xmlText;
    }

    const isFresh = (Date.now() - Number(parsed.savedAt)) < CAPABILITIES_TTL_MS;
    return isFresh ? parsed.xmlText : null;
  } catch {
    return null;
  }
}

function saveCapabilitiesToCache(provider, xmlText) {
  try {
    localStorage.setItem(getCapabilitiesCacheKey(provider), JSON.stringify({
      xmlText,
      savedAt: Date.now()
    }));
  } catch {
    // ignore storage errors
  }
}

async function loadCapabilities(provider) {
  let xmlText = readCapabilitiesFromCache(provider);
  let fromCache = Boolean(xmlText);

  if (!xmlText) {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 8000);
      const res = await fetch(provider.capabilitiesUrl, {credentials: 'omit', signal: controller.signal});
      clearTimeout(timeoutId);
      if (!res.ok) {
        throw new Error(`Capabilities-haku epäonnistui: HTTP ${res.status}`);
      }
      xmlText = await res.text();
      saveCapabilitiesToCache(provider, xmlText);
      fromCache = false;
    } catch (err) {
      const staleXml = readCapabilitiesFromCache(provider, {ignoreTtl: true});
      if (staleXml) {
        xmlText = staleXml;
        fromCache = true;
      } else {
        throw err;
      }
    }
  }

  const parser = new WMTSCapabilities();
  const capabilities = parser.read(xmlText);
  const layerNames = (capabilities?.Contents?.Layer || []).map(layer => layer.Identifier).filter(Boolean);

  if (!layerNames.length) {
    throw new Error('Capabilities-vastauksesta ei löytynyt karttatasoja.');
  }

  const detailLayer = pickBestLayer(layerNames, provider);
  const extraDetailLayer = pickPreferredLayer(
    layerNames,
    provider.coastalPreferredExact,
    provider.coastalPreferredPatterns,
    [detailLayer]
  );
  const overviewLayer = pickPreferredLayer(
    layerNames,
    provider.overviewPreferredExact,
    provider.overviewPreferredPatterns,
    [detailLayer, extraDetailLayer].filter(Boolean)
  );
  const wideOverviewLayer = pickPreferredLayer(
    layerNames,
    (provider.overviewPreferredExact || []).slice(1),
    provider.overviewPreferredPatterns,
    [detailLayer, extraDetailLayer, overviewLayer].filter(Boolean)
  );

  return {
    capabilities,
    detailLayer,
    extraDetailLayer,
    overviewLayer,
    wideOverviewLayer,
    fromCache
  };
}

function createWmtsLayer(capabilities, layerName, zIndex, provider) {
  const projection = getProjection(PROJECTION_CODE);
  if (!projection) {
    throw new Error(`Tuntematon projektio: ${PROJECTION_CODE}`);
  }

  const options = optionsFromCapabilities(capabilities, {
    layer: layerName,
    matrixSet: provider.matrixSet,
    projection
  });

  if (!options) {
    throw new Error(`WMTS-optioita ei saatu tasolle ${layerName}.`);
  }

  options.crossOrigin = 'anonymous';
  options.wrapX = false;
  const source = new WMTS(options);

  const originalUrlFn = source.getTileUrlFunction();
  const sanitizedName = sanitizeLayerName(layerName);
  source.setTileUrlFunction(function(tileCoord, pixelRatio, projection) {
    const url = originalUrlFn(tileCoord, pixelRatio, projection);
    if (!url) return url;
    // Parse WMTS params from the original URL to build path-based tile URL
    try {
      const parsed = new URL(url);
      const z = (parsed.searchParams.get('TILEMATRIX') || parsed.searchParams.get('TileMatrix') || '').split(':').pop();
      const col = parsed.searchParams.get('TILECOL') || parsed.searchParams.get('TileCol');
      const row = parsed.searchParams.get('TILEROW') || parsed.searchParams.get('TileRow');
      if (z && col && row) {
        return `${TILE_PATH_BASE}${sanitizedName}/${z}/${col}/${row}.png`;
      }
    } catch {}
    // Fallback to query-param mode if URL parsing fails
    const debugParam = ENABLE_TILE_PROXY_DEBUG ? '&debug=1' : '';
    return TILE_PROXY_URL + '?url=' + encodeURIComponent(url) + debugParam;
  });

  return new TileLayer({
    source,
    zIndex
  });
}

function createTraficomProxyXyzLayer(layerName, zIndex) {
  const sanitizedName = sanitizeLayerName(layerName);
  const source = new XYZ({
    crossOrigin: 'anonymous',
    maxZoom: 18,
    wrapX: false,
    tileUrlFunction(tileCoord) {
      if (!tileCoord || tileCoord.length < 3) {
        return undefined;
      }

      const z = tileCoord[0];
      const x = tileCoord[1];
      // OpenLayers XYZ source uses negative-Y convention: tileCoord[2] = -row - 1
      const y = tileCoord[2] < 0 ? -tileCoord[2] - 1 : tileCoord[2];
      if (x < 0 || z < 0) {
        return undefined;
      }

      return `${TILE_PATH_BASE}${sanitizedName}/${z}/${x}/${y}.png`;
    }
  });

  return new TileLayer({
    source,
    zIndex
  });
}

// Point-in-polygon test (ray casting algorithm)
function isPointInPolygon(point, polygon) {
  const [x, y] = point;
  let inside = false;
  
  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
    const [xi, yi] = polygon[i];
    const [xj, yj] = polygon[j];
    
    const intersect = ((yi > y) !== (yj > y))
      && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
    if (intersect) inside = !inside;
  }
  
  return inside;
}

function setUnmaskedTileLoad(source) {
  if (!source || typeof source.setTileLoadFunction !== 'function') {
    return;
  }
  source.setTileLoadFunction((tile, src) => {
    tile.getImage().src = src;
  });
}

function applyCurrentMasksToTraficom() {
  const layers = [];
  if (traficomWideOverviewLayer) {
    layers.push(traficomWideOverviewLayer);
  }
  if (traficomDetailLayer) {
    layers.push(traficomDetailLayer);
  }
  if (traficomExtraDetailLayer) {
    layers.push(traficomExtraDetailLayer);
  }
  if (!layers.length) {
    return;
  }

  if (!ENABLE_TRAFFICOM_MASKS) {
    layers.forEach(layer => {
      const source = layer.getSource();
      setUnmaskedTileLoad(source);
    });
    return;
  }

  const visibleMasks = activeMasks.filter(mask => mask.visible !== false);
  if (!visibleMasks.length) {
    layers.forEach(layer => {
      const source = layer.getSource();
      setUnmaskedTileLoad(source);
    });
    return;
  }

  const maskCoordinates = visibleMasks.map(mask => mask.coordinates);
  applyPolygonMask(layers, maskCoordinates);
}

// Apply polygon mask to Traficom layers
function applyPolygonMask(layers, wgs84PolygonData) {
  if (!wgs84PolygonData || wgs84PolygonData.length === 0) {
    console.warn('No polygon data to apply mask');
    return;
  }

  // Use the first polygon or combine all if multiple
  const polygons = Array.isArray(wgs84PolygonData[0]) 
    ? wgs84PolygonData 
    : [wgs84PolygonData];

  // Convert polygons to map projection for point-in-polygon checks
  const projectedPolygons = polygons.map(poly =>
    poly.map(coord => fromLonLat(coord, PROJECTION_CODE))
  );

  const polygonBounds = projectedPolygons.map(poly => {
    let minX = Number.POSITIVE_INFINITY;
    let minY = Number.POSITIVE_INFINITY;
    let maxX = Number.NEGATIVE_INFINITY;
    let maxY = Number.NEGATIVE_INFINITY;
    poly.forEach(([x, y]) => {
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x);
      maxY = Math.max(maxY, y);
    });
    return [minX, minY, maxX, maxY];
  });

  const isInsideAnyPolygon = point => {
    for (const poly of projectedPolygons) {
      if (isPointInPolygon(point, poly)) {
        return true;
      }
    }
    return false;
  };

  const extentsIntersect = (a, b) => (
    a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1]
  );

  const EPSILON = 1e-9;

  const orientation = (ax, ay, bx, by, cx, cy) => {
    const value = (by - ay) * (cx - bx) - (bx - ax) * (cy - by);
    if (Math.abs(value) < EPSILON) {
      return 0;
    }
    return value > 0 ? 1 : 2;
  };

  const onSegment = (ax, ay, bx, by, cx, cy) => (
    bx <= Math.max(ax, cx) + EPSILON &&
    bx + EPSILON >= Math.min(ax, cx) &&
    by <= Math.max(ay, cy) + EPSILON &&
    by + EPSILON >= Math.min(ay, cy)
  );

  const segmentsIntersect = (p1, q1, p2, q2) => {
    const o1 = orientation(p1[0], p1[1], q1[0], q1[1], p2[0], p2[1]);
    const o2 = orientation(p1[0], p1[1], q1[0], q1[1], q2[0], q2[1]);
    const o3 = orientation(p2[0], p2[1], q2[0], q2[1], p1[0], p1[1]);
    const o4 = orientation(p2[0], p2[1], q2[0], q2[1], q1[0], q1[1]);

    if (o1 !== o2 && o3 !== o4) {
      return true;
    }

    if (o1 === 0 && onSegment(p1[0], p1[1], p2[0], p2[1], q1[0], q1[1])) return true;
    if (o2 === 0 && onSegment(p1[0], p1[1], q2[0], q2[1], q1[0], q1[1])) return true;
    if (o3 === 0 && onSegment(p2[0], p2[1], p1[0], p1[1], q2[0], q2[1])) return true;
    if (o4 === 0 && onSegment(p2[0], p2[1], q1[0], q1[1], q2[0], q2[1])) return true;

    return false;
  };

  const doesPolygonIntersectTileBoundary = (polygon, tileExtent) => {
    const [minX, minY, maxX, maxY] = tileExtent;
    const tileEdges = [
      [[minX, minY], [maxX, minY]],
      [[maxX, minY], [maxX, maxY]],
      [[maxX, maxY], [minX, maxY]],
      [[minX, maxY], [minX, minY]]
    ];

    for (let i = 0; i < polygon.length; i += 1) {
      const a = polygon[i];
      const b = polygon[(i + 1) % polygon.length];
      for (const [e0, e1] of tileEdges) {
        if (segmentsIntersect(a, b, e0, e1)) {
          return true;
        }
      }
    }

    return false;
  };

  const classifyTileAgainstMasks = tileExtent => {
    const center = [
      (tileExtent[0] + tileExtent[2]) / 2,
      (tileExtent[1] + tileExtent[3]) / 2
    ];

    let fullyInsideAnyMask = false;

    for (let i = 0; i < projectedPolygons.length; i += 1) {
      const polygon = projectedPolygons[i];
      const bounds = polygonBounds[i];
      if (!extentsIntersect(tileExtent, bounds)) {
        continue;
      }

      if (doesPolygonIntersectTileBoundary(polygon, tileExtent)) {
        return 'boundary';
      }

      if (isPointInPolygon(center, polygon)) {
        fullyInsideAnyMask = true;
      }
    }

    return fullyInsideAnyMask ? 'inside' : 'outside';
  };

  // Apply to layers
  layers.forEach(layer => {
    if (!layer || typeof layer.getSource !== 'function') {
      return;
    }

    // Add tile load function for finer filtering
    const source = layer.getSource();
    if (source && typeof source.setTileLoadFunction === 'function') {
      const tileGrid =
        (typeof source.getTileGrid === 'function' && source.getTileGrid()) ||
        (typeof source.getTileGridForProjection === 'function' && source.getTileGridForProjection(getProjection(PROJECTION_CODE)));

      source.setTileLoadFunction((tile, src) => {
        const image = tile.getImage();
        
        // Get tile coordinate and convert to projected center point
        try {
          const tileCoord = tile.getTileCoord();
          if (tileCoord && tileCoord.length >= 3 && tileGrid) {
            const tileExtent = tileGrid.getTileCoordExtent(tileCoord);

            const maybeIntersectsMask = polygonBounds.some(bounds => extentsIntersect(tileExtent, bounds));
            if (!maybeIntersectsMask) {
              image.src = src;
              return;
            }

            const tileClass = classifyTileAgainstMasks(tileExtent);

            // Hide tiles fully inside mask polygons (inverted logic)
            if (tileClass === 'inside') {
              // Tile is inside mask area, make transparent
              image.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
              return;
            }

            // Fast path for clearly outside tiles.
            if (tileClass === 'outside') {
              image.src = src;
              return;
            }

            // Boundary tile: apply pixel-accurate masking so polygon edges are correct.
            fetch(src)
              .then(response => {
                if (!response.ok) {
                  throw new Error(`Tile fetch failed: HTTP ${response.status}`);
                }
                return response.blob();
              })
              .then(blob => createImageBitmap(blob))
              .then(bitmap => {
                const canvas = document.createElement('canvas');
                canvas.width = bitmap.width;
                canvas.height = bitmap.height;
                const ctx = canvas.getContext('2d', {willReadFrequently: true});
                if (!ctx) {
                  image.src = src;
                  return;
                }

                ctx.drawImage(bitmap, 0, 0);

                let imageData;
                try {
                  imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                } catch {
                  image.src = src;
                  return;
                }

                const data = imageData.data;
                const width = canvas.width;
                const height = canvas.height;
                const spanX = tileExtent[2] - tileExtent[0];
                const spanY = tileExtent[3] - tileExtent[1];
                let changed = false;

                for (let y = 0; y < height; y += 1) {
                  const mapY = tileExtent[3] - ((y + 0.5) / height) * spanY;
                  for (let x = 0; x < width; x += 1) {
                    const mapX = tileExtent[0] + ((x + 0.5) / width) * spanX;
                    if (isInsideAnyPolygon([mapX, mapY])) {
                      const offset = (y * width + x) * 4;
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
              .catch(() => {
                image.src = src;
              });
            return;
          }
        } catch (e) {
          console.debug('Tile filtering error:', e);
        }
        
        image.src = src;
      });
    }
  });

}



async function start() {
  try {
    if (!legacyMode) {
      registerServiceWorker();
    }

    const markNavionicsReady = setupLegacyNavionicsBridge();

    if (typeof window.Show === 'function') {
      window.Show('c-loading');
    }

    setStatus('Ladataan karttaa...');
    createMap(FALLBACK_CENTER, FALLBACK_ZOOM);

    const initialMaskData = await loadMasksFromDisk();

    const background = createBalticBackgroundLayers();
    map.addLayer(background.topoBase);
    background.countryLayers.forEach(layer => map.addLayer(layer));
    setupRoutePlanning();
    setupMaskAreaDrawing(initialMaskData);

    setStatus('Ladataan merikarttaa...');
    const provider = PROVIDERS.traficom;
    let capabilities = null;
    let detailLayer = 'Traficom:Merikarttasarjat public';
    let extraDetailLayer = null;
    let wideOverviewLayer = null;
    let fromCache = false;
    let useProxyXyzFallback = FORCE_PROXY_XYZ_FALLBACK;

    if (!useProxyXyzFallback) {
      try {
        const loaded = await loadCapabilities(provider);
        capabilities = loaded.capabilities;
        detailLayer = loaded.detailLayer;
        extraDetailLayer = loaded.extraDetailLayer;
        wideOverviewLayer = loaded.wideOverviewLayer;
        fromCache = loaded.fromCache;

        const traficomExtent = transformExtent(
          provider.approxCoverageBboxLonLat,
          'EPSG:4326',
          PROJECTION_CODE
        );
        if (wideOverviewLayer) {
          traficomWideOverviewLayer = createWmtsLayer(capabilities, wideOverviewLayer, 0, provider);
          traficomWideOverviewLayer.setExtent(traficomExtent);
          traficomWideOverviewLayer.setVisible(false);
          map.addLayer(traficomWideOverviewLayer);
        }
      } catch {
        useProxyXyzFallback = true;
        setStatus('Capabilities-haku epäonnistui, käytetään fallback-tilaa.');
      }
    }

    function ensureTraficomDetailLayer() {
      if (traficomDetailLayer) {
        return traficomDetailLayer;
      }

      traficomDetailLayer = useProxyXyzFallback
        ? createTraficomProxyXyzLayer(detailLayer, 10)
        : createWmtsLayer(capabilities, detailLayer, 10, provider);
      traficomDetailLayer.setVisible(false);
      map.addLayer(traficomDetailLayer);
      attachTileErrorFallback(traficomDetailLayer, background);
      applyCurrentMasksToTraficom();
      return traficomDetailLayer;
    }

    function ensureTraficomExtraDetailLayer() {
      if (!extraDetailLayer) {
        return null;
      }

      if (traficomExtraDetailLayer) {
        return traficomExtraDetailLayer;
      }

      traficomExtraDetailLayer = useProxyXyzFallback
        ? createTraficomProxyXyzLayer(extraDetailLayer, 20)
        : createWmtsLayer(capabilities, extraDetailLayer, 20, provider);
      traficomExtraDetailLayer.setVisible(false);
      map.addLayer(traficomExtraDetailLayer);
      attachTileErrorFallback(traficomExtraDetailLayer, background);
      applyCurrentMasksToTraficom();
      return traficomExtraDetailLayer;
    }

    const adaptTraficomLayersForCountry = (countryId, zoom) => {
      const currentZoom = zoom ?? 0;
      if (isTraficomCircuitOpen()) {
        if (traficomWideOverviewLayer) {
          traficomWideOverviewLayer.setVisible(false);
        }
        if (traficomDetailLayer) {
          traficomDetailLayer.setVisible(false);
        }
        if (traficomExtraDetailLayer) {
          traficomExtraDetailLayer.setVisible(false);
        }
        return;
      }

      const hasWideOverviewLayer = Boolean(traficomWideOverviewLayer);
      const showOverview = hasWideOverviewLayer && currentZoom >= TRAFICOM_MIN_ZOOM;
      const showDetail = hasWideOverviewLayer ? currentZoom >= TRAFICOM_DETAIL_MIN_ZOOM : currentZoom >= TRAFICOM_MIN_ZOOM;
      const showExtraDetail = Boolean(extraDetailLayer) && currentZoom >= TRAFICOM_EXTRA_DETAIL_MIN_ZOOM;

      if (traficomWideOverviewLayer) {
        traficomWideOverviewLayer.setVisible(showOverview);
      }

      if (showDetail) {
        const detailChartLayer = ensureTraficomDetailLayer();
        detailChartLayer.setVisible(true);
      } else if (traficomDetailLayer) {
        traficomDetailLayer.setVisible(false);
      }

      if (showExtraDetail) {
        const extraChartLayer = ensureTraficomExtraDetailLayer();
        if (extraChartLayer) {
          extraChartLayer.setVisible(true);
        }
      } else if (traficomExtraDetailLayer) {
        traficomExtraDetailLayer.setVisible(false);
      }
    };

    setupAdaptiveBackgrounds(background, adaptTraficomLayersForCountry);

    // Apply initial masks if any exist
    applyCurrentMasksToTraficom();

    const sourceInfo = 'oletussijainnista';
    const cacheInfo = useProxyXyzFallback ? 'proxy xyz fallback' : (fromCache ? 'capabilities cache' : 'capabilities verkosta');
    const [lon, lat] = toLonLat(map.getView().getCenter(), PROJECTION_CODE);
    const activeCountry = COUNTRY_PROVIDERS.find(provider => isInsideArea(lon, lat, provider.bboxLonLat));
    const areaInfo = activeCountry ? `alueprovider ${activeCountry.label}` : 'alueprovider globaali';
    const extraLayerInfo = extraDetailLayer
      ? ` -> ${extraDetailLayer} @ z${TRAFICOM_EXTRA_DETAIL_MIN_ZOOM}+`
      : '';
    const layerInfo = `${wideOverviewLayer || 'ei yleiskarttaa'} @ z${TRAFICOM_MIN_ZOOM}+ -> ${detailLayer} @ z${TRAFICOM_DETAIL_MIN_ZOOM}+${extraLayerInfo}`;
    setStatus(`Kartta valmis (${sourceInfo}, ${layerInfo}, ${areaInfo}, ${cacheInfo})`);
    hideStatusAfterDelay();

    markNavionicsReady();

    if (typeof window.refreshApiData === 'function') {
      window.refreshApiData();
    }

    if (typeof window.Hide === 'function') {
      window.Hide('c-loading');
    }
  } catch (err) {
    console.error(err);
    setStatus((err && err.message) ? err.message : String(err), true);
    if (typeof window.Hide === 'function') {
      window.Hide('c-loading');
    }
  }
}

start();
})().catch(err => {
  console.error(err);
  const statusEl = document.getElementById('status');
  if (statusEl) {
    statusEl.textContent = (err && err.message) ? err.message : String(err);
    statusEl.classList.remove('hidden');
    statusEl.classList.add('error');
  }
});