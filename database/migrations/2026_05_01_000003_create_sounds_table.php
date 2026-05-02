<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sounds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('file_url');
            $table->string('category');
            $table->unsignedInteger('duration')->nullable()->comment('Duration in seconds');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sounds');
    }
};
