"""Chuyển workflow tra cứu HS (app workflow đã dựng bằng update_workflow_qt.py) thành chatflow cho một app
Dify loại advanced-chat, ghi vào bản nháp và bản đang publish của app đích (lần đầu chưa có bản publish thì bấm Publish trong Dify).

    python3 scripts/to_chatflow.py <app_workflow_nguồn> <app_chatflow_đích>

Khác workflow:
- Câu người dùng lấy từ sys.query. Node 'Ghép mô tả' ghép với biến hội thoại mo_ta_truoc khi lượt trước đang
  'Cần bổ sung', nên người dùng chỉ cần trả lời phần còn thiếu (vd "dùng cho nam").
- Node Answer 'Tiến trình' stream chương / nhóm đang xét trước khi có kết quả; thẻ kết quả hoặc thẻ cần bổ sung là
  Answer cuối. Nhánh 'ok' xoá mo_ta_truoc, nhánh 'cần bổ sung' lưu mô tả đã ghép để lượt sau dùng tiếp.
Bản nháp cũ của app đích được lưu ra workflow/backup_<app_đích>.json (lần đầu).
"""
import json
import subprocess
import sys
import uuid

from update_workflow_qt import ROOT, START, _edge, _node, psql

MEMORY = "mo_ta_truoc"
MERGE_CODE = '''def main(query: str, truoc: str) -> dict:
    """Lượt trước 'Cần bổ sung' (truoc khác rỗng) -> ghép mô tả cũ với thông tin người dùng vừa bổ sung."""
    query = (query or "").strip()
    truoc = (truoc or "").strip()
    if truoc:
        return {"message": f"{truoc}\\nThông tin bổ sung: {query}", "la_bo_sung": "có"}
    return {"message": query, "la_bo_sung": "không"}
'''


def _var(name: str, selector: list, vtype: str = "string") -> dict:
    return {"variable": name, "value_selector": selector, "value_type": vtype}


def convert(graph: dict) -> dict:
    # mọi chỗ đọc message của node Bắt đầu chuyển sang đầu ra của 'Ghép mô tả'
    text = json.dumps(graph, ensure_ascii=False)
    text = text.replace(f'["{START}", "message"]', '["ghep_mo_ta", "message"]')
    text = text.replace(f"{{{{#{START}.message#}}}}", "{{#ghep_mo_ta.message#}}")
    graph = json.loads(text)

    nodes = {n["id"]: n for n in graph["nodes"]}
    nodes[START]["data"]["variables"] = []  # chatflow: câu hỏi nằm ở sys.query
    pos = lambda nid, dx=0, dy=0: (nodes[nid]["position"]["x"] + dx, nodes[nid]["position"]["y"] + dy)

    gone = {"end_ok", "end_more"}
    graph["nodes"] = [n for n in graph["nodes"] if n["id"] not in gone]
    graph["edges"] = [e for e in graph["edges"] if not gone & {e["source"], e["target"]}]

    # START -> Ghép mô tả -> (các node trước đây nối từ START)
    for e in graph["edges"]:
        if e["source"] == START:
            e.update(source="ghep_mo_ta", id=f"ghep_mo_ta-source-{e['target']}-target",
                     data={**e["data"], "sourceType": "code"})
    merge = {"type": "code", "code_language": "python3", "title": "Ghép mô tả", "selected": False, "code": MERGE_CODE,
             "variables": [_var("query", ["sys", "query"]), _var("truoc", ["conversation", MEMORY])],
             "outputs": {"message": {"type": "string", "children": None}, "la_bo_sung": {"type": "string", "children": None}}}
    graph["nodes"].append(_node("ghep_mo_ta", *pos(START, 0, 160), merge))
    graph["edges"].append(_edge(START, "ghep_mo_ta", "start", "code"))

    # Chọn 2–5 nhóm -> Tiến trình (Answer, stream ngay) -> Lấy cây từng nhóm
    graph["edges"] = [e for e in graph["edges"] if not (e["source"] == "code_b" and e["target"] == "iter")]
    progress = {"type": "answer", "title": "Tiến trình", "selected": False, "variables": [],
                "answer": "> 🔎 Chương Jev chọn: {{#code_a.chuong#}}\n> Nhóm đưa vào đi cây: {{#code_b.nhom#}}\n\n"}
    graph["nodes"].append(_node("tien_do", *pos("code_b", 0, 160), progress))
    graph["edges"] += [_edge("code_b", "tien_do", "code", "answer"), _edge("tien_do", "iter", "answer", "iteration")]

    # thẻ -> gán biến hội thoại -> Answer
    for tpl, assign_id, answer_id, item in (
            ("template_card", "nho_ok", "answer_ok",
             # Dify: clear chỉ nhận input_type "variable" (constant chỉ cho over-write/set)
             {"variable_selector": ["conversation", MEMORY], "input_type": "variable", "operation": "clear", "value": None}),
            ("template_more", "nho_more", "answer_more",
             {"variable_selector": ["conversation", MEMORY], "input_type": "variable", "operation": "over-write",
              "value": ["ghep_mo_ta", "message"]})):
        x, y = pos(tpl, 300)
        title = "Xoá mô tả đã nhớ" if assign_id == "nho_ok" else "Nhớ mô tả để hỏi tiếp"
        graph["nodes"].append(_node(assign_id, x, y, {"type": "assigner", "version": "2", "title": title,
                                                      "selected": False, "items": [item]}))
        graph["nodes"].append(_node(answer_id, x + 300, y, {"type": "answer", "title": "Trả lời", "selected": False,
                                                            "variables": [], "answer": f"{{{{#{tpl}.output#}}}}"}))
        graph["edges"] += [_edge(tpl, assign_id, "template-transform", "assigner"),
                           _edge(assign_id, answer_id, "assigner", "answer")]
    return graph


