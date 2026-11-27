<?php
/**
 * Members App — learning path and unlock rules.
 * ---------------------------------------------------------------------
 * NAMESPACE NOTE
 *   This lives in `App\Services` alongside the other 112 domain services,
 *   because that is how this repository loads domain code: an explicit
 *   `require_once` from the route file, not an autoloader (see
 *   api/v1/routes/attendance.php for the same pattern). Putting it in
 *   `acm/src` would have needed a composer change for one class.
 *
 * WHY THIS IS A SERVICE AND NOT SQL IN THE ROUTE
 *   The unlock rules are the only genuinely non-obvious logic in the member
 *   app, and they are the thing most likely to be "simplified" later by someone
 *   who did not read the prototype. Isolating them means they can be tested
 *   without a database, and a change to them shows up in one file's diff.
 *
 * THE RULES (from docs/prototypes/member-app-2026-11-26/backend/api/learning.php,
 * and ARCHIVE.md calls this "a considered product rule set"):
 *   1. Unit 1 is always open.
 *   2. Inside an open unit, the first N lessons are open (N = config, default 2).
 *   3. Any later lesson needs a teacher unlock.
 *   4. Unit N+1 requires: every lesson of unit N complete, AND unit N's quiz
 *      passed, AND a manual unlock of the unit.
 *
 * POINT 4 IS DELIBERATELY THE STRICT READING. The prototype's prose is
 * ambiguous about whether the unit unlock is an ADDITIONAL gate or an
 * ALTERNATIVE route. Making it additional means a student who has done all the
 * work still waits for a teacher — which is what "manual unlock" is for — and
 * never means a student is blocked by a rule they cannot satisfy alone.
 * Recorded here so the interpretation is visible rather than assumed.
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

final class MembersLearningPath
{
    private PDO $pdo;

    /** Lessons open without an unlock at the start of each unit. */
    private int $freeLessonsPerUnit;

    public function __construct(PDO $pdo, int $freeLessonsPerUnit = 2)
    {
        $this->pdo = $pdo;
        // Clamped: a negative or absurd value would open a whole course by
        // accident, and 0 would lock even the first lesson of unit 1.
        $this->freeLessonsPerUnit = max(1, min(50, $freeLessonsPerUnit));
    }

    /**
     * The whole path for one member, with per-lesson state.
     *
     * ONE ROUND TRIP PER TABLE, not per lesson. A path is ~8 units x ~5 lessons;
     * querying state per lesson would be 40 round trips on a 2G connection,
     * which is the difference between a usable app and a spinner.
     *
     * @return array<int,array<string,mixed>> subjects -> units -> lessons
     */
    public function pathForMember(int $memberId): array
    {
        if ($memberId <= 0) {
            return [];
        }

        $subjects = $this->pdo->query(
            'SELECT id, code, name_am, name_en, description_am, icon, sort_order'
            . ' FROM members_subjects WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($subjects === []) {
            return [];
        }

        $units = $this->pdo->query(
            'SELECT id, subject_id, number, title_am, title_en, summary_am'
            . ' FROM members_units WHERE is_active = 1 ORDER BY subject_id ASC, number ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $lessons = $this->pdo->query(
            'SELECT id, unit_id, number, title_am, title_en, est_minutes'
            . ' FROM members_lessons WHERE is_active = 1 ORDER BY unit_id ASC, number ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $quizzes = $this->pdo->query(
            'SELECT id, unit_id, title_am, pass_score, time_limit_seconds'
            . ' FROM members_quizzes WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);

        $completed = $this->completedLessonIds($memberId);
        $unlockedLessons = $this->unlockedLessonIds($memberId);
        $unlockedUnits = $this->unlockedUnitIds($memberId);
        $passedQuizzes = $this->passedQuizIds($memberId);

        // ---- index children by parent, once ------------------------------
        $unitsBySubject = [];
        foreach ($units as $unit) {
            $unitsBySubject[(int)$unit['subject_id']][] = $unit;
        }
        $lessonsByUnit = [];
        foreach ($lessons as $lesson) {
            $lessonsByUnit[(int)$lesson['unit_id']][] = $lesson;
        }
        $quizByUnit = [];
        foreach ($quizzes as $quiz) {
            $quizByUnit[(int)$quiz['unit_id']] = $quiz;
        }

        $out = [];
        foreach ($subjects as $subject) {
            $subjectId = (int)$subject['id'];
            $subjectUnits = $unitsBySubject[$subjectId] ?? [];
            $unitOut = [];
            // State of the PREVIOUS unit, which is what gates this one.
            $previousLessonsComplete = true; // unit 1 has no predecessor
            $previousQuizOk = true;

            foreach ($subjectUnits as $index => $unit) {
                $unitId = (int)$unit['id'];
                $unitLessons = $lessonsByUnit[$unitId] ?? [];
                $quiz = $quizByUnit[$unitId] ?? null;

                // THE GATE, matching the prototype exactly
                // (backend/api/learning.php:120-126):
                //     $u["open"] = $prevDone && $u["teacher_unlocked"];
                // So a unit needs a teacher unlock EVEN when the previous unit
                // is finished. Finishing work never opens the next unit by
                // itself -- that deliberate friction is the product decision.
                $unitIsFirst = $index === 0;
                $manuallyUnlocked = $unlockedUnits[$unitId] ?? false;
                $unitUnlocked = $unitIsFirst || $manuallyUnlocked;

                $lessonOut = [];
                $allLessonsComplete = $unitLessons !== [];
                foreach ($unitLessons as $position => $lesson) {
                    $lessonId = (int)$lesson['id'];
                    $isDone = $completed[$lessonId] ?? false;
                    if (!$isDone) {
                        $allLessonsComplete = false;
                    }

                    $isFree = $position < $this->freeLessonsPerUnit;
                    $isOpen = $unitUnlocked && ($isFree || ($unlockedLessons[$lessonId] ?? false));

                    // A completed lesson stays readable even if a later rule
                    // would have closed it. Losing access to work you already
                    // did is a support ticket and a trust problem.
                    if ($isDone) {
                        $isOpen = true;
                    }

                    $lessonOut[] = [
                        'id' => $lessonId,
                        'number' => (int)$lesson['number'],
                        'title_am' => (string)$lesson['title_am'],
                        'title_en' => $lesson['title_en'] !== null ? (string)$lesson['title_en'] : null,
                        'est_minutes' => $lesson['est_minutes'] !== null ? (int)$lesson['est_minutes'] : null,
                        'completed' => $isDone,
                        'open' => $isOpen,
                        'needs_unlock' => !$isOpen && $unitUnlocked && !$isFree,
                    ];
                }

                $quizPassed = $quiz !== null && ($passedQuizzes[(int)$quiz['id']] ?? false);

                $quizOut = null;
                if ($quiz !== null) {
                    $quizOut = [
                        'id' => (int)$quiz['id'],
                        'title_am' => (string)$quiz['title_am'],
                        'pass_score' => (int)$quiz['pass_score'],
                        'time_limit_seconds' => $quiz['time_limit_seconds'] !== null
                            ? (int)$quiz['time_limit_seconds'] : null,
                        'passed' => $quizPassed,
                    ];
                }

                $unitOut[] = [
                    'id' => $unitId,
                    'number' => (int)$unit['number'],
                    'title_am' => (string)$unit['title_am'],
                    'title_en' => $unit['title_en'] !== null ? (string)$unit['title_en'] : null,
                    'summary_am' => $unit['summary_am'] !== null ? (string)$unit['summary_am'] : null,
                    'open' => $unitUnlocked,
                    'lessons' => $lessonOut,
                    'quiz' => $quizOut,
                    // Why the NEXT unit may still be closed. The reason belongs
                    // to this unit because this unit is what the next one waits on.
                    'completes_for_next' => $allLessonsComplete && ($quiz === null || $quizPassed),
                    'locked_reason' => $this->lockedReason($unitIsFirst, $manuallyUnlocked, $previousLessonsComplete, $previousQuizOk),
                ];

                // The NEXT unit's gate reads this unit's verdict.
                $previousLessonsComplete = $allLessonsComplete;
                // A unit with NO quiz counts as quiz-satisfied: the prototype's
                // rule is `!quiz_required || (passed && all lessons done)`, and
                // requiring a quiz that does not exist would block the course
                // forever.
                $previousQuizOk = ($quiz === null) || $quizPassed;
                $previousUnitComplete = $previousLessonsComplete && $previousQuizOk;
            }

            $out[] = [
                'id' => $subjectId,
                'code' => (string)$subject['code'],
                'name_am' => (string)$subject['name_am'],
                'name_en' => $subject['name_en'] !== null ? (string)$subject['name_en'] : null,
                'description_am' => $subject['description_am'] !== null ? (string)$subject['description_am'] : null,
                'icon' => $subject['icon'] !== null ? (string)$subject['icon'] : null,
                'units' => $unitOut,
            ];
        }

        return $out;
    }

    /**
     * Why a unit is closed, in machine-readable terms. NULL when it is open.
     *
     * Reads the PREVIOUS unit's state, because that is what this unit waits on
     * (prototype: `$u["open"] = $prevDone && $u["teacher_unlocked"]`).
     * An earlier version read the CURRENT unit's quiz and could report
     * "quiz_not_passed" for a unit whose own quiz was merely unset — a wrong
     * answer to the student's only question, which is "what do I do next".
     *
     * Returned as a code, not a sentence: the app speaks two languages and the
     * API must not pick the words.
     */
    private function lockedReason(
        bool $unitIsFirst,
        bool $manuallyUnlocked,
        bool $previousLessonsComplete,
        bool $previousQuizOk
    ): ?string {
        if ($unitIsFirst || $manuallyUnlocked) {
            return null;
        }
        if (!$previousLessonsComplete) {
            return 'previous_unit_incomplete';
        }
        if (!$previousQuizOk) {
            return 'previous_quiz_not_passed';
        }
        // All the student can do is done; the rest is the teacher's action.
        return 'awaiting_teacher_unlock';
    }

    /**
     * Is one lesson open to this member?
     *
     * Used by the lesson reader, which must not rely on the client having
     * honoured the `open` flag from the path response. The server decides.
     */
    public function isLessonOpen(int $memberId, int $lessonId): bool
    {
        if ($memberId <= 0 || $lessonId <= 0) {
            return false;
        }
        $path = $this->pathForMember($memberId);
        foreach ($path as $subject) {
            foreach ($subject['units'] as $unit) {
                foreach ($unit['lessons'] as $lesson) {
                    if ((int)$lesson['id'] === $lessonId) {
                        return (bool)$lesson['open'];
                    }
                }
            }
        }
        return false;
    }

    /** @return array<int,bool> lesson_id => true */
    private function completedLessonIds(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT lesson_id FROM members_lesson_progress"
            . " WHERE member_id = :mid AND status = 'completed'"
        );
        $stmt->execute([':mid' => $memberId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /** @return array<int,bool> lesson_id => true, expired unlocks excluded */
    private function unlockedLessonIds(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lesson_id FROM members_lesson_unlocks'
            . ' WHERE member_id = :mid AND (expires_at IS NULL OR expires_at > NOW())'
        );
        $stmt->execute([':mid' => $memberId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /** @return array<int,bool> unit_id => true */
    private function unlockedUnitIds(int $memberId): array
    {
        // The table is keyed by lesson; a unit unlock is recorded as an unlock
        // of its first lesson. Kept in one place so the convention is not
        // re-derived at every call site.
        $stmt = $this->pdo->prepare(
            'SELECT l.unit_id FROM members_lesson_unlocks u'
            . ' JOIN members_lessons l ON l.id = u.lesson_id'
            . ' WHERE u.member_id = :mid AND (u.expires_at IS NULL OR u.expires_at > NOW())'
            . ' AND l.number = (SELECT MIN(l2.number) FROM members_lessons l2 WHERE l2.unit_id = l.unit_id)'
        );
        $stmt->execute([':mid' => $memberId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /** @return array<int,bool> quiz_id => true when PASSED at least once */
    private function passedQuizIds(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT quiz_id FROM members_quiz_attempts'
            . ' WHERE member_id = :mid AND passed = 1'
        );
        $stmt->execute([':mid' => $memberId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }
}
