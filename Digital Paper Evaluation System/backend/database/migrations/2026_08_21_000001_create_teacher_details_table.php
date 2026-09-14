<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teacher_details', function (Blueprint $table) {
            $table->id();
            // No database foreign key (project-wide rule) — resolved through
            // the User::teacherDetail() / TeacherDetail::user() relations.
            // The user's own name/email/phone/password live on users; this
            // table only holds the extra teacher-specific fields.
            $table->unsignedBigInteger('user_id');
            $table->string('department')->nullable();
            $table->string('designation')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('user_id');
            $table->index('department');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_details');
    }
};
