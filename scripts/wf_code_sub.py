import json
import re

RULES = "__RULES__"  # update_workflow_qt.py thay bằng 6 quy tắc lấy từ API /api/hs/quy-tac
UNKNOWN = "CHUA_DU_THONG_TIN"  # mỗi cấp cây có thêm lựa chọn này: Jev không phải đoán khi mô tả thiếu tiêu chí phân nhánh
UNKNOWN_TEXT = ("Mô tả hàng hóa không đủ thông tin để phân biệt các nhánh này: các nhánh khác nhau ở một tiêu chí "
                "(kích thước, định lượng, thành phần, công dụng, tình trạng...) mà mô tả không nói tới")
BUDGET = 160_000  # ký tự câu hỏi tối đa mỗi lượt Jev (đo: 146k chạy được, 185k bị HTTP 400 = vượt 64k token)
# Hướng dẫn chung cho mọi câu hỏi theo cấp: đặt MỘT lần trong state thay vì lặp ở từng câu
# (nhóm 8703 có 135 câu hỏi; lặp lại làm yêu cầu quá lớn, Jev trả HTTP 400)
GUIDE = ["Chỉ so sánh các nhánh cùng cấp với nhau (Quy tắc 6).",
         "Nhánh có phần 'KHÔNG PHẢI: ...' (các dòng 'Loại khác') chỉ đúng khi mô tả cho thấy rõ hàng không thuộc các nhánh "
         "được liệt kê trong đó.",
         f"Nếu mô tả thiếu thông tin mà các nhánh dùng để phân biệt, chọn {UNKNOWN} thay vì đoán."]
CONTENTS_CHARS = 1500  # độ dài phần 'GỒM CÁC DÒNG' của mỗi nhóm trong câu chọn nhóm
# Không kèm dòng con vào từng nhánh trong cây: đo 2×34 câu ra 18–19 đúng so với 21–22 (Jev nhầm vì từ trùng ở nhánh bên)
NOT_FOR = re.compile(r"^(.*) \(không phải: (.*)\)$")


def main(trees: list, message: str) -> dict:
    """Cây JSON (API /api/hs/nhom/XXXX) của 2–5 nhóm đã chọn -> bộ câu hỏi cho MỘT lượt gọi Jev:
    - 'nhom' (choice): chốt nhóm 4 số theo nội dung nhóm + chú giải Phần/Chương (Quy tắc 1);
    - 'co_nhom_phu_hop' (noul): có nhóm nào trong danh sách bao trùm hàng không. Tài liệu Jev: Noul đi kèm tốt hơn
      lựa chọn 'không phù hợp' vì lựa chọn đó tranh xác suất với các nhóm thật;
    - '<nhóm>_<nút>' (choice): với mỗi nút có nhánh con, chọn giữa các nhánh con CÙNG CẤP (Quy tắc 6).
    State là JSON có tên trường, câu hỏi trỏ tới `mo_ta_hang_hoa`. Node 'Đọc kết quả' nhân xác suất dọc đường đi."""
    heads, names, notes, paths, questions, skipped = {}, {}, {}, {}, {}, []
    for raw in trees:  # thứ tự nhóm = thứ tự xác suất Jev chọn nhóm, nên khi vượt BUDGET thì bỏ nhóm ít khả năng nhất
        t = json.loads(raw)
        code, g = t["code"], f"Nhóm {t['code'][:2]}.{t['code'][2:]}"
        own = _tree_questions(t, g)
        # tính cả câu hỏi của nhóm này: 8708 rồi thêm 8703 (~142k) từng vượt 180k và Jev trả HTTP 400
        if heads and len(json.dumps({**questions, **own}, ensure_ascii=False)) > BUDGET:
            skipped.append(code)
            continue
        questions.update(own)
        names[code] = f"{g}: {t['name']} ({t['chapter']})"
        # tên nhóm thường chung chung ("Bộ phận và phụ kiện của xe thuộc các nhóm 87.11 đến 87.13"): kèm các dòng
        # bên trong để Jev thấy hàng được nêu đích danh ở nhóm nào (vd 'Nan hoa' nằm trong 87.14 chứ không phải 87.11)
        heads[code] = f"{names[code]} | GỒM CÁC DÒNG: {_contents(t)}"
        for title, body in t["notes"].items():
            notes.setdefault(title, body)
        for c, v in t["codes"].items():
            paths[c] = f"{g} > {v['path']}" + (f" | Ghi chú: {v['note']}" if v["note"] else "")

    questions = {
        "nhom": {"type": "choice",
                 "instructions": "Chọn nhóm 4 số bao trùm đúng hàng hóa ở `mo_ta_hang_hoa`, xác định theo nội dung nhóm "
                                 "và `chu_giai` của Phần, Chương (Quy tắc 1); áp dụng Quy tắc 2 đến 5 khi cần.",
                 "criteria": heads},
        "co_nhom_phu_hop": {"type": "noul",
                            "instructions": "Hàng hóa ở `mo_ta_hang_hoa` có thuộc một trong các nhóm ở "
                                            "`cac_nhom_dang_xet` không (theo nội dung nhóm và `chu_giai`)?"},
        **questions}
    state = {"mo_ta_hang_hoa": message, "sau_quy_tac": RULES, "cach_chon_nhanh": GUIDE,
             "cac_nhom_dang_xet": list(names.values()), "chu_giai": notes}
    return {"questions": json.dumps(questions, ensure_ascii=False), "state": json.dumps(state, ensure_ascii=False),
            "all_paths": paths, "so_cau_hoi": len(questions), "bo_qua_nhom": ", ".join(skipped)}


