"""Mô hình cây chuẩn của biểu thuế (nguồn duy nhất cho mọi file sinh ra).

Dựng từ items của parse_tariff_excel.parse_sheet. Mỗi nhóm 4 số là một cây node theo cấp gạch ngang:
  node = {code: str|None, level: int, text, en, note, parent: index|None}
Từ cây suy ra cho mỗi mã 8 số: đường dẫn đầy đủ và phân nhóm 6 số.

Quy ước phân nhóm 6 số của một mã 8 số (theo cách trình bày biểu thuế Việt Nam):
  1. Có node tổ tiên mang mã 6 số -> đó là phân nhóm (phải trùng 6 số đầu của mã).
  2. Không có, và mã dạng XXXX00xx -> nhóm không chia phân nhóm (vd 9503.00).
  3. Không có, và mã dạng XXXXYY00 -> chính dòng 8 số là phân nhóm (vd 0101.21 = 01012100).
  Còn lại -> ghi vào anomalies để kiểm tra.
"""
import json
import re

from parse_tariff_excel import level_and_text, strip_colon

OTHER = "loại khác"


def _clean_en(en):
    return strip_colon(level_and_text(en)[1]) if en else ""


def _num_rate(val):
    if val is None:
        return None
    m = re.match(r"^\s*(\d+(?:\.\d+)?)\s*$", str(val).strip())
    return float(m.group(1)) if m else None


def build_tree(items, biz_notes=None, risk_map=None):
    """-> {'sections', 'chapters', 'headings': [...], 'skipped': [...]}"""
    biz_notes = biz_notes or {}
    risk_map = risk_map or {}
    sections, chapters, headings, by_code = {}, {}, [], {}
    skipped, emitted = [], set()
    notes = None  # list đang nhận chú giải (phần / chương / nhóm)
    sec = chap = cur = None
    stack = []  # [(level, node index)]

    for it in items:
        kind = it["kind"]
        if kind == "section":
            sec = {"no": it["obj"]["no"], "name": it["obj"]["name"], "notes": []}
            sections[sec["no"]] = sec
            notes, cur = sec["notes"], None
            continue
        if kind == "chapter":
            chap = {"no": it["obj"]["no"], "name": it["obj"]["name"], "section": sec["no"] if sec else "", "notes": []}
            chapters[chap["no"]] = chap
            notes, cur = chap["notes"], None
            continue
        if kind == "note":
            if notes is not None:
                notes.append(it["text"])
            continue

        code, desc = it["code"], it["desc"]
        if code and len(code) == 8:
            if code in emitted:  # nguồn lặp dòng (chương 98) -> giữ lần đầu
                skipped.append({"code": code, "reason": "trùng mã", "desc": desc})
                continue
            emitted.add(code)

        if code and (len(code) == 4 or (len(code) == 8 and not desc.startswith("-"))):
            if code[:4] not in by_code:
                ch = it["chapter"] or {}
                by_code[code[:4]] = {"code": code[:4], "name": desc, "en": _clean_en(it["en"]),
                                     "chapter": ch.get("no", ""), "notes": [],
                                     "biz_notes": list(biz_notes.get(code[:4], [])), "nodes": [],
                                     "risk_map": risk_map}
                headings.append(by_code[code[:4]])
                stack = []
            elif len(code) == 4:
                stack = []
            cur = by_code[code[:4]]
            notes = cur["notes"]
            if len(code) == 8:  # mã 8 số đứng thẳng ở cấp nhóm (vd 41120000)
                cur["nodes"].append({"code": code, "level": 1, "text": strip_colon(desc), "en": _clean_en(it["en"]),
                                     "note": it["note"], "parent": None, "tax": it.get("tax", {})})
            continue

        if cur is None and not code and notes is not None and len(desc) > 3:
            notes.append(desc)  # chú giải viết dạng gạch đầu dòng (vd định nghĩa "bộ com-lê" Chương 62)
            continue
        if cur is None or not desc.startswith("-"):
            skipped.append({"code": code, "reason": "ngoài cấu trúc", "desc": desc})
            continue
        lvl, text = level_and_text(desc)
        stack = [s for s in stack if s[0] < lvl]
        cur["nodes"].append({"code": code, "level": lvl, "text": strip_colon(text), "en": _clean_en(it["en"]),
                             "note": it["note"], "parent": stack[-1][1] if stack else None, "tax": it.get("tax", {})})
        stack.append((lvl, len(cur["nodes"]) - 1))

    headings = [h for h in headings if any(n["code"] and len(n["code"]) == 8 for n in h["nodes"])]
    return {"sections": sections, "chapters": chapters, "headings": headings, "skipped": skipped}


