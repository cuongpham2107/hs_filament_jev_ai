# /// script
# requires-python = ">=3.11"
# dependencies = ["openpyxl"]
# ///
"""Xuất cây biểu thuế (hs_tree.json, hs_tree/XXXX.json, hs_tree/tax/, muc_luc.json) + hs_rules.txt + hs_tax.csv
từ file biểu thuế Excel. Laravel (admin/, lệnh hs:import) nạp các file này vào CSDL.

Chạy:  uv run scripts/src/parse_tariff_excel.py 2026
- Đọc file .xlsx duy nhất trong data/2026/, ghi kết quả ngay trong data/2026/
  (hs_tree.json, hs_tree/, hs_tax.csv). Năm mới: tạo data/<năm>/, bỏ file Excel vào, chạy lại.
- Ghi chú nghiệp vụ dùng chung mọi năm: data/ghi_chu_nghiep_vu.md, mỗi mục "## XXXX" (nhóm 4 số)
  được ghép vào cuối phần chú giải của file cây nhóm đó.
- File có sheet BT2026: chỉ đọc sheet đó (đã gộp mọi biểu thuế), dùng cột C (mã loại dòng)
  để tách chương / chú giải / dòng hàng.
- File khác (vd bieu thue 2025-2.xlsx): sheet đầu tiên có cột "Mô tả", nhận dạng theo gạch ngang.
"""
import csv
import os
import re
import sys
from pathlib import Path

import openpyxl

HS_RE = re.compile(r"^\d{4}(\d{2}){0,2}$")
DASH_RE = re.compile(r"^((?:-\s*)+)")
NOTE_TYPES = range(7, 18)  # cột C BT2026: 7-17 = chú giải phần/chương/phân nhóm, SEN, ghi chú phụ
SKIP_HEAD = {"văn bản", "ngày hiệu lực", "unit of quantity"}


# ---------- đọc sheet ----------
def norm_code(v):
    if v is None:
        return None
    if isinstance(v, float):
        v = int(v)
    s = re.sub(r"[.\s]", "", str(v))
    return s if HS_RE.match(s) else None


def clean(s):
    return re.sub(r"\s+", " ", str(s or "")).strip()


def fmt(v):
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return clean(v)


def rate_columns(h3, h4, after):
    """Cột thuế/chính sách sau cột mô tả. Cột con A–F không tên (RCEPT) -> 'RCEPT-A'..."""
    out, last = [], None
    for i in range(after + 1, len(h3)):
        h = clean(h3[i])
        sub = clean(h4[i]) if i < len(h4) else ""
        sub = sub if re.fullmatch(r"[A-F]", sub) else ""
        if h:
            last = h
        name = (f"{h}-{sub}" if sub else h) if h else (f"{last}-{sub}" if sub and last else "")
        if name and name.lower() not in SKIP_HEAD and not name.lower().startswith("mô tả"):
            out.append((i, name))
    return out


def find_columns(rows):
    for hi, r in enumerate(rows[:40]):
        heads = [clean(c).lower() for c in r]
        desc = next((i for i, h in enumerate(heads) if h.startswith("mô tả") and "anh" not in h), None)
        if desc is None:
            continue
        data = rows[hi + 1:hi + 500]
        code = next((i for i, h in enumerate(heads) if h == "mã hàng"), None)
        if code is None:  # cột mã không có tiêu đề -> cột có nhiều mã HS nhất
            counts = [sum(norm_code(x[i]) is not None for x in data if i < len(x)) for i in range(len(r))]
            code = counts.index(max(counts))
        # cột loại dòng (BT2026 cột C): toàn số nguyên 1–24
        typ = [sum(isinstance(x[i], int) and 1 <= x[i] <= 24 for x in data if i < len(x)) for i in range(code)]
        return {
            "hi": hi, "code": code, "desc": desc,
            "en": next((i for i, h in enumerate(heads) if h.startswith("mô tả") and "anh" in h), None),
            "note": next((i for i, h in enumerate(heads) if "ghi chú" in h), None),
            "type": typ.index(max(typ)) if typ and max(typ) > 100 else None,
            "rates": rate_columns(r, rows[hi + 1] if hi + 1 < len(rows) else (), desc),
        }
    return None


