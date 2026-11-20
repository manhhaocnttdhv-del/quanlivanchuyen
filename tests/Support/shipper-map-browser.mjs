import { build } from 'esbuild';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Local-only browser fixture: render the real Blade partial with synthetic GPS.
const php = process.env.SHIPPER_MAP_TEST_PHP || 'php';
const partial = execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo view('portal.delivery.partials.map-tracking', ['demoArea' => 'HCM', 'stop' => ['embed' => null]])->render();`], { encoding: 'utf8' });
const fixture = await build({
    stdin: {
        contents: `
            import { setupShipperMap } from './resources/js/shipper-map.js';
            import { createIcons, LocateFixed, Navigation, Square, Play, Pause, Maximize2, RotateCcw, Truck, Flag, MapPin, Route } from 'lucide';
            let success, failure, watchCount = 0, clearCount = 0;
            Object.defineProperty(navigator, 'geolocation', { value: {
                watchPosition(onPosition, onError) { success = onPosition; failure = onError; document.querySelector('#watches').textContent = ++watchCount; return watchCount; },
                clearWatch() { document.querySelector('#clears').textContent = ++clearCount; },
            } });
            const reduced = matchMedia('(prefers-reduced-motion: reduce)');
            let reduceMotion = false;
            Object.defineProperty(reduced, 'matches', { get: () => reduceMotion });
            const nativeMatchMedia = window.matchMedia.bind(window);
            window.matchMedia = (query) => query === '(prefers-reduced-motion: reduce)' ? reduced : nativeMatchMedia(query);
            document.querySelector('#reduce').addEventListener('change', (event) => { reduceMotion = event.target.checked; reduced.dispatchEvent(new Event('change')); });
            document.querySelectorAll('[data-fix]').forEach(button => button.addEventListener('click', () => {
                if (!success) return;
                const second = button.dataset.fix === '2';
                success({ coords: { latitude: second ? 10.7765 : 10.7758, longitude: second ? 106.6997 : 106.7005, accuracy: 8, speed: 4 }, timestamp: Date.now() });
            }));
            document.querySelectorAll('[data-error]').forEach(button => button.addEventListener('click', () => failure?.({ code: Number(button.dataset.error) })));
            createIcons({ icons: { LocateFixed, Navigation, Square, Play, Pause, Maximize2, RotateCcw, Truck, Flag, MapPin, Route } });
            setupShipperMap();
        `,
        resolveDir: process.cwd(),
        loader: 'js',
    },
    bundle: true,
    write: false,
    outdir: resolve(process.env.TEMP || '.', 'shipper-map-browser'),
    entryNames: 'fixture',
    loader: { '.png': 'dataurl' },
    logLevel: 'silent',
});
const assets = new Map(fixture.outputFiles.map(file => [file.path.endsWith('.css') ? '/fixture.css' : '/fixture.js', file.contents]));
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const cssPath = manifest['resources/css/app.css'].file;
const html = `<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shipper map GPS fixture</title><link rel="stylesheet" href="/build/${cssPath}"><link rel="stylesheet" href="/fixture.css"><body><main style="max-width:900px;margin:auto;padding:20px"><h1 class="h5">GPS fixture</h1><div class="d-flex flex-wrap gap-2 mb-3"><button class="btn btn-secondary" data-fix="1">GPS 1</button><button class="btn btn-secondary" data-fix="2">GPS 2</button><button class="btn btn-secondary" data-error="1">Từ chối quyền</button><button class="btn btn-secondary" data-error="3">GPS timeout</button><label><input id="reduce" type="checkbox"> Giảm chuyển động</label><span>Watch: <output id="watches">0</output> / Clear: <output id="clears">0</output></span></div>${partial}</main><script type="module" src="/fixture.js"></script></body></html>`;
const server = createServer((request, response) => {
    if (request.url === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    if (assets.has(request.url)) { response.setHeader('Content-Type', request.url.endsWith('.css') ? 'text/css' : 'text/javascript'); response.end(assets.get(request.url)); return; }
    if (/^\/build\/assets\/[a-zA-Z0-9_.-]+$/.test(request.url)) {
        const path = resolve('public', `.${request.url}`);
        response.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : 'font/woff2');
        response.end(readFileSync(path));
        return;
    }
    response.writeHead(404).end();
});
server.listen(8002, '127.0.0.1', () => console.log('Synthetic GPS fixture: http://127.0.0.1:8002'));
