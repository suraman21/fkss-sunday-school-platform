<?php
/**
 * ACM S1 — office governance service.
 * ------------------------------------------------------------
 * Office modes (separated/solo), designated approvers, independent
 * reviewers, monthly audit reports and attestations.
 * Pre-migration safe: every reader degrades gracefully when 080 is
 * not applied yet (mode defaults to separated; designations read as
 * absent; writers fail with a clear message). Code deploys before
 * the owner applies migrations, so this is load-bearing.
 */

namespace SSMS\Acm\Office;

use InvalidArgumentException;
use PDO;
use RuntimeException;

class AcmOfficeService
{
    public const MODE_SEPARATED = 'separated';
    public const MODE_SOLO = 'solo';
    public const REPORT_SCHEMA = 's1.1';
    public const REPORT_CAP = 5000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- office mode ----

    public function mode(): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1'
            );
            $stmt->execute([':k' => 'acm.office_mode']);
            $mode = trim((string)($stmt->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return self::MODE_SEPARATED;
        }
        return $mode === self::MODE_SOLO ? self::MODE_SOLO : self::MODE_SEPARATED;
    }

    /**
     * Switch office mode. super_admin only (enforced), reason +
     * effective date required, permanent history row. Separated mode
     * requires at least two active officers; with one officer the
     * office must use solo mode (never silent self-approval).
     */
    public function setMode(string $mode, string $reason, string $effectiveDate, int $actorId, bool $isSuperAdmin): string
    {
        if (!$isSuperAdmin) {
            throw new InvalidArgumentException('Only a Super Admin can change the office mode.');
        }
        if ($mode !== self::MODE_SEPARATED && $mode !== self::MODE_SOLO) {
            throw new InvalidArgumentException('Unknown office mode.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A reason (3-2000 chars) is required.');
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $effectiveDate)) {
            throw new InvalidArgumentException('Effective date must be YYYY-MM-DD.');
        }
        if ($mode === self::MODE_SEPARATED && $this->countActiveOfficers() < 2) {
            throw new InvalidArgumentException(
                'Separated mode needs at least two active officers. Designate approvers and switch to solo mode instead.'
            );
        }
        $this->pdo->beginTransaction();
        try {
            $upsert = $this->pdo->prepare(
                'INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)'
                . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $upsert->execute([':k' => 'acm.office_mode', ':v' => $mode]);
            $hist = $this->pdo->prepare(
                'INSERT INTO acm_office_mode_history (mode, reason, effective_date, changed_by)'
                . ' VALUES (:m, :r, :e, :by)'
            );
            $hist->execute([
                ':m' => $mode,
                ':r' => $reason,
                ':e' => $effectiveDate,
                ':by' => $actorId > 0 ? $actorId : null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $mode;
    }

    public function modeHistory(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT h.id, h.mode, h.reason, h.effective_date, h.changed_by, h.changed_at,'
                . ' u.username AS changed_name FROM acm_office_mode_history h'
                . ' LEFT JOIN users u ON u.id = h.changed_by'
                . ' ORDER BY h.id DESC LIMIT ' . (int)$limit
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function countActiveOfficers(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'accounts_office' AND is_active = 1"
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ---- designations (approvers + reviewers share one governed flow) ----

    public function approvers(): array
    {
        return $this->designations('acm_office_approvers');
    }

    public function reviewers(): array
    {
        return $this->designations('acm_office_reviewers');
    }

    public function isActiveApprover(int $userId): bool
    {
        return $this->isActiveDesignee('acm_office_approvers', $userId);
    }

    public function isActiveReviewer(int $userId): bool
    {
        return $this->isActiveDesignee('acm_office_reviewers', $userId);
    }

    public function designateApprover(int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): array
    {
        return $this->designate('acm_office_approvers', $userId, $reason, $effective, $actorId, $isSuperAdmin);
    }

    public function revokeApprover(int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): void
    {
        $this->revoke('acm_office_approvers', $userId, $reason, $effective, $actorId, $isSuperAdmin);
    }

    public function designateReviewer(int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): array
    {
        return $this->designate('acm_office_reviewers', $userId, $reason, $effective, $actorId, $isSuperAdmin);
    }

    public function revokeReviewer(int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): void
    {
        $this->revoke('acm_office_reviewers', $userId, $reason, $effective, $actorId, $isSuperAdmin);
    }

    // ---- monthly audit report + attestation ----

    /**
     * Build the month report and its snapshot hash. The hash covers the
     * canonical JSON (month, period, schema, generated_at, rows) so the
     * attest call can detect a changed report before signing it.
     */
    public function auditReport(string $month): array
    {
        [$from, $to] = $this->monthBounds($month);
        $generatedAt = date('Y-m-d H:i:s');
        $audit = $this->reportRows(
            'SELECT id, user_id, username, action, details, ip_address, created_at'
            . " FROM activity_logs WHERE action LIKE 'acm.%'"
            . ' AND created_at >= :from AND created_at < :to ORDER BY id ASC',
            $from, $to
        );
        $events = $this->reportRows(
            'SELECT e.id, e.ticket_id, e.actor_id, e.event, e.note, e.created_at,'
            . ' t.ticket_no, u.username AS actor_name FROM acm_ticket_events e'
            . ' JOIN acm_activation_tickets t ON t.id = e.ticket_id'
            . ' LEFT JOIN users u ON u.id = e.actor_id'
            . ' WHERE e.created_at >= :from AND e.created_at < :to ORDER BY e.id ASC',
            $from, $to
        );
        $modes = $this->reportRows(
            'SELECT h.id, h.mode, h.reason, h.effective_date, h.changed_by, h.changed_at,'
            . ' u.username AS changed_name FROM acm_office_mode_history h'
            . ' LEFT JOIN users u ON u.id = h.changed_by'
            . ' WHERE h.changed_at >= :from AND h.changed_at < :to ORDER BY h.id ASC',
            $from, $to
        );
        $selfApproved = $this->reportRows(
            'SELECT t.id, t.ticket_no, t.account_type, t.decided_by, t.decided_at,'
            . ' u.username AS decider_name FROM acm_activation_tickets t'
            . ' LEFT JOIN users u ON u.id = t.decided_by'
            . ' WHERE t.self_approved = 1 AND t.decided_at >= :from AND t.decided_at < :to'
            . ' ORDER BY t.id ASC',
            $from, $to
        );
        $attestations = $this->attestationsFor($month);
        $byAction = [];
        foreach ($audit['rows'] as $row) {
            $a = (string)($row['action'] ?? '');
            $byAction[$a] = ($byAction[$a] ?? 0) + 1;
        }
        $report = [
            'month' => $month,
            'period_from' => $from,
            'period_to' => $to,
            'generated_at' => $generatedAt,
            'schema_version' => self::REPORT_SCHEMA,
            'hash_algo' => 'sha256',
            'summary' => [
                'audit_events' => $audit['total'],
                'ticket_events' => $events['total'],
                'mode_changes' => $modes['total'],
                'self_approved' => $selfApproved['total'],
                'by_action' => $byAction,
                'truncated' => $audit['truncated'] || $events['truncated'],
            ],
            'audit_events' => $audit['rows'],
            'ticket_events' => $events['rows'],
            'mode_changes' => $modes['rows'],
            'self_approved' => $selfApproved['rows'],
            'attestations' => $attestations,
        ];
        $canonical = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($canonical === false) {
            throw new RuntimeException('Report could not be encoded.');
        }
        $report['report_hash'] = hash('sha256', $canonical);
        return $report;
    }

    /**
     * Attest a month. Requires: active designated reviewer, independence
     * (no office actions by the attester that month), a note, and an
     * unchanged report hash. First attestation vs correction is derived,
     * never caller-supplied. No eligible reviewer => no row is written
     * and the month stays unattested (rule #4.6).
     */
    public function attest(string $month, string $note, string $expectedHash, int $attesterId): array
    {
        if ($attesterId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }
        [$from, $to] = $this->monthBounds($month);
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('An attestation note (3-2000 chars) is required.');
        }
        if (!$this->isActiveReviewer($attesterId)) {
            throw new InvalidArgumentException('Only a designated independent reviewer can attest.');
        }
        if ($this->hasOfficeActionsInMonth($attesterId, $from, $to)) {
            throw new InvalidArgumentException(
                'Independence: you performed office actions in this month, so you cannot attest it.'
            );
        }
        $report = $this->auditReport($month);
        if (!hash_equals((string)$report['report_hash'], trim($expectedHash))) {
            throw new InvalidArgumentException('The report changed since it was generated. Regenerate and review again.');
        }
        $prior = $this->attestationsFor($month);
        $latestId = empty($prior) ? null : (int)$prior[count($prior) - 1]['id'];
        $kind = $latestId === null ? 'first' : 'correction';
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO acm_office_attestations (month, period_from, period_to, generated_at,'
                . ' schema_version, hash_algo, report_hash, attested_by, note, kind, supersedes_id)'
                . ' VALUES (:m, :pf, :pt, :g, :sv, :ha, :h, :by, :n, :k, :s)'
            );
            $stmt->execute([
                ':m' => $month,
                ':pf' => $from,
                ':pt' => $to,
                ':g' => $report['generated_at'],
                ':sv' => self::REPORT_SCHEMA,
                ':ha' => 'sha256',
                ':h' => $report['report_hash'],
                ':by' => $attesterId,
                ':n' => $note,
                ':k' => $kind,
                ':s' => $latestId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $row = $this->attestationById($id);
        if ($row === null) {
            throw new RuntimeException('Attestation was recorded but could not be reloaded.');
        }
        return $row;
    }

    public function attestationsFor(string $month): array
    {
        $this->monthBounds($month); // validates format
        try {
            $stmt = $this->pdo->prepare(
                'SELECT a.*, u.username AS attester_name FROM acm_office_attestations a'
                . ' LEFT JOIN users u ON u.id = a.attested_by'
                . ' WHERE a.month = :m ORDER BY a.id ASC'
            );
            $stmt->execute([':m' => $month]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** True when the given calendar month has at least one attestation. */
    public function monthAttested(string $month): bool
    {
        return count($this->attestationsFor($month)) > 0;
    }

    // ---- internals ----

    private function designations(string $table): array
    {
        $this->assertDesigneeTable($table);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT d.*, u.username, u.full_name, u.is_active,'
                . ' b.username AS designated_name FROM `' . $table . '` d'
                . ' JOIN users u ON u.id = d.user_id'
                . ' LEFT JOIN users b ON b.id = d.designated_by'
                . " WHERE d.status = 'active' ORDER BY d.effective_from ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function isActiveDesignee(string $table, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $this->assertDesigneeTable($table);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT d.id FROM `' . $table . '` d JOIN users u ON u.id = d.user_id'
                . " WHERE d.user_id = :u AND d.status = 'active'"
                . ' AND d.effective_from <= NOW() AND u.is_active = 1 LIMIT 1'
            );
            $stmt->execute([':u' => $userId]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function designate(string $table, int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): array
    {
        $this->assertDesigneeTable($table);
        if (!$isSuperAdmin) {
            throw new InvalidArgumentException('Only a Super Admin can designate.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Choose a user to designate.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A reason (3-2000 chars) is required.');
        }
        $effectiveDt = $this->parseEffective($effective);
        $stmt = $this->pdo->prepare(
            'SELECT id, username, is_active FROM users WHERE id = :u LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw new InvalidArgumentException('User was not found.');
        }
        if ((int)$user['is_active'] !== 1) {
            throw new InvalidArgumentException('Only active accounts can be designated.');
        }
        $chk = $this->pdo->prepare(
            'SELECT id FROM `' . $table . '` WHERE user_id = :u AND status = \'active\' LIMIT 1'
        );
        $chk->execute([':u' => $userId]);
        if ($chk->fetchColumn() !== false) {
            throw new InvalidArgumentException('This user is already designated.');
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO `' . $table . '` (user_id, status, designated_by, effective_from, designate_reason)'
            . ' VALUES (:u, \'active\', :by, :ef, :r)'
        );
        $ins->execute([
            ':u' => $userId,
            ':by' => $actorId > 0 ? $actorId : null,
            ':ef' => $effectiveDt,
            ':r' => $reason,
        ]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'username' => (string)$user['username']];
    }

    private function revoke(string $table, int $userId, string $reason, string $effective, int $actorId, bool $isSuperAdmin): void
    {
        $this->assertDesigneeTable($table);
        if (!$isSuperAdmin) {
            throw new InvalidArgumentException('Only a Super Admin can revoke a designation.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('A reason (3-2000 chars) is required.');
        }
        $effectiveDt = $this->parseEffective($effective);
        $stmt = $this->pdo->prepare(
            'UPDATE `' . $table . '` SET status = \'revoked\', revoked_by = :by,'
            . ' revoked_at = NOW(), revoke_reason = :r, revoke_effective = :ef'
            . ' WHERE user_id = :u AND status = \'active\''
        );
        $stmt->execute([
            ':by' => $actorId > 0 ? $actorId : null,
            ':r' => $reason,
            ':ef' => $effectiveDt,
            ':u' => $userId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('No active designation was found for this user.');
        }
    }

    private function hasOfficeActionsInMonth(int $userId, string $from, string $to): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM activity_logs WHERE user_id = :u AND action LIKE 'acm.%'"
            . ' AND created_at >= :from AND created_at < :to LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':from' => $from, ':to' => $to]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        $ev = $this->pdo->prepare(
            'SELECT id FROM acm_ticket_events WHERE actor_id = :u'
            . ' AND created_at >= :from AND created_at < :to LIMIT 1'
        );
        $ev->execute([':u' => $userId, ':from' => $from, ':to' => $to]);
        return $ev->fetchColumn() !== false;
    }

    private function attestationById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, u.username AS attester_name FROM acm_office_attestations a'
            . ' LEFT JOIN users u ON u.id = a.attested_by WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{0:string,1:string} [inclusive from, exclusive to] */
    private function monthBounds(string $month): array
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            throw new InvalidArgumentException('Month must be YYYY-MM.');
        }
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $from = sprintf('%04d-%02d-01 00:00:00', $y, $mo);
        $nmo = $mo === 12 ? 1 : $mo + 1;
        $ny = $mo === 12 ? $y + 1 : $y;
        $to = sprintf('%04d-%02d-01 00:00:00', $ny, $nmo);
        return [$from, $to];
    }

    private function parseEffective(string $effective): string
    {
        $effective = trim($effective);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $effective)) {
            return $effective . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01]) ([01][0-9]|2[0-3]):[0-5][0-9]$/', $effective)) {
            return $effective . ':00';
        }
        throw new InvalidArgumentException('Effective must be YYYY-MM-DD or YYYY-MM-DD HH:MM.');
    }

    private function reportRows(string $sql, string $from, string $to): array
    {
        try {
            $stmt = $this->pdo->prepare($sql . ' LIMIT ' . (int)(self::REPORT_CAP + 1));
            $stmt->execute([':from' => $from, ':to' => $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['rows' => [], 'total' => 0, 'truncated' => false, 'unavailable' => true];
        }
        $truncated = count($rows) > self::REPORT_CAP;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::REPORT_CAP);
        }
        return ['rows' => $rows, 'total' => count($rows), 'truncated' => $truncated];
    }

    private function assertDesigneeTable(string $table): void
    {
        if ($table !== 'acm_office_approvers' && $table !== 'acm_office_reviewers') {
            throw new InvalidArgumentException('Unknown designation table.');
        }
    }
}
