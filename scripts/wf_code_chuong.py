import json


def main(muc_luc: str) -> dict:
    """Mục lục (hs_tree/muc_luc.json) -> câu hỏi Jev chọn chương. Chạy song song với node truy xuất."""
    ml = json.loads(muc_luc)
    crit = {no: f"Chương {no}: {c['name']} (Phần {c['section']}: {c['section_name']})" for no, c in ml["chapters"].items()}
    # không thêm lựa chọn "không chương nào": hàng nào cũng thuộc một chương, lựa chọn đó chỉ tranh xác suất
    q = {"chuong": {"type": "choice",
                    "instructions": "Chọn chương của Biểu thuế XNK Việt Nam chứa hàng hóa được mô tả. Tên Phần, Chương "
                                    "chỉ để tra cứu (Quy tắc 1): xét theo bản chất hàng hóa (vật liệu, công dụng, chức "
                                    "năng; là bộ phận của máy hoặc xe nào) chứ không theo việc trùng chữ.",
                    "criteria": crit}}
    return {"questions": json.dumps(q, ensure_ascii=False)}
