# ADR-0022: Member search via a TNTSearch file index

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-11-18 | Maintainers |

## Context

Member lookup is scattered across ~13 `LIKE` queries, 2 `FULLTEXT` services and per-page matchers. Amharic queries fail across spelling variants (ሐ/ሀ/ኀ, ጸ/ፀ, doubled Latin letters, Fidel-vs-Latin input), and typo tolerance differs per page. Constraints from [ADR-0001](0001-php-monolith-on-shared-hosting.md) still hold: shared hosting, no Composer on the server, no Redis, no background workers — so the engine must be a committed `vendor/` library with a file-based index, and search must keep working when the engine cannot run.

## Options considered

1. **Hand-rolled query expansion in MySQL.** Keep `LIKE`/`FULLTEXT` and expand each query into spelling variants. Pro: no new dependency. Con: every page re-implements ranking; `LIKE '%…%'` cannot index-scan; typo tolerance stays absent.
2. **MySQL `FULLTEXT` with the ngram parser.** Pro: no new files. Con: needs server-level parser configuration the shared host does not offer; still no transliteration or typo tolerance; relevance tuning is coarse.
3. **TNTSearch with the SQLite (file) engine.** Pro: MIT-licensed, PHP 8 compatible, zero daemons; supports custom tokenizers, boolean queries, as-you-type and fuzzy search; index is one file in a writable directory. Con: a new committed dependency (~3 packages); index writes happen inside web requests; prefix expansion returns a single term (worked around in the caller, see Decision).
4. **External search service (Elasticsearch/Typesense).** Rejected: violates ADR-0001 (no daemons, no shell, member-name PII must not leave the host).

## Decision

Use `teamtnt/tntsearch` (MIT, committed in `vendor/` per ADR-0001) with the file engine for member search:

- `EthiopicSearchTokenizer` bakes the Amharic pipeline (verified homophone folding + Latin light normalization) into index and query time, the same code path by construction.
- `AmharicTransliterator` (v1, pinned by `tests/fixtures/amharic_transliteration.fixture`) indexes Fidel→Latin spelling variants so Latin queries find Ethiopic-stored names and vice versa.
- `MemberSearchService::searchRanked()` runs a 4-stage cascade (caller exacts → boolean AND → relevance/prefix → fuzzy); exact-ID/name matches win by construction. Single-token prefix queries fan out to the top matching terms because the engine's as-you-type expands to one term only.
- The service never fails a request: missing index, missing `pdo_sqlite`, or any engine error returns `null` and the caller runs the previous MySQL query (honest `engine` field in the response: `tnt` or `mysql`).
- Write-through hooks (HR register, info edit, import commit, Telegram approval) re-index one row post-commit inside try/catch; a resumable backfill endpoint (super_admin) rebuilds the index in batches.
- The index lives in gitignored `admin/var/search/` (Apache deny via `.htaccess`; nginx mirror in [OPERATIONS.md](../OPERATIONS.md)); filters, pagination and role projection stay in MySQL.

## Consequences

- **Positive:** one ranked engine replaces scattered matchers; cross-script queries, typo tolerance and prefix search work everywhere the service is used; zero new daemons; instant rollback (delete the index → MySQL fallback).
- **Negative / risks:** index writes add milliseconds to member saves (bounded, single-row); the index file holds member-name PII and must keep its deny rules; fuzzy matches can surprise (bottom-ranked by design); multi-word prefix queries expand the last word only (documented P1).
- **Follow-up work:** synonym table + admin UI (P1); port remaining modules to the engine one at a time behind per-module flags (guardians/pickers, Telegram duplicates, hymns, then the rest); nothing is deleted from the old matchers until the engine is live-proven.

## Evidence

`admin/backend/services/MemberSearchService.php`, `EthiopicSearchTokenizer.php`, `AmharicTransliterator.php`; `admin/api_search.php`, `admin/api_search_backfill.php`; `tests/smoke/search_p0_smoke.php` (34 checks green on 2026-11-18); [OPERATIONS.md](../OPERATIONS.md) (index folder, preflight, nginx mirror).
