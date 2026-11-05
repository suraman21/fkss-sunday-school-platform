"""Static pins — Phase 2 (2026-10-22): View-only lock, private result card,
mobile live limit (APK 1.7.3+40).

Live behaviour is covered by tests/e2e/view_only_results.py and the Flutter
test test/score_limit_test.dart; this file pins the safety-critical wiring so
a later edit cannot silently drop it.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FL = ROOT / 'Mobile/wbws_flutter_app'


def read(p):
    return (ROOT / p).read_text(encoding='utf-8')


class WebViewOnly(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.teacher = read('admin/dashboards/teacher.php')
        cls.js = read('admin/js/view_only_marks.js')

    def test_script_loaded_after_score_limit(self):
        a = self.teacher.index('/admin/js/score_limit.js?v=')
        b = self.teacher.index('/admin/js/view_only_marks.js?v=20261022t')
        self.assertLess(a, b)

    def test_entry_attach_runs_after_term_lock(self):
        # Unlocking must restore each box's own state, so the snapshot
        # (data-fk-vo-was) has to be taken after the closed-semester lock.
        i = self.teacher.index('applyTermLockToEntry(entryLocked);\n')
        j = self.teacher.index("buttons: ['saveGradesBtn']")
        self.assertLess(i, j)
        self.assertLess(j - i, 700)

    def test_submit_attach_runs_after_submit_lock(self):
        i = self.teacher.index("tbody.querySelectorAll('.submit-score, .submit-remark').forEach(el => { el.disabled = submitLocked; });")
        j = self.teacher.index("buttons: ['submitBtn']")
        self.assertLess(i, j)

    def test_save_and_submit_refuse_while_view_only(self):
        for fn, key in (('function saveAllGrades()', 'currentAssessment.id'),
                        ('async function submitMarklist()', 'submitAssessmentData.id')):
            body = self.teacher[self.teacher.index(fn):][:600]
            self.assertIn('FKSSViewOnly.isOn(' + key + ')', body, fn)
            self.assertIn('View only is on', body, fn)

    def test_lock_restores_previous_state_and_is_per_test(self):
        self.assertIn('data-fk-vo-was', self.js)
        self.assertIn("'fkss_view_only_'", self.js)
        self.assertIn("action=student_results", self.js)

    def test_result_card_escapes_and_has_no_native_dialogs(self):
        self.assertNotRegex(self.js, r'\b(alert|confirm|prompt)\s*\(')
        self.assertIn('Only this student', self.js)
        self.assertIn('Escape', self.js)
        # every API string goes through the escaper before innerHTML
        self.assertRegex(self.js, r'function\s+esc\s*\(')

    def test_css_does_not_use_report_card_prefix(self):
        # test_detailed_report_card forbids new .rc-* rules; ours are fk-*.
        self.assertNotRegex(self.js, r"'\.rc-")


class ServerStudentResults(unittest.TestCase):
    def test_web_action_scoped_to_teacher_assignment(self):
        src = read('admin/api_subjects.php')
        body = src[src.index("case 'student_results':"):][:1800]
        self.assertIn('if ($__restrictedRole)', body)
        self.assertIn('edu_require_assignment(', body)
        self.assertIn('SubmissionService::studentResults($conn, $assessmentId, $memberId)', body)
        self.assertIn('http_response_code(404)', body)

    def test_v1_route_scoped_and_rate_limited(self):
        src = read('api/v1/routes/grades.php')
        body = src[src.index("$action === 'student-results'"):][:1600]
        self.assertIn("isApiRateLimited('grades_student_results', 120)", body)
        self.assertIn('if ($isRestricted)', body)
        self.assertIn('checkTeacherSubjectAccess(', body)
        self.assertIn("reportInternalError('grades student-results failed'", body)

    def test_service_limits_to_same_class_subject_semester_and_enrolled(self):
        src = read('admin/backend/services/SubmissionService.php')
        body = src[src.index('public static function studentResults('):][:6000]
        self.assertIn('self::memberBelongsToClass($conn, $memberId, $classId)', body)
        self.assertIn('not_taken', body)
        self.assertIn('not_entered', body)
        self.assertIn('subject_id', body)
        self.assertIn('term_id', body)


class MobilePhase2(unittest.TestCase):
    def test_new_files_exist(self):
        for f in ('lib/utils/score_limit.dart', 'lib/utils/view_only_lock.dart',
                  'lib/widgets/score_state_chip.dart', 'lib/widgets/submit_summary_sheet.dart',
                  'lib/screens/teacher/student_result_card.dart', 'test/score_limit_test.dart'):
            self.assertTrue((FL / f).is_file(), f)

    def test_grades_screen_uses_live_limit_view_only_and_summary(self):
        g = (FL / 'lib/screens/teacher/teacher_grades.dart').read_text(encoding='utf-8')
        self.assertIn('ScoreLimitFormatter(', g)
        self.assertIn('ViewOnlyLock.', g)
        self.assertIn('showSubmitSummarySheet(', g)
        self.assertIn('StudentResultCard(', g)
        self.assertIn("Key('view-only-toggle')", g)

    def test_api_service_calls_student_results(self):
        a = (FL / 'lib/services/api_service.dart').read_text(encoding='utf-8')
        self.assertIn('/grades/student-results', a)

    def test_no_new_packages(self):
        pub = (FL / 'pubspec.yaml').read_text(encoding='utf-8')
        self.assertRegex(pub, r'(?m)^version:\s*1\.7\.4\+41\s*$')
        lock = (FL / 'pubspec.lock').read_text(encoding='utf-8')
        self.assertNotIn('flutter_typeahead', lock)

    def test_release_notes_describe_1_7_3(self):
        n = (FL / 'RELEASE_NOTES.md').read_text(encoding='utf-8')
        self.assertTrue(n.split('## ')[1].startswith('1.7.4 (build 41)'))
        for s in ('Live limit', 'Did not take', 'View only', 'Result card'):
            self.assertIn(s, n)


if __name__ == '__main__':
    unittest.main()
