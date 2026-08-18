<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exam attendance for each competitor. A student may report they will not
     * attend; admins set it per student in the edit form and, later, in bulk on
     * import. Defaults to 'present' — everyone is assumed attending until marked
     * absent.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('attendance', 16)->default('present')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('attendance');
        });
    }
};
