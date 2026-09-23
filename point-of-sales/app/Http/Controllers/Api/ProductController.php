<?php

namespace App\Http\Controllers\Api;

use App\Enums\StockLedgerMovement;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Traits\ApiResponder;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use App\Services\OutletAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryPostingService $postingService
    ) {}

    /**
     * GET /api/v1/products
     * List products with optional search, category filter, pagination (per_page).
     */
    public function index(Request $request): JsonResponse
    {
        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
        $products = Product::query()
            ->with([
                'category',
                'warehouses' => fn ($query) => $query->whereIn('warehouses.id', $warehouseIds),
            ])
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->integer('category_id'), function ($q, $categoryId) {
                $q->where('category_id', $categoryId);
            })
            ->latest()
            ->paginate($this->perPage());

        return $this->paginated($products, ProductResource::collection($products));
    }

    /**
     * POST /api/v1/products
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'barcode' => ['required', 'string', 'max:255', Rule::unique('products', 'barcode')],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'buy_price' => ['required', 'numeric', 'min:0'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'tax_type' => ['nullable', 'in:exclusive,inclusive'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image' => ['nullable', 'string', 'max:255'],
            'is_composite' => ['nullable', 'boolean'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $product = Product::create([
            ...$validated,
            'image' => $validated['image'] ?? '',
            'description' => $validated['description'] ?? '',
            // Opening stock is posted below (ledger + cache), which also sets this counter.
            'stock' => 0,
            'min_stock' => $validated['min_stock'] ?? 0,
            'max_stock' => $validated['max_stock'] ?? 0,
            'tax_type' => $validated['tax_type'] ?? 'exclusive',
            'tax_rate' => $validated['tax_rate'] ?? 0,
            'is_composite' => $validated['is_composite'] ?? false,
        ]);

        // Attach to default warehouse (or first) if none given
        $warehouseId = $request->integer('warehouse_id');
        if ($warehouseId) {
            $warehouse = Warehouse::findOrFail($warehouseId);
            abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);
            $this->postOpeningStock($product, $warehouseId, (int) ($validated['stock'] ?? 0), $request->user()?->id);
        } else {
            $default = Warehouse::active()->orderBy('code')->first();
            if ($default && ! $this->outletAccessService->canUseWarehouse($request->user(), $default)) {
                $default = $this->outletAccessService->warehousesFor($request->user())->first();
            }
            if ($default) {
                $this->postOpeningStock($product, $default->id, (int) ($validated['stock'] ?? 0), $request->user()?->id);
            } else {
                $product->update(['stock' => (int) ($validated['stock'] ?? 0)]);
            }
        }

        return $this->created(new ProductResource($product->load('category')), 'Produk berhasil dibuat');
    }

    /**
     * GET /api/v1/products/{product}
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
        $product->load([
            'category',
            'warehouses' => fn ($query) => $query->whereIn('warehouses.id', $warehouseIds),
        ]);

        return $this->ok(new ProductResource($product));
    }

    /**
     * PUT/PATCH /api/v1/products/{product}
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'barcode' => ['sometimes', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($product->id)],
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product->id)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'buy_price' => ['sometimes', 'numeric', 'min:0'],
            'sell_price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'max_stock' => ['nullable', 'integer', 'min:0'],
            'tax_type' => ['nullable', 'in:exclusive,inclusive'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image' => ['nullable', 'string', 'max:255'],
            'is_composite' => ['nullable', 'boolean'],
        ]);

        $product->update($validated);

        return $this->ok(new ProductResource($product->load('category')), 'Produk berhasil diperbarui');
    }

    /**
     * DELETE /api/v1/products/{product}
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $product->delete();

        return $this->noContent();
    }

    /** Same opening-stock rule as the web form: through the ledger, OPENING unit for tracked products. */
    private function postOpeningStock(Product $product, int $warehouseId, int $qty, ?int $userId): void
    {
        if ($qty <= 0) {
            ProductWarehouse::firstOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
                ['stock' => 0]
            );

            return;
        }

        $this->postingService->receive(
            $product,
            $warehouseId,
            (float) $qty,
            StockLedgerMovement::Receive,
            new PostingContext('product_create', $product->id, 'Stok awal (API)', $userId),
            null,
            'OPENING',
        );
    }
}
