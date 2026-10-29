<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // zstd frame (binary) of a JSON object {prompt_log id: prompt text}
            $table->binary('payload');
            $table->timestamps();
        });

        // Laravel has no longBlob: binary() is a 64 KB BLOB on MySQL/MariaDB (unbounded on SQLite), too small for a zstd frame.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE prompt_archives MODIFY payload LONGBLOB NOT NULL');
        }

        Schema::table('prompt_logs', function (Blueprint $table) {
            $table->foreignId('prompt_archive_id')->nullable()->index()->constrained('prompt_archives')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prompt_logs', function (Blueprint $table) {
            // SQLite cannot drop a column that an index still names.
            $table->dropForeign(['prompt_archive_id']);
            $table->dropIndex(['prompt_archive_id']);
            $table->dropColumn('prompt_archive_id');
        });

        Schema::dropIfExists('prompt_archives');
    }
};
