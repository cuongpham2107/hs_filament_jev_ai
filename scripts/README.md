# Scripts xử lý biểu thuế & dữ liệu kiểm thử (`admin/scripts/`)

Thư mục này chứa cấu hình môi trường Python (`uv`) và mã nguồn trong `src/` phục vụ **nạp biểu thuế từ Excel** và **kiểm thử dữ liệu** cho ứng dụng Laravel.

## Cấu trúc

```
admin/scripts/
  pyproject.toml      Cấu hình dự án uv (package = false)
  requirements.txt    Danh sách thư viện Python (openpyxl, pytest)
  README.md           Hướng dẫn sử dụng
  src/
    parse_tariff_excel.py   Đọc file .xlsx trong admin/data/<năm>/ và ghi chú nghiệp vụ, xuất JSON/TXT/CSV trung gian
    tariff_tree.py          Dựng và kiểm tra cấu trúc cây chuẩn (Phần → Chương → Nhóm → Nút → Mã 8 số)
    test_tariff_tree.py     Bộ pytest kiểm tra tính toàn vẹn của cây biểu thuế
    benchmark_cases.py      Danh sách 34 câu hỏi mẫu (CASES) dùng cho php artisan hs:benchmark
```

## Cài đặt môi trường với `uv`

### Cách 1: Chạy trực tiếp bằng `uv run` (khuyên dùng)
`parse_tariff_excel.py` đã khai báo sẵn dependency nội tuyến (PEP 723), chỉ cần chạy:

```bash
cd admin
uv run scripts/src/parse_tariff_excel.py 2026
```

*(Đây cũng là cách job `BuildTariffYear` của Laravel gọi tự động khi bạn upload file Excel mới trong trang admin.)*

### Cách 2: Cài `.venv` tại chỗ bằng `uv`

```bash
cd admin/scripts
uv sync
```
Hoặc dùng `requirements.txt` với `uv pip`:
```bash
cd admin/scripts
uv venv
source .venv/bin/activate
uv pip install -r requirements.txt
```

## Các lệnh thường dùng

- **Phân tích file Excel biểu thuế năm `<năm>`** (đặt đúng 1 file `.xlsx` trong `admin/data/<năm>/`):
  ```bash
  cd admin
  uv run scripts/src/parse_tariff_excel.py 2026
  ```
  *(Có thể truyền biến môi trường `HS_DATA_PATH=/duong/dan/data` nếu thư mục `data` nằm ở vị trí khác.)*

- **Chạy test kiểm tra cây biểu thuế** (chạy ngay sau `parse_tariff_excel.py`, trước khi xoá file trung gian):
  ```bash
  cd admin/scripts
  uv run pytest src/test_tariff_tree.py
  ```

- **Chạy benchmark 34 câu mẫu trên Laravel (`HsLookup`)**:
  ```bash
  python3 -c "import json,sys; sys.path.insert(0,'admin/scripts/src'); from benchmark_cases import CASES; print(json.dumps(CASES, ensure_ascii=False))" > /tmp/cases.json
  php admin/artisan hs:benchmark /tmp/cases.json
  ```
