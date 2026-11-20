"""Regression checks for the release evidence and overlay verification."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

GATE = Path(__file__).resolve().parents[2] / "ops" / "qa-release.py"


class ReleaseEvidenceTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.stage = Path(directory.name)
        self.source = self.stage / "view.php"
        self.source.write_text("reviewed")
        sha = hashlib.sha256(self.source.read_bytes()).hexdigest()
        self.manifest = self.stage / "manifest"
        self.manifest.write_text(f"{sha}  view.php\n")
        self.junit = self.stage / "qa.json.junit.xml"
        self.junit.write_text('<testsuites><testsuite tests="1"/></testsuites>')
        self.commit = "3" * 40
        self.evidence = self.stage / "qa.json"
        self.evidence.write_text(json.dumps({
            "commit": self.commit, "tests": 1, "skipped": 0, "files": {"view.php": sha},
            "junit_sha256": hashlib.sha256(self.junit.read_bytes()).hexdigest(),
        }))
        self.env = {**os.environ, "VENDRA_RELEASE_COMMIT": self.commit,
                    "VENDRA_QA_EVIDENCE": str(self.evidence),
                    "VENDRA_QA_EVIDENCE_SHA256": hashlib.sha256(self.evidence.read_bytes()).hexdigest()}

    def verify(self):
        return subprocess.run(["python3", str(GATE), "verify", str(self.stage), str(self.manifest)],
                              env=self.env, capture_output=True).returncode

    def test_reviewed_overlay(self):
        self.assertEqual(self.verify(), 0)

    def test_rejects_changed_stage(self):
        self.source.write_text("changed")
        self.assertNotEqual(self.verify(), 0)

    def test_rejects_a_different_commit(self):
        self.env["VENDRA_RELEASE_COMMIT"] = "9" * 40
        self.assertNotEqual(self.verify(), 0)

    def test_rejects_a_different_report(self):
        self.junit.write_text("<testsuites/>")
        self.assertNotEqual(self.verify(), 0)

    def test_rejects_changed_evidence(self):
        self.evidence.write_text("{}")
        self.assertNotEqual(self.verify(), 0)


if __name__ == "__main__":
    unittest.main()
