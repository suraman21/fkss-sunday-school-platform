"""Preparation uses the selected build's trusted configured API identity."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / 'Mobile/wbws_flutter_app/lib'


def test_fetcher_and_active_preparation_scopes_use_configured_server():
    for name in ('attendance_preparation_fetcher.dart', 'grades_preparation_fetcher.dart'):
        text = (ROOT / 'services' / name).read_text()
        assert "import '../utils/config.dart';" in text
        assert 'serverIdentity: AppConfig.apiBaseUrl' in text
        assert "serverIdentity: 'https://felegekidusan.com/api/v1'" not in text
    for path in ('screens/attendance/attendance_screen.dart',
                 'screens/teacher/teacher_grades.dart', 'services/warm_store.dart'):
        assert 'serverIdentity: AppConfig.apiBaseUrl' in (ROOT / path).read_text()
