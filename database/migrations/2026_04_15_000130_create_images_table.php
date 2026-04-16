<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->string('uuid', 255)->primary();
            $table->string('color', 50)->nullable();
            $table->text('url_small');
            $table->text('url_regular');
            $table->text('url_full');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('collection_id');
            $table->text('download_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
