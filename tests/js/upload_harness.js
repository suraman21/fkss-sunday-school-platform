'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const path = require('node:path');

function makeHarness(options = {}) {
  const timers = new Map(), intervals = new Map();
  let seq = 0, now = Date.now();
  const backend = options.backend || { sessions: new Map() };
  const storage = options.storage || new Map();
  const h = { calls: [], pending: [], errors: [], backend, storage, hook: null, defer: false,
    ignoreAbort: false, published: 0, inputBindings: 0 };
  const nodes = {};
  for (const id of ['upload-idle-state', 'upload-stage-state', 'upload-progress-state',
    'upload-assembling-state', 'upload-success-state', 'upload-error-state', 'upload-error-message',
    'upload-status-subtext', 'upload-pct-display', 'upload-progress-fill', 'upload-bytes-display',
    'upload-chunk-display', 'upload-speed-display', 'upload-eta-display', 'upload-apk-input', 'upload-abi-select']) {
    nodes[id] = { style: {}, textContent: '', value: '', classList: { add() {}, remove() {} },
      addEventListener(event) { if (id === 'upload-apk-input' && event === 'change') h.inputBindings++; } };
  }
  nodes['upload-abi-select'].value = 'universal';
  h.nodes = nodes;
  const document = { readyState: 'loading', addEventListener() {},
    getElementById(id) { return nodes[id] || null; },
    querySelector(query) { return query.includes('csrf_token') ? { value: 'synthetic-csrf' } : null; } };
  const window = { crypto: webcrypto, SA_BOOT: { upload_owner: 7 },
    localStorage: { getItem(k) { return storage.get(k) || null; }, setItem(k,v) { storage.set(k,v); }, removeItem(k) { storage.delete(k); } } };
  const response = (status, object) => new Response(JSON.stringify(object), { status, headers: { 'Content-Type': 'application/json' } });
  h.response = response;
  const release = { latest_version: '1.7.4', latest_build: 41, artifacts_detail: { universal: { sha256: 'a'.repeat(64) } } };
  const fetch = options.fetch || (async (url, request = {}) => {
    const form = request.body;
    const action = form ? form.get('action') : new URL(url, 'https://example.test').searchParams.get('action');
    const call = { action, form, request, url };
    h.calls.push(call);
    if (h.hook) { const custom = h.hook(call); if (custom !== undefined) return await custom; }
    if (action === 'upload_preflight') return response(200, { status: 'success', data: {
      releases_dir_writable: true, chunk_dir_writable: true,
      limits_bytes: { post_max_size: 8388608, upload_max_filesize: 2097152 } } });
    if (action === 'get_release') return response(200, { status: 'success', data: release });
    const id = form.get('upload_id');
    let session = backend.sessions.get(id);
    if (action === 'upload_status') {
      if (!session) { session = { received: new Set(), hashes: JSON.parse(form.get('part_hashes')), state: 'receiving' }; backend.sessions.set(id, session); }
      return response(200, { status: 'success', data: { verified: true, state: session.state,
        received: [...session.received], release } });
    }
    if (action === 'upload_chunk') {
      const index = Number(form.get('chunk_index'));
      const ack = () => { session.received.add(index); return response(200, { status: 'success', data: {
        chunk_index: index, size: Number(form.get('chunk_size')), sha256: session.hashes[index] } }); };
      if (!h.defer) return ack();
      return await new Promise((resolve, reject) => {
        const entry = { index, settled: false, call,
          success() { if (!entry.settled) { entry.settled = true; resolve(ack()); } },
          fail(status = 503) { if (!entry.settled) { entry.settled = true; resolve(response(status, { status: 'error', retryable: true, message: 'temporary failure' })); } },
          network() { if (!entry.settled) { entry.settled = true; reject(new TypeError('Failed to fetch')); } } };
        request.signal.addEventListener('abort', () => { if (!h.ignoreAbort && !entry.settled) { entry.settled = true; reject(new DOMException('Aborted', 'AbortError')); } });
        h.pending.push(entry);
      });
    }
    if (action === 'assemble_chunks') {
      if (session.received.size !== Number(form.get('total_chunks'))) return response(409, { status: 'error', code: 'missing_chunks', retryable: true, message: 'missing parts' });
      h.published++; session.state = 'published';
      return response(200, { status: 'success', data: release, message: 'Published' });
    }
    if (action === 'cancel_upload') { if (session && session.state !== 'published') session.state = 'cancelled'; return response(200, { status: 'success' }); }
    throw new Error('Unexpected request: ' + action);
  });
  class ClockDate extends Date { static now() { return options.realTimers ? Date.now() : now; } }
  const context = { window, document, fetch, FormData, AbortController, TextEncoder, Uint8Array, Blob, Date: ClockDate, console,
    setTimeout: options.realTimers ? setTimeout : (fn, ms) => { const id = ++seq; timers.set(id, { fn, ms }); return id; },
    clearTimeout: options.realTimers ? clearTimeout : id => timers.delete(id),
    setInterval: options.realTimers ? setInterval : (fn, ms) => { const id = ++seq; intervals.set(id, { fn, ms }); return id; },
    clearInterval: options.realTimers ? clearInterval : id => intervals.delete(id) };
  const source = fs.readFileSync(options.source || path.join(__dirname, '../../admin/js/app_release.js'), 'utf8');
  vm.createContext(context);
  vm.runInContext(source.replace('window.AppReleaseUI = AppReleaseUI;', 'window.AppReleaseUI = AppReleaseUI; window.__state = currentUpload;'), context);
  h.ui = window.AppReleaseUI; h.state = window.__state; h.window = window;
  h.ui.renderStatus = info => { h.release = info; };
  h.ui.populateForm = () => {};
  h.ui.renderSuccessState = () => {};
  h.timerCount = () => timers.size + intervals.size;
  h.fire = ms => { const found = [...timers].find(([,t]) => t.ms === ms); if (!found) throw new Error('No timer at ' + ms); timers.delete(found[0]); now += ms; found[1].fn(); };
  h.select = (parts = 4, byte = 7) => {
    const blob = new Blob([new Uint8Array(parts * 524288).fill(byte)]);
    Object.defineProperty(blob, 'name', { value: 'test.apk' });
    h.ui.onFileSelected(blob); return blob;
  };
  h.live = () => h.pending.filter(p => !p.settled);
  h.part = index => { const found = h.live().find(p => p.index === index); if (!found) throw new Error('No pending part ' + index); return found; };
  h.cleanup = () => h.ui.resetUploadState();
  return h;
}
async function until(condition, message = 'condition', count = 3000) {
  for (let i = 0; i < count; i++) { if (condition()) return; await new Promise(r => setTimeout(r, 1)); }
  throw new Error('Timed out waiting for ' + message);
}
async function flush() { for (let i = 0; i < 8; i++) await new Promise(setImmediate); }
module.exports = { makeHarness, until, flush };
