<?php
/**
 * Members app — configuration.
 * ---------------------------------------------------------------------
 * NO SECRETS IN THIS FILE. Database credentials live in the repository's
 * existing `config.php` / `school_config.php` (the single source the rest of
 * the site already uses). This file holds only app-level policy that is safe
 * to read in a code review.
 *
 * An environment override exists for local work only:
 *   MEMBERS_ENV=local
 */

declare(strict_types=1);

return [
    /**
     * Which API the app talks to.
     *
     * The prototype grew its own parallel API (`/api/learning/*`, `/api/quiz/*`).
     * ARCHIVE.md says plainly: do not build a second API surface. Members reuse
     * `/api/v1` for everything the platform already answers — identity, the
     * member record, parents' children, attendance, notifications — and add a
     * new resource there (`/api/v1/learning`) only for what genuinely does not
     * exist yet. This app holds no endpoints of its own.
     */
    'api_base' => '/api/v1',

    /**
     * Session/token handling.
     *
     * The member app NEVER sees a password hash and never issues a token.
     * Login is POST /api/v1/auth/login, which returns the same bearer token the
     * phone app uses. The browser holds it in `sessionStorage`, not a cookie,
     * so there is no cookie to forge and no CSRF surface on state changes.
     */
    'token_storage_key' => 'fk_members_token',

    /**
     * Access token lifetime, mirrored from the API so the client can refresh
     * proactively instead of discovering expiry mid-action.
     */
    'access_token_ttl_seconds' => 3600,

    /** Supported interface languages, in display order. */
    'locales' => ['am', 'en'],
    'default_locale' => 'am',

    /**
     * Content Security Policy for pages this app renders.
     *
     * `script-src 'self'` with NO 'unsafe-inline' forces every handler into
     * app.js. That is deliberate: inline handlers are where XSS lives, and a
     * strict policy means a future template author cannot accidentally
     * reintroduce them.
     */
    'csp' => "default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self'; "
        . "script-src 'self'; "
        . "connect-src 'self'; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'",

    /** Unlock rules, re-implemented from the prototype's considered rule set. */
    'learning' => [
        // Unit 1 is always open.
        'first_unit_always_open' => true,
        // Within an open unit, the first N lessons are open.
        'open_lessons_per_unit' => 2,
        // A later lesson needs a teacher unlock.
        'later_lessons_need_unlock' => true,
        // Unit N+1 requires every lesson of unit N complete + its quiz passed.
        'unit_requires_previous_complete' => true,
    ],

    /** Pagination defaults. The API enforces its own hard maximum too. */
    'pagination' => [
        'default' => 20,
        'max' => 100,
    ],
];
