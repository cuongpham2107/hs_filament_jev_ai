import json
import re

MIN_CONF, MIN_MARGIN = 0.55, 0.20  # ok khi xác suất của mã >= 0.55 và hơn mã đứng nhì >= 0.20
SURE = 0.7  # một bước chọn được coi là chắc khi nhánh đứng đầu >= 0.7; MỌI bước trên đường đi phải chắc mới trả mã
OTHER = "KHONG_PHU_HOP"
NO_FIT = 0.3  # Noul 'có nhóm phù hợp' dưới mức này = không nhóm nào trong danh sách đúng (tài liệu Jev gợi ý 0.3 cho 'không')


def short(label: str) -> str:
    """Bỏ phần '(không phải: …)' khi hiển thị."""
    return re.sub(r" \(không phải: .*\)$", "", label)


def main(answers: dict, trees: list) -> dict:
    """Ghép câu trả lời của Jev thành xác suất từng mã 8 số: P(nhóm) × tích P(nhánh | nút cha) dọc đường đi."""
    p_head = answers["nhom"]["probabilities"]
    codes, steps, docs = {}, {}, {}
    for raw in trees:
        t = json.loads(raw)
        g, nodes = t["code"], t["nodes"]
        docs[g] = t
        kids = {}
        for i, n in enumerate(nodes):
            kids.setdefault(n["parent"], []).append(i)

        def cond(i):  # P(nút i | nút cha); nút không có anh em thì chắc chắn 1
            parent = nodes[i]["parent"]
            if len(kids[parent]) < 2:
                return 1.0
            q = answers.get(f"{g}_{'r' if parent is None else parent}") or {}
            return float((q.get("probabilities") or {}).get(str(i), 0.0))

        for i, n in enumerate(nodes):
            if not (n["code"] and len(n["code"]) == 8):
                continue
            chain, j = [], i
            while j is not None:
                chain.append(j)
                j = nodes[j]["parent"]
            chain = chain[::-1]
            p, trail = float(p_head.get(g, 0.0)), [(f"Nhóm {g[:2]}.{g[2:]}", float(p_head.get(g, 0.0)), None)]
            for k in chain:
                p *= cond(k)
                trail.append((short(nodes[k]["label"]), cond(k), k))
            codes[n["code"]] = p
            steps[n["code"]] = (g, trail)

    ranked = sorted(codes.items(), key=lambda x: -x[1])
    best, joint = ranked[0] if ranked else ("", 0.0)
    margin = joint - (ranked[1][1] if len(ranked) > 1 else 0.0)
    fits = (answers.get("co_nhom_phu_hop") or {}).get("noul")  # Noul đi kèm thay cho lựa chọn KHONG_PHU_HOP
    no_head = (fits < NO_FIT if fits is not None
               else p_head.get(OTHER, 0.0) >= max([v for k, v in p_head.items() if k != OTHER] or [0.0]))
    every_step_sure = all(p >= SURE for _, p, _ in steps.get(best, ("", []))[1])
    ok = bool(best) and not no_head and joint >= MIN_CONF and margin >= MIN_MARGIN and every_step_sure

    # điểm cần làm rõ = bước đầu tiên trên đường đi của mã đứng đầu mà Jev chưa chắc
    g, trail = steps.get(best, ("", []))
    sure_code, lam_ro = "", []
    for name, p, k in trail:
        if p < SURE:
            if k is None:
                lam_ro = [f"Nhóm {c[:2]}.{c[2:]} ({docs[c]['name']})"
                          for c, v in sorted(p_head.items(), key=lambda x: -x[1]) if c in docs and v >= 0.1]
            else:
                nodes = docs[g]["nodes"]
                q = answers.get(f"{g}_{'r' if nodes[k]['parent'] is None else nodes[k]['parent']}") or {}
                top = sorted((q.get("probabilities") or {}).items(), key=lambda x: -x[1])
                lam_ro = [short(nodes[int(i)]["label"]) for i, v in top if v >= 0.1 and i.isdigit()]
                if len(lam_ro) < 2:  # Jev báo thiếu thông tin: liệt kê mọi nhánh ở cấp này để người dùng chọn
                    parent = nodes[k]["parent"]
                    lam_ro = [short(n["label"]) for n in nodes if n["parent"] == parent][:8]
            break
        if k is not None and docs[g]["nodes"][k]["code"]:
            sure_code = docs[g]["nodes"][k]["code"]
    sub = sure_code[:6] if len(sure_code) >= 6 else ""

    paths = {c: f"Nhóm {t['code'][:2]}.{t['code'][2:]} > {v['path']}" for t in docs.values() for c, v in t["codes"].items()}
    p_sub = joint / trail[-1][1] if trail and trail[-1][1] else joint
    return {
        "trang_thai": "ok" if ok else "can_bo_sung",
        "ma_hs": best if not no_head else OTHER,
        "duong_dan": paths.get(best, ""),
        "do_tin_cay": round(joint, 3),
        "tin_cay_phan_nhom": round(p_sub, 3),
        "tin_cay_ma": round(trail[-1][1], 3) if trail else 0.0,
        "cach_biet": round(margin, 3),
        "de_nham": "\n".join(f"{c} ({p:.0%}): {paths.get(c, '')}" for c, p in ranked[1:4]),
        "phan_nhom": f"{sub[:4]}.{sub[4:]}" if sub else "",
        "ten_phan_nhom": (docs[g]["subheadings"].get(sub, "") if sub else ""),
        "can_lam_ro": ("Hàng hóa thuộc trường hợp nào: " + "; ".join(lam_ro)) if len(lam_ro) > 1
                      else "Mô tả thêm chất liệu, công dụng, cấu tạo, quy cách của hàng hóa",
        "cac_buoc": " › ".join(f"{name} ({p:.0%})" for name, p, _ in trail),
        "danh_gia": json.dumps(evaluation(answers, docs, g, trail), ensure_ascii=False),
        **chart(ranked),
    }


