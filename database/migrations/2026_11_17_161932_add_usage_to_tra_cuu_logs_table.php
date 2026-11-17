<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Token Jev đã dùng và tiền ước tính của từng lượt tra cứu (để xem lại lịch sử và cộng chi phí). */
    public function up(): void
    {
        Schema::table('tra_cuu_logs', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->after('do_tin_cay');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->decimal('cost_usd', 12, 6)->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('tra_cuu_logs', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'output_tokens', 'cost_usd']);
        });
    }
};
