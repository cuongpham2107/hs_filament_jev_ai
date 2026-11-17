# Quy tắc phân loại HS — dữ liệu và workflow

Tài liệu này ghi **mọi quy tắc, ngưỡng và quy ước dữ liệu** hệ thống đang dùng, kèm lý do.
Khi sửa một con số hoặc một quy tắc, cập nhật tài liệu này và chạy lại bộ test 2 lượt
(`scripts/test_song_song.py`; Jev dao động giữa các lượt nên một lượt không đủ để so phiên bản).

## 0. Mục tiêu

"Chính xác tuyệt đối" ở đây nghĩa là: **đã trả mã (`trang_thai = ok`) thì mã phải đúng**.
Chưa đủ căn cứ thì trả `can_bo_sung` kèm điều cần làm rõ, không đoán.
Mọi quy tắc dưới đây ưu tiên **không trả sai** hơn **trả được nhiều**.

---

## 1. Dữ liệu (`scripts/hs_export.py`, `scripts/hs_model.py`, `admin/`)

### 1.1 Luồng dữ liệu
```
data/<năm>/<biểu thuế>.xlsx  (đúng 1 file; sheet BT2026 + 6QT)
  -> hs_export.py: hs_tree.json, hs_tree/XXXX.json, hs_tree/tax/XXXXXXXX.json, hs_tree/muc_luc.json,
                   hs_rules.txt, hs_tax.csv, hs_skipped.json
  -> Laravel TariffImporter: bảng tariff_years, hs_rules, hs_sections, hs_chapters, hs_headings, hs_nodes
  -> API /api/hs/* trả lại đúng định dạng các file trên (Dify đọc)
```
File sinh ra chỉ là bước trung gian: job `BuildTariffYear` xoá chúng sau khi nạp xong (giữ Excel và `cu/`).

### 1.2 Cây chuẩn (`hs_model.py`)
Mọi thứ sinh từ cây chuẩn, không tự phân tích lại chuỗi. Cây giữ đúng cấu trúc biểu thuế:
- Mỗi nhóm 4 số là danh sách nút `{code, level, text, en, note, parent}`, `level` = số gạch ngang, `parent` = nút cấp
  nông hơn gần nhất. Giữ cả nút **không mã** (vd cấp 5 số "- Ngựa:").
- Chú giải Phần / Chương / nhóm + ghi chú nghiệp vụ gắn đúng chỗ.
- **Phân nhóm 6 số của mã 8 số** (`heading_codes`):
  1. Tổ tiên có mã 6 số → phân nhóm đó (bắt buộc trùng 6 số đầu).
  2. Mã `XXXX00xx` → nhóm không chia phân nhóm (vd 9503.00).
  3. Mã `XXXXYY00` không có dòng 6 số → chính dòng 8 số là phân nhóm (vd 0101.21 = 01012100).
  4. Lỗi nguồn: dòng phân nhóm **thiếu mã** (vd 4804.29 "Loại khác") → nút không mã cùng cấp với anh em có mã 6 số.
- **Nhãn "Loại khác"** luôn kèm anh em đứng trước cùng cha: `Loại khác (không phải: A, B)`. Theo Quy tắc 6,
  "Loại khác" chỉ có nghĩa khi biết nó loại trừ gì.
- **Chương 98** (mã ưu đãi riêng, đánh mã không theo phân nhóm 6 số) được đánh dấu `special`; bất thường ở chương 98
  chỉ là cảnh báo. Mục lục (`muc_luc.json`) bỏ chương 98 và chương 77 (trống) nên Jev không chọn chúng.

Biểu 2026: 1.269 nhóm, 11.871 mã, 0 lỗi (48 cảnh báo chương 98).

### 1.3 Tự kiểm tra khi export — có lỗi thì dừng
- Cấp cha < cấp con, mã thuộc đúng nhóm, mỗi mã 8 số xác định được phân nhóm, một phân nhóm chỉ có một tên,
  không trùng mã 8 số, số mã 8 số khớp sheet, sheet 6QT đủ 6 quy tắc.
