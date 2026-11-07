"""Synthetic disposable Git histories exercise the real history scanner."""
import os
from pathlib import Path
import subprocess

import pytest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/check_git_history_secrets.py'


def git(repo, *args):
    return subprocess.run(['git', *args], cwd=repo, check=True, capture_output=True, text=True)


def commit(repo, text):
    (repo / 'sample.txt').write_text(text)
    git(repo, 'add', 'sample.txt')
    git(repo, 'commit', '-qm', 'synthetic fixture')


def scan(repo):
    env = dict(os.environ)
    # Do not let an outer checkout redirect Git into its own object database.
    for key in ('GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE'):
        env.pop(key, None)
    return subprocess.run(['python3', str(SCRIPT)], cwd=repo, env=env,
                          capture_output=True, text=True, timeout=30)


@pytest.fixture
def repo(tmp_path):
    git(tmp_path, 'init', '-q')
    git(tmp_path, 'config', 'user.name', 'Synthetic Test')
    git(tmp_path, 'config', 'user.email', 'synthetic@example.invalid')
    return tmp_path


def test_bare_instructional_prefix_is_not_a_secret(repo):
    commit(repo, 'Reject token prefix: ' + 'gh' + 'p_' + '\n')
    result = scan(repo)
    assert result.returncode == 0, result.stderr


@pytest.mark.parametrize('prefix', ['p', 'o', 'u', 's', 'r', 'fine', 'private'])
def test_added_and_removed_secret_shapes_stay_detectable_without_printing_them(repo, prefix):
    commit(repo, 'clean\n')
    if prefix == 'private':
        synthetic = '-----BEGIN ' + 'PRIVATE KEY' + '-----'
    elif prefix == 'fine':
        synthetic = 'github' + '_pat_' + ('x' * 24)
    else:
        synthetic = 'gh' + prefix + '_' + ('x' * 24)
    commit(repo, synthetic + '\n')
    commit(repo, 'removed\n')
    result = scan(repo)
    assert result.returncode == 1
    assert synthetic not in result.stdout + result.stderr
    assert 'Credential-shaped history found' in result.stderr


def test_root_commit_and_other_branch_are_included(repo):
    synthetic = 'gh' + 'p_' + ('z' * 24)
    commit(repo, synthetic + '\n')
    assert scan(repo).returncode == 1


def test_non_current_branch_is_included(repo):
    commit(repo, 'root\n')
    original = git(repo, 'symbolic-ref', '--short', 'HEAD').stdout.strip()
    git(repo, 'checkout', '-qb', 'synthetic-secret-branch')
    commit(repo, 'gh' + 'p_' + ('z' * 24) + '\n')
    git(repo, 'checkout', '-q', original)
    assert scan(repo).returncode == 1


def test_merge_commit_conflict_resolution_is_included(repo):
    commit(repo, 'root\n')
    original = git(repo, 'symbolic-ref', '--short', 'HEAD').stdout.strip()
    git(repo, 'checkout', '-qb', 'synthetic-branch')
    commit(repo, 'side\n')
    git(repo, 'checkout', '-q', original)
    commit(repo, 'main\n')
    failed = subprocess.run(['git', 'merge', '--no-commit', 'synthetic-branch'], cwd=repo,
                            capture_output=True, text=True)
    assert failed.returncode != 0
    (repo / 'sample.txt').write_text('gh' + 'p_' + ('z' * 24) + '\n')
    git(repo, 'add', 'sample.txt')
    git(repo, 'commit', '-qm', 'synthetic merge')
    commit(repo, 'removed\n')
    assert scan(repo).returncode == 1


def test_binary_history_is_scanned_without_printing_secret(repo):
    commit(repo, 'clean\n')
    synthetic = 'gh' + 'p_' + ('q' * 24)
    (repo / 'sample.txt').write_bytes(b'\x00\x01' + synthetic.encode() + b'\x00')
    git(repo, 'add', 'sample.txt')
    git(repo, 'commit', '-qm', 'synthetic binary')
    commit(repo, 'removed\n')
    result = scan(repo)
    assert result.returncode == 1
    assert synthetic not in result.stdout + result.stderr


def test_non_repository_fails_closed(tmp_path):
    result = scan(tmp_path)
    assert result.returncode == 2
    assert 'failing closed' in result.stderr
