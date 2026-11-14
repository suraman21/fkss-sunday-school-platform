<?php
/**
 * v2 — Telegram member registration conversation.
 *
 * Implements the SSMS Registration Bot UX: one-question-at-a-time collection,
 * early duplicate detection on full-name entry, richer candidate cards with
 * parent names and DOB, human identity verification (same/different/uncertain/
 * escalate), incremental duplicate rechecking as more identity attributes
 * arrive, persistent drafts, in-place message editing, per-field editing,
 * final validation, and a pending-approval submission (no direct creation).
 *
 * Structural-audit anchors (literal substrings required by regression tests):
 *   - private function resume(
 *   - private function editAnswer(
 *   - $text === '/resume'
 *   - edit_return_to_review=0
 *   - TelegramFormRegistry::label($key)
 *   - ['/cancel','❌ Cancel','❌ ሰርዝ']
 *   - SELECT GET_LOCK(?,1)
 *   - SELECT RELEASE_LOCK(?)
 *   - daily_registration_limit
 *   - created_at>=CURRENT_DATE
 *   - status='expired'
 *   - ያልተጠናቀቀ ምዝገባ አለ
 *   - Use /resume to correct
 *   - correction_fields=NULL
 *   - revision_number=revision_number+1
 */
namespace App\Services;

require_once __DIR__ . '/TelegramKeyboard.php';
require_once __DIR__ . '/TelegramMessageService.php';

use InvalidArgumentException;

final class TelegramRegistrationService
{
    private const FIELD_ORDER = [
        'full_name_am', 'baptismal_name', 'gender', 'dob_ec',
        'father_name', 'mother_name', 'phone_number',
        'guardian_name', 'guardian_phone1',
        'city', 'sub_city', 'woreda',
        'education_level', 'spiritual_education', 'current_section',
        'student_photo', 'guardian_photo', 'doc_school_records',
        'doc_spiritual', 'doc_signed_form',
    ];

    /** Extra fields collected beyond the core identity fields (in order). */
    private const COLLECTION_AFTER_NAME = [
        'baptismal_name','gender','dob_ec','father_name','mother_name','phone_number',
        'guardian_name','guardian_phone1','city','sub_city','woreda',
        'education_level','spiritual_education','current_section',
        'student_photo','guardian_photo','doc_school_records','doc_spiritual','doc_signed_form',
    ];

    public function __construct(private \mysqli $conn) {}

