<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-lived, revocable web/mobile sessions for a competitor, scoped to a
     * single registration. Issued after web identification (competitor_number +
     * country + date of birth). Only the SHA-256 hash of the bearer token is
     * stored; the plaintext is returned once at identification.
     */
    public function up(): void
    {
        Schema::create('student_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['registration_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_sessions');
    }
};
