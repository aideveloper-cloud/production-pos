# WMS + Spoolman Integration Specification v1.1

**Status:** Draft for implementation  
**Base systems:**
- WMS / Inventory Core → [aideveloper-cloud/point-of-sales](https://github.com/aideveloper-cloud/point-of-sales.git) (fork of aryadwiputra/point-of-sales; Laravel + React)
- Spool Service → [aideveloper-cloud/Spoolman](https://github.com/aideveloper-cloud/Spoolman.git) (fork of Donkie/Spoolman; separate service; REST + WebSocket)

---

## 1. Purpose

Integrate Spoolman as a **Specialized Calculation / Tracking Engine** for physical spool/container weight and printer consumption — **not** as company Stock Master.

| System | Role |
|--------|------|
| WMS (`point-of-sales` evolved) | Company stock owner: SKU, warehouse, location, permission, receive/issue/transfer/count/adjustment, **stock_ledger = Source of Truth** |
| Spoolman | Physical spool identity, current weight, printer consumption telemetry |
| Integration Layer | Mapping, validation, event translation, reconciliation |

**Non-goals (v1.1):**
- Merging Spoolman source into Laravel modules
- Exposing Spoolman UI/API directly to warehouse users (no built-in auth)
- Making Spoolman totals authoritative over `stock_ledger`

---

## 2. Ownership Matrix

| Data | Owner |
|------|-------|
| SKU / Item master | WMS |
| Warehouse / Location | WMS |
| User / Permission | WMS |
| Receive / Issue / Transfer / Count / Adjustment | WMS |
| `stock_ledger` / posted balances | **WMS** |
| Physical spool / roll / container | Spoolman *or* WMS `physical_units` (see §4) |
| Spool current weight | Spoolman (filament) / WMS scale entry (non-filament) |
| Printer consumption telemetry | Spoolman |
| Company on-hand stock | **WMS** (via ledger) |

---

## 3. Architecture

```text
User ──► WMS Login + WH permission
              │
              ▼
     ┌────────────────────┐
     │  WMS (Laravel)     │
     │  Inventory Core    │
     │  stock_ledger SOT  │
     └─────────┬──────────┘
               │ Integration API (service account)
               ▼
     ┌────────────────────┐
     │ Spool Adapter      │
     │ + Weight Engine    │
     │ + Calculation Eng. │
     └─────────┬──────────┘
               │ REST / WebSocket (internal network only)
               ▼
     ┌────────────────────┐
     │ Spoolman           │
     │ Filament / Spool   │
     └─────────┬──────────┘
               │
               ▼
         Printers (Moonraker / OctoPrint)
```

**Security rule:** Spoolman listens on private network only. All human access goes through WMS permission checks (`warehouse_id` scope).

---

## 4. Domain Model (WMS tables)

### 4.1 `items` (extension)

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid/bigint | PK |
| `sku` | string unique | e.g. `RM-PLA-BLACK`, `RM-B70` |
| `domain` | enum | `RM` \| `FG` |
| `base_uom` | string | **required** — never assume kg |
| `secondary_uom` | string nullable | e.g. ROLL, SHEET, PCS |
| `stock_calc_strategy` | enum | see §6 |
| `tracks_physical_units` | bool | default false |
| `spoolman_enabled` | bool | true only for filament-like items |

### 4.2 `item_spoolman_mappings`

Maps WMS SKU ↔ Spoolman Filament.

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `item_id` | FK → items | |
| `spoolman_filament_id` | int | Spoolman filament.id |
| `spoolman_vendor_name` | string nullable | denormalized for display |
| `spoolman_material` | string nullable | PLA, PETG, … |
| `spoolman_color_hex` | string nullable | |
| `weight_uom` | string | usually `g` in Spoolman; convert to item base_uom |
| `is_active` | bool | |
| `synced_at` | timestamp nullable | |

**Unique:** `(item_id)` active one-to-one preferred in v1.1 (1 SKU → 1 filament type). Multi-filament per SKU = future.

### 4.3 `physical_units`

Generic container tracking (spool, roll, drum, sheet pack). Works **with or without** Spoolman.

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `unit_code` | string unique | e.g. `SP-000123`, `R001` |
| `item_id` | FK → items | |
| `warehouse_id` | FK | |
| `location_id` | FK nullable | |
| `lot_code` | string nullable | |
| `unit_type` | enum | `SPOOL` \| `ROLL` \| `DRUM` \| `SHEET_PACK` \| `OTHER` |
| `status` | enum | `ACTIVE` \| `QUARANTINE` \| `DEPLETED` \| `SCRAPPED` \| `TRANSFERRED_OUT` |
| `nominal_qty` | decimal nullable | e.g. 1 ROLL or nominal kg |
| `nominal_weight` | decimal nullable | standard / label weight |
| `actual_weight` | decimal nullable | last known actual |
| `weight_uom` | string | usually same as item weight secondary or base |
| `weight_source` | enum | `MANUAL` \| `SCALE` \| `SPOOLMAN` \| `IMPORT` \| `ESTIMATED` |
| `weight_verified_at` | timestamp nullable | |
| `spoolman_spool_id` | int nullable | set when linked |
| `opened_at` | timestamp nullable | |
| `depleted_at` | timestamp nullable | |
| `meta` | json nullable | printer_id, notes, … |
| `created_at` / `updated_at` | timestamps | |

**Indexes:** `(item_id, warehouse_id, status)`, `(spoolman_spool_id)`, `(lot_code)`.

### 4.4 `spoolman_consumption_events`

Inbound raw + processed events (idempotent).

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `event_id` | string unique | idempotency key from adapter |
| `spoolman_spool_id` | int | |
| `physical_unit_id` | FK nullable | resolved after map |
| `item_id` | FK | |
| `warehouse_id` | FK | |
| `qty_consumed` | decimal | in item base_uom (after conversion) |
| `qty_uom` | string | |
| `weight_before` | decimal nullable | |
| `weight_after` | decimal nullable | |
| `occurred_at` | timestamp | |
| `payload` | json | raw Spoolman/WS payload |
| `process_status` | enum | `PENDING` \| `POSTED` \| `REJECTED` \| `DUPLICATE` |
| `stock_ledger_id` | FK nullable | set when posted |
| `reject_reason` | string nullable | |
| `created_at` | timestamp | |

### 4.5 `weight_reconciliations`

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `item_id` | FK | |
| `warehouse_id` | FK | |
| `as_of` | timestamp | |
| `ledger_expected_weight` | decimal | from stock strategy |
| `physical_sum_weight` | decimal | Σ physical_units.actual_weight ACTIVE |
| `spoolman_sum_weight` | decimal nullable | |
| `variance_weight` | decimal | physical − expected (sign convention documented in UI) |
| `dq_status` | enum | `OK` \| `WARN` \| `REVIEW` \| `BLOCK` |
| `resolution` | enum nullable | `NONE` \| `ADJUSTMENT` \| `COUNT` \| `IGNORED` |
| `adjustment_id` | FK nullable | |

---

## 5. Integration API Contracts

Base path (WMS): `/api/integrations/spoolman/...`  
Auth: service token / internal mTLS. **Never** end-user Spoolman credentials.

### 5.1 WMS → Spoolman (outbound adapter)

| Action | Spoolman API (conceptual) | When |
|--------|---------------------------|------|
| Ensure filament exists | `POST/PATCH /api/v1/filament` | SKU mapped / receive first spool |
| Create spool | `POST /api/v1/spool` | Receive / register physical unit |
| Update location | `PATCH /api/v1/spool/{id}` | Transfer in WMS |
| Archive / empty | `PATCH` or use Spoolman empty flows | Deplete / scrap |

Exact field names follow [Spoolman OpenAPI](https://donkie.github.io/Spoolman/). Adapter owns unit conversion (g ↔ kg).

### 5.2 Spoolman → WMS (inbound)

#### Event: `SPOOL_CONSUMPTION`

```json
{
  "event": "SPOOL_CONSUMPTION",
  "event_id": "sm-con-20260922-0001847",
  "occurred_at": "2026-09-22T03:14:22Z",
  "spool_id": 123,
  "sku": "RM-PLA-BLACK",
  "warehouse_id": "WH-01",
  "qty": 0.4,
  "uom": "KG",
  "weight_before": 12.5,
  "weight_after": 12.1,
  "source": "spoolman_websocket"
}
```

#### Event: `SPOOL_WEIGHT_SYNC`

Full/partial snapshot for reconciliation (cron or WS batch).

```json
{
  "event": "SPOOL_WEIGHT_SYNC",
  "event_id": "sm-sync-20260922-hour09",
  "as_of": "2026-09-22T09:00:00Z",
  "spools": [
    {
      "spool_id": 123,
      "sku": "RM-PLA-BLACK",
      "warehouse_id": "WH-01",
      "current_weight": 12.1,
      "uom": "KG",
      "status": "ACTIVE"
    }
  ]
}
```

### 5.3 WMS user-facing proxy (permissioned)

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/warehouses/{wh}/spools` | `warehouse:{wh}:read` |
| GET | `/api/warehouses/{wh}/spools/{unitCode}` | same |
| POST | `/api/warehouses/{wh}/spools` | `warehouse:{wh}:receive` |
| POST | `/api/items/{sku}/reconcile-weight` | `inventory:reconcile` |

User never calls Spoolman. Cross-warehouse access → **403**.

### 5.4 Posting pipeline (immutable ledger)

```text
Spoolman event
  → Adapter normalize + convert UOM
  → Idempotency check (event_id)
  → Resolve physical_unit + item + warehouse
  → Validate (ACTIVE, mapped, qty > 0, WH match)
  → Begin DB transaction
       INSERT spoolman_consumption_events (PENDING)
       POST stock_ledger ISSUE/CONSUMPTION (immutable)
       UPDATE physical_units.actual_weight
       UPDATE stock_balance (derived / materialized)
       MARK event POSTED + stock_ledger_id
  → Commit
```

**Rules:**
- Posted ledger rows are immutable; corrections = new reversing + new posting.
- Rejected events stay auditable (`REJECTED` + reason).
- Do not “fix” Spoolman weight by rewriting WMS history silently.

---

## 6. Calculation Engine

Separate from Stock module. Strategies selected per item via `stock_calc_strategy`.

### 6.1 Strategies

| Strategy | Typical item | Primary on-hand |
|----------|--------------|-----------------|
| `QTY_BASED` | RM-W40-W (rolls counted) | ledger qty in base_uom |
| `ACTUAL_WEIGHT_BASED` | RM-B70 (nominal ≠ actual) | Σ `physical_units.actual_weight` ACTIVE |
| `SPOOL_WEIGHT_BASED` | FILAMENT-PLA | Σ Spoolman/current physical spool weights |
| `PIECE_BASED` | RM-PIPE | ledger pcs |
| `SHEET_BASED` | RM-PLATE | ledger sheets (+ optional area) |

### 6.2 Core formulas

```text
# Ledger (always authoritative for financial / company stock movements)
OnHandQty(item, wh) =
  SUM(stock_ledger.signed_qty) WHERE item, warehouse, posted

# Physical aggregates
PhysicalActualWeight(item, wh) =
  SUM(physical_units.actual_weight)
  WHERE item, warehouse, status = ACTIVE
    AND actual_weight IS NOT NULL

SpoolWeight(item, wh) =
  SUM(linked spool current_weight)   -- from Spoolman sync / physical_units

# Reporting measures (all may coexist)
OnHandStandardWeight = OnHandQty × standard_weight_per_base_uom
OnHandActualWeight   = PhysicalActualWeight   -- or weighted average × qty
OnHandSpoolWeight    = SpoolWeight
AvailableQty         = OnHandQty − reserved_qty
AvailableWeight      = f(strategy)            -- see below
VarianceWeight       = PhysicalActualWeight − OnHandStandardWeight
                       -- or SpoolWeight − ledger-implied weight
```

### 6.3 `AvailableWeight` by strategy

```text
QTY_BASED:
  AvailableWeight = AvailableQty × standard_weight_kg   -- if weight secondary exists

ACTUAL_WEIGHT_BASED:
  AvailableWeight = Σ actual_weight (ACTIVE, not reserved)

SPOOL_WEIGHT_BASED:
  AvailableWeight = Σ spool.current_weight (ACTIVE)
  # Optional hybrid:
  #   = SpoolWeight + ledger_weight_of_non_spooled_stock
```

### 6.4 Example — filament

```text
SKU RM-PLA-BLACK @ WH-01
  Spool A 12.5 + B 8.2 + C 19.1 + D 3.7 = 43.5 kg

strategy = SPOOL_WEIGHT_BASED
→ AvailableWeight = 43.5 kg
→ Consumption 0.4 kg on A → event → ledger ISSUE 0.4 KG → A = 12.1
```

### 6.5 Example — RM-B70 DQ REVIEW case

```text
Nominal 1,000 kg/roll vs historical actual ~670.9 kg/roll
→ tracks_physical_units = true
→ stock_calc_strategy = ACTUAL_WEIGHT_BASED
→ weight_source / weight_verified_at required for DQ OK
→ Variance opens REVIEW when |variance| / expected > threshold
```

---

## 7. Receive / Transfer / Count (WMS owns documents)

### 7.1 Receive filament

1. User creates Receive in WMS (permissioned).
2. Lines may include `create_physical_units[]` with nominal/actual weight.
3. On post receive:
   - Insert `physical_units`
   - Adapter creates Spoolman spool (if `spoolman_enabled`)
   - Store `spoolman_spool_id`
   - Ledger RECEIVE qty/weight per item strategy

### 7.2 Transfer

1. WMS transfer document posts ledger + moves `physical_units.warehouse_id/location_id`.
2. Adapter PATCHes Spoolman location fields (display only; WMS remains authority).

### 7.3 Stock count

1. Count against ledger + optional scale of physical units.
2. Variance → Adjustment workflow → new ledger rows (never mutate posted).

---

## 8. Reconciliation & Data Quality

```text
Expected (ledger strategy)  vs  Actual (Σ physical / Spoolman)
Difference → weight_reconciliations.dq_status
```

Suggested thresholds (configurable):

| |dq_status| Condition |
|--|---------|-----------|
| OK | \|variance\| ≤ 1% or ≤ 0.5 kg |
| WARN | ≤ 3% |
| REVIEW | > 3% or known bad nominal (e.g. RM-B70 pattern) |
| BLOCK | policy-driven (optional) |

Fields aligned with existing DQ concepts: `standard_weight_kg`, `actual_weight_kg`, `weight_source`, `weight_verified_at`.

---

## 9. Phase bridge → Production (future Phase 3)

```text
Production Order → BOM → Expected RM
       ↓
Spoolman / physical consumption = Actual RM Usage
       ↓
Variance → Yield / Scrap → Costing → Forecast / Purchase
```

v1.1 only needs durable `SPOOL_CONSUMPTION` → ledger ISSUE so Phase 3 can attach `production_order_id` later (`meta` / future FK).

---

## 10. Implementation checklist (dev)

### Sprint A — Foundation
- [ ] Extend `items` with UOM + `stock_calc_strategy` + flags
- [ ] Migrations: `item_spoolman_mappings`, `physical_units`, `spoolman_consumption_events`, `weight_reconciliations`
- [ ] Ensure `stock_ledger` posting service is single write path (immutable)

### Sprint B — Spool Adapter
- [ ] Config: `SPOOLMAN_BASE_URL`, service token, private network
- [ ] Client for Spoolman REST ([API docs](https://donkie.github.io/Spoolman/))
- [ ] WebSocket listener → queue → `SPOOL_CONSUMPTION` pipeline
- [ ] Idempotency + UOM conversion (g→kg)

### Sprint C — WMS UX proxy
- [ ] Permissioned spool list/detail under warehouse
- [ ] Receive flow creates physical units + Spoolman spool
- [ ] Reconciliation screen + DQ badges

### Sprint D — Non-filament physical units
- [ ] Roll tracking for RM-W40-W / RM-B70 without Spoolman
- [ ] Scale entry / import actual weight
- [ ] Strategy-based AvailableWeight on stock screens

### Hard constraints
- [ ] Spoolman **not** publicly reachable
- [ ] No dual write of “company stock” outside ledger
- [ ] Never assume all RM use kg

---

## 11. Config reference

```env
# WMS
SPOOLMAN_ENABLED=true
SPOOLMAN_BASE_URL=http://spoolman.internal:7912
SPOOLMAN_TIMEOUT_MS=5000
SPOOLMAN_WS_URL=ws://spoolman.internal:7912/api/v1/socket
WEIGHT_VARIANCE_WARN_PCT=1
WEIGHT_VARIANCE_REVIEW_PCT=3
```

---

## 12. Decision summary

1. **Develop together** — WMS owns stock; Spoolman owns spool weight/consumption.
2. **Do not** vendor Spoolman into Laravel codebase.
3. **Do** add `physical_units` so the same pattern covers rolls/drums, not only filament.
4. **Calculation Engine** is pluggable per SKU strategy.
5. **Every consumption** ends as an immutable ledger ISSUE after validation.

---

## References

- [aideveloper-cloud/point-of-sales](https://github.com/aideveloper-cloud/point-of-sales.git) (WMS fork)
- [aideveloper-cloud/Spoolman](https://github.com/aideveloper-cloud/Spoolman.git) (Spoolman fork)
- [Spoolman upstream](https://github.com/Donkie/Spoolman.git)
- [Spoolman REST API](https://donkie.github.io/Spoolman/)
- [Spoolman Wiki / Installation](https://github.com/Donkie/Spoolman/wiki/Installation)
- [point-of-sales upstream](https://github.com/aryadwiputra/point-of-sales.git)

## Implementation note (mapped to existing POS schema)

| Spec name | Implemented as |
|-----------|----------------|
| `items` | `products` (+ WMS columns) |
| `item_spoolman_mappings` | `product_spoolman_mappings` |
| `stock_ledger` | `stock_ledgers` (immutable; separate from retail `stock_mutations`) |
