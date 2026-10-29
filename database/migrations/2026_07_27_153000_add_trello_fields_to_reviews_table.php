<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_jobs', function (Blueprint $table) {
            $table->string('trello_card_id')->nullable()->after('github_check_run_id');
            $table->string('trello_comment_id')->nullable()->after('trello_card_id');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('trello_card_id')->nullable()->after('github_check_run_id');
            $table->string('trello_comment_id')->nullable()->after('trello_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('review_jobs', function (Blueprint $table) {
            $table->dropColumn(['trello_card_id', 'trello_comment_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['trello_card_id', 'trello_comment_id']);
        });
    }
};
