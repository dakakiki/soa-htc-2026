<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rewritten line on an installed-application screen (ADR-0133).
 *
 * A row exists only where an administrator typed something. The key is the i18n
 * key the screen already asks for, so there is nothing here that names a string
 * twice — and nothing to fall back to, because the fallback is the catalogue the
 * application ships with.
 */
class AppCopy extends Model
{
    protected $table = 'app_copy';

    protected $fillable = ['key', 'value'];
}
