<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TEXT (64KB) truncates large SRS uploads on MySQL; LONGTEXT matches the 10 MB upload limit.
     */
    public function up(): void
    {
        Schema::table('srs_documents', function (Blueprint $table) {
            $table->longText('extracted_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('srs_documents', function (Blueprint $table) {
            $table->text('extracted_text')->nullable()->change();
        });
    }
};
