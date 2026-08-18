# MIMS — Project Handover

**Project:** Manufacturing Information Management System (MIMS)
**Stack:** Laravel 13, PHP 8.3+, MySQL, Livewire, Flux UI, Laravel Fortify (auth)
**Location context:** Indonesia — Bahasa Indonesia labels needed in UI, English/underscore values in DB/code

**Skills in use:** `laravel-architect`, `business-logic-expert`, `manufacturing-domain-expert`, `frontend-product-design`, `livewire-volt-architect`, `laravel-security-expert`

**Working modes:** Two explicit modes requested by user —
- **Teaching mode**: explain concepts deeply (why, not just how)
- **Coding mode**: keep it tight — code + brief rationale, skip deep explanations
Default to teaching mode unless user says "coding mode."

**Current focus (as of 2026-08-09, see Session 8 below for latest): `ReceivePurchaseOrderAction` written and reviewed-correct, on branch `receive_po`.** `RejectPurchaseOrderAction` and `ReopenPurchaseOrderAction` were also written and wired into View PO between sessions (commit "approve, reject, reopen PO") — Immediate Next Steps #1/#2 from earlier are done. `ReceivePurchaseOrderAction` is the first real consumer of `CreateStockMovementAction`; along the way `purchase_order_receipts.notes` was replaced with a structured `receipt_condition` enum column, and two new model helpers (`PurchaseOrder::ensureCanReceive()`, `PurchaseOrderItem::syncQuantityReceived()`) were added. **Not yet done: any UI for warehouse staff to actually trigger this Action** — see Session 8 write-up for a real access gap discovered (`PurchaseOrderPolicy::view()` currently blocks the `warehouse` role entirely, so a warehouse user can't even open a PO yet). For backend Actions, user writes the code themselves (assistant gives a guide/rundown only, no code, per established preference — assistant does directly implement small, explicitly-requested mechanical fixes and one-off dev/test data setup when asked). For UI/design work, user has explicitly delegated implementation to the assistant — assistant writes the actual Blade/Livewire code, user reviews and course-corrects.

---

## Project Modules (planned)
Purchasing, Inventory, Production, Sales, Cost Accounting, plus Executive/Production dashboards.

**Future module note (added 2026-08-09, not yet started):** the Cost Accounting module is planned to aggregate data across all other modules (Purchasing, Inventory, Production, Sales) and produce financial reports exportable as **PDF and Excel**. No design work done yet — flagged here so it isn't lost, to be fleshed out (report contents, aggregation logic, export library choice) once the other modules are further along.

**Future feature note (added 2026-08-09, not yet started):** the Production module should support **cost production tracking** — calculating total production cost per production run/batch from three components: **machine depreciation**, **staff (labor) cost**, and **material cost**. No design work done yet (no schema, no allocation logic decided — e.g. how depreciation and labor get apportioned per batch). Flagged here so it isn't lost; likely feeds directly into the Cost Accounting module's financial reports above.

## Roles (7, flat — no hierarchy except access override)
`purchasing`, `sales`, `accounting`, `production`, `warehouse`, `system_admin`, `manager`

**Special rule:** `manager` and `system_admin` can access **all** dashboards, not just their own. Enforced via a Gate (`view-dashboard`), not just the login redirect.

## Key Architectural Decisions Made

1. **No real-time UI (WebSockets/Reverb)** — decided against it after analysis. `BROADCAST_CONNECTION=log` (no-op) for now, code stays broadcast-ready for later.
2. **Real-time backend cache IS required** — `products.current_stock` must update synchronously, in the same DB transaction as any `stock_movements` write, using `lockForUpdate()` to prevent race conditions/lost updates under concurrency. This replaces the need for live UI push for the inventory-concurrency risk.
3. **Concurrency protection = transactions + row locking**, not WebSockets. This is the actual defense for the "two staff allocate same stock" risk.
4. **Enum values stored as English/underscore** in DB (e.g. `finished_goods`, not `barang jadi`) — Bahasa Indonesia labels handled via a `label()` method on PHP backed enums, kept as a display-only concern.
5. **Architecture layers**: Controllers/Livewire = thin, orchestrate only. `Services/` = workflows (multi-step). `Actions/` = single atomic operations. `Events/`+`Listeners/` = decoupled side effects. `Enums/` = replace magic strings. `Observers/` = model lifecycle side effects (e.g. audit log). `Policies/` = model-tied authorization. `Gates/` = non-model authorization (e.g. dashboard access). `DTOs/` = only once a workflow has 5+ related fields, not for simple forms.
6. **Validation layering established:**
   - **Input validation** (Form Requests): is a single field well-formed (numeric, required, etc.)
   - **Business validation** (inside Actions): is the object, in its current state, allowed to do this (e.g. status checks)
   - **Authorization** (Policies/Gates): is *this user* allowed to do this at all, regardless of object state
7. **Derived/cached fields must never be set directly** — `products.current_stock`, `purchase_order_items.subtotal`, `purchase_orders.total_amount`, `purchase_order_items.quantity_received` are all calculated from their source data (movements/receipts), not entered independently.
8. **Never edit vendor files.** Fortify customization done via container binding (see below), not editing `vendor/laravel/fortify/...`.

## Database Schema — Implemented (verified against actual migrations, 2026-07-13)

All 6 Purchasing tables exist as real migrations under `database/migrations/` and have been run successfully (`php artisan migrate:status` confirms all `Ran`).

```dbml
Table products {
  id bigint [pk]
  product_code string [unique]
  product_name string
  unit_of_measure string
  type string [note: 'indexed; cast to App\Enums\ProductType in the model']
  current_stock decimal(12,2) [default: 0]   // cached — guarded in model, never mass-assignable
  safety_stock decimal(12,2) [default: 0]
  reorder_point decimal(12,2) [default: 0]
  created_at timestamp
  updated_at timestamp
  // NOTE: no soft deletes — originally planned (see earlier draft) but NOT implemented.
  // Decide: add softDeletes(), or drop the idea. Not yet decided.
}

Table suppliers {
  id bigint [pk]
  supplier_code string [unique]
  supplier_name string
  contact_person string [nullable]
  phone string [nullable]
  email string [nullable]
  address text [nullable]
  created_at timestamp
  updated_at timestamp
  // Same soft-delete gap as products — not implemented.
}

Table product_supplier {
  id bigint [pk]
  product_id bigint [ref: > products.id, note: 'cascadeOnDelete']
  supplier_id bigint [ref: > suppliers.id, note: 'cascadeOnDelete']
  price decimal(12,2)
  created_at timestamp
  updated_at timestamp
  indexes { (product_id, supplier_id) [unique] }
  // Only stores CURRENT price — price history table still flagged as future work, not built.
}

Table purchase_orders {
  id bigint [pk]
  po_number string [unique]
  supplier_id bigint [ref: > suppliers.id, note: 'restrictOnDelete']
  status string [default: 'draft', note: 'indexed; cast to App\Enums\PurchaseOrderStatus — 8 cases, see below']
  created_by bigint [ref: > users.id, note: 'restrictOnDelete']
  approved_by bigint [ref: > users.id, note: 'nullable, nullOnDelete']
  approved_at timestamp [nullable]
  order_date date
  expected_delivery_date date [nullable]
  total_amount decimal(12,2) [default: 0]   // cached — guarded, recalculated via PurchaseOrder::recalculateTotal()
  created_at timestamp
  updated_at timestamp
  indexes { (supplier_id, status) }
}

Table purchase_order_items {
  id bigint [pk]
  purchase_order_id bigint [ref: > purchase_orders.id, note: 'cascadeOnDelete']
  product_id bigint [ref: > products.id, note: 'restrictOnDelete']
  quantity_ordered decimal(12,2) [unsigned]
  quantity_received decimal(12,2) [unsigned, default: 0]   // cached — guarded, derived from receipts
  unit_price decimal(12,2) [unsigned]
  subtotal decimal(12,2) [unsigned, default: 0]   // guarded — PurchaseOrderItem::syncSubtotal() computes, doesn't persist
  created_at timestamp
  updated_at timestamp
  // No unique constraint on (purchase_order_id, product_id) — same product CAN appear as two separate line items. Flagged, not yet decided if intentional.
}

Table purchase_order_receipts {
  id bigint [pk]
  purchase_order_item_id bigint [ref: > purchase_order_items.id, note: 'restrictOnDelete — NOT purchase_order_id + product_id separately']
  quantity_received decimal(12,2) [unsigned]
  received_at timestamp
  received_by bigint [ref: > users.id, note: 'restrictOnDelete']
  notes text [nullable]
  created_at timestamp
  updated_at timestamp
}
```

**✅ DESIGNED AND MIGRATED (Session 7, 2026-08-01):**

```dbml
Table stock_movements {
  id bigint [pk]
  product_id bigint [ref: > products.id, note: 'restrictOnDelete']
  reference_id bigint [nullable, note: 'polymorphic — via nullableMorphs("reference")']
  reference_type string [nullable, note: 'stores the morph MAP KEY (e.g. "purchase_order_receipt"), not the FQCN — see morphMap() below']
  direction string [note: 'indexed; cast to App\Enums\Direction — in|out']
  type string [note: 'indexed; cast to App\Enums\StockMovementType — 7 cases, see below']
  amount decimal(12,2) [unsigned]
  created_by bigint [ref: > users.id, note: 'restrictOnDelete']
  created_at timestamp
  // No updated_at — a stock movement is an immutable ledger entry, never edited after creation.
  // Model enforces this via `const UPDATED_AT = null`, not just omitting the column.
}
```

