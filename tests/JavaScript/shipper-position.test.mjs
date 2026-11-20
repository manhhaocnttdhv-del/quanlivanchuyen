import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createPositionWatcher } from '../../resources/js/shipper-position.mjs';

function fixture(t) {
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: 1800000000000 });
    const watches = [];
    const cleared = [];
    const received = [];
    const errors = [];
    let stale = 0;
    const watcher = createPositionWatcher({
        geolocation: {
            watchPosition(success, error, options) {
                watches.push({ success, error, options });
                return watches.length - 1;
            },
            clearWatch(id) { cleared.push(id); },
        },
        onPosition(fix, first) { received.push({ fix, first }); },
        onError(error) { errors.push(error.code); },
        onStale() { stale++; },
    });
    t.after(() => watcher.stop());
    const fix = (overrides = {}) => ({ coords: { latitude: 10.77, longitude: 106.69, accuracy: 8, speed: 2 }, timestamp: Date.now(), ...overrides });
    return { watcher, watches, cleared, received, errors, fix, stale: () => stale };
}

test('GPS is opt-in and accepts only fresh, ordered, valid positions', (t) => {
    const state = fixture(t);
    assert.equal(state.watches.length, 0);
    state.watcher.start();
    const watch = state.watches[0];
    assert.deepEqual(watch.options, { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 });
    watch.success(state.fix());
    watch.success(state.fix());
    watch.success(state.fix({ timestamp: Date.now() - 21000 }));
    watch.success(state.fix({ timestamp: Date.now() + 6000 }));
    watch.success(state.fix({ timestamp: NaN }));
    watch.success(state.fix({ coords: { latitude: 91, longitude: 106 } }));
    watch.success(state.fix({ coords: { latitude: 10, longitude: Infinity } }));
    assert.equal(state.received.length, 1);
    assert.equal(state.received[0].first, true);
    t.mock.timers.tick(1000);
    watch.success(state.fix());
    assert.equal(state.received.length, 2);
    assert.equal(state.received[1].first, false);
});

test('stop and restart discard queued callbacks from the old GPS session', (t) => {
    const state = fixture(t);
    state.watcher.start();
    const old = state.watches[0];
    old.success(state.fix());
    state.watcher.stop();
    assert.deepEqual(state.cleared, [0]);
    t.mock.timers.tick(21000);
    old.success(state.fix());
    old.error({ code: 1 });
    assert.equal(state.received.length, 1);
    assert.equal(state.errors.length, 0);
    assert.equal(state.stale(), 0);
    state.watcher.start();
    old.success(state.fix());
    state.watches[1].success(state.fix());
    assert.equal(state.received.length, 2);
    assert.equal(state.received[1].first, true);
    state.watcher.start();
    assert.deepEqual(state.cleared, [0, 1]);
});

test('denied permission stops GPS; a temporary timeout can recover', (t) => {
    const state = fixture(t);
    state.watcher.start();
    state.watches[0].error({ code: 1 });
    state.watches[0].success(state.fix());
    assert.deepEqual(state.cleared, [0]);
    assert.deepEqual(state.errors, [1]);
    assert.equal(state.received.length, 0);
    state.watcher.start();
    state.watches[1].error({ code: 3 });
    state.watches[1].success(state.fix());
    assert.deepEqual(state.errors, [1, 3]);
    assert.equal(state.received.length, 1);
});

test('stale state is based on the fix timestamp and resets with a new position', (t) => {
    const state = fixture(t);
    state.watcher.start();
    state.watches[0].success(state.fix({ timestamp: Date.now() - 5000 }));
    t.mock.timers.tick(14999);
    assert.equal(state.stale(), 0);
    t.mock.timers.tick(1);
    assert.equal(state.stale(), 1);
    state.watches[0].success(state.fix());
    t.mock.timers.tick(15000);
    state.watches[0].success(state.fix());
    t.mock.timers.tick(19999);
    assert.equal(state.stale(), 1);
    t.mock.timers.tick(1);
    assert.equal(state.stale(), 2);
});
