<?php

return [
    // Thư mục dữ liệu và script Python của dự án tra cứu HS (admin/ nằm cạnh data/ và scripts/)
    'data_path' => env('HS_DATA_PATH', base_path('../data')),
    'project_path' => env('HS_PROJECT_PATH', base_path('..')),
    'uv_bin' => env('HS_UV_BIN', 'uv'),
    'export_timeout' => (int) env('HS_EXPORT_TIMEOUT', 900),
    // Bỏ trống = API không cần token (chỉ dùng trong mạng nội bộ)
    'api_token' => env('HS_API_TOKEN'),
    // App chatflow Dify tra cứu (khung chat trong admin gọi qua Laravel, key không ra trình duyệt)
    'dify_url' => env('HS_DIFY_URL', 'http://127.0.0.1:5001/v1'),
    'dify_key' => env('HS_DIFY_KEY'),
    'dify_timeout' => (int) env('HS_DIFY_TIMEOUT', 300),
];