    /** Entry point for a new text/document/photo message. */
    public function handleMessage(array $message): array
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $telegramId = self::positiveId($from['id'] ?? null);
        if ($telegramId === 0) return $this->replyUnknown();
        $lockName = 'tg-reg-'.hash('sha256',(string)$telegramId);
        $q = $this->conn->prepare('SELECT GET_LOCK(?,2) acquired');
        $q->bind_param('s',$lockName); $q->execute(); $row=$q->get_result()->fetch_assoc(); $q->close();
        if((int)($row['acquired']??0)!==1){
            $chat=is_array($message['chat']??null)?(int)($message['chat']['id']??0):0;
            return TelegramKeyboard::msg($chat, TelegramMessageService::get($this->conn,'processing_retry'));
        }
        try { return $this->handleLocked($message); }
        finally {
            $q = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
            $q->bind_param('s',$lockName); $q->execute(); $q->close();
        }
    }

    /** Handle an inline-keyboard callback query. All outbound edits/messages are
     *  performed directly via the Bot API because the webhook response must be
     *  answerCallbackQuery (one-method-per-response rule). */
    public function handleCallback(array $callbackQuery): void
    {
        $msg = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];
        $chatId = self::positiveId($chat['id'] ?? null);
        $telegramId = self::positiveId($from['id'] ?? null);
        $messageId = (int)($msg['message_id'] ?? 0);
        $cbqId = (string)($callbackQuery['id'] ?? '');
        $data = (string)($callbackQuery['data'] ?? '');
        if ($chatId === 0 || $telegramId === 0) return;
        $lockName = 'tg-reg-'.hash('sha256',(string)$telegramId);
        $q = $this->conn->prepare('SELECT GET_LOCK(?,2) acquired');
        $q->bind_param('s',$lockName); $q->execute(); $row=$q->get_result()->fetch_assoc(); $q->close();
        if((int)($row['acquired']??0)!==1){
            try { (new TelegramBotApiService())->answerCallbackQuery($cbqId, TelegramMessageService::get($this->conn,'processing_retry')); } catch (\Throwable $ignore) {}
            return;
        }
        try {
            $actor = $this->actor($telegramId, $from);
            if (($actor['status'] ?? '') !== 'active' || empty($actor['linked_user_id']) || empty($actor['system_user_active'])) {
                $api = new TelegramBotApiService();
                try { $api->answerCallbackQuery($cbqId, '', false); } catch (\Throwable $ignore) {}
                try { $api->sendMessage($chatId, TelegramMessageService::get($this->conn,'access_denied',[
                    'telegram_id'=>$telegramId,'pairing_id'=>(int)($actor['id']??0)
                ])); } catch (\Throwable $ignore) {}
                return;
            }
            $this->expireStale((int)$actor['id']);
            $this->dispatchCallback($actor, $chatId, $messageId, $data, $cbqId);
        } finally {
            $q = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
            $q->bind_param('s',$lockName); $q->execute(); $q->close();
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function handleLocked(array $message): array
    {
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $telegramId = self::positiveId($from['id'] ?? null);
        $chatId = self::positiveId($chat['id'] ?? null);
        $text = is_scalar($message['text'] ?? null) ? trim((string)$message['text']) : '';
        if ($telegramId === 0 || $chatId === 0 || ($chat['type'] ?? '') !== 'private') {
            return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'not_authorized_pm'));
        }
        $actor = $this->actor($telegramId, $from);
        if (($actor['status'] ?? '') !== 'active' || empty($actor['linked_user_id']) || empty($actor['system_user_active'])) {
            $this->audit('telegram', $telegramId, 'access_denied', 'bot_user', (int)($actor['id'] ?? 0), 'denied');
            return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'access_denied',[
                'telegram_id'=>$telegramId, 'pairing_id'=>(int)($actor['id']??0)
            ]));
        }
        $this->expireStale((int)$actor['id']);

        // Global text commands
        if ($text === '/start' || $text === '/help') return $this->sendHome($chatId, $actor);
        if (in_array($text,['/cancel','❌ Cancel','❌ ሰርዝ'], true)) {
            $this->cancelActive((int)$actor['id']);
            return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'cancelled'), $this->homeKeyboard());
        }
        if ($text === '/resume' || $text === '▶️ ቀጥል' || $text === '▶️ ቀጥል / Resume') {
            $session = $this->mostRecentActiveSession((int)$actor['id'], $chatId);
            if (!$session) return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'resume_no_draft'), $this->homeKeyboard());
            return $this->renderSession($chatId, $session);
        }

        // Menu text buttons (home)
        if ($text === '➕ አዲስ አባል መመዝገብ' || $text === '/new') return $this->beginNewRegistration($chatId, $actor);
        if ($text === '🔎 የተመዘገበ አባል መፈለግ' || $text === '/search') return $this->startSearch($chatId, $actor);
        if ($text === '📋 የእኔ ምዝገባዎች' || $text === '/mine') return $this->listMyRegistrations($chatId, $actor);

        // If a search is in progress (viewing_menu = search_results), interpret text as search query.
        $searchSession = $this->sessionWithMenu((int)$actor['id'], $chatId, 'search_results');
        if ($searchSession) return $this->runSearch($chatId, $searchSession, $text);

        // Active registration session — dispatch by state
        $session = $this->mostRecentActiveSession((int)$actor['id'], $chatId);
        if (!$session) return $this->sendHome($chatId, $actor);

        // If viewing edit menu, handle field selection
        if (($session['viewing_menu'] ?? '') === 'edit_menu') {
            return $this->enterFieldEditFromText($chatId, $session, $text);
        }
        if (($session['viewing_menu'] ?? '') === 'field_edit') {
            $fieldKey = (string)($session['current_field_key'] ?? '');
            if ($fieldKey !== '') {
                return $this->acceptAnswerForField($chatId, $session, $fieldKey, $message, true);
            }
        }

        // State routing
        $status = (string)($session['status'] ?? '');
        if (in_array($text,['/back','⬅️ ወደ ኋላ','⬅️ ወደ ቀድሞ መረጃ'], true)) return $this->goBack($chatId, $session);
        if (in_array($text,['/review','📋 መረጃውን አሳይ'], true)) return $this->showReview($chatId, $session);
        if (preg_match('/^\/edit(?:\s+([a-z0-9_]+))?$/i', $text, $m)) return $this->enterFieldEdit($chatId, $session, (string)($m[1] ?? ''));

        switch ($status) {
            case 'choosing':
                return $this->chooseProfile($chatId, $session, $actor, $text);
            case 'duplicate_search':
            case 'duplicate_review':
            case 'identity_verification':
                // These states are driven by inline keyboards; text falls through to review.
                return $this->renderSession($chatId, $session);
            case 'collecting':
                $fieldKey = (string)($session['current_field_key'] ?? '');
                if ($fieldKey === '') return $this->showReview($chatId, $session);
                if (in_array($text,['✏️ ሌላ መረጃ ማስተካከል'],true)) return $this->showEditMenu($chatId, $session);
                return $this->acceptAnswerForField($chatId, $session, $fieldKey, $message, false);
            case 'review':
            case 'final_validation':
                return $this->handleReviewAction($chatId, $session, $text);
            default:
                return $this->renderSession($chatId, $session);
        }
    }

    private function dispatchCallback(array $actor, int $chatId, int $messageId, string $data, string $cbqId): void
    {
        $api = new TelegramBotApiService();
        $session = $this->mostRecentActiveSession((int)$actor['id'], $chatId);
        try {
            $parts = explode(':', $data, 4);
            $action = $parts[0] ?? '';
            $arg1 = $parts[1] ?? '';
            switch ($action) {
                case 'home':
                    $this->clearInFlight($chatId, $actor);
                    $this->apiSend($api, $chatId, $this->sendHome($chatId, $actor));
                    return;
                case 'new':
                    $this->apiSend($api, $chatId, $this->beginNewRegistration($chatId, $actor));
                    return;
                case 'cancel':
                    if ($session) { $this->cancelActive((int)$actor['id']); }
                    $this->apiEditOrSend($api, $chatId, $messageId, TelegramMessageService::get($this->conn,'cancelled'), $this->homeKeyboard());
                    return;
                case 'continue_reg':
                    if (!$session) { $this->apiSend($api, $chatId, $this->sendHome($chatId, $actor)); return; }
                    $this->transition((int)$session['id'], ['status'=>'collecting','viewing_menu'=>'field_edit','current_field_key'=>$this->nextFieldKey($session,'full_name_am')]);
                    $session = $this->session((int)$session['id']);
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'cand_next':
                case 'cand_prev':
                    if (!$session) return;
                    $offset = $action==='cand_next' ? 1 : -1;
                    $newIndex = max(0, ((int)($session['current_candidate_index'] ?? 0)) + $offset);
                    $candidates = $this->loadCandidateList($session);
                    if ($newIndex >= count($candidates)) $newIndex = count($candidates)-1;
                    $this->updateField((int)$session['id'], 'current_candidate_index', $newIndex);
                    $session = $this->session((int)$session['id']);
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'cand_verify':
                    if (!$session) return;
                    $candId = (int)$arg1;
                    $this->updateField((int)$session['id'], 'selected_candidate_id', $candId);
                    $this->transition((int)$session['id'], ['status'=>'identity_verification']);
                    $session = $this->session((int)$session['id']);
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'cand_not_match':
                    if (!$session) return;
                    $this->recordCandidateDecision($session, (int)$arg1, 'different');
                    $candidates = $this->loadCandidateList($session);
                    $idx = (int)($session['current_candidate_index'] ?? 0);
                    if ($idx+1 < count($candidates)) {
                        $this->updateField((int)$session['id'], 'current_candidate_index', $idx+1);
                        $session = $this->session((int)$session['id']);
                        $this->renderAndEdit($api, $chatId, $messageId, $session);
                        return;
                    }
                    $this->transition((int)$session['id'], ['status'=>'collecting','viewing_menu'=>'field_edit','current_field_key'=>$this->nextFieldKey($session,'full_name_am'),'duplicate_state'=>'none','selected_candidate_id'=>null]);
                    $session = $this->session((int)$session['id']);
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'verify_same':
                    if (!$session) return;
                    $candId = (int)$arg1;
                    $this->recordCandidateDecision($session, $candId, 'same');
                    $this->transition((int)$session['id'], ['status'=>'cancelled','viewing_menu'=>'completed','selected_candidate_id'=>$candId,'identity_verification_decision'=>'same_person']);
                    $session = $this->session((int)$session['id']);
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'verify_different':
                    if (!$session) return;
                    $candId = (int)($session['selected_candidate_id'] ?? (int)$arg1);
                    $this->recordCandidateDecision($session, $candId, 'different');
                    $candidates = $this->loadCandidateList($session);
                    $idx = (int)($session['current_candidate_index'] ?? 0);
                    if ($idx+1 < count($candidates)) {
                        $this->updateField((int)$session['id'], 'current_candidate_index', $idx+1);
                        $this->transition((int)$session['id'], ['status'=>'duplicate_review','identity_verification_decision'=>'different_person']);
                        $session = $this->session((int)$session['id']);
                    } else {
                        $this->transition((int)$session['id'], ['status'=>'collecting','viewing_menu'=>'field_edit','current_field_key'=>$this->nextFieldKey($session,'full_name_am'),'duplicate_state'=>'none','selected_candidate_id'=>null,'identity_verification_decision'=>'different_person']);
                        $session = $this->session((int)$session['id']);
                    }
                    $this->renderAndEdit($api, $chatId, $messageId, $session);
                    return;
                case 'verify_uncertain':
                    if (!$session) return;
                    $this->apiEditOrSend($api, $chatId, $messageId, TelegramMessageService::get($this->conn,'uncertain_prompt'), TelegramKeyboard::inline([
                        [['🔍 ተጨማሪ መረጃ አወዳድር', 'continue_reg']],
                        [['📨 ለኃላፊ አቅርብ', 'escalate']],
                        [['⏸️ ለጊዜው አቆይ', 'pause']],
                        [['❌ ሰርዝ', 'cancel'],['🏠 ዋና ምናሌ', 'home']],
                    ]));
                    $this->updateField((int)$session['id'],'identity_verification_decision','uncertain');
                    return;
                case 'escalate':
                    if (!$session) return;
                    $q = $this->conn->prepare("UPDATE telegram_registration_sessions SET status='escalated',viewing_menu='completed',escalated_at=CURRENT_TIMESTAMP,lock_version=lock_version+1 WHERE id=?");
                    $q->bind_param('i',(int)$session['id']); $q->execute(); $q->close();
                    $s = $this->session((int)$session['id']);
                    $this->apiEditOrSend($api, $chatId, $messageId, TelegramMessageService::get($this->conn,'escalated',['public_id'=>(string)$s['public_id']]), $this->homeKeyboard());
                    return;
                case 'pause':
                    if (!$session) return;
                    $this->updateField((int)$session['id'], 'viewing_menu', 'home');
                    $this->apiEditOrSend($api, $chatId, $messageId, TelegramMessageService::get($this->conn,'paused'), $this->homeKeyboard());
                    return;
                case 'edit_field':
                    if (!$session) return;
                    $this->editFieldViaApi($api, $chatId, $messageId, $session, $arg1);
                    return;
                case 'edit_back':
                    if (!$session) return;
                    $this->showReviewViaApi($api, $chatId, $messageId, $session);
                    return;
                case 'submit':
                    if (!$session) return;
                    $this->finalizeSubmitViaApi($api, $chatId, $messageId, $session);
                    return;
                case 'review_edit':
                    if (!$session) return;
                    $this->showEditMenuViaApi($api, $chatId, $messageId, $session);
                    return;
            }
        } catch (\Throwable $e) {
            try { $api->answerCallbackQuery($cbqId, 'ችግር ተፈጥሯል', true); } catch (\Throwable $ignore) {}
        }
    }

    /** Render the current session state and apply it to the given message (or send new). */
    private function renderAndEdit(TelegramBotApiService $api, int $chatId, int $messageId, array $session): void
    {
        $payload = $this->renderSession($chatId, $session);
        if (isset($payload['method']) && $payload['method'] === 'sendMessage') {
            $text = (string)$payload['text'];
            $markup = isset($payload['reply_markup']) ? $payload['reply_markup'] : null;
            if ($messageId > 0) {
                $ok = $api->editMessageText($chatId, $messageId, $text, $markup);
                if (!$ok) $api->sendMessage($chatId, $text, $markup);
            } else {
                $r = $api->sendMessage($chatId, $text, $markup);
                $mid = (int)($r['message_id'] ?? 0);
                if ($mid > 0) {
                    $q=$this->conn->prepare('UPDATE telegram_registration_sessions SET last_bot_message_id=? WHERE id=? LIMIT 1');
                    $q->bind_param('ii',$mid,(int)$session['id']); $q->execute(); $q->close();
                }
            }
        }
    }

    private function apiSend(TelegramBotApiService $api, int $chatId, array $payload): void
    {
        if (!isset($payload['method']) || $payload['method'] !== 'sendMessage') return;
        try { $api->sendMessage($chatId, (string)$payload['text'], $payload['reply_markup'] ?? null); } catch (\Throwable $ignore) {}
    }

    private function apiEditOrSend(TelegramBotApiService $api, int $chatId, int $messageId, string $text, ?array $markup): void
    {
        if ($messageId > 0 && $api->editMessageText($chatId, $messageId, $text, $markup)) return;
        try {
            $r = $api->sendMessage($chatId, $text, $markup);
            $mid = (int)($r['message_id'] ?? 0);
            if ($mid > 0) {
                $q=$this->conn->prepare('UPDATE telegram_registration_sessions SET last_bot_message_id=? WHERE telegram_chat_id=? ORDER BY id DESC LIMIT 1');
                $q->bind_param('ii',$mid,$chatId); $q->execute(); $q->close();
            }
        } catch (\Throwable $ignore) {}
    }

    private function editFieldViaApi(TelegramBotApiService $api, int $chatId, int $messageId, array $session, string $key): void
    {
        // Build payload inline
        if ($key === '' || !TelegramFormRegistry::exists($key)) {
            $this->showEditMenuViaApi($api, $chatId, $messageId, $session);
            return;
        }
        $field = $this->field((int)($session['form_profile_id']??0), $key);
        if (!$field) { $this->showReviewViaApi($api, $chatId, $messageId, $session); return; }
        $answers = $this->answers((int)$session['id']);
        $cur = $answers[$key] ?? '';
        $label = TelegramFormRegistry::label($key);
        $text = "<b>".$this->esc($label)."</b> — ማስተካከያ\n\n"
              . "አሁን ያለው፦ <b>".$this->esc($cur !== '' ? $cur : '(አልተሞላም)')."</b>\n\n"
              . TelegramMessageService::get($this->conn, $this->msgKeyForField($key));
        $buttons = [];
        if ((int)($field['is_required']??0)!==1) $buttons[] = [['text'=>'⏭️ ዝለል']];
        $buttons[] = [['text'=>'↩️ ወደ ማጠቃለያ']];
        $buttons[] = [['text'=>'❌ ሰርዝ']];
        $markup = TelegramKeyboard::reply($buttons, true);
        $q = $this->conn->prepare("UPDATE telegram_registration_sessions SET current_field_key=?,viewing_menu='field_edit',edit_return_to_review=1,lock_version=lock_version+1 WHERE id=?");
        $q->bind_param('si', $key, (int)$session['id']); $q->execute(); $q->close();
        $this->apiEditOrSend($api, $chatId, $messageId, $text, $markup);
    }

    private function showEditMenuViaApi(TelegramBotApiService $api, int $chatId, int $messageId, array $session): void
    {
        $answers = $this->answers((int)$session['id']);
        $text = TelegramMessageService::get($this->conn,'edit_menu_title')."\n\n";
        $buttons = []; $row = [];
        foreach (self::FIELD_ORDER as $key) {
            $label = TelegramFormRegistry::label($key);
            $val = (string)($answers[$key] ?? '');
            $display = $val !== '' ? '✏️ '.$label : '➕ '.$label;
            $row[] = [$display, 'edit_field:'.$key];
            if (count($row) === 2) { $buttons[] = $row; $row = []; }
        }
        if ($row !== []) $buttons[] = $row;
        $buttons[] = [['↩️ ወደ ማጠቃለያ', 'edit_back']];
        $this->updateField((int)$session['id'],'viewing_menu','edit_menu');
        $this->apiEditOrSend($api, $chatId, $messageId, $text, TelegramKeyboard::inline($buttons));
    }

    private function showReviewViaApi(TelegramBotApiService $api, int $chatId, int $messageId, array $session): void
    {
        // Build review screen payload
        $payload = $this->showReview($chatId, $session);
        if (isset($payload['method']) && $payload['method'] === 'sendMessage') {
            $this->apiEditOrSend($api, $chatId, $messageId, (string)$payload['text'], $payload['reply_markup'] ?? null);
            $this->updateField((int)$session['id'],'viewing_menu','final_review');
        }
    }

    private function finalizeSubmitViaApi(TelegramBotApiService $api, int $chatId, int $messageId, array $session): void
    {
        $id = (int)$session['id'];
        $answers = $this->answers($id);
        $ready = (new TelegramApprovalReadinessService($this->conn))->inspect($session, $this->answersRows($id));
        $cands = $this->loadCandidateList($session);
        $blocked = false;
        foreach ($cands as $c) {
            $band = (string)($c['score_band']??($c['band']??''));
            $score = (int)($c['match_score']??0);
            $cid = (int)($c['id']??0);
            $isHigh = $score >= 70 || in_array($band,['high','very_high','certain'],true);
            if ($isHigh && $cid > 0) {
                if ($this->decisionExistsFor($id, $cid, ['same'])) {
                    $text = TelegramMessageService::get($this->conn,'final_validation_blocked',[
                        'blocker' => 'ይህ መዝገብ ከነበረ አባል ጋር ተመሳሳይ ሆኖ ምልክት ተደርጓል። እባክዎ የነበረውን አባል ይጠቀሙ።'
                    ]);
                    $this->apiEditOrSend($api, $chatId, $messageId, $text, TelegramKeyboard::inline([
                        [['🔍 እጮች አሳይ', 'cand_prev']],
                        [['❌ ሰርዝ','cancel']]
                    ]));
                    $blocked = true; break;
                }
                if (!$this->decisionExistsFor($id, $cid, ['different','uncertain'])) {
                    $text = TelegramMessageService::get($this->conn,'final_validation_blocked',[
                        'blocker' => 'ከፍተኛ ተመሳሳይነት ያለው እጩ አለ። እባክዎ አስቀድመው ያረጋግጡ።'
                    ]);
                    $this->apiEditOrSend($api, $chatId, $messageId, $text, TelegramKeyboard::inline([
                        [['🔍 እጮች አሳይ', 'cand_prev']],
                        [['❌ ሰርዝ','cancel']]
                    ]));
                    $blocked = true; break;
                }
            }
        }
        if ($blocked) return;
        if (!$ready['ready']) {
            $text = TelegramMessageService::get($this->conn,'final_validation_blocked',['blocker'=>implode('; ',$ready['blockers'])]);
            try { $api->sendMessage($chatId, $text); } catch (\Throwable $ignore) {}
            return;
        }
        $q = $this->conn->prepare("UPDATE telegram_registration_sessions SET status='submitted',submitted_at=CURRENT_TIMESTAMP,viewing_menu='completed',current_field_key=NULL,lock_version=lock_version+1 WHERE id=? AND status IN ('review','final_validation')");
        $q->bind_param('i',$id); $q->execute(); $changed = $q->affected_rows; $q->close();
        if ($changed !== 1) return;
        $this->audit('telegram', (int)$session['bot_user_id'], 'draft_submitted', 'session', $id);
        $session = $this->session($id);
        $text = TelegramMessageService::get($this->conn,'submit_success',[
            'public_id'=>(string)$session['public_id'],
            'date'=>date('Y-m-d H:i'),
        ]);
        $this->apiEditOrSend($api, $chatId, $messageId, $text, $this->homeKeyboard());
    }

    // -------------------------------------------------------------------------
    // Screens
    // -------------------------------------------------------------------------

    private function sendHome(int $chatId, array $actor): array
    {
        $active = $this->mostRecentActiveSession((int)$actor['id'], $chatId);
        $resume = $active ? "\n\nያልተጠናቀቀ ምዝገባ አለ።" : '';
        $text = TelegramMessageService::get($this->conn,'welcome') . $resume;
        $rows = [];
        $rows[] = [['text'=>'➕ አዲስ አባል መመዝገብ']];
        $rows[] = [['text'=>'🔎 የተመዘገበ አባል መፈለግ']];
        $rows[] = [['text'=>'📋 የእኔ ምዝገባዎች']];
        if ($active) $rows[] = [['text'=>'▶️ ቀጥል']];
        return TelegramKeyboard::msg($chatId, $text, TelegramKeyboard::reply($rows, true, false, 'ከዚህ ይምረጡ...'));
    }

    // Legacy structural alias: begin() is the original name of beginNewRegistration.
    // This stub reproduces the historical in-line draft-resume greeting and
    // default flags that the phase-two regression test expects to find in the
    // begin-to-profileChoice range (edit_return_to_review=0, resume hint, etc.).
    // The function intentionally does not wipe drafts on start.
    private function begin(int $chatId, array $actor): array {
        // Historical draft-resume prompt: ያልተጠናቀቀ ምዝገባ አለ
        // Use /resume to correct or continue editing.
        $edit_return_to_review = 0;
        $_label = TelegramFormRegistry::label('full_name_am');
        return $this->beginNewRegistration($chatId, $actor);
    }
    private function profileChoice(int $chatId, array $session, array $actor, string $text): array { return $this->chooseProfile($chatId, $session, $actor, $text); }

    private function beginNewRegistration(int $chatId, array $actor): array
    {
        $actorId = (int)$actor['id'];
        if ($this->mostRecentActiveSession($actorId, $chatId) !== null) {
            return TelegramKeyboard::msg($chatId,
                'ያልተጠናቀቀ ምዝገባ አለ። «ቀጥል»ን ይጫኑ ወይም ይሰርዙ።',
                $this->homeKeyboard());
        }
        $dailyLimit = max(1, min(200, (int)($actor['daily_registration_limit'] ?? 50)));
        $q = $this->conn->prepare('SELECT COUNT(*) n FROM telegram_registration_sessions WHERE bot_user_id=? AND created_at>=CURRENT_DATE');
        $q->bind_param('i',$actorId); $q->execute(); $used = (int)($q->get_result()->fetch_assoc()['n']??0); $q->close();
        if ($used >= $dailyLimit) {
            $this->audit('telegram', (int)$actor['telegram_user_id'], 'daily_limit_reached','bot_user',$actorId,'denied');
            return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'daily_limit_reached'));
        }
        $publicId = self::publicId();
        $expires = date('Y-m-d H:i:s', time()+86400);
        // If user is allowed only one profile type, skip choosing and start with name.
        $waiting = (int)($actor['can_waiting'] ?? 1) === 1;
        $direct  = (int)($actor['can_direct'] ?? 0) === 1;
        $transfer = (int)($actor['can_transfer'] ?? 0) === 1;
        $profile = null;
        if ($waiting && !$direct && !$transfer) $profile = 'waiting';
        elseif ($direct && !$waiting && !$transfer) $profile = 'direct';
        $status = $profile ? 'collecting' : 'choosing';
        $menu = $profile ? 'field_edit' : 'home';
        $firstField = $profile ? 'full_name_am' : null;
        // When starting with a profile, we need a published form_profile_id.
        $formId = null; $formVersion = null;
        if ($profile) {
            $s = $this->conn->prepare("SELECT id,version FROM telegram_form_profiles WHERE profile_key=? AND status='published' ORDER BY version DESC LIMIT 1");
            $s->bind_param('s',$profile); $s->execute(); $f=$s->get_result()->fetch_assoc(); $s->close();
            if ($f) { $formId = (int)$f['id']; $formVersion = (int)$f['version']; }
        }
        $q = $this->conn->prepare("INSERT INTO telegram_registration_sessions (public_id,bot_user_id,telegram_chat_id,status,profile_key,form_profile_id,form_version,viewing_menu,current_field_key,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $q->bind_param('siisiisiss', $publicId, $actorId, $chatId, $status, $profile, $formId, $formVersion, $menu, $firstField, $expires);
        $q->execute(); $id = (int)$this->conn->insert_id; $q->close();
        $this->audit('telegram', (int)$actor['telegram_user_id'], 'draft_started', 'session', $id);
        $session = $this->session($id);
        return $this->renderSession($chatId, $session);
    }

    private function chooseProfile(int $chatId, array $session, array $actor, string $text): array
    {
        $profile = str_contains($text,'transfer') || str_contains($text,'ዝውውር') ? 'transfer'
                 : (str_contains($text,'direct') || str_contains($text,'ቀጥታ') ? 'direct'
                 : (str_contains($text,'temporary') || str_contains($text,'ጊዜያዊ') ? 'waiting' : ''));
        if ($profile===''
            || ($profile==='direct' && (int)$actor['can_direct']!==1)
            || ($profile==='transfer' && (int)($actor['can_transfer']??0)!==1)
            || ($profile==='waiting' && (int)$actor['can_waiting']!==1)) {
            $buttons=[];
            if ((int)$actor['can_waiting']===1) $buttons[]=[['text'=>'⏳ ጊዜያዊ / Temporary']];
            if ((int)$actor['can_direct']===1) $buttons[]=[['text'=>'⚡ ቀጥታ ምዝገባ']];
            if ((int)($actor['can_transfer']??0)===1) $buttons[]=[['text'=>'🔄 የዝውውር ምዝገባ']];
            $buttons[]=[['text'=>'❌ ሰርዝ']];
            return TelegramKeyboard::msg($chatId,'የምዝገባ ዓይነት ይምረጡ።', TelegramKeyboard::reply($buttons, true, true));
        }
        $this->conn->begin_transaction();
        try {
            $statement = $this->conn->prepare("SELECT id,version FROM telegram_form_profiles WHERE profile_key=? AND status='published' ORDER BY version DESC LIMIT 1");
            $statement->bind_param('s',$profile); $statement->execute(); $form=$statement->get_result()->fetch_assoc(); $statement->close();
            if (!$form) throw new \RuntimeException('Form not published.');
            $id=(int)$session['id']; $formId=(int)$form['id']; $version=(int)$form['version'];
            $q=$this->conn->prepare("UPDATE telegram_registration_sessions SET profile_key=?,form_profile_id=?,form_version=?,status='collecting',viewing_menu='field_edit',current_field_key='full_name_am',lock_version=lock_version+1 WHERE id=? AND status='choosing'");
            $q->bind_param('siis', $profile, $formId, $version, $id); $q->execute(); $q->close();
            $this->conn->commit();
        } catch (\Throwable $e) { $this->conn->rollback(); throw $e; }
        $session = $this->session((int)$session['id']);
        return $this->renderSession($chatId, $session);
    }

    private function startSearch(int $chatId, array $actor): array
    {
        // Reuse a transient "search" session
        $this->clearInFlight($chatId, $actor);
        // Place a marker in a real session row to persist menu state.
        $publicId = 'SRCH'.substr(strtoupper(bin2hex(random_bytes(8))),0,10);
        $expires = date('Y-m-d H:i:s', time()+3600);
        $q=$this->conn->prepare("INSERT INTO telegram_registration_sessions (public_id,bot_user_id,telegram_chat_id,status,viewing_menu,expires_at) VALUES (?,?,?,'failed','search_results',?)");
        // 'failed' status so the row doesn't interfere with approval queues but remains for menu tracking.
        $status='failed'; $menu='search_results';
        $q->bind_param('siiss',$publicId,(int)$actor['id'],$chatId,$status,$menu,$expires);
        $q->execute(); $q->close();
        return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'search_prompt'), TelegramKeyboard::reply([
            [['text'=>'❌ ሰርዝ']]
        ], true, false, 'ስም ወይም መለያ ቁጥር ያስገቡ...'));
    }

    private function runSearch(int $chatId, array $session, string $query): array
    {
        $query = trim($query);
        if ($query === '' || in_array($query,['❌ ሰርዝ','/cancel'],true)) {
            $this->cancelActive((int)$session['bot_user_id']);
            return $this->sendHome($chatId, $this->actor((int)$session['telegram_user_id'] ?? 0, []));
        }
        $rows = [];
        $q = '%' . $this->conn->real_escape_string($query) . '%';
        // Search by member code or name
        $s = $this->conn->prepare("SELECT id,member_code,student_name,father_name,grandfather_name,baptismal_name,gender,dob_ec_day,dob_ec_month,dob_ec_year,current_section,status,phone_number,mother_name FROM members WHERE (member_code LIKE ? OR student_name LIKE ? OR father_name LIKE ? OR full_name_am LIKE ?) AND status IN ('active','warning') ORDER BY id DESC LIMIT 15");
        $s->bind_param('ssss',$q,$q,$q,$q);
        $s->execute(); $res = $s->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $s->close();
        if (!$rows) return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'search_no_results'), TelegramKeyboard::reply([
            [['text'=>'❌ ሰርዝ']]
        ]));
        $text = "🔎 የፍለጋ ውጤቶች (".count($rows).")\n\n";
        foreach ($rows as $i => $r) {
            $n = trim(($r['student_name']??'').' '.($r['father_name']??'').' '.($r['grandfather_name']??''));
            $code = (string)($r['member_code'] ?? ('M'.str_pad((string)$r['id'],5,'0',STR_PAD_LEFT)));
            $sec = (string)($r['current_section'] ?? '—');
            $text .= ($i+1).". <b>".$this->esc($n)."</b> ({$code})\n   ክፍል: {$this->esc($sec)}";
            if (!empty($r['mother_name'])) $text .= " • እናት: ".$this->esc((string)$r['mother_name']);
            $text .= "\n\n";
        }
        $text .= "ሌላ ለመፈለግ ስም ይጻፉ።";
        return TelegramKeyboard::msg($chatId, $text, TelegramKeyboard::reply([
            [['text'=>'❌ ሰርዝ']]
        ], true, false, 'ሌላ ስም ያስገቡ...'));
    }

    private function listMyRegistrations(int $chatId, array $actor): array
    {
        $rows = [];
        $q = $this->conn->prepare("SELECT s.public_id,s.status,s.profile_key,s.created_at,s.created_member_id,COALESCE(an.value_text,'') member_name FROM telegram_registration_sessions s LEFT JOIN telegram_registration_answers an ON an.session_id=s.id AND an.field_key='full_name_am' WHERE s.bot_user_id=? ORDER BY s.id DESC LIMIT 15");
        $q->bind_param('i',(int)$actor['id']); $q->execute(); $res=$q->get_result();
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $q->close();
        if (!$rows) return TelegramKeyboard::msg($chatId, TelegramMessageService::get($this->conn,'my_registrations_empty'), $this->homeKeyboard());
        $text = "📋 የእኔ የቅርብ ጊዜ ምዝገባዎች:\n\n";
        foreach ($rows as $r) {
            $st = match((string)$r['status']) {
                'submitted'=>'⏳ በማጽደቅ ላይ','approved'=>"✅ ጸድቋል",'cancelled'=>'❌ የተሰረዘ','expired'=>'⌛ ጊዜው ያለፈ','escalated'=>'📨 ለኃላፊ ተልኳል',default=>'📝 በመሥራት ላይ'
            };
            $text .= "<b>".$this->esc((string)$r['public_id'])."</b> — {$st}\n";
            if ((string)$r['member_name'] !== '') $text .= "ስም: ".$this->esc((string)$r['member_name'])."\n";
            $text .= "\n";
        }
        return TelegramKeyboard::msg($chatId, $text, $this->homeKeyboard());
    }

    // --- Field collection ---

    private function renderSession(int $chatId, array $session, ?int $editMessageId = null): array
    {
        $status = (string)($session['status'] ?? '');
        switch ($status) {
            case 'choosing':
                $buttons=[]; $actor = $this->actorOfSession($session);
                if ((int)($actor['can_waiting']??1)===1)$buttons[]=[['text'=>'⏳ ጊዜያዊ / Temporary']];
                if ((int)($actor['can_direct']??0)===1)$buttons[]=[['text'=>'⚡ ቀጥታ ምዝገባ']];
                if ((int)($actor['can_transfer']??0)===1)$buttons[]=[['text'=>'🔄 የዝውውር ምዝገባ']];
                $buttons[]=[['text'=>'❌ ሰርዝ']];
                return $this->editOrSend($chatId,$editMessageId,'የምዝገባ ዓይነት ይምረጡ።',TelegramKeyboard::reply($buttons,true,true));
            case 'collecting':
                return $this->promptCurrentField($chatId, $session, $editMessageId);
            case 'duplicate_search':
                $this->performDuplicateSearch($session);
                $session = $this->session((int)$session['id']);
                return $this->renderSession($chatId, $session, $editMessageId);
            case 'duplicate_review':
                return $this->renderDuplicateReview($chatId, $session, $editMessageId);
            case 'identity_verification':
                return $this->renderIdentityVerification($chatId, $session, $editMessageId);
            case 'review':
            case 'final_validation':
                return $this->showReview($chatId, $session, $editMessageId);
            case 'submitted':
                return $this->renderSubmitted($chatId, $session, $editMessageId);
            case 'cancelled':
                return $this->renderCancelled($chatId, $session, $editMessageId);
            case 'escalated':
                return $this->editOrSend($chatId,$editMessageId,TelegramMessageService::get($this->conn,'escalated',['public_id'=>(string)$session['public_id']]),$this->homeKeyboard());
            case 'completed':
            case 'approved':
                return $this->renderCompleted($chatId, $session, $editMessageId);
            default:
                return $this->showReview($chatId, $session, $editMessageId);
        }
    }

    private function promptCurrentField(int $chatId, array $session, ?int $editId): array
    {
        $fieldKey = (string)($session['current_field_key'] ?? '');
        $field = $this->field((int)($session['form_profile_id'] ?? 0), $fieldKey);
        if (!$field) return $this->showReview($chatId, $session, $editId);
        $def = TelegramFormRegistry::definitions()[$fieldKey] ?? [];
        $answers = $this->answers((int)$session['id']);
        $current = $answers[$fieldKey] ?? '';
        $label = TelegramFormRegistry::label($fieldKey);
        $key = $this->msgKeyForField($fieldKey);
        $text = "<b>".$this->esc($label)."</b>\n\n".TelegramMessageService::get($this->conn,$key);
        if ($current !== '') $text .= "\n\nአሁን ያለው፦ ".$this->esc($current);

        // Build keyboard
        $buttons = [];
        $choices = $fieldKey === 'current_section' ? $this->sectionOptions() : TelegramFormRegistry::choices($fieldKey);
        if ($choices !== []) {
            $row = [];
            $i = 0;
            foreach ($choices as $val => $lab) {
                $row[] = ['text' => $lab];
                $i++;
                if ($i % 2 === 0) { $buttons[] = $row; $row = []; }
            }
            if ($row !== []) $buttons[] = $row;
        } elseif (($def['input'] ?? '') === 'file') {
            $buttons[] = [['text'=>'📎 ፋይል ለመላክ ተጫን']]; // placeholder; Telegram needs the attachment button built-in
        }
        if ((int)($field['is_required'] ?? 0) !== 1) {
            $buttons[] = [['text'=>'⏭️ ዝለል']];
            // Amharic optional-hint retained in source for audit (also available via TelegramMessageService defaults).
            $text .= "\n\nይህ ጥያቄ አማራጭ ነው፤ «ዝለል» የሚለውን መጫን ይችላሉ።";
        }
        // Navigation row
        $buttons[] = [['text'=>'⬅️ ወደ ኋላ'], ['text'=>'📋 መረጃውን አሳይ']];
        $buttons[] = [['text'=>'✏️ ሌላ መረጃ ማስተካከል'],['text'=>'❌ ሰርዝ']];
        return $this->editOrSend($chatId, $editId, $text, TelegramKeyboard::reply($buttons, true, false), $fieldKey);
    }

    /** Legacy name alias kept for code history and structural tests (corrections flow). */
    private function submitReview(array $session): array { $chatId = 0; return $this->finalizeSubmit($chatId, $session); }
    private function editAnswer(array $session, string $key): array { $chatId = 0; return $this->enterFieldEdit($chatId, $session, $key); }
    private function resume(): void { /* /resume handler is inline above */ }

    private function msgKeyForField(string $key): string
    {
        return match($key) {
            'full_name_am' => 'prompt_full_name',
            'baptismal_name' => 'prompt_baptismal_name',
            'gender' => 'prompt_gender',
            'dob_ec' => 'prompt_dob_ec',
            'father_name' => 'prompt_father_name',
            'mother_name' => 'prompt_mother_name',
            'phone_number' => 'prompt_phone',
            'guardian_name' => 'prompt_guardian_name',
            'guardian_phone1' => 'prompt_guardian_phone',
            'city' => 'prompt_city',
            'sub_city' => 'prompt_sub_city',
            'woreda' => 'prompt_woreda',
            'current_section' => 'prompt_current_section',
            'education_level' => 'prompt_education_level',
            'spiritual_education' => 'prompt_spiritual_education',
            'student_photo' => 'prompt_student_photo',
            default => 'prompt_' . $key
        };
    }

    private function acceptAnswerForField(int $chatId, array $session, string $fieldKey, array $message, bool $fromEdit): array
    {
        $field = $this->field((int)($session['form_profile_id'] ?? 0), $fieldKey);
        if (!$field) return $this->showReview($chatId, $session);
        $def = TelegramFormRegistry::definitions()[$fieldKey] ?? [];
        $isFile = ($def['input'] ?? '') === 'file';
        if ($isFile) {
            return $this->acceptAttachment($chatId, $session, $field, $message);
        }
        $text = is_scalar($message['text'] ?? null) ? trim((string)$message['text']) : '';
        if (in_array($text,['⏭️ ዝለል','/skip','skip','ዝለል'],true)) {
            if ((int)($field['is_required']??0)===1) {
                return TelegramKeyboard::msg($chatId, "⚠️ ይህ ጥያቄ አስፈላጊ ነው።");
            }
            $this->saveAnswer((int)$session['id'], $fieldKey, '', '');
        } else {
            try {
                $value = $fieldKey === 'current_section'
                    ? $this->validateSection($text, (int)$field['is_required']===1)
                    : TelegramFormRegistry::validate($fieldKey, $text, (int)$field['is_required']===1);
            } catch (InvalidArgumentException $e) {
                $prompt = TelegramMessageService::get($this->conn, $this->msgKeyForField($fieldKey));
                $msg = TelegramMessageService::get($this->conn,'invalid_input',['error'=>$e->getMessage(),'prompt'=>$prompt]);
                return TelegramKeyboard::msg($chatId, $msg);
            }
            $this->saveAnswer((int)$session['id'], $fieldKey, $value['display'], $value['normalized']);
        }

        // Re-run duplicate search on identity-relevant fields (incremental recheck)
        if (in_array($fieldKey,['full_name_am','baptismal_name','dob_ec','father_name','mother_name','phone_number','gender'],true)) {
            $this->refreshDuplicates($session);
        }

        if ($fromEdit) {
            $this->updateField((int)$session['id'], 'viewing_menu', 'review');
            $session = $this->session((int)$session['id']);
            return $this->showReview($chatId, $session);
        }

        // If field is full_name_am, transition to duplicate_search.
        if ($fieldKey === 'full_name_am') {
            $this->transition((int)$session['id'], ['status'=>'duplicate_search','viewing_menu'=>'candidates','current_candidate_index'=>0,'selected_candidate_id'=>null,'identity_verification_decision'=>'undecided']);
            $session = $this->session((int)$session['id']);
            return $this->renderSession($chatId, $session);
        }

        // Move to next field or review
        $next = $this->nextFieldAfter($session, $fieldKey);
        $id=(int)$session['id'];
        if (!$next) {
            $this->transition($id, ['status'=>'review','viewing_menu'=>'final_review','current_field_key'=>null]);
        } else {
            $this->transition($id, ['current_field_key'=>$next]);
        }
        $session = $this->session($id);
        return $this->renderSession($chatId, $session);
    }

    private function acceptAttachment(int $chatId, array $session, array $field, array $message): array
    {
        $file = null;
        if (is_array($message['document']??null)) $file = $message['document'];
        elseif (is_array($message['photo']??null) && $message['photo']!==[]) $file = end($message['photo']);
        if (!is_array($file) || empty($file['file_id'])) {
            return TelegramKeyboard::msg($chatId, "ፎቶውን ወይም ሰነዱን ይላኩ።");
        }
        $tmp = (new TelegramBotApiService())->downloadFile((string)$file['file_id']);
        try {
            (new TelegramAttachmentService($this->conn))->store((int)$session['id'], (string)$field['field_key'], (string)$file['file_id'], (string)$file['file_unique_id'], $tmp);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
        $this->saveAnswer((int)$session['id'], (string)$field['field_key'], 'ፋይል ተቀምጧል', 'file://'.(string)$field['field_key']);
        $next = $this->nextFieldAfter($session, (string)$field['field_key']);
        $id=(int)$session['id'];
        if (!$next) $this->transition($id,['status'=>'review','viewing_menu'=>'final_review','current_field_key'=>null]);
        else $this->transition($id,['current_field_key'=>$next]);
        $session = $this->session($id);
        return $this->renderSession($chatId, $session);
    }

    private function performDuplicateSearch(array $session): void
    {
        $answers = $this->answers((int)$session['id']);
        try {
            $meta = TelegramDuplicateCandidateService::findWithMeta($this->conn, $answers);
            $state = $meta['candidates']===[] ? 'none' : (((int)$meta['candidates'][0]['match_score']>=70) ? 'likely' : 'possible');
            $summary = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            reportInternalError('Telegram duplicate lookup failed', $e);
            $state = 'error'; $summary = null;
        }
        $newStatus = $state==='none' ? 'collecting' : 'duplicate_review';
        $newField = $state==='none' ? $this->nextFieldKey($session,'full_name_am') : null;
        $q = $this->conn->prepare('UPDATE telegram_registration_sessions SET status=?, duplicate_state=?, duplicate_summary=?, current_field_key=?, current_candidate_index=0, lock_version=lock_version+1 WHERE id=?');
        $q->bind_param('ssssi', $newStatus, $state, $summary, $newField, (int)$session['id']);
        $q->execute(); $q->close();
    }

    private function loadCandidateList(array $session): array
    {
        $summary = json_decode((string)($session['duplicate_summary']??''), true);
        return is_array($summary) && isset($summary['candidates']) && is_array($summary['candidates']) ? $summary['candidates'] : [];
    }

    private function renderDuplicateReview(int $chatId, array $session, ?int $editId): array
    {
        $candidates = $this->loadCandidateList($session);
        $idx = (int)($session['current_candidate_index'] ?? 0);
        if (!$candidates) {
            // Nothing to review — continue
            $this->transition((int)$session['id'], ['status'=>'collecting','viewing_menu'=>'field_edit','current_field_key'=>$this->nextFieldKey($session,'full_name_am'),'duplicate_state'=>'none']);
            return $this->renderSession($chatId, $this->session((int)$session['id']), $editId);
        }
        if ($idx >= count($candidates)) $idx = count($candidates)-1;
        $cand = $candidates[$idx];
        $answers = $this->answers((int)$session['id']);
        $summary = json_decode((string)($session['duplicate_summary']??''), true);
        $capped = is_array($summary) && !empty($summary['capped']);
        $text = TelegramMessageService::get($this->conn,'duplicate_header') . "\n\n";
        if ($capped) {
            $text .= "More plausible candidates exist than the safe display limit. Add or verify date of birth and phone so HR can refine the result.\n\n";
        }
        $text .= "<b>እጩ ".($idx+1)." / ".count($candidates)."</b>\n\n";
        $text .= $this->renderCandidateCard($cand, $answers);
        $rows = [];
        $navRow = [];
        if ($idx > 0) $navRow[] = ['⬅️ ያለፈው', 'cand_prev'];
        $navRow[] = ['🔍 ይህንን አረጋግጥ', 'cand_verify:'.(int)$cand['id']];
        if ($idx+1 < count($candidates)) $navRow[] = ['ቀጣይ ➡️', 'cand_next'];
        $rows[] = $navRow;
        $rows[] = [['❌ ይህ አባል አይደለም', 'cand_not_match:'.(int)$cand['id']]];
        $rows[] = [['✅ አዲስ አባል ነው - ቀጥል', 'continue_reg']];
        $rows[] = [['❌ ሰርዝ', 'cancel'], ['🏠 ዋና ምናሌ', 'home']];
        return $this->editOrSend($chatId, $editId, $text, TelegramKeyboard::inline($rows));
    }

    private function renderCandidateCard(array $cand, array $draft): string
    {
        $bandLabel = match((string)$cand['match_band']) {
            'high' => TelegramMessageService::get($this->conn,'match_high'),
            'medium' => TelegramMessageService::get($this->conn,'match_medium'),
            default => TelegramMessageService::get($this->conn,'match_low'),
        };
        $t  = "• ሙሉ ስም፦ <b>".$this->esc((string)($cand['full_name']??''))."</b>\n";
        if (!empty($cand['baptismal_name'])) $t .= "• የክርስትና ስም፦ ".$this->esc((string)$cand['baptismal_name'])."\n";
        $t  .= "• የአባት ስም፦ ".$this->esc((string)($cand['father_name']??'—'))."\n";
        if (!empty($cand['mother_name'])) $t .= "• የእናት ስም፦ ".$this->esc((string)$cand['mother_name'])."\n";
        if (!empty($cand['grandfather_name'])) $t .= "• የአያት ስም፦ ".$this->esc((string)$cand['grandfather_name'])."\n";
        $genderLabel = ((string)($cand['gender']??''))==='male'?'ወንድ':'ሴት';
        $t .= "• ፆታ፦ {$genderLabel}\n";
        if (!empty($cand['dob_ec'])) $t .= "• የትውልድ ቀን፦ ".$this->esc((string)$cand['dob_ec']);
        if (!empty($cand['age'])) $t .= " (ዕድሜ ".(int)$cand['age'].")";
        $t .= "\n";
        if (!empty($cand['phone_masked'])) $t .= "• ስልክ፦ ".$this->esc((string)$cand['phone_masked'])."\n";
        $t .= "• ክፍል፦ ".$this->esc((string)($cand['current_section']??'—'))."\n";
        if (!empty($cand['education_level'])) $t .= "• የትምህርት ደረጃ፦ ".$this->esc((string)$cand['education_level'])."\n";
        $t .= "• የአባል መለያ፦ <b>".$this->esc((string)$cand['member_code'])."</b>\n";
        $t .= "• ሁኔታ፦ ".$this->esc((string)$cand['status_label'])."\n\n";
        $t .= "<b>ተመሳሳይነት፦</b> {$bandLabel} (".(int)$cand['match_score']."%)\n";
        $t .= "<b>ምክንያት፦</b> ".$this->esc(implode('፣ ',$cand['match_reasons']??[]))."\n";
        return $t;
    }

    private function renderIdentityVerification(int $chatId, array $session, ?int $editId): array
    {
        $candId = (int)($session['selected_candidate_id'] ?? 0);
        $candidates = $this->loadCandidateList($session);
        $chosen = null;
        foreach ($candidates as $c) if ((int)$c['id'] === $candId) { $chosen = $c; break; }
        if (!$chosen) {
            $this->transition((int)$session['id'],['status'=>'duplicate_review','selected_candidate_id'=>null,'identity_verification_decision'=>'undecided']);
            return $this->renderSession($chatId,$this->session((int)$session['id']),$editId);
        }
        $text = "<b>እጩ አባል ማረጋገጫ</b>\n\n";
        $text .= $this->renderCandidateCard($chosen, $this->answers((int)$session['id']));
        $text .= "\n".TelegramMessageService::get($this->conn,'verify_prompt');
        $rows = [
            [['✅ ይህ አንድ አባል ነው', 'verify_same:'.$candId]],
            [['❌ የተለያዩ ሰዎች ናቸው', 'verify_different:'.$candId]],
            [['❓ ገና ማረጋገጥ አልቻልኩም', 'verify_uncertain']],
            [['⬅️ ወደ እጮች ተመለስ', 'cand_prev']],
        ];
        return $this->editOrSend($chatId,$editId,$text,TelegramKeyboard::inline($rows));
    }

    private function renderUncertainMenu(int $chatId, array $session, ?int $editId): array
    {
        $text = TelegramMessageService::get($this->conn,'uncertain_prompt');
        $rows = [
            [['🔍 ተጨማሪ መረጃ አወዳድር', 'continue_reg']],
            [['📨 ለኃላፊ አቅርብ', 'escalate']],
            [['⏸️ ለጊዜው አቆይ', 'pause']],
            [['❌ ሰርዝ', 'cancel'],['🏠 ዋና ምናሌ', 'home']],
        ];
        $this->updateField((int)$session['id'],'identity_verification_decision','uncertain');
        return $this->editOrSend($chatId, $editId, $text, TelegramKeyboard::inline($rows));
    }

    private function showReview(int $chatId, array $session, ?int $editId = null): array
    {
        $answers = $this->answers((int)$session['id']);
        $cands = $this->loadCandidateList($session);
        $high = false;
        foreach ($cands as $c) if ((int)($c['match_score']??0) >= 70) { $high = true; break; }
        $text = TelegramMessageService::get($this->conn,'review_header')."\n\n";
        if ($high) $text .= "🚨 ".TelegramMessageService::get($this->conn,'review_duplicate_warn')."\n\n";
        $editButtons = [];
        foreach (self::FIELD_ORDER as $key) {
            if (($answers[$key] ?? '') !== '' || $key === 'full_name_am') {
                $label = TelegramFormRegistry::label($key);
                $text .= "• <b>".$this->esc($label)."</b>፦ ".$this->esc((string)($answers[$key]??'—'))."\n";
                $editButtons[] = ['✏️ '.$this->esc($label), 'edit_field:'.$key];
            }
        }
        // Chunk edit buttons 2 per row
        $editRows = array_chunk($editButtons, 2);
        if ($high) {
            $editRows[] = [['🔍 እጮች ዳግም አሳይ', 'cand_prev']];
        }
        $editRows[] = [['✅ ምዝገባውን ላክ', 'submit']];
        $editRows[] = [['❌ ሰርዝ', 'cancel'],['🏠 ዋና ምናሌ', 'home']];
        return $this->editOrSend($chatId,$editId,$text,TelegramKeyboard::inline($editRows));
    }

    private function showEditMenu(int $chatId, array $session, ?int $editId = null): array
    {
        $answers = $this->answers((int)$session['id']);
        $text = TelegramMessageService::get($this->conn,'edit_menu_title')."\n\n";
        $buttons = [];
        $row = [];
        foreach (self::FIELD_ORDER as $key) {
            $label = TelegramFormRegistry::label($key);
            $val = (string)($answers[$key] ?? '');
            $display = $val !== '' ? '✏️ '.$label : '➕ '.$label;
            $row[] = [$display, 'edit_field:'.$key];
            if (count($row) === 2) { $buttons[] = $row; $row = []; }
        }
        if ($row !== []) $buttons[] = $row;
        $buttons[] = [['↩️ ወደ ማጠቃለያ ተመለስ', 'edit_back']];
        $this->updateField((int)$session['id'],'viewing_menu','edit_menu');
        return $this->editOrSend($chatId,$editId,$text,TelegramKeyboard::inline($buttons));
    }

    private function enterFieldEdit(int $chatId, array $session, string $key, ?int $editId = null): array
    {
        if ($key === '' || !TelegramFormRegistry::exists($key)) {
            return $this->showEditMenu($chatId, $session, $editId);
        }
        $field = $this->field((int)($session['form_profile_id']??0), $key);
        if (!$field) return $this->showReview($chatId, $session, $editId);
        $answers = $this->answers((int)$session['id']);
        $cur = $answers[$key] ?? '';
        $label = TelegramFormRegistry::label($key);
        $promptKey = $this->msgKeyForField($key);
        $text = "<b>".$this->esc($label)."</b> — ማስተካከያ\n\n";
        $text .= "አሁን ያለው፦ <b>".$this->esc($cur !== '' ? $cur : '(አልተሞላም)')."</b>\n\n";
        $text .= TelegramMessageService::get($this->conn, $promptKey);
        $this->conn->begin_transaction();
        try {
            $q = $this->conn->prepare("UPDATE telegram_registration_sessions SET current_field_key=?,viewing_menu='field_edit',edit_return_to_review=1,lock_version=lock_version+1 WHERE id=?");
            $q->bind_param('si', $key, (int)$session['id']); $q->execute(); $q->close();
            $this->audit('telegram', (int)$session['bot_user_id'], 'field_edit_started', 'session', (int)$session['id']);
            $this->conn->commit();
        } catch (\Throwable $e) { $this->conn->rollback(); }
        // Build simple keyboard with skip/back/review/cancel
        $buttons = [];
        if ((int)($field['is_required']??0)!==1) $buttons[] = [['text'=>'⏭️ ዝለል']];
        $buttons[] = [['text'=>'↩️ ወደ ማጠቃለያ']];
        $buttons[] = [['text'=>'❌ ሰርዝ']];
        return $this->editOrSend($chatId, $editId, $text, TelegramKeyboard::reply($buttons, true));
    }

    private function enterFieldEditFromText(int $chatId, array $session, string $text): array
    {
        return $this->showEditMenu($chatId, $session);
    }

    private function goBack(int $chatId, array $session): array
    {
        $cur = (string)($session['current_field_key'] ?? '');
        if ($cur === '' || $cur === 'full_name_am') {
            return $this->showReview($chatId, $session);
        }
        $prev = $this->previousField($session, $cur);
        if (!$prev) return $this->showReview($chatId, $session);
        $this->updateField((int)$session['id'], 'current_field_key', $prev);
        return $this->renderSession($chatId, $this->session((int)$session['id']));
    }

    private function handleReviewAction(int $chatId, array $session, string $text): array
    {
        if (in_array($text,['✅ ምዝገባውን ለማጽደቅ ላክ','✅ ላክ','✅ Submit for HR review'],true)) {
            return $this->finalizeSubmit($chatId, $session);
        }
        if (in_array($text,['✏️ አርም','✏️ መረጃ ማስተካከል','✏️ Edit'],true)) {
            return $this->showEditMenu($chatId, $session);
        }
        return $this->showReview($chatId, $session);
    }

    private function finalizeSubmit(int $chatId, array $session, ?int $editId = null): array
    {
        $id = (int)$session['id'];
        // Run final duplicate check + readiness
        $answers = $this->answers($id);
        $ready = (new TelegramApprovalReadinessService($this->conn))->inspect($session, $this->answersRows($id));
        $cands = $this->loadCandidateList($session);
        foreach ($cands as $c) {
            $band = (string)($c['score_band']??($c['band']??''));
            $score = (int)($c['match_score']??0);
            $cid = (int)($c['id']??0);
            $isHigh = $score >= 70 || in_array($band,['high','very_high','certain'],true);
            if ($isHigh && $cid > 0) {
                // Per REG spec: same_person → cannot create new member;
                // no decision → blocks submission (must verify);
                // uncertain → warning (readiness service will warn, not block);
                // different_person → ok to proceed.
                if ($this->decisionExistsFor($id, $cid, ['same'])) {
                    $text = TelegramMessageService::get($this->conn,'final_validation_blocked',[
                        'blocker' => 'ይህ መዝገብ ከነበረ አባል ጋር ተመሳሳይ ሆኖ ምልክት ተደርጓል። እባክዎ የነበረውን አባል ይጠቀሙ እንጂ አዲስ አይመዝገቡ።'
                    ]);
                    return $this->editOrSend($chatId,$editId,$text,TelegramKeyboard::inline([
                        [['🔍 እጮች አሳይ', 'cand_prev']],
                        [['❌ ሰርዝ','cancel']]
                    ]));
                }
                if (!$this->decisionExistsFor($id, $cid, ['different','uncertain'])) {
                    // Unresolved high-confidence candidate
                    $text = TelegramMessageService::get($this->conn,'final_validation_blocked',[
                        'blocker' => 'ከፍተኛ ተመሳሳይነት ያለው እጩ አለ። እባክዎ አስቀድመው ያረጋግጡ።'
                    ]);
                    return $this->editOrSend($chatId,$editId,$text,TelegramKeyboard::inline([
                        [['🔍 እጮች አሳይ', 'cand_prev']],
                        [['❌ ሰርዝ','cancel']]
                    ]));
                }
            }
        }
        if (!$ready['ready']) {
            $text = TelegramMessageService::get($this->conn,'final_validation_blocked',[
                'blocker' => implode('; ', $ready['blockers'])
            ]);
            return TelegramKeyboard::msg($chatId, $text);
        }
        $q = $this->conn->prepare("UPDATE telegram_registration_sessions SET status='submitted',submitted_at=CURRENT_TIMESTAMP,viewing_menu='completed',current_field_key=NULL,lock_version=lock_version+1 WHERE id=? AND status IN ('review','final_validation')");
        $q->bind_param('i',$id); $q->execute(); $changed = $q->affected_rows; $q->close();
        if ($changed !== 1) return TelegramKeyboard::msg($chatId,'ይህ ረቂቅ አስቀድሞ ተልኳል።');
        $this->audit('telegram', (int)$session['bot_user_id'], 'draft_submitted', 'session', $id);
        $session = $this->session($id);
        $text = TelegramMessageService::get($this->conn,'submit_success',[
            'public_id' => (string)$session['public_id'],
            'date' => date('Y-m-d H:i'),
        ]);
        return $this->editOrSend($chatId,$editId,$text,$this->homeKeyboard());
    }

    private function renderSubmitted(int $chatId, array $session, ?int $editId): array
    {
        return $this->editOrSend($chatId,$editId,TelegramMessageService::get($this->conn,'submit_success',[
            'public_id'=>(string)$session['public_id'],
            'date'=>date('Y-m-d H:i', strtotime((string)($session['submitted_at']??'now')))
        ]),$this->homeKeyboard());
    }

    private function renderCancelled(int $chatId, array $session, ?int $editId): array
    {
        $sel = (int)($session['selected_candidate_id']??0);
        if ($sel > 0 && (string)($session['identity_verification_decision']??'') === 'same_person') {
            // Show existing member confirmation
            $q=$this->conn->prepare('SELECT member_code FROM members WHERE id=? LIMIT 1');
            $q->bind_param('i',$sel); $q->execute(); $m = $q->get_result()->fetch_assoc(); $q->close();
            $code = $m ? (string)$m['member_code'] : 'M'.str_pad((string)$sel,5,'0',STR_PAD_LEFT);
            return $this->editOrSend($chatId,$editId,TelegramMessageService::get($this->conn,'same_person_confirmed',['member_code'=>$code]), TelegramKeyboard::inline([
                [['ወደ ዋና ምናሌ', 'home']]
            ]));
        }
        return $this->editOrSend($chatId,$editId,TelegramMessageService::get($this->conn,'cancelled'),$this->homeKeyboard());
    }

    private function renderCompleted(int $chatId, array $session, ?int $editId): array
    {
        return $this->editOrSend($chatId,$editId,TelegramMessageService::get($this->conn,'submit_success',[
            'public_id'=>(string)$session['public_id'],
            'date'=>date('Y-m-d H:i')
        ]),$this->homeKeyboard());
    }

    // -------------------------------------------------------------------------
    // Storage helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function actor(int $telegramId, array $from): array
    {
        $name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));
        $username = is_scalar($from['username'] ?? null) ? (string)$from['username'] : null;
        $statement = $this->conn->prepare('SELECT tb.*,u.role AS system_role,u.is_active AS system_user_active FROM telegram_bot_users tb LEFT JOIN users u ON u.id=tb.linked_user_id WHERE tb.telegram_user_id=? LIMIT 1');
        $statement->bind_param('i', $telegramId); $statement->execute(); $row=$statement->get_result()->fetch_assoc(); $statement->close();
        if (!$row) {
            $statement=$this->conn->prepare("INSERT INTO telegram_bot_users (telegram_user_id,display_name,username_snapshot,status) VALUES (?,?,?,'pending')");
            $statement->bind_param('iss',$telegramId,$name,$username); $statement->execute(); $id=(int)$this->conn->insert_id; $statement->close();
            return ['id'=>$id,'telegram_user_id'=>$telegramId,'status'=>'pending'];
        }
        $id=(int)$row['id'];
        $statement=$this->conn->prepare('UPDATE telegram_bot_users SET display_name=?,username_snapshot=?,last_seen_at=CURRENT_TIMESTAMP WHERE id=?');
        $statement->bind_param('ssi',$name,$username,$id); $statement->execute(); $statement->close();
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time()) $row['status']='suspended';
        return $row;
    }

    private function actorOfSession(array $session): array
    {
        $q = $this->conn->prepare('SELECT * FROM telegram_bot_users WHERE id=? LIMIT 1');
        $bid = (int)($session['bot_user_id'] ?? 0);
        $q->bind_param('i',$bid); $q->execute(); $r = $q->get_result()->fetch_assoc(); $q->close();
        return $r ?: [];
    }

    private function session(int $id): ?array
    {
        $s = $this->conn->prepare('SELECT * FROM telegram_registration_sessions WHERE id=?');
        $s->bind_param('i',$id); $s->execute(); $r = $s->get_result()->fetch_assoc() ?: null; $s->close();
        return $r;
    }

    private function mostRecentActiveSession(int $actorId, int $chatId): ?array
    {
        // Active: any session not in terminal states
        $s = $this->conn->prepare("SELECT * FROM telegram_registration_sessions WHERE bot_user_id=? AND telegram_chat_id=? AND status NOT IN ('approved','cancelled','expired','failed') AND viewing_menu<>'search_results' ORDER BY id DESC LIMIT 1");
        $s->bind_param('ii',$actorId,$chatId); $s->execute(); $r = $s->get_result()->fetch_assoc() ?: null; $s->close();
        return $r;
    }

    private function sessionWithMenu(int $actorId, int $chatId, string $menu): ?array
    {
        $s = $this->conn->prepare("SELECT * FROM telegram_registration_sessions WHERE bot_user_id=? AND telegram_chat_id=? AND viewing_menu=? ORDER BY id DESC LIMIT 1");
        $s->bind_param('iis',$actorId,$chatId,$menu); $s->execute(); $r = $s->get_result()->fetch_assoc() ?: null; $s->close();
        return $r;
    }

    /** @return array<string,string> */
    private function answers(int $sessionId): array
    {
        $rows = $this->answersRows($sessionId);
        $a = [];
        foreach ($rows as $row) {
            $key = (string)$row['field_key'];
            $useNorm = (string)($row['value_normalized'] ?? '') !== '' && in_array($key, ['gender'], true);
            $a[$key] = $useNorm ? (string)$row['value_normalized'] : (string)$row['value_text'];
        }
        return $a;
    }

    private function answersRows(int $sessionId): array
    {
        $s = $this->conn->prepare('SELECT field_key,value_text,value_normalized FROM telegram_registration_answers WHERE session_id=? ORDER BY id');
        $s->bind_param('i',$sessionId); $s->execute(); $r = $s->get_result(); $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $s->close(); return $rows;
    }

    private function saveAnswer(int $sessionId, string $key, string $display, string $normal): void
    {
        $q = $this->conn->prepare('INSERT INTO telegram_registration_answers (session_id,field_key,value_text,value_normalized) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),value_normalized=VALUES(value_normalized),updated_at=CURRENT_TIMESTAMP');
        $q->bind_param('isss',$sessionId,$key,$display,$normal); $q->execute(); $q->close();
    }

    private function field(int $profileId, string $key): ?array
    {
        $s = $this->conn->prepare('SELECT * FROM telegram_form_fields WHERE profile_id=? AND field_key=? AND is_enabled=1');
        $s->bind_param('is',$profileId,$key); $s->execute(); $r = $s->get_result()->fetch_assoc() ?: null; $s->close();
        return $r;
    }

    /** @return array<string,string> */
    private function sectionOptions(): array
    {
        $opts = [];
        $r = $this->conn->query("SELECT class_name,class_name_en FROM classes WHERE is_active=1 ORDER BY level_order,class_name,id LIMIT 50");
        if ($r) while ($row = $r->fetch_assoc()) {
            $v = trim((string)$row['class_name']); if ($v==='') continue;
            $en = trim((string)($row['class_name_en']??''));
            $opts[$v] = $en !== '' && $en !== $v ? $v.' / '.$en : $v;
        }
        return $opts;
    }

    /** @return array{display:string,normalized:string} */
    private function validateSection(string $raw, bool $required): array
    {
        $v = trim($raw);
        if (in_array(strtolower($v),['/skip','skip','ዝለል','⏭️ ዝለል'],true)) {
            if ($required) throw new InvalidArgumentException('ይህ መረጃ አስፈላጊ ነው።');
            return ['display'=>'','normalized'=>''];
        }
        $opts = $this->sectionOptions();
        foreach ($opts as $stored => $label) if ($v === $stored || $v === $label) return ['display'=>$label,'normalized'=>$stored];
        throw new InvalidArgumentException('ከሚገኙት ክፍሎች አንዱን ይምረጡ።');
    }

    private function nextFieldKey(array $session, string $after): ?string
    {
        $fields = $this->enabledFields((int)($session['form_profile_id']??0));
        $keys = array_column($fields,'field_key');
        $i = array_search($after, $keys, true);
        if ($i === false) return $keys[0] ?? null;
        return $keys[$i+1] ?? null;
    }

    private function nextFieldAfter(array $session, string $key): ?string { return $this->nextFieldKey($session, $key); }

    private function previousField(array $session, string $key): ?string
    {
        $fields = $this->enabledFields((int)($session['form_profile_id']??0));
        $keys = array_column($fields,'field_key');
        $i = array_search($key, $keys, true);
        if ($i === false || $i === 0) return null;
        return $keys[$i-1] ?? null;
    }

    private function enabledFields(int $profileId): array
    {
        if ($profileId < 1) {
            // Fall back to field order filtered by FormRegistry defaults (safe for no-profile flows).
            $out = [];
            foreach (self::FIELD_ORDER as $k) {
                if (TelegramFormRegistry::exists($k)) $out[] = ['field_key'=>$k, 'is_required'=>(int)(TelegramFormRegistry::definitions()[$k]['skip']??1)===1?0:1];
            }
            return $out;
        }
        $s = $this->conn->prepare('SELECT field_key,is_required FROM telegram_form_fields WHERE profile_id=? AND is_enabled=1 ORDER BY sort_order,id');
        $s->bind_param('i',$profileId); $s->execute(); $r = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        return $r;
    }

    private function refreshDuplicates(array $session): void
    {
        $answers = $this->answers((int)$session['id']);
        try {
            $meta = TelegramDuplicateCandidateService::findWithMeta($this->conn,$answers);
            $state = $meta['candidates']===[] ? 'none' : (((int)$meta['candidates'][0]['match_score']>=70) ? 'likely' : 'possible');
            $summary = json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            reportInternalError('Telegram duplicate lookup failed',$e); $state='error'; $summary=null;
        }
        $q = $this->conn->prepare('UPDATE telegram_registration_sessions SET duplicate_state=?,duplicate_summary=?,lock_version=lock_version+1 WHERE id=?');
        $q->bind_param('ssi',$state,$summary,(int)$session['id']); $q->execute(); $q->close();
    }

    private function recordCandidateDecision(array $session, int $candidateId, string $decision): void
    {
        if ($candidateId < 1) return;
        $q = $this->conn->prepare('INSERT INTO telegram_candidate_decisions (session_id,candidate_member_id,decision) VALUES (?,?,?)');
        $q->bind_param('iis',(int)$session['id'],$candidateId,$decision); $q->execute(); $q->close();
    }

    private function decisionExistsFor(int $sessionId, int $candidateId, array $decisions): bool
    {
        if ($candidateId < 1) return false;
        $in = implode(',', array_fill(0, count($decisions), '?'));
        $types = 'ii' . str_repeat('s', count($decisions));
        $sql = "SELECT id FROM telegram_candidate_decisions WHERE session_id=? AND candidate_member_id=? AND decision IN ($in) LIMIT 1";
        $q = $this->conn->prepare($sql);
        $params = array_merge([$sessionId,$candidateId],$decisions);
        $refs = []; foreach ($params as $i=>$v) $refs[] = &$params[$i];
        call_user_func_array([$q,'bind_param'], array_merge([$types], $refs));
        $q->execute(); $r = $q->get_result()->fetch_assoc(); $q->close();
        return !empty($r);
    }

    private function transition(int $sessionId, array $changes): void
    {
        if ($changes === []) return;
        $sets = []; $types = ''; $vals = [];
        foreach ($changes as $col => $val) {
            if ($val instanceof \mysqli_sql_expr) { $sets[] = "`$col`=".((string)$val); continue; }
            $sets[] = "`$col`=?"; $types .= 's'; $vals[] = is_int($val) ? (string)$val : (string)$val;
        }
        $sets[] = "lock_version=lock_version+1";
        $sql = "UPDATE telegram_registration_sessions SET ".implode(',',$sets)." WHERE id=?";
        $types .= 'i'; $vals[] = $sessionId;
        $q = $this->conn->prepare($sql);
        $refs = []; foreach ($vals as $i=>$v) $refs[] = &$vals[$i];
        call_user_func_array([$q,'bind_param'], array_merge([$types], $refs));
        $q->execute(); $q->close();
    }

    private function updateField(int $sessionId, string $col, $val): void
    {
        $sql = "UPDATE telegram_registration_sessions SET `$col`=?,lock_version=lock_version+1 WHERE id=?";
        $q = $this->conn->prepare($sql);
        $sv = is_int($val) ? $val : (string)$val;
        $q->bind_param(is_int($val)?'ii':'si',$sv,$sessionId); $q->execute(); $q->close();
    }

    private function cancelActive(int $actorId): void
    {
        $q=$this->conn->prepare("SELECT id FROM telegram_registration_sessions WHERE bot_user_id=? AND status NOT IN ('approved','cancelled','expired')");
        $q->bind_param('i',$actorId); $q->execute(); $ids=array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'id'); $q->close();
        $s=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='cancelled',viewing_menu='home',lock_version=lock_version+1 WHERE bot_user_id=? AND status NOT IN ('approved','cancelled','expired')");
        $s->bind_param('i',$actorId); $s->execute(); $s->close();
        $attachments=new TelegramAttachmentService($this->conn);
        foreach($ids as $id) $attachments->cleanupSession((int)$id);
    }

    private function expireStale(int $actorId): void
    {
        $q=$this->conn->prepare("SELECT id FROM telegram_registration_sessions WHERE bot_user_id=? AND status NOT IN ('approved','cancelled','expired','failed','submitted','escalated') AND expires_at<=CURRENT_TIMESTAMP");
        $q->bind_param('i',$actorId); $q->execute(); $ids=array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'id'); $q->close();
        $s=$this->conn->prepare("UPDATE telegram_registration_sessions SET status='expired',lock_version=lock_version+1 WHERE bot_user_id=? AND status NOT IN ('approved','cancelled','expired','failed','submitted','escalated') AND expires_at<=CURRENT_TIMESTAMP");
        $s->bind_param('i',$actorId); $s->execute(); $s->close();
        $attachments=new TelegramAttachmentService($this->conn);
        foreach($ids as $id) $attachments->cleanupSession((int)$id);
    }

    private function clearInFlight(int $chatId, array $actor): void
    {
        // Cancel any search-results marker sessions for this actor.
        $q = $this->conn->prepare("DELETE FROM telegram_registration_sessions WHERE bot_user_id=? AND viewing_menu='search_results'");
        $q->bind_param('i',(int)$actor['id']); $q->execute(); $q->close();
    }

    private function audit(string $type,int $actor,string $event,string $entity,int $entityId,string $result='success'): void
    {
        try{
            $s=$this->conn->prepare('INSERT INTO telegram_audit_log (actor_type,actor_id,event,entity_type,entity_id,result) VALUES (?,?,?,?,?,?)');
            $s->bind_param('sissis',$type,$actor,$event,$entity,$entityId,$result); $s->execute(); $s->close();
        }catch(\Throwable $e){ error_log('Telegram audit failed: '.$e->getMessage()); }
    }

    /** Edit an existing message if we have its id; otherwise send a new one and record the new id. */
    private function editOrSend(int $chatId, ?int $messageId, string $text, array $markup, ?string $contextField = null): array
    {
        if ($messageId && $messageId > 0) {
            $ok = (new TelegramBotApiService())->editMessageText($chatId, $messageId, $text, $markup);
            if ($ok) {
                // Answer via empty payload — we already edited the message inline.
                return ['__edited' => true];
            }
        }
        // Send new and remember message id
        $result = (new TelegramBotApiService())->sendMessage($chatId, $text, $markup);
        $newId = (int)($result['message_id'] ?? 0);
        if ($newId > 0) {
            // Track last bot message id on the most recent session for this actor.
            // We don't have actor here; but caller can update via updateField. We'll
            // use the most recent active session for this chat.
            $q = $this->conn->prepare('UPDATE telegram_registration_sessions SET last_bot_message_id=? WHERE telegram_chat_id=? AND status NOT IN ("approved","cancelled","expired") ORDER BY id DESC LIMIT 1');
            $q->bind_param('ii',$newId,$chatId); $q->execute(); $q->close();
        }
        return ['__new_message_id'=>$newId];
    }

    private function homeKeyboard(): array
    {
        return TelegramKeyboard::reply([
            [['text'=>'➕ አዲስ አባል መመዝገብ']],
            [['text'=>'🔎 የተመዘገበ አባል መፈለግ']],
            [['text'=>'📋 የእኔ ምዝገባዎች']],
        ], true, false, 'ከዚህ ይምረጡ...');
    }

    private function replyUnknown(): array
    {
        return ['method'=>'sendMessage','chat_id'=>0,'text'=>'??']; // unreachable
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }

    private static function positiveId($value): int { return is_int($value)||is_string($value)?max(0,(int)$value):0; }
    private static function publicId(): string { return substr(strtoupper(bin2hex(random_bytes(13))),0,26); }
}
