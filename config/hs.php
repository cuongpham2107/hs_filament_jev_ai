<?php

return [
    // Thư mục dữ liệu của dự án tra cứu HS (mặc định admin/data)
    'data_path' => env('HS_DATA_PATH', base_path('data')),
    'uv_bin' => env('HS_UV_BIN', 'uv'),
    'export_timeout' => (int) env('HS_EXPORT_TIMEOUT', 900),
    // Bỏ trống = API không cần token (chỉ dùng trong mạng nội bộ)
    'api_token' => env('HS_API_TOKEN'),
    // Jev cho App\Ai\HsLookup: provider trong config/ai.php (key: TYPESAFE_API_KEY), model trống = jev-latest
    'jev_provider' => env('HS_JEV_PROVIDER', 'typesafe'),
    'jev_model' => env('HS_JEV_MODEL'),
    // Agent LLM tuỳ chọn (App\Ai\Agents\HsRationaleAgent) viết mục 5 (Mô tả khai báo) & mục 6 (Căn cứ phân loại)
    'llm_enabled' => (bool) env('HS_LLM_ENABLED', false),
    'llm_provider' => env('HS_LLM_PROVIDER'),
    'llm_model' => env('HS_LLM_MODEL'),
    // Debug log đánh giá từng bước của Jev (mặc định bật theo APP_DEBUG; đặt HS_JEV_LOG_CHANNEL=jev để tách file riêng)
    'debug_log' => (bool) env('HS_JEV_DEBUG_LOG', env('APP_DEBUG', false)),
    'debug_log_channel' => env('HS_JEV_LOG_CHANNEL'),
];
