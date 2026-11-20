<div data-shipper-map data-demo-area="{{ $demoArea }}">
    <div class="shipper-map-modes btn-group mb-3" role="group" aria-label="Nội dung bản đồ">
        <button type="button" class="btn btn-outline-primary active" data-map-mode="shipper" aria-pressed="true">Shipper và điểm nhận</button>
        <button type="button" class="btn btn-outline-primary" data-map-mode="address" aria-pressed="false">Địa chỉ đơn</button>
    </div>
    <p class="text-danger small" data-map-load-error role="alert" hidden>Chưa tải được bản đồ. Vui lòng tải lại trang.</p>
    <div data-map-tracking>
        <div class="shipper-map-stage">
            <div class="shipper-map-canvas shipper-leaflet-map" data-tracking-map role="region" aria-label="Bản đồ vị trí shipper"></div>
            <div class="shipper-map-source" data-map-source="manual"><span class="shipper-map-signal" aria-hidden="true"></span><span data-location-status role="status">Chưa chọn vị trí shipper</span></div>
            <button type="button" class="btn btn-light icon-button shipper-map-follow" data-map-follow aria-label="Theo vị trí shipper" title="Theo vị trí shipper" aria-pressed="true" disabled><i data-lucide="locate-fixed"></i></button>
        </div>
        <p class="text-danger small mt-2 mb-0" data-tile-error role="status" hidden>Không tải được nền bản đồ OpenStreetMap.</p>
        <div class="shipper-manual-controls" role="group" aria-label="Chọn vị trí trên bản đồ">
            <button type="button" class="btn btn-outline-primary active" data-manual-select="shipper" aria-pressed="true"><i data-lucide="truck"></i> Vị trí shipper</button>
            <button type="button" class="btn btn-outline-primary" data-manual-select="destination" aria-pressed="false"><i data-lucide="flag"></i> Điểm nhận</button>
            <button type="button" class="btn btn-outline-secondary icon-button" data-manual-center aria-label="Đặt điểm tại tâm bản đồ" title="Đặt điểm tại tâm bản đồ"><i data-lucide="map-pin"></i></button>
            <button type="button" class="btn btn-outline-secondary icon-button" data-manual-fit aria-label="Xem hai điểm đã chọn" title="Xem hai điểm đã chọn" disabled><i data-lucide="maximize-2"></i></button>
            <button type="button" class="btn btn-outline-secondary icon-button" data-manual-clear aria-label="Xóa hai điểm đã chọn" title="Xóa hai điểm đã chọn" disabled><i data-lucide="rotate-ccw"></i></button>
            <a class="btn btn-outline-primary" data-manual-directions target="_blank" rel="noopener noreferrer" hidden><i data-lucide="route"></i> Chỉ đường hai điểm</a>
        </div>
        <dl class="shipper-manual-points small" data-manual-points>
            <div><dt>Shipper</dt><dd data-manual-shipper>Chưa chọn</dd></div>
            <div><dt>Điểm nhận</dt><dd data-manual-destination>Chưa chọn</dd></div>
        </dl>
        <p class="small text-secondary mb-0" data-manual-distance hidden></p>
        <details class="shipper-map-preview mt-3">
            <summary>Tuyến mẫu</summary>
            <div class="shipper-preview-controls">
                <select class="form-select form-select-sm" aria-label="Khu vực tuyến mẫu" data-preview-route>
                    <option value="HN">Hà Nội</option><option value="HCM">TP.HCM</option><option value="DN">Đà Nẵng</option>
                </select>
                <button type="button" class="btn btn-primary" data-preview-play disabled><span data-play-icon><i data-lucide="play"></i></span><span data-pause-icon hidden><i data-lucide="pause"></i></span> <span data-preview-label>Chạy mô phỏng</span></button>
                <button type="button" class="btn btn-outline-secondary icon-button" data-preview-reset aria-label="Về đầu tuyến mẫu" title="Về đầu tuyến mẫu" disabled><i data-lucide="rotate-ccw"></i></button>
                <button type="button" class="btn btn-outline-secondary icon-button" data-preview-fit aria-label="Xem shipper và điểm nhận mẫu" title="Xem shipper và điểm nhận mẫu" disabled><i data-lucide="maximize-2"></i></button>
                <span class="small text-secondary" data-preview-remaining hidden></span>
            </div>
        </details>
        <details class="shipper-map-preview mt-3">
            <summary>Vị trí thiết bị (tùy chọn)</summary>
            <div class="shipper-tracking-controls">
                <button type="button" class="btn btn-primary" data-gps-start disabled><i data-lucide="navigation"></i> <span data-gps-label>Bật vị trí của tôi</span></button>
                <button type="button" class="btn btn-outline-secondary icon-button" data-gps-stop aria-label="Tắt vị trí" title="Tắt vị trí" hidden><i data-lucide="square"></i></button>
                <span class="small text-secondary" data-location-detail>Chưa dùng vị trí thiết bị</span>
            </div>
            <p class="small text-danger mb-2" data-location-error role="alert" hidden></p>
            <div class="shipper-tracking-metrics small" data-gps-metrics hidden>
                <span>Độ chính xác <strong data-gps-accuracy>—</strong></span>
                <span>Tốc độ <strong data-gps-speed>—</strong></span>
                <span>Cập nhật <strong data-gps-updated>—</strong></span>
            </div>
        </details>
    </div>
    <div data-map-address hidden>
        @if ($stop['embed'])
            <iframe class="shipper-map-canvas" data-address-src="{{ $stop['embed'] }}" title="Bản đồ điểm đến" referrerpolicy="no-referrer" allowfullscreen></iframe>
        @else
            <div class="shipper-map-unavailable"><i data-lucide="map-pin"></i><p>Chưa có địa chỉ điểm đến</p></div>
        @endif
    </div>
    <noscript><p class="text-secondary small mt-2">Bản đồ cần JavaScript.</p></noscript>
</div>