def level_and_text(desc):
    m = DASH_RE.match(desc)
    if not m:
        return 0, desc
    return m.group(1).count("-"), desc[m.end():].strip()


def strip_colon(s):
    # không cắt dấu "." cuối: là một phần của tên (vd "Tuber spp.", "Gracilaria spp.")
    return s.rstrip(" :;").strip()


def short_name(name, limit=80):
    # ponytail: rút gọn = cắt ở "(" / ";" đầu tiên, tối đa 80 ký tự
    s = re.split(r"\s*[(;]", name, maxsplit=1)[0].strip(" ,:")
    return s if len(s) <= limit else s[:limit].rsplit(" ", 1)[0] + "…"


def parse_sheet(rows, cols):
    """-> list item theo thứ tự: kind section/chapter/note/line."""
    get = lambda r, i: r[i] if i is not None and i < len(r) else None
    items, section, chapter, expect = [], None, None, False
    for r in rows[cols["hi"] + 1:]:
        desc, code, typ = clean(get(r, cols["desc"])), norm_code(get(r, cols["code"])), get(r, cols["type"])
        if not desc and not code:
            continue
        if typ in (2, 4) or (typ is None and expect):  # dòng tên ngay sau "PHẦN I" / "Chương 1"
            (section if typ == 2 else chapter)["name"] = desc
            expect = False
            continue
        if typ == 1:
            section = {"no": desc.split()[-1], "name": "", "notes": []}
            items.append({"kind": "section", "obj": section})
            continue
        if typ == 3 or (typ is None and re.match(r"^Chương \d+$", desc)):
            chapter = {"no": desc.split()[1], "name": "", "notes": [], "section": section}
            items.append({"kind": "chapter", "obj": chapter})
            expect = True
            continue
        if typ in NOTE_TYPES:
            items.append({"kind": "note", "text": desc})
            continue
        if typ in (5, 6):  # phân chương: không cần cho cây
            continue
        rate_dict = {name: fmt(get(r, idx)) for idx, name in cols.get("rates", ())} if cols.get("rates") else {}
        items.append({"kind": "line", "code": code, "desc": desc, "en": clean(get(r, cols["en"])),
                      "note": clean(get(r, cols["note"])), "chapter": chapter, "tax": rate_dict})
    return fix_shifted_codes(items)


def fix_shifted_codes(items):
    """Lỗi nguồn: mã 8 số nằm trên dòng tiêu đề '...:' trong khi dòng con kế tiếp không có mã."""
    out = [dict(x) for x in items]
    for a, b in zip(out, out[1:]):
        if (a["kind"] == b["kind"] == "line" and a["code"] and len(a["code"]) == 8
                and a["desc"].endswith(":") and not b["code"]
                and level_and_text(b["desc"])[0] > level_and_text(a["desc"])[0]):
            b["code"], a["code"] = a["code"], None
            b["shifted_from"] = a["desc"]  # ghi lại để kiểm tra (hs_skipped.json)
            if not any(b.get("tax", {}).values()) and any(a.get("tax", {}).values()):
                b["tax"] = a["tax"]
    return out


