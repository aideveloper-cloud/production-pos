# WMS + Spoolman — Sprint A (implemented)

Branch: `feature/wms-spoolman-integration-v1.1`

## What landed

- WMS columns on `products` (`domain`, `base_uom`, `stock_calc_strategy`, `spoolman_enabled`, …)
- Tables: `warehouse_locations`, `product_spoolman_mappings`, `physical_units`, `stock_ledgers`, `spoolman_consumption_events`, `weight_reconciliations`
- Services: `StockLedgerService`, `StockCalculationService`, `SpoolmanClient`, `SpoolConsumptionService`
- Inbound: `POST /api/integrations/spoolman/events` (Bearer `SPOOLMAN_INTEGRATION_TOKEN`)
- Proxy: `GET /api/v1/warehouses/{warehouse}/spools`

Full contract: workspace `docs/WMS-SPOOLMAN-INTEGRATION-v1.1.md`
