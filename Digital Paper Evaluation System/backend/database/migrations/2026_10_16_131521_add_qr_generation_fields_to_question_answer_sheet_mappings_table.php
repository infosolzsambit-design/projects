<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            // No database foreign key (project-wide rule) — a plain indexed column.
            $table->unsignedBigInteger('exam_sub_type_id')->nullable()->after('exam_type_id')
                ->comment('exam_sub_types.id (our own id, not the main db Ref Id)');
            $table->string('exam_year', 20)->nullable()->after('exam_sub_type_id');
            $table->string('batch', 20)->nullable()->after('exam_year');
            $table->boolean('is_scan_complete')->default(false)->after('packet_code')
                ->comment('1 = scan complete, 0 = not complete. 0 when the QR codes are generated');

            $table->index('exam_sub_type_id');
            $table->index('batch');
            $table->index('is_scan_complete');
        });
    }

    public function down(): void
    {
        Schema::table('question_answer_sheet_mappings', function (Blueprint $table) {
            $table->dropIndex(['exam_sub_type_id']);
            $table->dropIndex(['batch']);
            $table->dropIndex(['is_scan_complete']);
            $table->dropColumn(['exam_sub_type_id', 'exam_year', 'batch', 'is_scan_complete']);
        });
    }
};
