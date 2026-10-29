<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('review_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('type');
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