# ---------- dựng khối knowledge ----------
def build_headings(items):
    """-> list heading: {code, name, en, chapter, notes, codes: [mã 8 số]} (dùng để đối chiếu số mã với cây chuẩn)"""
    headings, by_code, emitted = [], {}, set()  # emitted: nguồn có dòng trùng (chương 98) -> giữ lần đầu
    cur, scope = None, None
    for it in items:
        if it["kind"] in ("section", "chapter"):
            cur, scope = None, it["obj"]["notes"]
            continue
        if it["kind"] == "note":
            if scope is not None:
                scope.append(it["text"])
            continue
        code, desc = it["code"], it["desc"]
        if code and len(code) == 8:
            if code in emitted:
                continue
            emitted.add(code)
        if code and (len(code) == 4 or (len(code) == 8 and not desc.startswith("-"))):
            # dòng nhóm 4 số (hoặc mã 8 số đứng thẳng ở cấp nhóm, vd 41120000 / chương 98)
            new = code[:4] not in by_code
            if new:
                by_code[code[:4]] = {"code": code[:4], "name": desc, "en": it["en"],
                                     "chapter": it["chapter"], "notes": [], "codes": []}
                headings.append(by_code[code[:4]])
            cur = by_code[code[:4]]
            scope = cur["notes"]
            if len(code) == 8:
                cur["codes"].append(code)
            continue
        if cur is None or not desc.startswith("-"):
            continue  # dòng ngoài cấu trúc
        if code and len(code) == 8:
            cur["codes"].append(code)
    return [h for h in headings if h["codes"]]


def read_biz_notes(path):
    """data/ghi_chu_nghiep_vu.md -> {nhóm 4 số: [dòng ghi chú]}; phần trước mục '## XXXX' đầu tiên bị bỏ qua."""
    notes, cur = {}, None
    if not path.exists():
        return notes
    for ln in path.read_text(encoding="utf-8").splitlines():
        m = re.match(r"^##\s+(\d{2})\.?(\d{2})\s*$", ln)
        if m:
            cur = notes.setdefault(m.group(1) + m.group(2), [])
        elif cur is not None and ln.strip():
            cur.append(ln.rstrip())
    return notes


def read_rules(ws):
    """Sheet 6QT -> lời của 6 quy tắc tổng quát (tiếng Việt), bỏ phần 'CHÚ GIẢI QUY TẮC' dài ~30.000 ký tự."""
    out, keep = [], False
    for r in ws.iter_rows(values_only=True):
        text = next((clean(c) for c in r if clean(c)), "")
        if re.match(r"^QUY TẮC \d+$", text):
            keep = True
        elif text.startswith("CHÚ GIẢI QUY TẮC"):
            keep = False
        if keep and text:
            out.append(text)
    return "\n".join(out)


# ---------- main ----------
ROOT = Path(os.environ["HS_DATA_PATH"]) if os.environ.get("HS_DATA_PATH") else Path(__file__).resolve().parents[2] / "data"


