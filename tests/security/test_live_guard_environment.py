"""Regression guards for CI's synthetic HTTP world; no production bypass."""
import os
from pathlib import Path
import subprocess
import importlib.util
from types import SimpleNamespace
import pytest

ROOT = Path(__file__).resolve().parents[2]


def test_live_seed_refuses_without_explicit_test_mode_before_loading_config():
    env = dict(os.environ)
    env.pop('SSMS_AUDIT_TESTING', None)
    result = subprocess.run(['php', str(ROOT / 'tests/e2e/live_guard_seed.php')],
                            env=env, capture_output=True, text=True, timeout=10)
    assert result.returncode == 2
    assert 'Refusing' in result.stderr
    assert 'Database' not in result.stdout


def test_router_cannot_be_used_as_a_cli_entrypoint():
    result = subprocess.run(['php', str(ROOT / 'tests/e2e/live_guard_router.php')],
                            capture_output=True, text=True, timeout=10)
    assert result.returncode == 0
    assert result.stdout == result.stderr == ''


def fixture_module():
    spec = importlib.util.spec_from_file_location('live_guard_conftest', ROOT / 'tests/security/conftest.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.mark.parametrize('enabled,live,expected', [(False,True,0),(True,False,0),(True,True,2)])
def test_login_isolation_is_opt_in_and_only_resets_between_live_tests(monkeypatch, enabled, live, expected):
    module = fixture_module()
    monkeypatch.setenv('SSMS_LIVE_GUARDS', '1' if enabled else '0')
    monkeypatch.setenv('SSMS_AUDIT_TESTING', '1')
    calls = []
    monkeypatch.setattr(module.subprocess, 'run', lambda args, **kw: calls.append(args))
    cls = type('ExampleLive' if live else 'StaticGuard', (), {})
    generator = module.isolate_live_guard_login_buckets.__wrapped__(SimpleNamespace(node=SimpleNamespace(cls=cls)))
    next(generator)
    assert len(calls) == expected // 2
    with pytest.raises(StopIteration): next(generator)
    assert len(calls) == expected
    for args in calls:
        assert args[0:4] == ['sudo','-n','mariadb','ssms_test']
        assert 'api:auth_login' in args[-1]
        assert 'UPDATE' not in args[-1]  # Never raise/disable the production limits.


def test_login_isolation_refuses_without_disposable_audit_mode(monkeypatch):
    module = fixture_module()
    monkeypatch.setenv('SSMS_LIVE_GUARDS', '1')
    monkeypatch.delenv('SSMS_AUDIT_TESTING', raising=False)
    generator = module.isolate_live_guard_login_buckets.__wrapped__(SimpleNamespace(node=SimpleNamespace(cls=type('ExampleLive', (), {}))))
    with pytest.raises(pytest.fail.Exception): next(generator)


@pytest.mark.parametrize('filename,method', [
    ('test_attendance_phase2.py','test_05_rollout_dry_run_then_apply_idempotent'),
    ('test_attendance_phase5.py','test_05_report_card_shows_the_makeup_and_keeps_the_original'),
])
def test_live_cli_probes_explicitly_select_the_http_world(filename, method):
    import ast
    tree = ast.parse((ROOT / 'tests/security' / filename).read_text())
    function = next(n for n in ast.walk(tree) if isinstance(n, ast.FunctionDef) and n.name == method)
    selections = []
    for node in ast.walk(function):
        if isinstance(node, ast.keyword) and node.arg == 'SSMS_DB_NAME' and isinstance(node.value, ast.Constant):
            selections.append(node.value.value)
        if isinstance(node, ast.Dict):
            for key, value in zip(node.keys,node.values):
                if isinstance(key,ast.Constant) and key.value == 'SSMS_DB_NAME' and isinstance(value,ast.Constant):
                    selections.append(value.value)
    assert selections == ['ssms_test']
