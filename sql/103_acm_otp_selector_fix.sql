-- ============================================================
-- 103_acm_otp_selector_fix.sql
--
-- BLOCKER FIX. Migration 082 made `acm_activation_codes.selector` NOT NULL
-- with a global UNIQUE key:
--
--     `selector` VARCHAR(16) ... NOT NULL,
--     UNIQUE KEY `uniq_acm_code_selector` (`selector`)
--
-- That was correct for the selector+secret pair, where the selector is a
-- random public lookup key. It is catastrophic for the S12 six-digit OTP,
-- which has no selector: every OTP row wrote `selector = ''`, and a
-- UNIQUE key permits exactly ONE empty string.
--
-- OBSERVED FAILURE (reproduced in a real SQL engine):
--     first  activation code issued  -> ok
--     second activation code issued  -> SQLSTATE 23000 on
--         uniq_acm_code_selector
--     reissue on the same ticket     -> same failure
--
-- So activation worked exactly once per database, then failed forever
-- with "Code issuance failed." The retry loop inside issueCode() cannot
-- help: it regenerates the SELECTOR on 23000, but an OTP row has no
-- selector to regenerate, so all five attempts write '' and all five fail.
--
-- TWO THINGS WERE WRONG, SO THIS FIXES BOTH
--
--   1. The empty string. A nullable selector is the honest model: an OTP
--      row genuinely HAS no selector. MySQL's UNIQUE permits many NULLs
--      and only one ''. So the column becomes NULL-able and the app
--      writes NULL (see ActivationService::issueCode).
--
--   2. The scope of the key. A global UNIQUE on the selector was also
--      wrong for the pair form: the SELECTOR is an indexed lookup key, so
--      it must be unique among rows that HAVE one -- all of them -- which
--      a plain UNIQUE already gives once NULLs are excluded. Keeping the
--      key makes the "two live codes share a selector" impossibility
--      structural instead of incidental.
--
-- WHY NOT A GENERATED COLUMN
--   Migration 100 used a generated column for the ticket open-guard
--   because the condition was a business rule that belonged in the schema.
--   Here the condition is simply "NULL-ness", which UNIQUE already
--   excludes. A generated column would add a second source of truth for
--   no gain.
--
-- ORDER OF OPERATIONS
--   Drop the key, widen the column, re-add the key. If the column were
--   widened first, existing '' values would become NULL -- fine -- but the
--   old key would still be enforcing the cardinality-1 limit during the
--   window. Both blocks are guarded on information_schema so the file is
--   re-runnable and safe on a database that only ever ran the pair form.
--
-- NO DATA LOSS
--   This widens a constraint; it never deletes a row. Existing rows with a
--   real selector keep it. Existing rows with '' (which can be at most
--   one, by definition of the broken key) are converted to NULL, which is
--   what an OTP row should have had.
-- ============================================================

-- 1. Drop the unique key if it still exists.
SET @ssms_103_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_activation_codes'
              AND index_name = 'uniq_acm_code_selector'
        ),
        'ALTER TABLE `acm_activation_codes` DROP INDEX `uniq_acm_code_selector`',
        'SELECT 1'
    )
);
PREPARE ssms_103_stmt FROM @ssms_103_sql;
EXECUTE ssms_103_stmt;
DEALLOCATE PREPARE ssms_103_stmt;

-- 2. Empty selectors become NULL, which is what an OTP row means.
--    Done before the column is widened so the value and the type agree.
UPDATE `acm_activation_codes` SET `selector` = NULL WHERE `selector` = '';

-- 3. Widen the column to allow that NULL. Guarded on IS_NULLABLE so the
--    statement is skipped when it is already correct.
SET @ssms_103_sql = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_activation_codes'
              AND column_name = 'selector'
              AND is_nullable = 'NO'
        ),
        'ALTER TABLE `acm_activation_codes` MODIFY COLUMN `selector` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL',
        'SELECT 1'
    )
);
PREPARE ssms_103_stmt FROM @ssms_103_sql;
EXECUTE ssms_103_stmt;
DEALLOCATE PREPARE ssms_103_stmt;

-- 4. Re-add the key. Unique across the rows that HAVE a selector; NULLs
--    are excluded by MySQL, so any number of OTP rows coexist.
SET @ssms_103_sql = (
    SELECT IF(
        NOT EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'acm_activation_codes'
              AND index_name = 'uniq_acm_code_selector'
        ),
        'ALTER TABLE `acm_activation_codes` ADD UNIQUE KEY `uniq_acm_code_selector` (`selector`)',
        'SELECT 1'
    )
);
PREPARE ssms_103_stmt FROM @ssms_103_sql;
EXECUTE ssms_103_stmt;
DEALLOCATE PREPARE ssms_103_stmt;

-- Verification: the widest a mobile-visible break could get. This must
-- report 0 rows.
SELECT `id`, `selector`, `purpose`, `status` FROM `acm_activation_codes`
WHERE `selector` = '' LIMIT 10;
