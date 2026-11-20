import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { createElement, Truck, Warehouse, Flag } from 'lucide';
import routes from './data/shipper-demo-routes.json';

const duration = 90000;
const km = (metres) => `${(metres / 1000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} km`;
const clock = (milliseconds) => {
    const seconds = Math.floor(milliseconds / 1000);
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
};

export function setupShipperMap() {
    const root = document.querySelector('[data-shipper-simulation]');
    if (!root) return;
    const field = (name) => root.querySelector(`[data-${name}]`);
    const canvas = field('demo-map');
    const map = L.map(canvas, { scrollWheelZoom: false, zoomControl: false });
    L.control.zoom({ position: 'topright', zoomInTitle: 'Phóng to', zoomOutTitle: 'Thu nhỏ' }).addTo(map);
    L.tileLayer('https://a.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        maxZoom: 19,
        referrerPolicy: 'strict-origin-when-cross-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>, tiles <a href="https://www.hotosm.org/" target="_blank" rel="noopener noreferrer">HOT</a> / <a href="https://www.openstreetmap.fr/" target="_blank" rel="noopener noreferrer">OSM France</a>',
    }).on('tileerror', () => { field('tile-error').hidden = false; }).addTo(map);

    const icon = (symbol, kind) => L.divIcon({
        className: `shipper-demo-marker ${kind}`,
        html: createElement(symbol, { 'aria-hidden': 'true' }).outerHTML,
        iconSize: [36, 36], iconAnchor: [18, 18],
    });
    const planned = L.polyline([], { color: '#78878c', weight: 6, opacity: 0.6 }).addTo(map);
    const travelled = L.polyline([], { color: '#087f73', weight: 6 }).addTo(map);
    const start = L.marker([0, 0], { icon: icon(Warehouse, 'start'), title: 'Điểm xuất phát mẫu', keyboard: false }).addTo(map).bindTooltip('Điểm xuất phát mẫu');
    const end = L.marker([0, 0], { icon: icon(Flag, 'end'), title: 'Điểm giao mẫu', keyboard: false }).addTo(map).bindTooltip('Điểm giao mẫu');
    const shipper = L.marker([0, 0], { icon: icon(Truck, 'courier'), title: 'Shipper mô phỏng', keyboard: false, zIndexOffset: 1000 }).addTo(map).bindTooltip('Shipper mô phỏng');
    let points = [];
    let distances = [];
    let total = 0;
    let elapsed = 0;
    let running = false;
    let frame = null;
    let lastTick = null;

    function updateStatus() {
        const button = field('demo-play');
        const label = running ? 'Tạm dừng mô phỏng' : elapsed >= duration ? 'Chạy lại mô phỏng' : 'Chạy mô phỏng';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(running));
        field('play-icon').hidden = running;
        field('pause-icon').hidden = !running;
        field('demo-status').textContent = elapsed >= duration ? 'Đã đến điểm mẫu' : running ? 'Đang di chuyển' : elapsed > 0 ? 'Tạm dừng' : 'Sẵn sàng';
    }

    function pause() {
        running = false;
        cancelAnimationFrame(frame);
        frame = null;
        lastTick = null;
        updateStatus();
    }

    function render() {
        const distance = total * elapsed / duration;
        let index = distances.findIndex((value) => value >= distance);
        index = Math.max(1, index < 0 ? points.length - 1 : index);
        const fraction = (distance - distances[index - 1]) / (distances[index] - distances[index - 1] || 1);
        const from = points[index - 1];
        const to = points[index];
        const position = L.latLng(from.lat + (to.lat - from.lat) * fraction, from.lng + (to.lng - from.lng) * fraction);
        shipper.setLatLng(position);
        travelled.setLatLngs([...points.slice(0, index), position]);
        field('demo-progress').value = String(Math.round(elapsed / duration * 1000));
        field('demo-percent').textContent = `${Math.round(elapsed / duration * 100)}%`;
        field('demo-travelled').textContent = km(distance);
        field('demo-remaining').textContent = km(total - distance);
        field('demo-time').textContent = clock(elapsed);
        if (field('demo-follow').checked && !map.getBounds().pad(-0.15).contains(position)) {
            map.panTo(position, { animate: false });
        }
    }

    function fit() {
        map.fitBounds(planned.getBounds(), { padding: [35, 35], maxZoom: 16, animate: false });
    }

    function loadRoute() {
        pause();
        const geometry = routes[field('demo-route').value];
        points = geometry.coordinates.map(([lng, lat]) => L.latLng(lat, lng));
        distances = [0];
        // Đo theo từng đoạn tuyến mẫu để xe đi đều qua cả đoạn ngắn và dài.
        points.slice(1).forEach((point, index) => distances.push(distances[index] + points[index].distanceTo(point)));
        total = distances.at(-1);
        elapsed = 0;
        planned.setLatLngs(points);
        start.setLatLng(points[0]);
        end.setLatLng(points.at(-1));
        fit();
        render();
        updateStatus();
    }

    function tick(timestamp) {
        if (!running) return;
        if (lastTick !== null) elapsed = Math.min(duration, elapsed + (timestamp - lastTick) * Number(field('demo-speed').value));
        lastTick = timestamp;
        render();
        if (elapsed >= duration) pause();
        else frame = requestAnimationFrame(tick);
    }

    field('demo-play').addEventListener('click', () => {
        if (running) { pause(); return; }
        if (elapsed >= duration) elapsed = 0;
        running = true;
        lastTick = null;
        updateStatus();
        frame = requestAnimationFrame(tick);
    });
    field('demo-reset').addEventListener('click', () => { pause(); elapsed = 0; fit(); render(); updateStatus(); });
    field('demo-fit').addEventListener('click', fit);
    field('demo-route').addEventListener('change', loadRoute);
    field('demo-follow').addEventListener('change', () => {
        if (field('demo-follow').checked) map.panTo(shipper.getLatLng());
    });
    field('demo-progress').addEventListener('input', () => {
        pause();
        elapsed = Number(field('demo-progress').value) / 1000 * duration;
        render();
        updateStatus();
    });
    root.querySelectorAll('[data-map-mode]').forEach((button) => button.addEventListener('click', () => {
        const demo = button.dataset.mapMode === 'demo';
        pause();
        field('map-demo').hidden = !demo;
        field('map-address').hidden = demo;
        root.querySelectorAll('[data-map-mode]').forEach((mode) => {
            const active = mode === button;
            mode.classList.toggle('active', active);
            mode.setAttribute('aria-pressed', String(active));
        });
        if (demo) { map.invalidateSize(); render(); }
        else {
            const iframe = root.querySelector('[data-address-src]');
            if (iframe && !iframe.src) iframe.src = iframe.dataset.addressSrc;
        }
    }));
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); });
    window.addEventListener('pagehide', pause);
    new ResizeObserver(() => map.invalidateSize()).observe(canvas);
    field('demo-route').value = Object.hasOwn(routes, root.dataset.demoArea) ? root.dataset.demoArea : 'HN';
    loadRoute();
    shipper.getElement().setAttribute('aria-label', 'Shipper mô phỏng');
    shipper.getElement().setAttribute('role', 'img');
    field('demo-play').disabled = false;
}
