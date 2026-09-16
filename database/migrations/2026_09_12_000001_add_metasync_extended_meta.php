<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metasync_pages', function (Blueprint $table): void {
            $table->string('og_title', 1024)->nullable()->after('short_text');
            $table->text('og_description')->nullable()->after('og_title');
            $table->string('og_image', 2048)->nullable()->after('og_description');
            $table->string('canonical_url', 2048)->nullable()->after('og_image');
            $table->text('hreflang')->nullable()->after('canonical_url');
            $table->text('schema_json')->nullable()->after('hreflang');
        });

        Schema::create('metasync_not_found', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 500)->unique();
            $table->unsignedInteger('hits')->default(1);
            $table->string('referer', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metasync_not_found');

        Schema::table('metasync_pages', function (Blueprint $table): void {
            $table->dropColumn(['og_title', 'og_description', 'og_image', 'canonical_url', 'hreflang', 'schema_json']);
        });
    }
};
