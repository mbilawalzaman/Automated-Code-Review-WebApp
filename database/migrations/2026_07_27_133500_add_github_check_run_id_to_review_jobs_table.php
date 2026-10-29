<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_jobs', function (Blueprint $table) {
            $table->string('github_check_run_id')->nullable()->after('pull_request_number');
        });
    }

    public function down(): void
    {
        Schema::table('review_jobs', function (Blueprint $table) {
            $table->dropColumn('github_check_run_id');
        });
    }
};
