#!/usr/bin/env python3
"""Gate a scoped deploy on effective web-user readability, including parent traversal."""
import argparse
import pathlib
import subprocess
import sys

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--root', default='/srv/reporvexo')
parser.add_argument('--user', default='vendra-web')
parser.add_argument('files', nargs='+')
args = parser.parse_args()
root = pathlib.Path(args.root)
if not root.is_absolute() or root.resolve() != root or not root.is_dir():
    parser.error('Root must be an existing canonical absolute directory.')
failed = False
for name in args.files:
    relative = pathlib.PurePosixPath(name)
    if relative.is_absolute() or '..' in relative.parts or not relative.parts:
        parser.error('Only relative paths inside root are allowed.')
    target = root / relative
    if target.is_symlink() or not target.is_file() or target.resolve() != target:
        print('Missing or non-regular deployment file: ' + name, file=sys.stderr)
        failed = True
        continue
    result = subprocess.run(['runuser', '-u', args.user, '--', 'test', '-r', str(target)], check=False)
    if result.returncode:
        print('Not readable as ' + args.user + ': ' + name, file=sys.stderr)
        failed = True
if failed:
    sys.exit(1)
print('Web-readable deployment files: ' + str(len(args.files)))
