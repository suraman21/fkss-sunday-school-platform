# Removing demo rows from `members` (demo server only)

## Why this is a runbook and not a migration

The `members` rows on the demo server carry a registration code in the
`grandfather_name` column:

```
Mimi Alemu Lemma (DEMO-REG-20261123-X7K4-060)
```

Nothing in this repository produces a `DEMO-REG-…` string (verified: no
occurrence in any `.php`, `.sql`, `.js` or `.py` file), so it was written by
an import or by hand on that server. It is demo data, not application
behaviour, and it is not in the production repository
(`Felege-Kidusan/felegekidusan`).

`CONTRIBUTING.md` and the CI step *"Migrations are not silently destructive"*
both treat row deletion as a **deployment decision**, not a migration step: a
bare `DELETE FROM` against a user-data table is exactly how a cleanup gets
smuggled past review. So this is a reviewed, explicit script you run yourself
against the demo database, with a dry run first.

## 1. Look before you delete

```sql
-- Everything that looks like demo data. Expect ONLY demo rows here.
SELECT id, member_code, student_name, grandfather_name, created_at
  FROM members
 WHERE grandfather_name LIKE 'DEMO-%'
    OR full_name_am LIKE '%DEMO-%'
 ORDER BY id;
```

Confirm the count matches what the Members Browser shows for demo rows. If a
row in that list is **not** demo data, stop — do not run step 2.

## 2. Check nothing depends on them

```sql
-- These must all be zero before deletion, or you would orphan records.
SELECT 'class_enrollments' AS dep, COUNT(*) AS n
  FROM class_enrollments WHERE member_id IN (SELECT id FROM members WHERE grandfather_name LIKE 'DEMO-%')
UNION ALL SELECT 'academic_records', COUNT(*)
  FROM academic_records WHERE member_id IN (SELECT id FROM members WHERE grandfather_name LIKE 'DEMO-%')
UNION ALL SELECT 'acm_persons', COUNT(*)
  FROM acm_persons WHERE member_id IN (SELECT id FROM members WHERE grandfather_name LIKE 'DEMO-%');
```

## 3. Remove them (demo database only)

Run inside a transaction so a mistake is recoverable until you commit:

```sql
START TRANSACTION;

-- Anyone whose person row was derived from a demo member goes too.
DELETE FROM acm_persons
 WHERE member_id IN (SELECT id FROM members WHERE grandfather_name LIKE 'DEMO-%');

DELETE FROM members WHERE grandfather_name LIKE 'DEMO-%';

-- Read the count before committing. It must equal the count from step 1.
SELECT ROW_COUNT();

COMMIT;   -- or ROLLBACK if the number is wrong
```

## 4. Verify

Reload `/admin/dashboard?section=members`. **Showing 1–50 of N** must have
dropped by exactly the number of demo rows, and no "DEMO-REG-…" text may
remain in a profile card.

## Why `grandfather_name` in production matters more than the demo rows

If any of these rows exist in production, the demo server is not the only
place that was seeded, and the export path is a problem on its own: the
Member Export and the ID-card renderer both print `grandfather_name`
verbatim, so a registration code would appear on a printed card. The office
should confirm production has no `DEMO-%` rows:

```sql
SELECT COUNT(*) FROM members WHERE grandfather_name LIKE 'DEMO-%';
```
