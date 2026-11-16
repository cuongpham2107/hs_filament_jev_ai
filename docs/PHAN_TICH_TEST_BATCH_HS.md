# Phân tích kết quả test hàng loạt & debug log Jev (16/11/2026)

Tài liệu này tổng hợp kết quả chạy thử nghiệm bộ 125 dòng hàng hóa M&E / xây dựng qua API `POST /api/hs/chat` và phân tích nguyên nhân từ dữ liệu debug của Jev (`debug=true` + Laravel debug log).

---

## 1. Công cụ & cấu hình đã bổ sung

- **Script chạy batch**: `scripts/test_hs_batch.py`
  - Chứa 125 dòng đầu vào (114 dòng duy nhất khi dùng `--unique`).
  - Hỗ trợ `--debug`, `--from-results`, `--only-status`, `--start`, `--limit`, tự đọc `APP_URL` và `APP_KEY` trong `.env`, tự retry khi gặp HTTP `429`.
- **API debug payload (`debug=true`)**: `app/Http/Controllers/Api/ChatController.php`, `app/Ai/HsLookup/HsLookup.php`, `app/Ai/HsLookup/LookupResult.php`
  - Khi truyền `"debug": true`, API trả thêm object `debug` gồm: `chapters`, `headings_step2`, `picked_headings`, `skipped_headings`, `headings_step4`, `co_nhom_phu_hop`, `cac_buoc`, `can_lam_ro`, `top_codes` (top 10 mã 8 số kèm xác suất & path), `step_probs` (xác suất từng lựa chọn tại các nút rẽ nhánh).
- **Laravel Debug Logs**: `config/hs.php`, `config/logging.php`, `app/Ai/HsLookup/JevClient.php`, `app/Ai/HsLookup/HsLookup.php`
  - Bật/tắt qua `HS_JEV_DEBUG_LOG` (mặc định theo `APP_DEBUG`).
  - Mặc định ghi vào log chung (`storage/logs/laravel.log`); đặt `HS_JEV_LOG_CHANNEL=jev` trong `.env` để tách ra `storage/logs/jev.log`.
  - Gắn `traceId` (8 ký tự đầu của `conversation_id`) và log theo từng bước:
    - `[Jev] API call` (`duration_ms`, `attempts`, `provider`, `model`, `state_bytes`, `question_count`, `question_keys`)
    - `[Jev][{traceId}] Bắt đầu tra cứu`
    - `[Jev][{traceId}] Bước 1 - Chọn chương`
    - `[Jev][{traceId}] Bước 2 - Chọn nhóm 4 số`
    - `[Jev][{traceId}] Bước 4 - Chốt nhóm & đi cây`
    - `[Jev][{traceId}] Bước 5 - Kết luận`

---

## 2. Kết quả các lần chạy thực tế

### Lần 1: Chạy 114 dòng duy nhất (`--unique`)
- **Tổng thời gian**: `325.9s` (đã gồm nghỉ `1.05s`/request). Thời gian API trung bình: `1.76s` (`min: 1.10s`, `max: 6.64s`).
- **Kết quả**:
  - `ok`: **26 / 114 (22.8%)**
  - `can_bo_sung`: **87 / 114 (76.3%)**
  - `error`: **1 / 114 (0.9%)** — dòng `#32` chỉ có chuỗi `#&VN` (HTTP `502`).
- **Phân tách theo chất lượng mô tả**:
  - **13 dòng bị cắt mất tên hàng ở đầu** (vd `#3 (loại ống không nối)`, `#10 tự dính, dạng miếng...`, `#32 #&VN`, `#33 dùng trong xây dựng...`, `#57 bằng thép, đường kính 16 mm...`, `#74 dùng làm giá đỡ...`, `#87 dày 0.3mm...`, `#109 (không nối)`): `12 can_bo_sung`, `1 error`.
  - **101 dòng đầy đủ câu chữ**: `26 ok (25.7%)`, `75 can_bo_sung`.
- **Vị trí rớt ngưỡng ở 75 dòng đầy đủ bị `can_bo_sung`**:
  - `step0_nhom < 70%` (chốt nhóm 4 số): **22 dòng**
  - `step1_cay < 70%` (nhánh cấp 1): **23 dòng**
  - `step2_cay < 70%` (nhánh cấp 2): **19 dòng**
  - `step3/4_cay < 70%` (nhánh cấp 3–4): **7 dòng**
  - Mọi bước `>= 70%` nhưng `joint < 55%` hoặc `margin < 20%`: **4 dòng**
- **Các nhóm đã chốt `ok` chuẩn xác**:
  - `7307.93.10` / `7307.93.90`: cút/tê hàn giáp mối tách chuẩn ngưỡng đường kính trong `< 15cm` vs `>= 15cm` (`#37–41`, `#46–49`).
  - `7306.30.91` / `7306.30.99`: ống thép mạ kẽm hàn tách chuẩn ngưỡng `OD < 140mm` vs `>= 140mm` (`#83–86`, `#119–122`).
  - `7306.40.90`: ống inox 304 hàn (`#123, #124`).
  - `7318.15.90` (bu lông 20mm `#58, #107`), `8531.10.30` (đầu báo khói `#80`), `3214.10.00` (keo chống cháy `#94`), `8536.90.19` (đầu cốt 16mm2 `#100`), `7308.90.99` (cột/khung kết cấu thép `#105, #113`).

