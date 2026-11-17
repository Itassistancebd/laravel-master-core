<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_core_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name', 64);
            $table->string('setting_key', 128);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(
                ['group_name', 'setting_key'],
                'master_core_settings_group_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_core_settings');
    }
};