# ---------- suy ra từ cây ----------
def labels(nodes):
    """Nhãn hiển thị từng node: 'Loại khác' kèm các anh em đứng trước nó."""
    out, seen = [], {}
    for n in nodes:
        sib = seen.setdefault(n["parent"], [])
        t = n["text"]
        if t.lower().startswith(OTHER) and sib:
            t = f"{t} (không phải: {', '.join(sib)})"
        out.append(t)
        sib.append(n["text"])
    return out


def ancestors(nodes, i):
    chain = []
    while i is not None:
        chain.append(i)
        i = nodes[i]["parent"]
    return chain[::-1]


def _uncoded_subheading(nodes, chain):
    """Tổ tiên không mã có ít nhất 1 anh em mang mã 6 số -> coi là dòng phân nhóm bị thiếu mã."""
    for j in reversed(chain[:-1]):
        if nodes[j]["code"]:
            continue
        p = nodes[j]["parent"]
        if any(n["parent"] == p and n["code"] and len(n["code"]) == 6 for n in nodes):
            return j
    return None


def heading_codes(h):
    """-> (codes, subs, anomalies)
    codes: {mã 8 số: {sub, path, display_path, note, en, tax, risk}}; subs: {mã 6 số: đường dẫn ('' = nhóm không chia phân nhóm)}"""
    nodes, lab = h["nodes"], labels(h["nodes"])
    codes, subs, anomalies = {}, {}, []

    # 1. Thu thập mức thuế MFN theo từng phân nhóm 6 số để tính rủi ro B3 (chênh lệch >= 5%)
    sub_rates = {}
    for n in nodes:
        if n.get("code") and len(n["code"]) == 8:
            sub = n["code"][:6]
            tax = n.get("tax", {})
            r = _num_rate(tax.get("NK ưu đãi"))
            sub_rates.setdefault(sub, []).append(r)

    diff_sub = {}
    for sub, rates in sub_rates.items():
        val_rates = [r for r in rates if r is not None]
        if len(val_rates) > 1 and max(val_rates) - min(val_rates) >= 5.0:
            diff_sub[sub] = (min(val_rates), max(val_rates))

    risk_map = h.get("risk_map") or {}
    head_part = f"{h['code']} {h['name']}"

    for i, n in enumerate(nodes):
        if not (n["code"] and len(n["code"]) == 8):
            continue
        c, chain = n["code"], ancestors(nodes, i)
        six = [j for j in chain if nodes[j]["code"] and len(nodes[j]["code"]) == 6]
        if six:
            j = six[-1]
            if nodes[j]["code"] != c[:6]:
                anomalies.append(f"{c}: nằm dưới phân nhóm {nodes[j]['code']}")
            sub_path = " > ".join(lab[k] for k in ancestors(nodes, j))
        elif c[4:6] == "00":
            sub_path = ""
        elif c[6:] == "00":
            sub_path = " > ".join(lab[k] for k in chain)
        elif (j := _uncoded_subheading(nodes, chain)) is not None:
            # lỗi nguồn: dòng phân nhóm thiếu mã (vd 4804.29 "Loại khác") -> node không mã cùng cấp với anh em mã 6 số
            sub_path = " > ".join(lab[k] for k in ancestors(nodes, j))
        else:
            anomalies.append(f"{c}: không xác định được phân nhóm 6 số")
            sub_path = " > ".join(lab[k] for k in chain[:-1])
        if subs.get(c[:6], sub_path) != sub_path:
            anomalies.append(f"{c}: phân nhóm {c[:6]} có 2 tên khác nhau")
        subs.setdefault(c[:6], sub_path)

        # Xây dựng display_path sạch (không có "(không phải: ...)")
        chain_parts = []
        for k in chain:
            nc = nodes[k]["code"]
            nt = nodes[k]["text"]
            if nc and len(nc) == 6:
                chain_parts.append(f"{nc[:4]}.{nc[4:]} {nt}")
            else:
                chain_parts.append(nt)
        display_path = " › ".join([head_part, *chain_parts])

        tax = n.get("tax", {})
        sub = c[:6]
        if c in risk_map:
            risk = risk_map[c]
        elif sub in diff_sub:
            min_r, max_r = diff_sub[sub]
            risk = {
                "level": "Trung bình",
                "reason": f"Cùng phân nhóm {sub[:4]}.{sub[4:]} có mức thuế MFN chênh lệch từ {min_r:g}% đến {max_r:g}% — cần kiểm tra kỹ mô tả dòng hàng"
            }
        elif tax.get("Chính sách mặt hàng theo mã HS"):
            risk = {
                "level": "Trung bình",
                "reason": "Mặt hàng có chính sách quản lý chuyên ngành theo văn bản của Bộ/Ngành"
            }
        else:
            risk = {
                "level": "Thấp",
                "reason": "Không nằm trong danh mục rủi ro"
            }

        codes[c] = {
            "sub": c[:6],
            "path": " > ".join(lab[k] for k in chain),
            "display_path": display_path,
            "note": n["note"],
            "en": " > ".join(nodes[k]["en"] for k in chain if nodes[k]["en"]),
            "tax": tax,
            "risk": risk
        }
    return codes, subs, anomalies


