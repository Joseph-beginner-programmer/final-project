# MIMS — Project Handover

**Project:** Manufacturing Information Management System (MIMS)
**Stack:** Laravel 13, PHP 8.3+, MySQL, Livewire, Flux UI, Laravel Fortify (auth)
**Location context:** Indonesia — Bahasa Indonesia labels needed in UI, English/underscore values in DB/code

**Skills in use:** `laravel-architect`, `business-logic-expert`, `manufacturing-domain-expert`, `frontend-product-design`, `livewire-volt-architect`, `laravel-security-expert`

**Working modes:** Two explicit modes requested by user —
- **Teaching mode**: explain concepts deeply (why, not just how)
- **Coding mode**: keep it tight — code + brief rationale, skip deep explanations
Default to teaching mode unless user says "coding mode."

**Current focus (as of 2026-07-25, see Session 5 below for latest): UI/design work.** Backend (Purchasing Actions, Gates, Policies) is stable for now — active effort is on the Livewire/Flux frontend: role-based sidebar, the Create Purchase Order page, theming, and localization. For backend Actions, user writes the code themselves (assistant gives a guide/rundown only, no code, per established preference). For UI/design work, user has explicitly delegated implementation to the assistant — assistant writes the actual Blade/Livewire code, user reviews and course-corrects.

---

## Project Modules (planned)
Purchasing, Inventory, Production, Sales, Cost Accounting, plus Executive/Production dashboards.

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

**NOT YET DESIGNED:** `stock_movements` (cross-module unified inventory ledger — agreed as necessary, schema not drafted yet).

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
| `ApprovePurchaseOrderAction` | PendingApproval → Approved | Authorization: Policy, not inline role check | Not written |
| `RejectPurchaseOrderAction` | PendingApproval → Rejected | Blocked by the open enum issue above | Not written |
| `ReceivePurchaseOrderAction` | Approved/PartiallyReceived → PartiallyReceived/FullyReceived | See full step sequence below. Likely graduates to a **Service** (`ReceivePurchaseOrderService`) rather than staying a plain Action — it's the first workflow that actually composes multiple atomic units (calls `CreateStockMovementAction`, fires an event) instead of just building one aggregate. | Not written |
| `CreateStockMovementAction` | (cross-module, shared) | Centralized, row-locked stock update — NOT YET WRITTEN, only designed conceptually | Not written |

