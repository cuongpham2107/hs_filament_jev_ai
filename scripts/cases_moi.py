"""Bộ câu test mới, viết theo kiểu dòng "Tên hàng" trên tờ khai. Không nhóm nào có trong data/ghi_chu_nghiep_vu.md,
nên kết quả không nhờ đáp án ghi sẵn.

Mỗi câu: (mô tả, mã kỳ vọng, loại)
  loại "ma":  mô tả đủ thông tin -> phải trả đúng mã 8 số này.
  loại "hoi": mô tả CỐ Ý thiếu một thông tin quyết định mã 8 số -> đúng là hỏi lại; mã kỳ vọng là phần chắc chắn
              (6 số). Nếu hệ thống vẫn trả mã thì là đoán.
Mã kỳ vọng do Claude tra trên cây biểu thuế 2026, CHƯA được người nghiệp vụ xác nhận.
"""

CASES = [
    ("BU LÔNG LỤC GIÁC BẰNG THÉP KHÔNG GỈ, CÓ REN, ĐƯỜNG KÍNH THÂN 10MM, DÀI 50MM (STAINLESS STEEL HEX BOLT M10X50)",
     "73181510", "ma"),
    ("VÒNG BI CẦU 6204ZZ, MỚI 100% (DEEP GROOVE BALL BEARING)", "84821000", "ma"),
    ("SĂM XE MÁY BẰNG CAO SU, MỚI 100%, CỠ 2.75-17 (INNER TUBE FOR MOTORCYCLE)", "40139020", "ma"),
    ("LỐP XE MÁY BẰNG CAO SU LOẠI BƠM HƠI, MỚI 100%, CỠ 80/90-17 (PNEUMATIC TYRE FOR MOTORCYCLE)", "40114000", "ma"),
    ("MÀNG PE DẠNG CUỘN, KHÔNG XỐP, CHƯA IN, CHƯA GIA CỐ, DÀY 0.05MM (POLYETHYLENE FILM ROLL)", "39201090", "ma"),
    ("TÔM THẺ CHÂN TRẮNG NGUYÊN CON ĐÔNG LẠNH, CÒN ĐẦU, CỠ 40/50 (FROZEN WHOLE VANNAMEI SHRIMP)", "03061729", "ma"),
    ("GẠO TRẮNG HẠT DÀI 5% TẤM, ĐÃ XÁT TOÀN BỘ, KHÔNG PHẢI GẠO THƠM, KHÔNG PHẢI GẠO NẾP, KHÔNG PHẢI GẠO ĐỒ "
     "(LONG GRAIN WHITE RICE 5% BROKEN)", "10063099", "ma"),
    ("SỢI FILAMENT POLYESTER DÚN DTY 150D/48F, MÀU TRẮNG THÔ, CHƯA ĐÓNG GÓI BÁN LẺ (POLYESTER TEXTURED YARN)",
     "54023390", "ma"),
    ("PIN SẠC LITHIUM ION 18650 3.7V 2600MAH, DẠNG CELL ĐƠN, KHÔNG PHẢI BỘ PIN (LI-ION RECHARGEABLE CELL)",
     "85076090", "ma"),
    ("BÓNG ĐÈN LED 9W ĐUÔI XOÁY E27, ĐIỆN ÁP 220V (LED BULB E27)", "85395210", "ma"),
    ("QUẠT ĐIỆN ĐỂ BÀN, ĐỘNG CƠ ĐIỆN GẮN LIỀN CÔNG SUẤT 45W (ELECTRIC TABLE FAN)", "84145110", "ma"),
    ("NỒI CƠM ĐIỆN GIA DỤNG 1.8 LÍT, CÔNG SUẤT 700W (ELECTRIC RICE COOKER)", "85166010", "ma"),
    ("KÍNH CƯỜNG LỰC AN TOÀN ĐÃ CẮT ĐÚNG HÌNH DẠNG, DÙNG LÀM KÍNH CỬA XE Ô TÔ CON (TEMPERED SAFETY GLASS FOR CAR DOOR)",
     "70071110", "ma"),
    ("BÁNH QUY BƠ NGỌT, KHÔNG CHỨA CA CAO, HỘP 300G (BUTTER COOKIES)", "19053110", "ma"),
    ("NƯỚC KHOÁNG THIÊN NHIÊN ĐÓNG CHAI 500ML, KHÔNG GA, KHÔNG ĐƯỜNG (NATURAL MINERAL WATER)", "22011010", "ma"),
    ("SƠN NƯỚC NỘI THẤT GỐC NHŨ TƯƠNG ACRYLIC PHÂN TÁN TRONG NƯỚC, THÙNG 18 LÍT (WATER-BASED ACRYLIC EMULSION PAINT)",
     "32091090", "ma"),
    ("VẢI DỆT KIM 100% COTTON ĐÃ NHUỘM, KHỔ 180CM, 220G/M2 (DYED COTTON KNITTED FABRIC)", "60062200", "ma"),
    ("ÁO PHÔNG NAM CỔ TRÒN DỆT KIM 100% COTTON (MEN'S KNITTED COTTON T-SHIRT)", "61091010", "ma"),
    ("MÁY IN LASER ĐEN TRẮNG, CHỈ CÓ CHỨC NĂNG IN, KẾT NỐI MÁY TÍNH QUA USB (MONOCHROME LASER PRINTER)",
     "84433239", "ma"),
    ("CHUỘT MÁY TÍNH KHÔNG DÂY KẾT NỐI USB (WIRELESS OPTICAL MOUSE)", "84716040", "ma"),
    ("ĐỒNG HỒ ĐEO TAY ĐIỆN TỬ CHẠY PIN, MẶT HIỂN THỊ SỐ LCD, VỎ VÀ DÂY BẰNG NHỰA (DIGITAL WRIST WATCH)",
     "91021200", "ma"),
    ("KHẨU TRANG Y TẾ 3 LỚP BẰNG VẢI KHÔNG DỆT, DÙNG MỘT LẦN (DISPOSABLE SURGICAL FACE MASK)", "63079040", "ma"),
    ("XE MÁY TAY GA NGUYÊN CHIẾC MỚI 100%, DUNG TÍCH XI LANH 125CC (SCOOTER 125CC, CBU)", "87112096", "ma"),
    ("BỘ SẠC ĐIỆN THOẠI DI ĐỘNG 5V 2A, ĐẦU VÀO 220V (MOBILE PHONE ADAPTER)", "85044019", "ma"),
    ("GHẾ NHỰA ĐÚC LIỀN KHỐI, KHÔNG CÓ KHUNG KIM LOẠI, KHÔNG NHỒI ĐỆM (MONOBLOCK PLASTIC CHAIR)", "94018000", "ma"),
    ("VAN SĂM XE ĐẠP BẰNG ĐỒNG THAU (BRASS TUBE VALVE)", "84818011", "ma"),
    ("BÌNH XĂNG Ô TÔ CON BẰNG THÉP, CHƯA LẮP NẮP (FUEL TANK FOR PASSENGER CAR)", "87089921", "ma"),
    # thiếu thông tin có chủ ý -> đúng là hỏi lại
    ("ÁO PHÔNG DỆT KIM 100% COTTON (KNITTED COTTON T-SHIRT)", "610910", "hoi"),          # nam hay nữ
    ("BÓNG ĐÈN LED 9W (LED BULB)", "853952", "hoi"),                                      # đuôi xoáy hay loại khác
    ("TÔM THẺ CHÂN TRẮNG ĐÔNG LẠNH (FROZEN VANNAMEI SHRIMP)", "030617", "hoi"),            # còn đầu, bỏ đầu, bỏ đuôi
    ("THÉP CUỘN CÁN NÓNG KHÔNG HỢP KIM, CHƯA PHỦ MẠ, RỘNG 1250MM (HOT ROLLED STEEL COIL)", "7208", "hoi"),  # chiều dày
    ("BAO TÚI NHỰA PE ĐỰNG HÀNG (POLYETHYLENE BAGS)", "392321", "hoi"),                  # kích thước, loại túi
    # câu thật người dùng gửi: nhiều dòng, lẫn Anh/Việt, có chất liệu
    ("NAN HOA & CHÂN NAN HOA DÀNH CHO \nXE MÁY (SPOKES & NIPPLES FOR MOTORCYCLE),", "87141020", "ma"),
    ("FABRIC HANDBAG WITH  LEATHER (NON-REPT.) TRIM \nchất liệu 100%vải polyamide-100%da bê (BOS TAURUS)",
     "42022220", "ma"),                                                                  # viền da không đổi mặt ngoài
]


