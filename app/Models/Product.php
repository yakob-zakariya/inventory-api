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
use Illuminate\Support\Facades\DB;

#[Fillable(['category_id', 'sku', 'name', 'description', 'unit', 'cost_price', 'selling_price', 'additional_cost', 'reorder_level'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, RecordsCreator, SoftDeletes;

    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reorder_level' => 0,
        'additional_cost' => '0.00',
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
            'additional_cost' => 'decimal:2',
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
     * Only products whose stock is `low` (above zero, at or below the reorder level) or `out` (zero or less).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function stockLevel(Builder $query, string $level): void
    {
        $stock = static::stockSubquery();

        match ($level) {
            'low' => $query->where($stock, '>', 0)->where($stock, '<=', DB::raw($this->qualifyColumn('reorder_level'))),
            'out' => $query->where($stock, '<=', 0),
        };
    }

    /**
     * Only products with stock above zero.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where(static::stockSubquery(), '>', 0);
    }

    /**
     * Sort by `name` (default), `stock` (lowest first) or `-stock` (highest first), then by name.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sortBy(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'stock' => $query->orderBy(static::stockSubquery()),
            '-stock' => $query->orderByDesc(static::stockSubquery()),
            default => null,
        };

        $query->orderBy($this->qualifyColumn('name'));
    }

    /**
     * Stock on hand of the product row in the outer query.
     *
     * @return Builder<StockMovement>
     */
    private static function stockSubquery(): Builder
    {
        return StockMovement::query()
            ->selectRaw('coalesce(sum(quantity), 0)')
            ->whereColumn('product_id', 'products.id');
    }

    /**
     * Value of all stock on hand, in cents, at cost and at selling price, calculated in SQL.
     *
     * @return array{cost: int, selling: int}
     */
    public static function inventoryValueCents(): array
    {
        $stock = StockMovement::query()
            ->select('product_id')
            ->selectRaw('sum(quantity) as on_hand')
            ->groupBy('product_id');

        $totals = static::query()
            ->joinSub($stock, 'stock', 'stock.product_id', '=', 'products.id')
            ->selectRaw('coalesce(sum(stock.on_hand * round(products.cost_price * 100)), 0) as cost')
            ->selectRaw('coalesce(sum(stock.on_hand * round(products.selling_price * 100)), 0) as selling')
            ->toBase()
            ->first();

        return ['cost' => (int) $totals->cost, 'selling' => (int) $totals->selling];
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
