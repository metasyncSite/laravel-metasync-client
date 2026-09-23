<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alias hosts (bought drop domains, old brands) pulled from the
        // project info; the middleware redirects their traffic.
        Schema::create('metasync_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 255)->unique();
            $table->string('fallback_url', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // '' = the site's own domain, otherwise one of metasync_domains.
        Schema::table('metasync_redirects', function (Blueprint $table): void {
            $table->dropUnique(['from_path']);
            $table->string('host', 255)->default('')->after('remote_id');
            $table->unique(['host', 'from_path']);
        });

        Schema::table('metasync_not_found', function (Blueprint $table): void {
            $table->dropUnique(['path']);
            $table->string('host', 255)->default('')->after('id');
            $table->unique(['host', 'path']);
        });
    }

    public function down(): void
    {
        Schema::table('metasync_not_found', function (Blueprint $table): void {
            $table->dropUnique(['host', 'path']);
            $table->dropColumn('host');
            $table->unique(['path']);
        });

        Schema::table('metasync_redirects', function (Blueprint $table): void {
            $table->dropUnique(['host', 'from_path']);
            $table->dropColumn('host');
            $table->unique(['from_path']);
        });

        Schema::dropIfExists('metasync_domains');
    }
};
