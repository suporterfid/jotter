<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_delivery_items')) {
            Schema::create('notification_delivery_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('delivery_id')->constrained('notification_deliveries')->cascadeOnDelete();
                $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
                $table->string('channel', 32);
                $table->timestamps();

                $table->unique(['notification_id', 'channel']);
                $table->unique(['delivery_id', 'notification_id']);
                $table->index(['delivery_id', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_items');
    }
};
