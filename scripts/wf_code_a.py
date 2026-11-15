import json

MAX_OPTS = 250  # Jev nhận tối đa ~254 lựa chọn mỗi câu hỏi
CHAP_MASS, MAX_CHAPS, MIN_P = 0.9, 3, 0.05
OTHER = "KHONG_PHU_HOP"


def main(chuong: dict, muc_luc: str) -> dict:
    """Ứng viên nhóm = mọi nhóm của 1–3 chương Jev chọn (lấy chương theo xác suất đến khi cộng dồn >= 90%)."""
    criteria = {}
    probs = sorted(((c, p) for c, p in chuong["chuong"]["probabilities"].items() if c != OTHER), key=lambda x: -x[1])
    chosen, acc = [], 0.0
    for c, p in probs:
        if p < MIN_P or len(chosen) >= MAX_CHAPS or acc >= CHAP_MASS:
            break
        chosen.append(c)
        acc += p
    heads = json.loads(muc_luc)["headings"]
    for c in chosen:
        for code, name in heads.get(c, []):
            if len(criteria) < MAX_OPTS:
                criteria.setdefault(code, name)

    criteria[OTHER] = "Không nhóm nào trong danh sách bao trùm hàng hóa này"
    q = {"nhom": {"type": "choice",
                  "instructions": "Chọn nhóm 4 số trong Biểu thuế XNK Việt Nam bao trùm đúng hàng hóa được mô tả, "
                                  "theo nội dung nhóm và chú giải (Quy tắc 1). Khi hàng có thể vào nhiều nhóm, nhóm mô "
                                  "tả cụ thể nhất được ưu tiên (Quy tắc 3a).",
                  "criteria": criteria}}
    return {"questions": json.dumps(q, ensure_ascii=False),
            "chuong": ", ".join(f"{c} ({p:.0%})" for c, p in probs[:len(chosen)]),
            "so_ung_vien": len(criteria) - 1}
