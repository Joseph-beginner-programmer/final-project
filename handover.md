# MIMS — Project Handover

**Project:** Manufacturing Information Management System (MIMS)
**Stack:** Laravel 13, PHP 8.3+, MySQL, Livewire, Flux UI, Laravel Fortify (auth)
**Location context:** Indonesia — Bahasa Indonesia labels needed in UI, English/underscore values in DB/code

**Skills in use:** `laravel-architect`, `business-logic-expert`, `manufacturing-domain-expert`, `frontend-product-design`, `livewire-volt-architect`, `laravel-security-expert`

**Working modes:** Two explicit modes requested by user —
- **Teaching mode**: explain concepts deeply (why, not just how)
- **Coding mode**: keep it tight — code + brief rationale, skip deep explanations
Default to teaching mode unless user says "coding mode."

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

**⚠️ OPEN ISSUE — status graph contradicts documented design:** `PendingApproval` can only transition to `Approved`, `Draft`, or `Cancelled` in the current code — **nothing transitions into `Rejected`**, even though this handover's own earlier draft (and the `RejectPurchaseOrderAction` row below) documents `PendingApproval → Rejected` as intended. Not yet fixed — need to decide whether to add `Rejected` as a valid target from `PendingApproval` in the enum, or whether the design changed and `Rejected` should be removed/rethought.

## Purchasing Module — Business Rules Established

**Status lifecycle (state machine) — as originally intended:**
```
Draft → PendingApproval → Approved → PartiallyReceived → FullyReceived → Closed
              ↓
          Rejected → (back to Draft)
Cancelled reachable from Draft, PendingApproval, or Approved
```
(See ⚠️ OPEN ISSUE above — actual code doesn't yet allow `PendingApproval → Rejected`.)

**Actions identified so far (still just design intent — none of these Action classes are written yet; the enforcement that exists today lives directly on the models, e.g. `PurchaseOrder::transitionTo()`):**
| Action | Transition | Notes |
|---|---|---|
| `CreatePurchaseOrderAction` | → Draft | Clerk starts PO, can be WIP |
| `UpdatePurchaseOrderAction` | Draft → Draft | Only editable while Draft |
| `SubmitPurchaseOrderForApprovalAction` | Draft → PendingApproval | Business validation: must have ≥1 line item, all quantities > 0 |
| `ApprovePurchaseOrderAction` | PendingApproval → Approved | Authorization: Policy, not inline role check |
| `RejectPurchaseOrderAction` | PendingApproval → Rejected | Blocked by the open enum issue above |
| `ReceivePurchaseOrderAction` | Approved/PartiallyReceived → PartiallyReceived/FullyReceived | See full step sequence below |
| `CreateStockMovementAction` | (cross-module, shared) | Centralized, row-locked stock update — NOT YET WRITTEN, only designed conceptually |

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

## Immediate Next Steps (pick up here in new session)
1. **Decide the `Rejected` transition issue** — `PurchaseOrderStatus::canTransitionTo()` currently has no path into `Rejected` from `PendingApproval`, contradicting the documented design and the `RejectPurchaseOrderAction` row in the Actions table. Fix the enum or revise the design.
2. **Decide the soft-deletes question** — `products`/`suppliers` migrations don't have `softDeletes()` despite earlier draft schema assuming it. Implement, or drop from the plan.
3. **Finish testing the role-based dashboard/auth flow** (carried over, not yet confirmed done):
   - Login as `manager` → verify can visit ANY dashboard (Gate override works)
   - Login as `purchasing` → verify visiting `/warehouse/dashboard` returns 403
4. Design `stock_movements` table (cross-module ledger) — agreed necessary, not yet drafted.
5. Write `CreateStockMovementAction` (centralized, transaction-wrapped, `lockForUpdate()`).
6. Finalize the PO status recalculation logic for multi-item receiving (`ReceivePurchaseOrderAction` step 6).
7. Revisit Purchase Order **cancellation** rules — e.g. can a partially-received PO be cancelled? What happens to already-received stock?
8. Decide over-receipt policy (currently leaning: block receiving more than outstanding quantity — not finalized).
9. Build out real `Policies/` for Purchase Order actions (approve, reject, receive) — currently only the dashboard Gate exists; PO-specific Policies not yet written.
10. Continue building out the Purchasing `Actions/` classes in code — the business rules and transition graph exist on the models/enum, but none of `CreatePurchaseOrderAction`, `SubmitPurchaseOrderForApprovalAction`, etc. are written yet as actual classes.
11. Decide whether the missing `unique(['purchase_order_id', 'product_id'])` constraint on `purchase_order_items` is intentional (same product as two separate line items) or should be added.
12. Login page: decide if `<x-passkey-verify>` should be (re-)added — it's fully functional (real routes + JS) but currently unused anywhere in the app.

---
*Paste this file at the start of a new session to resume with full context.*
