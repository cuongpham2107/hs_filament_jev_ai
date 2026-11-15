"""Dựng workflow tra cứu HS (chỉ dùng Jev, dữ liệu từ API Laravel, không LLM) cho MỘT app Dify.

    python3 scripts/update_workflow_qt.py <app_id> [--api=<url>] [--bieu-do]

Luồng: Jev chọn chương -> Jev chọn 2–5 nhóm -> một lượt Jev chốt nhóm (Quy tắc 1) + đi cây từng cấp (Quy tắc 6)
-> 'Đọc kết quả' trả mã khi mọi bước >= 70% -> thẻ kết quả (thuế, chính sách...) hoặc nhánh 'Cần bổ sung'.
--api=<url>: địa chỉ API Laravel mà Dify gọi (mặc định http://host.docker.internal:8000); script tự đọc 6 quy tắc
             từ cùng API qua localhost.
--bieu-do: biểu đồ ECharts so sánh các mã trong thẻ kết quả, và thẻ định dạng cho nhánh 'Cần bổ sung'.

Đọc bản nháp của app từ Postgres, sửa đồ thị, ghi lại vào bản nháp và bản đang xuất bản của CHÍNH app đó.
Lần chạy đầu lưu đồ thị gốc ra workflow/backup_<app_id>.json; phần trình bày (GET thuế, Ghép kết quả, Định dạng thẻ)
được lấy lại từ bản gốc này mỗi lần chạy. Chạy lại nhiều lần được. Đóng hoặc tải lại tab editor của app sau khi chạy,
nếu không tab cũ sẽ tự lưu đè bản nháp.
"""
import json
import subprocess
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PSQL = ["docker", "exec", "-i", "docker-db_postgres-1", "psql", "-U", "postgres", "-d", "dify", "-t", "-A",
        "-v", "ON_ERROR_STOP=1"]


def psql(sql: str) -> str:
    return subprocess.run([*PSQL, "-c", sql], capture_output=True, text=True, check=True).stdout.strip()


def patch_core(graph: dict, rules: str) -> dict:
    nodes = {n["id"]: n["data"] for n in graph["nodes"]}

    sub = nodes["code_sub"]
    sub["title"] = "Câu hỏi: chốt nhóm + từng cấp cây"
    sub["code"] = (ROOT / "scripts" / "wf_code_sub.py").read_text(encoding="utf-8").replace(
        '"__RULES__"', json.dumps(rules, ensure_ascii=False))
    sub["outputs"] = {k: {"type": t, "children": None} for k, t in
                      (("questions", "string"), ("state", "string"), ("all_paths", "object"), ("so_cau_hoi", "number"),
                       ("bo_qua_nhom", "string"))}

    nodes["eval_sub"]["title"] = "Jev chốt nhóm + đi cây"
    nodes["eval_sub"].setdefault("tool_configurations", {})["state_format"] = {"value": "json", "type": "constant"}

    d = nodes["code_d"]
    d["code"] = (ROOT / "scripts" / "wf_code_d.py").read_text(encoding="utf-8")
    d["variables"] = [{"variable": "answers", "value_selector": ["eval_sub", "answers"], "value_type": "object"},
                      {"variable": "trees", "value_selector": ["iter", "output"], "value_type": "array[string]"}]
    d["outputs"]["cac_buoc"] = {"type": "string", "children": None}
    d["outputs"]["danh_gia"] = {"type": "string", "children": None}  # JSON: lựa chọn Jev chấm ở từng bước

    # bỏ bước dàn phẳng mã 8 số: 'Ứng viên mã 8 số' và 'Jev chọn mã'
    gone = {"code_c", "eval_ma"}
    graph["nodes"] = [n for n in graph["nodes"] if n["id"] not in gone]
    graph["edges"] = [e for e in graph["edges"] if e["source"] not in gone and e["target"] not in gone]
    if not any(e["source"] == "eval_sub" and e["target"] == "code_d" for e in graph["edges"]):
        graph["edges"].append({"id": "eval_sub-source-code_d-target", "source": "eval_sub", "sourceHandle": "source",
                               "target": "code_d", "targetHandle": "target", "type": "custom", "zIndex": 0,
                               "data": {"isInIteration": False, "isInLoop": False,
                                        "sourceType": "tool", "targetType": "code"}})
    return graph


