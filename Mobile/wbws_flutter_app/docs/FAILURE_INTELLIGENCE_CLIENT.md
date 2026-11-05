# Failure Intelligence — Client Contract

What the Flutter client sends for failure intelligence, and the guarantees
that did not change. Server side: [`docs/FAILURE_INTELLIGENCE.md`](../../../docs/FAILURE_INTELLIGENCE.md)
(repo root) — registry, dashboard, alerting, deploy order.

## 1. The fleet-context header: `X-Installation-Id`

Added in `lib/services/api_service.dart` (`post()`), **only on sync writes**:
authenticated calls that carry an `Idempotency-Key` or an
`X-Client-Attempt-Id`. The value is the **same anonymous installation id
telemetry already uses** (`TelemetryService.instance.getInstallationId()`),
so the channel reuses an installation identifier rather than creating a
new account identity. Correlation with builds and attempts is still
operational information; “anonymous” does not mean all telemetry is
unidentifiable or safe for public sharing. If the id is empty the header
is simply omitted.
Servers that do not know the header ignore it, so old servers + new
clients remain compatible.

## 2. Crash signature content contract + caps

Built by `buildCrashSignature()` in `lib/services/crash_log_service.dart`
from the crash log (Dart errors and the native trap in
`android/.../FkssApplication.kt`, which appends Java/Kotlin throwables to
the same log). The signature is deliberately **bounded and low-cardinality**:

| Part | Contract |
|---|---|
| `signature_class` | Exception class: text before the first `:` on the first body line; `Unknown` if empty |
| `frames` | At most **3** first-party frames — Dart frames in the app's own package, native frames in the app's own Kotlin/Java namespace; framework, engine and OS frames are deliberately excluded |
| Frame content | File + function only — no paths, no messages, no arguments |
| Per-part cap | Allow-listed charset, ≤ **120** chars (`_kMaxSignaturePartLength`) |
| Frame cap | ≤ **3** frames, deduplicated (`_kMaxSignatureFrames`) |
| Total cap | Whole signature JSON ≤ **512** bytes (`_kMaxSignatureJsonBytes`), enforced by dropping frames from the end |
| Nothing usable | The builder returns null when the crash body carries nothing signature-worthy — no signature is sent |

The server stores the SHA-256 of this signature as the crash fingerprint
(`app_crash_signatures.crash_key`, migration 063), so identical crashes
group into one issue row. Pinned on the client by
`test/crash_signature_test.dart` and on the repo side by
`tests/security/test_failure_intelligence_phase2.py` / `..._phase5.py`
(the doc may not drift from the constants in the code).

## 3. Idempotency and retry behavior remains separate

The historical term **exact-once guarantee** refers to the existing
idempotency/replay contract for eligible operations; adding fleet context
does not strengthen that contract or change its identity/transaction rules.
This header/signature contract is not an unconditional exactly-once
delivery or background-execution guarantee. See [current recovery and
attention-state guidance](../../../docs/user_manual/SYNC_AND_RECOVERY.md).

The sync protocol is untouched. `Idempotency-Key`, `X-Client-Attempt-Id` /
`X-Client-Attempt-Number`, and the outbox semantics behave exactly as
before; `X-Installation-Id` is additive, opaque, and takes no part in
deduplication or retry decisions. Crash reporting remains best-effort and
never blocks or alters the sync write path.
