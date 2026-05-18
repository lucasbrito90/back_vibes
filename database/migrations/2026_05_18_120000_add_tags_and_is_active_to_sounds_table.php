<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('duration');
            $table->boolean('is_active')->default(true)->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_active']);
        });
    }
};
