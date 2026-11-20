import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { createElement, Truck, Warehouse, Flag } from 'lucide';
import routes from './data/shipper-demo-routes.json';
import { createPositionWatcher } from './shipper-position.mjs';

const previewDuration = 90000;
const km = (metres) => `${(metres / 1000).toLocaleString('vi-VN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} km`;

export function setupShipperMap() {
    const root = document.querySelector('[data-shipper-map]');
    if (!root) return;
    const field = (name) => root.querySelector(`[data-${name}]`);
    const canvas = field('tracking-map');
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    root.dataset.reducedMotion = String(reducedMotion.matches);
    const map = L.map(canvas, { scrollWheelZoom: false, zoomControl: false });
    L.control.zoom({ position: 'topright', zoomInTitle: 'Phóng to', zoomOutTitle: 'Thu nhỏ' }).addTo(map);
    L.tileLayer('https://a.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        maxZoom: 19,
        referrerPolicy: 'strict-origin-when-cross-origin',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>, tiles <a href="https://www.hotosm.org/" target="_blank" rel="noopener noreferrer">HOT</a> / <a href="https://www.openstreetmap.fr/" target="_blank" rel="noopener noreferrer">OSM France</a>',
    }).on('tileerror', () => { field('tile-error').hidden = false; }).addTo(map);

    const icon = (symbol, kind, size = 36) => L.divIcon({
        className: `shipper-map-marker ${kind}`,
        html: `<span class="shipper-marker-body">${createElement(symbol, { 'aria-hidden': 'true' }).outerHTML}</span>`,
        iconSize: [size, size], iconAnchor: [size / 2, size / 2],
    });
    const planned = L.polyline([], { color: '#2475aa', weight: 6, opacity: 0.85 });
    const travelled = L.polyline([], { color: '#087f73', weight: 6 });
    const start = L.marker([0, 0], { icon: icon(Warehouse, 'start'), title: 'Điểm xuất phát mẫu', keyboard: false });
    const end = L.marker([0, 0], { icon: icon(Flag, 'end'), title: 'Điểm nhận mẫu', keyboard: false })
        .bindTooltip('Điểm nhận mẫu', { permanent: true, direction: 'top', offset: [0, -20] });
    const shipper = L.marker([0, 0], { icon: icon(Truck, 'courier', 48), keyboard: false, zIndexOffset: 1000 })
        .bindTooltip('Shipper mẫu', { permanent: true, direction: 'bottom', offset: [0, 26] });
    const accuracy = L.circle([0, 0], { radius: 0, color: '#087f73', weight: 1, fillOpacity: 0.08, interactive: false });
    const previewLayers = [planned, travelled, start, end];
    let source = 'idle';
    let follow = true;
    let position = null;
    let latestGpsPosition = null;
    let motionFrame = null;
    let previewFrame = null;
    let previewRunning = false;
    let previewElapsed = 0;
    let lastTick = null;
    let points = [];
    let distances = [];
    let total = 0;
    const gps = createPositionWatcher({
        geolocation: navigator.geolocation,
        onPosition: receivePosition,
        onError: gpsError,
        onStale: () => {
            cancelAnimationFrame(motionFrame);
            if (position) place(position);
            status('Tín hiệu GPS cũ');
            field('location-detail').textContent = 'Vị trí gần nhất';
        },
    });

    function status(label, kind = 'idle') {
        field('location-status').textContent = label;
        field('map-source').dataset.mapSource = kind;
    }

    function setFollow(value) {
        follow = value;
        field('map-follow').setAttribute('aria-pressed', String(value));
        field('map-follow').title = value ? 'Đang theo vị trí shipper' : 'Theo vị trí shipper';
    }

    function place(next, moving = false) {
        position = next;
        shipper.setLatLng(next);
        if (!map.hasLayer(shipper)) shipper.addTo(map);
        const marker = shipper.getElement();
        const label = source === 'preview' ? 'Shipper mẫu' : 'Vị trí thiết bị';
        if (shipper.getTooltip().getContent() !== label) shipper.setTooltipContent(label);
        marker.setAttribute('role', 'img');
        marker.setAttribute('aria-label', source === 'preview' ? 'Shipper trên tuyến mẫu' : 'Vị trí shipper');
        marker.dataset.moving = String(moving);
        field('map-follow').disabled = false;
        // Cập nhật camera cùng nhịp với marker để xe luôn nằm chính giữa khi bám vị trí.
        if (follow) map.setView(next, map.getZoom(), { animate: false });
    }

    function glide(next, moving) {
        cancelAnimationFrame(motionFrame);
        const from = position;
        if (!from || reducedMotion.matches || from.distanceTo(next) > 200) {
            place(next, moving);
            return;
        }
        let started = null;
        function step(timestamp) {
            started ??= timestamp;
            const fraction = Math.min(1, (timestamp - started) / 850);
            place(L.latLng(from.lat + (next.lat - from.lat) * fraction, from.lng + (next.lng - from.lng) * fraction), moving);
            if (fraction < 1) motionFrame = requestAnimationFrame(step);
        }
        motionFrame = requestAnimationFrame(step);
    }

    function pausePreview() {
        previewRunning = false;
        cancelAnimationFrame(previewFrame);
        previewFrame = null;
        lastTick = null;
        field('play-icon').hidden = false;
        field('pause-icon').hidden = true;
        field('preview-label').textContent = source === 'preview' && previewElapsed > 0 && previewElapsed < previewDuration ? 'Tiếp tục mô phỏng' : 'Chạy mô phỏng';
        if (source === 'preview') {
            status(previewElapsed >= previewDuration ? 'Đã đến điểm nhận mẫu' : previewElapsed > 0 ? 'Mô phỏng · Tạm dừng' : 'Sẵn sàng · Tuyến mẫu', 'preview');
            if (position) place(position);
        }
    }

    function stopGps(label = 'Đã tắt vị trí') {
        gps.stop();
        cancelAnimationFrame(motionFrame);
        field('gps-start').disabled = false;
        field('gps-label').textContent = 'Bật vị trí của tôi';
        field('gps-stop').hidden = true;
        if (source === 'gps') {
            status(label);
            field('location-detail').textContent = position ? 'Vị trí gần nhất' : 'GPS chưa kết nối';
            if (position) place(position);
        }
    }

    function gpsError(error) {
        cancelAnimationFrame(motionFrame);
        if (position) place(position);
        if (error.code === 1) stopGps('Chưa được cấp vị trí');
        else status(position ? 'Tín hiệu GPS gián đoạn' : 'Chưa lấy được vị trí');
        field('location-error').hidden = false;
        field('location-error').textContent = error.code === 1 ? 'Quyền vị trí bị từ chối. Hãy cho phép vị trí trong trình duyệt rồi thử lại.'
            : error.code === 3 ? 'Lấy vị trí quá thời gian. Hãy kiểm tra GPS và thử lại.' : 'Chưa nhận được tín hiệu GPS. Hãy kiểm tra vị trí của thiết bị.';
        field('gps-start').disabled = false;
        field('gps-label').textContent = 'Thử lại GPS';
        field('location-detail').textContent = position ? 'Vị trí gần nhất' : 'GPS chưa kết nối';
    }

    function startGps() {
        stopGps();
        pausePreview();
        previewLayers.forEach((layer) => map.removeLayer(layer));
        map.removeLayer(accuracy);
        map.removeLayer(shipper);
        field('map-follow').disabled = true;
        position = null;
        latestGpsPosition = null;
        source = 'gps';
        field('preview-label').textContent = 'Chạy mô phỏng';
        field('gps-metrics').hidden = true;
        field('preview-remaining').hidden = true;
        field('preview-fit').disabled = true;
        field('preview-reset').disabled = true;
        field('location-error').hidden = true;
        if (!window.isSecureContext || !navigator.geolocation) {
            gpsError({ code: 2 });
            field('location-error').textContent = 'GPS cần trình duyệt hỗ trợ vị trí và kết nối HTTPS hoặc localhost.';
            return;
        }
        setFollow(true);
        status('Đang lấy vị trí');
        field('gps-start').disabled = true;
        field('gps-label').textContent = 'Đang kết nối GPS';
        field('gps-stop').hidden = false;
        field('location-detail').textContent = 'Đang chờ GPS';
        gps.start();
    }

    function receivePosition(fix, first) {
        const { latitude, longitude, accuracy: radius, speed } = fix.coords;
        const next = L.latLng(latitude, longitude);
        latestGpsPosition = next;
        const moving = Number.isFinite(speed) ? speed > 0.5 : !!position && position.distanceTo(next) > 5;
        if (first) map.setView(next, 16, { animate: false });
        glide(next, moving);
        if (Number.isFinite(radius) && radius > 0 && radius <= 1000) accuracy.setLatLng(next).setRadius(radius).addTo(map);
        else map.removeLayer(accuracy);
        status('GPS đang kết nối', 'gps');
        field('location-error').hidden = true;
        field('gps-label').textContent = 'Đang dùng GPS';
        field('gps-start').disabled = true;
        field('location-detail').textContent = moving ? 'Đang di chuyển' : 'Đã có vị trí';
        field('gps-metrics').hidden = false;
        field('gps-accuracy').textContent = Number.isFinite(radius) ? `± ${Math.round(radius)} m` : '—';
        field('gps-speed').textContent = Number.isFinite(speed) && speed >= 0 ? `${Math.round(speed * 3.6)} km/h` : '—';
        field('gps-updated').textContent = new Date(fix.timestamp).toLocaleTimeString('vi-VN', { hour12: false });
    }

    function renderPreview() {
        const distance = total * previewElapsed / previewDuration;
        let index = distances.findIndex((value) => value >= distance);
        index = Math.max(1, index < 0 ? points.length - 1 : index);
        const fraction = (distance - distances[index - 1]) / (distances[index] - distances[index - 1] || 1);
        const from = points[index - 1];
        const to = points[index];
        const next = L.latLng(from.lat + (to.lat - from.lat) * fraction, from.lng + (to.lng - from.lng) * fraction);
        place(next, previewRunning);
        travelled.setLatLngs([...points.slice(0, index), next]);
        field('preview-remaining').textContent = `Còn ${km(total - distance)} trên tuyến mẫu`;
    }

    function previewTick(timestamp) {
        if (!previewRunning) return;
        if (lastTick !== null) previewElapsed = Math.min(previewDuration, previewElapsed + timestamp - lastTick);
        lastTick = timestamp;
        renderPreview();
        if (previewElapsed >= previewDuration) pausePreview();
        else previewFrame = requestAnimationFrame(previewTick);
    }

    function preparePreview(reload = false) {
        stopGps();
        map.removeLayer(accuracy);
        field('gps-metrics').hidden = true;
        field('location-error').hidden = true;
        if (source !== 'preview' || reload || previewElapsed >= previewDuration) {
            source = 'preview';
            const geometry = routes[field('preview-route').value];
            points = geometry.coordinates.map(([lng, lat]) => L.latLng(lat, lng));
            distances = [0];
            points.slice(1).forEach((point, index) => distances.push(distances[index] + points[index].distanceTo(point)));
            total = distances.at(-1);
            previewElapsed = 0;
            position = null;
            planned.setLatLngs(points);
            start.setLatLng(points[0]);
            end.setLatLng(points.at(-1));
            previewLayers.forEach((layer) => layer.addTo(map));
            map.setView(points[0], 16, { animate: false });
        }
        field('location-detail').textContent = 'Chưa dùng vị trí thiết bị';
        field('preview-remaining').hidden = false;
        field('preview-fit').disabled = false;
        field('preview-reset').disabled = false;
    }

    function fitPreview() {
        setFollow(false);
        map.fitBounds(planned.getBounds(), { paddingTopLeft: [45, 70], paddingBottomRight: [45, 45], maxZoom: 16, animate: false });
    }

    function resetPreview() {
        pausePreview();
        preparePreview(true);
        renderPreview();
        pausePreview();
        fitPreview();
    }

    function beginPreview() {
        preparePreview();
        previewRunning = true;
        lastTick = null;
        setFollow(true);
        field('play-icon').hidden = true;
        field('pause-icon').hidden = false;
        field('preview-label').textContent = 'Tạm dừng mô phỏng';
        status('Đang di chuyển · Tuyến mẫu', 'preview');
        renderPreview();
        previewFrame = requestAnimationFrame(previewTick);
    }

    field('gps-start').addEventListener('click', startGps);
    field('gps-stop').addEventListener('click', () => stopGps());
    field('map-follow').addEventListener('click', () => {
        setFollow(true);
        map.stop();
        if (position) map.setView(position, map.getZoom(), { animate: false });
    });
    map.on('dragstart', () => setFollow(false));
    map.on('zoomend', () => { if (follow && position) map.panTo(position, { animate: false }); });
    field('preview-play').addEventListener('click', () => previewRunning ? pausePreview() : beginPreview());
    field('preview-route').addEventListener('change', () => {
        resetPreview();
    });
    field('preview-reset').addEventListener('click', resetPreview);
    field('preview-fit').addEventListener('click', fitPreview);
    root.querySelectorAll('[data-map-mode]').forEach((button) => button.addEventListener('click', () => {
        const tracking = button.dataset.mapMode === 'shipper';
        if (!tracking) { pausePreview(); stopGps('GPS đã tạm dừng'); }
        field('map-tracking').hidden = !tracking;
        field('map-address').hidden = tracking;
        root.querySelectorAll('[data-map-mode]').forEach((mode) => {
            mode.classList.toggle('active', mode === button);
            mode.setAttribute('aria-pressed', String(mode === button));
        });
        if (tracking) {
            map.invalidateSize({ pan: false });
            if (follow && position) map.setView(position, map.getZoom(), { animate: false });
        } else {
            const iframe = root.querySelector('[data-address-src]');
            if (iframe && !iframe.src) iframe.src = iframe.dataset.addressSrc;
        }
    }));
    function suspend() { pausePreview(); stopGps('GPS đã tạm dừng'); }
    document.addEventListener('visibilitychange', () => { if (document.hidden) suspend(); });
    window.addEventListener('pagehide', suspend);
    reducedMotion.addEventListener('change', () => {
        root.dataset.reducedMotion = String(reducedMotion.matches);
        if (!reducedMotion.matches) return;
        pausePreview();
        cancelAnimationFrame(motionFrame);
        if (source === 'gps' && latestGpsPosition) place(latestGpsPosition);
    });
    new ResizeObserver(() => {
        map.invalidateSize({ pan: false });
        if (follow && position) map.setView(position, map.getZoom(), { animate: false });
    }).observe(canvas);
    const region = Object.hasOwn(routes, root.dataset.demoArea) ? root.dataset.demoArea : 'HN';
    field('preview-route').value = region;
    // Hiện sẵn hai điểm mẫu; chỉ đọc GPS khi người dùng chủ động chọn.
    resetPreview();
    field('gps-start').disabled = false;
    field('preview-play').disabled = false;
}