- `hs_skipped.json`: dòng bị bỏ qua (`bo_qua`: trùng mã, ngoài cấu trúc) và mã bị dời sang dòng con. Biểu 2026: 129 dòng
  chương 98 trùng mã (giữ lần đầu), 1 dòng ngoài cấu trúc, 0 mã bị dời. Soát file này khi đổi năm.
- `test_khop_voi_excel` (`scripts/test_hs_model.py`): đọc thẳng sheet BT2026, không dùng code của `hs_export`, so từng
  mã 8 số chương 1–97 với cây (chữ Việt/Anh, cấp, nút cha, ĐVT, NK TT, NK ưu đãi, VAT).
- Chữ của nút giữ nguyên dấu chấm cuối của nguồn (vd "Tuber spp."); chỉ cắt `:` và `;` cuối dòng.

### 1.4 Không có ghi chú nghiệp vụ theo nhóm
Bảng `hs_business_notes` (+ file `data/ghi_chu_nghiep_vu.md`) đã bị bỏ (11/2026). Lý do:
- Ghi chú chỉ được nạp **sau khi** nhóm đã lọt vào danh sách ứng viên, nên không sửa được lỗi chọn sai nhóm —
  đúng loại lỗi hay gặp nhất.
- Ghi chú không tham gia tính độ tin cậy/kết luận, nên dù Jev "hiểu ra" thì kết quả vẫn `can_bo_sung`.
- Phủ 11/1269 nhóm, giao với bộ test M&E chỉ 3 nhóm; nội dung trộn "ca cụ thể" với "quy tắc biên".

Kiến thức nghiệp vụ (vd thanh RAM đã hàn chip → 8473.30.10) thay bằng: câu hỏi làm rõ sinh từ chính các nhánh của cây
(`LookupQuestions::ask`) và, khi có dữ liệu, bảng thuật ngữ dùng chung (chưa làm).

---

## 2. Workflow Dify (`scripts/update_workflow_qt.py`, `scripts/wf_code_*.py`)

Chỉ dùng Jev, **không embedding, không LLM**: mọi phần người dùng thấy đều do Jev chọn hoặc lấy thẳng từ dữ liệu, nên
không có chỗ nào tự bịa căn cứ hay mã.

