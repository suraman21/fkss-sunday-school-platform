# Source-truth audit — open review record

## 1. Request

Audit all documentation updated and everything touched in the known recent work. Source implementation is the authority; invent no fields, permissions, relationships or guarantees. Push completed corrections. Continue the authorized development PR/check/merge workflow without production promotion.

## 2. Current Problem

**Overall status: INCOMPLETE — ADDITIONAL WORK REQUIRED.** This record is not an exhaustive audit sign-off, a score assessment or security certification.

The conservative discovery boundary is `105007b..6518916df481a458c74f99fa2abc5bebe62a496e` (recent PRs 41–45): **113 tracked changed/deleted paths**, plus **31 top-level workspace artifacts**. Initial manifests are preserved under [tracked discovery](../audits/source-truth-2026-10-28/TRACKED_CHANGE_INVENTORY.json) and [artifact discovery](../audits/source-truth-2026-10-28/WORKSPACE_ARTIFACT_INVENTORY.json). Their `UNREVIEWED` fields record the initial discovery state, not completed coverage.

Current progress: selected claims in five documents have been investigated/corrected; **none of the 113 paths has complete claim-by-claim audit clearance**. The five partially reviewed paths are `docs/CHANGE_IMPACT.md`, `docs/OPERATIONS.md`, `docs/SECURITY.md`, `docs/api/LEDGER_ACTIONS.md` and `docs/architecture/SECURITY_MODEL.md`. The other 108 paths remain uncleared, including source, tests, workflows and the two deletions. All 31 workspace artifact editions remain pending semantic/provenance review. Search hits, file reads and prior CI results are not audit clearance.

## 3. Root Cause

The identified documentation errors mix implementation with misleading historical comments, intended deployment policy, earlier observations or unsupported fleet-wide conclusions. This is the cause of the selected wording errors, not a proven common cause for every remaining path.

In particular, `api/v1/core/auth.php` calls its format JWT in comments, but `createToken` and `createRefreshToken` actually return `base64(JSON) + '.' + hex(HMAC-SHA256)`. `verifyTokenState` checks that format. Likewise, Gradle comments about installed fleet trust are not certificate/device evidence.

## 4. Solution

Source revision for implementation findings: **`6518916df481a458c74f99fa2abc5bebe62a496e`**. Corrections were made individually, without bulk replacement of identifiers such as `JWT_SECRET`.

| ID | Finding and correction | Authority and evidence classification |
|---|---|---|
| A01 | Replaced JWT terminology in change-impact questions, the ledger browser/API distinction and the security-model disclaimer with custom two-part HMAC terminology | `api/v1/core/auth.php`: `createToken`, `createRefreshToken`, `verifyTokenState`. VERIFIED for format; two synthetic helper round-trips and different-key rejection execute in the new tests. Not a full authentication audit |
| A02 | Distinguished required private placement from loader behavior; documented the project-directory fallback and missing-file HTTP stop | `config.php`: `$_envFileNames`, `$_envBaseDirs`, `$_envLoaded`. VERIFIED by source inspection. Actual hosted file location UNKNOWN; no secrets file was inspected |
| A03 | Removed the claim that changing `JWT_SECRET` logs out every mobile session | `api/v1/core/auth.php`: `API_TOKEN_SECRET`, `verifyTokenState`; `api/v1/routes/auth.php`: refresh-token verification. VERIFIED key-verification behavior, including bounded synthetic tests. Installed-app sign-out timing and cache/unsent-work effects DEPENDENCY NOT VERIFIED |
| A04 | Removed the assertion that absence of `BACKUP_ENCRYPTION_KEY` alone makes backups unusable; documented defined-constant precedence and `BACKUP_KEY` fallback | `admin/backend/services/BackupService.php`: `encryptionSecret`. VERIFIED source selection. Effective hosted key, custody and recovery UNKNOWN |
| A05 | Changed universal admin CSRF wording into a bounded description of the actual helpers, without claiming endpoint-wide coverage | `config.php`: `validateCsrf`, `requireCsrf`, `requireCsrfForPost`. VERIFIED helper branches by source; every-action invocation coverage remains pending |
| A06 | Removed every-device uninstall/reinstall and lost-debug-keystore guarantees; required actual certificate/device compatibility evidence and preservation of unsent work | `Mobile/wbws_flutter_app/android/app/build.gradle`: release `signingConfig` expression. VERIFIED selection logic only. Fleet identity, upgrade compatibility and migration procedure DEPENDENCY NOT VERIFIED |
| A07 | Linked the three-action page to later Finance, Materials, permissions and DDL references instead of leaving an undifferentiated remaining-ledger-actions statement | Companion documents and dispatcher-heading consistency checks. VERIFIED reference existence; no new full-conformance or runtime claim |
| A08 | Separated earlier backup/settings/hosting observations from current audit evidence; qualified PHP lint coverage with actual first-party/vendor exclusions | Pre-correction `docs/OPERATIONS.md` at this baseline; `BackupService::privateStorageRoot`; `.github/workflows/backend-checks.yml`: `security-tests`, `php-lint-85`. Source selections VERIFIED; historical hosting observations and current protection/PHP/backup states UNKNOWN in this audit |
| A09 | Changed claims that a restricted register/private infrastructure records exist and operate into explicit handling requirements with unverified operational status | Documentation policy is not executable evidence of a private organizational process. Register existence, contents, maintenance and custody UNKNOWN; no private register was accessed |

