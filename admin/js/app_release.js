/**
 * Super Admin — Mobile App Release Management UI Module
 * Verified parallel part-pool APK upload engine (up to 512 KB parts,
 * 4-way sliding window, per-part deadlines/retries, resumable) with
 * Real-time State Handling & cPanel Fault-Tolerance.
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_app_release.php';
  // Bounded binary part pool; concurrency decreases after transient failures.
  var PART_SIZE = 512 * 1024;       // 512 KB — Telegram's recommended part quantum
  var POOL_SIZE = 4;                // initial maximum parallel requests
  var PART_DEADLINE_MS = 45000;     // a stalled part is aborted and retried alone
  var MAX_PART_RETRIES = 8;
  var WATCHDOG_MS = 60000;          // no ack at all in this window => visible diagnostics

  // Upload State Machine
  var currentUpload = {
    state: 'idle', // 'idle' | 'selected' | 'preparing' | 'uploading' | 'assembling' | 'success' | 'error'
    file: null,
    abi: 'universal',
    uploadId: null,
    totalChunks: 0,
    ackedBytes: 0,
    ackedCount: 0,
    attempt: null
  };

  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
  }

  function formatDuration(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return 'calculating...';
    if (seconds < 60) return Math.ceil(seconds) + 's';
    var mins = Math.floor(seconds / 60);
    var secs = Math.ceil(seconds % 60);
    return mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
  }

  function showAlert(type, message) {
    var box = document.getElementById('app-release-alert-box');
    if (!box) return;
    var icon = type === 'success' ? 'check-circle' : (type === 'warn' ? 'triangle-exclamation' : 'circle-exclamation');
    box.innerHTML = '<div class="alert alert-' + type + '" style="margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;border-radius:0.5rem;padding:0.85rem 1.25rem">' +
      '<i class="fa-solid fa-' + icon + '"></i> <span>' + escapeHtml(message) + '</span>' +
      '</div>';
    setTimeout(function () {
      if (box.innerHTML.indexOf(message) !== -1) {
        box.innerHTML = '';
      }
    }, 8000);
  }

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    if (input && input.value) return input.value;
    if (window.SA_BOOT && window.SA_BOOT.csrf) return window.SA_BOOT.csrf;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    return '';
  }

  function generateUploadId() {
    var rand = Math.random().toString(36).substring(2, 12) + Math.random().toString(36).substring(2, 12);
    return 'apk_' + Date.now() + '_' + rand;
  }

  var AppReleaseUI = {
    init: function () {
      this.bindDropZone();
      this.refresh();
    },

    bindDropZone: function () {
      var dropZone = document.getElementById('upload-idle-state') || document.querySelector('.apk-drop-box');
      var fileInput = document.getElementById('upload-apk-input');

      if (dropZone && !dropZone._apkUploadBound) {
        dropZone._apkUploadBound = true;
        ['dragenter', 'dragover'].forEach(function (eventName) {
          dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('drag-active');
          }, false);
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
          dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('drag-active');
          }, false);
        });

        dropZone.addEventListener('drop', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var dt = e.dataTransfer;
          if (dt && dt.files && dt.files.length > 0) {
            if (fileInput) {
              fileInput.files = dt.files;
            }
            AppReleaseUI.onFileSelected(dt.files[0]);
          }
        }, false);
      }

      if (fileInput && !fileInput._apkUploadBound) {
        fileInput._apkUploadBound = true;
        fileInput.addEventListener('change', function () {
          if (fileInput.files && fileInput.files[0]) {
            AppReleaseUI.onFileSelected(fileInput.files[0]);
          }
        }, false);
      }
    },

    onFileInputChange: function (input) {
      if (input && input.files && input.files[0]) {
        this.onFileSelected(input.files[0]);
      }
    },

    onAbiChange: function (val) {
      if (currentUpload.attempt) {
        var select = document.getElementById('upload-abi-select');
        if (select) select.value = currentUpload.abi;
        return;
      }
      currentUpload.abi = val || 'universal';
      var abiEl = document.getElementById('stage-file-abi');
      if (abiEl) {
        abiEl.textContent = currentUpload.abi === 'universal' ? 'Universal APK' : currentUpload.abi;
      }
    },

    onFileSelected: function (file) {
      if (!file) return;
      if (currentUpload.attempt) { showAlert('warn', 'Stop this upload before selecting another APK.'); return; }

      if (!file.name.toLowerCase().endsWith('.apk')) {
        showAlert('danger', 'Invalid file type. Only Android APK binaries (.apk) are supported.');
        var fileInput = document.getElementById('upload-apk-input');
        if (fileInput) fileInput.value = '';
        return;
      }

      if (file.size > 536870912) { // 512 MiB
        showAlert('danger', 'APK file exceeds the maximum 512 MiB limit.');
        return;
      }

      currentUpload.file = file;
      currentUpload.state = 'selected';
      currentUpload.totalChunks = Math.max(1, Math.ceil(file.size / PART_SIZE));
      currentUpload.uploadId = generateUploadId();

      var abiSelect = document.getElementById('upload-abi-select');
      if (abiSelect) {
        currentUpload.abi = abiSelect.value || 'universal';
      }

      this.renderUploadUI();
    },

    renderUploadUI: function () {
      var idleBox = document.getElementById('upload-idle-state');
      var stageBox = document.getElementById('upload-stage-state');
      var progressBox = document.getElementById('upload-progress-state');
      var assemblingBox = document.getElementById('upload-assembling-state');
      var successBox = document.getElementById('upload-success-state');
      var errorBox = document.getElementById('upload-error-state');

      var all = [idleBox, stageBox, progressBox, assemblingBox, successBox, errorBox];
      all.forEach(function (el) {
        if (el) el.style.display = 'none';
      });

      switch (currentUpload.state) {
        case 'idle':
          if (idleBox) idleBox.style.display = 'block';
          break;

        case 'selected':
          if (stageBox) {
            stageBox.style.display = 'block';
            var nameEl = document.getElementById('stage-file-name');
            var sizeEl = document.getElementById('stage-file-size');
            var chunksEl = document.getElementById('stage-file-chunks');
            var abiEl = document.getElementById('stage-file-abi');

            if (nameEl) nameEl.textContent = currentUpload.file ? currentUpload.file.name : '';
            if (sizeEl) sizeEl.textContent = currentUpload.file ? formatBytes(currentUpload.file.size) : '';
            if (chunksEl) chunksEl.textContent = currentUpload.totalChunks + ' parts (' + formatBytes(PART_SIZE) + ' / part)';
            if (abiEl) abiEl.textContent = currentUpload.abi === 'universal' ? 'Universal APK' : currentUpload.abi;
          }
          break;

        case 'preparing':
        case 'uploading':
          if (progressBox) progressBox.style.display = 'block';
          break;

        case 'assembling':
          if (assemblingBox) {
            assemblingBox.style.display = 'block';
            var totalChunksText = document.getElementById('assemble-chunks-count');
            if (totalChunksText) totalChunksText.textContent = currentUpload.totalChunks;
          }
          break;

        case 'success':
          if (successBox) successBox.style.display = 'block';
          break;

        case 'error':
          if (errorBox) errorBox.style.display = 'block';
          break;
      }
    },

    // Each attempt owns its requests, counters and timers. Never let old callbacks
    // read a newly selected file or decrement a later attempt's counters.
    isCurrent: function (ctx) { return currentUpload.attempt === ctx && !ctx.stopped; },

    stopAttempt: function () {
      var ctx = currentUpload.attempt;
      if (!ctx) return;
      ctx.stopped = true;
      Object.keys(ctx.controllers).forEach(function (k) { ctx.controllers[k].abort(); });
      Object.keys(ctx.timers).forEach(function (k) { clearTimeout(Number(k)); });
      ctx.waiters.splice(0).forEach(function (reject) { reject({ stale: true }); });
      if (ctx.watchdog) clearInterval(ctx.watchdog);
      currentUpload.attempt = null;
    },

    storageKey: function () {
      return 'fkss-apk-upload:v2:' + String((window.SA_BOOT && window.SA_BOOT.upload_owner) || 0);
    },

    readResume: function () {
      var value = currentUpload.resume || null;
      try { value = JSON.parse(window.localStorage.getItem(this.storageKey()) || 'null') || value; }
      catch (e) { /* Storage disabled: retain same-page recovery in memory. */ }
      if (value && /^[a-zA-Z0-9_-]{8,64}$/.test(value.id) && Date.now() - value.savedAt < 14400000 && value.savedAt <= Date.now()) return value;
      return null;
    },

    saveResume: function (ctx, force) {
      if (!this.isCurrent(ctx) || !ctx.fingerprint || (!force && Date.now() - ctx.savedAt < 5000)) return;
      ctx.savedAt = Date.now();
      currentUpload.resume = { id: ctx.id, fingerprint: ctx.fingerprint,
        size: ctx.file.size, partSize: ctx.partSize, abi: ctx.abi, savedAt: ctx.savedAt };
      try { window.localStorage.setItem(this.storageKey(), JSON.stringify(currentUpload.resume)); }
      catch (e) { /* No APK bytes, filenames, CSRF tokens or session cookies stored. */ }
    },

    clearResume: function () {
      currentUpload.resume = null;
      try { window.localStorage.removeItem(this.storageKey()); } catch (e) {}
    },

    wait: function (ctx, ms) {
      var self = this;
      return new Promise(function (resolve, reject) {
        if (!self.isCurrent(ctx)) { reject({ stale: true }); return; }
        ctx.waiters.push(reject);
        var timer = setTimeout(function () {
          delete ctx.timers[timer];
          ctx.waiters = ctx.waiters.filter(function (r) { return r !== reject; });
          if (!self.isCurrent(ctx)) reject({ stale: true }); else resolve();
        }, ms);
        ctx.timers[timer] = true;
      });
    },

    requestJson: async function (ctx, action, values, timeout) {
      if (!this.isCurrent(ctx)) throw { stale: true };
      var controller = new AbortController(), id = ++ctx.requestSeq;
      ctx.controllers[id] = controller;
      var timer = setTimeout(function () { controller.abort(); }, timeout || PART_DEADLINE_MS);
      ctx.timers[timer] = true;
      var form = new FormData();
      form.append('action', action);
      form.append('csrf_token', getCsrfToken());
      Object.keys(values).forEach(function (k) {
        var v = values[k];
        if (k === 'chunk_file') form.append(k, v, 'part.bin'); else form.append(k, String(v));
      });
      try {
        var response = await fetch(API_URL + '?action=' + encodeURIComponent(action), {
          method: 'POST', credentials: 'same-origin', redirect: 'error',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
          body: form, signal: controller.signal
        });
        if (!this.isCurrent(ctx)) throw { stale: true };
        var json;
        try { json = JSON.parse(await response.text()); }
        catch (parseError) {
          throw { uploadError: true, status: response.status,
            retryable: response.status >= 500 || response.status === 408 || response.status === 429,
            message: 'HTTP ' + response.status + ' during ' + action + ': server returned a non-JSON response. Check hosting/proxy logs.' };
        }
        if (!this.isCurrent(ctx)) throw { stale: true };
        if (!json || typeof json !== 'object' || Array.isArray(json)) json = {};
        if (!response.ok || json.status !== 'success') {
          var retryable = response.status >= 500 || [408, 423, 429].indexOf(response.status) !== -1;
          // Authentication/CSRF/size errors are not repaired by re-encoding a request.
          if ([400, 401, 403, 404, 413, 415, 422].indexOf(response.status) !== -1) retryable = false;
          else if (typeof json.retryable === 'boolean') retryable = json.retryable;
          throw { uploadError: true, status: response.status, code: json.code,
            retryable: retryable, details: json.details || {},
            message: 'HTTP ' + response.status + ': ' + (json.message || 'Server rejected ' + action) };
        }
        return json;
      } catch (error) {
        if (!this.isCurrent(ctx) || error.stale) throw { stale: true };
        if (error.uploadError) throw error;
        throw { uploadError: true, retryable: true, status: 0,
          message: error.name === 'AbortError' ? action + ' timed out; saved parts are retained.'
            : 'Network failure during ' + action + ' (' + (error.message || 'Failed to fetch') + '). No usable HTTP response; saved parts are retained.' };
      } finally {
        clearTimeout(timer); delete ctx.timers[timer]; delete ctx.controllers[id];
      }
    },

    retryJson: async function (ctx, action, values, attempts, timeout) {
      for (var attempt = 0; ; attempt++) {
        try { return await this.requestJson(ctx, action, values, timeout); }
        catch (error) {
          if (error.stale || !error.retryable || attempt >= attempts) throw error;
          this.noteRetry(action + ': ' + error.message);
          await this.wait(ctx, Math.min(8000, 700 * Math.pow(1.6, attempt)));
        }
      }
    },

    startUpload: async function () {
      if (!currentUpload.file || ['preparing', 'uploading', 'assembling'].indexOf(currentUpload.state) !== -1) return;
      this.stopAttempt();
      var ctx = { file: currentUpload.file, abi: currentUpload.abi, id: currentUpload.uploadId,
        controllers: {}, timers: {}, waiters: [], requestSeq: 0, pending: {}, queue: [],
        active: {}, acked: {}, retries: {}, count: 0, bytes: 0, inFlight: 0, next: 0,
        parallel: POOL_SIZE, stopped: false, savedAt: 0, recoveries: 0,
        lastAck: Date.now(), speedAt: Date.now(), speedBytes: 0, speed: 0 };
      currentUpload.attempt = ctx;
      currentUpload.state = 'preparing'; this.renderUploadUI();
      var percentage = document.getElementById('upload-pct-display');
      if (percentage) percentage.textContent = 'Checking…';
      this.setTransferLabel('Checking hosting limits and fingerprinting the APK for verified resume…');
      try {
        if (!window.crypto || !window.crypto.subtle || typeof AbortController === 'undefined') {
          throw { message: 'Verified uploads require HTTPS and a modern browser with Web Crypto and AbortController.', retryable: false };
        }
        var preflight = await this.retryJson(ctx, 'upload_preflight', {}, 2);
        var info = preflight.data;
        if (!info || !info.releases_dir_writable || !info.chunk_dir_writable) throw { message: 'Upload storage is not writable. Ask hosting support to check directory permissions and account quota.' };
        var limits = info.limits_bytes || {}, safe = PART_SIZE;
        if (limits.post_max_size > 0) safe = Math.min(safe, Math.floor(limits.post_max_size * 0.55));
        if (limits.upload_max_filesize > 0) safe = Math.min(safe, Math.floor(limits.upload_max_filesize * 0.8));
        safe = Math.floor(safe / 65536) * 65536;
        if (safe < 65536) throw { message: 'Hosting request limits are too small for reliable APK uploads. Ask hosting support to review PHP/proxy limits.' };
        var saved = this.readResume();
        ctx.partSize = saved && saved.size === ctx.file.size && saved.abi === ctx.abi && saved.partSize >= 65536 && saved.partSize <= safe ? saved.partSize : safe;
        ctx.total = Math.ceil(ctx.file.size / ctx.partSize);
        if (ctx.total < 1 || ctx.total > 8192) throw { message: 'Invalid APK size or number of parts.' };
        ctx.hashes = [];
        // Hash one bounded part at a time, not a whole-APK array buffer.
        for (var i = 0; i < ctx.total; i++) {
          var buffer = await ctx.file.slice(i * ctx.partSize, (i + 1) * ctx.partSize).arrayBuffer();
          var hash = await window.crypto.subtle.digest('SHA-256', buffer);
          if (!this.isCurrent(ctx)) return;
          ctx.hashes.push(this.digestHex(hash));
          this.setTransferLabel('Verifying file identity: part ' + (i + 1) + ' / ' + ctx.total + ' (no file bytes stored in the browser).');
        }
        ctx.fingerprint = this.digestHex(await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(
          ctx.file.size + ':' + ctx.partSize + ':' + ctx.abi + ':' + ctx.hashes.join(''))));
        if (!this.isCurrent(ctx)) return;
        ctx.id = saved && saved.fingerprint === ctx.fingerprint ? saved.id : generateUploadId();
        currentUpload.uploadId = ctx.id; currentUpload.totalChunks = ctx.total;
        this.saveResume(ctx, true);
        await this.beginTransfer(ctx);
      } catch (error) {
        if (!error.stale && this.isCurrent(ctx)) this.showUploadError(error.message || 'Could not prepare upload.');
      }
    },

    digestHex: function (buffer) {
      return Array.from(new Uint8Array(buffer)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    },

    statusValues: function (ctx) {
      return { upload_id: ctx.id, total_chunks: ctx.total, total_size: ctx.file.size,
        fingerprint: ctx.fingerprint, part_size: ctx.partSize,
        part_hashes: JSON.stringify(ctx.hashes), abi: ctx.abi };
    },

    beginTransfer: async function (ctx) {
      // Never turn an unreadable/failed status response into a full-file restart.
      var result = await this.retryJson(ctx, 'upload_status', this.statusValues(ctx), 3);
      if (!this.isCurrent(ctx)) return;
      var data = result.data;
      if (!data || data.verified !== true) throw { message: 'Server upload protocol is not current. Refresh after the development deployment completes.' };
      if (data.state === 'published') { this.completeUpload(ctx, { data: data.release, message: 'Publication confirmed from its saved receipt.' }); return; }
      if (!Array.isArray(data.received) || data.state !== 'receiving') throw { message: 'Server returned invalid upload progress; no parts were resent.' };
      ctx.acked = {}; ctx.count = 0; ctx.bytes = 0; ctx.next = 0; ctx.queue = [];
      for (var i = 0; i < data.received.length; i++) {
        var idx = data.received[i];
        if (!Number.isInteger(idx) || idx < 0 || idx >= ctx.total || ctx.acked[idx]) throw { message: 'Server returned invalid chunk indices.' };
        ctx.acked[idx] = true; ctx.count++; ctx.bytes += this.partBytes(idx, ctx);
      }
      ctx.speedBytes = ctx.bytes; ctx.speedAt = Date.now(); ctx.lastAck = Date.now();
      currentUpload.state = 'uploading'; this.renderUploadUI(); this.paintProgress(ctx);
      this.setTransferLabel('Verified ' + ctx.count + ' / ' + ctx.total + ' saved parts. Sending only missing parts.');
      this.startWorkers(ctx);
    },

    partBytes: function (idx, ctx) {
      ctx = ctx || currentUpload.attempt;
      return Math.max(0, Math.min(ctx.partSize, ctx.file.size - idx * ctx.partSize));
    },

    startWorkers: function (ctx) { this.startWatchdog(ctx); this.workerLoop(ctx); },

    workerLoop: function (ctx) {
      ctx = ctx || currentUpload.attempt;
      if (!ctx || !this.isCurrent(ctx) || currentUpload.state !== 'uploading') return;
      while (ctx.inFlight < ctx.parallel) {
        var idx = -1;
        while (ctx.queue.length) { var queued = ctx.queue.shift(); if (!ctx.acked[queued] && !ctx.active[queued]) { idx = queued; break; } }
        while (idx < 0 && ctx.next < ctx.total) { var next = ctx.next++; if (!ctx.acked[next] && !ctx.active[next] && !ctx.pending[next]) idx = next; }
        if (idx < 0) break;
        this.sendPart(idx, ctx);
      }
      // Allocation exhaustion and zero active requests do NOT imply success.
      if (ctx.count === ctx.total && ctx.inFlight === 0 && Object.keys(ctx.pending).length === 0) this.assembleChunksOnServer(ctx);
    },

    sendPart: async function (idx, ctx) {
      if (!this.isCurrent(ctx) || ctx.active[idx]) return;
      ctx.inFlight++; ctx.active[idx] = true;
      var values = { upload_id: ctx.id, chunk_index: idx, total_chunks: ctx.total,
        total_size: ctx.file.size, chunk_size: this.partBytes(idx, ctx),
        chunk_file: ctx.file.slice(idx * ctx.partSize, (idx + 1) * ctx.partSize) };
      try {
        // Keep slow-link retries bounded without repeating the same short deadline.
        var deadline = Math.min(300000, PART_DEADLINE_MS * Math.pow(2, ctx.retries[idx] || 0));
        var result = await this.requestJson(ctx, 'upload_chunk', values, deadline);
        if (!this.isCurrent(ctx)) return;
        if (!result.data || result.data.chunk_index !== idx || result.data.size !== this.partBytes(idx, ctx) || result.data.sha256 !== ctx.hashes[idx]) {
          throw { message: 'Server acknowledgment did not match part ' + (idx + 1) + '. No success was assumed.', retryable: false };
        }
        this.markPartAcked(idx, ctx);
      } catch (error) {
        if (!error.stale && this.isCurrent(ctx)) this.handlePartFailure(idx, error, ctx);
      } finally {
        ctx.inFlight--; delete ctx.active[idx];
        if (this.isCurrent(ctx)) this.workerLoop(ctx);
      }
    },

    markPartAcked: function (idx, ctx) {
      if (!this.isCurrent(ctx) || ctx.acked[idx]) return;
      ctx.acked[idx] = true; ctx.count++; ctx.bytes += this.partBytes(idx, ctx); ctx.lastAck = Date.now();
      var seconds = (Date.now() - ctx.speedAt) / 1000;
      if (seconds >= 0.5) { ctx.speed = (ctx.bytes - ctx.speedBytes) / seconds; ctx.speedBytes = ctx.bytes; ctx.speedAt = Date.now(); }
      this.saveResume(ctx, false); this.paintProgress(ctx);
    },

    handlePartFailure: function (idx, error, ctx) {
      var retries = (ctx.retries[idx] || 0) + 1; ctx.retries[idx] = retries;
      if (!error.retryable || retries > MAX_PART_RETRIES) {
        this.showUploadError('Upload stopped on part ' + (idx + 1) + ' of ' + ctx.total + '. ' + error.message +
          ' Retry Upload will verify server progress before sending anything.');
        return;
      }
      // Reduce pressure after any transient rejection, including Failed to fetch.
      ctx.parallel = Math.max(1, Math.floor(ctx.parallel / 2));
      this.noteRetry('part ' + (idx + 1) + ': ' + error.message + ' (retry ' + retries + '/' + MAX_PART_RETRIES + ', ' + ctx.parallel + ' connection(s))');
      var self = this, delay = Math.min(8000, 700 * Math.pow(1.6, retries - 1));
      var timer = setTimeout(function () {
        delete ctx.timers[timer]; delete ctx.pending[idx];
        if (!self.isCurrent(ctx)) return;
        ctx.queue.push(idx); self.workerLoop(ctx);
      }, delay);
      ctx.timers[timer] = true; ctx.pending[idx] = timer;
    },

    paintProgress: function (ctx) {
      if (!this.isCurrent(ctx)) return;
      currentUpload.ackedBytes = ctx.bytes; currentUpload.ackedCount = ctx.count;
      var pct = Math.min(99, Math.round(ctx.bytes / ctx.file.size * 100));
      this.updateProgressDisplay(pct, ctx.bytes, ctx.file.size, ctx.count, ctx.total, ctx.speed,
        ctx.speed > 0 ? (ctx.file.size - ctx.bytes) / ctx.speed : 0);
      this.setTransferLabel('Verified parts ' + ctx.count + '/' + ctx.total + ' · ' + ctx.inFlight + ' in flight · ' + Object.keys(ctx.pending).length + ' waiting to retry');
    },

    setTransferLabel: function (text) {
      var label = document.getElementById('upload-status-subtext');
      if (label) label.textContent = text;
    },

    noteRetry: function (message) { this.setTransferLabel('Retrying: ' + message); },

    startWatchdog: function (ctx) {
      var self = this;
      if (ctx.watchdog) clearInterval(ctx.watchdog);
      ctx.watchdog = setInterval(function () {
        if (!self.isCurrent(ctx) || currentUpload.state !== 'uploading') { clearInterval(ctx.watchdog); return; }
        if (Date.now() - ctx.lastAck > WATCHDOG_MS) self.setTransferLabel('No verified part for ' + Math.round((Date.now() - ctx.lastAck) / 1000) + 's. Requests have bounded deadlines; saved parts are retained.');
      }, 5000);
    },

    updateProgressDisplay: function (pct, loadedBytes, totalBytes, chunkNum, totalChunks, speedBps, etaSeconds) {
      var fill = document.getElementById('upload-progress-fill'); if (fill) fill.style.width = pct + '%';
      var values = { 'upload-pct-display': pct + '%', 'upload-bytes-display': formatBytes(loadedBytes) + ' / ' + formatBytes(totalBytes),
        'upload-chunk-display': 'Part ' + chunkNum + ' / ' + totalChunks,
        'upload-speed-display': formatBytes(speedBps) + '/s', 'upload-eta-display': formatDuration(etaSeconds) };
      Object.keys(values).forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = values[id]; });
    },

    assembleChunksOnServer: async function (ctx) {
      if (!this.isCurrent(ctx) || currentUpload.state !== 'uploading' || ctx.count !== ctx.total || ctx.inFlight || Object.keys(ctx.pending).length) return;
      currentUpload.state = 'assembling'; this.renderUploadUI();
      try {
        var result = await this.requestJson(ctx, 'assemble_chunks', { upload_id: ctx.id,
          total_chunks: ctx.total, total_size: ctx.file.size, file_name: ctx.file.name, abi: ctx.abi }, 180000);
        if (this.isCurrent(ctx)) this.completeUpload(ctx, result);
      } catch (error) {
        if (!this.isCurrent(ctx) || error.stale) return;
        if (!error.retryable) { this.showUploadError(error.message); return; }
        // Lost final response is ambiguous, not proof of failed publication.
        // A receipt confirms commit; otherwise reconcile exact server parts.
        if (++ctx.recoveries > 2) { this.showUploadError('Publication outcome could not be confirmed. ' + error.message + ' Retry checks the saved receipt first.'); return; }
        try { await this.wait(ctx, 1200); await this.beginTransfer(ctx); }
        catch (statusError) { if (this.isCurrent(ctx)) this.showUploadError('Could not confirm publication: ' + statusError.message + ' Retry checks server status; do not delete the published APK.'); }
      }
    },

    completeUpload: function (ctx, result) {
      if (!this.isCurrent(ctx)) return;
      var artifact = result.data && result.data.artifacts_detail && result.data.artifacts_detail[ctx.abi];
      if (!artifact || !/^[a-f0-9]{64}$/.test(artifact.sha256)) throw { message: 'Server did not return a valid publication receipt. Retry checks server status.', retryable: false };
      this.clearResume(); this.stopAttempt();
      currentUpload.state = 'success'; this.renderUploadUI();
      this.renderSuccessState(result.data, ctx.file.name, ctx.file.size);
      this.renderStatus(result.data); this.populateForm(result.data);
      showAlert(result.audit_logged === false ? 'warn' : 'success', result.message || 'APK publication confirmed.');
    },

    renderSuccessState: function (data, filename, sizeBytes) {
      var nameEl = document.getElementById('success-apk-name');
      var sizeEl = document.getElementById('success-apk-size');
      var shaEl = document.getElementById('success-apk-sha');
      var verEl = document.getElementById('success-apk-version');

      if (nameEl) nameEl.textContent = filename || 'fkss.apk';
      if (sizeEl) sizeEl.textContent = formatBytes(sizeBytes);
      if (verEl && data) verEl.textContent = 'v' + data.latest_version + ' (Build ' + data.latest_build + ')';
      
      var sha = '';
      if (data && data.artifacts_detail && data.artifacts_detail[currentUpload.abi]) {
        sha = data.artifacts_detail[currentUpload.abi].sha256;
      }
      if (shaEl) shaEl.textContent = sha ? ('SHA-256: ' + sha) : '';
    },

    showUploadError: function (msg) {
      if (currentUpload.attempt) this.saveResume(currentUpload.attempt, true);
      this.stopAttempt();
      currentUpload.state = 'error';
      this.renderUploadUI();
      var errEl = document.getElementById('upload-error-message');
      if (errEl) errEl.textContent = msg;
      showAlert('danger', msg);
    },

    cancelUpload: function (confirmed) {
      if (confirmed !== true && window.DangerConfirm && window.DangerConfirm.ask) {
        window.DangerConfirm.ask({ tier: 'low', title: 'Cancel this APK upload?', summary: 'The parts uploaded so far are discarded. You can start the upload again.', confirmLabel: 'Cancel upload' })
          .then(function (r) { if (r) AppReleaseUI.cancelUpload(true); });
        return;
      }
      var id = currentUpload.uploadId;
      this.stopAttempt();
      this.clearResume();
      if (id) {
        var formData = new FormData();
        formData.append('action', 'cancel_upload'); formData.append('upload_id', id);
        formData.append('csrf_token', getCsrfToken());
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 15000);
        fetch(API_URL + '?action=cancel_upload', { method: 'POST', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
          body: formData, signal: controller.signal })
          .then(function (response) {
            if (!response.ok) throw new Error('Cancel not acknowledged');
          }).catch(function () {
            showAlert('warn', 'Transfer stopped locally. Server cleanup could not be confirmed; temporary parts expire automatically.');
          }).finally(function () { clearTimeout(timer); });
      }

      this.resetUploadState();
    },

    resetUploadState: function () {
      this.stopAttempt(); // Reset stops local work but deliberately retains resumable parts.
      currentUpload.state = 'idle';
      currentUpload.file = null;
      currentUpload.uploadId = null;
      currentUpload.ackedBytes = 0;
      currentUpload.ackedCount = 0;

      var fileInput = document.getElementById('upload-apk-input');
      if (fileInput) fileInput.value = '';

      this.renderUploadUI();
    },

    refresh: function () {
      var container = document.getElementById('app-release-status-container');
      fetch(API_URL + '?action=get_release', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success' && res.data) {
          AppReleaseUI.renderStatus(res.data);
          AppReleaseUI.populateForm(res.data);
        } else {
          if (container && container.innerHTML.indexOf('Version') === -1) {
            container.innerHTML = '<div style="color:#f87171;padding:1.5rem;font-size:.875rem;background:#0f172a;border-radius:0.5rem;border:1px solid rgba(239,68,68,0.3)">' +
              '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(res.message || 'Failed to load release info.') + '</div>';
          }
        }
      })
      .catch(function (err) {
        if (container && container.innerHTML.indexOf('Version') === -1) {
          container.innerHTML = '<div style="color:#f87171;padding:1.5rem;font-size:.875rem;background:#0f172a;border-radius:0.5rem;border:1px solid rgba(239,68,68,0.3)">' +
            '<i class="fa-solid fa-triangle-exclamation"></i> Network error loading release info.</div>';
        }
      });
    },

    renderStatus: function (data) {
      var container = document.getElementById('app-release-status-container');
      if (!container) return;

      var hasUniversal = data.artifacts_detail && data.artifacts_detail.universal;
      var hasArm64 = data.artifacts_detail && data.artifacts_detail['arm64-v8a'];
      var hasArm32 = data.artifacts_detail && data.artifacts_detail['armeabi-v7a'];

      var html = '<div style="display:flex;flex-direction:column;gap:1rem">';
      
      // Version status banner
      html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#0f172a;border-radius:0.5rem;border:1px solid #334155;flex-wrap:wrap;gap:0.75rem">' +
        '<div>' +
          '<div style="font-size:1.2rem;font-weight:700;color:#f8fafc;letter-spacing:0.02em">Version ' + escapeHtml(data.latest_version) + ' <span style="font-size:.85rem;color:#94a3b8;font-weight:400">(Build ' + escapeHtml(data.latest_build) + ')</span></div>' +
          '<div style="font-size:.775rem;color:#cbd5e1;margin-top:.3rem">Min required: v' + escapeHtml(data.min_version) + ' (Build ' + escapeHtml(data.min_build) + ')' +
          (data.force_update ? ' • <span style="color:#f87171;font-weight:700"><i class="fa-solid fa-triangle-exclamation"></i> Mandatory Update Gate</span>' : '') + '</div>' +
          (data.apk_package ? '<div style="font-size:.75rem;color:#94a3b8;margin-top:.3rem">App in the file: <code>' + escapeHtml(data.apk_package) + '</code></div>' : '') +
          this._renderPhonePolicy(data) +
        '</div>' +
        '<div>' +
          (data.download_available
            ? '<span style="background:rgba(16,185,129,0.15);color:#4ade80;border:1px solid rgba(16,185,129,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-check"></i> Published</span>'
            : '<span style="background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.4);padding:.4rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:.35rem"><i class="fa-solid fa-circle-xmark"></i> No APK Uploaded</span>') +
        '</div>' +
      '</div>';

      // Active artifacts list
      html += '<div style="margin-top:.25rem">';
      html += '<div style="font-size:.75rem;font-weight:700;color:#94a3b8;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:0.75px">Active Artifacts</div>';

      if (!hasUniversal && !hasArm64 && !hasArm32) {
        html += '<div style="padding:1rem;background:#0f172a;border:1px dashed #334155;border-radius:0.5rem;font-size:.825rem;color:#94a3b8;text-align:center"><i class="fa-solid fa-box-open" style="font-size:1.25rem;display:block;margin-bottom:0.4rem;color:#7c8aa5"></i>No APK binary uploaded yet. Use the upload card on the right to publish a build.</div>';
      } else {
        if (hasUniversal) {
          var u = data.artifacts_detail.universal;
          html += this._renderArtifactItem('Universal APK (All devices)', u, 'universal');
        }
        if (hasArm64) {
          var a64 = data.artifacts_detail['arm64-v8a'];
          html += this._renderArtifactItem('ARM64-v8a (64-bit split)', a64, 'arm64-v8a');
        }
        if (hasArm32) {
          var a32 = data.artifacts_detail['armeabi-v7a'];
          html += this._renderArtifactItem('ARMeabi-v7a (32-bit legacy)', a32, 'armeabi-v7a');
        }
      }
      html += '</div>';

      // Quick test link
      if (data.download_available) {
        html += '<div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">' +
          '<a href="/api/v1/app/download" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-download" style="color:#38bdf8"></i> Test Direct APK Download</a>' +
          '<a href="/api/v1/app/config" target="_blank" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;background:#0f172a;border-color:#334155;color:#e2e8f0;padding:0.6rem 1rem"><i class="fa-solid fa-code" style="color:#a78bfa"></i> View Config JSON</a>' +
        '</div>';
      }

      html += '</div>';
      container.innerHTML = html;
    },

    // The rule phones actually receive from /api/v1/app/config.
    _renderPhonePolicy: function (data) {
      var p = data.phone_policy;
      if (!p) return '';
      var text;
      if (!data.download_available) {
        text = 'No APK is published, so phones are not asked to update and nobody is blocked.';
      } else {
        text = 'Phones older than v' + p.min_version + ' (build ' + p.min_build + ') must update' +
          (p.force_update ? '; Force update also blocks phones older than v' + p.latest_version + ' (build ' + p.latest_build + ').' :
            '; others see an optional update to v' + p.latest_version + ' (build ' + p.latest_build + ').');
      }
      return '<div id="ar-phone-policy" style="font-size:.75rem;color:#cbd5e1;margin-top:.3rem"><strong>What phones see now:</strong> ' + escapeHtml(text) + '</div>';
    },

    _renderArtifactItem: function (label, art, abi) {
      return '<div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;margin-bottom:.5rem;background:#0f172a;border-radius:.5rem;border:1px solid #334155;border-left:4px solid #38bdf8">' +
        '<div style="min-width:0;flex:1">' +
          '<div style="font-size:.85rem;font-weight:600;color:#f8fafc;display:flex;align-items:center;gap:.45rem">' +
            '<i class="fa-solid fa-cube" style="color:#38bdf8;font-size:.8rem"></i> ' + escapeHtml(label) +
            '<span style="font-size:.75rem;color:#94a3b8;font-weight:400">(' + escapeHtml(art.size_formatted) + ')</span>' +
          '</div>' +
          '<div style="font-size:.7rem;color:#cbd5e1;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.2rem" title="SHA-256: ' + escapeHtml(art.sha256) + '"><span style="color:#7c8aa5">SHA-256:</span> ' + escapeHtml(art.sha256) + '</div>' +
        '</div>' +
        '<div style="display:flex;gap:.35rem;margin-left:.75rem">' +
          '<button type="button" class="btn btn-outline btn-sm" style="padding:.35rem .6rem;font-size:.75rem;color:#f87171;border-color:rgba(239,68,68,0.4);background:#1e293b" onclick="AppReleaseUI.deleteApk(\'' + escapeHtml(abi) + '\')" title="Delete artifact"><i class="fa-solid fa-trash"></i></button>' +
        '</div>' +
      '</div>';
    },

    populateForm: function (data) {
      var setVal = function (id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val != null ? val : '';
      };
      var setChecked = function (id, checked) {
        var el = document.getElementById(id);
        if (el) el.checked = !!checked;
      };

      AppReleaseUI._cfg = { min_version: String(data.min_version || ''), min_build: parseInt(data.min_build, 10) || 0, force_update: !!data.force_update };
      setVal('cfg-latest-version', data.latest_version);
      setVal('cfg-latest-build', data.latest_build);
      // Taken from the uploaded APK: shown, not typed.
      var fromApk = data.version_source === 'apk';
      ['cfg-latest-version', 'cfg-latest-build'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.readOnly = fromApk;
      });
      var srcHint = document.getElementById('cfg-version-source');
      if (srcHint) {
        srcHint.textContent = fromApk
          ? 'Read from the published APK (' + (data.apk_package || '') + '). Upload a new APK to change it.'
          : 'No readable APK is published, so this typed value is used.';
      }
      setVal('cfg-min-version', data.min_version);
      setVal('cfg-min-build', data.min_build);
      setChecked('cfg-force-update', data.force_update);
      setVal('cfg-release-notes', data.release_notes);
      setVal('cfg-banner-text', data.banner_text);
      setVal('cfg-banner-kind', data.banner_kind || 'info');
      setChecked('cfg-drains-enabled', data.background_drains_enabled !== false);
    },

    saveConfig: function (e, phrase) {
      if (e) e.preventDefault();
      // Danger confirmation (HIGH, conditional) — same rule as the server.
      if (phrase === undefined) {
        var cur = AppReleaseUI._cfg || {};
        var gv = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
        var fEl = document.getElementById('cfg-force-update');
        var force = !!(fEl && fEl.checked);
        var minV = String(gv('cfg-min-version')).replace(/[^0-9.]/g, '') || '1.0.0';
        var minB = Math.max(1, parseInt(gv('cfg-min-build'), 10) || cur.min_build || 1);
        var forceOn = force && !cur.force_update;
        var minCh = minV !== cur.min_version || minB !== cur.min_build;
        if (forceOn || minCh) {
          if (!(window.DangerConfirm && window.DangerConfirm.ask)) { showAlert('danger', 'The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.'); return; }
          var cons = [];
          if (forceOn) cons.push('FORCE UPDATE is switched ON: every phone running an app older than the minimum version is BLOCKED until it installs the new APK.');
          if (minCh) cons.push('The minimum supported app becomes v' + minV + ' (build ' + minB + '), was v' + (cur.min_version || '?') + ' (build ' + (cur.min_build || '?') + ').');
          cons.push('Teachers and staff who cannot download the new APK right away cannot use the app.');
          window.DangerConfirm.ask({
            tier: 'high', title: 'Change which app versions are allowed?', object: 'Mobile app v' + minV + ' (build ' + minB + ')',
            summary: 'This changes which installed apps keep working.', intent: 'I want to change the app update rule',
            consequences: cons, keeps: ['Data on the server is not changed.', 'You can switch Force update off again later.'],
            phrase: 'FORCE UPDATE', confirmLabel: 'Save update rule'
          }).then(function (r) { if (r) AppReleaseUI.saveConfig(null, r.phrase); });
          return;
        }
      }
      var btn = document.getElementById('btn-save-cfg');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
      }

      var form = document.getElementById('form-release-config');
      var formData = new FormData(form);
      var drainsEl = document.getElementById('cfg-drains-enabled');
      formData.set('background_drains_enabled', drainsEl && drainsEl.checked ? '1' : '0');
      formData.append('action', 'save_config');
      if (phrase) formData.append('confirm_phrase', phrase);

      fetch(API_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        credentials: 'same-origin',
        body: formData
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showAlert('success', res.message || 'Release policy saved successfully!');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          showAlert('danger', res.message || 'Failed to save configuration.');
        }
      })
      .catch(function () {
        showAlert('danger', 'Network error saving configuration.');
      })
      .finally(function () {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Release Policy';
        }
      });
    },

    deleteApk: function (abi, phrase) {
      if (phrase === undefined) {
        if (!(window.DangerConfirm && window.DangerConfirm.ask)) { showAlert('danger', 'The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.'); return; }
        window.DangerConfirm.ask({
          tier: 'high', title: 'Delete the ' + abi + ' APK?', object: abi + ' APK',
          summary: 'The downloadable app file is deleted from the server.', intent: 'I want to delete this APK',
          consequences: ['Nobody can download or update the app from this file any more.', 'Phones told to update (Force update) may have nothing to download until a new APK is uploaded.'],
          keeps: ['Apps already installed on phones keep working.'],
          irreversible: true, phrase: 'DELETE APK', confirmLabel: 'Delete APK'
        }).then(function (r) { if (r) AppReleaseUI.deleteApk(abi, r.phrase); });
        return;
      }

      var formData = new FormData();
      formData.append('action', 'delete_apk');
      formData.append('confirm_phrase', phrase);
      formData.append('abi', abi);
      formData.append('csrf_token', getCsrfToken());

      fetch(API_URL, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        credentials: 'same-origin',
        body: formData
      })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showAlert('success', res.message || 'Artifact removed.');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          showAlert('danger', res.message || 'Failed to remove artifact.');
        }
      })
      .catch(function () {
        showAlert('danger', 'Network error removing artifact.');
      });
    }
  };

  window.AppReleaseUI = AppReleaseUI;

  // Auto-init on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      AppReleaseUI.init();
    });
  } else {
    AppReleaseUI.init();
  }
})(window, document);
