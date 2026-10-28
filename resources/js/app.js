import './bootstrap';

// Keep jQuery available for the project conventions used by the admin screens.
import $ from 'jquery';
import { Tooltip } from 'bootstrap';

window.$ = $;
window.jQuery = $;

// Keep shared UI behavior in one place so each sprint can reuse it.
$(function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        new Tooltip(element);
    });
});
