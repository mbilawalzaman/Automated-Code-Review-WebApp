<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->text('credentials')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('health_status')->default('unknown');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
