<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Active/inactive toggle, mirrors the venue (schools) status.
            $table->string('status', 20)->default('active')->after('region_id');
            $table->string('city')->nullable()->after('status');
            $table->string('address')->nullable()->after('city');
            $table->string('phone', 100)->nullable()->after('address');
            // Legacy carried two separate uploads (a photo and a document).
            $table->string('image_path')->nullable()->after('phone');
            $table->string('file_path')->nullable()->after('image_path');
            // Per-user student permissions (independent of the season role).
            $table->boolean('can_student_insert')->default(true)->after('file_path');
            $table->boolean('can_student_edit')->default(true)->after('can_student_insert');
            $table->boolean('can_student_delete')->default(true)->after('can_student_edit');
            $table->boolean('can_reset_test_results')->default(false)->after('can_student_delete');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'city', 'address', 'phone', 'image_path', 'file_path',
                'can_student_insert', 'can_student_edit', 'can_student_delete', 'can_reset_test_results',
            ]);
        });
    }
};
