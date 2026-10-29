<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('srs_documents', function (Blueprint $table) {
            $table->string('original_filename')->nullable()->after('file_path');
            $table->longText('extracted_text')->nullable()->after('content_hash');
            $table->timestamp('extracted_at')->nullable()->after('extracted_text');
            $table->timestamp('submitted_at')->nullable()->after('extracted_at');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
            $table->timestamp('superseded_at')->nullable()->after('rejection_reason');

            $table->index(['repository_id', 'status']);
        });

        // Left behind by down(); the composite index above covers the same lookups.
        if (Schema::hasIndex('srs_documents', 'srs_documents_repository_id_index')) {
            Schema::table('srs_documents', function (Blueprint $table) {
                $table->dropIndex('srs_documents_repository_id_index');
            });
        }
    }

    public function down(): void
    {
        // MySQL satisfies the repository_id foreign key with the composite index, so a
        // single-column index has to replace it before the composite one can be dropped.
        if (! Schema::hasIndex('srs_documents', 'srs_documents_repository_id_index')) {
            Schema::table('srs_documents', function (Blueprint $table) {
                $table->index('repository_id', 'srs_documents_repository_id_index');
            });
        }

        Schema::table('srs_documents', function (Blueprint $table) {
            $table->dropIndex(['repository_id', 'status']);
        });

        Schema::table('srs_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'original_filename',
                'extracted_text',
                'extracted_at',
                'submitted_at',
                'rejected_at',
                'rejection_reason',
                'superseded_at',
            ]);
        });
    }
};
