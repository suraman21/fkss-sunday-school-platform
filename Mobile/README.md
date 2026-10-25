# Mobile/

The Android app. Nothing here is part of the website: the root `.htaccess` blocks this folder.

| Path | What it is |
|---|---|
| `wbws_flutter_app/` | The Flutter app (`fkss_app`). Start at [wbws_flutter_app/README.md](wbws_flutter_app/README.md); build and signing: [wbws_flutter_app/docs/RELEASE.md](wbws_flutter_app/docs/RELEASE.md); release log: [wbws_flutter_app/RELEASE_NOTES.md](wbws_flutter_app/RELEASE_NOTES.md) |
| `app_icon.png`, `splash_logo.png` | **Build inputs.** `wbws_flutter_app/pubspec.yaml` builds the launcher icon and splash screen from them (`../app_icon.png`, `../splash_logo.png`). Do not move or rename them |
| `app_icon.jpg`, `logo_felege_kidusan.png`, `logo_felege_kidusan.webp` | Source artwork for the branding. Not referenced by the build |
| `code_dump.txt` | Old text copy of the app's source code. Not used by anything |

**Rules**
- `main` builds the production app: its API address in `lib/utils/config.dart` is fixed to production, and CI checks this. Beta builds come from the `dev` branch ([BRANCHING.md](../BRANCHING.md)).
- Never commit signing keys, keystores or built APKs; CI rejects them.
- Dart files use snake_case names ([CONTRIBUTING.md §9](../CONTRIBUTING.md#9-naming-and-where-files-go)).
