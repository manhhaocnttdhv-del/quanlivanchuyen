import './bootstrap';
import $ from 'jquery';
import { Collapse, Tab, Tooltip, Modal, Offcanvas } from 'bootstrap';
import {
    createIcons, PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight,
    ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck,
    Zap, Banknote, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2, Route, Printer, RotateCcw, Send, X, Phone, Image, TriangleAlert, MessagesSquare, Paperclip, Clock3, LockKeyhole,
} from 'lucide';

window.$ = window.jQuery = $;
const iconSet = { PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight, ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck, Zap, Banknote, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2, Route, Printer, RotateCcw, Send, X, Phone, Image, TriangleAlert, MessagesSquare, Paperclip, Clock3, LockKeyhole };
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
    $('form[data-confirm]').on('submit', function (event) { if (!window.confirm(this.dataset.confirm)) event.preventDefault(); });
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
