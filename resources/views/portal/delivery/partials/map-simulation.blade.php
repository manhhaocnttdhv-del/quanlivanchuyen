<div data-shipper-simulation data-demo-area="{{ $demoArea }}">
    <div class="shipper-map-modes btn-group mb-3" role="group" aria-label="Chế độ bản đồ">
        <button type="button" class="btn btn-outline-primary active" data-map-mode="demo" aria-pressed="true">Mô phỏng</button>
        <button type="button" class="btn btn-outline-primary" data-map-mode="address" aria-pressed="false">Địa chỉ đơn</button>
    </div>
    <p class="text-danger small" data-map-load-error hidden>Chưa tải được mô phỏng. Vui lòng tải lại trang.</p>
    <div data-map-demo>
        <div class="shipper-demo-heading">
            <label class="d-flex align-items-center gap-2"><span class="small text-secondary">Tuyến mẫu</span>
                <select class="form-select form-select-sm" aria-label="Tuyến mẫu" data-demo-route>
                    <option value="HN">Hà Nội</option><option value="HCM">TP.HCM</option><option value="DN">Đà Nẵng</option>
                </select>
            </label>
            <span class="status-badge" data-demo-status role="status">Sẵn sàng</span>
        </div>
        <div class="shipper-map-canvas shipper-leaflet-map" data-demo-map role="region" aria-label="Bản đồ mô phỏng shipper"></div>
        <p class="text-danger small mt-2 mb-0" data-tile-error hidden>Không tải được nền bản đồ OpenStreetMap.</p>
        <div class="shipper-demo-controls">
            <div class="shipper-demo-buttons">
                <button type="button" class="btn btn-primary icon-button" data-demo-play aria-label="Chạy mô phỏng" title="Chạy mô phỏng" aria-pressed="false" disabled><span data-play-icon><i data-lucide="play"></i></span><span data-pause-icon hidden><i data-lucide="pause"></i></span></button>
                <button type="button" class="btn btn-outline-primary icon-button" data-demo-reset aria-label="Bắt đầu lại" title="Bắt đầu lại"><i data-lucide="rotate-ccw"></i></button>
                <button type="button" class="btn btn-outline-primary icon-button" data-demo-fit aria-label="Xem toàn tuyến" title="Xem toàn tuyến"><i data-lucide="maximize-2"></i></button>
                <select class="form-select form-select-sm" aria-label="Tốc độ mô phỏng" data-demo-speed><option value="1">1x</option><option value="2">2x</option><option value="4">4x</option></select>
            </div>
            <label class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" data-demo-follow checked><span class="form-check-label small">Theo shipper</span></label>
        </div>
        <div class="shipper-demo-progress">
            <input type="range" class="form-range" min="0" max="1000" step="1" value="0" aria-label="Tiến độ mô phỏng" data-demo-progress>
            <output data-demo-percent>0%</output>
        </div>
        <div class="shipper-demo-metrics small"><span>Đã đi <strong data-demo-travelled>0,00 km</strong></span><span>Còn lại <strong data-demo-remaining></strong></span><span>Thời gian mẫu <strong data-demo-time>00:00</strong></span></div>
    </div>
    <div data-map-address hidden>
        @if ($stop['embed'])
            <iframe class="shipper-map-canvas" data-address-src="{{ $stop['embed'] }}" title="Bản đồ địa chỉ đơn" referrerpolicy="no-referrer" allowfullscreen></iframe>
        @else
            <div class="shipper-map-unavailable"><i data-lucide="map-pin"></i><p>Chưa có địa chỉ điểm đến</p></div>
        @endif
    </div>
    <noscript><p class="text-secondary small mt-2">Mô phỏng cần JavaScript.</p></noscript>
</div>
