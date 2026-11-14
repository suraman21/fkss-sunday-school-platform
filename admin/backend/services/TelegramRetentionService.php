<?php
/** Bounded retention/expiry maintenance for shared-host cron. */
namespace App\Services;

final class TelegramRetentionService
{
    /** @return array<string,int> */
    public static function run(\mysqli $conn,int $batch=500): array
    {
        $batch=max(10,min(2000,$batch));
        $processedDays=defined('TELEGRAM_PROCESSED_UPDATE_RETENTION_DAYS')?max(7,min(365,(int)TELEGRAM_PROCESSED_UPDATE_RETENTION_DAYS)):30;
        $cancelledDays=defined('TELEGRAM_CANCELLED_SESSION_RETENTION_DAYS')?max(7,min(365,(int)TELEGRAM_CANCELLED_SESSION_RETENTION_DAYS)):90;
        $auditDays=defined('TELEGRAM_AUDIT_RETENTION_DAYS')?max(90,min(2555,(int)TELEGRAM_AUDIT_RETENTION_DAYS)):730;
        $counts=['expired_sessions'=>0,'processed_updates_deleted'=>0,'cancelled_sessions_deleted'=>0,'candidate_decisions_deleted'=>0,'audit_deleted'=>0];
        // Expire any in-flight draft states (including v2 duplicate/verify/final states).
        // Terminal states (submitted/approved/completed/escalated/cancelled/failed) are never
        // auto-expired — they represent either an audit record or a user-explicit end state.
        $q=$conn->prepare("UPDATE telegram_registration_sessions SET status='expired',lock_version=lock_version+1 WHERE status IN ('choosing','collecting','review','duplicate_search','duplicate_review','identity_verification','final_validation') AND expires_at<=NOW() LIMIT {$batch}");$q->execute();$counts['expired_sessions']=$q->affected_rows;$q->close();
        $q=$conn->prepare("DELETE FROM telegram_processed_updates WHERE received_at<DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY received_at LIMIT {$batch}");$q->bind_param('i',$processedDays);$q->execute();$counts['processed_updates_deleted']=$q->affected_rows;$q->close();
        // Answers and candidate decisions are removed before terminal sessions because
        // the schema avoids FK cascades for predictable shared-host migration compatibility.
        $q=$conn->prepare("DELETE FROM telegram_candidate_decisions WHERE session_id IN (SELECT id FROM (SELECT s.id FROM telegram_registration_sessions s WHERE s.status IN ('cancelled','expired','failed') AND s.updated_at<DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY s.id LIMIT {$batch}) stale_sessions)");$q->bind_param('i',$cancelledDays);$q->execute();$counts['candidate_decisions_deleted']=$q->affected_rows;$q->close();
        $q=$conn->prepare("DELETE FROM telegram_registration_answers WHERE id IN (SELECT id FROM (SELECT a.id FROM telegram_registration_answers a JOIN telegram_registration_sessions s ON s.id=a.session_id WHERE s.status IN ('cancelled','expired','failed') AND s.updated_at<DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY a.id LIMIT {$batch}) stale_answers)");$q->bind_param('i',$cancelledDays);$q->execute();$q->close();
        $q=$conn->prepare("DELETE FROM telegram_registration_sessions WHERE status IN ('cancelled','expired','failed') AND updated_at<DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY id LIMIT {$batch}");$q->bind_param('i',$cancelledDays);$q->execute();$counts['cancelled_sessions_deleted']=$q->affected_rows;$q->close();
        $q=$conn->prepare("DELETE FROM telegram_audit_log WHERE created_at<DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY id LIMIT {$batch}");$q->bind_param('i',$auditDays);$q->execute();$counts['audit_deleted']=$q->affected_rows;$q->close();
        return $counts;
    }
}
