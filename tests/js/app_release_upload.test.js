'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { makeHarness, until, flush } = require('./upload_harness');
function harness(t, options) { const h = makeHarness(options); t.after(() => h.cleanup()); return h; }

test('assembly requires every verified ACK and no pending retry', async t => {
  const h = harness(t); h.defer = true; h.select(4); await h.ui.startUpload();
  await until(() => h.live().length === 4);
  h.part(3).network(); await flush();
  const ctx = h.state.attempt;
  assert.equal(ctx.parallel, 2);
  for (let i = 0; i < 3; i++) h.part(i).success();
  await flush();
  assert.equal(ctx.count, 3); assert.equal(ctx.inFlight, 0);
  assert.equal(Object.keys(ctx.pending).length, 1);
  assert.equal(h.calls.filter(c => c.action === 'assemble_chunks').length, 0);
  h.fire(700); await until(() => h.live().length === 1);
  h.part(3).success(); await until(() => h.state.state === 'success');
  assert.equal(h.published, 1); assert.equal(h.timerCount(), 0);
});

test('stale completion cannot change a newer attempt or its counters', async t => {
  const h = harness(t); h.defer = true; h.ignoreAbort = true; h.select(1);
  await h.ui.startUpload(); await until(() => h.live().length === 1);
  const old = h.part(0), oldCtx = h.state.attempt;
  h.ui.resetUploadState(); h.select(1, 9); await h.ui.startUpload();
  await until(() => h.live().length === 2);
  const current = h.state.attempt;
  old.success(); await flush();
  assert.equal(oldCtx.inFlight, 0); assert.equal(current.inFlight, 1);
  assert.equal(current.count, 0); assert.equal(h.published, 0);
  h.live()[0].success(); await until(() => h.state.state === 'success');
});

test('transient failures back off concurrency from four to two to one', async t => {
  const h = harness(t); h.defer = true; h.select(5); await h.ui.startUpload();
  await until(() => h.live().length === 4);
  h.part(0).fail(); await flush(); assert.equal(h.state.attempt.parallel, 2);
  h.part(1).fail(); await flush(); assert.equal(h.state.attempt.parallel, 1);
  assert.equal(h.calls.filter(c => c.action === 'upload_chunk').length, 4);
  assert.equal(Object.keys(h.state.attempt.pending).length, 2);
});

test('failed status never falls back to restarting file transfer', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => c.action === 'upload_status' ? h.response(403, { status: 'error', message: 'Session expired' }) : undefined;
  await h.ui.startUpload();
  assert.equal(h.state.state, 'error');
  assert.equal(h.calls.filter(c => c.action === 'upload_chunk').length, 0);
  assert.match(h.nodes['upload-error-message'].textContent, /403/);
  assert.equal(h.timerCount(), 0);
});

test('reload resumes only missing parts after fingerprint verification', async t => {
  const first = harness(t); first.defer = true; first.select(3); await first.ui.startUpload();
  await until(() => first.live().length === 3);
  const id = first.state.uploadId;
  first.part(0).success(); await flush(); first.cleanup();
  const second = harness(t, { backend: first.backend, storage: first.storage });
  second.select(3); await second.ui.startUpload(); await until(() => second.state.state === 'success');
  assert.equal(second.state.uploadId, id);
  assert.deepEqual(second.calls.filter(c => c.action === 'upload_chunk').map(c => Number(c.form.get('chunk_index'))).sort(), [1, 2]);
  assert.equal(second.storage.size, 0);
});

test('same size but different content cannot reuse a persisted upload ID', async t => {
  const first = harness(t); first.defer = true; first.select(1); await first.ui.startUpload();
  await until(() => first.live().length === 1); const id = first.state.uploadId; first.cleanup();
  const second = harness(t, { backend: first.backend, storage: first.storage });
  second.select(1, 99); await second.ui.startUpload(); await until(() => second.state.state === 'success');
  assert.notEqual(second.state.uploadId, id);
});

for (const status of [401, 403, 413]) test('HTTP ' + status + ' is terminal without base64 re-encoding', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => c.action === 'upload_chunk' ? h.response(status, { status: 'error', retryable: true, message: 'Rejected' }) : undefined;
  await h.ui.startUpload(); await until(() => h.state.state === 'error');
  const calls = h.calls.filter(c => c.action === 'upload_chunk');
  assert.equal(calls.length, 1); assert.equal(calls[0].form.has('chunk_b64'), false);
  assert.match(h.nodes['upload-error-message'].textContent, new RegExp(String(status)));
  assert.equal(h.timerCount(), 0);
});

test('JSON null preserves actual HTTP status instead of becoming a network error', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => c.action === 'upload_status' ? h.response(403, null) : undefined;
  await h.ui.startUpload();
  assert.equal(h.state.state, 'error');
  assert.match(h.nodes['upload-error-message'].textContent, /HTTP 403/);
  assert.doesNotMatch(h.nodes['upload-error-message'].textContent, /Network failure/);
});