# 20 câu của bộ test đầu tiên (12/11/2026): (mô tả, mã kỳ vọng — có câu chỉ đến 6 số, loại)
CASES_CU = [
    ('FABRIC HANDBAG WITH  LEATHER (NON-REPT.) TRIM  chất liệu 100%vải polyamide-100%da bê (BOS TAURUS)', '42022220', "ma"),
    ('Áo sơ mi nam dài tay, vải dệt thoi 100% cotton', '620520', "ma"),
    ('Máy tính xách tay Dell Latitude 5440, màn hình 14 inch, CPU Intel Core i5, RAM 16GB', '84713020', "ma"),
    ('Xăng động cơ không pha chì RON 95', '271012', "ma"),
    ('Ô tô con 5 chỗ ngồi, động cơ xăng dung tích xi lanh 1.498 cc, mới 100%', '870322', "ma"),
    ('Stainless steel kitchen sink, single bowl, for household use', '732410', "ma"),
    ('Điện thoại di động thông minh Samsung Galaxy A55, mới 100%', '851713', "ma"),
    ('Giày thể thao đế ngoài bằng cao su, mũ giày bằng vải dệt', '640411', "ma"),
    ('PET resin chips (polyethylene terephthalate), dạng nguyên sinh, độ nhớt 0.80 dl/g', '39076', "ma"),
    ('Cà phê nhân Robusta chưa rang, chưa khử chất cafein', '090111', "ma"),
    ('Sữa tươi tiệt trùng nguyên chất dạng lỏng, hàm lượng chất béo 3.5%, không bổ sung đường', '04012010', "ma"),
    ('Rượu vang đỏ từ nho tươi, nồng độ cồn 13.5% vol, đóng chai thủy tinh dung tích 750ml', '22042111', "ma"),
    ('Lốp bằng cao su loại bơm hơi, chưa qua sử dụng, loại dùng cho ô tô con chở người', '40111000', "ma"),
    ('Giấy in văn phòng A4 không tráng, định lượng 70 g/m2, dạng tờ kích thước 210 x 297 mm', '480256', "ma"),
    ('Máy điều hòa không khí loại 2 cục (split-system) lắp tường dùng trong gia đình, công suất làm mát 2.6 kW (9.000 BTU)', '84151020', "ma"),
    ('Tủ lạnh gia đình kiểu liên hợp có cửa mở riêng biệt cho ngăn đá và ngăn lạnh, dung tích 350 lít', '84181032', "ma"),
    ('Màn hình máy tính LCD/LED 27 inch, có cổng kết nối HDMI và DisplayPort, dùng cho máy xử lý dữ liệu tự động', '852852', "ma"),
    ('Tấm pin quang điện năng lượng mặt trời đã lắp ráp thành mảng mô-đun (photovoltaic cells in modules)', '85414300', "ma"),
    ('Ghế xoay văn phòng có thể điều chỉnh độ cao, chân thép kim loại có bánh xe, đệm bọc vải', '94013900', "ma"),
    ('Xe đạp 3 bánh đồ chơi trẻ em có bàn đạp, khung kim loại kết hợp nhựa', '95030010', "ma"),
]
