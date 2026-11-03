<?php

namespace App\Http\Controllers\Apps;

use App\Enums\StockCalcStrategy;
use App\Enums\StockLedgerMovement;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\PostingContext;
use App\Services\OutletAccessService;
use App\Services\StockMutationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryPostingService $postingService
    ) {}

    /**
     * Opening stock is posted like any other movement (tracked products get an OPENING unit),
     * so the ledger and the cached balances start out equal.
     */
    private function postOpeningStock(Product $product, int $warehouseId, int $qty, ?int $userId, string $note = 'Stok awal saat produk dibuat'): ?PhysicalUnit
    {
        if ($qty <= 0) {
            ProductWarehouse::firstOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
                ['stock' => 0]
            );

            return null;
        }

        return $this->postingService->receive(
            $product,
            $warehouseId,
            (float) $qty,
            StockLedgerMovement::Receive,
            new PostingContext('product_create', $product->id, $note, $userId),
            null,
            'OPENING',
        );
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $request->validate(['domain' => 'nullable|in:RM,FG']);
        $products = Product::when($request->search, function ($products, $search) {
            $products = $products->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%');
            });
        })->when($request->domain, fn ($query, $domain) => $query->where('domain', $domain))
            ->with(['category', 'warehouses:id,code,name'])
            ->latest()
            ->paginate($this->perPage())
            ->withQueryString();

        $products->getCollection()->transform(function (Product $product) {
            $product->setAttribute('warehouse_stocks', $product->warehouses->map(fn ($warehouse) => [
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'stock' => (int) $warehouse->pivot->stock,
            ])->values());

            return $product;
        });

        $warehouses = $this->outletAccessService->warehousesFor($request->user());

        return Inertia::render('Dashboard/Products/Index', [
            'products' => $products,
            'warehouses' => $warehouses,
            'domain' => $request->input('domain', ''),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        // get categories
        $categories = Category::all();

        // get non-composite products for composite component selection
        $products = Product::where('is_composite', false)
            ->orderBy('title')
            ->get(['id', 'title', 'sell_price', 'is_composite']);

        // return inertia
        return Inertia::render('Dashboard/Products/Create', [
            'categories' => $categories,
            'products' => $products,
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
            'warehouses' => config('warehouse.is_warehouse')
                ? $this->outletAccessService->warehousesFor(request()->user())->values()
                : [],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        if (config('warehouse.is_warehouse')) {
            return $this->storeWarehouseMaterial($request);
        }

        /**
         * validate
         */
        $request->validate([
            'domain' => 'sometimes|required|in:RM,FG',
            'base_uom' => 'nullable|in:PCS,KG,M,ROLL,BOX,SET',
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
            'barcode' => 'required|unique:products,barcode',
            'sku' => 'required|unique:products,sku',
            'title' => 'required',
            'description' => 'required',
            'category_id' => 'required',
            'buy_price' => 'required',
            'sell_price' => 'required',
            'stock' => 'required|integer|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ]);
        // upload image
        $image = $request->file('image');
        $image->storeAs('public/products', $image->hashName());

        // create product
        $product = Product::create([
            'image' => $image->hashName(),
            'barcode' => $request->barcode,
            'sku' => $request->sku,
            'title' => $request->title,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'domain' => $request->input('domain', 'FG'),
            'base_uom' => $request->input('base_uom') ?: ($request->input('domain', 'FG') === 'FG' ? 'PCS' : 'KG'),
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            // Non-composite opening stock is posted below, which also sets this counter.
            'stock' => $request->boolean('is_composite') ? $request->stock : 0,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
        ]);

        if ($request->boolean('is_composite')) {
            $this->validateComponentProducts($request);
            $this->syncComponents($product, $request->input('components'));
        } else {
            $this->syncUnits($product, $request->input('units'));

            // ponytail: keep global products.stock and per-warehouse pivot in sync; PUSAT (first active main) is the default warehouse
            $warehouse = Warehouse::find($request->warehouse_id)
                ?? Warehouse::active()->where('type', 'main')->orderBy('sort_order')->orderBy('code')->first()
                ?? Warehouse::active()->orderBy('sort_order')->orderBy('code')->first();

            abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

            if ($warehouse) {
                $this->postOpeningStock($product, $warehouse->id, (int) $request->stock, $request->user()?->id);
            } else {
                $product->update(['stock' => (int) $request->stock]);
            }

            $this->stockMutationService->recordInitialStock($product, $request->user()?->id, $warehouse?->id);
        }
        $this->auditLogService->log(
            event: 'product.created',
            module: 'products',
            auditable: $product,
            description: 'Produk baru dibuat.',
            after: $this->productAuditPayload($product->fresh())
        );

        // redirect
        return to_route('products.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(Product $product)
    {
        // get categories
        $categories = Category::all();

        // get non-composite products for composite component selection
        $products = Product::where('is_composite', false)
            ->where('id', '!=', $product->id)
            ->orderBy('title')
            ->get(['id', 'title', 'sell_price', 'is_composite']);

        return Inertia::render('Dashboard/Products/Edit', [
            'product' => $product->load(['components', 'units', 'warehouses:id,code,name']),
            'categories' => $categories,
            'products' => $products,
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, Product $product)
    {
        if (config('warehouse.is_warehouse')) {
            return $this->updateWarehouseMaterial($request, $product);
        }

        $before = $this->productAuditPayload($product);

        /**
         * validate
         */
        $request->validate([
            'domain' => 'sometimes|required|in:RM,FG',
            'barcode' => 'required|unique:products,barcode,'.$product->id,
            'sku' => 'required|unique:products,sku,'.$product->id,
            'title' => 'required',
            'description' => 'required',
            'category_id' => 'required',
            'buy_price' => 'required',
            'sell_price' => 'required',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ]);

        if ($request->boolean('is_composite')) {
            $this->validateComponentProducts($request, $product);
        } elseif ($product->is_composite && $product->components()->exists()) {
            return back()->withErrors([
                'components' => 'Produk komposit harus memiliki minimal satu komponen.',
            ]);
        }

        // check image update
        if ($request->file('image')) {

            // remove old image
            Storage::disk('local')->delete('public/products/'.basename($product->image));

            // upload new image
            $image = $request->file('image');
            $image->storeAs('public/products', $image->hashName());

            // update product with new image
            $product->update([
                'image' => $image->hashName(),
                'barcode' => $request->barcode,
                'sku' => $request->sku,
                'title' => $request->title,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'domain' => $request->input('domain', $product->domain),
                'buy_price' => $request->buy_price,
                'sell_price' => $request->sell_price,
                'min_stock' => $request->min_stock ?? 0,
                'max_stock' => $request->max_stock ?? 0,
                'tax_type' => $request->input('tax_type', 'exclusive'),
                'tax_rate' => $request->input('tax_rate', 11.00),
                'is_composite' => $request->boolean('is_composite'),
            ]);

            if ($request->boolean('is_composite')) {
                $this->syncComponents($product, $request->input('components'));
            } else {
                $this->syncUnits($product, $request->input('units'));
            }

            $this->logProductUpdate($product, $before);

            return to_route('products.index');
        }

        // update product without image
        $product->update([
            'barcode' => $request->barcode,
            'sku' => $request->sku,
            'title' => $request->title,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'domain' => $request->input('domain', $product->domain),
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
        ]);

        if ($request->boolean('is_composite')) {
            $this->syncComponents($product, $request->input('components'));
        } else {
            $this->syncUnits($product, $request->input('units'));
        }

        $this->logProductUpdate($product, $before);

        // redirect
        return to_route('products.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        // find by ID
        $product = Product::findOrFail($id);
        $before = $this->productAuditPayload($product);

        // remove image
        Storage::disk('local')->delete('public/products/'.basename($product->image));

        // delete
        $product->delete();

        $this->auditLogService->log(
            event: 'product.deleted',
            module: 'products',
            auditable: $product,
            description: 'Produk dihapus.',
            before: $before
        );

        // redirect
        return back();
    }

    /**
     * Same fields as the warehouse create form. Stock stays on the ledger; this only renames the item.
     */
    private function updateWarehouseMaterial(Request $request, Product $product)
    {
        $request->validate([
            'sku' => 'required|string|max:100|unique:products,sku,'.$product->id,
            'title' => 'required|string|max:255',
            'category_id' => 'required_without:category_name|nullable|exists:categories,id',
            'category_name' => 'required_without:category_id|nullable|string|max:100',
            'count_unit' => 'required|in:เส้น,ชิ้น,แผ่น,ม้วน',
            'domain' => 'required|in:RM,FG',
            'weight_per_roll' => 'nullable|numeric|min:0',
        ], [
            'sku.required' => 'ใส่รหัสสินค้า',
            'sku.unique' => 'รหัสนี้มีอยู่แล้ว',
            'title.required' => 'ใส่ชื่อรายการ',
            'category_id.required_without' => 'เลือกหมวด หรือพิมพ์ชื่อหมวดใหม่',
            'category_name.required_without' => 'เลือกหมวด หรือพิมพ์ชื่อหมวดใหม่',
            'count_unit.required' => 'เลือกหน่วยนับ',
        ]);

        $before = $this->productAuditPayload($product);
        $oldSku = $product->sku;
        $categoryName = trim((string) $request->input('category_name', ''));
        $category = $categoryName !== ''
            ? Category::query()->firstOrCreate(
                ['name' => $categoryName],
                ['image' => 'rm.png', 'description' => 'Raw materials']
            )
            : Category::query()->findOrFail($request->category_id);

        $uom = $this->countUnit($request->count_unit);
        $weight = $request->filled('weight_per_roll') ? (float) $request->weight_per_roll : null;
        $sku = trim((string) $request->sku);

        if ($sku !== $oldSku && PhysicalUnit::query()->where('unit_code', $sku)->exists()) {
            return back()->withErrors(['sku' => 'รหัสนี้ใช้กับหน่วยสแกนของรายการอื่นอยู่แล้ว']);
        }

        $recode = $product->barcode === $oldSku || $product->barcode === null || $product->barcode === '';
        if ($recode && Product::query()->where('barcode', $sku)->whereKeyNot($product->id)->exists()) {
            return back()->withErrors(['sku' => 'รหัสนี้มีอยู่แล้ว']);
        }

        $product->update([
            'barcode' => ($product->barcode === $oldSku || $product->barcode === null || $product->barcode === '') ? $sku : $product->barcode,
            'sku' => $sku,
            'title' => $request->title,
            'description' => $request->title,
            'category_id' => $category->id,
            'domain' => $request->input('domain'),
            'base_uom' => $uom['base'],
            'stock_calc_strategy' => $uom['strategy'],
            'tracks_physical_units' => true,
            'spoolman_enabled' => $uom['base'] === 'ROLL' && $weight !== null && $weight > 0,
            'standard_weight_kg' => $uom['base'] === 'ROLL' ? $weight : null,
        ]);

        PhysicalUnit::query()
            ->where('product_id', $product->id)
            ->where('unit_code', $oldSku)
            ->update([
                'unit_code' => $sku,
                'unit_type' => $uom['unit_type'],
                'weight_uom' => $uom['base'],
            ]);

        $this->logProductUpdate($product, $before);

        return to_route('products.index');
    }

    /**
     * Warehouse materials: name, SKU, category, count unit, warehouse and opening qty.
     * The opening qty lands on an ACTIVE unit coded as the SKU, so the floor can cut it immediately.
     */
    private function storeWarehouseMaterial(Request $request)
    {
        $request->validate([
            'sku' => 'required|string|max:100|unique:products,sku',
            'title' => 'required|string|max:255',
            'category_id' => 'required_without:category_name|nullable|exists:categories,id',
            'category_name' => 'required_without:category_id|nullable|string|max:100',
            'count_unit' => 'required|in:เส้น,ชิ้น,แผ่น,ม้วน',
            'domain' => 'required|in:RM,FG',
            'stock' => 'required|integer|min:0',
            'warehouse_id' => 'required|exists:warehouses,id',
            'weight_per_roll' => 'nullable|numeric|min:0',
        ], [
            'sku.required' => 'ใส่รหัสสินค้า',
            'sku.unique' => 'รหัสนี้มีอยู่แล้ว',
            'title.required' => 'ใส่ชื่อรายการ',
            'category_id.required_without' => 'เลือกหมวด หรือพิมพ์ชื่อหมวดใหม่',
            'category_name.required_without' => 'เลือกหมวด หรือพิมพ์ชื่อหมวดใหม่',
            'count_unit.required' => 'เลือกหน่วยนับ',
            'domain.required' => 'เลือก RM หรือ FG',
            'stock.required' => 'ใส่จำนวน',
            'warehouse_id.required' => 'เลือกโกดัง',
        ]);

        $warehouse = Warehouse::query()->findOrFail($request->warehouse_id);
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $categoryName = trim((string) $request->input('category_name', ''));
        $category = $categoryName !== ''
            ? Category::query()->firstOrCreate(
                ['name' => $categoryName],
                ['image' => 'rm.png', 'description' => 'Raw materials']
            )
            : Category::query()->findOrFail($request->category_id);

        $uom = $this->countUnit($request->count_unit);
        $weight = $request->filled('weight_per_roll') ? (float) $request->weight_per_roll : null;
        $sku = trim((string) $request->sku);

        $product = Product::query()->create([
            'image' => 'product.png',
            'barcode' => $sku,
            'sku' => $sku,
            'title' => $request->title,
            'description' => $request->title,
            'category_id' => $category->id,
            'domain' => $request->input('domain'),
            'base_uom' => $uom['base'],
            'stock_calc_strategy' => $uom['strategy'],
            'tracks_physical_units' => true,
            'spoolman_enabled' => $uom['base'] === 'ROLL' && $weight !== null && $weight > 0,
            'standard_weight_kg' => $uom['base'] === 'ROLL' ? $weight : null,
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => 0,
            'min_stock' => 0,
            'max_stock' => 0,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
            'is_composite' => false,
        ]);

        $unit = $this->postOpeningStock(
            $product,
            $warehouse->id,
            (int) $request->stock,
            $request->user()?->id,
            'เพิ่มรายการเข้าโกดัง',
        );

        if ($unit && ! PhysicalUnit::query()->where('unit_code', $sku)->whereKeyNot($unit->id)->exists()) {
            $unit->unit_code = $sku;
            $unit->lot_code = 'OPENING';
            $unit->unit_type = $uom['unit_type'];
            $unit->save();
        }

        $product->refresh();
        $this->stockMutationService->recordInitialStock($product, $request->user()?->id, $warehouse->id);
        $this->auditLogService->log(
            event: 'product.created',
            module: 'products',
            auditable: $product,
            description: $request->input('domain') === 'FG' ? 'เพิ่มสินค้าสำเร็จรูป' : 'เพิ่มวัตถุดิบ',
            after: $this->productAuditPayload($product->fresh())
        );

        return to_route('products.index');
    }

    /**
     * @return array{base:string, strategy:StockCalcStrategy, unit_type:string}
     */
    private function countUnit(string $thai): array
    {
        return match ($thai) {
            'ม้วน' => ['base' => 'ROLL', 'strategy' => StockCalcStrategy::QtyBased, 'unit_type' => 'ROLL'],
            'แผ่น' => ['base' => 'SHEET', 'strategy' => StockCalcStrategy::SheetBased, 'unit_type' => 'SHEET_PACK'],
            default => ['base' => 'PCS', 'strategy' => StockCalcStrategy::PieceBased, 'unit_type' => 'OTHER'],
        };
    }

    private function compositeRules(): array
    {
        return [
            'is_composite' => 'nullable|boolean',
            'components' => 'required_if:is_composite,1,true|array|min:1',
            'components.*.component_product_id' => 'required|integer|distinct|exists:products,id',
            'components.*.qty' => 'required|integer|min:1',
        ];
    }

    private function validateComponentProducts(Request $request, ?Product $product = null): void
    {
        $componentIds = collect($request->input('components'))
            ->pluck('component_product_id')
            ->unique();

        if ($product && $componentIds->contains($product->id)) {
            abort(422, 'Produk tidak bisa menjadi komponen dirinya sendiri.');
        }

        $compositeCount = Product::whereIn('id', $componentIds)->where('is_composite', true)->count();

        if ($compositeCount > 0) {
            abort(422, 'Komponen tidak boleh produk komposit lain.');
        }
    }

    private function syncComponents(Product $product, ?array $components): void
    {
        $product->components()->sync(
            collect($components)->mapWithKeys(fn (array $c) => [
                $c['component_product_id'] => ['qty' => (int) $c['qty']],
            ])
        );
    }

    private function unitRules(): array
    {
        return [
            'units' => 'nullable|array',
            'units.*.unit_id' => 'required|integer|distinct|exists:units,id',
            'units.*.is_base' => 'required|boolean',
            'units.*.conversion_factor' => 'required|numeric|min:0.0001',
            'units.*.buy_price' => 'nullable|integer|min:0',
            'units.*.sell_price' => 'nullable|integer|min:0',
            'units.*.barcode' => 'nullable|string|max:255',
        ];
    }

    private function syncUnits(Product $product, ?array $units): void
    {
        $rows = collect($units ?? [])
            ->keyBy('unit_id')
            ->map(fn (array $u) => [
                'is_base' => $u['is_base'],
                'conversion_factor' => $u['conversion_factor'],
                'buy_price' => $u['buy_price'] ?? $product->buy_price,
                'sell_price' => $u['sell_price'] ?? $product->sell_price,
                'barcode' => $u['barcode'] ?? null,
            ]);

        if ($rows->isEmpty()) {
            // ponytail: no units sent — only seed default PCS base when product has none (matches docs/features/unit-conversion.md)
            if ($product->units()->exists()) {
                return;
            }

            $pcs = Unit::where('code', 'PCS')->first();
            if ($pcs) {
                $product->units()->attach($pcs->id, [
                    'is_base' => true,
                    'conversion_factor' => 1,
                    'buy_price' => $product->buy_price,
                    'sell_price' => $product->sell_price,
                ]);
            }

            return;
        }

        if (! $rows->contains(fn ($r) => $r['is_base'])) {
            // ponytail: no explicit base unit sent — treat first row as base with factor 1
            $first = $rows->keys()->first();
            $rows[$first] = ['is_base' => true, 'conversion_factor' => 1] + $rows[$first];
        }

        $product->units()->sync($rows->all());
    }

    private function logProductUpdate(Product $product, array $before): void
    {
        $after = $this->productAuditPayload($product->fresh());

        $this->auditLogService->log(
            event: 'product.updated',
            module: 'products',
            auditable: $product,
            description: 'Data produk diperbarui.',
            before: $before,
            after: $after
        );

        if (
            (int) $before['buy_price'] !== (int) $after['buy_price']
            || (int) $before['sell_price'] !== (int) $after['sell_price']
        ) {
            $this->auditLogService->log(
                event: 'product.price_updated',
                module: 'products',
                auditable: $product,
                description: 'Harga produk diperbarui.',
                before: [
                    'buy_price' => $before['buy_price'],
                    'sell_price' => $before['sell_price'],
                ],
                after: [
                    'buy_price' => $after['buy_price'],
                    'sell_price' => $after['sell_price'],
                ]
            );
        }
    }

    private function productAuditPayload(Product $product): array
    {
        return $this->auditLogService->only($product->toArray(), [
            'title',
            'barcode',
            'sku',
            'buy_price',
            'sell_price',
            'stock',
            'category_id',
            'domain',
            'base_uom',
        ]);
    }
}
