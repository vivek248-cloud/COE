#!/usr/bin/env python3
"""Regression test for real-world COE DOCX question banks.

Usage:
  python tests/docx_real_bank_regression.py path/to/U23HI5CCT09.docx

The supplied Hindi sample contains repeated CODE/LEVEL/UNIT/TYPE metadata,
inline MCQ options, Match blocks, Assertion/Reason blocks, VSA, PA/P and E.
This test protects those structures from future parser regressions.
"""
import json, subprocess, sys
from collections import Counter

if len(sys.argv) != 2:
    raise SystemExit("Usage: python tests/docx_real_bank_regression.py <docx>")

p=sys.argv[1]
r=subprocess.run(
    [sys.executable, "includes/docx_extractor.py", p],
    capture_output=True, text=True, encoding="utf-8"
)
if r.returncode:
    print(r.stdout or r.stderr)
    raise SystemExit(r.returncode)

x=json.loads(r.stdout)
assert x.get("success") is True, x
qs=x.get("questions", [])
assert len(qs) >= 200, f"Expected the supplied real bank to produce 200+ logical records; got {len(qs)}"

types=Counter(q.get("question_type") for q in qs)
for required in ("MCQ","MATCH","ASSERTION_REASON","VSA","PARAGRAPH","ESSAY"):
    assert types[required] > 0, f"Missing {required} records: {dict(types)}"

for q in qs:
    assert q.get("sub_unit"), f"Missing sub-unit in Q{q.get('q_number')}"
    assert q.get("k_level") in {"K1","K2","K3","K4","K5","K6"}, f"Invalid K-level in Q{q.get('q_number')}"
    assert q.get("question_text"), f"Empty question text in Q{q.get('q_number')}"

mcq=[q for q in qs if q.get("question_type")=="MCQ"]
assert sum(len(q.get("options",{})) >= 4 for q in mcq) >= 20, "Inline/line-separated MCQ option extraction regressed"

ar=[q for q in qs if q.get("question_type")=="ASSERTION_REASON"]
assert sum(bool(q.get("assertion")) and bool(q.get("reason")) for q in ar) >= max(2, len(ar)//2), "Assertion/Reason structure extraction regressed"

print("PASS")
print("questions:",len(qs))
print("types:",dict(types))
print("warnings:",sum(len(q.get("warnings",[])) for q in qs))
print("mcq_with_4_options:",sum(len(q.get("options",{})) >= 4 for q in mcq))
print("assertion_reason_complete:",sum(bool(q.get("assertion")) and bool(q.get("reason")) for q in ar))
