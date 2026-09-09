<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metasync_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->text('url_path');
            $table->char('url_hash', 40);
            $table->string('lang', 10)->default('uk');
            $table->string('page_type', 64)->nullable();
            $table->string('title', 1024)->nullable();
            $table->text('description')->nullable();
            $table->string('h1', 1024)->nullable();
            $table->longText('text')->nullable();
            $table->text('short_text')->nullable();
            $table->boolean('noindex')->default(false);
            $table->timestamp('meta_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['url_hash', 'lang']);
        });

        Schema::create('metasync_redirects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->string('from_path', 500)->unique();
            $table->string('to_url', 2048);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metasync_redirects');
        Schema::dropIfExists('metasync_pages');
    }
};
