<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable content for the "SOA Cert" participation certificate: a rich-text
 * body (with [placeholders] substituted per student), a signature caption, and
 * uploaded header logo / signature / QR images. All optional — the certificate
 * falls back to a built-in default body and the "SOA HTC" wordmark when unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('cert_body')->nullable();
            $table->string('cert_signature_text')->nullable();
            $table->string('cert_logo_path')->nullable();
            $table->string('cert_signature_path')->nullable();
            $table->string('cert_qr_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['cert_body', 'cert_signature_text', 'cert_logo_path', 'cert_signature_path', 'cert_qr_path']);
        });
    }
};