| # | Node | Quy tắc | Giá trị | Lý do |
|---|---|---|---|---|
| 2.1 | Jev chọn chương (`LookupQuestions::chapter`) | Một câu choice trên mục lục chương (tên chương + Phần). Không có lựa chọn "không chương nào" | — | Hàng nào cũng thuộc một chương; lựa chọn đó chỉ tranh xác suất. |
| 2.1b | Chọn lại chương (`chapterRetry`) | Nếu xác suất chương đứng đầu **< 0,60** thì hỏi lại **một lần** với chú giải loại trừ ("Chương này không bao gồm…" của chương và Phần) của các chương đang nghi. Chỉ nhận kết quả lượt hai khi nó chắc hơn lượt đầu **≥ 0,15**; lệch ít thì giữ lượt đầu | 0,60 / 0,15 | Lượt đầu chỉ thấy tên chương. Trong 969 lượt ghi log, 188 lượt (19%) có top < 0,60 — phần lớn là hàng M&E thật (73 vs 83 vs 85, 74 vs 85, 48 vs 96 vs 49). Ngưỡng +0,15 để tránh trường hợp chú giải làm Jev *đổi* chương mà không chắc hơn (khẩu trang 63 → 30). |
| 2.2 | Ứng viên nhóm (`wf_code_a.py`) | Mọi nhóm của các chương lấy theo xác suất tới khi cộng dồn ≥ 0,9, tối đa 3 chương, bỏ chương < 0,05; tối đa 250 nhóm | 0,9 / 3 / 0,05 / 250 | Chọn chương bắt được nhóm khi khác chữ nhưng đúng bản chất hàng. Jev nhận ~254 lựa chọn mỗi câu. |
| 2.3 | Chọn 2–5 nhóm (code trong Dify) | Lấy nhóm p ≥ 0,02 theo thứ tự xác suất, dừng khi cộng dồn ≥ 0,95 hoặc đủ 5. Câu chọn nhóm **không có lựa chọn "không nhóm nào"** | 0,02 / 0,95 / 5 | Thà xét thừa nhóm (bước sau còn chốt lại) hơn bỏ sót. Option "không nhóm nào" tranh xác suất với nhóm thật ([companion noul](https://docs.typesafe.ai/patterns/companion-noul)); việc đó do `co_nhom_phu_hop` lo. |
| 2.4 | Câu hỏi chốt nhóm + từng cấp (`wf_code_sub.py`) | **Một lượt Jev** gồm: `nhom` (choice, Quy tắc 1), `co_nhom_phu_hop` (noul), và với mỗi nút có ≥ 2 nhánh con một câu choice giữa các nhánh **cùng cấp** (Quy tắc 6). State là JSON: `mo_ta_hang_hoa`, `sau_quy_tac`, `cach_chon_nhanh`, `cac_nhom_dang_xet`, `chu_giai` | — | Câu hỏi các cấp không phụ thuộc nhau nên hỏi chung một lượt (Jev chạy song song); code tự đi cây. |
| 2.5 | ″ | Lựa chọn của câu `nhom` = tên nhóm + **"GỒM CÁC DÒNG"** (các dòng bên trong nhóm, bỏ "Loại khác", ≤ 1.500 ký tự) | 1.500 | Tên nhóm thường chung chung ("Bộ phận… của xe thuộc 87.11 đến 87.13"). Đo 2×34 câu: 19 → 21–22 đúng (khẩu trang 63.07, nan hoa 87.14, chuột 84.71, kính 70.07). |
| 2.6 | ″ | **Không** kèm dòng con vào từng nhánh trong cây | — | Đã thử: 18–19 đúng (giảm) — từ trùng ở nhánh bên kéo Jev sang (gạo, tôm, máy in, xe tay ga). |
| 2.7 | ″ | Mỗi câu theo cấp có lựa chọn `CHUA_DU_THONG_TIN` | — | Jev không phải đoán khi mô tả thiếu tiêu chí phân nhánh; giữ vì đo được giảm đoán ở "Loại khác". |
| 2.8 | ″ | "Loại khác" ghi `Loại khác \| KHÔNG PHẢI: A, B`; hướng dẫn: chỉ chọn khi mô tả cho thấy rõ hàng không thuộc A, B | — | Quy tắc 6. |
| 2.9 | ″ | Tổng ký tự câu hỏi ≤ 160.000; nhóm làm vượt thì bỏ (nhóm xác suất thấp trước), ghi ra `bo_qua_nhom` | 160.000 | Jev giới hạn 64k token/lượt: 146k ký tự chạy được, 181k bị HTTP 400. Nhóm 8703 một mình ~155k. |
| 2.10 | ″ | Mô tả lựa chọn là chuỗi | — | Plugin Dify `linw1995/typesafe` 0.1.1 không nhận mô tả dạng object. |
| 2.11 | Đọc kết quả (`App\Ai\HsLookup\LookupResult`) | P(mã) = P(nhóm) × tích P(nhánh \| cha) chỉ để **xếp hạng**. **`ok` khi: nút yếu nhất trên đường đi ≥ 0,60, nút yếu nhất cách nhánh mạnh nhất còn lại ≥ 0,20, noul `co_nhom_phu_hop` ≥ 0,7**. Noul trong dải 0,3–0,7 là "phân vân": trả `can_bo_sung` kèm câu hỏi chọn nhóm, không đoán | 0,60 / 0,20 / 0,3–0,7 | Tích 4 tầng 0,85 = 0,52 < 0,55 nên bản cũ chặn oan 8/34 ca có mã top-1 đúng. Ngưỡng noul ba dải theo [routing by probability](https://docs.typesafe.ai/patterns/probability-routing). |
| 2.12 | ″ | `can_lam_ro` = câu hỏi sinh từ **mọi** nút có ≥2 nhánh và xác suất < 0,7 trên đường đi (tối đa 2 câu); câu chữ do `LookupQuestions::ask()` dựng từ phần khác nhau của nhãn các nhánh | — | Câu hỏi lại phải đúng điểm rẽ nhánh và đọc được, không bắt người dùng hiểu nhãn biểu thuế. |
| 2.13 | Node Jev | Thử lại 3 lần, cách 1 giây | 3 / 1.000 ms | Plugin chờ tối đa 20 giây và không tự thử lại khi quá giờ ("TypeSafe timed out"). |
| 2.14 | Thẻ kết quả | Dựng bằng code từ API thuế. **Luôn trả tối đa 4 mã ứng viên** kèm đường dẫn, thuế NK ưu đãi, VAT — chốt được thì thẻ 12 mục (mã đầu tô đậm), chưa chốt thì bảng 4 mã + câu hỏi làm rõ | — | Theo cách thị trường dùng (ECUS…): trả danh sách để người khai tự quyết, không chặn output bằng ngưỡng tin cậy. |
| 2.15 | Chương 98 | Không phải ứng viên phân loại. Mã chương 98 là mã ưu đãi đi kèm mã chương 1–97 khi đủ điều kiện | — | **Chờ nghiệp vụ xác nhận** cách khai và bước tra mã 98 tương ứng. |

### Những thứ bị cấm trong workflow
- Fix cứng theo câu test (vd `if "1.498" in msg`).
- Luật nghiệp vụ trong prompt chung — dùng câu hỏi làm rõ sinh từ chính các nhánh biểu thuế (2.12).
- Cắt danh sách lựa chọn theo thứ tự xuất hiện (phải cắt theo xác suất).

---

## 3. Đánh giá

- Bộ test: `scripts/cases_moi.py` — `CASES` (34 câu: 29 câu đủ thông tin phải ra đúng mã 8 số, 5 câu cố ý thiếu một tiêu
  chí phải hỏi lại) và `CASES_CU` (20 câu đầu tiên). Mã kỳ vọng do Claude tra trên biểu thuế, **chưa được nghiệp vụ xác
  nhận** (vd bộ sạc điện thoại 8504.40.19 hay 8504.40.90).
- Mức chấm: `ĐÚNG` · `HỎI LẠI ĐÚNG` · `HỎI LẠI (mã đầu đúng)` · `HỎI LẠI (mã đầu sai)` · `ĐOÁN` · **`SAI TỰ TIN` — phải
  bằng 0**.
- Hiện tại (15/11/2026, 2 lượt × 34 câu): 21–22 đúng, 5 hỏi lại đúng, 0 sai tự tin, 0 lỗi.

## 4. Lịch sử
| Ngày | Thay đổi | Kết quả |
|---|---|---|
| 11–12/11/2026 | Luồng RAG 2 bước: truy xuất Dataset → Jev chọn nhóm → phân nhóm → mã; LLM viết căn cứ | 18/20 đúng (bộ cũ) |
| 15/11/2026 | Quy tắc 1 + 6: một lượt Jev chốt nhóm + đi cây, `CHUA_DU_THONG_TIN`, mọi bước ≥ 0,7 | 0 sai tự tin trên 53 câu × 3 lượt |
| 15/11/2026 | Bỏ embedding: Jev chọn chương thay truy xuất | hỏi lại nhiều hơn ~6%, không thêm câu sai |
| 15/11/2026 | Dữ liệu vào CSDL Laravel + API; bỏ LLM; thẻ dựng bằng code; retry node Jev | 19/34 |
| 15/11/2026 | State JSON + noul theo tài liệu Jev; "GỒM CÁC DÒNG" ở câu chọn nhóm; biểu đồ ECharts | 21–22/34 |