Relevant executable sources: [token helpers](../../api/v1/core/auth.php), [refresh route](../../api/v1/routes/auth.php), [configuration/CSRF helpers](../../config.php), [backup service](../../admin/backend/services/BackupService.php), [Gradle selection](../../Mobile/wbws_flutter_app/android/app/build.gradle) and [backend workflow](../../.github/workflows/backend-checks.yml). These are exact evidence entry points, not assertions that the full files and all consumers are cleared.

## 5. Files Changed

- `docs/CHANGE_IMPACT.md`: identity terminology.
- `docs/OPERATIONS.md`: backup key selection, historical/current evidence separation, signing limits, private-record policy and bounded PHP CI wording.
- `docs/SECURITY.md`: loader placement, helper coverage, token/key-rotation limits and private-register policy versus evidence.
- `docs/api/LEDGER_ACTIONS.md`: mobile-token distinction and companion-reference scope.
- `docs/architecture/SECURITY_MODEL.md`: token-format qualification.
- `tests/security/test_source_truth_audit.py`: seven bounded regression cases.
- `docs/audits/source-truth-2026-10-28/`: two immutable initial discovery manifests.
- This open audit record.

## 6. Logic Changed

No application logic changed. Tests execute synthetic token helpers and inspect selected source/documentation boundaries. No assertions in existing tests were weakened.

## 7. Database Changes

None. No schema, migration, school data or deployed database was accessed or changed. No backup/restore operation was executed.

## 8. API Changes

None. Documentation terminology does not change the token format, bearer transport, routes, payloads, response contracts or permissions.

## 9. Security Changes

No authentication, authorization, CSRF, secrets, storage, signing configuration or security policy enforcement changed. Documentation now distinguishes source controls, written requirements and unverified operational dependencies. No credential values, private findings or school records belong in this audit record.

## 10. UI/UX Changes

No application controls, labels or behavior changed. These are documentation/evidence corrections only; they do not establish browser or phone acceptance.

## 11. Testing

Combined local validation: **57 passed, zero skips** using the command below. The pre-commit replay took **2.47 seconds**; the preceding combined run took 2.42 seconds. This includes the seven new bounded cases and 50 existing checks. The result covers this local correction pass, not the entire audit or remote CI. These durations describe observed runs, not a performance guarantee.

```sh
python3 -m pytest \
  tests/security/test_source_truth_audit.py \
  tests/security/test_ledger_reference_depth.py \
  tests/security/test_developer_documentation.py \
  tests/security/test_docs_links.py \
  tests/security/test_documentation_contract.py \
  tests/security/test_documentation_source_facts.py \
  tests/security/test_structure_include_paths.py \
  tests/security/test_structure_boundaries.py \
  tests/audit/test_static_safety.py::StaticSafetyTests::test_ledger_validator_executes_with_no_database \
  -q -rs
```

The two new executable cases use PHP in separate local processes, synthetic identities/keys and no configuration bootstrap, network or database. They confirm access/refresh format, same-key verification and rejection under a different fixture key. The five other new cases are source/documentation regressions, not HTTP, deployment, recovery or device tests. A successful local suite does not complete semantic review or substitute for exact-head CI.

## 12. Regression Check

Review the entire correction diff, check whitespace and relative links, and confirm only the listed documentation, manifests and new test file changed. Preserve runtime, configuration, dependencies, schema, permissions, workflows and original dated failure evidence. Earlier green PR45/main checks belong to those revisions, not this audit branch. Push/PR/check/merge outcomes must be recorded separately when actually observed.

## 13. Remaining Risks

- All 113 original paths still lack full audit clearance; five have only selected-claim corrections. The two deleted documents (`docs/ci_cd/ARCHITECTURE.md`, `docs/ci_cd/ci.yml.txt`) still need original-content/retirement evidence review.
- All 31 workspace deliverables need edition/provenance, claim and source reconciliation. Preserve them as historical editions; do not silently overwrite them or describe them as audited current references.
- Source comments still contain JWT/fleet wording; this correction does not change those source dependencies or certify neighboring behavior.
- Complete field/relationship/permission/error/action reconciliation, source/test/workflow review, every-control manual coverage and hosted/device acceptance remain open.
- Hosted schema, secrets placement, feature state, certificates, private-register operation, backup copies/custody/recovery and deployment parity remain unverified. Production work remains separately authorized only.

## 14. Assumptions

The known recent-work boundary is a conservative starting inventory, not proof that older/unattributed work is covered. No missing fact is filled with an invented business rule. Written owner/deployment/security requirements are labeled as policy, not as installed controls. Any source or access gap stays open.

## 15. Final Status

**INCOMPLETE — ADDITIONAL WORK REQUIRED.** Nine selected finding groups have corrections, not whole-file or whole-system clearance. Keep the broad audit open until each original path, deleted artifact, supporting source dependency and workspace edition has an evidenced disposition. Completed correction updates must be pushed; access/check failures block integration, not justify a fake success claim. No production promotion is authorized by this record.