### Lần 2: Chạy lại 88 dòng chưa chốt (`can_bo_sung` + `error`) với `--debug`
- **Tổng thời gian**: `242.9s`.
- **Kết quả**: `can_bo_sung = 86`, `ok = 2` (`#45: 7307.19.00` đạt `70.8%`; `#125: 7306.40.20` đạt `67.7%`), `error = 0` (`#32 #&VN` trả `can_bo_sung 0.8%`).
- **Thống kê từ `jev_debug`**:
  - `co_nhom_phu_hop >= 0.30` ở **87 / 88** dòng (chỉ `#109 (không nối)` đạt `0.18`). Jev nhận diện rất tốt tập nhóm ứng viên.
  - `CHUA_DU_THONG_TIN` đứng `#1` tại ít nhất 1 nút cây ở **78 / 88** dòng.

### Lần 3: Chạy 10 dòng đầu (`#1–#10`) sau khi bật Laravel debug logs
- **Tổng thời gian**: `23.7s`, kết quả: `10/10 can_bo_sung`.
- **Đối chiếu chi tiết từ log**:

| # | Mặt hàng (tóm tắt) | Bước 2 → Bước 4 (nhóm) | `noul` | Kết luận & top code | Nút nghẽn chính trong log |
|---|---|---|---|---|---|
| 1 | Hàng rào thép tạm thời, có ống khung tháo rời | `7314(48), 7308(40)` → `7314(70), 7308(29)` | `0.88` | `7308.90.99` (`14.5%`) vs `7314.20.00` (`10.5%`) | Vào `7314` (`70%`) nhưng `7314_r` bị `CHUA_DU_THONG_TIN = 64%` (thiếu cỡ dây/mắt lưới); còn `7308` chỉ được `29%` |
| 2 | Hệ thống khung treo ống gió thép hộp mạ kẽm | `8302(45), 7308(31)` → `7308(58), 8302(32)` | `0.83` | `7308.90.99` (`36.7%`) vs `8302.41.90` (`12.1%`) | Cây `7308` đạt `79%›91%›88%`, nhưng chốt nhóm `7308` chỉ `58%` (tranh chấp `8302`) |
| 3 | `(loại ống không nối). Mới 100%#&VN` | `7304(88)` → `7304(84), 3917(16)` | `0.66` | `7304.90.90` (`0.1%`) | Dữ liệu mất tên hàng ở đầu → `7304_r` `CHUA_DU_THONG_TIN = 98%` |
| 4 | Ke góc vuông 4 lỗ thép mạ kẽm `30*30*3T` | `7308(33), 8302(30)` → `7308(45), 8302(34), 7326(18)` | `0.83` | `7308.90.99` (`33.5%`) vs `8302.41.90` (`21.3%`) | Cây `7308` đạt `88%›96%›88%`, rớt ở bước chốt nhóm `7308` (`45%`) |
| 5 | Ke góc vuông 4 lỗ thép mạ kẽm `130*90*9T` | `8302(31), 7308(29)` → `7308(49), 8302(28), 7326(18)` | `0.84` | `7308.90.99` (`31.7%`) vs `8302.41.90` (`14.1%`) | Cây `7308` đạt `81%›95%›84%`, rớt ở bước chốt nhóm `7308` (`49%`) |
| 6 | Đèn pha LED 200W `CP01-200W/G03`, 220V 50Hz | `9405(94)` → `9405(95)` | `0.84` | `9405.42.10` (`19.6%`) vs `9405.42.90` (`15.7%`) | `9405_22`: `9405.42` (`58%`) vs `CHUA_DU_THONG_TIN` (`27%`); `9405_29`: `Đèn pha` (`40%`) vs `Loại khác` (`32%`) |
| 7 | Đèn pha LED 200W `ORTC-200`, 220V 50Hz | `9405(93)` → `9405(95)` | `0.83` | `9405.42.10` (`20.8%`) vs `9405.42.90` (`13.9%`) | `9405_22`: `9405.42` (`56%`) vs `CHUA_DU_THONG_TIN` (`26%`); `9405_29`: `Đèn pha` (`45%`) vs `Loại khác` (`30%`) |
| 8 | Đèn pha LED 400W `DPL02-400`, 220V 50Hz | `9405(92)` → `9405(96)` | `0.83` | `9405.42.90` (`15.7%`) vs `9405.42.10` (`14.5%`) | `9405_22`: `9405.42` (`47%`) vs `CHUA_DU_THONG_TIN` (`37%`); `9405_29`: `Loại khác` (`39%`) vs `Đèn pha` (`36%`) |
| 9 | Tủ điện phân phối MAIN 250A, 220V, có aptomat | `8537(92)` → `8537(98)` | `0.94` | `8537.10.19` (`60.5%`) vs `8537.10.99` (`27.1%`) | Mọi bước khác `93%–99%`, chỉ vướng `8537_0`: `Bảng chuyển mạch và điều khiển` (`67%`) vs `Loại khác` (`30%`) |
| 10 | `tự dính, dạng miếng, đã in thông tin, 2.5x7.5cm` | `4821(88)` → `4821(68), 9619(22)` | `0.71` | `4821.10.90` (`16.0%`) | Dữ liệu mất tên hàng/chất liệu → `4821` (`68%`), `4821_0` `CHUA_DU_THONG_TIN = 74%` |

