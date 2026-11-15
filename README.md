# Tra cứu mã HS (biểu thuế XNK Việt Nam)

Tra mã HS 8 số và thuế suất từ mô tả hàng hóa. Mục tiêu: **đã trả mã thì mã phải đúng**; chưa đủ căn cứ thì hỏi lại
đúng tiêu chí còn thiếu thay vì đoán.

- **Dữ liệu**: file Excel biểu thuế mỗi năm → `scripts/hs_export.py` dựng cây Phần → Chương → Nhóm 4 số → nút → mã 8 số
  → app Laravel (`admin/`) nạp vào CSDL và phát qua API.
- **Phân loại**: workflow Dify chỉ dùng Jev (TypeSafe), không embedding, không LLM. Jev chọn chương → chọn nhóm →
  một lượt chốt nhóm (Quy tắc 1) và đi cây từng cấp (Quy tắc 6). Thẻ kết quả (thuế, chính sách, rủi ro, biểu đồ so
  sánh mã) dựng bằng code từ dữ liệu.

Quy tắc, ngưỡng và lý do: [`docs/QUY_TAC_PHAN_LOAI.md`](docs/QUY_TAC_PHAN_LOAI.md).

## Cấu trúc

```
admin/                    Laravel 13 + Filament 5 (SQLite): quản lý biểu thuế theo năm, API cho Dify
data/
  ghi_chu_nghiep_vu.md    ghi chú nghiệp vụ theo nhóm 4 số, dùng chung mọi năm
  <năm>/                  đúng 1 file Excel biểu thuế (file sinh ra bị xoá sau khi nạp vào CSDL)
scripts/
  hs_export.py, hs_model.py   Excel -> cây chuẩn (hs_tree.json, hs_tree/XXXX.json, hs_tree/tax/, muc_luc.json)
  test_hs_model.py            test cây chuẩn trên dữ liệu thật (chạy sau hs_export.py)
  update_workflow_qt.py       dựng workflow cho một app Dify (ghi thẳng vào Postgres của Dify)
  wf_code_*.py                code các node Python trong workflow
  test_song_song.py, cases_moi.py   chạy bộ câu test qua API Dify
workflow/backup_<app_id>.json     đồ thị gốc của từng app trước lần sửa đầu (để khôi phục)
dify/                     mã nguồn + docker-compose của Dify (repo riêng, không nằm trong git này)
```

## Vận hành

**Dify** (Docker):
```bash
cd dify/docker && docker compose up -d
```

**Laravel** (`admin/`, cổng 8000; Dify gọi qua `http://host.docker.internal:8000`). `PHP_CLI_SERVER_WORKERS=8` trong
`admin/.env` và cờ `--no-reload` để nhận nhiều request song song từ Dify:
```bash
php admin/artisan serve --host=0.0.0.0 --port=8000 --no-reload
```
Đăng nhập admin: `http://localhost:8000/admin`. API: `/api/hs/muc-luc`, `/api/hs/nhom/{4 số}`, `/api/hs/thue/{8 số}`,
`/api/hs/quy-tac` (đặt `HS_API_TOKEN` thì gửi kèm `Authorization: Bearer <token>`).

**Năm biểu thuế mới**: trong admin, "Năm biểu thuế" → tải file Excel (cần queue worker `php admin/artisan queue:work`),
hoặc bỏ file vào `data/<năm>/` rồi:
```bash
php admin/artisan hs:import 2027 --export
```
Job chạy `hs_export.py`, nạp vào CSDL rồi xoá file trung gian. Excel cũ của năm đó được chuyển vào `data/<năm>/cu/`.

**Ghi chú nghiệp vụ**: sửa `data/ghi_chu_nghiep_vu.md` (mỗi mục `## XXXX` là một nhóm 4 số) rồi dựng lại năm đó.

**Hai bản tra cứu** (khung chat chọn bằng `HS_LOOKUP_ENGINE` trong `admin/.env`):
- `dify` (mặc định): chatflow Dify, dựng bằng `scripts/update_workflow_qt.py` + `scripts/to_chatflow.py`.
- `laravel`: viết tay trong `admin/app/Services/HsLookup/` (cùng câu hỏi, ngưỡng), gọi Jev qua `laravel/ai`
  (`TYPESAFE_API_KEY`), đọc cây và thuế thẳng từ CSDL. So kết quả với bản Dify:
  ```bash
  python3 -c "import json,sys; sys.path.insert(0,'scripts'); from cases_moi import CASES; print(json.dumps(CASES, ensure_ascii=False))" > /tmp/cases.json && php admin/artisan hs:benchmark /tmp/cases.json
  ```
  Sửa câu hỏi / ngưỡng thì sửa ở cả hai nơi (`scripts/wf_code_*.py` và `HsLookup/`) cho tới khi bỏ hẳn một bản.

**Sửa workflow**: sửa `scripts/wf_code_*.py` hoặc `update_workflow_qt.py`, rồi (đóng tab editor của app trước):
```bash
python3 scripts/update_workflow_qt.py <app_id> --bieu-do
```

**Test**:
```bash
DIFY_KEY=app-xxx python3 scripts/test_song_song.py moi 8
```
```bash
cd admin && php artisan test --compact
```
```bash
uv run --with openpyxl python3 scripts/hs_export.py 2026 && uv run --with openpyxl --with pytest pytest scripts/test_hs_model.py
```
