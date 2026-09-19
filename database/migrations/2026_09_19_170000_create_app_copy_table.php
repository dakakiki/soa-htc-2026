<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an administrator has rewritten on the installed application's screens
 * (ADR-0133).
 *
 * 🔴 A table of OVERRIDES, not of copy. A row exists only where somebody typed
 * something; every screen still ships its own words in `en.ts`, and an
 * installation that never opens Website → Mobile draws exactly what it draws
 * today. That is what makes clearing a box a way of giving the original back
 * rather than emptying a screen.
 *
 * 🪤 Its own table rather than columns on the `settings` singleton, which is
 * where the mail template's overrides live. That row is wide because the mail
 * has a fixed handful of fields; this has 115 and will have more the day a
 * screen gains a sentence, and a schema change per sentence is not a schema.
 *
 * The key is the i18n key the screen already asks for (`public.app.who`), so
 * there is no second naming of the same string and nothing to keep in step.
 * Which keys are allowed is decided in code, by AppScreens — the table takes
 * whatever the controller validated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_copy', function (Blueprint $table): void {
            $table->id();
            // Long enough for the deepest key in the catalogue with room over.
            $table->string('key', 120)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_copy');
    }
};
