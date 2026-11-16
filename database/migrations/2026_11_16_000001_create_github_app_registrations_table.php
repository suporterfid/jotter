<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GitHub App an instance administrator registered from the admin panel
 * (manifest flow). At most one row is in use: the newest. The private key and
 * the webhook secret are encrypted with APP_KEY. JOTTER_GITHUB_APP_ID in .env
 * takes precedence over this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_app_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('app_id', 32);
            $table->string('slug', 100);
            $table->string('owner', 100)->nullable();
            $table->string('html_url', 255)->nullable();
            $table->text('private_key');
            $table->text('webhook_secret')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_app_registrations');
    }
};
