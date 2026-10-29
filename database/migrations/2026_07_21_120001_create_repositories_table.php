<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('full_name')->unique();
            $table->unsignedBigInteger('github_repo_id')->nullable()->unique();
            $table->string('status')->default('disconnected');
            $table->boolean('is_paused')->default(false);
            $table->string('default_branch')->default('main');
            $table->string('trello_board_id')->nullable();
            $table->string('trello_list_id')->nullable();
            $table->json('notification_settings')->nullable();
            $table->json('review_rules')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