LLM_LINE = "    llm = _parse_llm(text)\n"
NO_LLM = ('    llm = {"mat_hang": message, "mo_ta_khai_bao": message,\n'
          '           "can_cu": "Quy tắc 1 (chọn nhóm) rồi Quy tắc 6 (từng cấp cây), xác suất Jev ở mỗi bước: " + text,\n'
          '           "ma_de_nham": [{"ma": m.group(1), "ly_do": f"Jev chấm {m.group(2)}"}\n'
          '                          for m in re.finditer(r"^(\\d{8}) \\((\\d+%)\\)", de_nham, re.M)]}\n')


def presentation_no_llm(graph: dict, original: dict) -> dict:
    """Thêm lại phần trình bày (GET thuế -> Ghép kết quả -> Định dạng thẻ) lấy từ bản gốc, bỏ LLM 'Viết căn cứ':
    căn cứ = các bước Jev đã chọn (code_d.cac_buoc), mã dễ nhầm = code_d.de_nham. Không gọi LLM nên trả lời nhanh."""
    keep = {"http_tax", "code_e", "template_card"}
    gone = keep | {"llm_cancu"}
    graph["nodes"] = [n for n in graph["nodes"] if n["id"] not in gone]
    graph["nodes"] += [json.loads(json.dumps(n)) for n in original["nodes"] if n["id"] in keep]
    graph["edges"] = [e for e in graph["edges"] if e["source"] not in gone and e["target"] not in gone
                      and not (e["source"] == "ifelse" and e["target"] == "end_ok")]
    graph["edges"] += [
        {**_edge("ifelse", "http_tax", "if-else", "http-request"), "id": "ifelse-true-http_tax-target",
         "sourceHandle": "true"},
        _edge("http_tax", "code_e", "http-request", "code"),
        _edge("code_e", "template_card", "code", "template-transform"),
        _edge("template_card", "end_ok", "template-transform", "end")]
    nodes = {n["id"]: n["data"] for n in graph["nodes"]}
    e = nodes["code_e"]
    if LLM_LINE not in e["code"]:
        sys.exit("code_e của bản gốc không còn dòng gọi _parse_llm, cần xem lại")
    e["title"] = "Ghép kết quả (không LLM)"
    e["code"] = e["code"].replace(LLM_LINE, NO_LLM).replace("def main(text: str,", "def main(de_nham: str, text: str,")
    e["variables"] = [v for v in e["variables"] if v["variable"] not in ("text", "de_nham")] + [
        {"variable": "text", "value_selector": ["code_d", "cac_buoc"], "value_type": "string"},
        {"variable": "de_nham", "value_selector": ["code_d", "de_nham"], "value_type": "string"}]
    card = nodes["template_card"]
    card["template"] = card["template"].replace("<details>", "<details open>")  # bảng thuế FTA mở sẵn
    nodes["end_ok"]["outputs"] = [
        {"variable": "the_ket_qua", "value_selector": ["template_card", "output"], "value_type": "string"}]
    # chỉ xuất thẻ: web app Dify in mọi biến đầu ra, object ket_qua hiện thành khối JSON thô dưới thẻ.
    # Số liệu chi tiết vẫn đọc được ở đầu ra node 'Đọc kết quả' / 'Ghép kết quả' qua API.
    return graph


START, RETRIEVE ="1794374757151", "1794405324671"


