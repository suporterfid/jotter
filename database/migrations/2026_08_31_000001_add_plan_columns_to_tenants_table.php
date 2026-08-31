<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // `self_hosted` is the default and changes nothing; the other states
            // exist only for hosted operators and are set with `tenant:plan`.
            $table->enum('plan_status', ['self_hosted', 'trial', 'active', 'past_due', 'read_only'])
                ->default('self_hosted')
                ->after('name');
            $table->timestamp('trial_ends_at')->nullable()->after('plan_status');
            $table->string('plan_name')->nullable()->after('trial_ends_at');
            $table->unsignedInteger('plan_seats')->nullable()->after('plan_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['plan_status', 'trial_ends_at', 'plan_name', 'plan_seats']);
        });
    }
};
