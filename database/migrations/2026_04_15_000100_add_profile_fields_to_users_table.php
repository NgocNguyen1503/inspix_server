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
        Schema::table('users', function (Blueprint $table) {
            $table->string('bio', 100)->nullable()->after('email');
            $table->text('avatar_url')->nullable()->after('password');
            $table->unsignedInteger('total_collections')->default(0)->after('avatar_url');
            $table->unsignedInteger('total_likes')->default(0)->after('total_collections');
            $table->unsignedInteger('total_images')->default(0)->after('total_likes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio',
                'avatar_url',
                'total_collections',
                'total_likes',
                'total_images',
            ]);
        });
    }
};
