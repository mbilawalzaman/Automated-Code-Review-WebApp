<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->decimal('coverage_percentage', 5, 2)->nullable()->after('tools_used');
            $table->decimal('changed_code_coverage', 5, 2)->nullable()->after('coverage_percentage');
            $table->json('test_summary')->nullable()->after('changed_code_coverage');
            $table->boolean('execution_authorized')->nullable()->after('test_summary');
            $table->json('detected_stacks')->nullable()->after('execution_authorized');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn([
                'coverage_percentage',
                'changed_code_coverage',
                'test_summary',
                'execution_authorized',
                'detected_stacks',
            ]);
        });
    }
};
