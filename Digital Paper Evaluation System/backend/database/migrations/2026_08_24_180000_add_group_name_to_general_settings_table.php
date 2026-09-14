<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional visual grouping for the General Settings form — fields with
     * the same group_name render together in their own section instead of
     * the default ungrouped card (see the new "Branding & Icons" fields in
     * GeneralSettingSeeder and GeneralSettingsView.vue's per-group
     * sections). Null keeps a field in the default section, same as today.
     */
    public function up(): void
    {
        Schema::table('general_settings', function (Blueprint $table) {
            $table->string('group_name')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('general_settings', function (Blueprint $table) {
            $table->dropColumn('group_name');
        });
    }
};
