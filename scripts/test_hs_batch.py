#!/usr/bin/env python3
"""Script test hàng loạt tra cứu mã HS qua HTTP API (POST /api/hs/chat).

Đọc mặc định APP_URL và APP_KEY từ file .env ở thư mục gốc dự án.
Kết quả được lưu ra JSON và CSV (UTF-8 BOM để mở trực tiếp bằng Excel).
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

ROOT_DIR = Path(__file__).resolve().parent.parent
DEFAULT_OUT_DIR = ROOT_DIR / "storage" / "app" / "hs_batch_results"

ITEMS: list[str] = [
    "Hàng rào thép tạm thời dùng cho công trường, 0.4T, cao 2m (bao gồm ống khung, có thể tháo rời). Mới 100% (SLTT: 179.448, ĐGTT: 1076000)#&VN",
    "Hệ thống khung treo ống gió bằng thép hộp mạ kẽm, kích thước: 8920*400mm. Mới 100%  (SLTT: 11354.83136, ĐGTT: 45000)#&VN",
    "(loại ống không nối). Mới 100%#&VN",
    "Ke góc vuông 4 lỗ bằng thép mạ kẽm, dùng để treo thép hộp liên kết với khung xà gồ, kích thước: 30*30mm*3T. Mới 100%#&VN",
    "Ke góc vuông 4 lỗ bằng thép mạ kẽm, dùng để treo thép hộp liên kết với khung xà gồ, kích thước: 130*90mm*9T. Mới 100%#&VN",
    "Đèn pha LED chiếu sáng khu vực thi công, model CP01-200W/G03, công suất 200W, điện áp 220V, tần số 50Hz, nsx: Orienrlight. Mới 100%#&VN",
    "Đèn pha LED chiếu sáng khu vực thi công, model ORTC-200, công suất 200W, điện áp 220V, tần số 50Hz, nsx: Orienrlight. Mới 100%#&VN",
    "Đèn pha LED chiếu sáng khu vực thi công, model DPL02-400, công suất 400W, điện áp 220V, tần số 50Hz, nsx: Orienrlight. Mới 100%#&VN",
    "Tủ điện phân phối MAIN 250A cấp nguồn cho các thiết bị, điện áp: 220V, KT: 700*1200*300mm, Có lắp sẵn các aptomat, nsx: Conkaiser (1 chiếc/bộ). Mới 100%#&VN",
    "tự dính, dạng miếng, đã in thông tin, kích thước: 2.5cm x 7.5cm. Mới 100%#&PH",
    "Ống bằng thép mạ kẽm nhúng nóng, để luồn dây điện đi nổi, đường kính trong 22mm, 2 đầu có ren, không có mối hàn, không nối. Mới 100%#&MY",
    "Đai treo ống thép 16-22C, chất liệu thép mạ kẽm nhúng nóng, đường kính 20mm-25mm. Mới 100%#&VN",
    "Thanh ren kèm đai ốc bằng thép mã kẽm nhúng nóng, dùng để liên kết hệ thống giá đỡ, KT: 3/8inchx3000mm. Mới 100%#&VN",
    "Bu lông neo thanh ren, giá đỡ và phụ kiện vào kết cấu bê tông hoặc tường, chất liệu: thép, có ren, đường kính ngoài của thân : 3/8inch. Mới 100%#&VN",
    "Ống luồn dây điện bằng thép mềm, có bọc nhựa chống thấm đường kính trong: 22mm. Mới 100%#&VN",
    "Đầu nối bằng thép dùng cho ống luồn dây điện mềm, đường kính trong: 22mm, có ren. Mới 100%#&VN",
    "bằng thép mạ kẽm nhúng nóng, dùng cho thiết bị điện điện áp 600V, KT: 102mm x 102mm x 54mm. Mới 100%#&VN",
    "Hộp kéo cáp bằng thép mạ kẽm nhúng nóng, dùng cho hệ thống điện 600V, kích thước 200x200x150mm*1.6T. Mới 100%#&VN",
    "Cáp tín hiệu 16AWG, 1Pr, lõi đồng mạ thiếc, lớp cách điện HDPE, lớp bọc chống nhiễu bằng nhôm, vỏ nhựa PVC, 1 sợi xoắn tạo thành cặp, k có đầu nối, 24V, lõi 1.29mm. Mới 100%#&TH",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 1 lõi dẫn đồng, tiết diện sợi cáp 120mm2, cách điện XLPE, vỏ ngoài PVC chống cháy,k có đầu nối.Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 4 lõi dẫn đồng, tiết diện sợi cáp 35mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k đầu nối. Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 3 lõi dẫn đồng, tiết diện sợi cáp 6mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k có đầu nối. Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 3 lõi dẫn đồng, tiết diện sợi cáp 4mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k có đầu nối. Mới 100%#&VN",
    "0.6/1(1.2)kV Cu/F-GV 16mm2, lõi đồng bọc nhựa cách điện, không đầu nối, hàng mới 100%. Mới 100%#&VN",
    "0.6/1(1.2)kV Cu/F-GV 70mm2, lõi đồng bọc nhựa cách điện, không đầu nối, hàng mới 100%. Mới 100%#&VN",
    "Đầu cốt 1 lỗ loại 16mm2 bằng đồng, để nối cáp điện với tủ điện, dùng cho điện áp 75-95A. Mới 100%#&VN",
    "Đầu cốt 1 lỗ loại 35mm2 bằng đồng, dùng để nối cáp điện với tủ điện, dùng cho điện áp 110-135A. Mới 100%#&VN",
    "Đầu cốt 1 lỗ loại 70mm2 bằng đồng, dùng để nối cáp điện với tủ điện, dùng cho điện áp 190-240A. Mới 100%#&VN",
    "Đầu cốt 2 lỗ loại 120mm2 bằng đồng, dùng để nối cáp điện với tủ điện, dùng cho điện áp 315-380A. Mới 100%#&VN",
    "Tấm thạch cao chống cháy chịu nhiệt kèm phụ kiện, dùng bọc bảo vệ dầm,KT: CB CLIP 20*25*30*20*20*70*0.6T,CHANNEL 20*19*30*0.6T, 45*25*0.6T,1m2=8.8 kg.Mới 100%( LTT: 1958.505291,ĐGTT: 622000VND)#&VN",
    "Tấm thạch cao chống cháy chịu nhiệt kèm phụ kiện, dùng bọc bảo vệ dầm; KT:CB CLIP 20*25*30*20*20*70*0.6T, CHANNEL 20*19*30*0.6T, 45*25*0.6T, 1 m2=8.8 kg.Mới 100%(LTT: 3904.22683, ĐGTT: 705000 VND)#&VN",
    "#&VN",
    "dùng trong xây dựng, có cốt thép gia cường, kích thước 600x2400x150mm. Mới 100%#&VN",
    "cấu tạo rỗng, dùng để bảo vệ bề mặt vách panel, kt: 1.22x2.44m. Mới 100% (LTT: 121.81944, ĐGTT: 113000VND)#&VN",
    "Tấm thạch cao chống cháy chịu nhiệt kèm phụ kiện, dùng bọc bảo vệ dầm; KT: CB CLIP 20*25*30*20*20*70*0.6T, CHANNEL 20*19*30*0.6T, 45*25*0.6T, 1 m2=8.8 kg. Mới 100% #&VN",
    "Nút bịt ống bằng thép mạ kẽm, đường kính trong 50(mm), dùng cho đường ống dẫn nước trong nhà xưởng. Mới 100%.#&KR",
    "Cút nối ống 90 độ bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 25(mm). Mới 100%#&KR",
    "Cút nối ống 90 độ bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 50(mm). Mới 100%#&KR",
    "Cút nối ống 90 độ bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 100(mm). Mới 100%#&KR",
    "Cút nối ống 45 độ bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 150(mm). Mới 100%#&KR",
    "Cút nối ống 90 độ bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 150(mm). Mới 100%#&KR",
    "Nối ống bằng thép mạ kẽm, dạng đúc, loại có ren, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 25(mm), dài 100mm. Mới 100%#&KR",
    "Nối ống bằng thép mạ kẽm, dạng đúc, loại có ren, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 50(mm), dài 100mm. Mới 100%#&KR",
    "Nối giảm bằng thép mạ kẽm, loại 1 đầu ren, 1 đầu hàn, dạng đúc, đường kính D25xD15(mm), dài 300mm. Mới 100%#&KR",
    "Nối giảm bằng thép mạ kẽm, loại 1 đầu ren, 1 đầu hàn, dạng đúc, đường kính D25xD20(mm), dài 300mm. Mới 100%#&KR",
    "Tê nối ống bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong: 25x25(mm). Mới 100%.#&KR",
    "Tê nối ống bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong: 50x25(mm). Mới 100%.#&KR",
    "Tê nối ống bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong: 50x50(mm). Mới 100%.#&KR",
    "Tê nối ống bằng thép mạ kẽm, loại hàn giáp mối, không đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong: 150x150(mm). Mới 100%.#&KR",
    "Nút bịt ống bằng thép mạ kẽm, đường kính trong 25(mm), dùng cho đường ống dẫn nước trong nhà xưởng. Mới 100%.#&KR",
    "Mặt bích mù nối ống bằng thép mạ kẽm, dạng đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính 100(mm). Mới 100%.#&KR",
    "Mặt bích mù nối ống bằng thép mạ kẽm, dạng đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính 150(mm). Mới 100%.#&KR",
    "Mặt bích đơn nối ống bằng thép mạ kẽm 10K, dạng đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 100(mm). Mới 100%.#&KR",
    "Mặt bích đơn nối ống bằng thép mạ kẽm 10K, dạng đúc, dùng cho đường ống dẫn nước trong nhà xưởng, đường kính trong 150(mm). Mới 100%#&KR",
    "Van bi đồng tay gạt, đường kính 25mm, dùng để khóa ống nước. Mới 100%#&KR",
    "Van góc chữa cháy bằng thép (dùng cho hệ thống PCCC), đường kính 50(mm). Mới 100%#&KR",
    "bằng thép, đường kính 16 mm. Mới 100%#&KR",
    "Bu lông (kèm đai ốc, vòng đệm), bằng thép, đường kính 20 mm. Mới 100%#&KR",
    "Đai treo ống bằng thép, đường kính 25(mm). Mới 100%#&KR",
    "Đai treo ống bằng thép, đường kính 50(mm). Mới 100%#&KR",
    "Đai treo ống bằng thép, đường kính 100(mm). Mới 100%#&KR",
    "Đai treo ống bằng thép, đường kính 150(mm). Mới 100%#&KR",
    'Bu lông nở chất liệu thép, đường kính 1/2" (12.5mm), chiều dài thân 150mm. Mới 100%#&KR',
    "Đầu phun chữa cháy của hệ thống phòng cháy chữa cháy, loại 57 độ C, đường kính chân ren 20(mm), chất liệu chính: thép. Mới 100%#&KR",
    'Thanh ty ren chất liệu thép, dùng treo hệ thống đường ống trên cao, đường kính 3/8" (9.375mm), chiều dài thân 3000m. Mới 100%.#&KR',
    'Thanh ty ren chất liệu thép, dùng treo hệ thống đường ống trên cao, đường kính 1/2" (12.5mm), chiều dài thân 3000m. Mới 100%.#&KR',
    "Bu lông chữ U chất liệu thép, đường kính 25 mm. Mới 100%#&VN",
    "Bu lông chữ U chất liệu thép, đường kính 50 mm. Mới 100%#&VN",
    "Bu lông chữ U chất liệu thép, đường kính 100 mm. Mới 100%#&VN",
    "Bu lông chữ U chất liệu thép, đường kính 150 mm. Mới 100%#&VN",
    "Phụ kiện Weldolets nối đường ống chính và các nhánh, bằng thép mạ kẽm, dạng đúc, dùng cho đường ống dẫn nước gas trong nhà xưởng, đường kính D50xD25(mm). Mới 100%#&KR",
    "Phụ kiện Weldolets nối đường ống chính và các nhánh, bằng thép mạ kẽm, dạng đúc, dùng cho đường ống dẫn nước gas trong nhà xưởng, đường kính D100xD50(mm). Mới 100%#&KR",
    "Phụ kiện Weldolets nối đường ống chính và các nhánh, bằng thép mạ kẽm, dạng đúc, dùng cho đường ống dẫn nước gas trong nhà xưởng, đường kính D150xD50(mm). Mới 100%#&KR",
    "dùng làm giá đỡ cho hệ thống ống gió. Mới 100%#&VN",
    "dùng làm giá đỡ cho hệ thống ống gió. Mới 100%#&VN",
    "Bản mã thép carbon có đục lỗ, dùng để cố định hệ khung treo trong lắp đặt trần. Mới 100%#&VN",
    "Ống bằng thép mạ kẽm, để luồn dây điện đi nổi, đường kính trong 22mm, 2 đầu không có ren, không có mối hàn, không nối. Mới 100%#&VN",
    "bằng thép mạ kẽm, dùng cho thiết bị điện điện áp 600V, KT: 95x95x54mm. Mới 100%#&VN",
    "Cáp tín hiệu 16AWG, 1Pr, vỏ nhựa LSZH, cách điện silicone chịu nhiệt chống cháy, lớp bọc nhôm chống nhiễu, không đầu nối, ĐA 300/500V, 2 lõi đồng, tiết diện 1.5mm2. Mới 100%#&VN",
    "Đầu báo khói quang sử dụng cho hệ thống báo cháy trong nhà xưởng (k có chức năng phân tích, truyền tín hiệu qua giao diện vô tuyến), model: FSP-951, điện áp 15-32VDC. Mới 100%#&TH",
    "Đế dùng cho đầu báo khói model: B501/B501A, chất liệu: nhựa kỹ thuật, KT: phi 102x18,8 mm, NSX: Notifier (Honeywell). Mới 100%#&TH",
    "phủ màng laminate tự dính, dạng miếng, đã in thông tin, KT: 24mmx33m. Mới 100%#&CN",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 25(mm), đường kính ngoài 34mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 50(mm), đường kính ngoài 60.5mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 100(mm), đường kính ngoài 114.3mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, dùng cho đường ống nước, đường kính trong 150(mm), đường kính ngoài 165.2mm, dài 6m. Mới 100%.#&KR",
    "dày 0.3mm, dạng cuộn, khổ rộng 1500mm, dùng bảo vệ và cố định hàng hóa, sản phẩm. Mới 100%#&VN",
    "Ván ép cốp pha phủ phim, KT: 1220x2440mm dùng trong xây dựng nhà xưởng. Mới 100%#&VN",
    "Hệ thống khung treo ống gió bằng thép hộp mạ kẽm, kích thước: 8920*400mm. Mới 100%#&VN",
    "Hệ thống khung treo lắp đặt hệ trần bằng thép hộp mạ kẽm, kích thước: 54*74,525m. Mới 100%#&VN",
    "dùng trong xây dựng, có cốt thép gia cường, kích thước 600x2400x150mm. Mới 100%#&VN",
    "Tấm thạch cao chống cháy chịu nhiệt kèm phụ kiện, dùng bọc bảo vệ dầm,KT: CB CLIP 20*25*30*20*20*70*0.6T,CHANNEL 20*19*30*0.6T, 45*25*0.6T,1m2=8.8 kg.Mới 100%#&VN",
    "Tấm thạch cao chống cháy chịu nhiệt kèm phụ kiện, dùng bọc bảo vệ cột; KT: CB CLIP 20*25*30*20*20*70*0.6T, CHANNEL 20*19*30*0.6T, 45*25*0.6T, 1 m2=8.8 kg. Mới 100%#&VN",
    "Keo chèn bịt làm kín chống cháy VIT-1F, dùng phủ kín khe hở thạch cao chống cháy với dầm (matít),0.3kg/tuýp(TP: cas 1317-65-3: 10-45%; 25085-34-1: 10-50%). Mới 100%#&VN",
    "(loại ống không nối). Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 4 lõi dẫn đồng, tiết diện sợi cáp 35mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k đầu nối. Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 3 lõi dẫn đồng, tiết diện sợi cáp 6mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k có đầu nối. Mới 100%#&VN",
    "Cáp điện có cách điện dạng đùn dùng cho điện áp danh định 0.6/1(1.2)kV, 3 lõi dẫn đồng, tiết diện sợi cáp 4mm2, cách điện XLPE, vỏ ngoài PVC chống cháy, k có đầu nối. Mới 100%#&VN",
    "0.6/1(1.2)kV Cu/F-GV 16mm2, lõi đồng bọc nhựa cách điện PVC dạng đùn,vỏ bọc ngoài PVC chống cháy, không đầu nối. Mới 100%#&VN",
    "Đầu cốt 1 lỗ loại 16mm2 bằng đồng, để nối cáp điện với tủ điện, dùng cho điện áp 220-380V (75-95A). Mới 100%#&VN",
    "Đèn pha LED chiếu sáng khu vực thi công, model ORTC-200, công suất 200W, điện áp 220V, tần số 50Hz, nsx: Orienrlight. Mới 100%#&VN",
    "tự dính, dạng miếng, đã in thông tin, kích thước: 2.5cm x 7.5cm. Mới 100%#&VN",
    "Tủ điện phân phối MAIN 250A cấp nguồn cho các thiết bị, điện áp: 220V, KT: 700*1200*300mm, Có lắp sẵn các aptomat, nsx: Conkaiser (1 chiếc/bộ). Mới 100%#&VN",
    "Bộ định tâm tạo bởi các thanh thép tròn d8 uốn hình cung được hàn cố định lên cốt thép chính tạo ra khoảng hở đều xung quanh ống vách. Mới 100%#&VN",
    "Cột SC1 dùng làm cột trụ cho kết cấu nhà xưởng, bằng thép cacbon, KT: H-250x250x9x14. Mới 100%#&VN",
    "Thép cacbon dạng tấm, chưa được gia công quá mức cán nóng kt : 300mm*300mm*30T. Mới 100%#&VN",
    "Bu lông chịu lực cao dùng liên kết các kết cấu, chất liệu: thép carbon, đường kính ngoài thân 20mm, có ren. Mới 100%#&VN",
    "Bu lông neo thanh ren, giá đỡ và phụ kiện vào kết cấu bê tông hoặc tường, chất liệu: thép, có ren, đường kính ngoài của thân: 3/8inch. Mới 100%#&VN",
    "(không nối). Mới 100%#&VN",
    "(không nối). Mới 100%#&VN",
    "Ván ép cốp pha phủ phim, KT: 1220x2440mm dùng trong xây dựng nhà xưởng. Mới 100%#&VN",
    "Tấm thạch cao kết hợp phụ kiện dùng làm vách tường 100*100*3.2T, 50*100.2.3T, CARRYING CHANNEL12*38*1.5T, STUD 45*100*0.75T, CH-STUD 35*102*0.75T, RUNNER 40*102*0.75T. Mới 100%#&VN",
    "Khung kết cấu thép dùng làm kết cấu mái (H200*200*8*12, I350*175*7*11, H294*200*8*12, I400*200*8*13, C100*3.2*20*50). Mới 100%#&VN",
    "Tấm panel chất liệu thép mạ kẽm giữa có lớp bông thuỷ tinh dùng làm tường vách ngăn chống cháy, kết hợp các phụ kiện khung thép hộp 100*150*4T, 100*100*4T. Mới 100%#&VN",
    "Khung xương thép mạ kẽm dùng để đỡ tấm trần Main T-Bar 25*38*0.4T, Cross T-Bar 25*25*0.4T, Carrying Channel 12*38*1.15T, Minor Channel 19*10*0.95T, Hanger 20*100*2T. Mới 100%#&VN",
    "Ống thép mạ kẽm dùng làm khung trần (loại thép hộp vuông), không nối, hàm lượng cacbon: 0.15%, NSX: CÔNG TY TNHH ỐNG THÉP 190(KT: 150*100mm*2.3T. Mới 100%#&VN",
    "Thanh viền tạo rãnh ALW Moulding giúp giấu mép tấm trần, bằng nhôm, được sơn tĩnh điện KT 15*15*15*15*0.9T. Mới 100%#&VN",
    "dày 0.3mm, dạng cuộn, khổ rộng 1500mm, dùng bảo vệ và cố định hàng hóa, sản phẩm. Mới 100%#&VN",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 65(mm), đường kính ngoài 76.3mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 80(mm), đường kính ngoài 89.1mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 150(mm), đường kính ngoài 165.2mm, dài 6m. Mới 100%#&KR",
    "Ống thép mạ kẽm (không hợp kim), loại hàn nối, hàm lượng cacbon 0.1 %, dùng cho đường ống nước, đường kính trong 200(mm), đường kính ngoài 216.3mm, dài 6m. Mới 100%#&KR",
    "Ống inox304, C<=0.08%, Cr 18~20%, Ni 8~10.5%, ID 54.5mm, OD 60.5mm, dày 3.0mm, dài 6m, loại hàn, mặt cắt ngang hình tròn, dùng cho đường ống khí. Mới 100%#&KR",
    "Ống inox304, C<=0.08%, Cr 18~20%, Ni 8~10.5%, ID 83.1mm, OD 88.9mm, dày 3.0mm, dài 6m, loại hàn, mặt cắt ngang hình tròn, dùng cho đường ống khí. Mới 100%#&KR",
    "Ống inox304, C<=0.08%, Cr 18~20%, Ni 8~10.5%, ID 107.1mm, OD 114.3mm, dày 3.0mm, dài 6m, loại hàn, mặt cắt ngang hình tròn, dùng cho đường ống khí. Mới 100%#&KR",
]


def load_env(env_path: Path) -> dict[str, str]:
    """Đọc các cặp KEY=VALUE cơ bản từ file .env."""
    env: dict[str, str] = {}
    if not env_path.exists():
        return env
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        env[key] = value
    return env


def call_chat_api(
    endpoint: str,
    api_key: str,
    message: str,
    timeout: int,
    debug: bool = False,
    max_retries: int = 3,
) -> tuple[int, dict]:
    """Gọi POST /api/hs/chat, tự chờ và thử lại nếu gặp HTTP 429 (rate limit)."""
    body_obj: dict[str, object] = {"message": message, "stream": False}
    if debug:
        body_obj["debug"] = True
    payload = json.dumps(body_obj, ensure_ascii=False).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "x-api-key": api_key,
    }

    for attempt in range(max_retries + 1):
        req = urllib.request.Request(endpoint, data=payload, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                body = resp.read().decode("utf-8")
                return resp.status, json.loads(body)
        except urllib.error.HTTPError as err:
            raw_body = err.read().decode("utf-8", errors="replace")
            if err.code == 429 and attempt < max_retries:
                retry_after = err.headers.get("Retry-After")
                wait_seconds = int(retry_after) + 1 if retry_after and retry_after.isdigit() else 15
                print(f"    -> Bị giới hạn tốc độ (429), chờ {wait_seconds}s rồi thử lại...", flush=True)
                time.sleep(wait_seconds)
                continue
            try:
                parsed = json.loads(raw_body)
            except json.JSONDecodeError:
                parsed = {"status": "error", "error": raw_body or str(err)}
            return err.code, parsed
        except Exception as err:  # noqa: BLE001
            if attempt < max_retries:
                time.sleep(2)
                continue
            return 0, {"status": "error", "error": str(err)}

    return 0, {"status": "error", "error": "Quá số lần thử lại."}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Chạy test hàng loạt tra cứu mã HS qua API /api/hs/chat.")
    parser.add_argument("--url", help="Base URL của ứng dụng (mặc định lấy APP_URL trong .env).")
    parser.add_argument("--api-key", help="x-api-key (mặc định lấy APP_KEY trong .env).")
    parser.add_argument("--file", type=Path, help="Đọc danh sách mô tả từ file text (mỗi dòng 1 mặt hàng).")
    parser.add_argument("--from-results", type=Path, help="Đọc danh sách mặt hàng từ file JSON kết quả lần chạy trước.")
    parser.add_argument(
        "--only-status",
        default="",
        help="Khi dùng --from-results, chỉ lọc các dòng có status nằm trong danh sách (vd: can_bo_sung,error).",
    )
    parser.add_argument("--start", type=int, default=1, help="Bắt đầu từ dòng thứ N (1-based, mặc định: 1).")
    parser.add_argument("--limit", type=int, default=0, help="Giới hạn số dòng chạy thử (0 = chạy hết).")
    parser.add_argument("--unique", action="store_true", help="Loại bỏ các dòng trùng lặp hoàn toàn.")
    parser.add_argument("--debug", action="store_true", help="Bật debug=true để lưu toàn bộ mã ứng viên và xác suất Jev trả về.")
    parser.add_argument("--delay", type=float, default=1.05, help="Nghỉ giữa các request (giây, mặc định 1.05s để tránh vượt 60 req/phút).")
    parser.add_argument("--timeout", type=int, default=120, help="Timeout mỗi request (giây, mặc định: 120).")
    parser.add_argument("--out-dir", type=Path, default=DEFAULT_OUT_DIR, help="Thư mục lưu file kết quả JSON & CSV.")
    parser.add_argument("--prefix", default="hs_batch", help="Tiền tố tên file output (mặc định: hs_batch).")
    parser.add_argument("--dry-run", action="store_true", help="Chỉ in danh sách sẽ gửi, không gọi API.")
    return parser.parse_args()


def load_items(
    file_path: Path | None,
    from_results: Path | None,
    only_status: str,
    unique: bool,
    start: int,
    limit: int,
) -> list[tuple[int, str]]:
    if from_results is not None:
        prev_rows = json.loads(from_results.read_text(encoding="utf-8"))
        allowed = {s.strip() for s in only_status.split(",") if s.strip()}
        indexed = [
            (int(r["stt"]), str(r["mo_ta"]))
            for r in prev_rows
            if not allowed or str(r.get("status", "")) in allowed
        ]
    else:
        if file_path is not None:
            raw_lines = [line.strip() for line in file_path.read_text(encoding="utf-8").splitlines() if line.strip()]
        else:
            raw_lines = list(ITEMS)
        indexed = list(enumerate(raw_lines, start=1))

    if unique:
        seen: set[str] = set()
        deduped: list[tuple[int, str]] = []
        for idx, text in indexed:
            if text not in seen:
                seen.add(text)
                deduped.append((idx, text))
        indexed = deduped

    sliced = [pair for pair in indexed if pair[0] >= start]
    if limit > 0:
        sliced = sliced[:limit]
    return sliced


def write_outputs(out_dir: Path, prefix: str, stamp: str, rows: list[dict]) -> tuple[Path, Path]:
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / f"{prefix}_{stamp}.json"
    csv_path = out_dir / f"{prefix}_{stamp}.csv"

    json_path.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")

    fieldnames = [
        "stt",
        "mo_ta",
        "http_status",
        "status",
        "ma_hs",
        "ma_hs_formatted",
        "do_tin_cay",
        "co_nhom_phu_hop",
        "top_codes_jev",
        "headings_jev",
        "chapters_jev",
        "cac_buoc",
        "can_lam_ro",
        "duration_ms",
        "conversation_id",
        "can_cu",
        "error",
        "markdown",
    ]
    with csv_path.open("w", encoding="utf-8-sig", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            dbg = row.get("jev_debug") or {}
            top_codes_str = " | ".join(
                f"{c.get('ma_hs_formatted')} ({c.get('prob', 0) * 100:.1f}%)"
                for c in (dbg.get("top_codes") or [])
            )
            headings_str = " | ".join(
                f"{h.get('code')} ({h.get('prob', 0) * 100:.1f}%)"
                for h in (dbg.get("headings_step4") or dbg.get("headings_step2") or [])
            )
            chapters_str = " | ".join(
                f"C{ch.get('chapter')} ({ch.get('prob', 0) * 100:.1f}%)"
                for ch in (dbg.get("chapters") or [])
            )
            csv_row = {
                "stt": row.get("stt"),
                "mo_ta": row.get("mo_ta"),
                "http_status": row.get("http_status"),
                "status": row.get("status"),
                "ma_hs": row.get("ma_hs"),
                "ma_hs_formatted": row.get("ma_hs_formatted"),
                "do_tin_cay": row.get("do_tin_cay"),
                "co_nhom_phu_hop": dbg.get("co_nhom_phu_hop"),
                "top_codes_jev": top_codes_str,
                "headings_jev": headings_str,
                "chapters_jev": chapters_str,
                "cac_buoc": dbg.get("cac_buoc") or "",
                "can_lam_ro": dbg.get("can_lam_ro") or "",
                "duration_ms": row.get("duration_ms"),
                "conversation_id": row.get("conversation_id"),
                "can_cu": " | ".join(row.get("can_cu") or []),
                "error": row.get("error"),
                "markdown": row.get("markdown"),
            }
            writer.writerow(csv_row)

    return json_path, csv_path


def main() -> int:
    args = parse_args()
    env = load_env(ROOT_DIR / ".env")
    base_url = (args.url or env.get("APP_URL") or "http://127.0.0.1:8017").rstrip("/")
    api_key = args.api_key or env.get("APP_KEY") or ""
    endpoint = f"{base_url}/api/hs/chat"

    items = load_items(args.file, args.from_results, args.only_status, args.unique, args.start, args.limit)
    if not items:
        print("Không có mặt hàng nào để chạy.", file=sys.stderr)
        return 1

    print(f"Endpoint : {endpoint}")
    print(f"Số lượng : {len(items)} dòng" + (" (debug=true)" if args.debug else ""))

    if args.dry_run:
        for idx, msg in items:
            print(f"[{idx:3d}] {msg}")
        return 0

    if not api_key:
        print("Thiếu APP_KEY trong .env (hoặc truyền --api-key).", file=sys.stderr)
        return 1

    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    rows: list[dict] = []
    counts: dict[str, int] = {}
    started_all = time.monotonic()

    for pos, (orig_idx, msg) in enumerate(items, start=1):
        t0 = time.monotonic()
        http_status, data = call_chat_api(endpoint, api_key, msg, timeout=args.timeout, debug=args.debug)
        elapsed_s = time.monotonic() - t0

        status = str(data.get("status") or "error")
        counts[status] = counts.get(status, 0) + 1
        ma_hs = data.get("ma_hs") or ""
        ma_hs_fmt = data.get("ma_hs_formatted") or ""
        conf = data.get("do_tin_cay")
        conf_str = f"{conf * 100:5.1f}%" if isinstance(conf, (int, float)) else "    -"
        dbg = data.get("debug")

        row = {
            "stt": orig_idx,
            "mo_ta": msg,
            "http_status": http_status,
            "status": status,
            "ma_hs": ma_hs,
            "ma_hs_formatted": ma_hs_fmt,
            "do_tin_cay": conf,
            "duration_ms": data.get("duration_ms") or int(elapsed_s * 1000),
            "conversation_id": data.get("conversation_id"),
            "can_cu": data.get("can_cu") or [],
            "error": data.get("error") or data.get("message") if http_status >= 400 or status == "error" else None,
            "jev_debug": dbg,
            "markdown": data.get("markdown") or "",
        }
        rows.append(row)

        # Lưu liên tục sau mỗi dòng để không mất dữ liệu nếu dừng giữa chừng.
        json_path, csv_path = write_outputs(args.out_dir, args.prefix, stamp, rows)

        preview = msg[:65] + ("..." if len(msg) > 65 else "")
        code_display = (ma_hs_fmt or ma_hs or "-").ljust(10)
        print(f"[{pos:3d}/{len(items)} | #{orig_idx:3d}] {status:<11} {code_display} {conf_str} {elapsed_s:5.1f}s | {preview}", flush=True)

        if pos < len(items) and args.delay > 0:
            time.sleep(args.delay)

    total_s = time.monotonic() - started_all
    print("\n--- TỔNG KẾT ---")
    print(f"Tổng thời gian: {total_s:.1f}s | Thống kê: {json.dumps(counts, ensure_ascii=False)}")
    print(f"File JSON     : {json_path}")
    print(f"File CSV      : {csv_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
