<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biểu thuế theo đúng cây phân loại: Phần -> Chương -> Nhóm 4 số -> nút cây (cấp gạch ngang) -> mã 8 số.
 * Quy tắc 1: phân loại theo nội dung nhóm và chú giải Phần, Chương -> chú giải nằm ở bảng phần/chương/nhóm.
 * Quy tắc 6: chỉ so các phân nhóm cùng cấp -> hs_nodes giữ parent_id và level (số gạch ngang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('source_file')->nullable();
            $table->string('status')->default('dang_xu_ly');
            $table->text('message')->nullable();
            $table->boolean('is_active')->default(false);
            $table->json('counts')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hs_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('no');
            $table->text('content');
            $table->unique(['tariff_year_id', 'no']);
        });

        Schema::create('hs_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_year_id')->constrained()->cascadeOnDelete();
            $table->string('no', 8);
            $table->string('name', 500);
            $table->json('notes');
            $table->unique(['tariff_year_id', 'no']);
        });

        Schema::create('hs_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hs_section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('no', 3);
            $table->string('name', 500);
            $table->json('notes');
            $table->unique(['tariff_year_id', 'no']);
        });

        Schema::create('hs_headings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hs_chapter_id')->constrained()->cascadeOnDelete();
            $table->char('code', 4);
            $table->text('name');
            $table->text('en')->nullable();
            $table->json('notes');                 // ghi chú trong nhóm (biểu thuế)
            $table->json('biz_notes');             // ghi chú nghiệp vụ (data/ghi_chu_nghiep_vu.md)
            $table->json('subheadings');           // {phân nhóm 6 số: đường dẫn}, do hs_model.py suy ra
            $table->unique(['tariff_year_id', 'code']);
        });

        Schema::create('hs_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hs_heading_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('hs_nodes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');      // thứ tự trong nhóm, đúng thứ tự dòng của biểu thuế
            $table->unsignedTinyInteger('level');          // số gạch ngang: 1 = phân nhóm một gạch
            $table->string('code', 8)->nullable();         // mã 6 hoặc 8 số; dòng tiêu đề trung gian không có mã
            $table->text('text');                          // chữ nguyên văn của dòng
            $table->text('label');                         // 'Loại khác (không phải: A, B)' đã ghép anh em
            $table->text('en')->nullable();
            $table->text('note')->nullable();
            // chỉ có ở dòng mã 8 số
            $table->char('subheading', 6)->nullable();
            $table->text('path')->nullable();
            $table->text('path_en')->nullable();
            $table->text('display_path')->nullable();
            $table->json('tax')->nullable();
            $table->json('risk')->nullable();
            $table->index(['tariff_year_id', 'code']);
            $table->unique(['hs_heading_id', 'position']);
        });
    }

    public function down(): void
    {
        foreach (['hs_nodes', 'hs_headings', 'hs_chapters', 'hs_sections', 'hs_rules', 'tariff_years'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
