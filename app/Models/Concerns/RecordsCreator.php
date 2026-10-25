<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills `created_by` with the logged-in user when a model is created.
 */
trait RecordsCreator
{
    public static function bootRecordsCreator(): void
    {
        static::creating(function (self $model): void {
            $model->created_by ??= Auth::id();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
