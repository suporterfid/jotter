<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
                $table->string('channel', 32);
                $table->string('kind', 32);
                $table->string('dedupe_key', 191)->unique();
                $table->string('status', 32)->default('pending');
                $table->json('payload')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status', 'kind']);
                $table->index(['notification_id', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
