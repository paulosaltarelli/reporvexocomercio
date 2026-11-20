#!/usr/bin/env python3
"""Run the complete PHPUnit suite or verify release evidence against an overlay."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git(root, *args):
    return subprocess.check_output(["git", *args], cwd=root)


def sources(root):
    if git(root, "status", "--porcelain", "--untracked-files=all"):
        raise ValueError("A evidência de publicação exige um checkout limpo.")
    files = git(root, "ls-files", "-z").decode().split("\0")[:-1]
    return {name: sha(root / name) for name in files}


def run(root, evidence):
    if not os.getenv("VENDRA_TEST_PASSWORD") and not (root / ".env.testing").is_file():
        raise ValueError("Configure VENDRA_TEST_PASSWORD para o MySQL dedicado.")
    commit = git(root, "rev-parse", "HEAD").decode().strip() if evidence else None
    files = sources(root) if evidence else None
    report = evidence.with_name(evidence.name + ".junit.xml") if evidence else None
    if evidence and (evidence.is_relative_to(root) or evidence.exists() or report.exists()):
        raise ValueError("Escolha um arquivo de evidência novo fora do checkout.")
    with tempfile.TemporaryDirectory() as tmp:
        views = Path(tmp) / "views"
        views.mkdir()
        junit = Path(tmp) / "junit.xml"
        subprocess.run([str(root / "vendor/bin/phpunit"), "-c", str(root / "phpunit.xml"),
                        "--fail-on-skipped", "--fail-on-incomplete", "--fail-on-empty-test-suite",
                        "--fail-on-risky", "--log-junit", str(junit)], cwd=root, check=True,
                       env={**os.environ, "APP_ENV": "testing", "VIEW_COMPILED_PATH": str(views)})
        cases = ET.parse(junit).findall(".//testcase")
        if not cases or any(case.find(tag) is not None for case in cases
                            for tag in ("failure", "error", "skipped")):
            raise ValueError("Relatório JUnit incompleto ou com falhas.")
        for suite in ("Unit", "Feature"):
            prefix = f"Tests\\{suite}\\"
            if not any(case.get("class", "").startswith(prefix) for case in cases):
                raise ValueError(f"Suíte {suite} ausente.")
        if evidence:
            if git(root, "rev-parse", "HEAD").decode().strip() != commit or sources(root) != files:
                raise ValueError("O código mudou durante a suíte.")
            record = {"commit": commit, "tests": len(cases), "skipped": 0, "files": files,
                      "junit_sha256": sha(junit)}
            with report.open("xb") as stream:
                stream.write(junit.read_bytes())
            with evidence.open("x") as stream:
                json.dump(record, stream, sort_keys=True)
            print(f"QA {commit}: {len(cases)} testes. SHA-256 da evidência: {sha(evidence)}")


def verify(stage, manifest):
    path = Path(os.environ["VENDRA_QA_EVIDENCE"])
    commit = os.environ["VENDRA_RELEASE_COMMIT"]
    expected = os.environ["VENDRA_QA_EVIDENCE_SHA256"]
    data = path.read_bytes()
    if hashlib.sha256(data).hexdigest() != expected:
        raise ValueError("SHA-256 da evidência diverge da revisão.")
    record = json.loads(data)
    if not re.fullmatch("[a-f0-9]{40}", commit) or record["commit"] != commit:
        raise ValueError("Commit diverge da execução de QA.")
    if sha(path.with_name(path.name + ".junit.xml")) != record["junit_sha256"]:
        raise ValueError("O relatório JUnit diverge da evidência.")
    if record["tests"] <= 0 or record["skipped"] != 0:
        raise ValueError("Evidência sem execução integral.")
    lines = manifest.read_text().splitlines()
    if not lines:
        raise ValueError("Manifesto vazio.")
    for line in lines:
        match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9_./-]+)", line)
        if not match:
            raise ValueError("Manifesto inválido.")
        digest, name = match.groups()
        if record["files"].get(name) != digest or sha(stage / name) != digest:
            raise ValueError(f"Arquivo fora da revisão testada: {name}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    run_parser = sub.add_parser("run")
    run_parser.add_argument("--evidence", type=Path)
    verify_parser = sub.add_parser("verify")
    verify_parser.add_argument("stage", type=Path)
    verify_parser.add_argument("manifest", type=Path)
    args = parser.parse_args()
    try:
        if args.command == "run":
            run(Path(__file__).resolve().parent.parent, args.evidence.resolve() if args.evidence else None)
        else:
            verify(args.stage, args.manifest)
    except (ValueError, KeyError, OSError, subprocess.CalledProcessError, ET.ParseError) as exc:
        sys.exit(f"QA interrompido: {exc}")
