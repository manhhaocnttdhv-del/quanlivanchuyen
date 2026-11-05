import './bootstrap';
import $ from 'jquery';
import { Collapse, Tab, Tooltip, Modal, Offcanvas } from 'bootstrap';
import {
    createIcons, PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight,
    ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck,
    Zap, Banknote, Wallet, ChartColumn, Download, Filter, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2, Route, Printer, RotateCcw, Send, X, Phone, Image, TriangleAlert, MessagesSquare, Paperclip, Clock3, LockKeyhole, UserRound, Store,
} from 'lucide';

window.$ = window.jQuery = $;
const iconSet = { PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight, ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck, Zap, Banknote, Wallet, ChartColumn, Download, Filter, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2, Route, Printer, RotateCcw, Send, X, Phone, Image, TriangleAlert, MessagesSquare, Paperclip, Clock3, LockKeyhole, UserRound, Store };
const money = (value) => new Intl.NumberFormat('vi-VN').format(value) + 'đ';

function renderQuote($target, quote) {
    $target.empty().removeClass('d-none');
    $('<div>', { class: 'quote-label', text: `Cước tham khảo · ${quote.route}` }).appendTo($target);
    $('<div>', { class: 'quote-price', text: money(quote.total) }).appendTo($target);
    $('<p>', { class: 'small mb-3', text: `${quote.service} · Dự kiến ${quote.days}` }).appendTo($target);
    const $breakdown = $('<dl>', { class: 'quote-breakdown' }).appendTo($target);
    [ [`Cước cơ bản (đến ${quote.base_weight_grams}g)`, quote.base_fee], ['Khối lượng thêm', quote.weight_fee] ].forEach(([label, value]) => {
        const $row = $('<div>').appendTo($breakdown);
        $('<dt>', { text: label }).appendTo($row);
        $('<dd>', { text: money(value) }).appendTo($row);
    });
    $('<p>', { class: 'quote-disclaimer', text: `${quote.version} · Cước được tính lại và chốt khi gửi duyệt.` }).appendTo($target);
}

function clearErrors($form, names = null) {
    $form.find('.is-invalid').filter((_, field) => !names || names.includes(field.name))
        .removeClass('is-invalid').removeAttr('aria-invalid');
    $form.find('[data-error-for]').filter((_, field) => !names || names.includes(field.dataset.errorFor)).empty();
}

function showRequestError(xhr, $form, $result) {
    if (xhr.status === 422 && xhr.responseJSON?.errors) {
        Object.entries(xhr.responseJSON.errors).forEach(([name, errors]) => {
            // Field names are defined by our Form Requests. Use text nodes for all messages.
            $form.find('[name]').filter((_, field) => field.name === name).addClass('is-invalid').attr('aria-invalid', 'true');
            $form.find('[data-error-for]').filter((_, field) => field.dataset.errorFor === name).text(errors[0]);
        });
        $form.find('.is-invalid').first().trigger('focus');
    }
    const message = xhr.status === 429 ? 'Bạn thao tác quá nhanh. Vui lòng thử lại sau một phút.'
        : xhr.status === 419 ? 'Phiên đã hết hạn. Hãy tải lại trang rồi thử lại.'
        : xhr.status === 422 ? 'Vui lòng kiểm tra các ô được đánh dấu.'
        : 'Chưa thể kết nối. Hãy kiểm tra mạng và thử lại.';
    $result.removeClass('d-none').empty().append($('<p>', { class: 'text-danger small mb-0', text: message }));
}

function requestQuote($form, $button, $result, url) {
    clearErrors($form, ['origin', 'destination', 'weight', 'service']);
    const $fields = $form.find('[name]').filter((_, field) => ['origin', 'destination', 'weight', 'service', '_token'].includes(field.name));
    const label = $button.html();
    const $quoteFields = $form.find('[name]').filter((_, field) => ['origin', 'destination', 'weight', 'service'].includes(field.name));
    $button.prop('disabled', true).text('Đang tính…');
    const data = $fields.serialize();
    $quoteFields.prop('disabled', true);
    $.ajax({ url, method: 'POST', data, dataType: 'json', timeout: 15000 })
        .done((quote) => renderQuote($result, quote))
        .fail((xhr) => { $quoteFields.prop('disabled', false); showRequestError(xhr, $form, $result); })
        .always(() => { $button.prop('disabled', false).html(label); $quoteFields.prop('disabled', false); });
}

