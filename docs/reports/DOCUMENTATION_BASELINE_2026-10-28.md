# Documentation baseline — 2026-10-28

**Record type:** dated verification evidence, not a live deployment record.

## Revision and provenance

- Repository: `suraman21/fkss-sunday-school-platform` (development).
- Reviewed baseline: `7209278b44dd35f0e2cf9ec94d198ee968bb10dc`, merge of Profile App updates.
- Prior upload/CI repair: PR #41, merge `bf924d7`.
- Profile App updates: PR #42, merge `7209278`; final feature head `26e37e1`.
- First available Git record: `773f278`, **Initial commit**, 2026-08-15. This establishes the start of the available repository history, not the start of the school or all development work.
- Source mobile version: `1.7.4+41`, [pubspec.yaml](../../Mobile/wbws_flutter_app/pubspec.yaml). A source version is not publication or installed-fleet evidence.

## Baseline checks

The public GitHub checks endpoint for the full baseline SHA returned eight completed checks, all with conclusion `success`, when read on 2026-10-28:

| Check | GitHub job |
|---|---|
| PHP syntax (php -l) | [122543727086](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543727086) |
| PHP 8.5 syntax and deprecations | [122543727258](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543727258) |
| Security and regression suite | [122543727084](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543727084) |
| Flutter unit tests | [122543726780](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543726780) |
| Migration numbering | [122543727076](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543727076) |
| Android debug APK | [122543727115](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463582/job/122543727115) |
| Repository integrity (secrets, artifacts, history) | [122543726848](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463602/job/122543726848) |
| main must build the production app | [122543726833](https://github.com/suraman21/fkss-sunday-school-platform/actions/runs/40605463694/job/122543726833) |

**VERIFIED:** these are successful check conclusions for the baseline, not checks of a later documentation branch. No job log was inferred from its display name. The configured backend zero-skip gate is established separately in [backend-checks.yml](../../.github/workflows/backend-checks.yml).

## Documentation contradictions resolved by this baseline

- The 79-skip failure described in earlier onboarding/CI pages is historical. The checked-in workflow now installs `openpyxl` and starts the isolated seeded HTTP test world. Preserve earlier reports as dated records, not current warnings.
- Version `1.7.3+40` is a previous source checkpoint, not the baseline source version.
- The owner's current release policy is development-main integration, development-server update and owner testing, then separately authorized production promotion. Earlier descriptions of automatically keeping both repository mains identical must not override it.
- Logout is a choice-based recovery flow, not unconditional deletion: [session_logout_dialog.dart](../../Mobile/wbws_flutter_app/lib/widgets/session_logout_dialog.dart) and `SessionCoordinator.applyLogoutChoice()` in [session_service.dart](../../Mobile/wbws_flutter_app/lib/services/session_service.dart).
- [DEPLOYMENT_RUNBOOK.md](../audits/DEPLOYMENT_RUNBOOK.md) remains a required migration/restore safety reference. Its historical label does not authorize deletion.

## Evidence limits

No production server, school database, hosted feature flag, signing key or phone was accessed for this review. Repository parity, hosting cron execution, deployed schema, published APK, installed version and restore readiness are **UNKNOWN** unless supported by their own dated acceptance records. A check's success is not proof that every user workflow, role or button was exercised.
