<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each row is one configurable field on the General Settings form (see
     * GeneralSettingsView.vue) rather than a fixed column-per-setting
     * table — "type"/"options"/"validation_rules" drive how that field
     * renders and validates, "value"/"extra_value" hold what was saved.
     * Ported from the ccu_nwdb.general_settings table structure.
     */
    public function up(): void
    {
        Schema::create('general_settings', function (Blueprint $table) {
            $table->id();
            $table->string('field_name')->unique();
            $table->string('label')->nullable();
            // text | textarea | number | email | url | date | radio |
            // selectbox | checkbox | file — see GeneralSettingsView.vue's
            // per-type rendering and GeneralSettingController's per-type
            // validation, both keyed off this column.
            $table->string('type', 50)->default('text');
            $table->text('value')->nullable();
            // Companion value for a radio option with "has_extra" set in
            // `options` (e.g. "Enable Club Commission: Yes" revealing a
            // "Commission Value (%)" input) — unused by every other type.
            $table->text('extra_value')->nullable();
            // [{label, value, has_extra?, extra_label?}, ...] for
            // radio/selectbox/checkbox fields; null for every other type.
            $table->json('options')->nullable();
            $table->string('placeholder')->nullable();
            $table->text('help_text')->nullable();
            $table->text('validation_rules')->nullable();
            $table->boolean('is_required')->default(false)->comment('1 = required, 0 = optional');
            $table->integer('sort_order')->default(0);
            $table->boolean('status')->default(true)->comment('1 = active (shown on the form), 0 = inactive');

            // No database foreign keys anywhere in this project — these are
            // plain indexed columns, resolved through model relationships.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('sort_order');
            $table->index('status');
            $table->index('deleted_at');
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_settings');
    }
};
