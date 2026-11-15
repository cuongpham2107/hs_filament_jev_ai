<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lịch sử từng lượt tra cứu trong khung chat (trang Tra cứu HS). */
    public function up(): void
    {
        Schema::create('tra_cuu_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->string('conversation_id')->nullable()->index();
            $table->boolean('is_follow_up')->default(false);
            $table->string('status', 20)->index();           // ok | can_bo_sung | error
            $table->string('ma_hs', 8)->nullable()->index(); // mã Jev xếp đầu (kể cả khi cần bổ sung)
            $table->decimal('do_tin_cay', 5, 3)->nullable();
            $table->text('can_cu')->nullable();               // các dòng căn cứ (chương, nhóm, đường đi)
            $table->longText('the_ket_qua')->nullable();      // markdown thẻ kết quả / thẻ cần bổ sung
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tra_cuu_logs');
    }
};
