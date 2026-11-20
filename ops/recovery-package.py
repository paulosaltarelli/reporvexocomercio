#!/usr/bin/env python3
"""Export a verified local Vendra snapshot or restore it into a fresh private directory."""
import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile

REQUIRED = {"application.tar.gz", "databases.sql", "databases.txt"}
OPTIONAL = {"controlplane.tar.gz"}
MANIFEST = "SHA256SUMS"


def digest(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def verify(folder):
    manifest = folder / MANIFEST
    if manifest.is_symlink() or not manifest.is_file():
        raise ValueError("Manifesto ausente ou inválido.")
    hashes = {}
    for line in manifest.read_text(encoding="ascii").splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  ([a-z.]+)", line)
        if not match or match[2] not in REQUIRED | OPTIONAL or match[2] in hashes:
            raise ValueError("Entrada de manifesto inválida.")
        hashes[match[2]] = match[1]
    if not REQUIRED <= hashes.keys() or {entry.name for entry in folder.iterdir()} != hashes.keys() | {MANIFEST}:
        raise ValueError("Pacote incompleto ou inesperado.")
    for name, expected in hashes.items():
        path = folder / name
        if path.is_symlink() or not path.is_file() or digest(path) != expected:
            raise ValueError("Checksum divergente.")
    return sorted(hashes) + [MANIFEST]


def internal_archives(folder):
    for filename, prefix in [("application.tar.gz", "reporvexo"), ("controlplane.tar.gz", "controlplane")]:
        if (folder / filename).exists():
            yield folder / filename, prefix


def checked_entries(archive, prefix):
    found_env = False
    for entry in archive:
        path = Path(entry.name)
        if not path.parts or path.parts[0] != prefix or ".." in path.parts or not (entry.isfile() or entry.isdir()):
            raise ValueError("Tipo ou caminho inesperado no pacote interno.")
        if entry.isfile() and path == Path("reporvexo/.env"):
            found_env = True
        yield entry, path
    if prefix == "reporvexo" and not found_env:
        raise ValueError("Configuração da aplicação ausente.")


def export(args):
    if not re.fullmatch(r"[A-Fa-f0-9]{40}|[A-Fa-f0-9]{64}", args.recipient):
        raise ValueError("Informe a impressão digital completa da chave pública.")
    if not args.destination.is_dir() or args.destination.is_symlink():
        raise ValueError("O diretório de destino precisa existir.")
    if not args.local_drill and not os.path.ismount(args.destination):
        raise ValueError("O destino externo precisa estar montado.")
    if args.snapshot.name.startswith(".") or args.snapshot.is_symlink():
        raise ValueError("Use um snapshot finalizado.")
    files = verify(args.snapshot)
    for path, prefix in internal_archives(args.snapshot):
        with tarfile.open(path, mode="r:gz") as archive:
            for _ in checked_entries(archive, prefix):
                pass
    final = args.destination / (args.snapshot.name + ".tar.gpg")
    with tempfile.NamedTemporaryFile(dir=args.destination, prefix=".upload-") as staging:
        command = ["gpg", "--no-options", "--batch", "--no-auto-key-locate", "--trust-model", "always",
                   "--recipient", args.recipient, "--encrypt", "--output", "-"]
        with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=staging) as process:
            with process.stdin, tarfile.open(fileobj=process.stdin, mode="w|") as archive:
                for name in files:
                    archive.add(args.snapshot / name, arcname=name, recursive=False)
            if process.wait():
                raise ValueError("Falha ao cifrar o pacote.")
        os.fsync(staging.fileno())
        ciphertext_hash = digest(Path(staging.name))
        os.link(staging.name, final)  # Atomic publication, never overwrite an earlier backup.
        directory = os.open(args.destination, os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    print(ciphertext_hash, final.name)


def restore(args):
    if not re.fullmatch("[0-9a-f]{64}", args.sha256) or digest(args.encrypted) != args.sha256:
        raise ValueError("Hash externo do arquivo cifrado divergente.")
    # New folder exclusively owned by this invocation. Never write into a live installation.
    args.output.mkdir(mode=0o700)
    try:
        with tempfile.TemporaryFile(dir=args.output) as plaintext:
            subprocess.run(["gpg", "--no-options", "--batch", "--output", "-", "--decrypt", str(args.encrypted)],
                           stdout=plaintext, check=True)
            plaintext.seek(0)
            with tarfile.open(fileobj=plaintext, mode="r:") as archive:
                seen = set()
                for entry in archive:
                    if entry.name in seen or entry.name not in REQUIRED | OPTIONAL | {MANIFEST} or not entry.isfile():
                        raise ValueError("Conteúdo inesperado no pacote externo.")
                    seen.add(entry.name)
                    with archive.extractfile(entry) as source, (args.output / entry.name).open("xb") as target:
                        shutil.copyfileobj(source, target)
        verify(args.output)
        for archive_path, prefix in internal_archives(args.output):
            with tarfile.open(archive_path, mode="r:gz") as archive:
                for entry, path in checked_entries(archive, prefix):
                    dest = args.output / path
                    if entry.isdir():
                        dest.mkdir(parents=True, exist_ok=True, mode=0o700)
                    else:
                        dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                        with archive.extractfile(entry) as source, dest.open("xb") as target:
                            shutil.copyfileobj(source, target)
                        dest.chmod(0o700 if entry.mode & 0o100 else 0o600)
    except BaseException:
        shutil.rmtree(args.output)
        raise
    print("Arquivos restaurados e conferidos em diretório privado. O ensaio SQL é uma etapa separada.")


if __name__ == "__main__":
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(required=True)
    encrypt = commands.add_parser("export")
    encrypt.add_argument("snapshot", type=Path)
    encrypt.add_argument("destination", type=Path)
    encrypt.add_argument("recipient")
    encrypt.add_argument("--local-drill", action="store_true", help="Permite destino local para ensaio")
    encrypt.set_defaults(run=export)
    decrypt = commands.add_parser("restore")
    decrypt.add_argument("encrypted", type=Path)
    decrypt.add_argument("sha256")
    decrypt.add_argument("output", type=Path)
    decrypt.set_defaults(run=restore)
    options = parser.parse_args()
    options.run(options)
