<?php

declare(strict_types=1);

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named navigation. The site can hold as many as it needs; `slug` is the
 * handle a layout asks for.
 */
class Menu extends Model
{
    protected $table = 'cms_menus';

    protected $fillable = ['name', 'slug'];

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id')->orderBy('position');
    }

    /** Just the top level; each item carries its own children. */
    /** @return HasMany<MenuItem, $this> */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