$(function () {
    createIcons({ icons: iconSet });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => new Tooltip(element));
    document.querySelector('[data-validation-summary]')?.focus();

    $('#quote-form').on('submit', function (event) {
        event.preventDefault();
        requestQuote($(this), $(this).find('[type=submit]'), $('#quote-result'), this.action);
    }).on('input change', ':input', () => $('#quote-result').empty().addClass('d-none'));

    $('#tracking-form').on('submit', function (event) {
        event.preventDefault();
        const $form = $(this);
        const $result = $('#tracking-result');
        const $button = $form.find('[type=submit]');
        clearErrors($form);
        const label = $button.html();
        $button.prop('disabled', true).text('Đang tra cứu…');
        $form.find(':input:not(button)').prop('readOnly', true);
        $.ajax({ url: this.action, method: 'POST', data: $form.serialize(), dataType: 'json', timeout: 15000 })
            .done((result) => {
                $result.empty().removeClass('d-none is-error');
                $('<strong>', { text: result.status ?? 'Chưa tìm thấy đơn hàng' }).appendTo($result);
                $('<p>', { class: 'mb-0 mt-1', text: result.message }).appendTo($result);
                (result.events ?? []).forEach((item) => $('<p>', { class: 'small mt-2 mb-0', text: `${item.time} · ${item.status}` }).appendTo($result));
            })
            .fail((xhr) => showRequestError(xhr, $form, $result))
            .always(() => { $button.prop('disabled', false).html(label); $form.find(':input').prop('readOnly', false); });
    }).on('input', ':input', () => $('#tracking-result').empty().addClass('d-none'));

    $('[data-print]').on('click', () => window.print());
    $('[data-report-ledger]').on('change', function () {
        const $form = $(this).closest('form');
        const orderFilters = this.value === 'collections';
        const deposits = this.value === 'deposits';
        $form.find('[data-report-order-filter]').prop('hidden', !orderFilters).find('select').prop('disabled', !orderFilters);
        $form.find('[data-report-shop-filter]').prop('hidden', deposits).find('select').prop('disabled', deposits);
        const $group = $form.find('[data-report-group]');
        $group.find('option').each((_, option) => {
            option.disabled = deposits ? option.value !== 'warehouse' : !orderFilters && ['area', 'shipper'].includes(option.value);
        });
        if ($group.find(':selected').prop('disabled')) $group.val('warehouse');
    });
    $('[data-report-export]').each((_, element) => {
        const $panel = $(element);
        let state = element.dataset.exportState;
        let requests = 0;
        const poll = () => {
            if (!['queued', 'processing'].includes(state) || requests >= 120) return;
            if (document.hidden) { window.setTimeout(poll, 5000); return; }
            requests++;
            $.getJSON(element.dataset.reportExport).done(data => {
                state = data.status;
                $panel.find('[data-export-label]').text(data.label);
                $panel.find('[data-export-count]').text(data.row_count);
                $panel.find('[data-export-error]').text(data.error_message ?? '');
                $panel.find('[data-export-download]').prop('hidden', !data.download_url).attr('href', data.download_url ?? '#');
            }).always(() => window.setTimeout(poll, 5000));
        };
        window.setTimeout(poll, 3000);
    });
    $('[data-finance-kind]').on('change', function () {
        const kind = this.value;
        $('[data-finance-basis]').each((_, block) => {
            const type = block.dataset.financeBasis;
            const visible = type === 'incident' ? kind !== 'reversal'
                : type === 'reversal' ? kind === 'reversal'
                : type === 'fee' ? kind === 'fee_reduction' : kind.startsWith('collection_');
            $(block).toggle(visible).find(':input').prop('disabled', !visible);
        });
    }).trigger('change');
    $('form[data-confirm]').on('submit', function (event) { if (!window.confirm(this.dataset.confirm)) event.preventDefault(); });

    const $registration = $('[data-registration-form]');
    if ($registration.length) {
        const updateSenderType = () => {
            const isBusiness = $registration.find('[name="account_type"]:checked').val() === 'business';
            $registration.find('[data-business-field]').prop('hidden', !isBusiness)
                .find('input').prop('disabled', !isBusiness).prop('required', isBusiness);
            $registration.find('[data-sender-name]').toggleClass('col-md-6', isBusiness).toggleClass('col-12', !isBusiness);
            if (!isBusiness) clearErrors($registration, ['shop_name']);
        };
        $registration.find('[name="account_type"]').on('change', updateSenderType);
        updateSenderType();
        const $area = $registration.find('[name="area_id"]');
        const $warehouses = $registration.find('[data-warehouse-area]');
        const updateRegistrationWarehouses = () => {
            const areaId = String($area.val() ?? '');
            let count = 0;
            $warehouses.each((_, warehouse) => {
                const matches = areaId !== '' && warehouse.dataset.warehouseArea === areaId;
                warehouse.hidden = !matches;
                if (matches) count++;
            });
            const areaName = $area.find('option:selected').text();
            const message = !areaId ? 'Chưa chọn tỉnh/thành.'
                : count === 0 ? `Chưa có kho tiếp nhận đang hoạt động tại ${areaName}.`
                : `${areaName}: ${count} kho đang hoạt động.`;
            $registration.find('[data-warehouse-summary]').text(message);
        };
        $area.on('change', updateRegistrationWarehouses);
        updateRegistrationWarehouses();
    }

    const $pickup = $('#pickup_address_id');
    const $warehouse = $('#warehouse_id');
    if ($pickup.length && $warehouse.length) {
        const pickupAreas = $pickup.data('areas');
        const warehouseAreas = $warehouse.data('areas');
        const $warehouseOptions = $warehouse.find('option[value!=""]').clone();
        const updateReceivingWarehouses = () => {
            const selectedWarehouse = $warehouse.val();
            const area = pickupAreas[$pickup.val()];
            const $options = $warehouseOptions.filter((_, option) => area && warehouseAreas[option.value] === area);
            const placeholder = !area ? 'Chọn địa chỉ lấy hàng trước'
                : !$options.length ? 'Chưa có kho tiếp nhận tại khu vực này' : 'Chọn kho tiếp nhận';
            $warehouse.empty().append($('<option>', { value: '', text: placeholder })).append($options.clone());
            $warehouse.val($options.toArray().some((option) => option.value === selectedWarehouse) ? selectedWarehouse : '');
            $warehouse.prop('disabled', !$options.length);
        };
        $pickup.on('change', updateReceivingWarehouses);
        updateReceivingWarehouses();
    }

    let itemIndex = $('#order-item-rows .order-item-row').length;
    $('#add-order-item').on('click', function () {
        if ($('#order-item-rows .order-item-row').length >= 20) return;
        const $row = $('#order-item-rows .order-item-row').first().clone();
        $row.find('.invalid-feedback').remove();
        $row.find('input').each((_, input) => {
            input.name = input.name.replace(/items\[\d+\]/, `items[${itemIndex}]`);
            input.id = input.id.replace(/\d+$/, itemIndex);
            input.value = '';
            input.classList.remove('is-invalid');
        });
        $row.find('label').each((_, label) => { label.htmlFor = label.htmlFor.replace(/\d+$/, itemIndex); });
        itemIndex++;
        $('#order-item-rows').append($row);
        $row.find('input').first().trigger('focus');
    });
    $('#order-item-rows').on('click', '.remove-order-item', function () {
        if ($('#order-item-rows .order-item-row').length > 1) $(this).closest('.order-item-row').remove();
        else $(this).closest('.order-item-row').find('input').val('');
    });

    // Keep Bootstrap data-API modules in the Vite bundle, without exporting globals.
    $('#add-fee-rule').on('click', function () {
        const $row = $('#fee-rule-rows tr').first().clone();
        const next = Date.now();
        $row.find('[name]').each((_, field) => {
            field.name = field.name.replace(/rules\[\d+\]/, `rules[${next}]`);
        });
        $('#fee-rule-rows').append($row);
    });
    $('#fee-rule-rows').on('click', '.remove-fee-rule', function () { $(this).closest('tr').remove(); });
    void [Collapse, Tab, Modal, Offcanvas];
});
