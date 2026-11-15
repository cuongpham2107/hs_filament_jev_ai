"""Chạy bộ câu test qua API workflow Dify, gọi song song.

    DIFY_KEY=app-xxx python3 scripts/test_song_song.py [moi|cu] [số luồng]

moi = CASES trong scripts/cases_moi.py (mặc định), cu = CASES_CU (bộ 20 câu đầu tiên).
DIFY_BASE_URL mặc định http://127.0.0.1:5001/v1. Kết quả ghi ra test_results_<moi|cu>.json ở gốc dự án (git bỏ qua).
"""
import collections
import json
import os
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

from cases_moi import CASES as NEW
from cases_moi import CASES_CU as OLD

ROOT = Path(__file__).resolve().parent.parent
BASE_URL = os.environ.get("DIFY_BASE_URL", "http://127.0.0.1:5001/v1")


def run(msg: str) -> tuple:
    """Gọi workflow (streaming) -> ({tên node: dữ liệu node_finished}, dữ liệu workflow_finished)."""
    key = os.environ.get("DIFY_KEY") or sys.exit("Cần biến môi trường DIFY_KEY (API key của app Dify)")
    body = json.dumps({"inputs": {"message": msg}, "response_mode": "streaming", "user": "test-suite"}).encode()
    req = urllib.request.Request(f"{BASE_URL}/workflows/run", body,
                                 {"Authorization": f"Bearer {key}", "Content-Type": "application/json"})
    nodes, final = {}, None
    with urllib.request.urlopen(req, timeout=600) as r:
        for raw in r:
            line = raw.decode("utf-8").strip()
            if not line.startswith("data: "):
                continue
            e = json.loads(line[6:])
            d = e.get("data", {})
            if e.get("event") == "node_finished":
                nodes[d.get("title", f"node_{len(nodes)}")] = d
            elif e.get("event") == "workflow_finished":
                final = d
    return nodes, final


def one(q: str) -> dict:
    t0 = time.time()
    try:
        nodes, final = run(q)
    except Exception as e:  # lỗi mạng / timeout: ghi lại, không làm hỏng cả lượt chạy
        return {"loi": str(e)[:300], "giay": round(time.time() - t0, 1)}
    out = lambda n: (nodes.get(n) or {}).get("outputs") or {}
    res = (final or {}).get("outputs") or {}
    kq = res.get("ket_qua") or res
    d = out("Đọc kết quả")
    errs = [f"{n}: {str(x.get('error'))[:200]}" for n, x in nodes.items() if x.get("status") != "succeeded"]
    return {"giay": round(time.time() - t0, 1), "loi": "; ".join(errs) or (final or {}).get("error"),
            "trang_thai": d.get("trang_thai") or kq.get("trang_thai"), "ma": str(d.get("ma_hs") or kq.get("ma_hs") or ""),
            "tin_cay": d.get("do_tin_cay", kq.get("do_tin_cay")), "nhom": out("Chọn 2–5 nhóm").get("nhom"),
            "chuong": out("Ứng viên nhóm").get("chuong"), "so_ung_vien": out("Ứng viên nhóm").get("so_ung_vien"),
            "cac_buoc": d.get("cac_buoc"), "can_lam_ro": d.get("can_lam_ro") or kq.get("can_lam_ro")}


def verdict(r: dict, want: str, kind: str) -> str:
    if r.get("loi"):
        return "LỖI"
    hit = r["ma"].startswith(want)
    if r["trang_thai"] == "ok":
        if kind == "hoi":
            return "ĐOÁN (đúng hướng)" if hit else "SAI TỰ TIN"
        return "ĐÚNG" if hit else "SAI TỰ TIN"
    if kind == "hoi":
        return "HỎI LẠI ĐÚNG" if r["ma"][:4] == want[:4] or hit else "HỎI LẠI (sai hướng)"
    return "HỎI LẠI (mã đầu đúng)" if hit else "HỎI LẠI (mã đầu sai)"


def main() -> None:
    which = sys.argv[1] if len(sys.argv) > 1 else "moi"
    workers = int(sys.argv[2]) if len(sys.argv) > 2 else 7
    cases = NEW if which == "moi" else OLD
    t0 = time.time()
    with ThreadPoolExecutor(max_workers=workers) as ex:
        results = list(ex.map(lambda c: one(c[0]), cases))
    rows = []
    for i, ((q, want, kind), r) in enumerate(zip(cases, results), 1):
        r.update(cau=i, mo_ta=q, ky_vong=want, loai=kind, ket_luan=verdict(r, want, kind))
        rows.append(r)
        print(f"{i:>2} {r['ket_luan']:<22} {r.get('ma', ''):<9} kỳ vọng {want:<9} tin cậy {r.get('tin_cay')} | {q[:55]}")
        if r["ket_luan"] not in ("ĐÚNG", "HỎI LẠI ĐÚNG"):
            print(f"     chương {r.get('chuong')} | {r.get('so_ung_vien')} ứng viên -> nhóm {r.get('nhom')}\n"
                  f"     {r.get('cac_buoc') or ''}\n     {r.get('can_lam_ro') or r.get('loi') or ''}"[:600])
    (ROOT / f"test_results_{which}.json").write_text(json.dumps(rows, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"\n{dict(collections.Counter(r['ket_luan'] for r in rows))} | {time.time() - t0:.0f}s, {workers} luồng")


if __name__ == "__main__":
    main()
