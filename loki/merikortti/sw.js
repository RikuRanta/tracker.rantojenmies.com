const TILE_CACHE_NAME = 'traficom-wmts-tiles-v2';
const MAX_CACHE_ITEMS = 400;

self.addEventListener('install', event => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

function isWmtsTileRequest(request) {
  const url = new URL(request.url);
  if (!url.hostname.includes('traficom.fi')) {
    return false;
  }
  if (!url.pathname.includes('/rasteripalvelu/wmts')) {
    return false;
  }
  return url.searchParams.has('TILEMATRIX') || url.searchParams.has('tilematrix');
}

function parseTileZoom(url) {
  const matrix = url.searchParams.get('TILEMATRIX') || url.searchParams.get('tilematrix') || '';
  const match = String(matrix).match(/(\d+)$/);
  return match ? Number(match[1]) : NaN;
}

function shouldProcessTile(url) {
  const layer = (url.searchParams.get('LAYER') || url.searchParams.get('layer') || '').toLowerCase();
  const zoom = parseTileZoom(url);
  // Apply only to Traficom overview-style layers at zoom band where paper background appears.
  return Number.isFinite(zoom) && zoom >= 9 && zoom <= 13 && layer.includes('yleiskartat');
}

function isNoDataLike(data, offset) {
  const r = data[offset];
  const g = data[offset + 1];
  const b = data[offset + 2];
  const a = data[offset + 3];
  if (a < 240) {
    return false;
  }
  const maxRgb = Math.max(r, g, b);
  const minRgb = Math.min(r, g, b);
  return maxRgb - minRgb <= 12 && r >= 180 && g >= 180 && b >= 180;
}

async function maskTileNoDataToTransparent(response) {
  const contentType = response.headers.get('content-type') || '';
  if (!contentType.toLowerCase().includes('image/png')) {
    return response;
  }

  if (typeof OffscreenCanvas === 'undefined' || typeof createImageBitmap === 'undefined') {
    return response;
  }

  try {
    const blob = await response.blob();
    const bitmap = await createImageBitmap(blob);
    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
    const ctx = canvas.getContext('2d', {willReadFrequently: true});
    if (!ctx) {
      return response;
    }

    ctx.drawImage(bitmap, 0, 0);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    const width = canvas.width;
    const height = canvas.height;
    const visited = new Uint8Array(width * height);
    const queue = [];
    let head = 0;

    const pushIfMaskable = (x, y) => {
      const idx = y * width + x;
      if (visited[idx]) {
        return;
      }
      const offset = idx * 4;
      if (!isNoDataLike(data, offset)) {
        return;
      }
      visited[idx] = 1;
      queue.push([x, y]);
    };

    for (let x = 0; x < width; x += 1) {
      pushIfMaskable(x, 0);
      pushIfMaskable(x, height - 1);
    }
    for (let y = 0; y < height; y += 1) {
      pushIfMaskable(0, y);
      pushIfMaskable(width - 1, y);
    }

    let changed = false;
    while (head < queue.length) {
      const [x, y] = queue[head];
      head += 1;

      const idx = y * width + x;
      const offset = idx * 4;
      data[offset + 3] = 0;
      changed = true;

      if (x > 0) {
        pushIfMaskable(x - 1, y);
      }
      if (x + 1 < width) {
        pushIfMaskable(x + 1, y);
      }
      if (y > 0) {
        pushIfMaskable(x, y - 1);
      }
      if (y + 1 < height) {
        pushIfMaskable(x, y + 1);
      }
    }

    if (!changed) {
      return response;
    }

    ctx.putImageData(imageData, 0, 0);
    const outBlob = await canvas.convertToBlob({type: 'image/png'});
    const headers = new Headers(response.headers);
    headers.set('content-type', 'image/png');
    headers.delete('content-length');
    return new Response(outBlob, {
      status: response.status,
      statusText: response.statusText,
      headers
    });
  } catch {
    return response;
  }
}

async function trimCache(cache, maxItems) {
  const keys = await cache.keys();
  if (keys.length <= maxItems) {
    return;
  }

  const removals = keys.length - maxItems;
  for (let i = 0; i < removals; i += 1) {
    await cache.delete(keys[i]);
  }
}

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || !isWmtsTileRequest(event.request)) {
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(TILE_CACHE_NAME);
    const cached = await cache.match(event.request);

    const networkPromise = fetch(event.request)
      .then(async response => {
        if (response && response.ok) {
          const requestUrl = new URL(event.request.url);
          let responseToStore = response;

          if (shouldProcessTile(requestUrl)) {
            responseToStore = await maskTileNoDataToTransparent(response.clone());
          }

          await cache.put(event.request, responseToStore.clone());
          await trimCache(cache, MAX_CACHE_ITEMS);
        }
        if (!response || !response.ok) {
          return response;
        }

        const requestUrl = new URL(event.request.url);
        if (shouldProcessTile(requestUrl)) {
          return maskTileNoDataToTransparent(response.clone());
        }
        return response;
      })
      .catch(() => null);

    if (cached) {
      event.waitUntil(networkPromise);
      return cached;
    }

    const networkResponse = await networkPromise;
    if (networkResponse) {
      return networkResponse;
    }

    return new Response('', {status: 504, statusText: 'Gateway Timeout'});
  })());
});
