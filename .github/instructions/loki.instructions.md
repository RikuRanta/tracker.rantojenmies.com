---
description: "Use when editing legacy Loki frontend files (PHP shell + jQuery/OpenLayers JS)"
applyTo: "loki/{index.php,config.php,php/**,js/**,styles/**,img/**,merikortti/**}"
---

# Loki frontend instructions (legacy UI)

## Scope
- These instructions apply to the legacy Loki UI under `loki/`.

## Architecture and runtime
- Keep Loki as a plain PHP + static JS/CSS app with no bundler or framework migration.
- Keep script loading order stable in `loki/index.php` because globals are order-dependent.
  - Head scripts (in order): `jquery.js`, `jquery-dateFormat.min.js`, `menu.js`, `onReady.js`, `cors.js`
  - Body scripts (in order): inline JS globals, `initMenu.js`, `map.js`, `initMap.js`, `merikortti/masks-data.js`, inline `callback`/`url`/`headers` block, `merikortti/app.js` (module)
- Preserve global browser variables and function names used across files (for example `callback`, `url`, `headers`, `corsResponse`, `initLog`, `initPlaces`, `initEvents`).

## Map library — OpenLayers 10.6.1 (replacing Navionics)
- The map is **OpenLayers 10.6.1**, dynamically imported from `esm.sh` inside `loki/js/map.js`.
- **`loki/js/map.js`** is the key map file. It contains:
  - The OpenLayers map init IIFE
  - WMTS Traficom layer integration (Finnish nautical charts from `julkinen.traficom.fi/rasteripalvelu/wmts`)
  - Polygon mask logic (hides Traficom tiles inside mask polygons using canvas pixel manipulation)
  - WMTS capabilities caching in `localStorage` with 30-minute TTL
  - **`window.navionics` compatibility shim** exposing: `onMapReady`, `setSafeCenter`, `setZoom`, `hideBalloons`, `removeLayer`, `loadKml`
- `initMap.js` uses `navionics.*` calls through this shim — do not break the shim interface.
- The Navionics WebAPI (`JNC.Views.Map`) is **no longer used**.
- `crossOrigin: 'anonymous'` is set on WMTS sources; boundary tiles use `fetch()` for pixel masking which triggers CORS errors — a server-side tile proxy solves this.

## Masks
- `loki/merikortti/masks-data.js` exposes `window.__MAP_MASKS_DATA__` with polygon mask coordinate arrays (WGS84 lon/lat).
- `loki/merikortti/app.js` is loaded as `<script type="module">` and handles the Merikortti sub-app.

## API contract
- Keep request flow based on `corsRequest()` from `loki/js/cors.js`.
- Keep auth headers in frontend requests exactly as:
  - `X-Api-Token` — HMAC-signed token generated server-side in `create_tracker_api_token()` in `loki/index.php`
  - `X-Api-Imei`
- Do not switch Loki calls to different auth header names unless backend changes require it everywhere.
- Handle GET and POST JSON response parsing carefully; preserve current behavior where parsed data is read from shared globals.

## UI behavior
- Preserve menu behavior driven by `loki/js/menu.js` and `loki/js/initMenu.js` (`slide-left` and `slide-bottom` menus).
- When changing live tracking flow, preserve timers/interval cleanup to avoid duplicate refresh loops.

## PHP and output conventions
- Keep manual includes (`require_once`) and simple config loading from `loki/config.php`.
- Keep environment-variable fallback style for vessel/site settings.
- Preserve UTF-8 output handling pattern in `loki/index.php` unless a full encoding fix is done consistently across page output and API payloads.

## Editing guidelines
- Prefer minimal, surgical changes; avoid broad rewrites of legacy style.
- Match existing JS style (classic functions, global state, jQuery-first DOM updates) unless task explicitly asks for modernization.
- If adding new UI actions, wire them through existing menu/action patterns and ensure both desktop and mobile menu behavior still works.