UNKNOWN = "CHUA_DU_THONG_TIN"
MAX_OPTIONS = 6  # mỗi bước hiện tối đa 6 lựa chọn xác suất cao nhất (luôn gồm nhánh được chọn)


def evaluation(answers: dict, docs: dict, g: str, trail: list) -> list:
    """Jev chấm những lựa chọn nào ở từng bước trên đường đi của mã đứng đầu: chọn nhóm 4 số, rồi mỗi cấp cây
    (chỉ các cấp có >= 2 nhánh, tức có câu hỏi). -> [{buoc, lua_chon: [{ten, p, chon}]}]"""
    def options(probs: dict, name, chosen: str) -> list:
        top = sorted(probs.items(), key=lambda x: -x[1])
        keep = [(k, v) for k, v in top[:MAX_OPTIONS] if v >= 0.005 or k == chosen]
        if chosen not in dict(keep) and chosen in probs:
            keep.append((chosen, probs[chosen]))
        return [{"ten": name(k), "p": round(float(v), 3), "chon": k == chosen} for k, v in keep]

    p_head = answers["nhom"]["probabilities"]
    head_name = lambda c: (f"{c[:2]}.{c[2:]}: {docs[c]['name']}" if c in docs
                           else "Không nhóm nào phù hợp" if c == OTHER else c)
    steps = [{"buoc": "Chọn nhóm 4 số (Quy tắc 1)", "lua_chon": options(p_head, head_name, g)}] if g else []
    nodes = docs[g]["nodes"] if g in docs else []
    for _, _, k in trail:
        if k is None:
            continue
        parent = nodes[k]["parent"]
        q = answers.get(f"{g}_{'r' if parent is None else parent}")
        if not q:
            continue  # nút không có anh em: không có câu hỏi
        where = f"nhóm {g[:2]}.{g[2:]}" if parent is None else f"«{short(nodes[parent]['label'])}»"
        # giữ "(không phải: …)" của dòng "Loại khác": thiếu nó thì không biết "Loại khác" loại trừ gì
        name = lambda i: "Chưa đủ thông tin để phân biệt" if i == UNKNOWN else nodes[int(i)]["label"]
        steps.append({"buoc": f"Trong {where} (Quy tắc 6)", "lua_chon": options(q.get("probabilities") or {}, name, str(k))})
    return steps


def chart(ranked: list, top: int = 4) -> dict:
    """Option ECharts (khối ```echarts trong thẻ markdown): mã đứng đầu + 3 mã kế tiếp + phần còn lại.
    Tên từng mã đã có ở mục 'Mã dễ nhầm', biểu đồ chỉ ghi số mã cho gọn."""
    data = [{"name": f"{c[:4]}.{c[4:6]}.{c[6:]}", "value": round(p * 100, 1)} for c, p in ranked[:top] if p >= 0.005]
    rest = round(100 - sum(d["value"] for d in data), 1)
    if rest >= 0.5:
        data.append({"name": "Khác / chưa rõ", "value": rest, "itemStyle": {"color": "#d0d5dd"}})
    option = {"tooltip": {"trigger": "item", "formatter": "{b}: {c}%"},
              "legend": {"bottom": 0, "type": "scroll"},
              "series": [{"type": "pie", "radius": ["35%", "65%"], "center": ["50%", "45%"],
                          "label": {"formatter": "{b}\n{c}%", "fontSize": 13}, "data": data}]}
    return {"chart_echarts": json.dumps(option, ensure_ascii=False)}