test('lost finalize response is reconciled from receipt without a second publication', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => {
    if (c.action !== 'assemble_chunks') return undefined;
    h.backend.sessions.get(c.form.get('upload_id')).state = 'published';
    h.published++; throw new TypeError('Failed to fetch');
  };
  await h.ui.startUpload(); await until(() => h.state.state === 'assembling'); await flush();
  h.fire(1200); await until(() => h.state.state === 'success');
  assert.equal(h.published, 1);
  assert.equal(h.calls.filter(c => c.action === 'assemble_chunks').length, 1);
  assert.equal(h.calls.filter(c => c.action === 'upload_status').length, 2);
});

test('cancellation aborts active requests, clears timers, and fences late callbacks', async t => {
  const h = harness(t); h.defer = true; h.select(2); await h.ui.startUpload();
  await until(() => h.live().length === 2); const id = h.state.uploadId;
  h.ui.cancelUpload(true); await flush();
  assert.equal(h.state.state, 'idle'); assert.equal(h.live().length, 0);
  assert.equal(h.storage.size, 0); assert.equal(h.timerCount(), 0);
  assert.equal(h.calls.find(c => c.action === 'cancel_upload').form.get('upload_id'), id);
  assert.equal(h.published, 0);
});

test('per-request deadline aborts a hung part and schedules bounded recovery', async t => {
  const h = harness(t); h.defer = true; h.select(1); await h.ui.startUpload();
  await until(() => h.live().length === 1);
  h.fire(45000); await flush();
  assert.equal(h.live().length, 0);
  assert.equal(h.state.attempt.inFlight, 0);
  assert.equal(Object.keys(h.state.attempt.pending).length, 1);
  h.fire(700); await until(() => h.live().length === 1);
  h.fire(90000); await flush();
  assert.equal(Object.keys(h.state.attempt.pending).length, 1);
  h.fire(1120); await until(() => h.live().length === 1);
  h.part(0).success(); await until(() => h.state.state === 'success');
});

test('mismatched ACK is never counted or assembled', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => c.action === 'upload_chunk' ? h.response(200, { status: 'success', data: { chunk_index: 0, size: 1, sha256: 'wrong' } }) : undefined;
  await h.ui.startUpload(); await until(() => h.state.state === 'error');
  assert.equal(h.state.ackedCount, 0);
  assert.equal(h.calls.filter(c => c.action === 'assemble_chunks').length, 0);
});

test('disabled localStorage still permits verified same-page resume', async t => {
  const h = harness(t); h.defer = true; h.select(2);
  h.window.localStorage.getItem = h.window.localStorage.setItem = () => { throw new Error('Storage blocked'); };
  await h.ui.startUpload(); await until(() => h.live().length === 2);
  const id = h.state.uploadId; h.part(0).success(); await flush();
  h.part(1).fail(403); await until(() => h.state.state === 'error');
  h.defer = false; await h.ui.startUpload(); await until(() => h.state.state === 'success');
  assert.equal(h.state.uploadId, id);
  assert.equal(h.calls.filter(c => c.action === 'upload_chunk' && c.form.get('chunk_index') === '0').length, 1);
});

test('retries stop after the configured eight retries without leaking timers', async t => {
  const h = harness(t); h.defer = true; h.select(1); await h.ui.startUpload();
  for (let attempt = 0; attempt <= 8; attempt++) {
    await until(() => h.live().length === 1); h.part(0).network(); await flush();
    if (attempt < 8) h.fire(Math.min(8000, 700 * Math.pow(1.6, attempt)));
  }
  assert.equal(h.state.state, 'error'); assert.equal(h.timerCount(), 0);
  assert.equal(h.calls.filter(c => c.action === 'upload_chunk').length, 9);
  assert.equal(h.published, 0);
});

test('file input bindings are idempotent', t => {
  const h = harness(t); h.ui.bindDropZone(); h.ui.bindDropZone();
  assert.equal(h.inputBindings, 1);
});

test('invalid publication receipt fails visibly instead of freezing or claiming success', async t => {
  const h = harness(t); h.select(1);
  h.hook = c => c.action === 'assemble_chunks' ? h.response(200, { status: 'success', data: null }) : undefined;
  await h.ui.startUpload(); await until(() => h.state.state === 'error');
  assert.match(h.nodes['upload-error-message'].textContent, /valid publication receipt/);
  assert.equal(h.storage.size, 1); assert.equal(h.timerCount(), 0);
});

test('512 MiB is accepted at selection and larger files are rejected', t => {
  const h = harness(t);
  h.ui.onFileSelected({ name: 'boundary.apk', size: 536870912 });
  assert.equal(h.state.state, 'selected'); assert.equal(h.state.file.size, 536870912);
  h.cleanup(); h.ui.onFileSelected({ name: 'oversize.apk', size: 536870913 });
  assert.equal(h.state.file, null); assert.equal(h.state.state, 'idle');
});
