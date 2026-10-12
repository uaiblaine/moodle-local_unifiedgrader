// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Save queue with exponential backoff retry for failed AJAX operations.
 *
 * When a save fails (e.g. due to network loss), the operation is queued and
 * retried automatically with increasing delays. Detects server availability
 * via active probing (not just navigator.onLine) so that local server
 * restarts (e.g. MAMP) are detected promptly.
 *
 * @module     local_unifiedgrader/save_queue
 * @copyright  2026 South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {number} Retry tick interval in ms. */
const TICK_INTERVAL = 5000;

/** @type {number[]} Backoff schedule in ms. */
const BACKOFF_SCHEDULE = [5000, 10000, 20000, 40000, 60000];

/** @type {number} Maximum retry attempts before giving up. */
const MAX_RETRIES = 5;

/**
 * @typedef {object} QueueEntry
 * @property {string} id Unique entry identifier.
 * @property {string} type Operation type label (for display/logging).
 * @property {Function} saveFn Async function that performs the save.
 * @property {Function|null} onSuccessFn Optional callback invoked after successful retry.
 * @property {number} retryCount Number of retries attempted.
 * @property {number} nextRetryAt Timestamp (ms) of next retry.
 * @property {string} sesskey Session key at time of enqueue.
 * @property {string|null} key What the payload saves (e.g. one student's grade), or null.
 */

/** @type {QueueEntry[]} Pending save operations. */
const _queue = [];

/** @type {Set<Function>} Status change listeners. */
const _listeners = new Set();

/** @type {number|null} Retry tick interval ID. */
let _tickId = null;

/**
 * Server reachability state. This is distinct from navigator.onLine because
 * shutting down a local server (e.g. MAMP) does not trigger the browser's
 * offline event — the NIC is still up.
 *
 * @type {boolean}
 */
let _serverReachable = true;

/**
 * Whether the browser NIC reports online. Used in conjunction with
 * _serverReachable for the aggregate "online" status.
 *
 * @type {boolean}
 */
let _browserOnline = typeof navigator !== 'undefined' ? navigator.onLine !== false : true;

/**
 * Generate a unique ID for a queue entry.
 *
 * @param {string} type Operation type.
 * @return {string}
 */