def _edge(src: str, dst: str, st: str, tt: str) -> dict:
    return {"id": f"{src}-source-{dst}-target", "source": src, "sourceHandle": "source", "target": dst,
            "targetHandle": "target", "type": "custom", "zIndex": 0,
            "data": {"isInIteration": False, "isInLoop": False, "sourceType": st, "targetType": tt}}


def _node(nid: str, x: float, y: float, data: dict) -> dict:
    return {"id": nid, "type": "custom", "data": data, "position": {"x": x, "y": y},
            "positionAbsolute": {"x": x, "y": y}, "sourcePosition": "right", "targetPosition": "left",
            "width": 242, "height": 90, "zIndex": 0, "selected": False}


def add_chapter_step(graph: dict) -> dict:
    """Thêm bước Jev chọn chương (mục lục từ API); 'Ứng viên nhóm' = các nhóm của 1–3 chương Jev chọn."""
    nodes = {n["id"]: n for n in graph["nodes"]}
    src = lambda f: (ROOT / "scripts" / f).read_text(encoding="utf-8")
    if "http_mucluc" not in nodes:
        http = {k: v for k, v in nodes["http_tree"]["data"].items() if k not in ("iteration_id", "isInIteration")}
        http.update(title="GET mục lục chương", isInIteration=False, isInLoop=False, selected=False)  # url: use_api
        jev = json.loads(json.dumps(nodes["eval_nhom"]["data"]))
        jev.update(title="Jev chọn chương", selected=False)
        jev["tool_parameters"] = {"questions": {"value": "{{#code_chuong.questions#}}", "type": "mixed"},
                                  "state": {"value": "{{#" + START + ".message#}}", "type": "mixed"}}
        code = {"type": "code", "code_language": "python3", "title": "Câu hỏi chọn chương", "selected": False,
                "variables": [{"variable": "muc_luc", "value_selector": ["http_mucluc", "body"], "value_type": "string"}],
                "outputs": {"questions": {"type": "string", "children": None}}}
        graph["nodes"] += [_node("http_mucluc", 80, -260, http), _node("code_chuong", 380, -260, code),
                           _node("eval_chuong", 680, -260, jev)]
        graph["edges"] += [_edge(START, "http_mucluc", "start", "http-request"),
                           _edge("http_mucluc", "code_chuong", "http-request", "code"),
                           _edge("code_chuong", "eval_chuong", "code", "tool"),
                           _edge("eval_chuong", "code_a", "tool", "code")]
        nodes = {n["id"]: n for n in graph["nodes"]}
    nodes["code_chuong"]["data"]["code"] = src("wf_code_chuong.py")
    a = nodes["code_a"]["data"]
    a["code"] = src("wf_code_a.py")
    a["variables"] = [{"variable": "chuong", "value_selector": ["eval_chuong", "answers"], "value_type": "object"},
                      {"variable": "muc_luc", "value_selector": ["http_mucluc", "body"], "value_type": "string"}]
    a["outputs"] = {k: {"type": t, "children": None} for k, t in (("questions", "string"), ("chuong", "string"),
                                                                    ("so_ung_vien", "number"))}
    return graph


def drop_retrieval(graph: dict) -> dict:
    """Bỏ node 'Truy xuất tri thức' (embedding) của workflow gốc nếu còn."""
    graph["nodes"] = [n for n in graph["nodes"] if n["id"] != RETRIEVE]
    graph["edges"] = [e for e in graph["edges"] if RETRIEVE not in (e["source"], e["target"])]
    return graph


DATA_URLS = {  # node HTTP -> URL lấy dữ liệu
    "http_tree": "/api/hs/nhom/{{#iter.item#}}",
    "http_mucluc": "/api/hs/muc-luc",
    "http_tax": "/api/hs/thue/{{#code_d.ma_hs#}}",
}


