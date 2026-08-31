<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Idempotency markers for the scheduler-driven trial e-mails.
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('plan_seats');
            $table->timestamp('trial_ended_notified_at')->nullable()->after('trial_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['trial_reminder_sent_at', 'trial_ended_notified_at']);
        });
    }
};
