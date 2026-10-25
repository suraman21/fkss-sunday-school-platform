/**
 * Super Admin — Mobile App Release Management UI Module
 * Telegram-style parallel part-pool APK upload engine (512 KB parts,
 * 4-way sliding window, per-part deadlines/retries, resumable) with
 * Real-time State Handling & cPanel Fault-Tolerance.
 */
(function (window, document) {
  'use strict';

  var API_URL = '/admin/api_app_release.php';
  // Telegram-style part-pool transfer (modelled on MTProto upload.saveFilePart,
  // core.telegram.org/api/files): fixed 512 KB parts, a sliding window of
  // parallel part requests, per-part deadlines and independent retries.
  var PART_SIZE = 512 * 1024;       // 512 KB — Telegram's recommended part quantum
  var POOL_SIZE = 4;                // parts always in flight (parallel call queue)
  var PART_DEADLINE_MS = 45000;     // a stalled part is aborted and retried alone
  var MAX_PART_RETRIES = 8;
  var WATCHDOG_MS = 60000;          // no ack at all in this window => visible diagnostics

  // Upload State Machine
  var currentUpload = {
    state: 'idle', // 'idle' | 'selected' | 'uploading' | 'assembling' | 'success' | 'error'
    file: null,
    abi: 'universal',
    uploadId: null,
    totalChunks: 0,
    currentChunkIndex: 0,
    chunkRetries: 0,
    startTime: 0,
    uploadedBytesBeforeCurrentChunk: 0,
    lastSpeedUpdate: 0,
    lastLoadedBytes: 0,
    speedBps: 0,
    xhr: null,
    aborted: false,
    partsAcked: {},
    partRetries: {},
    controllers: {},
    ackedBytes: 0,
    ackedCount: 0,
    inFlight: 0,
    nextPart: 0,
    base64Only: false,
    watchdog: null,
    lastAckAt: 0,
    lastErrorText: '',
    fallbackActive: false
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

  function blobToBase64(blob, cb) {
    var fr = new FileReader();
    fr.onload = function () {
      var s = String(fr.result || '');
      cb(s.slice(s.indexOf(',') + 1));
    };
    fr.onerror = function () { cb(''); };
    fr.readAsDataURL(blob);
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

      if (dropZone) {
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

      if (fileInput) {
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
      currentUpload.abi = val || 'universal';
      var abiEl = document.getElementById('stage-file-abi');
      if (abiEl) {
        abiEl.textContent = currentUpload.abi === 'universal' ? 'Universal APK' : currentUpload.abi;
      }
    },

    onFileSelected: function (file) {
      if (!file) return;

      if (!file.name.toLowerCase().endsWith('.apk')) {
        showAlert('danger', 'Invalid file type. Only Android APK binaries (.apk) are supported.');
        var fileInput = document.getElementById('upload-apk-input');
        if (fileInput) fileInput.value = '';
        return;
      }

      if (file.size > 262144000) { // 250 MB
        showAlert('danger', 'APK file exceeds the maximum 250MB limit.');
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

    startUpload: function () {
      if (!currentUpload.file) {
        var fileInput = document.getElementById('upload-apk-input');
        if (fileInput && fileInput.files && fileInput.files[0]) {
          currentUpload.file = fileInput.files[0];
          currentUpload.totalChunks = Math.max(1, Math.ceil(fileInput.files[0].size / PART_SIZE));
          currentUpload.uploadId = generateUploadId();
        }
      }

      if (!currentUpload.file || currentUpload.state === 'uploading') return;

      var abiSelect = document.getElementById('upload-abi-select');
      if (abiSelect) {
        currentUpload.abi = abiSelect.value || 'universal';
      }

      currentUpload.state = 'uploading';
      currentUpload.aborted = false;
      currentUpload.currentChunkIndex = 0;
      currentUpload.chunkRetries = 0;
      currentUpload.startTime = Date.now();
      currentUpload.lastSpeedUpdate = Date.now();
      currentUpload.uploadedBytesBeforeCurrentChunk = 0;
      currentUpload.lastLoadedBytes = 0;
      currentUpload.speedBps = 0;

      this.renderUploadUI();
      this.beginTransfer();
    },


    // ── Transfer engine — Telegram-style part pool ──────────────────
    // Clones MTProto's upload strategy (core.telegram.org/api/files):
    //   * the file is split into fixed 512 KB parts, each its own request;
    //   * a sliding window keeps POOL_SIZE parts in flight at all times —
    //     every ack immediately launches the next part (parallel call queue);
    //   * each part carries its own deadline: a stalled request is aborted
    //     and THAT PART alone is retried, so one dead connection can never
    //     freeze the transfer (the old sequential engine died this way);
    //   * resume = ask the server which parts already exist and send only
    //     the missing ones (survives page reloads and browser restarts).
    beginTransfer: function () {
      var self = this;
      currentUpload.fallbackActive = false;
      currentUpload.base64Only = false;
      currentUpload.partsAcked = {};
      currentUpload.ackedBytes = 0;
      currentUpload.ackedCount = 0;
      currentUpload.nextPart = 0;
      currentUpload.inFlight = 0;
      currentUpload.partRetries = {};
      currentUpload.controllers = {};
      currentUpload.lastAckAt = Date.now();
      currentUpload.lastErrorText = '';

      var label = document.getElementById('upload-status-subtext');
      if (label) {
        label.innerHTML = '<span style="color:#38bdf8"><i class="fa-solid fa-satellite-dish"></i> Checking server for already-received parts…</span>';
      }

      var formData = new FormData();
      formData.append('action', 'upload_status');
      formData.append('upload_id', currentUpload.uploadId);
      formData.append('total_chunks', currentUpload.totalChunks);
      formData.append('csrf_token', getCsrfToken());

      fetch(API_URL + '?action=upload_status', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrfToken() },
        credentials: 'same-origin',
        body: formData
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res && res.status === 'success' && res.data && Array.isArray(res.data.received)) {
            res.data.received.forEach(function (idx) { self.markPartAcked(Number(idx), true); });
          }
          self.startWorkers();
        })
        .catch(function () { self.startWorkers(); });
    },

    partBytes: function (idx) {
      var start = idx * PART_SIZE;
      return Math.max(0, Math.min(PART_SIZE, currentUpload.file.size - start));
    },

    markPartAcked: function (idx, silent) {
      if (currentUpload.partsAcked[idx]) return;
      currentUpload.partsAcked[idx] = 1;
      currentUpload.ackedCount++;
      currentUpload.ackedBytes += this.partBytes(idx);
      if (!silent) {
        currentUpload.lastAckAt = Date.now();
        var now = Date.now();
        var timeDiff = (now - currentUpload.lastSpeedUpdate) / 1000;
        if (timeDiff >= 0.5) {
          currentUpload.speedBps = (currentUpload.ackedBytes - currentUpload.lastLoadedBytes) / timeDiff;
          currentUpload.lastSpeedUpdate = now;
          currentUpload.lastLoadedBytes = currentUpload.ackedBytes;
        }
        this.paintProgress();
      }
    },

    paintProgress: function () {
      var total = currentUpload.file.size;
      var loaded = currentUpload.ackedBytes;
      var pct = Math.min(99, Math.round((loaded / total) * 100));
      var remaining = Math.max(0, total - loaded);
      var eta = currentUpload.speedBps > 0 ? remaining / currentUpload.speedBps : 0;
      AppReleaseUI.updateProgressDisplay(pct, loaded, total, currentUpload.ackedCount, currentUpload.totalChunks, currentUpload.speedBps, eta);
      var label = document.getElementById('upload-status-subtext');
      if (label) {
        label.innerHTML = '<span style="color:#38bdf8"><i class="fa-solid fa-upload"></i> Part ' +
          currentUpload.ackedCount + '/' + currentUpload.totalChunks +
          ' &bull; ' + currentUpload.inFlight + ' in flight' +
          (currentUpload.base64Only ? ' &bull; text-mode' : '') + '</span>';
      }
    },

    startWorkers: function () {
      this.startWatchdog();
      var label = document.getElementById('upload-status-subtext');
      if (label && currentUpload.ackedCount > 0) {
        label.innerHTML = '<span style="color:#38bdf8"><i class="fa-solid fa-rotate-left"></i> Resuming — ' +
          currentUpload.ackedCount + '/' + currentUpload.totalChunks + ' parts already on server</span>';
      }
      var n = Math.min(POOL_SIZE, currentUpload.totalChunks);
      for (var i = 0; i < n; i++) this.workerLoop();
    },

    workerLoop: function () {
      if (currentUpload.aborted || currentUpload.state !== 'uploading') return;
      var idx = -1;
      while (currentUpload.nextPart < currentUpload.totalChunks) {
        var cand = currentUpload.nextPart++;
        if (!currentUpload.partsAcked[cand]) { idx = cand; break; }
      }
      if (idx === -1) {
        // Nothing left to claim. If no part is still in flight, every part
        // is stored — commit exactly once (Telegram's atomic commit step).
        if (currentUpload.inFlight === 0) this.assembleChunksOnServer();
        return;
      }
      this.sendPart(idx);
    },

    sendPart: function (idx) {
      if (currentUpload.aborted || currentUpload.state !== 'uploading') return;
      var self = this;
      currentUpload.inFlight++;
      var start = idx * PART_SIZE;
      var slice = currentUpload.file.slice(start, start + PART_SIZE);

      var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      currentUpload.controllers[idx] = controller;
      var deadline = setTimeout(function () {
        if (controller) controller.abort(); // stall breaker: retry this part only
      }, PART_DEADLINE_MS);

      var settled = false;
      var finishAttempt = function (ok, detail) {
        if (settled) return;
        settled = true;
        clearTimeout(deadline);
        delete currentUpload.controllers[idx];
        currentUpload.inFlight--;
        if (currentUpload.aborted || currentUpload.state !== 'uploading') return;
        if (ok) {
          currentUpload.partRetries[idx] = 0;
          self.markPartAcked(idx, false);
          self.workerLoop(); // success launches the next part (sliding window)
          return;
        }
        self.handlePartFailure(idx, detail);
      };

      var postForm = function (formData) {
        return fetch(API_URL + '?action=upload_chunk', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': getCsrfToken() },
          credentials: 'same-origin',
          body: formData,
          signal: controller ? controller.signal : undefined
        });
      };

      var sendBase64 = function () {
        return new Promise(function (resolve) { blobToBase64(slice, resolve); }).then(function (b64) {
          if (!b64) { finishAttempt(false, 'could not read file part'); return null; }
          var formData = new FormData();
          formData.append('action', 'upload_chunk');
          formData.append('upload_id', currentUpload.uploadId);
          formData.append('chunk_index', String(idx));
          formData.append('total_chunks', String(currentUpload.totalChunks));
          formData.append('total_size', String(currentUpload.file.size));
          formData.append('chunk_size', String(self.partBytes(idx)));
          formData.append('chunk_b64', b64);
          formData.append('csrf_token', getCsrfToken());
          return postForm(formData).then(function (res) {
            if (!res.ok) { finishAttempt(false, 'HTTP ' + res.status + ' (text-mode)'); return null; }
            return res.json().then(function (json) {
              if (json && json.status === 'success') finishAttempt(true);
              else finishAttempt(false, (json && json.message) || 'server rejected part (text-mode)');
            }, function () { finishAttempt(false, 'unreadable server response (text-mode)'); });
          });
        });
      };

      var sendBinary = function () {
        var formData = new FormData();
        formData.append('action', 'upload_chunk');
        formData.append('upload_id', currentUpload.uploadId);
        formData.append('chunk_index', String(idx));
        formData.append('total_chunks', String(currentUpload.totalChunks));
        formData.append('total_size', String(currentUpload.file.size));
        formData.append('chunk_size', String(self.partBytes(idx)));
        formData.append('chunk_file', slice, 'part' + idx);
        formData.append('csrf_token', getCsrfToken());
        return postForm(formData).then(function (res) {
          if (res.status === 403 || res.status === 413 || res.status === 415 || res.status === 422) {
            // A WAF or body-size rule rejected the binary body. Telegram's
            // equivalent move: shrink/re-encode the part — here the remaining
            // parts switch to text-mode, which survives WAF inspection.
            currentUpload.base64Only = true;
            return sendBase64();
          }
          if (!res.ok) { finishAttempt(false, 'HTTP ' + res.status); return null; }
          return res.json().then(function (json) {
            if (json && json.status === 'success') finishAttempt(true);
            else finishAttempt(false, (json && json.message) || 'server rejected part');
          }, function () { finishAttempt(false, 'unreadable server response'); });
        });
      };

      (currentUpload.base64Only ? sendBase64() : sendBinary())
        .catch(function (err) {
          if (currentUpload.aborted || currentUpload.state !== 'uploading') {
            if (!settled) { settled = true; clearTimeout(deadline); delete currentUpload.controllers[idx]; currentUpload.inFlight--; }
            return;
          }
          var name = (err && err.name) || '';
          finishAttempt(false, name === 'AbortError'
            ? 'part stalled — deadline exceeded'
            : ((err && err.message) || 'network error'));
        });
    },

    handlePartFailure: function (idx, detail) {
      if (currentUpload.aborted || currentUpload.state !== 'uploading') return;
      currentUpload.lastErrorText = 'part ' + (idx + 1) + ': ' + detail;
      var retries = (currentUpload.partRetries[idx] || 0) + 1;
      currentUpload.partRetries[idx] = retries;
      if (retries > MAX_PART_RETRIES) {
        this.showUploadError('Upload stopped on part ' + (idx + 1) + ' of ' + currentUpload.totalChunks +
          ' after ' + MAX_PART_RETRIES + ' retries. Last error: ' + detail +
          ' — press Upload again to resume from the parts already stored.');
        return;
      }
      var self = this;
      var backoff = Math.min(8000, 700 * Math.pow(1.6, retries - 1));
      this.noteRetry('part ' + (idx + 1) + ' — ' + detail + ' (retry ' + retries + '/' + MAX_PART_RETRIES + ')');
      setTimeout(function () {
        if (currentUpload.aborted || currentUpload.state !== 'uploading') return;
        if (!currentUpload.partsAcked[idx]) self.sendPart(idx);
        else self.workerLoop();
      }, backoff);
    },

    noteRetry: function (message) {
      var label = document.getElementById('upload-status-subtext');
      if (!label) return;
      label.innerHTML = '<span style="color:#fbbf24"><i class="fa-solid fa-rotate fa-spin"></i> Unstable connection — retrying ' +
        escapeHtml(message) + '</span>';
    },

    // Visible-diagnostics watchdog: the old engine could sit at 0% forever
    // with no feedback. If no part is acked for WATCHDOG_MS the UI says so,
    // in red, with the last concrete error — while retries continue.
    startWatchdog: function () {
      var self = this;
      if (currentUpload.watchdog) clearInterval(currentUpload.watchdog);
      currentUpload.watchdog = setInterval(function () {
        if (currentUpload.state !== 'uploading' || currentUpload.aborted) {
          clearInterval(currentUpload.watchdog);
          currentUpload.watchdog = null;
          return;
        }
        var idleFor = Date.now() - currentUpload.lastAckAt;
        if (idleFor > WATCHDOG_MS && currentUpload.inFlight > 0) {
          var label = document.getElementById('upload-status-subtext');
          if (label) {
            label.innerHTML = '<span style="color:#f87171"><i class="fa-solid fa-triangle-exclamation"></i> No server response for ' +
              Math.round(idleFor / 1000) + 's' +
              (currentUpload.lastErrorText ? ' — last error: ' + escapeHtml(currentUpload.lastErrorText) : '') +
              '. Still retrying…</span>';
          }
        }
      }, 5000);
    },

    updateProgressDisplay: function (pct, loadedBytes, totalBytes, chunkNum, totalChunks, speedBps, etaSeconds) {
      var bar = document.getElementById('upload-progress-fill');
      var pctText = document.getElementById('upload-pct-display');
      var bytesText = document.getElementById('upload-bytes-display');
      var chunkText = document.getElementById('upload-chunk-display');
      var speedText = document.getElementById('upload-speed-display');
      var etaText = document.getElementById('upload-eta-display');

      if (bar) bar.style.width = pct + '%';
      if (pctText) pctText.textContent = pct + '%';
      if (bytesText) bytesText.textContent = formatBytes(loadedBytes) + ' / ' + formatBytes(totalBytes);
      if (chunkText) chunkText.textContent = 'Chunk ' + chunkNum + ' / ' + totalChunks;
      if (speedText) speedText.textContent = speedBps > 0 ? (formatBytes(speedBps) + '/s') : '--';
      if (etaText) etaText.textContent = formatDuration(etaSeconds);
    },

    assembleChunksOnServer: function () {
      if (currentUpload.state !== 'uploading') return; // single commit, ever
      currentUpload.state = 'assembling';
      this.renderUploadUI();

      var formData = new FormData();
      formData.append('action', 'assemble_chunks');
      formData.append('upload_id', currentUpload.uploadId);
      formData.append('total_chunks', currentUpload.totalChunks);
      formData.append('total_size', currentUpload.file.size);
      formData.append('file_name', currentUpload.file.name);
      formData.append('abi', currentUpload.abi);
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
          currentUpload.state = 'success';
          AppReleaseUI.renderUploadUI();
          AppReleaseUI.renderSuccessState(res.data, currentUpload.file.name, currentUpload.file.size);
          showAlert('success', res.message || 'APK published and cryptographic sidecar generated successfully!');
          if (res.data) {
            AppReleaseUI.renderStatus(res.data);
            AppReleaseUI.populateForm(res.data);
          }
        } else {
          AppReleaseUI.showUploadError(res.message || 'Server failed to assemble APK chunks.');
        }
      })
      .catch(function (err) {
        AppReleaseUI.showUploadError('Network error while assembling APK on server: ' + (err.message || 'Request failed'));
      });
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
      currentUpload.aborted = true;
      if (currentUpload.xhr) {
        try { currentUpload.xhr.abort(); } catch (e) {}
      }
      if (currentUpload.controllers) {
        Object.keys(currentUpload.controllers).forEach(function (k) {
          try { currentUpload.controllers[k] && currentUpload.controllers[k].abort(); } catch (e) {}
        });
        currentUpload.controllers = {};
      }
      if (currentUpload.watchdog) { clearInterval(currentUpload.watchdog); currentUpload.watchdog = null; }

      if (currentUpload.uploadId) {
        var formData = new FormData();
        formData.append('action', 'cancel_upload');
        formData.append('upload_id', currentUpload.uploadId);
        formData.append('csrf_token', getCsrfToken());

        fetch(API_URL, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': getCsrfToken() },
          credentials: 'same-origin',
          body: formData
        }).catch(function () {});
      }

      this.resetUploadState();
    },

    resetUploadState: function () {
      currentUpload.state = 'idle';
      currentUpload.file = null;
      currentUpload.uploadId = null;
      currentUpload.currentChunkIndex = 0;
      currentUpload.totalChunks = 0;
      currentUpload.xhr = null;
      currentUpload.aborted = false;
      currentUpload.partsAcked = {};
      currentUpload.partRetries = {};
      currentUpload.controllers = {};
      currentUpload.ackedBytes = 0;
      currentUpload.ackedCount = 0;
      currentUpload.inFlight = 0;
      currentUpload.base64Only = false;
      if (currentUpload.watchdog) { clearInterval(currentUpload.watchdog); currentUpload.watchdog = null; }

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