# Khối ```echarts trong markdown được Dify vẽ thành biểu đồ tương tác rộng hết khung. Ảnh PNG từ node Pie Chart
# (plugin langgenius/chart) bị Dify thu thành ô ~240px và font matplotlib của plugin không có dấu tiếng Việt.
CHART_MD = "{% if chart %}\n**So sánh xác suất các mã (Jev):**\n\n```echarts\n{{ chart }}\n```\n{% endif %}\n\n"
MORE_CARD = """### ⚠️ Cần bổ sung thông tin
Chưa đủ căn cứ để kết luận mã HS cho mô tả:
```text
{{ message }}
```

### 1. Hệ thống đang nghiêng về
{% if ma_hs|length == 8 -%}
<span style="color:#b54708;font-weight:700;font-size:1.25em">{{ ma_hs[:4] }}.{{ ma_hs[4:6] }}.{{ ma_hs[6:] }}</span> `(Độ tin cậy {{ (do_tin_cay * 100) | round(1) }}% — chưa đủ để kết luận)`
{%- else -%}
**Chưa xác định được nhóm phù hợp** trong các nhóm đã xét.
{%- endif %}

### 2. Cần làm rõ
👉 {{ can_lam_ro }}

### 3. Các bước đã phân loại
{{ cac_buoc or "—" }}

### 4. Các mã đang cân nhắc
{% for line in de_nham.split("\\n") if line -%}
- `{{ line.split(" ", 1)[0][:4] }}.{{ line.split(" ", 1)[0][4:6] }}.{{ line.split(" ", 1)[0][6:8] }}` {{ line.split(" ", 1)[1] }}
{% else -%}
- Không có mã nào khác đáng kể.
{% endfor %}
""" + CHART_MD.replace("\n\n", "\n", 1)


def _var(name: str, selector: list, vtype: str = "string") -> dict:
    return {"variable": name, "value_selector": selector, "value_type": vtype}


def add_charts(graph: dict) -> dict:
    """Biểu đồ so sánh xác suất các mã (code_d.chart_echarts) chèn dưới mục 11 của thẻ kết quả; nhánh 'Cần bổ sung'
    có thẻ định dạng riêng kèm cùng biểu đồ. Bỏ node Pie Chart và 'Lấy ảnh biểu đồ' của cách làm cũ nếu còn."""
    old = {n["id"] for n in graph["nodes"] if n["data"].get("tool_name") == "pie_chart"} | {"chart_file"}
    graph["nodes"] = [n for n in graph["nodes"] if n["id"] not in old]
    nodes = {n["id"]: n["data"] for n in graph["nodes"]}
    outs = nodes["code_d"]["outputs"]
    for k in ("chart_data", "chart_labels"):
        outs.pop(k, None)
    outs["chart_echarts"] = {"type": "string", "children": None}
    graph["edges"] = [e for e in graph["edges"] if not old & {e["source"], e["target"]}
                      and not (e["source"] == "ifelse" and e["target"] == "end_more")
                      and "template_more" not in (e["source"], e["target"])]
    if not any(e["source"] == "code_d" and e["target"] == "ifelse" for e in graph["edges"]):
        graph["edges"].append(_edge("code_d", "ifelse", "code", "if-else"))
    chart = _var("chart", ["code_d", "chart_echarts"])

    card = nodes["template_card"]
    if "{{ chart }}" not in card["template"]:
        card["template"] = card["template"].replace("### 12.", CHART_MD + "### 12.", 1)
    card["variables"] = [v for v in card["variables"] if v["variable"] != "chart"] + [
        chart]

    graph["nodes"] = [n for n in graph["nodes"] if n["id"] != "template_more"]
    more = {"type": "template-transform", "title": "Định dạng thẻ cần bổ sung", "selected": False,
            "template": MORE_CARD,
            "variables": [_var("message", [START, "message"]), _var("ma_hs", ["code_d", "ma_hs"]),
                          _var("do_tin_cay", ["code_d", "do_tin_cay"], "number"),
                          _var("can_lam_ro", ["code_d", "can_lam_ro"]), _var("cac_buoc", ["code_d", "cac_buoc"]),
                          _var("de_nham", ["code_d", "de_nham"]), chart]}
    end_ok = next(n for n in graph["nodes"] if n["id"] == "end_ok")
    graph["nodes"].append(_node("template_more", end_ok["position"]["x"] - 300, end_ok["position"]["y"] + 220, more))
    graph["edges"] += [{**_edge("ifelse", "template_more", "if-else", "template-transform"),
                        "id": "ifelse-false-template_more-target", "sourceHandle": "false"},
                       _edge("template_more", "end_more", "template-transform", "end")]
    # chỉ xuất thẻ (tên khác end_ok); các trường trang_thai, ma_hs... vẫn ở đầu ra node 'Đọc kết quả'
    nodes["end_more"]["outputs"] = [_var("the_can_bo_sung", ["template_more", "output"])]
    return graph


