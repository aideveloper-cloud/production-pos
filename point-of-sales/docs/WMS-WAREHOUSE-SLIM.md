# Warehouse Stock App — Sprint notes

Implemented from plan `WMS Warehouse Slim`:

1. `APP_MODE=warehouse` + `config/warehouse.php`
2. Middleware `BlockRetailInWarehouseMode`, `EnsureWarehouseScope`
3. Slim sidebar (retail sections hidden) + Stock Floor link
4. `/dashboard` warehouse KPIs
5. `/dashboard/warehouse-floor` Receive / Cut → `stock_ledgers` (+ Spoolman patch when enabled)
6. LAN runbook in root `README.md`