def _contents(t: dict, limit: int = CONTENTS_CHARS) -> str:
    """Các dòng trong nhóm (bỏ 'Loại khác' vì không nói lên hàng gì), không trùng, cắt ở `limit` ký tự."""
    seen = []
    for n in t["nodes"]:
        label = _short(n["label"]).strip()
        if label and not label.lower().startswith("loại khác") and label not in seen:
            seen.append(label)
    text = "; ".join(seen)
    return text if len(text) <= limit else text[:limit].rsplit("; ", 1)[0] + "; ..."


def _tree_questions(t: dict, g: str) -> dict:
    """Mỗi nút có ≥2 nhánh con -> một câu hỏi chọn giữa các nhánh con cùng cấp (Quy tắc 6)."""
    nodes, kids, out = t["nodes"], {}, {}
    for i, n in enumerate(nodes):
        kids.setdefault(n["parent"], []).append(i)
    for parent, children in kids.items():
        if len(children) < 2:
            continue  # một nhánh duy nhất: không có gì để chọn
        where = g if parent is None else f"{g} > " + " > ".join(_chain(nodes, parent))
        out[f"{t['code']}_{'r' if parent is None else parent}"] = {
            "type": "choice",
            "instructions": f"Giả sử hàng hóa ở `mo_ta_hang_hoa` thuộc «{where}». Chọn nhánh con mô tả đúng hàng hóa.",
            "criteria": {**{str(i): _option(nodes[i]) for i in children}, UNKNOWN: UNKNOWN_TEXT}}
    return out


def _chain(nodes: list, i: int) -> list:
    out = []
    while i is not None:
        out.append(_short(nodes[i]["label"]))
        i = nodes[i]["parent"]
    return out[::-1]


def _short(label: str) -> str:
    m = NOT_FOR.match(label)
    return m.group(1) if m else label


def _option(n: dict) -> str:
    """Tên nhánh, phần 'KHÔNG PHẢI' của dòng 'Loại khác' tách riêng, kèm tiếng Anh và ghi chú.
    Plugin Dify (linw1995/typesafe 0.1.1) chỉ nhận mô tả lựa chọn dạng chuỗi, không nhận object."""
    m = NOT_FOR.match(n["label"])
    parts = [m.group(1) if m else n["label"]]
    if m:
        parts.append(f"KHÔNG PHẢI: {m.group(2)}")
    if n["en"]:
        parts.append(f"EN: {n['en']}")
    if n["note"]:
        parts.append(f"Ghi chú: {n['note']}")
    return " | ".join(parts)
