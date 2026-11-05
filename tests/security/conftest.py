"""Explicit isolation for legacy HTTP guards on the disposable integration DB.

Production authentication/limits are unchanged. Clear prior tests' login buckets,
not limits within a test, so role-matrix tests do not throttle one another.
"""
import os
import subprocess
import pytest


@pytest.fixture(autouse=True)
def isolate_live_guard_login_buckets(request):
    enabled = os.environ.get('SSMS_LIVE_GUARDS') == '1'
    cls = request.node.cls
    live = cls is not None and ('Live' in cls.__name__ or cls.__name__ == 'TimetableWebToMobileEndToEnd')
    if not (enabled and live):
        yield
        return
    if os.environ.get('SSMS_AUDIT_TESTING') != '1':
        pytest.fail('Live-guard isolation requires explicit disposable audit mode')
    def clean():
        subprocess.run(['sudo', '-n', 'mariadb', 'ssms_test', '-e',
            "DELETE FROM security_rate_limits WHERE action_name IN ('admin-login-ip','admin-login-account','api:auth_login')"],
            check=True, capture_output=True, text=True, timeout=20)
    clean()
    try:
        yield
    finally:
        clean()
