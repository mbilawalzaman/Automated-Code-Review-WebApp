<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('category')->default('auth')->after('user_id');
            $table->string('event_type')->default('updated')->after('category');
            $table->string('severity')->default('info')->after('event_type');
            $table->json('metadata')->nullable()->after('after_state');

            $table->index(['category', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropIndex(['event_type', 'created_at']);
            $table->dropIndex(['severity', 'created_at']);

            $table->dropColumn(['category', 'event_type', 'severity', 'metadata']);
        });
    }
};
