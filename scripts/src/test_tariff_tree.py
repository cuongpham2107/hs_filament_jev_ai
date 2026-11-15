"""Kiểm tra cây chuẩn trên dữ liệu thật. Chạy sau parse_tariff_excel.py:
    uv run --with openpyxl --with pytest pytest scripts/src/test_tariff_tree.py
"""
import copy
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import tariff_tree as M  # noqa: E402

YEAR = "2026"
DATA_ROOT = Path(os.environ["HS_DATA_PATH"]) if os.environ.get("HS_DATA_PATH") else Path(__file__).resolve().parents[2] / "data"
DATA = DATA_ROOT / YEAR
TREE = json.loads((DATA / "hs_tree.json").read_text(encoding="utf-8"))
H = {h["code"]: h for h in TREE["headings"]}


def test_khong_loi_cau_truc():
    errs, _ = M.validate(TREE, sum(len(M.heading_codes(h)[0]) for h in TREE["headings"]))
    assert errs == []


def test_phan_nhom_chi_co_mot_ma_con():  # 0101.21 không có dòng 6 số riêng: dòng 01012100 là phân nhóm
    _, subs, _ = M.heading_codes(H["0101"])
    assert subs["010121"] == "Ngựa > Loại thuần chủng để nhân giống"


def test_nhom_khong_chia_phan_nhom():
    _, subs, _ = M.heading_codes(H["9503"])
    assert subs == {"950300": ""}


def test_dong_phan_nhom_thieu_ma_trong_nguon():  # 4804.29 "Loại khác" không ghi mã trong Excel
    _, subs, anomalies = M.heading_codes(H["4804"])
    assert subs["480429"].endswith("Loại khác (không phải: Chưa tẩy trắng)") and anomalies == []


def test_chu_giai_gach_dau_dong_khong_bi_mat():  # định nghĩa bộ com-lê, Chú giải Chương 62
    assert any("áo vét hoặc jacket" in n for n in TREE["chapters"]["62"]["notes"])


def test_mo_ta_co_dau_lon_hon_khong_bi_tach():
    codes, _, _ = M.heading_codes(H["9835"])
    assert codes["98350000"]["path"].endswith("NaOH > 42%.")  # giữ nguyên dấu chấm cuối của nguồn


def test_so_sanh_nam():
    old = copy.deepcopy(TREE)
    for n in next(h for h in old["headings"] if h["code"] == "8703")["nodes"]:
        if n["code"] == "87032211":
            n["code"] = "87032200"
        if n["code"] == "87032212":
            n["text"] = "Xe ATV cũ"
    # 87032230 "Loại khác (không phải: …ATV…)" đổi phạm vi theo anh em -> phải bị báo
    assert M.diff_years(old, TREE) == {"them": ["87032211"], "bo": ["87032200"], "doi_mo_ta": ["87032212", "87032230"]}


def test_file_cay_co_nut_cha_con():  # để workflow đi từng cấp (Quy tắc 6)
    doc = json.loads((DATA / "hs_tree" / "8714.json").read_text(encoding="utf-8"))
    nodes = doc["nodes"]
    spoke = next(n for n in nodes if n["code"] == "87141020")
    assert nodes[spoke["parent"]]["code"] == "871410" and nodes[spoke["parent"]]["parent"] is None
    assert {n["code"] for n in nodes if n["code"] and len(n["code"]) == 8} == set(doc["codes"])
    other = next(n for n in nodes if n["code"] == "87141090")
    assert other["label"].startswith("Loại khác (không phải: Yên xe")


def test_muc_luc_chuong():
    ml = json.loads((DATA / "hs_tree" / "muc_luc.json").read_text(encoding="utf-8"))
    assert "98" not in ml["chapters"] and len(ml["chapters"]) == 96
    assert sum(len(v) for v in ml["headings"].values()) == 1228
    assert ["8482", next(n for c, n in ml["headings"]["84"] if c == "8482")] in ml["headings"]["84"]


def test_sau_quy_tac():
    rules = (DATA / "hs_rules.txt").read_text(encoding="utf-8")
    assert [ln for ln in rules.splitlines() if ln.startswith("QUY TẮC")] == [f"QUY TẮC {i}" for i in range(1, 7)]
    assert "đặc tính cơ bản" in rules and "CHÚ GIẢI" not in rules and len(rules) < 6000


def test_khop_voi_excel():
    """Đọc thẳng sheet BT2026 (không dùng code của hs_export) rồi so từng mã 8 số chương 1–97 với cây:
    chữ tiếng Việt, tiếng Anh, cấp gạch ngang, nút cha và 4 cột thuế chính. Mất ~1 phút vì file Excel lớn."""
    import re
    import openpyxl

    norm = lambda v: re.sub(r"\s+", " ", str("" if v is None else v)).strip()
    num = lambda v: norm(int(v) if isinstance(v, float) and v.is_integer() else v)

    def split(desc):  # "- - Loại khác:" -> (2, "Loại khác")
        m = re.match(r"^((?:-\s*)*)(.*)$", norm(desc))
        return m.group(1).count("-"), m.group(2).rstrip(" :;").strip()

    ws = openpyxl.load_workbook(next(DATA.glob("*.xlsx")), read_only=True, data_only=True)["BT2026"]
    rows = list(ws.iter_rows(values_only=True))
    head = [norm(c) for c in rows[2]]
    tax_cols = {n: head.index(n) for n in ("Đơn vị tính", "NK TT", "NK ưu đãi", "VAT")}
    C_TYPE, C_CODE, C_VI, C_EN = 2, 5, 6, 7  # cột C = loại dòng (>= 18 là dòng hàng), F = mã, G/H = mô tả

    sheet, stack = {}, []
    for r in rows[3:]:
        if not isinstance(r[C_TYPE], int) or r[C_TYPE] < 18 or not norm(r[C_VI]):
            continue
        code = re.sub(r"[.\s]", "", norm(r[C_CODE]))
        lvl, text = split(r[C_VI])
        stack = [s for s in stack if s[0] < lvl] if lvl else []
        if len(code) == 8:
            assert code not in sheet, f"mã lặp trong sheet: {code}"
            sheet[code] = {"level": lvl or 1, "text": text, "en": split(r[C_EN])[1],
                           "parent": stack[-1][1] if stack else None,
                           "tax": {n: num(r[i]) for n, i in tax_cols.items()}}
        if lvl:
            stack.append((lvl, text))

    tree = {n["code"]: {"level": n["level"], "text": n["text"], "en": n["en"],
                        "parent": h["nodes"][n["parent"]]["text"] if n["parent"] is not None else None,
                        "tax": {k: n["tax"][k] for k in tax_cols}}
            for h in TREE["headings"] if h["chapter"] != "98"
            for n in h["nodes"] if n["code"] and len(n["code"]) == 8}
    assert sorted(set(sheet) ^ set(tree)) == []
    assert len(sheet) > 11000
    diff = {c: (sheet[c], tree[c]) for c in sheet if sheet[c] != tree[c]}
    assert diff == {}, f"{len(diff)} mã lệch, vd: {list(diff.items())[:3]}"


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_"):
            fn()
            print("OK", name)
