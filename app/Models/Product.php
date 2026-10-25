<?php

namespace App\Models;

use App\Models\Concerns\RecordsCreator;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'sku', 'name', 'description', 'unit', 'cost_price', 'selling_price', 'reorder_level'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, RecordsCreator, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reorder_level' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'reorder_level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Add a `stock` attribute: the sum of the product's stock movements.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withStock(Builder $query): void
    {
        $query->withSum('stockMovements as stock', 'quantity');
    }

    /**
     * Current stock, always calculated from the ledger.
     */
    public function currentStock(): int
    {
        return (int) $this->stockMovements()->sum('quantity');
    }

    /**
     * Filter by a partial match on name or SKU.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query) => $query->where(
            fn (Builder $query) => $query->whereLike('name', "%{$term}%")->orWhereLike('sku', "%{$term}%"),
        ));
    }
}
