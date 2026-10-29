<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->json('change_classes')->nullable()->after('detected_stacks');
            $table->json('impact_analysis')->nullable()->after('change_classes');
            $table->json('tool_executions')->nullable()->after('impact_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['change_classes', 'impact_analysis', 'tool_executions']);
        });
    }
};
