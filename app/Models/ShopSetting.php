<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The shop's name and contact details printed on documents. There is exactly one row,
 * created by its migration.
 */
#[Fillable(['name', 'address', 'phone', 'email', 'tin'])]
class ShopSetting extends Model
{
    /**
     * The shop's details.
     */
    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