Cross-module unified inventory ledger — every stock change (purchase receipt, sales shipment, production consumption/output, manual adjustment, etc.) writes one row here, direction-tagged `in`/`out`. `products.current_stock` stays the cached/derived total (per architectural decision #2); this table is the source of truth it's derived from.

## Purchasing Domain Models — Implemented (Eloquent)

All 6 models exist under `app/Models/` with real business logic already written (this was built before it got documented here — handover previously understated actual progress):

- **`Supplier`** — `#[Guarded(['id'])]`. `purchaseOrders(): HasMany`, `products(): BelongsToMany` (via pivot, see below).
- **`Product`** — `#[Guarded(['id', 'current_stock'])]`. Casts `type` to `ProductType` enum + 3 decimal fields. `scopePurchasable()` local scope (`where type = raw_material`). `isBelowReorderPoint()` uses `bccomp()` — **all quantity/money comparisons in this codebase use bcmath (`bccomp`/`bcsub`/`bcmul`), never raw float comparison**, to avoid binary floating-point rounding error compounding across a ledger.
- **`ProductSupplier`** — custom `Pivot` subclass (`#[Table('product_supplier')]`, `extends Pivot` not `Model`), casts `price` to `decimal:2`. Wired via `->using(ProductSupplier::class)` on both `Product::suppliers()` and `Supplier::products()`, since the pivot carries real data (price), not just a join.
- **`PurchaseOrder`** — `#[Guarded(['id', 'po_number', 'status', 'approved_by', 'approved_at', 'total_amount'])]` — every system-derived field locked out of mass assignment. Relations: `supplier()`, `createdBy()`/`approvedBy()` (both `BelongsTo(User::class)` on different FKs), `items(): HasMany`. **Status is a finite state machine**: `transitionTo(PurchaseOrderStatus $target)` is documented as "the ONLY method allowed to change this PO's status" — it calls `$this->status->canTransitionTo($target)` (lives on the enum) and throws `InvalidStatusTransitionException` if illegal. `recalculateTotal()` re-sums `items()->sum('subtotal')` — total is never hand-set.
- **`PurchaseOrderItem`** — `#[Guarded(['id', 'quantity_received', 'subtotal'])]`. `syncSubtotal()` computes via `bcmul()` but deliberately does **not** call `save()` — caller controls when it's persisted (e.g. inside a larger transaction). `remainingQuantity()` and `isFullyReceived()` also bcmath-based.
- **`PurchaseOrderReceipt`** — `#[Guarded(['id'])]`. One row per delivery event against one PO item; `purchaseOrderItem()`, `receivedBy()` (→ `User`).

**`PurchaseOrderStatus` enum** (`app/Enums/PurchaseOrderStatus.php`) — 8 cases: `Draft`, `PendingApproval`, `Approved`, `Rejected`, `PartiallyReceived`, `FullyReceived`, `Cancelled`, `Closed`. Transition graph lives in `canTransitionTo()`.

**✅ RESOLVED (Session 4, 2026-07-18/19):** `PendingApproval` now includes `Rejected` in its allowed-target list (`self::PendingApproval => in_array($target, [self::Approved, self::Draft, self::Rejected, self::Cancelled], true)`), fixed by the user directly. `Rejected → Draft` (resubmit path) was already correct. The state machine now matches the originally documented design. `RejectPurchaseOrderAction` itself is still not written (see Actions table).

**Still open:** `Closed` remains a commented-out enum case (`// case Closed = 'closed';`) — the soft-deletes-style "decide or drop" question from earlier still applies here. `PartiallyReceived → Closed` and `PurchaseOrder::close()` Gate/Policy already exist and expect a `Closed` status, but the enum case itself isn't live yet.

## Inventory Domain Models — Implemented (Session 7, 2026-08-01 to 08-03)

New module, separate from Purchasing — `app/Actions/Inventory/` and `app/DTO/Inventory/` (mirrors the existing `Purchasing` folder split).

- **`StockMovement`** (`app/Models/StockMovement.php`) — `#[Guarded(['id'])]`. `const UPDATED_AT = null` (immutable ledger row, no updated_at column at all). Casts `direction`→`Direction`, `type`→`StockMovementType`, `created_at`→`datetime`, `amount`→`decimal:2`. Relations: `createdBy(): BelongsTo` (→ `User`), `product(): BelongsTo`, `reference(): MorphTo` (polymorphic — points at whatever domain event caused the movement, e.g. a `PurchaseOrderReceipt`).
- **`Direction`** enum (`app/Enums/Direction.php`) — 2 cases: `In = 'in'`, `Out = 'out'`. No `label()` yet (not wired into any UI so far).
- **`StockMovementType`** enum (`app/Enums/StockMovementType.php`) — 7 cases: `PurchaseReceived`, `PurchaseReturn`, `SalesShipment`, `SalesReturn`, `ProductionOutput`, `ProductionInput`, `StockAdjustment`. Covers all planned modules (Purchasing/Sales/Production) up front, even though only the Purchasing side has a real code path today — same "design the ledger once" reasoning as the `stock_movements` table itself.
- **`CreateStockMovementData`** DTO (`app/DTO/Inventory/CreateStockMovementData.php`) — `productId`, `direction` (`Direction`), `type` (`StockMovementType`), `amount` (**`string`**, not float/decimal — keeps bcmath discipline all the way from caller input through to the Action), `createdBy`, plus optional `referenceId`/`referenceType` for the polymorphic link. Crosses the project's own 5+-field DTO threshold.
- **`CreateStockMovementAction`** (`app/Actions/Inventory/CreateStockMovementAction.php`) — **✅ written and reviewed-correct.** Inside one `DB::transaction()`: locks the `Product` row (`lockForUpdate()`), computes the new balance via `bcadd`/`bcsub` depending on `direction`, guards against negative stock (see `InsufficientStockException` below, only checked for `Direction::Out`), writes `current_stock` via direct property assignment + `save()` (guarded field, same pattern as `PurchaseOrder`'s guarded fields), then creates the `StockMovement` row via plain mass-assignment (`StockMovement` is only `#[Guarded(['id'])]`, so this works unlike `Product`). Went through several review passes this session — real bugs caught and fixed along the way: a malformed 4-arg `Product::where('id', $data->productId, null, 'and')` call that Laravel silently reinterpreted as `WHERE id IS NOT NULL` (locked/updated an arbitrary product instead of the requested one, with no error — the most dangerous class of bug caught this session), a missing `amount` key in the `StockMovement` create array (would have thrown a DB NOT NULL violation), and `handle()` never actually returning the value built inside the `DB::transaction()` closure (same "value computed but never surfaces from the method" shape as `ApprovePurchaseOrderAction`'s first-draft bug from Session 6).
- **`InsufficientStockException`** (`app/Exceptions/InsufficientStockException.php`) — new, mirrors `NonPurchasableProductException`'s shape exactly: `extends RuntimeException`, promoted `readonly` properties (`Product $product`, `string $requested`, `string $available`), a technical `parent::__construct()` message for logs, and a translatable `userMessage()` using `:name`/`:requested`/`:available` placeholders. Not yet added to `lang/id.json` (still English-only fallback). Went through a few review rounds too — `$requested`/`$available` were briefly typed `int` (would've truncated fractional quantities) before being fixed to `string`, and `userMessage()` briefly referenced bare `$requested`/`$available` instead of `$this->requested`/`$this->available` (undefined-variable bug — promoted constructor params aren't visible as local variables in a different method) before being corrected.

**Relations wired into existing models to support the ledger:**
- `Product::stockMovements(): HasMany` (`app/Models/Product.php`) — **renamed from the initial `stockMovement()`** (singular-name inconsistency flagged during review, since fixed) for consistency with `purchaseOrderItems()` etc.
- `PurchaseOrderReceipt::stockMovements(): MorphMany` (`app/Models/PurchaseOrderReceipt.php`) — the inverse side of `StockMovement::reference()`; correctly plural. This is the first real polymorphic relation in the codebase.

**Morph map registered** in `AppServiceProvider::boot()`: `Relation::morphMap(['purchase_order_receipt' => PurchaseOrderReceipt::class])`. Deliberate choice over Laravel's default (storing the fully-qualified class name in `reference_type`) — decouples the DB column from the PHP namespace, so a future `App\Models\...` rename/refactor doesn't silently orphan existing ledger rows. Only one entry exists so far; every future `reference_type` source (sales shipments, production consumption, etc.) will need its own map entry added here as those modules get built.

**✅ DECIDED (Session 7): over-receipt / negative-stock policy.** Two related but distinct policy questions, both resolved this session:
- **Negative stock (in `CreateStockMovementAction`):** blocked. An `Out` movement that would push `current_stock` below zero throws `InsufficientStockException` before any write happens (checked via `bccomp($newStock, '0', 2) < 0`, scoped to `Direction::Out` only — an `In` movement can mathematically never go negative).
- **Over-receipt (for the still-unwritten `ReceivePurchaseOrderAction`):** **allowed freely** — a receipt can push `purchase_order_items.quantity_received` past `quantity_ordered`. Chosen over blocking/capping because the project's own priority (architectural decision #2) is that `products.current_stock` stay accurate to physical reality; capping or blocking a receipt that reflects what was *actually* physically delivered would desync the system from the warehouse floor, which is worse than a "messy" paper trail. `PurchaseOrderItem::isFullyReceived()` already uses `bccomp(...) >= 0` (not `== 0`), so it's already compatible with this decision with no code change needed; `remainingQuantity()` will need a floor-at-0 clamp wherever it's displayed, since it can now go negative.

**Dev/test data:** a dummy `warehouse`-role user was created for manual testing — `warehouse@test.com` / `password`, id 7 — via `php artisan tinker`, not a seeder (one-off, not meant to be repeatable/committed).

## Purchasing Module — Business Rules Established

**Status lifecycle (state machine) — as originally intended:**
```
Draft → PendingApproval → Approved → PartiallyReceived → FullyReceived → Closed
              ↓
          Rejected → (back to Draft)
Cancelled reachable from Draft, PendingApproval, or Approved
```
(See ⚠️ OPEN ISSUE above — actual code doesn't yet allow `PendingApproval → Rejected`.)

**Actions — status as of 2026-07-15:**
| Action | Transition | Notes | Status |
|---|---|---|---|
| `CreatePurchaseOrderAction` | → Draft | Creates PO shell + optional items in one `DB::transaction()`. See "Purchasing Actions Implemented" below for full design. | **Written** |
| `UpdatePurchaseOrderAction` | Draft → Draft | Header fields only (supplier_id, order_date, expected_delivery_date). Supplier IS editable while Draft (deliberate decision — nothing depends on it yet). | **Written** |
| `AddPurchaseOrderItemAction` | (Draft only) | Granular per-item add — chosen over full-replace item sync. | **Written** |
| `UpdatePurchaseOrderItemAction` | (Draft only) | Edits quantity/unit_price only; `product_id` is immutable on a line item (swap product = remove + add instead). | **Written** |
| `RemovePurchaseOrderItemAction` | (Draft only) | Deletes one line item. | **Written** |
| `SubmitPurchaseOrderForApprovalAction` | Draft → PendingApproval | Business validation: must have ≥1 line item, all quantities > 0. See Session 4 notes below for bugs caught during review. | **Written** |
| `ApprovePurchaseOrderAction` | PendingApproval → Approved | Authorization: Policy, not inline role check. Sets two guarded fields (`approved_by`, `approved_at`) alongside the status transition — deliberately two separate `save()` calls (one inside `transitionTo()`, one explicit) wrapped in `DB::transaction()`, rather than bypassing `transitionTo()` to combine into one write, to preserve "transitionTo() is the only method allowed to change status." | **Written** (Session 6, reviewed twice — first draft had wrong class name colliding with `CreatePurchaseOrderAction`, and set `approved_by`/`approved_at` without ever calling `save()`) |
| `RejectPurchaseOrderAction` | PendingApproval → Rejected | Near-mirror of `ApprovePurchaseOrderAction`, sets `rejected_by`/`rejected_at`/`rejection_reason` instead of `approved_by`/`approved_at`. Wired into View PO with a modal collecting the rejection reason. | **Written** (between sessions, commit "approve, reject, reopen PO") |
| `ReopenPurchaseOrderAction` | Rejected → Draft | Not in the original design table — added between sessions alongside Reject. Wired into View PO ("Move to Draft" button). | **Written** |
| `CreateStockMovementAction` | (cross-module, shared) | Centralized, row-locked stock update. `bcadd`/`bcsub` onto `Product::current_stock` inside `lockForUpdate()` + `DB::transaction()`, blocks negative stock via `InsufficientStockException`. | **Written** (Session 7, reviewed multiple times — wrong-product `where()` call, missing `amount`, missing `return` all caught and fixed) |
| `ReceivePurchaseOrderAction` | Approved/PartiallyReceived → PartiallyReceived/FullyReceived | First real consumer of `CreateStockMovementAction`. Full design + review history in Session 8 below. | **Written** (Session 8, 2026-08-09, reviewed through several passes — see write-up below) |

**`ReceivePurchaseOrderAction` step sequence — ✅ finalized (Session 7, 2026-08-03), revised from the original plan below, not yet coded:**

Operates on **one `PurchaseOrderItem` per call** (matches `purchase_order_receipts.purchase_order_item_id`'s per-item FK and the existing granular-Action convention — a shipment covering multiple items means multiple calls). All inside one `DB::transaction()`:
1. Lock the `PurchaseOrderItem` row (`lockForUpdate()`) — same concurrency reasoning as the `Product` lock inside `CreateStockMovementAction`, applied one level up (two staff recording receipts against the same line item at once).
2. Business validation — explicit status guard (`Approved`/`PartiallyReceived`), likely a small `PurchaseOrder::ensureCanReceive()` model helper mirroring `ensureIsEditable()`'s shape. **This check is necessary here even though `PurchaseOrderPolicy::receive()` already checks the same condition** — unlike `ApprovePurchaseOrderAction`/`RejectPurchaseOrderAction`, this Action doesn't unconditionally call `transitionTo()` (see step 6), so there's no guarantee anything else in the call path validates status on every invocation.
3. ~~Quantity check / block over-receipt~~ — **not needed.** Over-receipt is allowed by design decision (see "Inventory Domain Models" above) — no cap, no block.
4. Create the `purchase_order_receipts` record. `received_at` is **not** caller-suppliable (dropped from `ReceivePurchaseOrderData` deliberately) — set to `now()` inside the Action, same reasoning as `createdBy` not being caller-suppliable on `CreatePurchaseOrderAction`.
5. Recompute `purchase_order_items.quantity_received` as a fresh `SUM()` over that item's `receipts()` (not an increment) — mirrors `PurchaseOrder::recalculateTotal()`'s pattern, self-heals if a receipt is ever corrected/voided later. Suggested new model method: `PurchaseOrderItem::syncQuantityReceived()`, same "computes, doesn't `save()` itself" shape as `syncSubtotal()`.
6. Recalculate PO overall status by checking **every** item via `isFullyReceived()` (already `>=`-based, no change needed): all fully received → target `FullyReceived`; some but not all → target `PartiallyReceived`. **Only call `$po->transitionTo($target)` if `$target !== $po->status`** — `PurchaseOrderStatus::canTransitionTo()` does not allow `PartiallyReceived → PartiallyReceived`, so a second still-partial receipt against an already-`PartiallyReceived` PO must skip the `transitionTo()` call entirely or it throws `InvalidStatusTransitionException` incorrectly.
7. Call `CreateStockMovementAction::handle()` — `direction: In`, `type: PurchaseReceived`, `amount` = this receipt's quantity, `referenceId`/`referenceType` = the just-created `PurchaseOrderReceipt`'s id + the `'purchase_order_receipt'` morph-map key. Safe to call from inside this Action's own outer transaction — Laravel nests `DB::transaction()` calls via savepoints automatically.
8. Fire `PurchaseOrderReceived` event → no listener needs to exist yet (decoupled side-effect seam, same reasoning as architectural decision #1 — not a half-finished feature, just an extension point).

## Purchasing Actions Implemented (Session 3, 2026-07-14/15)

**`app/Actions/Purchasing/CreatePurchaseOrderAction.php`** — takes `App\DTO\Purchasing\CreatePurchaseOrderData` (note: folder is `DTO`, singular — renamed from an initial `DTOs`). Crosses the project's own "5+ related fields → use a DTO" threshold (supplierId, orderDate, expectedDeliveryDate, createdBy, items[]). Steps, all inside one `DB::transaction()`:
1. Insert the PO row with fillable fields + a temporary placeholder `po_number` (a UUID) — needed because `po_number` is guarded, unique, and NOT NULL, but its real value depends on the row's own id, which doesn't exist until after the first insert.
2. Re-save with the real `po_number`, format `PO-{year}-{zero-padded id}` (e.g. `PO-2026-000123`) — decided over a per-year-reset counter to avoid needing a separate counter table.
3. Items are **optional at creation** (`$data->items = []` default) — matches the existing rule that "≥1 item, qty > 0" is enforced later at `SubmitPurchaseOrderForApprovalAction`, not at Create. Supports both "fill the whole form at once" and "start blank, add items later" flows.
4. Each item is validated for `$product->type->isPurchasable()` (raw_material only) — throws `NonPurchasableProductException` (new, mirrors `InvalidStatusTransitionException`'s style) if violated.
5. `$po->recalculateTotal()` at the end.
- Authorization is deliberately **not** inside the Action — assumes the caller already checked `Gate::authorize('create', PurchaseOrder::class)`. Only `Gate::define('purchasing.create', ...)` has been added so far (`AppServiceProvider::boot()`): Purchasing role or Manager, **not** SystemAdmin (SystemAdmin treated as technical/config-only, not a business actor).

**✅ RESOLVED (Session 4, 2026-07-18/19):** All Gates `PurchaseOrderPolicy` depends on are now registered in `AppServiceProvider::boot()` — `purchasing.view`, `purchasing.approve`, `purchasing.cancel`, `purchasing.close`, `warehouse.receive`, alongside the pre-existing `purchasing.create`. Rules: `purchasing.create`/`.cancel`/`.close`/`.view` → Purchasing or Manager; `purchasing.approve` → **Manager only** (deliberate segregation-of-duties choice — Purchasing staff can't approve their own POs even before the Policy's own `created_by !== $user->id` check kicks in); `warehouse.receive` → Warehouse or Manager. `system_admin` is excluded from every one of these (technical/config-only role, not a business actor — consistent with the dashboard-access design). Verified by reading the file directly; not yet exercised via an actual login-as-each-role test in the browser.

**✅ ADDED (Session 6, 2026-07-29):** `PurchaseOrderPolicy::update()` — didn't exist before, needed once View PO's inline item-editing was scoped in. Shape: `purchasing.create` Gate + `status === Draft`, same as `submit()` — but **deliberately no `created_by` check**, unlike `submit()`. User's explicit call: a Draft PO is team-editable by any Purchasing/Manager user, not locked to its creator, consistent with `cancel`/`approve`/`reject` (none of which are creator-restricted either).

**`UpdatePurchaseOrderAction`** — header fields only (supplierId, orderDate, expectedDeliveryDate); only 3 fields, stays plain params per the DTO threshold rule (no DTO). Business validation: `$po->ensureIsEditable()` (new model helper, throws `PurchaseOrderNotEditableException` if status isn't Draft). Supplier IS editable while Draft — decided deliberately, nothing depends on supplier before approval/receipts exist.

**Item editing is granular, not full-replace** (deliberate choice over resyncing a whole items array):
- **`AddPurchaseOrderItemAction`** — same purchasable-product check as Create.
- **`UpdatePurchaseOrderItemAction`** — quantity/unit_price only; `product_id` is immutable on an existing line (swap product = remove + add).
- **`RemovePurchaseOrderItemAction`** — deletes the row, recalculates total. No extra "must have zero receipts" guard needed — `ensureIsEditable()` already guarantees status is Draft, and receipts can only exist once status is Approved/PartiallyReceived, so `quantity_received` is guaranteed 0 by the state machine itself.

All three item Actions + `UpdatePurchaseOrderAction` call **`PurchaseOrder::ensureIsEditable()`** (new model helper) instead of repeating the same `if ($this->status !== Draft) throw ...` four times — centralizes the "must still be Draft" check in one place, mirrors the existing `isFullyReceived()`/`isBelowReorderPoint()`-style small model helpers.

**Recurring gotcha reinforced across all of these:** guarded fields (`status`, `total_amount`, `subtotal`, `po_number`) can never go through `update([...])`/mass assignment, even from the model's own methods — guarding blocks *mass assignment specifically*, not direct writes. Every Action sets them via `$model->field = $value; $model->save();` instead. (`PurchaseOrder::transitionTo()` and `recalculateTotal()` were also fixed to this pattern earlier in this session, after discovering the mass-assignment bug live.)

**Tooling fix (unrelated to business logic, but blocking real work):** `phpstan.neon` now has `parameters.parseModelCastsMethod: true`. Root cause of a whole session's worth of confusing phpstan errors (enum comparisons, decimal fields typed as `float` instead of `string`, `->format()` on what should be a `Carbon` instance): Larastan supports the project's method-style `casts()` (vs. the classic `$casts` property) but only if this flag is explicitly turned on — it defaults to `false` and was never set. One-line fix, no model changes needed, fixes every model project-wide.

**Still open / in progress:** relation methods across all 6 Purchasing models (`Product`, `Supplier`, `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseOrderReceipt`) lack PHPDoc generics (`@return BelongsTo<X, $this>` etc.) — attempted once this session but got reverted before landing. Separately, the IDE (PhpStorm/Intelephense) reports property access like `$po->id` as going through `Model::__get(): mixed`, because these models — unlike `User.php` — have no `@property` docblocks. User has started hand-writing these (`PurchaseOrder.php` now has `@property int $id`, more likely to follow) rather than installing `barryvdh/laravel-ide-helper` to auto-generate them. Neither of these is blocking runtime or the currently-passing parts of phpstan — cosmetic/IDE-accuracy only, unless it starts producing real cascading errors like the relation-generics gap already did once (see `RemovePurchaseOrderItemAction`/`UpdatePurchaseOrderItemAction`, which needed `PurchaseOrderItem::purchaseOrder()` to have `@return BelongsTo<PurchaseOrder, $this>` before phpstan would recognize `$item->purchaseOrder->ensureIsEditable()`).

## Code Implemented So Far (Auth / Role-based Dashboard Routing)

**Stack specifics discovered:** This Laravel 13 project uses PHP 8 **attributes** for Eloquent config, not traditional properties — e.g. `#[Fillable([...])]` and `#[Hidden([...])]` above the class, NOT `protected $fillable`. This caused a real bug (see Known Issues Resolved).

Auth uses **Laravel Fortify** (not Livewire-based auth scaffolding). Login view: `resources/views/pages/auth/login.blade.php` (Flux UI components, posts to `login.store`).

### 1. `app/Enums/UserRole.php`
```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Purchasing = 'purchasing';
    case Sales = 'sales';
    case Accounting = 'accounting';
    case Production = 'production';
    case Warehouse = 'warehouse';
    case SystemAdmin = 'system_admin';
    case Manager = 'manager';

    public function label(): string
    {
        return match($this) {
            self::Purchasing => 'Purchasing',
            self::Sales => 'Sales',
            self::Accounting => 'Accounting',
            self::Production => 'Production',
            self::Warehouse => 'Warehouse',
            self::SystemAdmin => 'System Admin',
            self::Manager => 'Manager',
        };
    }

    public function dashboardRoute(): string
    {
        return match($this) {
            self::Purchasing => 'purchasing.dashboard',
            self::Sales => 'sales.dashboard',
            self::Accounting => 'accounting.dashboard',
            self::Production => 'production.dashboard',
            self::Warehouse => 'warehouse.dashboard',
            self::SystemAdmin => 'admin.dashboard',
            self::Manager => 'executive.dashboard',
        };
    }
}
```

### 2. `app/Http/Responses/LoginResponse.php`
Custom Fortify `LoginResponse` implementation — redirects by role instead of Fortify's generic default.
```php
<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = Auth::user();

        return redirect()->intended(
            route($user->role->dashboardRoute(), absolute: false)
        );
    }
}
```

### 3. `app/Providers/AppServiceProvider.php`
Binds the custom LoginResponse into the container (Fortify's `AuthenticatedSessionController` resolves `app(LoginResponse::class)` — this is how it's overridden without touching vendor code). Also defines the `view-dashboard` Gate (Manager/SystemAdmin override).
```php
<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Http\Responses\LoginResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        Gate::define('view-dashboard', function ($user, UserRole $dashboardRole) {
            return $user->role === $dashboardRole
                || in_array($user->role, [UserRole::Manager, UserRole::SystemAdmin], true);
        });
    }
}
```

### 4. Migration — `add_role_to_users_table`
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role')->default('warehouse')->after('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('role');
    });
}
```
Status: **written and migrated.**

### 5. `app/Models/User.php` — modified
- Added `'role' => \App\Enums\UserRole::class` to `casts()` method (this project uses method-style casts, not `$casts` property).
- **IMPORTANT:** Fillable is controlled via `#[Fillable([...])]` PHP attribute above the class, NOT `protected $fillable`. Must include `'role'` in the attribute list:
```php
#[Fillable(['name', 'email', 'password', 'role'])]
```
Status: **fixed after a bug (see below).**

### 6. Middleware — `app/Http/Middleware/EnsureUserCanAccessDashboard.php`
```php
<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessDashboard
{
    public function handle(Request $request, Closure $next, string $dashboardRole): Response
    {
        $role = UserRole::from($dashboardRole);

        abort_unless($request->user()->can('view-dashboard', $role), 403);

        return $next($request);
    }
}
```
Registered as alias `dashboard.access` in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'dashboard.access' => \App\Http\Middleware\EnsureUserCanAccessDashboard::class,
    ]);
})
```

### 7. Routes — `routes/web.php`
7 placeholder dashboard routes, each guarded by `auth` + `dashboard.access:{role}` middleware, e.g.:
```php
Route::middleware(['auth'])->group(function () {
    Route::view('/purchasing/dashboard', 'dashboards.placeholder')
        ->name('purchasing.dashboard')->middleware('dashboard.access:purchasing');
    // ...repeated for sales, accounting, production, warehouse (admin.dashboard = system_admin, executive.dashboard = manager)
});
```

### 8. View — `resources/views/dashboards/placeholder.blade.php`
Simple placeholder showing `{{ auth()->user()->role->label() }} Dashboard — Coming soon.` Wrapped in whatever the project's actual app layout component is (verify name, e.g. `x-layouts::app`).

## Known Issues Resolved

**Session 1:**
1. **Blank/raw `{{ }}` output on placeholder page** — file was saved as `.php` instead of `.blade.php`. Fixed by renaming.
2. **`role` always defaulting to `warehouse` regardless of input** — root cause: this Laravel 13 project uses the `#[Fillable([...])]` PHP attribute for mass-assignment control, NOT the traditional `protected $fillable` property. The user had added `role` to the (unused) `$fillable` property while the actual `#[Fillable(...)]` attribute above the class still only listed `name, email, password`. Fixed by adding `'role'` to the attribute list and removing the redundant unused `$fillable` property.
3. Any test users created *before* the Fillable fix have `role = 'warehouse'` stuck in the DB (mass-assignment silently dropped the field) — must be corrected manually via `User::where(...)->update(['role' => '...'])`, since `update()` on a query builder bypasses fillable restrictions.

