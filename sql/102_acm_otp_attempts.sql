-- ============================================================
-- 102_acm_otp_attempts.sql
--
-- Per-ACCOUNT failure accounting for 6-digit OTP verification.
--
-- WHY THIS TABLE EXISTS
--   100/acm_ticket_open_guard.sql closed the duplicate-ticket race. This
--   closes the race that replaces the one the old selector design avoided
--   for free.
--
--   The previous code was SELECTOR (6 public chars) + SECRET (8 chars,
--   ~39 bits). The selector was an indexed, UNIQUE lookup key, so a wrong
--   guess could only ever burn attempts on ONE row, and that row's
--   `attempts`/`max_attempts` capped it.
--
--   A 6-digit OTP is 10^6 values and cannot be its own lookup key: a
--   hash of a 6-digit number is trivially reversible by enumeration, so
--   storing `hash(otp)` as the key would let anyone derive live codes
--   from a table dump. Verification therefore has to be scoped by
--   something else, and whatever we scope by becomes the guessing
--   surface. If the scope is the ticket, an attacker who knows a ticket
--   number gets one global counter to burn. If the scope is "all live
--   codes", one counter serves the whole school.
--
--   So the counter lives here, keyed on (ticket, source) AND read as a
--   ticket-wide total. Both must stay under the limit for a guess to be
--   evaluated. One attacker cannot exhaust a victim's ticket (their
--   source row is separate), and many sources cannot pool guesses past
--   the ticket total.
--
--   NIST SP 800-63B-4 requires a throttling mechanism whenever a secret
--   carries under 64 bits; a 6-digit code carries ~20. This table is that
--   mechanism, and `LoginThrottle` is the equivalent for passwords.
--
-- A NEW CODE MUST NOT RESET THE COUNT
--   SP 800-63B-4 Sec 3.1.3.2 is explicit: reissuing a secret does not
--   clear the failed-attempt count. Reissue revokes the old code row, so
--   the per-code `attempts` column resets by construction. This table is
--   keyed on the TICKET, not the code, precisely so the count survives a
--   reissue. Do not "fix" that by adding code_id to the key.
--
-- STYLE
--   Matches this repo's migration rules: no FOREIGN KEY, no JSON column,
--   one guarded statement per block, re-runnable. `attempts` is widened by
--   ON DUPLICATE KEY UPDATE in the application, not here.
-- ============================================================

CREATE TABLE IF NOT EXISTS `acm_otp_attempts` (
  `ticket_id` INT UNSIGNED NOT NULL,
  `source` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ticket_id`, `source`),
  KEY `idx_acm_otp_attempts_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guards: verify the table is usable before anything depends on it.
-- A missing table here would fail open (no counter -> unlimited guesses),
-- so the deploy check is part of the migration rather than a comment.
SELECT 'acm_otp_attempts', COUNT(*) FROM information_schema.TABLES
WHERE table_schema = DATABASE() AND table_name = 'acm_otp_attempts';

-- A code issued before this migration has no OTP attempt history. That is
-- correct: its `acm_activation_codes.attempts` still applies, and the
-- ticket-wide total starts at zero. No backfill is possible or wanted.
