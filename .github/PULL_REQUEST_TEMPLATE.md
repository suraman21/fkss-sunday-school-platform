## Checklist (see BRANCHING.md)

- [ ] This PR's **head branch is NOT `dev`** (the beta‑app branch is never merged into `main`).
- [ ] No beta values on `main`: no `arkeonethiopia.com/api`, `fkss.beta`, `FKSS Beta`, `isBeta` in `Mobile/wbws_flutter_app`.
- [ ] `python3 -m pytest tests -q --ignore=tests/mutation` green · `flutter test` green.
- [ ] New SQL (if any) is additive and idempotent, and listed in the PR description.
- [ ] All user‑facing dates are Ethiopian calendar.