const _makeId = (type) => `${type}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

/**
 * Get the current Moodle session key.
 *
 * @return {string}
 */
const _getSesskey = () => {
    try {
        return window.M?.cfg?.sesskey || '';
    } catch (e) {
        return '';
    }
};

/**
 * Aggregate online check: both browser NIC and server must be reachable.
 *
 * @return {boolean}
 */
const _isOnline = () => _browserOnline && _serverReachable;

/**
 * Remove the queued entries of a type and key.
 *
 * An entry whose retry is already running finishes; it is no longer in the
 * queue, so it is neither retried again nor counted.
 *
 * @param {string} type Operation type label.
 * @param {string} key What the payload saves.
 * @return {boolean} Whether any entry was removed.
 */
const _removeEntries = (type, key) => {
    let removed = false;
    for (let i = _queue.length - 1; i >= 0; i--) {
        if (_queue[i].type === type && _queue[i].key === key) {
            _queue.splice(i, 1);
            removed = true;
        }
    }
    return removed;
};

/**
 * Notify all status change listeners.
 */
const _notifyListeners = () => {
    const queueLength = _queue.length;
    const online = _isOnline();
    _listeners.forEach(fn => {
        try {
            fn(queueLength, online);
        } catch (e) {
            window.console.warn('[save_queue] Listener error:', e);
        }
    });
};

/**
 * Probe the Moodle server to check if it is reachable. Uses a lightweight
 * HTTP HEAD request to the Moodle wwwroot. Any response (including redirects
 * or error pages) indicates the server is up; only a network failure (fetch
 * throws) means it is truly unreachable.
 *
 * @return {Promise<boolean>} True if the server responded.
 */
const _probeServer = async() => {
    try {
        const url = window.M?.cfg?.wwwroot || window.location.origin;
        await fetch(url, {method: 'HEAD', cache: 'no-store'});
        return true;
    } catch (e) {
        return false;
    }
};

/**
 * Process the retry tick — probe server and attempt queued saves that are due.
 */
const _tick = async() => {
    // Nothing to do: queue is empty and server is reachable.
    if (_queue.length === 0 && _serverReachable) {
        return;
    }

    // If the browser says offline, don't bother probing.
    if (!_browserOnline) {
        return;
    }

    // Active server probe: check if the server is actually reachable.
    const wasReachable = _serverReachable;
    _serverReachable = await _probeServer();

    if (!wasReachable && _serverReachable) {
        window.console.info('[save_queue] Server is reachable again — processing queue');
        // Server just came back — reset backoff timers for immediate retry.
        _queue.forEach(entry => {
            entry.nextRetryAt = Date.now();
        });
    }

    if (!_serverReachable) {
        // Server still down — notify listeners but don't attempt retries.
        if (wasReachable) {
            window.console.info('[save_queue] Server unreachable — pausing retries');
        }
        _notifyListeners();
        return;
    }

    // Server is reachable. If queue is empty, just update listeners (clears offline status).
    if (_queue.length === 0) {
        _notifyListeners();
        return;
    }

    const now = Date.now();
    const currentSesskey = _getSesskey();

    // Process entries that are ready for retry.
    const ready = _queue.filter(entry => entry.nextRetryAt <= now);

    for (const entry of ready) {
        // Discard entries from a different session (sesskey changed).
        if (entry.sesskey && currentSesskey && entry.sesskey !== currentSesskey) {
            const idx = _queue.indexOf(entry);
            if (idx !== -1) {
                _queue.splice(idx, 1);
            }
            window.console.info('[save_queue] Discarded stale entry:', entry.type);
            continue;
        }

        try {
            await entry.saveFn();
            // Success — remove from queue.
            const idx = _queue.indexOf(entry);
            if (idx !== -1) {
                _queue.splice(idx, 1);
            }
            // Invoke success callback if provided.
            if (entry.onSuccessFn) {
                try {
                    entry.onSuccessFn();
                } catch (cbErr) {
                    window.console.warn('[save_queue] onSuccess callback error:', cbErr);
                }
            }
            window.console.info('[save_queue] Retry succeeded:', entry.type);
        } catch (err) {
            entry.retryCount++;
            if (entry.retryCount >= MAX_RETRIES) {
                const idx = _queue.indexOf(entry);
                if (idx !== -1) {
                    _queue.splice(idx, 1);
                }
                window.console.warn('[save_queue] Max retries reached, dropping:', entry.type, err);
            } else {
                const backoffMs = BACKOFF_SCHEDULE[Math.min(entry.retryCount, BACKOFF_SCHEDULE.length - 1)];
                entry.nextRetryAt = Date.now() + backoffMs;
                window.console.info(
                    `[save_queue] Retry ${entry.retryCount}/${MAX_RETRIES} for ${entry.type}, ` +
                    `next in ${backoffMs}ms`
                );
            }
        }
    }

    _notifyListeners();
};

/**
 * Handle browser online event.
 */
const _handleOnline = () => {
    _browserOnline = true;
    window.console.info('[save_queue] Browser online');
    // Probe server immediately to check actual reachability.
    _tick();
};

/**
 * Handle browser offline event.
 */
const _handleOffline = () => {
    _browserOnline = false;
    _serverReachable = false;
    window.console.info('[save_queue] Browser offline — pausing retries');
    _notifyListeners();
};

/**
 * Enqueue a failed save operation for retry.
 *
 * An entry with a key replaces a queued entry of the same type and key: the
 * newer payload is the whole of what that key saves, and replaying the older one
 * after it would put stale data back.
 *
 * @param {string} type Operation type label (e.g. 'saveGrade', 'saveAnnotations').
 * @param {Function} saveFn Async function that performs the save. Called with no arguments.
 * @param {Function} [onSuccessFn] Optional callback invoked after a successful retry.
 * @param {string|null} [key] What the payload saves, for replacing and discarding entries.
 */
export const enqueue = (type, saveFn, onSuccessFn, key = null) => {
    // Mark server as unreachable since a save just failed.
    _serverReachable = false;

    if (key !== null) {
        _removeEntries(type, key);
    }
    _queue.push({
        id: _makeId(type),
        type,
        saveFn,
        onSuccessFn: onSuccessFn || null,
        retryCount: 0,
        nextRetryAt: Date.now() + BACKOFF_SCHEDULE[0],
        sesskey: _getSesskey(),
        key,
    });
    window.console.info('[save_queue] Enqueued:', type, '(queue size:', _queue.length + ')');
    _notifyListeners();
};

/**
 * Drop the queued entries of a type and key.
 *
 * For a payload the server has since stored by another route: a later save
 * of the same key carried newer data, which a replay would overwrite.
 *
 * @param {string} type Operation type label.
 * @param {string} key What the payload saves.
 */
export const discard = (type, key) => {
    if (_removeEntries(type, key)) {
        _notifyListeners();
    }
};

/**
 * Start the retry tick loop and register event listeners.
 */
export const start = () => {
    if (_tickId !== null) {
        return;
    }
    _tickId = setInterval(_tick, TICK_INTERVAL);
    window.addEventListener('online', _handleOnline);
    window.addEventListener('offline', _handleOffline);
};

/**
 * Stop the retry tick loop and remove event listeners.
 */
export const stop = () => {
    if (_tickId !== null) {
        clearInterval(_tickId);
        _tickId = null;
    }
    window.removeEventListener('online', _handleOnline);
    window.removeEventListener('offline', _handleOffline);
};

/**
 * Get the number of pending retry operations.
 *
 * @return {number}
 */
export const getQueueLength = () => _queue.length;

/**
 * Check whether the server is considered reachable.
 *
 * @return {boolean}
 */
export const isOnline = () => _isOnline();

/**
 * Register a listener for queue and connectivity status changes.
 *
 * @param {Function} callback Called with (queueLength: number, isOnline: boolean).
 * @return {Function} Unsubscribe function.
 */
export const onStatusChange = (callback) => {
    _listeners.add(callback);
    return () => _listeners.delete(callback);
};
