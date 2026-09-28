#!/usr/bin/env python3
"""Smoke test for the structure-aware DOCX importer.

Usage:
  python tests/docx_extractor_smoke.py "path/to/real-question-bank.docx"

The test intentionally checks structural invariants instead of a hard-coded
question count, because real banks can legitimately contain different totals.
"""
import json, subprocess, sys
from collections import Counter

if len(sys.argv) != 2:
    print("Usage: python tests/docx_extractor_smoke.py <question-bank.docx>")
    raise SystemExit(2)

docx_path=sys.argv[1]
result=subprocess.run(
    [sys.executable, "includes/docx_extractor.py", docx_path],
    capture_output=True, text=True, encoding="utf-8"
)
if result.returncode != 0:
    print(result.stdout or result.stderr)
    raise SystemExit(result.returncode)

payload=json.loads(result.stdout)
assert payload.get("success") is True, payload
questions=payload.get("questions", [])
assert questions, "No questions extracted"

types=Counter(q.get("question_type") for q in questions)
sections=Counter(q.get("section_type") for q in questions)

# Every imported logical record must have the fields required by the current DB pipeline.
required={"q_number","unit_no","sub_unit","section_type","k_level","co_level","marks","question_text","answer_key"}
for i,q in enumerate(questions,1):
    missing=required-set(q)
    assert not missing, f"Q{i} missing {sorted(missing)}"
    assert 1 <= int(q["unit_no"]) <= 5, f"Q{i} invalid unit"
    assert str(q["sub_unit"]).count(".") == 1, f"Q{i} invalid sub-unit"
    assert str(q["k_level"]).upper() in {"K1","K2","K3","K4","K5","K6"}, f"Q{i} invalid K"
    assert str(q["co_level"]).upper() == "CO"+str(q["k_level"])[1:], f"Q{i} CO/K mismatch"
    assert str(q["question_text"]).strip(), f"Q{i} empty text"

print("PASS")
print("questions:", len(questions))
print("types:", dict(types))
print("sections:", dict(sections))
print("warnings:", sum(len(q.get("warnings",[])) for q in questions))