def heading_doc(h, tree):
    """File cây 1 nhóm cho Jev (hs_tree/XXXX.json)."""
    ch = tree["chapters"].get(h["chapter"], {})
    sec = tree["sections"].get(ch.get("section", ""), {})
    notes = {}
    for title, body in ((f"Ghi chú nghiệp vụ nhóm {h['code']}", h["biz_notes"]),
                        (f"Ghi chú trong nhóm {h['code']}", h["notes"]),
                        (f"Chú giải Chương {ch.get('no', '')}: {ch.get('name', '')}", ch.get("notes")),
                        (f"Chú giải Phần {sec.get('no', '')}: {sec.get('name', '')}", sec.get("notes"))):
        if body:
            notes[title] = "\n".join(body)
    codes, subs, _ = heading_codes(h)
    # file cây chỉ chứa phần Jev cần đọc; thuế/rủi ro tách ra hs_tree/tax/<mã>.json (xem heading_tax)
    # vì node HTTP của Dify giới hạn 1 MB/phản hồi (nhóm 8703 có thuế nhúng kèm = 1,4 MB)
    lean = {c: {k: v[k] for k in ("sub", "path", "note", "en")} for c, v in codes.items()}
    # nodes: cây theo cấp gạch ngang để đi từng cấp (Quy tắc 6: chỉ so các phân nhóm cùng cấp độ);
    # parent = chỉ số nút cha trong chính danh sách này (None = nhánh một gạch của nhóm)
    nodes = [{"code": n["code"], "label": lab, "en": n["en"], "note": n["note"], "parent": n["parent"]}
             for n, lab in zip(h["nodes"], labels(h["nodes"]))]
    return {"code": h["code"], "name": h["name"], "en": h["en"],
            "chapter": f"Chương {ch.get('no', '')}: {ch.get('name', '')}",
            "special": ch.get("no") == "98", "notes": notes, "subheadings": subs, "codes": lean, "nodes": nodes}


