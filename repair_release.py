#!/usr/bin/env python3
import base64
import glob
import hashlib
import io
import os
import string
import sys
import tarfile

ALPHABET = string.ascii_uppercase + string.ascii_lowercase + string.digits + '+/'


def die(msg: str) -> None:
    print(f'[release-repair] ERROR: {msg}', file=sys.stderr)
    raise SystemExit(1)


def decode_candidate(text: str):
    try:
        return base64.b64decode(text, validate=True)
    except Exception:
        return None


def valid_tar(data: bytes) -> bool:
    try:
        with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as tf:
            tf.getmembers()
        return True
    except Exception:
        return False


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def matches(text: str, expected: str):
    data = decode_candidate(text)
    if data is None:
        return None
    if expected and digest(data) != expected:
        return None
    if not valid_tar(data):
        return None
    return data


def main() -> None:
    if len(sys.argv) != 5:
        die('usage: repair_release.py RELEASE_DIR PREFIX SHA_FILE OUTPUT')

    rel, prefix, sha_file, out = sys.argv[1:]
    part_paths = sorted(glob.glob(os.path.join(rel, prefix + '.b64.part-*')))
    if not part_paths:
        die(f'no parts found for {prefix}')

    pieces = []
    boundaries = []
    total = 0
    for path in part_paths:
        raw = open(path, 'r', encoding='utf-8', errors='strict').read()
        clean = ''.join(raw.split())
        if not clean:
            die(f'empty part: {os.path.basename(path)}')
        pieces.append(clean)
        total += len(clean)
        boundaries.append(total)
    boundaries = boundaries[:-1]
    text = ''.join(pieces)

    expected = ''
    sha_path = os.path.join(rel, sha_file)
    if os.path.isfile(sha_path):
        expected = open(sha_path, 'r', encoding='utf-8').read().strip().split()[0].lower()
        if len(expected) != 64 or any(c not in '0123456789abcdef' for c in expected):
            die(f'invalid sha file: {sha_file}')

    data = matches(text, expected)
    repair = None

    if data is None and expected:
        # Historical release chunks had a one-character defect close to a split boundary.
        # Search only around boundaries and accept a candidate solely when both the
        # canonical SHA-256 and tar.gz validation match.
        positions = sorted({
            p
            for boundary in boundaries
            for p in range(max(0, boundary - 12), min(len(text), boundary + 12) + 1)
        })

        for pos in positions:
            for ch in ALPHABET:
                candidate = text[:pos] + ch + text[pos:]
                data = matches(candidate, expected)
                if data is not None:
                    repair = f'insert {ch!r} at {pos}'
                    break
            if data is not None:
                break

        if data is None:
            for pos in positions:
                candidate = text[:pos] + text[pos + 1:]
                data = matches(candidate, expected)
                if data is not None:
                    repair = f'delete {text[pos]!r} at {pos}'
                    break

        if data is None:
            for pos in positions:
                original = text[pos]
                for ch in ALPHABET:
                    if ch == original:
                        continue
                    candidate = text[:pos] + ch + text[pos + 1:]
                    data = matches(candidate, expected)
                    if data is not None:
                        repair = f'replace {original!r} with {ch!r} at {pos}'
                        break
                if data is not None:
                    break

    if data is None:
        raw_data = decode_candidate(text)
        actual = digest(raw_data) if raw_data is not None else 'decode-failed'
        die(f'{prefix} could not be validated/repaired (expected={expected or "none"}, actual={actual})')

    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    with open(out, 'wb') as fh:
        fh.write(data)

    if repair:
        print(f'[release-repair] repaired {prefix}: {repair}; sha256={digest(data)}')
    else:
        print(f'[release-repair] validated {prefix}; sha256={digest(data)}')


if __name__ == '__main__':
    main()
