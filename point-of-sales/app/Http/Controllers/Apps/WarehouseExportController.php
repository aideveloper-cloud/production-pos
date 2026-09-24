<?php

namespace App\Http\Controllers\Apps;

use App\Exports\Warehouse\MaterialsExport;
use App\Exports\Warehouse\PhysicalUnitsExport;
use App\Exports\Warehouse\StockBalancesExport;
use App\Exports\Warehouse\StockLedgerExport;
use App\Http\Controllers\Controller;
use App\Services\Inventory\StockLedgerFilter;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin downloads (Excel or CSV via ?format=csv). Every export is limited to the warehouses the
 * user may access; the ledger export applies the same filters as the history page.
 */
class WarehouseExportController extends Controller
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function materials(Request $request): BinaryFileResponse
    {
        return $this->download($request, new MaterialsExport($this->warehouseIds($request)), 'materials');
    }

    public function units(Request $request): BinaryFileResponse
    {
        return $this->download($request, new PhysicalUnitsExport(
            $this->warehouseIds($request),
            $request->integer('warehouse_id') ?: null,
            $request->string('status')->upper()->value() ?: null,
        ), 'units');
    }

    public function balances(Request $request): BinaryFileResponse
    {
        return $this->download($request, new StockBalancesExport(
            $this->warehouseIds($request),
            $request->integer('warehouse_id') ?: null,
        ), 'stock-balances');
    }

    public function stockLedgers(Request $request): BinaryFileResponse
    {
        return $this->download($request, new StockLedgerExport(
            StockLedgerFilter::fromRequest($request),
            $this->warehouseIds($request),
        ), 'stock-movements');
    }

    /**
     * @return array<int, int>
     */
    private function warehouseIds(Request $request): array
    {
        return $this->outletAccessService->warehousesFor($request->user())->pluck('id')->all();
    }

    private function download(Request $request, object $export, string $name): BinaryFileResponse
    {
        $csv = $request->query('format') === 'csv';

        return Excel::download(
            $export,
            $name.'-'.now()->format('Ymd-His').($csv ? '.csv' : '.xlsx'),
            $csv ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
