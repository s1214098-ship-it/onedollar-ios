#!/usr/bin/env python3
"""Merge 達文西 GJP members into one company with three branches, and split
branch suffixes out of delivery buyer names. Does not touch product stock."""

from __future__ import annotations

import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

COMPANY = "宜蘭縣私立達文西幼兒園"
CANONICAL_ID = "gjp_mem_D161"
MERGE_IDS = ["gjp_mem_D163", "gjp_mem_D164", "gjp_mem_S05"]
UNIT_BRANCH = {
    "D161": {"name": "雪山村", "code": "209"},
    "D163": {"name": "幼兒園", "code": ""},
    "D164": {"name": "托嬰中心", "code": ""},
    "S05": {"name": "", "code": ""},
}
BRANCHES = [
    {"id": "br_daowenxi_xueshan", "name": "雪山村", "code": "209", "phone": "", "address": "", "note": "管家婆 D161"},
    {"id": "br_daowenxi_youer", "name": "幼兒園", "code": "", "phone": "", "address": "", "note": "管家婆 D163"},
    {"id": "br_daowenxi_tuoying", "name": "托嬰中心", "code": "", "phone": "", "address": "", "note": "管家婆 D164"},
]
ALIASES = [
    "宜蘭縣私立達文西幼兒園",
    "宜蘭縣私立達文西幼兒園(雪山村)(209)",
    "宜蘭縣私立達文西幼兒園(幼兒園)",
    "宜蘭縣私立達文西幼兒園(托嬰中心)",
    "達文西幼兒園",
    "達文西幼",
    "達文西",
    "宜蘭縣私",
]


def load(path: Path):
    text = path.read_text(encoding="utf-8-sig")
    return json.loads(text)


def dump(path: Path, data) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def parse_party(raw: str) -> tuple[str, str, str]:
    import re
    raw = (raw or "").strip()
    match = re.match(r"^(.+?)\s*[（(]([^）)]+)[）)](?:\s*[（(]([^）)]+)[）)])?\s*$", raw)
    if not match:
        return raw, "", ""
    company = match.group(1).strip()
    first = (match.group(2) or "").strip()
    second = (match.group(3) or "").strip()
    if second:
        return company, first, second
    if first.isdigit() and len(first) >= 2:
        return company, "", first
    return company, first, ""


def is_daowenxi(text: str) -> bool:
    return "達文西" in (text or "")


def repair_members(members: list) -> tuple[list, dict]:
    by_id = {}
    for row in members:
        if isinstance(row, dict) and row.get("id"):
            by_id[str(row["id"])] = row
    canonical = by_id.get(CANONICAL_ID)
    if not isinstance(canonical, dict):
        canonical = {"id": CANONICAL_ID, "created_at": datetime.now(timezone.utc).isoformat()}
        members.append(canonical)
        by_id[CANONICAL_ID] = canonical
    now = datetime.now().astimezone().isoformat(timespec="seconds")
    canonical.update({
        "name": COMPANY,
        "customer_name": COMPANY,
        "organization_name": COMPANY,
        "company_key": "daowenxi",
        "company_name": COMPANY,
        "aliases": ALIASES,
        "branches": BRANCHES,
        "source_unit_codes": ["D161", "D163", "D164", "S05"],
        "merged_from": MERGE_IDS,
        "status": canonical.get("status") if canonical.get("status") not in ("merged",) else "正常",
        "updated_at": now,
        "note": (canonical.get("note") or "").strip(),
    })
    if "merged_into" in canonical:
        canonical.pop("merged_into", None)
    extra = []
    for mid in MERGE_IDS:
        row = by_id.get(mid)
        if not isinstance(row, dict):
            continue
        extra.append(f"{mid}/{(row.get('organization_name') or row.get('name') or '')}")
        row["status"] = "merged"
        row["merged_into"] = CANONICAL_ID
        row["company_key"] = "daowenxi"
        row["company_name"] = COMPANY
        row["updated_at"] = now
        orig = (row.get("organization_name") or row.get("name") or "").strip()
        if orig and orig not in canonical["aliases"]:
            canonical["aliases"].append(orig)
    if extra:
        note = canonical.get("note") or ""
        stamp = "已合併達文西三分店：" + "、".join(extra)
        if stamp not in note:
            canonical["note"] = (note + "\n" + stamp).strip()
    return members, {
        "canonical_id": CANONICAL_ID,
        "merged": MERGE_IDS,
        "aliases": canonical["aliases"],
    }


def repair_deliveries(notes: list) -> tuple[list, int]:
    changed = 0
    for row in notes:
        if not isinstance(row, dict):
            continue
        buyer = row.get("buyer") if isinstance(row.get("buyer"), dict) else None
        blob = json.dumps(row, ensure_ascii=False)
        if "達文西" not in blob:
            continue
        if buyer is None:
            buyer = {"name": row.get("customer_name") or ""}
            row["buyer"] = buyer
        name = str(buyer.get("name") or "")
        branch = str(buyer.get("branch") or row.get("customer_branch") or "")
        company, parsed_branch, code = parse_party(name)
        if is_daowenxi(name) or is_daowenxi(company):
            if name != COMPANY:
                buyer["original_name"] = buyer.get("original_name") or name
            buyer["name"] = COMPANY
            buyer["company_name"] = COMPANY
            if not branch:
                branch = parsed_branch
            if code == "209" and not branch:
                branch = "雪山村"
            if branch:
                buyer["branch"] = branch
            if code:
                buyer["branch_code"] = code
            if not buyer.get("member_id"):
                buyer["member_id"] = CANONICAL_ID
            elif str(buyer.get("member_id")) in MERGE_IDS:
                buyer["member_id"] = CANONICAL_ID
            row["customer_branch"] = buyer.get("branch") or ""
            changed += 1
    return notes, changed


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--members", required=True)
    parser.add_argument("--deliveries", required=True)
    parser.add_argument("--backup-dir", default="")
    args = parser.parse_args()
    members_path = Path(args.members)
    deliveries_path = Path(args.deliveries)
    backup_dir = Path(args.backup_dir) if args.backup_dir else members_path.parent
    backup_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    shutil.copy2(members_path, backup_dir / f"members.json.before-daowenxi-{stamp}")
    shutil.copy2(deliveries_path, backup_dir / f"delivery_notes.json.before-daowenxi-{stamp}")
    members = load(members_path)
    deliveries = load(deliveries_path)
    if not isinstance(members, list) or not isinstance(deliveries, list):
        raise SystemExit("members/delivery_notes must be JSON arrays")
    members, member_info = repair_members(members)
    deliveries, delivery_changed = repair_deliveries(deliveries)
    dump(members_path, members)
    dump(deliveries_path, deliveries)
    print(json.dumps({
        "ok": True,
        "members": member_info,
        "deliveries_updated": delivery_changed,
        "member_count": len(members),
        "delivery_count": len(deliveries),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
