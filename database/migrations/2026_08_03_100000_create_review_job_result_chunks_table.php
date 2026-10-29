<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_job_result_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_job_id')->constrained('review_jobs')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->json('findings');
            $table->timestamps();

            $table->unique(['review_job_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_job_result_chunks');
    }
};
