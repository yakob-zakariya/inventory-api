<?php

namespace App\Models;

use App\Models\Concerns\RecordsCreator;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, RecordsCreator;

    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Whether any product, including soft-deleted ones, belongs to this category.
     */
    public function isInUse(): bool
    {
        return $this->products()->withTrashed()->exists();
    }

    /**
     * Filter by a partial name match.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query) => $query->whereLike('name', "%{$term}%"));
    }
}
