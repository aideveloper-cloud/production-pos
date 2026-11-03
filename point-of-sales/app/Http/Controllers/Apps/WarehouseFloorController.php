<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\PhysicalUnit;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\WarehouseStockMovementService;
use App\Services\OutletAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseFloorController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly WarehouseStockMovementService $movementService,
    ) {}

    public function index(Request $request): Response
    {
        return $this->renderFloor($request, 'Dashboard/WarehouseFloor/Index');
    }

    /**
     * Legacy /pos entry: send the device to its own POS (RM or FG), RM when none yet.
     */
    public function entry(Request $request): RedirectResponse
    {
        $domain = strtolower((string) $request->session()->get('pos.domain', 'RM'));

        return redirect()->route('pos.show', ['domain' => $domain, ...$request->query()]);
    }

    public function pos(Request $request, string $domain): Response|RedirectResponse
    {
        $domain = $this->routeDomain($domain);
        $user = $request->user();
        $sessionWarehouseId = (int) $request->session()->get('pos.warehouse_id', 0);

        if (! $user?->isSuperAdmin() && $sessionWarehouseId <= 0) {
            return Inertia::render('Pos/Unlock', [
                'domain' => $domain,
                'warehouses' => Warehouse::query()
                    ->whereIn('code', ['WH-PHRANON', 'WH-DECHA-MESH', 'WH-DECHA-POST', 'WH-CHOKDEE'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name'])
                    ->map(fn (Warehouse $w) => [
                        'id' => $w->id,
                        'code' => $w->code,
                        'name' => $w->name,
                    ])
                    ->values(),
            ]);
        }

        // A PIN-unlocked device belongs to one POS; it cannot wander into the other one.
        $deviceDomain = $this->deviceDomain($request);
        if ($deviceDomain && $deviceDomain !== $domain) {
            return redirect()->route('pos.show', ['domain' => strtolower($deviceDomain)]);
        }

        return $this->renderFloor($request, 'Pos/Index', $sessionWarehouseId, $domain);
    }

    public function unlock(Request $request, string $domain): RedirectResponse
    {
        $domain = $this->routeDomain($domain);
        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
        ]);

        $pin = $validated['pin'];
        $matched = Warehouse::query()
            ->whereNotNull('pos_pin')
            ->where('is_active', true)
            ->get()
            ->first(fn (Warehouse $warehouse) => Hash::check($pin, (string) $warehouse->pos_pin));

        if (! $matched) {
            throw ValidationException::withMessages([
                'pin' => __('messages.warehouse_floor.invalid_pin'),
            ]);
        }

        $posUser = User::query()->where('email', 'pos@wms.local')->first();
        abort_unless($posUser, 500, 'POS device user missing. Run CompanyUserSeeder.');

        Auth::login($posUser, true);
        $request->session()->regenerate();
        $request->session()->put('pos.warehouse_id', $matched->id);
        $request->session()->put('pos.domain', $domain);
        $request->session()->forget('pos.operator_name');

        return redirect()->route('pos.show', ['domain' => strtolower($domain)]);
    }

    public function lock(Request $request): RedirectResponse
    {
        $domain = strtolower((string) $request->session()->get('pos.domain', 'RM'));
        $request->session()->forget(['pos.warehouse_id', 'pos.operator_name']);

        if ($request->user()?->email === 'pos@wms.local') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('pos.show', ['domain' => $domain]);
    }

    public function lookup(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:200'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'mode' => ['nullable', 'string', 'in:cut,receive'],
        ]);

        $raw = trim($validated['code']);
        $mode = $validated['mode'] ?? 'cut';
        $warehouseId = (int) ($validated['warehouse_id']
            ?? $request->session()->get('pos.warehouse_id')
            ?? 0);

        // A PIN-unlocked device only sees its own warehouse, whatever warehouse_id it sends.
        $lockedWarehouseId = (int) $request->session()->get('pos.warehouse_id', 0);
        if ($lockedWarehouseId > 0 && ! $user->isSuperAdmin()) {
            $warehouseId = $lockedWarehouseId;
        }

        $spoolmanId = null;
        if (preg_match('/WEB\+SPOOLMAN:S-(\d+)/i', $raw, $m)
            || preg_match('/\/spool\/show\/(\d+)/i', $raw, $m)
            || preg_match('/^S-(\d+)$/i', $raw, $m)
            || preg_match('/^(\d{1,9})$/', $raw, $m)) {
            $spoolmanId = (int) $m[1];
        }

        $unitQuery = PhysicalUnit::query()
            ->with(['product:id,sku,title,domain,base_uom,barcode'])
            ->where('status', 'ACTIVE');

        if ($warehouseId > 0) {
            $unitQuery->where('warehouse_id', $warehouseId);
        } else {
            $allowed = $this->outletAccessService->warehousesFor($user)->pluck('id');
            $unitQuery->whereIn('warehouse_id', $allowed);
        }

        $unit = null;
        if ($spoolmanId) {
            $unit = (clone $unitQuery)->where('spoolman_spool_id', $spoolmanId)->first();
            if (! $unit) {
                $padded = sprintf('SP-%06d', $spoolmanId);
                $unit = (clone $unitQuery)->where('unit_code', $padded)->first();
            }
        }

        if (! $unit) {
            $unit = (clone $unitQuery)
                ->whereRaw('UPPER(unit_code) = ?', [strtoupper($raw)])
                ->first();
        }

        if ($unit) {
            $warehouse = Warehouse::query()->find($unit->warehouse_id);
            abort_unless($warehouse && $this->outletAccessService->canUseWarehouse($user, $warehouse), 403);
            $this->assertPosDomain($request, (string) $unit->product?->domain);

            return response()->json([
                'type' => 'unit',
                'unit' => [
                    'id' => $unit->id,
                    'unit_code' => $unit->unit_code,
                    'actual_weight' => $unit->actual_weight,
                    'weight_uom' => $unit->weight_uom,
                    'lot_code' => $unit->lot_code,
                    'spoolman_spool_id' => $unit->spoolman_spool_id,
                    'sku' => $unit->product?->sku,
                    'domain' => $unit->product?->domain,
                    'product_id' => $unit->product_id,
                    'product_title' => $unit->product?->title,
                    'base_uom' => $unit->product?->base_uom,
                ],
            ]);
        }

        if ($mode === 'receive') {
            $product = Product::query()
                ->where(function ($q) use ($raw) {
                    $q->whereRaw('UPPER(sku) = ?', [strtoupper($raw)])
                        ->orWhereRaw('UPPER(COALESCE(barcode, "")) = ?', [strtoupper($raw)]);
                })
                ->first(['id', 'sku', 'title', 'domain', 'base_uom', 'barcode']);

            if ($product) {
                $this->assertPosDomain($request, (string) $product->domain);

                return response()->json([
                    'type' => 'product',
                    'product' => $product,
                ]);
            }
        }

        return response()->json([
            'type' => 'none',
            'message' => __('messages.warehouse_floor.scan_not_found'),
        ], 404);
    }

    private function renderFloor(Request $request, string $page, ?int $lockedWarehouseId = null, ?string $posDomain = null): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $warehouses = $this->outletAccessService->warehousesFor($user);

        if ($lockedWarehouseId && ! $user->isSuperAdmin()) {
            $warehouses = $warehouses->where('id', $lockedWarehouseId)->values();
        }

        // The admin floor opens on every warehouse at once (?warehouse_id=all, the default) so nothing
        // looks missing just because it sits in another warehouse; the tablet POS stays per warehouse.
        $allWarehouses = $page === 'Dashboard/WarehouseFloor/Index'
            && $warehouses->count() > 1
            && in_array((string) $request->input('warehouse_id', 'all'), ['', 'all'], true);

        $warehouseId = (int) $request->integer('warehouse_id');
        if ($lockedWarehouseId && ! $user->isSuperAdmin()) {
            $warehouseId = $lockedWarehouseId;
        } elseif ($warehouseId <= 0) {
            $warehouseId = (int) ($warehouses->first()?->id ?? 0);
        }

        $warehouse = $warehouses->firstWhere('id', $warehouseId);
        abort_unless($warehouse, 403);
        $scopeIds = $allWarehouses ? $warehouses->pluck('id')->all() : [$warehouse->id];
        $warehouseNames = $warehouses->pluck('name', 'id');

        $units = PhysicalUnit::query()
            ->with(['product:id,sku,title,domain,base_uom,spoolman_enabled,stock_calc_strategy,standard_weight_kg'])
            ->whereIn('warehouse_id', $scopeIds)
            ->where('status', 'ACTIVE')
            ->where('actual_weight', '>', 0)
            ->when($posDomain, fn ($query) => $query->whereHas('product', fn ($q) => $q->where('domain', $posDomain)))
            ->orderBy('unit_code')
            ->limit($allWarehouses ? 1500 : 400)
            ->get()
            ->map(fn (PhysicalUnit $unit) => [
                'id' => $unit->id,
                'warehouse_id' => $unit->warehouse_id,
                'warehouse_name' => $warehouseNames[$unit->warehouse_id] ?? null,
                'unit_code' => $unit->unit_code,
                'actual_weight' => $unit->actual_weight,
                'weight_uom' => $unit->weight_uom,
                'lot_code' => $unit->lot_code,
                'spoolman_spool_id' => $unit->spoolman_spool_id,
                'sku' => $unit->product?->sku,
                'domain' => $unit->product?->domain,
                'product_id' => $unit->product_id,
                'product_title' => $unit->product?->title,
                'base_uom' => $unit->product?->base_uom,
                'standard_weight_kg' => $unit->product?->standard_weight_kg,
                'weight_kg' => $this->unitWeightKg($unit),
            ]);

        $products = Product::query()
            ->where(function ($q) {
                $q->whereIn('domain', ['RM', 'FG'])
                    ->orWhere('tracks_physical_units', true)
                    ->orWhere('spoolman_enabled', true);
            })
            ->where('is_composite', false)
            ->when($posDomain, fn ($query) => $query->where('domain', $posDomain))
            ->where(function ($q) use ($scopeIds) {
                $q->whereHas('warehouses', fn ($q) => $q->whereIn('warehouses.id', $scopeIds))
                    ->orWhere(function ($q) {
                        $q->whereIn('domain', ['RM', 'FG'])->where('tracks_physical_units', true);
                    });
            })
            ->with(['warehouses' => fn ($q) => $q->select('warehouses.id')->whereIn('warehouses.id', $scopeIds)])
            ->orderBy('sku')
            ->limit(500)
            ->get(['id', 'sku', 'title', 'domain', 'base_uom', 'spoolman_enabled', 'tracks_physical_units'])
            // Lets the all-warehouses admin view preselect where a product is received into.
            ->map(fn (Product $product) => [
                ...$product->only(['id', 'sku', 'title', 'domain', 'base_uom', 'spoolman_enabled', 'tracks_physical_units']),
                'warehouse_ids' => $product->warehouses->pluck('id')->values()->all(),
            ]);

        return Inertia::render($page, [
            'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
            ])->values(),
            'currentWarehouseId' => $allWarehouses ? null : $warehouse->id,
            'units' => $units,
            'products' => $products,
            'isAdmin' => $user->isSuperAdmin(),
            'presetsKg' => [1, 2, 5, 10, 20],
            'operatorName' => (string) $request->session()->get('pos.operator_name', ''),
            'requiresOperatorName' => true,
            'posDomain' => $posDomain,
        ]);
    }

    public function receive(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'uom' => ['nullable', 'string', 'max:20'],
            'unit_code' => ['nullable', 'string', 'max:50'],
            'physical_unit_id' => ['nullable', 'integer', 'exists:physical_units,id'],
            'lot_code' => ['nullable', 'string', 'max:100'],
            'create_unit' => ['nullable', 'boolean'],
            'operator_name' => ['required', 'string', 'min:2', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertPosWarehouse($request, (int) $validated['warehouse_id']);
        $product = Product::query()->findOrFail((int) $validated['product_id']);
        $this->assertPosDomain($request, $product->domain);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $operator = $this->requireOperator($request, $validated);
        $validated['notes'] = trim(($validated['notes'] ?? '').' | ผู้ทำรายการ: '.$operator);
        $validated['operator_name'] = $operator;

        $result = $this->movementService->receive($validated, $request->user());
        $unit = $result['physical_unit']?->load(['product:id,sku,title', 'warehouse:id,name']);

        return back()->with([
            'success' => __('messages.warehouse_floor.receive_posted', [
                'qty' => (float) $result['ledger']->qty,
                'uom' => $result['ledger']->uom,
            ]),
            'last_unit' => $unit ? [
                'id' => $unit->id,
                'unit_code' => $unit->unit_code,
                'actual_weight' => $unit->actual_weight,
                'weight_uom' => $unit->weight_uom,
                'lot_code' => $unit->lot_code,
                'spoolman_spool_id' => $unit->spoolman_spool_id,
                'sku' => $unit->product?->sku,
                'product_title' => $unit->product?->title,
                'warehouse_name' => $unit->warehouse?->name,
                'barcode' => $unit->unit_code,
            ] : null,
        ]);
    }

    public function cut(Request $request)
    {
        $validated = $request->validate([
            'physical_unit_id' => ['required', 'integer', 'exists:physical_units,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'uom' => ['nullable', 'string', 'max:20'],
            'operator_name' => ['required', 'string', 'min:2', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $unit = PhysicalUnit::query()->findOrFail($validated['physical_unit_id']);
        $this->assertPosWarehouse($request, (int) $unit->warehouse_id);
        $this->assertPosDomain($request, (string) $unit->product()->value('domain'));

        $warehouse = Warehouse::query()->findOrFail($unit->warehouse_id);
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $operator = $this->requireOperator($request, $validated);
        $validated['notes'] = trim(($validated['notes'] ?? '').' | ผู้ทำรายการ: '.$operator);
        $validated['operator_name'] = $operator;
        $validated['meta'] = [
            'source' => 'warehouse_floor',
            'operator_name' => $operator,
        ];

        $result = $this->movementService->cut($validated, $request->user());

        return back()->with(
            'success',
            __('messages.warehouse_floor.cut_posted', [
                'qty' => abs((float) $result['ledger']->qty),
                'uom' => $result['ledger']->uom,
            ])
        );
    }

    private function assertPosDomain(Request $request, ?string $productDomain): void
    {
        if (! $request->routeIs('pos.receive', 'pos.cut', 'pos.lookup')) {
            return;
        }

        $domain = $this->routeDomain((string) $request->route('domain'));
        $deviceDomain = $this->deviceDomain($request);
        if ($deviceDomain && $deviceDomain !== $domain) {
            abort(403, 'POS device is locked to '.$deviceDomain.'.');
        }

        if ($productDomain !== $domain) {
            $other = $productDomain === 'FG' ? 'สินค้าสำเร็จรูป (FG)' : 'วัตถุดิบ (RM)';
            throw ValidationException::withMessages([
                'domain' => 'นี่คือ POS '.$domain.' รายการนี้เป็น'.$other.' ให้ใช้ POS '.($domain === 'RM' ? 'FG' : 'RM'),
            ]);
        }
    }

    private function routeDomain(string $domain): string
    {
        $domain = strtoupper($domain);
        abort_unless(in_array($domain, ['RM', 'FG'], true), 404);

        return $domain;
    }

    /**
     * The POS a PIN-unlocked device was opened as; null for admins and dashboard users.
     */
    private function deviceDomain(Request $request): ?string
    {
        if ($request->user()?->isSuperAdmin() || (int) $request->session()->get('pos.warehouse_id', 0) <= 0) {
            return null;
        }

        $domain = (string) $request->session()->get('pos.domain', '');

        return in_array($domain, ['RM', 'FG'], true) ? $domain : null;
    }

    private function assertPosWarehouse(Request $request, int $warehouseId): void
    {
        if ($request->user()?->isSuperAdmin()) {
            return;
        }

        $locked = (int) $request->session()->get('pos.warehouse_id', 0);
        if ($locked > 0 && $locked !== $warehouseId) {
            abort(403, 'Warehouse locked by POS PIN.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function requireOperator(Request $request, array $validated): string
    {
        $operator = trim((string) ($validated['operator_name'] ?? ''));
        if (mb_strlen($operator) < 2) {
            throw ValidationException::withMessages([
                'operator_name' => 'กรุณาใส่ชื่อผู้ทำรายการก่อนตัดหรือรับเข้า',
            ]);
        }

        $request->session()->put('pos.operator_name', $operator);

        return $operator;
    }

    private function unitWeightKg(PhysicalUnit $unit): ?string
    {
        $qty = (float) ($unit->actual_weight ?? 0);
        $uom = strtoupper((string) ($unit->weight_uom ?: $unit->product?->base_uom ?: ''));
        if (in_array($uom, ['KG', 'KILOGRAM', 'KILOGRAMS'], true)) {
            return number_format($qty, 4, '.', '');
        }
        if ($unit->product?->standard_weight_kg === null) {
            return null;
        }

        return number_format($qty * (float) $unit->product->standard_weight_kg, 4, '.', '');
    }
}
