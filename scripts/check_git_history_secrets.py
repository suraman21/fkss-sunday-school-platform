#!/usr/bin/env python3
"""Report credential-shaped additions/removals in every reachable Git history.

Only commit IDs are printed, never matching lines. Bare instructional prefixes
are not credentials; use the same minimum shape as the current-tree check,
plus all currently supported classic-token prefixes. Merge parents and root
commits are included. This does not replace a dedicated secret scanner.
"""
import re
import subprocess
import sys


def main() -> int:
    prefix = 'gh'
    fine_grained = 'github' + '_pat_'
    private_key_end = 'PRIVATE KEY' + '-----'
    pattern = (
        prefix + r'[pousr]_[A-Za-z0-9]{20,}|'
        + fine_grained + r'[A-Za-z0-9_]{20,}|'
        + private_key_end
    )
    try:
        result = subprocess.run(
            ['git', 'log', '--all', '--root', '-m', '--format=%H',
             '--extended-regexp', '--text', '--no-patch', '-G', pattern],
            check=True, text=True, capture_output=True,
        )
    except (OSError, subprocess.CalledProcessError):
        print('Could not inspect Git history; failing closed.', file=sys.stderr)
        return 2
    hits = list(dict.fromkeys(line for line in result.stdout.splitlines() if line))
    if any(not re.fullmatch(r'[0-9a-f]{40,64}', commit) for commit in hits):
        print('Unexpected Git history output; failing closed.', file=sys.stderr)
        return 2
    if hits:
        print('Credential-shaped history found in commit(s): ' + ', '.join(hits[:5]), file=sys.stderr)
        return 1
    print('No credential-shaped changes found in reachable Git history.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
