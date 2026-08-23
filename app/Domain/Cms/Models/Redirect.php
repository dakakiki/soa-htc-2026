<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A public address that used to belong to a page or post. Written when a
 * published item's slug changes, so a link already out in the world keeps
 * working (PROJECT_CONTEXT §8.6).
 */
class Redirect extends Model
{
    protected $table = 'cms_redirects';

    protected $fillable = ['from_path', 'target_type', 'target_id'];

    protected function casts(): array
    {
        return ['target_id' => 'integer'];
    }

    public const TYPE_PAGE = 'page';

    public const TYPE_POST = 'post';
}