---

## 3. Đánh giá các nguyên nhân gốc

1. **Chưa có ghi chú nghiệp vụ (`data/ghi_chu_nghiep_vu.md`) cho nhóm M&E / xây dựng**:
   - Hiện `data/ghi_chu_nghiep_vu.md` mới chỉ có 9 mục (`2710, 4202, 4802, 6404, 8428, 8501, 8703, 8712, 9503`), chưa phủ các nhóm trọng tâm trong bộ test (`7307, 7308, 8302, 7314, 7318, 7326, 8481, 8536, 8537, 8544, 8547, 9405, 6809`).
   - Do thiếu hướng dẫn ranh giới giữa các nhóm, Jev hay bị chia xác suất ở bước chọn nhóm (vd `7308` vs `8302` vs `7326`; `7314` vs `7308`; `8547` vs `7304/7306`) hoặc nghi ngờ nhầm *"gang đúc không dẻo"* ở `73.07` dù mô tả đã ghi *"thép mạ kẽm, dạng đúc"*.

2. **`CHUA_DU_THONG_TIN` hút xác suất mạnh tại các nút `"Loại khác | KHÔNG PHẢI: ..."`**:
   - Vì hướng dẫn yêu cầu chỉ chọn `"Loại khác | KHÔNG PHẢI: A, B"` khi mô tả cho thấy rõ hàng không thuộc `A, B`, Jev thường tỏ ra quá dè dặt ngay cả khi mô tả đã loại trừ gián tiếp:
     - `9405_22`: ghi `220V, 50Hz` nhưng vẫn cho `CHUA_DU_THONG_TIN` `26%–37%` vì nhánh anh em `9405.41` là `Loại quang điện`.
     - `8536_0` (`#26–29` đầu cốt đồng): `CHUA_DU_THONG_TIN` lấy `31%` trước nhánh `Cầu chì nhiệt`.
     - `7307_0` (`#42–45, #51–54, #71–73`): `CHUA_DU_THONG_TIN` lấy `30%–43%`.
     - `8481_r` (`#55` van bi đồng): `CHUA_DU_THONG_TIN` lấy `27%`.

3. **Ngưỡng `ok` hiện tại (`SURE = 0.70`, `MIN_CONF = 0.55`, `MIN_MARGIN = 0.20`) rất nhạy với một nút ranh giới**:
   - Chỉ cần 1 nút đạt `64%–69%` (như `#9` tủ điện `8537_0 = 67%`, `#55` van bi `8481_r = 68%`, `#26` đầu cốt `8536_0 = 67%`), toàn bộ kết quả chuyển thành `can_bo_sung` dù xác suất nhóm `92%–100%` và mã top 1 đúng hướng.

4. **Chất lượng text tờ khai đầu vào chưa được tiền xử lý**:
   - `13` dòng bị cắt mất tên hàng ở đầu (không thể phân loại chính xác nếu không khôi phục từ file gốc).
   - Viết tắt `"k có đầu nối"` / `"k đầu nối"` ở `#20–23` làm Jev hiểu ngược nghĩa thành *"Đã lắp với đầu nối điện"* (`8544.42`, `83%–97%`).
   - Chuỗi `0.6/1(1.2)kV` ở `#24` bị nhầm sang điện áp `> 1000V` (`8544.60`).
   - Các chuỗi nhiễu `#&VN`, `#&KR`, `(SLTT: ..., ĐGTT: ...)` và dòng rỗng `#&VN` (`#32`).

---

## 4. Hướng làm tiếp theo trên local

1. **Bổ sung `data/ghi_chu_nghiep_vu.md`** cho các nhóm M&E hay gặp (`7307`, `7308`, `8302`, `7314`, `7318`, `8481`, `8536`, `8537`, `8544`, `8547`, `9405`), sau đó nạp lại (`php artisan hs:import 2026`) và chạy lại `--start 1 --limit 10` rồi `--unique` để đo mức tăng tỷ lệ `ok`.
2. **Tiền xử lý mô tả tờ khai trước khi gọi Jev**: loại bỏ `#&XX`, `(SLTT: ..., ĐGTT: ...)`, chuẩn hóa `"k có đầu nối"` / `"k đầu nối"` → `"không có đầu nối"`, từ chối sớm chuỗi rỗng sau làm sạch (`422`).
3. **Tinh chỉnh câu hỏi / `CHUA_DU_THONG_TIN` trong `LookupQuestions.php`** và kiểm tra trên cả bộ chuẩn 34 câu (`php artisan hs:benchmark`) lẫn bộ 114 câu M&E để giữ mục tiêu `0` câu `SAI TỰ TIN`.
