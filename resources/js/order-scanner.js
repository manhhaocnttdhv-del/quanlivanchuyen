import { BrowserQRCodeReader } from '@zxing/browser';

export function setupOrderScanner() {
    const panel = document.querySelector('[data-order-scanner]');
    const form = panel.querySelector('[data-scan-form]');
    const video = panel.querySelector('[data-scan-video]');
    const start = panel.querySelector('[data-scan-start]');
    const stop = panel.querySelector('[data-scan-stop]');
    const file = panel.querySelector('#scan-image');
    const message = panel.querySelector('[data-scan-message]');
    const reader = new BrowserQRCodeReader();
    let controls;
    let generation = 0;
    let submitted = false;

    const stopCamera = () => {
        generation++;
        controls?.stop();
        controls = undefined;
        video.srcObject?.getTracks().forEach(track => track.stop());
        video.srcObject = null;
        video.hidden = true;
        stop.hidden = true;
        start.disabled = false;
    };
    const open = (result) => {
        if (submitted) return;
        stopCamera();
        form.elements.code.value = result.getText();
        submitted = true;
        form.requestSubmit();
    };

    start.addEventListener('click', async () => {
        message.textContent = '';
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            message.textContent = 'Camera cần HTTPS hoặc localhost. Bạn có thể chọn ảnh QR hoặc nhập mã.';
            return;
        }
        stopCamera();
        const run = generation;
        start.disabled = true;
        stop.hidden = false;
        video.hidden = false;
        try {
            const active = await reader.decodeFromConstraints({ video: { facingMode: { ideal: 'environment' } }, audio: false }, video,
                (result, error, scanner) => { if (result && run === generation) { scanner.stop(); open(result); } });
            if (run !== generation) active.stop();
            else controls = active;
        } catch (error) {
            if (run !== generation) return;
            stopCamera();
            message.textContent = error.name === 'NotAllowedError' ? 'Chưa được cấp quyền camera. Bạn có thể chọn ảnh QR hoặc nhập mã.'
                : 'Không mở được camera. Bạn có thể chọn ảnh QR hoặc nhập mã.';
        }
    });
    stop.addEventListener('click', stopCamera);
    file.addEventListener('change', async () => {
        stopCamera();
        message.textContent = '';
        const image = file.files?.[0];
        if (!image) return;
        if (!['image/png', 'image/jpeg', 'image/webp'].includes(image.type) || image.size > 5 * 1024 * 1024) {
            message.textContent = 'Chọn ảnh PNG, JPG hoặc WebP tối đa 5 MB.';
            file.value = '';
            return;
        }
        const url = URL.createObjectURL(image);
        file.disabled = true;
        start.disabled = true;
        try {
            open(await reader.decodeFromImageUrl(url));
        } catch {
            message.textContent = 'Không đọc được QR trong ảnh. Chọn ảnh rõ hơn hoặc nhập mã vận đơn.';
        } finally {
            URL.revokeObjectURL(url);
            file.value = '';
            file.disabled = false;
            start.disabled = false;
        }
    });
    form.addEventListener('submit', stopCamera);
    window.addEventListener('pagehide', stopCamera);
    window.addEventListener('pageshow', () => { submitted = false; });
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
}
