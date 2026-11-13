<?php
/** Opt-in storage boundary only: not authorization, completeness or a lease. */
namespace App\Services;

final class AttendancePreparationReadBoundary
{
    /**
     * Run trusted strict application readers in one owned, read-only snapshot.
     * The caller must declare EVERY table those readers depend on, including
     * feature-enabled dependencies, and must not manage transactions or DDL.
     * This is not a SQL sandbox. No legacy caller is switched implicitly.
     *
     * @param string[] $tables Real, unqualified application table identifiers
     * @return mixed Successful empty results are preserved, not failures.
     */
    public static function read(\mysqli $conn, array $tables, callable $reader): mixed
    {
        if (!$tables || !array_is_list($tables)) {
            throw new \InvalidArgumentException('Preparation tables must be a nonempty list.');
        }
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table)) {
                throw new \InvalidArgumentException('Invalid preparation table identifier.');
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        $owned = false;
        $configured = false;
        try {
            $result = self::query($conn, 'SELECT @@session.autocommit AS enabled');
            try {
                $autocommit = $result->fetch_assoc();
            } finally {
                $result->free();
            }
            if ((int)($autocommit['enabled'] ?? 0) !== 1) {
                throw new \RuntimeException('Preparation requires an independent connection transaction.');
            }
            // Next-transaction only, not SESSION: preserve connection defaults.
            // Both MySQL and MariaDB reject this inside an existing transaction;
            // do not call BEGIN (which could implicitly end someone else's work)
            // until this succeeds. A rejected outer transaction is never ours.
            if ($conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== true) {
                throw new \RuntimeException('Preparation isolation could not be established.');
            }
            $configured = true;
            if (!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
                throw new \RuntimeException('Preparation snapshot could not be started.');
            }
            $owned = true;
            $configured = false;
            foreach ($tables as $table) {
                self::requireTransactionalTable($conn, $table);
            }
            $value = $reader($conn);
            if (!$conn->commit()) {
                throw new \RuntimeException('Preparation snapshot could not be completed.');
            }
            $owned = false;
            return $value;
        } catch (\Throwable $error) {
            if ($configured) {
                // BEGIN failed after setting next-transaction characteristics.
                // Do not leak pending isolation into unrelated connection work,
                // or assume whether a failed BEGIN reached the server.
                try { $conn->close(); } catch (\Throwable $ignored) {}
            }
            if ($owned) {
                try {
                    if (!$conn->rollback()) {
                        throw new \RuntimeException('Preparation rollback was not confirmed.');
                    }
                } catch (\Throwable $rollbackError) {
                    // Never leave an uncertain owned transaction reusable.
                    try { $conn->close(); } catch (\Throwable $ignored) {}
                    throw new \RuntimeException('Preparation cleanup failed; connection discarded.', 0, $rollbackError);
                }
            }
            throw $error;
        }
    }

    /** Call only inside an already-owned transaction before its first write. */
    public static function requireTransactionalTable(\mysqli $conn, string $table): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table)) {
            throw new \InvalidArgumentException('Invalid storage table identifier.');
        }
        // A real read acquires a transaction-duration metadata lock before the
        // metadata check; engine/schema replacement cannot race later readers.
        self::query($conn, 'SELECT 1 FROM `' . $table . '` LIMIT 0')->free();
        $definition = self::query($conn, 'SHOW CREATE TABLE `' . $table . '`');
        try {
            $row = $definition->fetch_assoc();
        } finally {
            $definition->free();
        }
        // information_schema lists persistent tables, not temporary shadows.
        // Inspect the actual resolved object too; otherwise a MyISAM temporary
        // table could shadow a persistent InnoDB table and pass metadata checks.
        $ddl = $row['Create Table'] ?? null;
        if (!is_string($ddl) || !preg_match('/^CREATE TABLE\s/Di', $ddl)) {
            throw new \RuntimeException('Preparation requires an unshadowed base table.');
        }
        $stmt = $conn->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        if (!$stmt) {
            throw new \RuntimeException('Preparation storage metadata is unavailable.');
        }
        try {
            if (!$stmt->bind_param('s', $table) || !$stmt->execute()) {
                throw new \RuntimeException('Preparation storage metadata could not be read.');
            }
            $result = $stmt->get_result();
            if (!$result) {
                throw new \RuntimeException('Preparation storage metadata result is unavailable.');
            }
            try {
                $metadata = $result->fetch_assoc();
                if ($result->num_rows !== 1 || ($metadata['TABLE_NAME'] ?? null) !== $table
                    || ($metadata['TABLE_TYPE'] ?? null) !== 'BASE TABLE'
                    || strcasecmp((string)($metadata['ENGINE'] ?? ''), 'InnoDB') !== 0) {
                    throw new \RuntimeException('Preparation requires a real InnoDB base table.');
                }
            } finally {
                $result->free();
            }
        } finally {
            $stmt->close();
        }
    }

    private static function query(\mysqli $conn, string $sql): \mysqli_result
    {
        $result = $conn->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('Preparation storage read failed.');
        }
        return $result;
    }
}
