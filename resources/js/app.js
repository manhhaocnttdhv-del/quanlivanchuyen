import './bootstrap';
import $ from 'jquery';
import { Collapse, Tab, Tooltip, Modal } from 'bootstrap';
import {
    createIcons, PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight,
    ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck,
    Zap, Banknote, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2,
} from 'lucide';

window.$ = window.jQuery = $;
const iconSet = { PackageCheck, Menu, ClipboardList, Plus, ArrowLeft, ArrowRight, ArrowUpRight, Package, ScanSearch, Calculator, Search, ShieldCheck, Truck, Zap, Banknote, MapPinHouse, MapPin, ReceiptText, Info, Save, Pencil, Trash2, CircleCheck, FilePlus2 };
const money = (value) => new Intl.NumberFormat('vi-VN').format(value) + 'đ';

function renderQuote($target, quote) {
    $target.empty().removeClass('d-none');
    $('<div>', { class: 'quote-label', text: `Cước tham khảo · ${quote.route}` }).appendTo($target);
    $('<div>', { class: 'quote-price', text: money(quote.total) }).appendTo($target);
    $('<p>', { class: 'small mb-3', text: `${quote.service} · Dự kiến ${quote.days}` }).appendTo($target);
    const $breakdown = $('<dl>', { class: 'quote-breakdown' }).appendTo($target);
    [ ['Cước cơ bản (đến 500g)', quote.base_fee], ['Khối lượng thêm', quote.weight_fee], ['Phụ phí dịch vụ nhanh', quote.service_fee] ].forEach(([label, value]) => {
        const $row = $('<div>').appendTo($breakdown);
        $('<dt>', { text: label }).appendTo($row);
        $('<dd>', { text: money(value) }).appendTo($row);
    });
    $('<p>', { class: 'quote-disclaimer', text: 'Biểu phí mẫu, chưa gồm phụ phí phát sinh. Cước và thời gian sẽ được xác nhận khi tiếp nhận kiện.' }).appendTo($target);
}

function clearErrors($form) {
    $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
    $form.find('[data-error-for]').empty();
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
    clearErrors($form);
    const $fields = $form.find('[name]').filter((_, field) => ['origin', 'destination', 'weight', 'service', '_token'].includes(field.name));
    const label = $button.html();
    const $quoteFields = $form.find('[name]').filter((_, field) => ['origin', 'destination', 'weight', 'service'].includes(field.name));
    const $saveButton = $form.is('#draft-form') ? $form.find('[type=submit]') : $();
    $button.prop('disabled', true).text('Đang tính…');
    $saveButton.prop('disabled', true);
    const data = $fields.serialize();
    $quoteFields.prop('disabled', true);
    $.ajax({ url, method: 'POST', data, dataType: 'json' })
        .done((quote) => renderQuote($result, quote))
        .fail((xhr) => { $quoteFields.prop('disabled', false); showRequestError(xhr, $form, $result); })
        .always(() => { $button.prop('disabled', false).html(label); $quoteFields.prop('disabled', false); $saveButton.prop('disabled', false); });
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
        $.ajax({ url: this.action, method: 'POST', data: $form.serialize(), dataType: 'json' })
            .done((result) => {
                $result.empty().removeClass('d-none is-error');
                $('<strong>', { text: result.found ? 'Bản nháp · Chưa gửi vận chuyển' : 'Chưa tìm thấy đơn hàng' }).appendTo($result);
                $('<p>', { class: 'mb-0 mt-1', text: result.message }).appendTo($result);
            })
            .fail((xhr) => showRequestError(xhr, $form, $result))
            .always(() => { $button.prop('disabled', false).html(label); $form.find(':input').prop('readOnly', false); });
    }).on('input', ':input', () => $('#tracking-result').empty().addClass('d-none'));

    $('#draft-estimate').on('click', function () {
        const $form = $('#draft-form');
        requestQuote($form, $(this), $('#draft-quote'), $form.data('estimate-url'));
    });
    $('#draft-form').on('input change', '[name=origin], [name=destination], [name=weight], [name=service]', () => {
        $('#draft-quote').empty().append($('<p>', { class: 'small text-secondary mb-0', text: 'Thông tin đã đổi. Bấm Tính cước để xem mức mới.' }));
    });

    document.querySelector('#delete-modal')?.addEventListener('show.bs.modal', (event) => {
        $('#delete-form').attr('action', event.relatedTarget.dataset.deleteUrl);
        $('#delete-code').text(event.relatedTarget.dataset.code);
    });

    // Keep Bootstrap data-API modules in the Vite bundle, without exporting globals.
    void [Collapse, Tab, Modal];
});