def main(year):
    out = ROOT / str(year)
    xlsx = sorted(p for p in out.glob("*.xlsx") if not p.name.startswith("~$"))
    if len(xlsx) != 1:
        sys.exit(f"Cần đúng 1 file .xlsx trong {out}, đang có: {[p.name for p in xlsx]}")
    biz = read_biz_notes(ROOT / "ghi_chu_nghiep_vu.md")
    wb = openpyxl.load_workbook(xlsx[0], read_only=True, data_only=True)
    for ws in ([wb["BT2026"]] if "BT2026" in wb.sheetnames else wb.worksheets):
        rows = [tuple(r) for r in ws.iter_rows(values_only=True)]
        cols = find_columns(rows)
        if cols:
            break
    else:
        sys.exit("Không thấy sheet nào có cột 'Mô tả'")
    print(f"sheet {ws.title}: header dòng {cols['hi'] + 1}, mã=col{cols['code']}, mô tả=col{cols['desc']}, "
          f"EN=col{cols['en']}, loại dòng=col{cols['type']}, ghi chú=col{cols['note']}, "
          f"{len(cols['rates'])} cột thuế/chính sách")

    if "6QT" in wb.sheetnames:
        rules = read_rules(wb["6QT"])
        assert len(re.findall(r"^QUY TẮC \d+$", rules, re.M)) == 6, "sheet 6QT không đủ 6 quy tắc"
        (out / "hs_rules.txt").write_text(rules + "\n", encoding="utf-8")
        print(f"hs_rules.txt: 6 quy tắc, {len(rules)} ký tự")

    items = parse_sheet(rows, cols)
    headings = build_headings(items)
    out.mkdir(parents=True, exist_ok=True)
    tree_dir = out / "hs_tree"
    tree_dir.mkdir(exist_ok=True)

    # nguồn chuẩn + các file sinh từ nó (hs_tree/XXXX.json, hs_tree/tax/, muc_luc.json)
    import json
    import tariff_tree as M
    risk_file = out / "hs_risk.json"
    risk_map = json.loads(risk_file.read_text(encoding="utf-8")) if risk_file.exists() else {}
    tree = M.build_tree(items, biz, risk_map)
    errs, warns = M.validate(tree, len({c for h in headings for c in h["codes"]}))
    if errs:
        sys.exit("LỖI CẤU TRÚC CÂY:\n" + "\n".join(errs[:50]))
    M.dump({"year": str(year), "source": xlsx[0].name, "sections": tree["sections"],
            "chapters": tree["chapters"], "headings": tree["headings"]}, out / "hs_tree.json")
    # những gì script tự bỏ qua / tự sửa so với nguồn -> ghi ra để người nghiệp vụ soát khi đổi năm
    shifted = [{"code": it["code"], "desc": it["desc"], "ma_nam_o_dong": it["shifted_from"]}
               for it in items if it.get("shifted_from")]
    M.dump({"bo_qua": tree["skipped"], "doi_ma_sang_dong_con": shifted}, out / "hs_skipped.json")
    print(f"Bỏ qua {len(tree['skipped'])} dòng, dời mã {len(shifted)} dòng -> hs_skipped.json")
    M.dump(M.muc_luc(tree), tree_dir / "muc_luc.json")
    tax_dir = tree_dir / "tax"
    tax_dir.mkdir(exist_ok=True)
    for h in tree["headings"]:
        M.dump(M.heading_doc(h, tree), tree_dir / f"{h['code']}.json")
        for c, meta in M.heading_tax(h).items():
            (tax_dir / f"{c}.json").write_text(json.dumps(meta, ensure_ascii=False), encoding="utf-8")
    print(f"Cây chuẩn: {len(tree['headings'])} nhóm, 0 lỗi, {len(warns)} cảnh báo chương 98")

    rates = {}
    for r in rows[cols["hi"] + 1:]:
        c = norm_code(r[cols["code"]] if cols["code"] < len(r) else None)
        if c and len(c) == 8 and c not in rates:
            rates[c] = [fmt(r[i]) if i < len(r) else "" for i, _ in cols["rates"]]
    codes8 = [c for h in headings for c in h["codes"]]
    with open(out / "hs_tax.csv", "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["hscode", *(n for _, n in cols["rates"])])
        for c in dict.fromkeys(codes8 + sorted(rates)):
            w.writerow([c, *rates.get(c, [""] * len(cols["rates"]))])

    # tự kiểm tra
    assert len(codes8) == len(set(codes8)), "mã 8 số trùng"
    unknown = sorted(set(biz) - {h["code"] for h in headings})
    if unknown:
        print(f"CẢNH BÁO: ghi chú nghiệp vụ cho nhóm không có trong biểu thuế {year}: {unknown}")

    print("===== 10 dòng csv =====")
    print("\n".join((out / "hs_tax.csv").read_text(encoding="utf-8-sig").splitlines()[:11]))
    n_notes = sum(1 for it in items if it["kind"] == "note")
    missing = sorted(set(rates) - set(codes8))
    print(f"\nTổng mã 8 số: {len(codes8)} (trong sheet: {len(rates)}, không vào cây: {len(missing)} {missing[:10]})"
          f"\nSố nhóm: {len(headings)} | Dòng chú giải: {n_notes}"
          f"\nGhi chú nghiệp vụ: {sum(len(v) for v in biz.values())} dòng cho nhóm {sorted(biz)}")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("Cách chạy: uv run scripts/src/parse_tariff_excel.py <năm>   (vd: uv run scripts/src/parse_tariff_excel.py 2026)")
    main(sys.argv[1])