JEV_RETRY = {"retry_enabled": True, "max_retries": 3, "retry_interval": 1000}


def retry_jev(graph: dict) -> dict:
    """Plugin TypeSafe chờ tối đa 20 giây và không tự gọi lại khi quá giờ ('TypeSafe timed out'); Dify gọi lại node."""
    for n in graph["nodes"]:
        if n["data"].get("type") == "tool" and n["id"].startswith("eval_"):
            n["data"]["retry_config"] = dict(JEV_RETRY)
    return graph


def use_api(graph: dict, base: str) -> dict:
    """Các node HTTP lấy dữ liệu từ API Laravel (admin/)."""
    for n in graph["nodes"]:
        if n["id"] in DATA_URLS:
            n["data"]["url"] = base.rstrip("/") + DATA_URLS[n["id"]]
    return graph


def main() -> None:
    flags = {a for a in sys.argv if a.startswith("--")}
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) != 1:
        sys.exit(__doc__)
    app_id = args[0]
    if not all(c in "0123456789abcdef-" for c in app_id) or len(app_id) != 36:
        sys.exit("app_id phải là UUID")
    api = next((f.split("=", 1)[1] for f in flags if f.startswith("--api=")), "http://host.docker.internal:8000")
    local = api.replace("host.docker.internal", "localhost").rstrip("/")
    with urllib.request.urlopen(f"{local}/api/hs/quy-tac", timeout=10) as r:
        rules = json.load(r)["quy_tac"].strip()

    name = psql(f"select name from apps where id='{app_id}'")
    raw = psql(f"select graph from workflows where app_id='{app_id}' and version='draft'")
    if not name or not raw:
        sys.exit(f"Không thấy app hoặc bản nháp của app {app_id}")
    backup = ROOT / "workflow" / f"backup_{app_id}.json"
    if not backup.exists():  # giữ bản gốc đầu tiên, không ghi đè khi chạy lại
        backup.write_text(raw, encoding="utf-8")

    graph = patch_core(json.loads(raw), rules)
    graph = presentation_no_llm(graph, json.loads(backup.read_text(encoding="utf-8")))
    graph = drop_retrieval(add_chapter_step(graph))
    graph = retry_jev(use_api(graph, api))
    if "--bieu-do" in flags:
        graph = add_charts(graph)
    tmp = ROOT / "scripts" / "_graph_tmp.json"
    tmp.write_text(json.dumps(graph, ensure_ascii=False), encoding="utf-8")
    try:
        subprocess.run(["docker", "cp", str(tmp), "docker-db_postgres-1:/tmp/graph_qt.json"], check=True)
        out = psql(f"""update workflows set graph = pg_read_file('/tmp/graph_qt.json'), updated_at = now()
                       where app_id = '{app_id}'
                         and (version = 'draft' or id = (select workflow_id from apps where id = '{app_id}'))""")
    finally:
        tmp.unlink(missing_ok=True)
    print(f"App '{name}': {out} | {len(graph['nodes'])} node, {len(graph['edges'])} cạnh | bản gốc: {backup.name}")


if __name__ == "__main__":
    main()
