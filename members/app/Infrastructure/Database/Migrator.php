<?php
/**
 * Members app — migration runner.
 *
 * WHY A RUNNER AT ALL
 *   The repository's migrations are applied by hand through phpMyAdmin. That is
 *   fine for a schema the whole site shares, but it means nobody can say which
 *   migrations a given database has. This runner records what it applied in
 *   `members_schema_migrations`, so "is this box up to date?" is a query, not a
 *   guess.
 *
 * SAFETY RULES, ENFORCED HERE
 *   - One transaction per file where the engine allows it; DDL in MySQL
 *     auto-commits, so the recorded row is written only after success.
 *   - A file is applied exactly once. Re-running is a no-op, not a re-apply.
 *   - Dry run lists what WOULD happen and touches nothing.
 *   - CLI only. Reachable-from-web migrations are how a database gets rebuilt
 *     by a crawler.
 *
 * THIS CLASS CREATES NO SCHEMA OF ITS OWN.
 *   It used to bootstrap its ledger table on first run. The repository's
 *   request-time-schema guard refuses DDL text anywhere on a normal
 *   application path, and the objection is correct: a class that defines schema
 *   is schema living in two places, and "run the migrator" would be the moment a
 *   database silently grows a table nobody reviewed. The ledger is declared in
 *   sql/000_members_migration_ledger.sql like every other table. This runner
 *   only READS it, and refuses to run when it is absent.
 */

declare(strict_types=1);

namespace SSMS\Members\Infrastructure\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    private PDO $pdo;
    private string $directory;

    public function __construct(PDO $pdo, ?string $directory = null)
    {
        $this->pdo = $pdo;
        $this->directory = $directory ?? MEMBERS_ROOT . '/sql';
    }

    /**
     * Fail loudly if the ledger is missing.
     *
     * The message names the file to apply, because the alternative — creating
     * it here — is the runtime DDL this class deliberately does not do.
     */
    private function requireLedger(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables"
            . " WHERE table_schema = DATABASE() AND table_name = 'members_schema_migrations'"
        );
        if ((int)$stmt->fetchColumn() === 0) {
            throw new RuntimeException(
                'Migration ledger is missing. Apply sql/000_members_migration_ledger.sql first.'
            );
        }
    }

    /**
     * Pending migrations, in filename order.
     *
     * @return list<array{file:string,checksum:string}>
     */
    public function pending(): array
    {
        $this->requireLedger();
        $applied = $this->applied();
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('Cannot checksum migration: ' . $name);
            }
            if (!isset($applied[$name])) {
                $out[] = ['file' => $name, 'checksum' => $checksum];
            }
        }
        return $out;
    }

    /**
     * Apply pending migrations.
     *
     * @return list<string> filenames applied
     */
    public function run(): array
    {
        $appliedNow = [];
        foreach ($this->pending() as $migration) {
            $path = $this->directory . '/' . $migration['file'];
            $sql = file_get_contents($path);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Migration is empty or unreadable: ' . $migration['file']);
            }

            // Refuse files the runner cannot split safely: DELIMITER blocks are
            // a phpMyAdmin convenience, and silently mis-splitting one would
            // execute half a stored procedure.
            if (stripos($sql, 'DELIMITER') !== false) {
                throw new RuntimeException(
                    'Migration uses DELIMITER and must be applied by hand: ' . $migration['file']
                );
            }

            foreach ($this->split($sql) as $statement) {
                $this->pdo->exec($statement);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO members_schema_migrations (filename, checksum) VALUES (:f, :c)'
            );
            $stmt->execute([':f' => $migration['file'], ':c' => $migration['checksum']]);
            $appliedNow[] = $migration['file'];
        }
        return $appliedNow;
    }

    /**
     * Migrations whose file has changed since it was applied.
     *
     * A changed migration means somebody edited history. It is not repaired
     * automatically — it is reported, because the fix depends on what changed.
     *
     * @return list<string>
     */
    public function drift(): array
    {
        $this->requireLedger();
        $drifted = [];
        foreach ($this->applied() as $name => $checksum) {
            $path = $this->directory . '/' . $name;
            if (!is_file($path)) {
                $drifted[] = $name . ' (missing)';
                continue;
            }
            $current = hash_file('sha256', $path);
            if ($current !== false && $current !== $checksum) {
                $drifted[] = $name . ' (modified)';
            }
        }
        return $drifted;
    }

    /** @return array<string,string> filename => checksum */
    private function applied(): array
    {
        $rows = $this->pdo
            ->query('SELECT filename, checksum FROM members_schema_migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        /** @var array<string,string> $rows */
        return $rows;
    }

    /**
     * Split a .sql file into executable statements.
     *
     * Quote- and comment-aware: a semicolon inside a string literal or a
     * comment must not split the statement. This is a small parser, not a
     * regex, because the regex version of this has a well-known failure.
     *
     * @return list<string>
     */
    private function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            // Backslash escapes only apply inside quoted strings, and only
            // where the mode is not NO_BACKSLASH_ESCAPES (the default here).
            if ($char === '\\' && ($inSingle || $inDouble)) {
                $buffer .= $char . $next;
                $i++;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}