CONVERSATION_VARIABLES = {MEMORY: {
    "id": str(uuid.uuid5(uuid.NAMESPACE_URL, f"tra-cuu-hs/{MEMORY}")), "name": MEMORY, "value_type": "string",
    "value": "", "selector": ["conversation", MEMORY],
    "description": "Mô tả hàng của lượt trước khi hệ thống cần hỏi thêm; rỗng khi đã ra mã"}}


def main() -> None:
    if len(sys.argv) != 3 or not all(len(a) == 36 for a in sys.argv[1:]):
        sys.exit(__doc__)
    src, dst = sys.argv[1:]
    if psql(f"select mode from apps where id='{dst}'") != "advanced-chat":
        sys.exit("App đích phải là chatflow (advanced-chat)")
    raw_src = psql(f"select graph from workflows where app_id='{src}' and version='draft'")
    raw_dst = psql(f"select graph from workflows where app_id='{dst}' and version='draft'")
    if not raw_src or not raw_dst:
        sys.exit("Không thấy bản nháp của app nguồn hoặc app đích")
    backup = ROOT / "workflow" / f"backup_{dst}.json"
    if not backup.exists():
        backup.write_text(raw_dst, encoding="utf-8")

    graph = convert(json.loads(raw_src))
    tmp = ROOT / "scripts" / "_graph_tmp.json"
    tmp.write_text(json.dumps(graph, ensure_ascii=False), encoding="utf-8")
    conv = json.dumps(CONVERSATION_VARIABLES, ensure_ascii=False).replace("'", "''")
    try:
        subprocess.run(["docker", "cp", str(tmp), "docker-db_postgres-1:/tmp/graph_qt.json"], check=True)
        out = psql(f"""update workflows set graph = pg_read_file('/tmp/graph_qt.json'),
                           conversation_variables = '{conv}', updated_at = now()
                       where app_id = '{dst}'
                         and (version = 'draft' or id = (select workflow_id from apps where id = '{dst}'))""")
    finally:
        tmp.unlink(missing_ok=True)
    print(f"Chatflow {dst}: {out} (bản nháp + bản publish nếu có) | {len(graph['nodes'])} node, "
          f"{len(graph['edges'])} cạnh | bản cũ: {backup.name}. App chưa publish lần nào thì bấm Publish trong Dify.")


if __name__ == "__main__":
    main()