**`ReceivePurchaseOrderAction` full step sequence (agreed, not yet coded):**
1. Authorization check (Policy) — is user allowed to receive POs
2. Business validation — status must be `Approved` or `PartiallyReceived` (not Draft/PendingApproval/Rejected/Cancelled)
3. Business validation — quantity check (don't allow receiving more than outstanding — default to blocking over-receipt unless decided otherwise)
4. Create `purchase_order_receipts` record
5. Increment `purchase_order_items.quantity_received`
6. Recalculate PO overall status (check ALL items — only `fully_received` if every item fully received; `partially_received` if some progress but not all complete)
7. Update product stock via `CreateStockMovementAction` (row-locked, not direct increment)
8. Fire `PurchaseOrderReceived` event → listeners (reorder point check, audit log)
9. All of steps 2–8 in one `DB::transaction()`

**Not yet answered:** exact PO status logic for "2 of 3 items fully received, 1 has zero receipts" (should be `partially_received`) — conceptually agreed, not yet coded into the recalculation logic.

## Purchasing Actions Implemented (Session 3, 2026-07-14/15)

**`app/Actions/Purchasing/CreatePurchaseOrderAction.php`** — takes `App\DTO\Purchasing\CreatePurchaseOrderData` (note: folder is `DTO`, singular — renamed from an initial `DTOs`). Crosses the project's own "5+ related fields → use a DTO" threshold (supplierId, orderDate, expectedDeliveryDate, createdBy, items[]). Steps, all inside one `DB::transaction()`:
1. Insert the PO row with fillable fields + a temporary placeholder `po_number` (a UUID) — needed because `po_number` is guarded, unique, and NOT NULL, but its real value depends on the row's own id, which doesn't exist until after the first insert.
2. Re-save with the real `po_number`, format `PO-{year}-{zero-padded id}` (e.g. `PO-2026-000123`) — decided over a per-year-reset counter to avoid needing a separate counter table.
3. Items are **optional at creation** (`$data->items = []` default) — matches the existing rule that "≥1 item, qty > 0" is enforced later at `SubmitPurchaseOrderForApprovalAction`, not at Create. Supports both "fill the whole form at once" and "start blank, add items later" flows.
4. Each item is validated for `$product->type->isPurchasable()` (raw_material only) — throws `NonPurchasableProductException` (new, mirrors `InvalidStatusTransitionException`'s style) if violated.
5. `$po->recalculateTotal()` at the end.
- Authorization is deliberately **not** inside the Action — assumes the caller already checked `Gate::authorize('create', PurchaseOrder::class)`. Only `Gate::define('purchasing.create', ...)` has been added so far (`AppServiceProvider::boot()`): Purchasing role or Manager, **not** SystemAdmin (SystemAdmin treated as technical/config-only, not a business actor).

**✅ RESOLVED (Session 4, 2026-07-18/19):** All Gates `PurchaseOrderPolicy` depends on are now registered in `AppServiceProvider::boot()` — `purchasing.view`, `purchasing.approve`, `purchasing.cancel`, `purchasing.close`, `warehouse.receive`, alongside the pre-existing `purchasing.create`. Rules: `purchasing.create`/`.cancel`/`.close`/`.view` → Purchasing or Manager; `purchasing.approve` → **Manager only** (deliberate segregation-of-duties choice — Purchasing staff can't approve their own POs even before the Policy's own `created_by !== $user->id` check kicks in); `warehouse.receive` → Warehouse or Manager. `system_admin` is excluded from every one of these (technical/config-only role, not a business actor — consistent with the dashboard-access design). Verified by reading the file directly; not yet exercised via an actual login-as-each-role test in the browser.

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

## Immediate Next Steps (pick up here in new session)

**UI/design track (current focus):**
1. ~~Seed `product_supplier`~~ — **resolved.** `database/seeders/ProductSeeder.php`, `SupplierSeeder.php`, and `ProductSupplierSeeder.php` now exist; run them (and confirm `DatabaseSeeder.php` calls them) if not already done, then this no longer blocks end-to-end testing of the Create PO page's item dropdown.
2. **Implement the `unit_price` default-from-catalog-price behavior** — design fully discussed in Session 5 above (guide-only, user to write it): a generic `updated(string $name, mixed $value)` hook on the Create PO component, regex-matched on `items\.(\d+)\.product_id`, pulling `->pivot->price` off `$this->availableProducts` (already carries it via existing `withPivot('price')`) and setting `$this->items[$index]['unit_price']` via `bcadd()`, blank if no catalog price exists. Not yet written.
3. **Build the View Purchase Order page** — deferred in favor of Create PO (needed something to view first). Scoped design already discussed once: header info, line items with `remainingQuantity()`/`isFullyReceived()`, status-and-role-aware action buttons (`@can` against the existing Policy). Only wire buttons for Actions that actually exist (currently just Submit).
4. **Verify the newly-registered Gates actually behave as intended, in the browser** — not yet exercised end-to-end: login as `purchasing` and confirm they *cannot* approve a PO (Manager-only rule) but *can* cancel one; login as `manager` and confirm dashboard-override + approval both work.
5. Translate the deferred settings pages (2FA setup, passkeys, recovery codes, delete-account) into `lang/id.json` whenever those features are actually prioritized — no code changes needed, just dictionary entries.
6. Consider whether `pdo_sqlite` should be installed so automated Feature/Pest tests can actually run in this environment (currently blocked — see Session 4 notes).

**Backend/domain track (paused, not abandoned):**
7. Write `ApprovePurchaseOrderAction` (`PendingApproval → Approved`) and `RejectPurchaseOrderAction` (`PendingApproval → Rejected`) — both now unblocked (Gates exist, enum transition fixed).
8. Decide the `Closed` status question — enum case is still commented out; `purchasing.close` Gate and `PurchaseOrderPolicy::close()` already assume it exists.
9. Decide the soft-deletes question — `products`/`suppliers` migrations don't have `softDeletes()` despite earlier draft schema assuming it. Implement, or drop from the plan.
10. Design `stock_movements` table (cross-module ledger) — agreed necessary, not yet drafted.
11. Write `CreateStockMovementAction` (centralized, transaction-wrapped, `lockForUpdate()`).
12. Finalize the PO status recalculation logic for multi-item receiving (`ReceivePurchaseOrderAction` step 6).
13. Revisit Purchase Order **cancellation** rules — e.g. can a partially-received PO be cancelled? What happens to already-received stock?
14. Decide over-receipt policy (currently leaning: block receiving more than outstanding quantity — not finalized).
15. Decide whether the missing `unique(['purchase_order_id', 'product_id'])` constraint on `purchase_order_items` is intentional (same product as two separate line items) or should be added.
16. Login page: decide if `<x-passkey-verify>` should be (re-)added — it's fully functional (real routes + JS) but currently unused anywhere in the app.
17. **Unresolved from Session 3:** whether to (a) re-sweep PHPDoc relation generics (`@return BelongsTo<X, $this>` etc.) across all 6 Purchasing models — attempted once, got reverted before landing — and (b) how to fix the IDE's "`Property $id accessed via magic method Model::__get()`" nag: hand-write `@property` docblocks (started on `PurchaseOrder.php`, only `$id` so far) vs. install `barryvdh/laravel-ide-helper` to auto-generate them. Neither is blocking; both were mid-discussion when this was written.

---
*Paste this file at the start of a new session to resume with full context.*
