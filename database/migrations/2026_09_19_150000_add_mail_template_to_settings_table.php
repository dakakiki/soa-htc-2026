<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The e-mail template's own settings (ADR-0132).
 *
 * Until now the mail borrowed everything it showed: the logo from the theme, the
 * name beside it from `site_title`, the footer paragraph from the website's own
 * footer block. That was right while there was nothing to decide — but a logo
 * the mail cannot draw is a blank masthead, and the website's footer has no
 * place for a contact address.
 *
 * 🔴 Every column is NULLABLE and every reader falls back to today's source, so
 * an installation that never opens the screen sends exactly the mail it sends
 * now. Nothing here is a switch that has to be set.
 *
 * 🪤 On the `settings` singleton rather than in a table of its own. Every value
 * the mail already draws lives on that row, {@see
 * \App\Domain\Communication\Support\MailBranding} reads it once per send and
 * memoises it, and a second table would be a second query per mail for one
 * record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            // Raster only, by validation and again when read: Gmail and Outlook
            // draw no SVG, and both logos on a fresh install are vectors.
            $table->string('mail_logo_path')->nullable()->after('cert_qr_path');
            $table->string('mail_header_text', 200)->nullable()->after('mail_logo_path');
            $table->string('mail_greeting', 200)->nullable()->after('mail_header_text');
            $table->text('mail_signoff')->nullable()->after('mail_greeting');
            $table->text('mail_footer_text')->nullable()->after('mail_signoff');
            $table->string('mail_footer_web', 200)->nullable()->after('mail_footer_text');
            $table->string('mail_footer_email', 200)->nullable()->after('mail_footer_web');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'mail_logo_path', 'mail_header_text', 'mail_greeting',
                'mail_signoff', 'mail_footer_text', 'mail_footer_web', 'mail_footer_email',
            ]);
        });
    }
};
