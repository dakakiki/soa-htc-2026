<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One file in the media library. Uploaded once, referenced by URL from
 * anywhere — an article body, a cover image, a page.
 *
 * Nothing tracks where a file is used: it can be pasted into any body as plain
 * HTML, so a usage count would be a guess. Deleting is therefore the editor's
 * judgement, not something the model can guard.
 */
class Media extends Model
{
    protected $table = 'cms_media';

    protected $fillable = ['path', 'original_name', 'mime_type', 'size', 'width', 'height', 'alt', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