def heading_tax(h):
    """-> {mã 8 số: {display_path, tax, risk}} — mỗi mã ghi 1 file nhỏ hs_tree/tax/<mã>.json cho bước ghép kết quả."""
    codes, _, _ = heading_codes(h)
    return {c: {"display_path": v["display_path"], "tax": v["tax"], "risk": v["risk"]} for c, v in codes.items()}


def muc_luc(tree):
    """Mục lục chương -> nhóm 4 số (hs_tree/muc_luc.json) cho bước Jev chọn chương.
    Bỏ chương 98 và chương không có nhóm nào (chương 77 để dự phòng)."""
    heads = {}
    for h in tree["headings"]:
        if h["chapter"] != "98":
            heads.setdefault(h["chapter"], []).append([h["code"], h["name"] + (f" / {h['en']}" if h["en"] else "")])
    chapters = {no: {"name": c["name"], "section": c["section"],
                     "section_name": tree["sections"].get(c["section"], {}).get("name", "")}
                for no, c in tree["chapters"].items() if no in heads}
    return {"chapters": chapters, "headings": heads}


def validate(tree, n_codes_in_sheet):
    """-> (lỗi, cảnh báo). Lỗi rỗng = đạt. Chương 98 đánh mã riêng (không theo phân nhóm 6 số) -> chỉ cảnh báo."""
    errs, warns, total = [], [], 0
    for h in tree["headings"]:
        codes, _, anomalies = heading_codes(h)
        total += len(codes)
        (warns if h["chapter"] == "98" else errs).extend(f"[{h['code']}] {a}" for a in anomalies)
        for i, n in enumerate(h["nodes"]):
            p = n["parent"]
            if p is not None and h["nodes"][p]["level"] >= n["level"]:
                errs.append(f"[{h['code']}] node {i} '{n['text'][:40]}' cấp {n['level']} nằm dưới cấp {h['nodes'][p]['level']}")
            if n["code"] and n["code"][:4] != h["code"]:
                errs.append(f"[{h['code']}] mã {n['code']} không thuộc nhóm")
    if total != n_codes_in_sheet:
        errs.append(f"số mã 8 số trong cây {total} != trong sheet {n_codes_in_sheet}")
    return errs, warns


def diff_years(old, new):
    """So 2 hs_tree.json (2 năm) -> {'them', 'bo', 'doi_mo_ta'} theo mã 8 số."""
    def flat(tree):
        return {c: v["path"] for h in tree["headings"] for c, v in heading_codes(h)[0].items()}
    a, b = flat(old), flat(new)
    return {"them": sorted(set(b) - set(a)), "bo": sorted(set(a) - set(b)),
            "doi_mo_ta": sorted(c for c in set(a) & set(b) if a[c] != b[c])}


def dump(obj, path):
    path.write_text(json.dumps(obj, ensure_ascii=False, indent=1), encoding="utf-8")


if __name__ == "__main__":
    # So sánh 2 năm: python scripts/hs_model.py 2025 2026  -> data/2026/diff_2025_2026.json
    import sys
    from pathlib import Path
    if len(sys.argv) != 3:
        sys.exit("Cách chạy: python scripts/hs_model.py <năm cũ> <năm mới>")
    root = Path(__file__).resolve().parent.parent / "data"
    old, new = (json.loads((root / y / "hs_tree.json").read_text(encoding="utf-8")) for y in sys.argv[1:])
    d = diff_years(old, new)
    dump(d, root / sys.argv[2] / f"diff_{sys.argv[1]}_{sys.argv[2]}.json")
    print(f"thêm {len(d['them'])} | bỏ {len(d['bo'])} | đổi mô tả {len(d['doi_mo_ta'])}")
