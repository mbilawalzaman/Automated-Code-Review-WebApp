<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->foreignId('objective_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity');
            $table->string('status')->default('open');
            $table->string('file_path');
            $table->unsignedInteger('line_number')->nullable();
            $table->string('rule_id')->nullable();
            $table->string('source')->nullable();
            $table->string('title');
            $table->text('evidence');
            $table->text('objective_impact')->nullable();
            $table->text('recommendation')->nullable();
            $table->boolean('is_blocking')->default(false);
            $table->foreignId('resolved_in_review_id')->nullable()->constrained('reviews')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('false_positive_reason')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'severity']);
            $table->index(['review_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
