export function createPositionWatcher({ geolocation, onPosition, onError, onStale }) {
    let watchId = null;
    let session = 0;
    let lastTimestamp = 0;
    let staleTimer = null;

    function stop() {
        session++;
        if (watchId !== null) geolocation.clearWatch(watchId);
        watchId = null;
        clearTimeout(staleTimer);
    }

    function start() {
        stop();
        lastTimestamp = 0;
        const current = session;
        watchId = geolocation.watchPosition((fix) => {
            if (current !== session) return;
            const { latitude, longitude } = fix.coords;
            const age = Date.now() - fix.timestamp;
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180
                || !Number.isFinite(fix.timestamp) || fix.timestamp <= lastTimestamp || age > 20000 || age < -5000) return;
            const first = lastTimestamp === 0;
            lastTimestamp = fix.timestamp;
            clearTimeout(staleTimer);
            onPosition(fix, first);
            staleTimer = setTimeout(() => {
                if (current === session) onStale();
            }, Math.max(0, 20000 - age));
        }, (error) => {
            if (current !== session) return;
            clearTimeout(staleTimer);
            if (error.code === 1) stop();
            onError(error);
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
    }

    return { start, stop };
}
