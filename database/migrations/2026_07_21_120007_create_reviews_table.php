<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('review_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('review_error');
            $table->string('commit_sha', 40)->nullable();
            $table->unsignedInteger('pull_request_number')->nullable();
            $table->string('pull_request_title')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('summary')->nullable();
            $table->json('tools_used')->nullable();
            $table->string('github_check_run_id')->nullable();
            $table->string('trello_message_id')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'status']);
            $table->index(['commit_sha', 'pull_request_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
