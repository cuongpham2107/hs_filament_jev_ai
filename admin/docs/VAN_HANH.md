# Tài liệu kỹ thuật & Vận hành

Tài liệu chi tiết về kiến trúc, cấu trúc thư mục, vận hành server, nạp biểu thuế và kiểm thử của hệ thống Tra cứu mã HS.

- **Dữ liệu**: file Excel biểu thuế mỗi năm → `scripts/src/parse_tariff_excel.py` dựng cây Phần → Chương → Nhóm 4 số → nút → mã 8 số → nạp vào CSDL SQLite và phát qua API.
- **Phân loại**: chạy trực tiếp trong Laravel theo chuẩn Laravel AI SDK (`app/Ai/HsLookup/`), gọi Jev (TypeSafe, `TYPESAFE_API_KEY`) qua `laravel/ai`, không dùng embedding. Jev chọn chương → chọn nhóm → một lượt chốt nhóm (Quy tắc 1) và đi cây từng cấp (Quy tắc 6). Thẻ kết quả (thuế, chính sách, rủi ro, biểu đồ so sánh mã) dựng trực tiếp từ dữ liệu CSDL. Có sẵn `App\Ai\Tools\HsLookupTool` và `App\Ai\Agents\HsRationaleAgent` (bật bằng `HS_LLM_ENABLED=true` để LLM viết mục 5–6).

Quy tắc, ngưỡng và lý do phân loại: [`QUY_TAC_PHAN_LOAI.md`](QUY_TAC_PHAN_LOAI.md).

## Cấu trúc thư mục

```text
admin/
  app/Ai/HsLookup/        Luồng tra cứu HS viết tay (gọi Jev qua laravel/ai, đọc cây/thuế từ CSDL)
  app/Ai/Agents/          HsRationaleAgent (tuỳ chọn LLM viết mục 5–6)
  app/Ai/Tools/           HsLookupTool (Tool chuẩn Laravel AI SDK)
  data/
    ghi_chu_nghiep_vu.md  Ghi chú nghiệp vụ theo nhóm 4 số, dùng chung mọi năm
    <năm>/                Đúng 1 file Excel biểu thuế (file trung gian bị xoá sau khi nạp vào CSDL)
  docs/                   Tài liệu yêu cầu, vận hành & quy tắc phân loại HS
  scripts/                Python pipeline (uv + pytest), xem thêm ../scripts/README.md
    src/
      parse_tariff_excel.py, tariff_tree.py   Excel -> cây chuẩn (hs_tree.json, hs_tree/XXXX.json, hs_tree/tax/, muc_luc.json)
      test_tariff_tree.py                      Test cây chuẩn trên dữ liệu thật (chạy sau parse_tariff_excel.py)
      benchmark_cases.py                       Bộ 34 câu test chuẩn dùng cho php artisan hs:benchmark
```

## Vận hành

### Khởi động server (cổng 8000)

```bash
composer run dev
```

- Đăng nhập admin: `http://localhost:8000/admin` (khung chat tại `/admin/tra-cuu`).
- API: `/api/hs/muc-luc`, `/api/hs/nhom/{4 số}`, `/api/hs/thue/{8 số}`, `/api/hs/quy-tac` (đặt `HS_API_TOKEN` thì gửi kèm `Authorization: Bearer <token>`).

### Nạp năm biểu thuế mới

Trong admin, vào **Năm biểu thuế** → tải file Excel (cần queue worker `php artisan queue:work`), hoặc bỏ file vào `data/<năm>/` rồi chạy:

```bash
php artisan hs:import 2027 --export
```

Job chạy `parse_tariff_excel.py` qua `uv`, nạp vào CSDL rồi xoá file trung gian. Excel cũ của năm đó được chuyển vào `data/<năm>/cu/`.

### Cập nhật ghi chú nghiệp vụ

Sửa `data/ghi_chu_nghiep_vu.md` (mỗi mục `## XXXX` là một nhóm 4 số) rồi dựng lại năm đó.

## Kiểm thử & Benchmark

```bash
php artisan test --compact
```

```bash
python3 -c "import json,sys; sys.path.insert(0,'scripts/src'); from benchmark_cases import CASES; print(json.dumps(CASES, ensure_ascii=False))" > /tmp/cases.json && php artisan hs:benchmark /tmp/cases.json
```

```bash
uv run --project scripts scripts/src/parse_tariff_excel.py 2026 && cd scripts && uv run pytest src/test_tariff_tree.py
```