**Session 2 (2026-07-13):**
4. **`unsignedDecimal()` doesn't exist in Laravel 13** — along with `unsignedFloat()`/`unsignedDouble()`, this was removed from `Illuminate\Database\Schema\Blueprint`. Only the integer variants (`unsignedInteger`, `unsignedBigInteger`, etc.) still exist as dedicated methods. This silently stopped migration batch 2 after `purchase_orders` — `purchase_order_items` and `purchase_order_receipts` sat `Pending` in `migrate:status`. Fixed by replacing every `$table->unsignedDecimal('col', 12, 2)` with `$table->decimal('col', 12, 2)->unsigned()` (the fluent modifier still works for `decimal`/`float`/`double`). Both migrations now ran successfully.
5. Also did a redesign pass on `resources/views/pages/auth/login.blade.php` (visual only — split-panel layout, brand panel depth/texture, icon-embedded inputs, password show/hide toggle via Alpine, subtle entrance motion). A follow-up attempt to wire in the existing-but-unused `<x-passkey-verify>` component (real WebAuthn passkey login, routes already registered by the Laravel Passkeys package) was tried and then reverted at the user's request — the login page currently does **not** offer passkey sign-in, even though the component/routes exist and work.

**Session 3 (2026-07-14/15):**
6. **Guarded fields were being silently dropped via mass assignment from inside the model's own methods** — `PurchaseOrder::transitionTo()` and `recalculateTotal()` originally did `$this->update(['status' => $target])` / `update(['total_amount' => ...])`, but `status` and `total_amount` are both in `#[Guarded([...])]`. Guarding blocks mass assignment (`fill()`/`update()`/constructor array) regardless of *which* code calls it — it doesn't distinguish "internal model code" from "an attacker-controlled request array." Because `Model::preventSilentlyDiscardingAttributes()` is never called anywhere in this app, this failed **silently** (no exception, field just didn't change) rather than throwing. Fixed by setting the attribute directly (`$this->status = $target; $this->save();`) — direct property assignment goes through `setAttribute()`, not the guarded mass-assignment path. Same pattern now used throughout the new Purchasing Actions for every guarded field (`po_number`, `subtotal`).
7. **Root cause of a whole session's worth of confusing phpstan errors found**: Larastan *does* support this project's method-style `casts()` (instead of the classic `$casts` property) — via a config flag, `parseModelCastsMethod`, that defaults to `false` and was never set in `phpstan.neon`. Every earlier "Cannot call method X() on string" / "expects 0 arguments" style error this session was this one missing config line, not a real bug and not a reason to change the models' cast style. Fixed with one line: `parameters.parseModelCastsMethod: true`.
8. **Project root had 5 empty/junk files** (`'Test`, `'purchasing'`, `'purchasing@test.com'`, `bcrypt('password')`, `npm`) — accidental artifacts from a shell-quoting mishap (Windows/PowerShell doesn't treat single quotes the way bash does), unrelated to the app. Deleted. Also: git repo was (re)initialized in this session (one `initial commit` now exists), `.gitignore` got `/.claude` added (tool-local config, same treatment as `.idea`/`.vscode`), and `.env`/`node_modules`/`vendor`/`database/database.sqlite` were confirmed already correctly ignored.

## Session 4 (2026-07-18/19) — Gates, Submit Action, Purchasing UI begins

**Backend, quick recap (see resolved callouts above for detail):** all missing `purchasing.*`/`warehouse.receive` Gates registered; `SubmitPurchaseOrderForApprovalAction` written (`Draft → PendingApproval`, validates ≥1 item + all quantities > 0 via `bccomp`); `Rejected` transition gap fixed by the user. Bugs caught and fixed during review of `SubmitPurchaseOrderForApprovalAction`: a userMessage that said "minimum of 0" when the rule actually requires >0 (contradicted itself), a typo ("have on items" → "has no items"), a missing return type, a redundant duplicate `items` query, and — most importantly — a live logic-inversion bug where the user's own fix flipped `bccomp(...) == 0` to `bccomp(...) >= 0`, which (since quantities are `unsigned`, always ≥ 0) made the check fire on *every* item including valid ones, i.e. the action could never succeed. Caught before it shipped.

**Pivoted to UI.** Sequencing decision: build **Create Purchase Order** before **View Purchase Order**, since there's no meaningful "view" without a PO existing first, and viewing doesn't need a new Action anyway (Actions are for mutations; a view page just queries + checks a Policy).

### Purchasing UI built this session

1. **Role-based collapsible sidebar** (`resources/views/layouts/app/sidebar.blade.php`) — one `flux:sidebar.group` per operational role (`UserRole::operational()`, a new static method: Purchasing/Sales/Accounting/Production/Warehouse — Manager/SystemAdmin excluded, they get the dashboard-access override instead of a "module" entry). Locked groups (role doesn't match, via the existing `view-dashboard` Gate) render `expandable(false)` — no chevron at all, not just a disabled one — with a single inert `lock-closed`-icon placeholder row instead of real nav items. Unlocked groups are expandable and link to that role's dashboard. Items only get added to a group once their route actually exists ("grow as built" — deliberately rejected pre-scaffolding fake nav items for pages that don't exist yet).
2. **Desktop sidebar collapse** — turned out to be a one-line Flux feature, not something to build: `collapsible="mobile"` → `collapsible` (bare), plus removing a manual `lg:hidden` that was suppressing the existing collapse button on desktop. All the icon-rail-collapsed CSS (`data-flux-sidebar-collapsed-desktop:*`) was already shipped in the Flux package, unused.
3. **Light/dark mode toggle** — same story: Flux's full appearance system (`⚡appearance.blade.php`, `$flux.appearance`, `@fluxAppearance` in `partials/head.blade.php`) was already present from the starter kit. The only blocker was `layouts/app/sidebar.blade.php:5` hardcoding `<html class="dark">`. User removed it themselves. Settings → Appearance now actually works.
4. **`Create Purchase Order` page** — `resources/views/pages/purchasing/orders/create.blade.php` (Livewire 4 single-file component; note the actual filename has **no `⚡` prefix** despite the settings pages' convention — tested and confirmed the emoji is cosmetic only, not required for `pages::` resolution to work). Route: `purchasing.orders.create` in `routes/web.php`, via `Route::livewire()`.
   - Design decisions made along the way: single "Save Purchase Order" button only (no Draft/Submit split — Submit is a separate later action from View PO, not this form); item rows scoped to `$supplier->products()->purchasable()` (product must belong to the PO's chosen supplier — supplier must be picked before item rows unlock); zero items allowed (matches what `CreatePurchaseOrderAction` already permits — the "must have ≥1 item" rule lives at Submit, not Create); untouched blank starter rows are filtered out before validation (so "zero items" and "one accidentally-blank row" aren't confused).
   - Live subtotal-per-row and running Est. Total, computed via `bcmul`/`bcadd` (not raw arithmetic) — same math discipline as `PurchaseOrderItem::syncSubtotal()`, formatted as Rupiah (`Rp 150.000` style).
   - **Design went through two passes**: an initial build, then a "make it less AI-generated-looking" redesign (flatter surfaces, no icon-badge clichés, monospace/tabular numbers for codes+money, two-column layout with a sticky "Order Summary" rail) after the user pointed at a reference mockup, then a **light-mode contrast + accent-color pass** after the user reported the page was "painful to look at in light mode" and "barebone" — root cause was several `bg-white/[x%]`/`bg-accent/[0.05]`-style opacity tints and `text-zinc-400` labels that were only ever tuned against the (previously hardcoded) dark background. Fixed by giving light and dark explicit separate values instead of one shared opacity trick, and adding real accent-color moments (title underline, numbered section markers, accent-tinted Order Summary panel) instead of relying on near-invisible tints.
   - **Real bug found and fixed post-build:** the Supplier `<flux:select>` (and per-row product selects) showed the *first real option* selected instead of the placeholder text, even though nothing was chosen yet. Root cause: `public ?int $supplierId = null` — Livewire syncs the `<select>`'s value from `null`, which doesn't match Flux's placeholder option (`value=""`), so the browser clears all selection; per the HTML spec, a single-select with nothing selected falls back to auto-displaying the first **non-disabled** option — and the placeholder option is `disabled`, so it gets skipped in favor of the first real item. Fixed by changing the property (and the per-row `product_id` default) from `null`/`?int` to `''`/`string`, so the "empty" state actually matches the placeholder's `value=""`. Worth remembering as a general Livewire+native-`<select>`+placeholder gotcha, not specific to this field.
   - **Data gap discovered, not yet fixed:** `product_supplier` pivot table has **0 rows** (3 suppliers, 6 purchasable products exist, but none are linked). The item dropdown will be empty for every supplier until this is seeded.

### Localization (Bahasa Indonesia)

- `APP_LOCALE=id` set in `.env`. Laravel's `__('English string')` calls use the literal string as the JSON key (not short keys) — matches how this codebase already wrote every `__()` call — so translations live in **`lang/id.json`** (newly created, ~85 entries).
- **Scope decision (explicit, user-confirmed):** translate core/daily-use pages now (login, sidebar, dashboard placeholder, Settings → Profile/Appearance, register/forgot-password/reset-password/verify-email, the Create PO page, and the 5 Purchasing exception `userMessage()` strings). **Deliberately deferred:** 2FA setup, passkeys, recovery codes, delete-account flow — already `__()`-wrapped in code, just not yet translated in `lang/id.json`; adding them later is content-only, no code changes needed.
- Two real gaps found and fixed during the audit: `pages/auth/login.blade.php` had **zero** `__()` calls at all (every string hardcoded English) — now fully wrapped; the Purchasing exception `userMessage()` methods also weren't `__()`-wrapped — now fixed, including one with a `:name` placeholder (`NonPurchasableProductException`).
- **Known limitation, not fixable without deeper rework:** Livewire's `#[Title('...')]` attribute (browser tab title) can't call `__()` — PHP attribute arguments must be compile-time constants. Tab titles stay in English; low priority.

### Environment constraint discovered

This dev environment's PHP has **no `pdo_sqlite` driver**, only `pdo_mysql`. `phpunit.xml` is configured for an in-memory sqlite test DB (correctly — it fails closed, doesn't fall back to the real MySQL DB), but that means **no automated Feature/Pest tests can currently run** without installing the driver (not done — installing PHP extensions wasn't authorized). Workaround used for verifying Blade/Livewire changes render correctly without a browser: render the compiled view directly via `view('pages::...')->render()` in a one-off PHP script (sharing an empty `errors` bag manually, since that's normally injected by middleware) — confirms Blade compiles and `__()`/translations resolve correctly, though it can't catch client-side/Alpine/JS behavior (like the select-placeholder bug above, which was reasoned from the HTML spec + Flux source, not empirically observed).

## Session 5 (2026-07-25) — Create PO polish: loading feedback, numeric inputs, layout, unit-price design

**UI fixes on Create Purchase Order page** (`resources/views/pages/purchasing/orders/create.blade.php`):

1. **Loading feedback for the supplier round-trip.** Picking a supplier triggers a real Livewire network request (resolves the `selectedSupplier`/`availableProducts` computed properties) with no prior visual feedback — the native `<select>` shows the new value instantly (native browser behavior, independent of Livewire), but the supplier card and Line Items table only update once the response comes back, which read as unexplained lag. Fixed with `wire:loading`/`wire:target="supplierId"`: the select disables itself and shows a small `flux:icon.loading` spinner + "Loading supplier details..." text while in flight; the Line Items area shows a 3-row `animate-pulse` skeleton (respects `motion-reduce:animate-none`) in place of the placeholder/table during the same request, via `wire:loading.remove`/`wire:loading` sibling `<div>`s wrapping the existing `@if/@else`. No JS needed — pure Livewire directives, same spinner icon Flux's own `flux:button` already uses elsewhere in this app.

2. **Native number-input spinner overlapped the value** (user's screenshot showed "6" rendered behind the up/down arrows on Qty/Unit Price). First attempt — Tailwind's `[&::-webkit-inner-spin-button]:appearance-none` etc. added to `<flux:input class="...">` — silently did nothing. **Root cause found:** Flux's `flux:input` component (`vendor/livewire/flux/stubs/resources/views/flux/input/index.blade.php:140` vs `:154`) applies the `class` prop to the **outer wrapper `<div>`**, not the actual `<input>` element — the real input's own classes come from a separate `class:input="..."` prop instead. `text-right`/`font-mono`/`tabular-nums` "worked" anyway only because `text-align` and `font-family` are CSS-*inherited* properties; `::-webkit-inner-spin-button` isn't inherited, so it silently no-op'd on the wrapper. Per the user's steer ("work around it, don't fight it") — rather than switching to `class:input`, swapped Qty/Unit Price from `type="number"` to `type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*"`. Removes the native spinner permanently on every browser, keeps the numeric mobile keyboard, and needed no backend change since `numeric`/`gt:0` validation already runs server-side in `rules()`.

3. **Column layout reworked for Indonesian Rupiah values** (e.g. `1.000.000`). Simple width bumps (`w-28`→`w-32`, `w-36`→`w-48`) weren't enough — user gave explicit permission to rearrange rather than just widen further. Removed the dedicated **Stock** `<th>`/`<td>` entirely and folded the stock badge (dot + quantity + "Low" flag) into the existing meta line under the product `<flux:select>`, using `justify-between` so product code/unit stay left and the stock badge is pushed to the cell's right edge. This freed a full column's width, redistributed to **Qty** (`w-32`→`w-40`) and **Unit Price** (`w-48`→`w-60`, comfortably fits `100.000.000`). Product column `min-w-60`→`min-w-48`. The loading skeleton (see #1) was updated from 5 placeholder blocks to 4 to match the new column count.

**Design/teaching discussion (guide-only, not yet implemented — per established backend preference): unit_price default-from-catalog-price.**

- User pointed out `product_supplier.price` exists and asked for a guideline (not code) to make the Create PO form's `unit_price` field *default* to that catalog price on product selection while staying freely editable — deliberately **not** locked/derived like `subtotal`/`total_amount` are.
- Verified `withPivot('price')` is already declared on both `Product::suppliers()` (`app/Models/Product.php:37`) and `Supplier::products()` (`app/Models/Supplier.php:25`) — meaning `$this->availableProducts` (already loaded in the component) already carries `->pivot->price` per product. No extra query needed to implement this.
- Planned mechanic (talked through, not yet written by user): a generic `updated(string $name, mixed $value)` Livewire lifecycle hook — **not** a per-property `updatedItemsNProductId()` magic method, which doesn't exist for dotted array paths like `items.0.product_id` (that magic-name generation only works for plain top-level properties, e.g. the existing `updatedSupplierId()`). Inside, `preg_match('/^items\.(\d+)\.product_id$/', $name, $matches)` to catch only product-row changes — this same hook fires for *every* synced property project-wide (`orderDate`, `items.N.quantity_ordered`, etc.), so the regex guard is load-bearing, not optional. Then look up the product in `$this->availableProducts`, and set `$this->items[$index]['unit_price']` via `bcadd((string) $product->pivot->price, '0', 2)` if found, else `''` if not (never guess a price).
- Policy: **overwrite unconditionally** when a row's product changes, don't try to preserve a stale price — same reasoning as the existing "swap product = remove + add" design for `UpdatePurchaseOrderItemAction`.
- No backend changes needed anywhere — `CreatePurchaseOrderAction`/`CreatePurchaseOrderData`/`rules()` already treat `unit_price` as arbitrary per-item input; this is purely a frontend UX default-fill.
- **Edge case resolved:** the previously-flagged "`product_supplier` has 0 rows" data gap (Session 4) no longer needs special-case handling here — `database/seeders/ProductSupplierSeeder.php` now exists (alongside `ProductSeeder`/`SupplierSeeder`), so `->pivot->price` should have real data once seeded. The `null`-price fallback (blank field, user types manually) stays as correct defensive behavior for any product genuinely missing a catalog price, but is no longer expected to be the everyday case.

## Session 6 (2026-07-25 to 2026-07-29) — List PO, View PO, Alpine/theme bug, Approve Action begins

### Create PO page — final polish (carried over from tail of Session 5)
Walked the user through the existing `create()` method line-by-line (teaching mode, no new code): the blank-row `array_filter`/`array_values` logic (`OR` not `AND` — a partially-filled row must survive to hit a real validation error, not be silently dropped; user initially proposed `AND`, talked through why that causes silent data loss, then self-corrected once reminded this form only ever produces a `Draft`, not a submission), `$this->validate()` (traced to `Livewire\Features\SupportValidation\HandlesValidation`, confirmed `rules()`/`validationAttributes()` are optional hooks discovered via `method_exists()` — not framework-reserved names), the `CreatePurchaseOrderData` DTO construction (named arguments, `createdBy: Auth::id()` deliberately not user-suppliable), and the `try/catch` around only `NonPurchasableProductException` (foreseeable domain failure → handled gracefully; anything else deliberately left to bubble to Laravel's default handler).

Two small UI fixes: the Subtotal `<td>` was the only column with no explicit width, so it could shrink below `"Rp 150.000"` and wrap at the space after `"Rp"` — fixed with `whitespace-nowrap`. Redesigned the per-row stock indicator (was: always-on colored dot + text only on the Low case, i.e. color-not-only violation) into: plain quiet text for normal stock, a bordered chip with `flux:icon.exclamation-triangle` + "Low · N" only when `isBelowReorderPoint()` — reserves visual weight for the exceptional/actionable case instead of decorating every row equally.

### Sidebar
Added a "Create Purchase Order" item (`document-plus` icon) to the Purchasing group, alongside Dashboard. Investigated animating the `flux:sidebar.group` expand/collapse (user asked) — traced to Flux's `<ui-disclosure>` custom element doing a hard `hidden`/`data-open:block` toggle with zero transition support (confirmed via `vendor/livewire/flux/stubs/.../sidebar/group.blade.php` and `flux.css`). Real fix would require a global CSS override (`@starting-style`/`allow-discrete`) targeting Flux's *undocumented internal* class names — flagged as a genuine vendor-coupling fragility tradeoff (works today, could silently break on a Flux upgrade with no warning) and **deliberately skipped**, not built.

### Purchase Order List page — `purchasing.orders.list` (route name explicitly chosen by user over `.index`)
`resources/views/pages/purchasing/orders/list.blade.php`, guarded by the `purchasing.view` Gate. Built with search (debounced, escapes `%`/`_`)/status-filter/sort toolbar, all `#[Url]`-synced (bookmarkable/refresh-safe) + Livewire's `WithPagination`, status badges color-mapped per `PurchaseOrderStatus` case via a component-local `statusBadgeClasses()` helper (kept off the enum itself, same separation as `label()`).

**Design iteration:** first pass had 3 top-of-page KPI stat cards (Pending Approval / Open Orders / Overdue Deliveries counts) mirroring a reference mockup. User later called these out as "the kind of thing that's common when designing with AI" and asked for a redesign via `/ui-ux-pro-max` — replaced with a single **contextual summary bar directly under the table**: `"N orders match the current filters · Total value Rp X"`, with an overdue-count notice that only renders `@if` count > 0 instead of a permanent tile. Refactored the query logic behind this into a shared `filteredQuery()` method (search+status only, no sort/pagination) so `orders()`, `filteredTotalValue()`, and `overdueCount()` all stay consistent with whatever's currently filtered — previously `overdueCount` was silently global regardless of the active filters, which would have been a confusing mismatch next to a scoped total-value figure.

PO Number cells now link to the View PO page (added once that route existed).

### Low-stock reorder-point notification — designed, explicitly NOT built yet
User asked for a full UI-to-backend guideline for a "notify when stock drops below reorder point" feature (guide-only, per established backend preference). Full chain documented: Events/Listeners (`ProductBelowReorderPoint` → a Listener, not inline in the Action — same "...and then it also..." Service/Action litmus already established), Laravel's built-in database Notification channel (not a hand-rolled table), role-scoped recipients (Purchasing + Manager), edge-triggered vs. level-triggered+dedupe detection tradeoff, and a bell-icon UI following the project's own no-WebSockets decision (plain `wire:poll`, consistent with `BROADCAST_CONNECTION=log`).

**Concluded not buildable yet, and deliberately not started:** this is blocked on more than just `CreateStockMovementAction` (itself unwritten) — the *decrease* direction of stock (the only direction that can trigger a reorder-point breach) has **no real code path at all** yet in this codebase. `ReceivePurchaseOrderAction` (also unwritten) only ever *increases* stock. Actual consumption belongs to the Production/Sales modules, neither of which exist yet. Explicitly parked as an idea, not scaffolded — scaffolding untestable pieces now was rejected as exactly the "no half-finished implementations" anti-pattern the project avoids.

### View Purchase Order page — `purchasing.orders.show` (`/purchasing/orders/{purchaseOrder}`)
Scoped via `AskUserQuestion` before building: user chose the bigger option — **read-only info + inline item editing while Draft** (not just read-only + Submit). This surfaced a real gap: **`PurchaseOrderPolicy` had no `update()` ability** for "can this user edit this PO's line items" — user added it themselves (mirrors `submit()`'s shape: `purchasing.create` Gate + `status === Draft`, but — per user's explicit decision — **no `created_by` restriction**, unlike `submit()`. Reasoning: matches `cancel`/`approve`/`reject`, none of which are creator-locked either; a Draft PO is team-editable, not personal-until-submitted).

Built from a supplied reference screenshot (`detail.png`) — kept the reference's *structural* idea (3 info cards + line-items table + top action button) but reskinned entirely to house style and real schema fields, not the reference's literal terminal/hacker aesthetic or its invented fields (`Department`, tax/logistics breakdown don't exist in this schema — swapped for `Created By` and a simple Total). Per explicit instruction, the header's primary CTA is **"Submit for Approval"** (`paper-airplane` icon, gated `@can('submit', ...)`), not an "Edit" button — editing happens inline in the table itself, not behind a separate mode toggle.

**Editing model, a deliberate choice:** existing item rows use plain `wire:model` (deferred, no live network chatter per keystroke) + an explicit per-row checkmark button calling `updateItem($id)` — chosen over live/auto-save specifically so the displayed Subtotal always reflects the *actual persisted* value, never a locally-computed guess that could drift from what's really saved. New-item row reuses `AddPurchaseOrderItemAction`. Existing rows' Product is correctly non-editable (matches `UpdatePurchaseOrderItemAction`'s signature — no `productId` param, swap = remove+add). Only wires buttons for Actions that exist today — no Approve/Reject/Receive/Cancel yet, per "grow as built."

Built a `updated()` catalog-price-autofill hook for the new-item row's `unit_price`, mirroring Create PO's Session 5 pattern exactly — user built this themselves with guidance, including a live debugging pass (see below).

### Three real bugs found and fixed this session (not UI polish — genuine full-stack debugging)

1. **Theme reverting to dark on every reload.** Root cause: **two independent Alpine.js instances on one page.** `resources/js/app.js` had `import Alpine from 'alpinejs'; window.Alpine = Alpine; Alpine.start()` — but Livewire *already* bundles and auto-starts its own Alpine (via `@fluxScripts`), and Flux's appearance-store logic (traced through the vendor JS bundle, `vendor/livewire/flux/dist/flux.min.js`) is written assuming exactly one instance exists. Two instances racing to initialize on each load explained the inconsistent, order-dependent behavior (same-origin confirmed, no thrown errors, since these are genuinely separate module instances that don't share Alpine's own duplicate-`.start()` warning flag). Fixed by stripping `app.js` back to empty — nothing in the codebase depended on the manually-imported `@alpinejs/mask` plugin at the time.
2. **`x-mask` money formatting, three separate bugs in sequence** as the user built a Rupiah input mask: (a) plugin never registered — `app.js` referenced `mask` in an `alpine:init` listener with no `import mask from '@alpinejs/mask'`, threw `ReferenceError` and silently no-opped the whole directive; (b) `$money($input, ',', '.' 0)` — missing comma made it invalid JS, threw `SyntaxError`; (c) even once syntactically valid, `$money()`'s actual signature (verified by reading `node_modules/@alpinejs/mask/src/index.js`) is `(input, delimiter, thousands, precision)` where `delimiter` is the *decimal* character, not thousands — the user's original argument order/count put values in the wrong slots.
3. **Unit price showing `3.000.000` for a `30000.00` value (100× inflated).** Root cause: `formatMoney()` with `precision: 0` strips any character that isn't a digit or the configured decimal-delimiter — since the bound value came straight from a `decimal:2`-cast Eloquent attribute (always has a literal `.00`), and `.` wasn't configured as the decimal delimiter, the `.00`'s two digits got silently absorbed as extra whole-number digits, inflating the display by 100×. Fixed at the source, not in the mask config: `(string) (int) $item->unit_price` in `syncItemsFromModel()`, so a decimal point never reaches the masked input at all — consistent with the project's standing "Rupiah has no cents in practice" stance from Session 5.

Also: caught the user leaving a debugging `dd()` inside the `updated()` hook after using it to confirm a computed value — explained that `dd()` doesn't just dump, it **terminates the script**, so the Livewire request never completes/re-renders while it's still there; looked like "the value won't populate" but was actually "the response never arrives."

### `ApprovePurchaseOrderAction` — backend track resumes
Guided design (no code from assistant, per standing preference): needs `(PurchaseOrder $po, int $approvedBy)`, no authorization inside the Action (Policy's job), `transitionTo(PurchaseOrderStatus::Approved)` for the status write (never bypass it, even to save a query), then guarded fields `approved_by`/`approved_at` set via direct assignment + explicit `save()`, both wrapped in `DB::transaction()`. First draft reviewed had two real bugs: the class was literally named `CreatePurchaseOrderAction` (filename/class mismatch — would have fatally collided with the real `CreatePurchaseOrderAction` class in the same namespace) and set `approved_by`/`approved_at` without ever calling `save()` (silent no-op — values set in memory, never persisted). Second draft, after fixes, reviewed correct. Not yet wired into the View PO page's UI (no Approve button yet) and `RejectPurchaseOrderAction` not yet started.

## Session 7 (2026-08-01 to 08-03) — `stock_movements` ledger built, `CreateStockMovementAction` completed, `ReceivePurchaseOrderAction` designed

New branch, `stock_movement`. Picked up handover items #5/#6 (design `stock_movements`, write `CreateStockMovementAction`) ahead of `RejectPurchaseOrderAction`/the Approve button wiring, which remain untouched from Session 6.

**Schema + models (2026-08-01):** `stock_movements` migration, `Direction`/`StockMovementType` enums, `StockMovement` model, `CreateStockMovementData` DTO, morph map registration in `AppServiceProvider::boot()`. Full detail in "Inventory Domain Models" above.

**`CreateStockMovementAction` — written and completed (2026-08-02/03).** User wrote each draft, assistant reviewed (guide-only, no code from assistant for this Action, per standing preference) — multiple real bugs caught across several passes:
- A malformed `Product::where('id', $data->productId, null, 'and')` call (4 positional args misused) that Laravel silently reinterpreted as `WHERE id IS NOT NULL` — would have locked and updated an **arbitrary** product on every call, with no error thrown, since the table isn't empty. The most severe bug caught this session — fixed to the correct 4-arg form `where('id', '=', $data->productId, 'and')`.
- A missing `amount` key in the `StockMovement::create()` array (DB NOT NULL violation waiting to happen).
- `handle()` never returning the value built inside the `DB::transaction()` closure — same "value computed but never surfaces from the method" shape as `ApprovePurchaseOrderAction`'s first-draft bug from Session 6. Fixed by `return`-ing the `DB::transaction(...)` call itself.
- `Product::stockMovement()` renamed to `stockMovements()` (singular-name inconsistency flagged during review, fixed same session).

**Negative-stock guard added, `InsufficientStockException` created** — mirrors `NonPurchasableProductException`'s exact shape (promoted `readonly` properties, technical message + translatable `userMessage()`). Went through its own review cycles: `$requested`/`$available` briefly typed `int` (would truncate fractional quantities, fixed to `string`), and `userMessage()` briefly referenced bare `$requested`/`$available` instead of `$this->requested`/`$this->available` (undefined-variable bug, fixed). Guard scoped to `Direction::Out` only, checked via `bccomp($newStock, '0', 2) < 0` before any write. Not yet localized into `lang/id.json`.

**Dev/test data:** created a dummy `warehouse`-role user (`warehouse@test.com` / `password`, id 7) via `php artisan tinker` for manual testing — one-off, not a seeder.

**Over-receipt policy decided (2026-08-03):** allowed freely, not blocked/capped — see "Inventory Domain Models" above for the full reasoning (prioritizes `current_stock` accuracy over a tidy paper trail, matches architectural decision #2).

**`ReceivePurchaseOrderAction` — fully designed, not yet coded.** `ReceivePurchaseOrderData` DTO written (`purchaseOrderItemId`, `quantityReceived` as `string`, `receivedBy`, `notes` nullable with a default — deliberately **excludes** `received_at`, set to `now()` inside the Action instead, since nothing in the project currently needs caller-supplied backdating). Full finalized step sequence recorded in the Actions table section above — supersedes the original Session-era sketch, most notably: the status check needs to be explicit inside the Action (not just relying on `transitionTo()`, since over-receipt means status doesn't always change), `quantity_received` should be recalculated from a fresh `SUM()` over receipts rather than incremented (mirrors `recalculateTotal()`), and `transitionTo()` must only be called when the target actually differs from the current status (`PartiallyReceived → PartiallyReceived` isn't a legal transition in the enum, would incorrectly throw).

## Session 8 (2026-08-09) — `ReceivePurchaseOrderAction` written, `notes` → `receipt_condition`, warehouse access gap found

New branch, `receive_po`. Picked up between-session work first: `RejectPurchaseOrderAction` and `ReopenPurchaseOrderAction` had already been written and wired into View PO (commit "approve, reject, reopen PO") — not re-verified in detail this session, just confirmed present.

**Design change before coding: `purchase_order_receipts.notes` replaced with a structured `receipt_condition` enum**, user's own idea — a free-text note per receipt was judged less useful than a fixed classification, especially since this data will likely feed the future Cost Accounting/production-cost-tracking work (see notes below). New enum `PurchaseOrderItemReceiptCondition` (`ReceivedAsExpected`, `ReceivedMoreThanOrdered`, `ReceivedLessThanOrdered`, `DamagedGoods`), `NOT NULL` on the column (no nullable/default — every receipt must be classified, unlike the free-text `notes` it replaced). New migration for this lives at `database/migrations/purchase/2026_08_09_012720_update_purhase_order_receipts_table.php` — **deliberately outside the flat `database/migrations/` convention**, a user choice to start organizing migrations into subfolders. Confirmed this requires either `php artisan migrate --path=database/migrations/purchase` on every run, or registering it permanently via `$this->loadMigrationsFrom(database_path('migrations/purchase'))` in `AppServiceProvider::boot()` if subfolders are meant to "just work" with a plain `php artisan migrate` going forward — **not yet registered**, user is using `--path` manually for now. The original (already-applied) `create_purchase_order_receipts_table` migration also lost its `$table->index('purchase_order_item_id')` line — confirmed **intentional** (user's own call, not an accident), inert either way since that migration already ran.

**`ReceivePurchaseOrderAction` — written, reviewed through several passes, all caught issues fixed:**
- `handle()`'s `DB::transaction()` closure never `return`ed `$receipt` (same "value computed but never surfaces" shape as `CreateStockMovementAction`'s and `ApprovePurchaseOrderAction`'s first-draft bugs in earlier sessions) — fixed.
- `PurchaseOrderReceipt::casts()` initially cast `receipt_condition` to `PurchaseOrderReceipt::class` (copy/paste slip — cast the attribute to the model itself instead of the enum) — fixed to `PurchaseOrderItemReceiptCondition::class`.
- Zero-quantity boundary bug: initial check was `quantityReceived < 0`, letting `0` through and creating a meaningless receipt + zero-amount stock movement; fixed to reject `<= 0`, and further tightened from a raw `<=` comparison to `bccomp($data->quantityReceived, "0", 2) <= 0` per the project's bcmath-only convention for quantities.
- `PurchaseOrderItem::syncQuantityReceived()` and `InvalidReceiptQuantityException`'s constructor were both initially typed `int` instead of `string` for a quantity value — same silent-fractional-truncation class of bug already caught once on `InsufficientStockException` in Session 7. Fixed to `string` in both places.
- `syncQuantityReceived()` initially **incremented** the cached `quantity_received` (`bcadd` onto its current value) instead of recomputing a fresh `SUM()` over `receipts()` as the finalized design specified — fixed to `$this->quantity_received = $this->receipts()->sum('quantity_received');`, parameterless now (matches `syncSubtotal()`'s "computes, doesn't take the raw delta" shape), restoring the self-healing property if a receipt is ever corrected/voided later.
- The `CreateStockMovementData` call initially used `Auth::id()` for `createdBy` instead of `$data->receivedBy` — inconsistent with the receipt's own `received_by` (which correctly used `$data->receivedBy`), and broke the project's convention of Actions never reaching into global auth state directly. Fixed; the now-unused `Auth` import was also removed.
- `PurchaseOrderNotReceivableExcepiton` (typo — "Excepiton") — filename and class both renamed to `PurchaseOrderNotReceivableException`.
- New model helper `PurchaseOrder::ensureCanReceive()` added (mirrors `ensureIsEditable()`'s shape), throwing unless status is `Approved`/`PartiallyReceived` — matches `PurchaseOrderPolicy::receive()`'s own check, needed as an explicit in-Action guard per the Session 7 design note (this Action doesn't unconditionally call `transitionTo()`, so nothing else guarantees the status check runs every time).

Final reviewed-correct shape: lock the item → reject `<= 0` quantity via `bccomp` → `ensureCanReceive()` → create the receipt (with `receipt_condition`, `received_at: now()`) → `syncQuantityReceived()` fresh-sum + save → recompute PO status via `items()->get()->every(isFullyReceived())`, only calling `transitionTo()` if the target differs from current status → call `CreateStockMovementAction` with `referenceType: 'purchase_order_receipt'` (the morph-map key, not the FQCN) → return `$receipt`.

**Real gap discovered while whiteboarding the frontend flow, not yet fixed:** a `warehouse`-role user currently **cannot reach any PO page at all**. `purchasing.orders.list` (`list.blade.php:27`) gates on `Gate::authorize('purchasing.view')`, and `PurchaseOrderPolicy::view()` delegates to the same `purchasing.view` Gate — both are `Purchasing`/`Manager`-only in `AppServiceProvider::boot()`. `PurchaseOrderPolicy::receive()` and the `warehouse.receive` Gate already exist and correctly check `Approved`/`PartiallyReceived` status, but nothing upstream lets a warehouse user reach a PO to trigger it. Needs deciding: broaden `purchasing.view`/`PurchaseOrderPolicy::view()` to also allow `warehouse.receive`-authorized users, and decide whether warehouse discovers receivable POs via the existing list (would show Draft/PendingApproval/Rejected rows they can't act on) or a separate scoped list. **No UI for the receive flow exists yet at all** — no button, no per-item quantity input, no `receiptCondition` dropdown, no Livewire method calling this Action.

**UX decisions locked for the eventual UI (not yet built):** one "Confirm Receipt" button per PO (not per line item) — a real delivery covers several items at once, so the trigger should be batched even though the Action itself stays scoped to one item per call; the Livewire method will loop over touched rows and call `ReceivePurchaseOrderAction::handle()` once per item. Each item's call commits independently (**no outer transaction** wrapping the loop) — deliberate, matches the project's own over-receipt reasoning: a failure on item 3 of a 3-item shipment shouldn't roll back items 1-2's already-true physical receipt just for transactional neatness. Rows with blank/zero quantity are simply skipped by the caller (not sent at all), on top of the Action's own `bccomp <= 0` guard staying in place as defense-in-depth. `receiptCondition` dropdown wording drafted: `received_as_expected` / "Diterima Sesuai Pesanan", `received_more_than_ordered` / "Diterima Lebih dari Pesanan", `damaged_goods` / "Barang Rusak" (a 4th enum case, `ReceivedLessThanOrdered`, was added during implementation, not yet given a discussed Indonesian label).

**Pinned, not yet fixed (see Immediate Next Steps #19):** `config/app.php:68` hardcodes `'timezone' => 'UTC'`; every `now()` call project-wide (including this Action's `received_at`) is stamped in UTC with no display-time conversion to `Asia/Jakarta` anywhere in the UI. Also noticed: `AppServiceProvider::configureDefaults()` (which would set `Date::use(CarbonImmutable::class)`) is defined but never called from `boot()` — dead code, `now()` currently returns mutable `Carbon`.

## Concepts Already Taught (don't re-explain unless asked)
- Input validation vs. business validation vs. authorization (three distinct layers)
- Services vs. Actions (workflow orchestration vs. atomic operation)
- Events/Listeners for decoupled side effects
- Why cached/derived fields (stock, totals) must be calculated, not manually set
- Row locking (`lockForUpdate()`) and transactions for concurrency safety, as the actual fix for concurrent-stock-edit risk (instead of real-time UI)
- Real-time backend cache consistency vs. real-time UI push — two separate concepts, only the former is needed
- Service Container, bindings, and Service Providers (`register()` vs `boot()`) — used to override Fortify's `LoginResponse` without touching vendor code
- Gate vs. Middleware (rule definition vs. enforcement checkpoint) — and Gate vs. Policy (model-less vs. model-tied permission)
- `Fillable` (incoming mass-assignment whitelist) vs. `Hidden` (outgoing serialization blacklist) — two independent concerns
- `#[Guarded([...])]` used specifically to lock out *system-derived* fields (stock, status, totals, subtotals) from mass assignment, distinct from `#[Fillable]` being a general whitelist
- Enum-driven finite state machine: the enum owns the legal transition graph (`canTransitionTo`), the model owns enforcement through one single mutator method (`transitionTo`) — why this beats letting any code path do `update(['status' => ...])` directly
- Why bcmath (`bccomp`/`bcsub`/`bcmul`) is used instead of native float comparison/arithmetic for money and quantities — binary floating point rounding error compounds across a ledger
- Custom Pivot models (`extends Pivot`, `#[Table(...)]`, wired via `->using()`) for many-to-many relationships that carry real data, vs. Laravel's default anonymous pivot
- FK delete behavior chosen deliberately per relationship (`cascadeOnDelete` vs `restrictOnDelete` vs `nullOnDelete`) rather than one default across the schema
- Laravel 13 removed `unsignedDecimal`/`unsignedFloat`/`unsignedDouble` from the migration Blueprint — use `->decimal(...)->unsigned()` instead
- `php artisan migrate --path=...` scopes which files are considered, but only runs ones not already recorded in the `migrations` table — need `migrate:rollback --path=...` first to re-run an already-applied file
- Guarding (`#[Guarded]`) blocks *mass assignment* specifically (`fill()`/`update()`/constructor array), not direct property writes — a model's own internal methods can and should bypass it via `$this->field = $value; $this->save();` when the field is guarded precisely to keep external callers from setting it directly
- DTOs are only worth it past ~5 related fields (project's own threshold) — `UpdatePurchaseOrderAction` (3 fields) and the item Actions (2-3 fields) deliberately stayed plain method params instead of getting DTO classes
- Action vs. Service litmus test: if the method's job needs "...and then it also..." to describe it — i.e. it composes other Actions or crosses module boundaries — it's a Service; if it's producing one aggregate in one sentence, it's an Action
- Larastan's method-style `casts()` support needs `parseModelCastsMethod: true` in `phpstan.neon` — off by default
- Livewire lifecycle hooks (`mount()`, `updating()`, `updated()`, `updated{Property}()`) are **magic hooks by convention, not PHP-reserved words** — Livewire's base `Component` class checks whether your class defines a method with that exact name and calls it automatically at the right point, the same pattern as a Service Provider's `boot()`. You never call them yourself.
- `wire:model` vs `wire:model.live` is a **timing** difference, not a different sync mechanism: `.live` fires a request immediately on change/input; plain `wire:model` defers the value until *some* request happens for any reason (another `.live` field, a button click, etc.), at which point it rides along and syncs then. `updated()` fires for both — `.live` just controls *when*.
- `updated{PropertyName}()` (e.g. `updatedSupplierId()`) only works for plain top-level properties — Livewire generates that method name directly from the property name. Dotted array paths like `items.0.product_id` have no valid PHP method-name equivalent, so they only reach the generic `updated(string $name, mixed $value)` catch-all, matched via string/regex on `$name`.
- How a Blade `wire:model.live="items.{{ $index }}.product_id"` (inside a `@foreach`) links to the PHP `updated()` hook: there's no function call between them — Blade substitutes `{{ $index }}` at render time so each row's real HTML has a literal attribute like `wire:model.live="items.0.product_id"`; the browser reads that exact string off the changed element and sends it in the network request; Livewire uses it both to sync `$this->items[0]['product_id']` (via a dot-notation setter) *and* as the `$name` argument passed into `updated()`. One shared string does all three jobs.
- `updated()` is called **once per changed property, per request** — if a single request happens to carry multiple dirty properties (e.g. a deferred `wire:model` field riding along with a `.live` field's request), the hook runs multiple times in that cycle, once per property, each with its own `$name`/`$value`. This is why the regex guard inside it matters: the same hook sees every property change project-wide, not just the one you're targeting.
- `WithPagination`'s `resetPage()` is a thin wrapper over `setPage(1, $pageName)`, which sets `$this->paginators[$pageName] = 1` (an array, not a plain property — `$pageName` lets a component run multiple independent paginators). Calling it inside an `updating{Property}()` hook (e.g. `updatingSearch()`) guards against landing on a now-out-of-range page number after a filter shrinks the result set.
- `#[Url]` and `WithPagination`'s own `page` query-string registration are the *same underlying mechanism* (Livewire's query-string sync) applied two ways — one explicit via attribute, one automatic via the trait's `queryStringHandlesPagination()`. Both make component state bookmarkable/refresh-safe by syncing to the browser URL via `pushState`, not a full reload.
- **"Magic method" precision:** `updatingSearch()`, `updated()`, `mount()`, `rules()` etc. are *not* real PHP magic methods (`__get`/`__set`/etc., which the PHP engine itself recognizes). They're Livewire's own userland convention: `method_exists($this, $constructedName)` checks sprinkled through Livewire's own source, calling your method *in addition to*, not *instead of*, its normal internal behavior if found. Purely additive — never assume defining one "overrides" framework behavior.
- `dd()` is "dump and **die**" — it terminates the PHP process immediately after dumping. Fine for confirming a value in isolation, but leaving it inside a Livewire action method means the request never finishes rendering/responding — a symptom that looks exactly like "the UI isn't updating" is actually "the response never arrived because the script died mid-request." Remove it the moment the value is confirmed.
- A Laravel/Livewire project only needs **one** Alpine.js instance — Livewire bundles and auto-starts its own. Manually `import Alpine from 'alpinejs'; Alpine.start()` in `app.js` creates a second, independent instance racing the first to initialize on every page load, causing exactly the kind of order-dependent, hard-to-reproduce bugs (Flux's appearance/theme store silently losing its persisted value on some loads but not others) that don't throw errors because the two instances don't share Alpine's own duplicate-instance detection. To add an Alpine plugin safely, hook into Livewire's own instance via `document.addEventListener('alpine:init', () => Alpine.plugin(pluginFn))` instead of starting a second one.
- `@alpinejs/mask`'s `$money(input, delimiter, thousands, precision)` — verified from actual plugin source, not assumed: `delimiter` is the *decimal-point* character (defaults to `.`), not the thousands separator; `thousands` is the actual character inserted every 3 digits; get the argument order wrong and values silently land in the wrong slot rather than erroring. With `precision: 0`, feeding it a value that already contains a literal decimal point (e.g. straight from a `decimal:2`-cast Eloquent attribute, always `"30000.00"`) inflates the displayed number 100× — the mask strips the `.` as a non-digit and the trailing two decimal digits get absorbed as extra whole-number digits. Fix at the data source (cast to a whole-number string before binding), not by fighting the mask's config.
- Flux's `flux:input` component detects `.live` on the bound `wire:model` automatically and injects its own trailing loading spinner for free (`vendor/livewire/flux/stubs/.../input/index.blade.php`) — opt out per-field with `:loading="false"` if you're handling loading feedback yourself elsewhere (e.g. a scoped spinner in an adjacent cell via `wire:target`).

## Immediate Next Steps (pick up here in new session)

**Backend/domain track (current focus):**
1. ~~Write `RejectPurchaseOrderAction`~~ **Done** (between sessions, commit "approve, reject, reopen PO") — wired into View PO.
2. ~~Wire an Approve button into the View PO page~~ **Done** (between sessions) — Approve, Reject, and an unplanned `ReopenPurchaseOrderAction` ("Move to Draft") are all wired into `show.blade.php`.
3. Decide the `Closed` status question — enum case is still commented out; `purchasing.close` Gate and `PurchaseOrderPolicy::close()` already assume it exists.
4. Decide the soft-deletes question — `products`/`suppliers` migrations don't have `softDeletes()` despite earlier draft schema assuming it. Implement, or drop from the plan.
5. ~~Design `stock_movements` table~~ **Done (Session 7)** — migrated, see schema block above.
6. ~~Finish `CreateStockMovementAction::handle()`~~ **Done (Session 7)** — written, reviewed, negative-stock guarded via `InsufficientStockException`.
7. ~~Write `ReceivePurchaseOrderAction`~~ **Done (Session 8, 2026-08-09)** — written, reviewed through several passes, reviewed-correct. See Session 8 write-up above for full bug list caught during review.
8. ~~Finalize the PO status recalculation logic~~ **Done (Session 8)** — coded as designed: fresh `SUM()` over receipts, `transitionTo()` only called when target differs from current status.
9. Revisit Purchase Order **cancellation** rules — e.g. can a partially-received PO be cancelled? What happens to already-received stock? (`CancelPurchaseOrderAction` itself isn't written yet either — only the Gate/Policy method exist.)
10. ~~Decide over-receipt policy~~ **Decided (Session 7): allowed freely**, not blocked/capped — see "Inventory Domain Models" above.
11. Decide whether the missing `unique(['purchase_order_id', 'product_id'])` constraint on `purchase_order_items` is intentional (same product as two separate line items) or should be added.
20. **Build the receive-PO UI** — nothing exists yet (see Session 8 write-up for the locked-in UX shape: one "Confirm Receipt" button per PO, per-item quantity + `receiptCondition` dropdown, caller loops calling `ReceivePurchaseOrderAction` once per touched item, no outer transaction across the loop). Blocked on #21 first, or warehouse users can't even reach the page to use it.
21. **Fix the warehouse PO-access gap (Session 8 finding)** — `purchasing.orders.list` and `PurchaseOrderPolicy::view()` are both `purchasing.view`-gated (`Purchasing`/`Manager` only), so a `warehouse`-role user currently gets a 403 on every PO page, even though `PurchaseOrderPolicy::receive()`/`warehouse.receive` already exist and work. Decide: broaden `view()` to include warehouse, and whether warehouse gets the same list or a separate one scoped to `Approved`/`PartiallyReceived` POs.
22. Decide whether to register `database/migrations/purchase/` via `loadMigrationsFrom()` in `AppServiceProvider::boot()` (Session 8) — currently requires `--path` on every `artisan migrate` invocation if left unregistered.

**UI/design track (paused, not abandoned):**
12. **Verify Gates/Policies actually behave as intended, in the browser** — still not exercised end-to-end: login as `purchasing` and confirm they *cannot* approve a PO (Manager-only rule) but *can* edit/cancel one; login as `manager` and confirm dashboard-override + approval both work. Now more testable than before since View PO's inline editing exists.
13. Translate the List PO and View PO pages' new strings into `lang/id.json` — built this session, not yet localized (same deferred-content-only gap as the 2FA/passkey pages from Session 4).
14. Consider whether `pdo_sqlite` should be installed so automated Feature/Pest tests can actually run in this environment (currently blocked — see Session 4 notes).
15. Low-stock reorder-point notification — fully designed (see Session 6 above), deliberately not started; blocked on `stock_movements` + `CreateStockMovementAction` + an actual stock-*decrease* code path (Production/Sales module, doesn't exist yet).
16. Login page: decide if `<x-passkey-verify>` should be (re-)added — it's fully functional (real routes + JS) but currently unused anywhere in the app.
17. **Unresolved from Session 3:** whether to (a) re-sweep PHPDoc relation generics (`@return BelongsTo<X, $this>` etc.) across all 6 Purchasing models — attempted once, got reverted before landing, and per Session 6's CI failure list (`phpstan analyse`, run via `tests.yml`'s "Run Type Analysis" step) this is no longer just cosmetic, it's now failing the build — and (b) how to fix the IDE's "`Property $id accessed via magic method Model::__get()`" nag: hand-write `@property` docblocks (started on `PurchaseOrder.php`, only `$id` so far) vs. install `barryvdh/laravel-ide-helper` to auto-generate them.
18. **CI is currently red** — `tests.yml`'s `phpstan analyse` step is failing with ~11 errors, grouped into: (a) the relation-generics gap above (undefined-method/property errors on a bare `Model` type), (b) `bccomp()`/bcmath calls expecting `numeric-string` not plain `string`, (c) direct `Carbon`-cast property assignment (`$po->order_date = $stringValue`) type mismatches, (d) a few one-off missing generics (`UserRole::operational()` return type, `Product`'s `HasFactory` trait, `Product::purchaseOrderItems()`/`suppliers()`). User explicitly deferred fixing this ("ok maybe later") — still outstanding.
19. **Pinned (2026-08-09), not urgent:** `config/app.php:68` hardcodes `'timezone' => 'UTC'` (not read from `.env`). Every `now()` call project-wide (`created_at`, `approved_at`, and the soon-to-exist `received_at` in `ReceivePurchaseOrderAction`) is stamped in UTC, and nothing at display time converts to Indonesia local time (`Asia/Jakarta`, UTC+7) — Blade views just format whatever timezone the Carbon instance already carries. Every timestamp shown in the UI is currently ~7 hours behind local wall-clock time. Decide: convert at display time only (keep storing UTC — more standard practice), or change `app.timezone` to `Asia/Jakarta` outright. User explicitly deferred ("put a pin on that, circle back to it later"). Separately/relatedly: `AppServiceProvider::configureDefaults()` (which calls `Date::use(CarbonImmutable::class)`) is defined but never called from `boot()` — dead code, `now()` currently returns mutable `Carbon`, not `CarbonImmutable`.

---
*Paste this file at the start of a new session to resume with full context.*
