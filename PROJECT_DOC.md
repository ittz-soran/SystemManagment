# Store Management System — Project Documentation

**Status:** design complete, no open questions. Ready to build — nothing has been coded yet.

> **How to use this file:** upload or paste it at the start of every new chat about this project. It contains every decision made, so no context is lost between sessions. Update the Task Log (Section 12) at the end of each session.

---

## 1. Instructions for Claude — read first, every session

- This is a **PHP/Laravel inventory and store management system** for a real electronics shop in Iraq.
- Follow the tech stack, schema, and rules below exactly. Do not change framework, folder structure, or naming mid-project.
- Check the **Task Log (Section 12)** before writing code — see what exists, what's next.
- Give **complete, copy-paste-ready code** with file paths labelled (`// File: app/Models/Product.php`).
- **Three rules that govern everything — never write logic that violates them:**
  1. **FIFO costing (Section 5).** Every stock-in creates a batch; every stock-out consumes the oldest batch first and records which batch it came from.
  2. **Batch cost = the unit price typed by the user (Section 6).** Discounts never change it.
  3. **Locks are computed live, never stored (Section 8).**
- If a decision changes, **update this file** — don't just say it in chat.

---

## 2. Overview

**Roles:** two only.
- **Admin** — full access, always. Cannot be restricted.
- **User** — gets a default permission set on creation (login, sale, purchase, view products). The admin then adds or removes individual permissions per user.

**Currency:** Iraqi Dinar (IQD). All prices are whole numbers — 1,500 · 25,000 · 210,000. Stored as **integer BIGINT**, never decimal. Displayed with `number_format()` as `250,000 IQD`.

**Languages:** English (default), Kurdish Sorani, Arabic, Persian. The last three are **RTL** — use Laravel localization (`lang/` files) plus Bootstrap 5's RTL build, switching text and direction together.

**Scope:** single shop, no branches. No expiry-date tracking.

---

## 3. Tech Stack

| Layer | Choice |
|---|---|
| Framework | **Laravel** (latest stable) |
| Database | **MySQL** / MariaDB |
| Frontend | **Blade + Bootstrap 5** (RTL build for Sorani/Arabic/Persian) |
| Auth | **Laravel Breeze** |
| Invoices | **Browser print page** — a Blade view styled for printing. No PDF library. |
| Local env | XAMPP / Laragon / Laravel Sail |

Structure is Laravel default: `app/Models`, `app/Http/Controllers`, `app/Http/Requests`, `database/migrations`, `resources/views/<module>/`, `routes/web.php`.

**Conventions:** PascalCase models · camelCase methods · snake_case tables/columns · one controller per module (`ProductController`) · Eloquent over raw SQL · Form Requests for validation.

---

## 4. Database Schema

### Master data

**users** — id, name, email, password, role (**admin** / **user**), is_active, **language**, **theme** (light/dark/auto), **items_per_page**, timestamps
> `admin` always has everything — permission checks short-circuit to true and the UI hides the permission editor.
> `user` starts from a default set and is then customised per user via `user_permissions`.

**permissions** — id, key (e.g. `sales.create`, `purchases.edit`, `reports.view`), group (module name), label, timestamps
> Seeded once, not user-editable.

**user_permissions** — id, user_id, permission_id, timestamps · **unique (user_id, permission_id)**
> The real permission list for `user`-role accounts. Creating a user seeds the defaults below; the admin then **adds or removes individual permissions** freely.
> **Default set for a new user:** `auth.login`, `sales.create`, `sales.view`, `purchases.create`, `purchases.view`, `products.view`, `customers.view`, `suppliers.view`.
> Never consulted for `admin` accounts.

**categories** — id, name, parent_id (nullable — allows sub-categories), timestamps
> **One category per product.** A category has many products; a product has one `category_id`. No pivot table.
> This still supports both features you wanted: filtering by *several* categories at once is `WHERE category_id IN (...)`, and bulk-assigning selected products to a category is a bulk `UPDATE` of `category_id`.

**products** — id, name, **sku** (unique), **barcode** (unique, nullable), **category_id** (FK), unit, purchase_price, sale_price, quantity, reorder_level, is_active, timestamps
> `purchase_price` / `sale_price` are only **default suggestions for the cart**.

**About `products.quantity` — it is a cache, not the truth.**
The real stock is `SUM(stock_batches.quantity_remaining)`. The column exists only so product lists and the dashboard load fast without summing batches every time.
- **Recalculate it inside the same transaction** as every stock movement — purchase, sale, either return, adjustment. Never in a separate query afterwards, or a crash between the two leaves it wrong.
- Always write it as `SUM(quantity_remaining)`, never as `quantity + n` / `quantity − n`. Incremental maths compounds any error permanently; a recomputed sum is self-correcting.
- Provide an admin **"Recheck stock"** action that compares every product's cached value against its batch sum and lists mismatches. If they ever differ, the batches win.

**SKU rules:**
- If the product has a manufacturer code, Soran types it — e.g. Flash Teamgroup 32GB 3.2 → **`C175`**.
- If it has none, the system **auto-generates** `SS` + next number → **`SS65`** ("Soran Store 65").
- Auto-generated numbers come from a counter, never from `MAX(id)` — deleting a product must not let a code be reused.
- Both kinds live in the same `sku` column, unique across all products.

**Barcode rules:**
- If the product has a printed barcode, scan or type it.
- If not, auto-generate one so every product is scannable at the till.
- Use **EAN-13** with a valid check digit, in an internal-use prefix (e.g. `200`–`299`), so it never collides with a real manufacturer barcode.
- Scanning in the sale/purchase cart searches `barcode` first, then `sku`, then name.

**suppliers** — id, name, phone, address, balance, timestamps
> `balance` positive = the shop owes the supplier. **Negative is NOT allowed** — it floors at zero, mirroring customers.
> If a return exceeds what you still owe (e.g. the purchase was already paid), the balance clears to zero and the remainder is **cash received back from the supplier** — a `payments` row with `direction = in` against the purchase return. This answers old open question 2.

**customers** — id, name, phone, address, balance, **is_system**, timestamps
> `balance` positive = the customer owes the shop. **Negative is NOT allowed** — it floors at zero. See "Refunds" in Section 7.
> **Cash Customer:** seed one row with `is_system = true`, named "Cash Customer". It is the default on the sale form for walk-in buyers, cannot be deleted or renamed, and must always be paid in full (no loan). `sales.customer_id` stays a required FK — walk-ins point here.

### Purchases

**purchases** — id, **document_no** (`PUR-…`), supplier_id, user_id, supplier_invoice_no, total_amount, **discount_amount** (SIGNED), grand_total, **status** (`active` / `partly_returned` / `returned`), purchase_date, timestamps
> `grand_total = total_amount − discount_amount`. Signed because a supplier may round **up** (one real invoice rounds 366,902.50 to 367,000 — that's a negative discount of 97.50).
> The discount **never** touches item prices or batch costs.

**purchase_items** — id, purchase_id, product_id, quantity, unit_price, **quantity_returned**, timestamps
> The same product may appear on two lines at two different prices — supported, **never merged**. Each line becomes its own `stock_batches` row, which is exactly how FIFO keeps two costs for one product straight.

### Sales

**sales** — id, **document_no** (`INV-…`), customer_id, user_id, total_amount, **status**, sale_date, timestamps
> **Sales have no discount field.** Price is controlled per line instead — default or manual.
> **`status`** is derived, never typed: `active` · `partly_returned` · `returned` (everything came back).

**How status is set:** after any sale return, recompute from the lines —
```
all lines fully returned   → returned
some quantity returned     → partly_returned
none                       → active
```
Recalculate inside the same transaction as the return, and again if a return is deleted (a sale can go back from `returned` to `partly_returned`). The sale row itself is **never voided or deleted** — the sale happened, the return happened, and both stay visible. `status` only makes the history list readable at a glance.

`purchases` carries the same `status` column, driven by purchase returns.

**sale_items** — id, sale_id, product_id, quantity, unit_price, **quantity_returned**, timestamps
> The same product may appear on two lines at two different prices — supported, **never merged**. Same rule as `purchase_items`.

### FIFO engine — the core of the system

**stock_batches** — one layer of stock at one cost
- id, product_id, **source_type** (`purchase` / `adjustment`), **source_id**, purchase_item_id (**nullable**), **unit_cost**, quantity_in, **quantity_remaining**, received_at, **sequence**, timestamps
- `source_type` / `source_id` — every batch is traceable to where it came from. A purchase line, or an `in` stock adjustment. Never both, never neither.
- `purchase_item_id` is null for adjustment batches; it exists as a direct link because purchase returns look up the batch by purchase line.
- `unit_cost` = exactly the price typed on that purchase line.
- `sequence` = line order within the purchase. Needed because the same product can appear twice in one purchase at two costs, sharing a timestamp — without it FIFO order is undefined.

**stock_movements** — the complete audit of every unit in and out
- id, product_id, stock_batch_id, reference_type (**purchase** / sale / sale_return / purchase_return / adjustment), reference_id, **reference_item_id** (nullable), **reverses_movement_id** (nullable, FK to `stock_movements`), quantity (+in / −out), **unit_cost** (copied from the batch), **occurred_at**, **sequence**, user_id, timestamps

- ⚠️ Order FIFO by `received_at, sequence` — **never by `id`**.
- ⚠️ **`reference_item_id` is required for sales and both return types.** It points at the `sale_item` / `purchase_item` row, not just the parent document.
  **Why it matters:** one sale can list the same product on two lines at two prices, and both may draw from the same batch. Returning 2 units of line 2 must restore exactly what line 2 consumed. Without this column the system cannot tell the two lines apart, and per-line returns (Section 7) are impossible.
- **A purchase also writes movements** (`reference_type = 'purchase'`, quantity positive). Creating the batch is not enough — with purchase rows present, `stock_movements` alone reconstructs the full history of a product, and `SUM(quantity)` per product must equal current stock. That is the check that proves the books are intact.
- ⚠️ **`reverses_movement_id`** — every return movement points at the exact sale movement it undoes. See "Returning across batches" below; without it, repeated partial returns silently corrupt the cost layers.

### Returns

**sale_returns** — id, **document_no** (`SRT-…`), sale_id, customer_id, user_id, total_amount, return_date, reason, timestamps
> `customer_id` is copied from the sale for fast reporting. **The sale is the source of truth** — never let them diverge; set it once on creation and never edit it independently.
**sale_return_items** — id, sale_return_id, **sale_item_id (REQUIRED)**, product_id, quantity, unit_price, timestamps

**purchase_returns** — id, **document_no** (`PRT-…`), purchase_id, supplier_id, user_id, total_amount, return_date, reason, timestamps
> `supplier_id` copied from the purchase — same rule as above.
**purchase_return_items** — id, purchase_return_id, **purchase_item_id (REQUIRED)**, product_id, quantity, unit_price, **discount_share** (defaults to **0** — optional, see Section 7), timestamps

> Returns reference the **line**, not the product — the same product on two lines at two prices refunds differently.

### Money

**payments** — id, document_no, payable_type (sale/purchase/sale_return/purchase_return), payable_id, amount (always positive), **direction** (in/out), payment_method (cash / bank / transfer), paid_at, user_id, notes, timestamps
> Polymorphic. Many payments per document. Amount due = `grand_total − SUM(amount WHERE direction = 'in')`.
> `direction = out` is money leaving the till — a cash refund to a customer, or paying a supplier.

**account_transactions** — the customer/supplier ledger
- id, accountable_type (customer/supplier), accountable_id, type (sale / purchase / payment / refund / return / opening_balance), reference_type, reference_id, amount, **balance_after**, user_id, notes, timestamps
> This is the truth; `customers.balance` / `suppliers.balance` are caches of the latest `balance_after`. Provide an admin "recalculate balances" action.

**expense_categories** — id, name, is_active, timestamps
> A managed list, not free text, so the expense report groups cleanly. Seed with: Rent · Utilities · Salaries · Transport · Maintenance · Supplies · Government fees · Other.
> Admin can add or deactivate categories. **Deactivating hides it from new entries but keeps old expenses intact** — never delete a category that has expenses against it.

**expenses** — id, **document_no** (`EXP-…`), title, **expense_category_id** (FK), amount, expense_date, user_id, notes, timestamps

### Stock adjustments

**stock_adjustments** — id, **document_no** (`ADJ-…`), product_id, user_id, direction (**in** / **out**), quantity, unit_cost (required for `in`, null for `out`), reason (damage / theft / miscount / correction / other), notes, adjusted_at, timestamps

- **`out`** — consumes batches FIFO, writing `stock_movements` rows with `reference_type = 'adjustment'`, so the value written off is the true FIFO cost. Blocked if stock is insufficient.
- **`in`** — creates a new `stock_batches` row at the entered `unit_cost`, with `source_type = 'adjustment'` and `purchase_item_id` null. FIFO needs a cost for every unit, so `unit_cost` cannot be blank.
- This is the **only** way to correct a locked document, so it must exist before go-live.
- Adjustments never touch supplier or customer balances — stock moves, money does not.

### Records

**activity_logs** — id, user_id, action (login/create/update/delete), module, record_id, description, **old_values** (JSON), ip_address, timestamps

**settings** — key/value, cached. Holds shop info, appearance/branding, `timezone`, `usd_rate`, `books_closed_before`, `low_stock_threshold`, `sku_prefix`, `date_format`. Full list in **Section 8c**.

---

## 5. FIFO Costing — the core rule

**When stock is sold, the units purchased first are consumed first, at that batch's cost.**

A single `quantity` column can't do this. Buy 10 @ 5,000 then 10 @ 6,000, sell 12 — the cost is 10×5,000 + 2×6,000 = **62,000**, not an average. The system must know which purchase each unit came from.

| Action | Effect |
|---|---|
| **Purchase** | Each line creates a `stock_batches` row: `quantity_in = quantity_remaining = qty`, `unit_cost` = typed price |
| **Sale** | Consume batches ordered by `received_at, sequence` ascending. Write one `stock_movements` row **per batch touched** — one sale line can span several batches. |
| **Sale return** | Units go back to the **batches they came from**, in **reverse order of consumption** (last taken, first returned) |
| **Purchase return** | Deduct from **that purchase's own batch**, not the oldest — you're returning those specific goods to that supplier |
| **Profit** | Revenue − FIFO cost from `stock_movements.unit_cost` − expenses |

### Returning across batches — the exact algorithm ⚠️

One sale line can draw from several batches. Returning part of that line must put each unit back in **the batch it actually came from**, and must never restore the same unit twice.

**Worked case.** Sale line consumed 3 from Batch 1 @ 200,000 and 2 from Batch 2 @ 210,000.

| Return | Goes back to | Because |
|---|---|---|
| 1st unit | Batch 2 | last consumed, first returned |
| 2nd unit | Batch 2 | Batch 2 gave 2, one still owed |
| 3rd unit | **Batch 1** | Batch 2 fully restored, move to the next |

Restoring the 3rd unit to Batch 2 instead would leave Batch 2 above its `quantity_in` and Batch 1 permanently short. Stock totals would still look correct, so nothing appears broken — but every later sale draws the wrong cost.

**Never recompute this by re-deriving "reverse order" from scratch**, because a second return has no way to know what the first one already gave back. Track it at the movement level instead.

```
// inside the transaction, batches already locked
movements = stock_movements
    .where(reference_type = 'sale')
    .where(reference_item_id = <the sale_item being returned>)
    .where(quantity < 0)
    .orderBy(sequence, DESC)        // last consumed first
    .lockForUpdate()

remaining = quantity_to_return

foreach (m in movements) {
    alreadyGivenBack = SUM(quantity) of movements where reverses_movement_id = m.id
    available        = abs(m.quantity) - alreadyGivenBack
    if (available == 0) continue

    take = min(available, remaining)

    batch(m.stock_batch_id).quantity_remaining += take

    create stock_movement(
        quantity             = +take,
        stock_batch_id       = m.stock_batch_id,
        unit_cost            = m.unit_cost,      // copied from the original movement
        reference_type       = 'sale_return',
        reference_item_id    = <sale_return_item id>,
        reverses_movement_id = m.id              // ← the link that makes it exact
    )

    remaining -= take
    if (remaining == 0) break
}

if (remaining > 0) → error: more returned than this line ever consumed
```

**Why this is exact:**
- `available` per movement is computed from what was actually given back, not re-derived — so the second, third, and fourth partial returns each pick up precisely where the last left off.
- `unit_cost` is copied from the **original movement**, guaranteeing the COGS reversal equals the COGS that was recorded, to the dinar.
- **Deleting a return** becomes trivial and safe: take its movements, subtract each from its batch, delete them. The `reverses_movement_id` links restore the earlier state exactly, with no recomputation.
- `sale_return_items` needs **no batch columns** — the movements carry all of it, and one source of truth cannot disagree with itself.

**Purchase returns are simpler:** one `purchase_item` maps to exactly one batch, so there is no ordering question. Deduct from that batch and write one movement.

**A batch that reaches 0 is not finished** — a return can refill it. Never treat empty batches as closed.

**Block overselling.** If stock is 8 and the sale asks for 12, refuse: *"Not enough stock: 8 available."* Negative stock has no batch to draw cost from and breaks FIFO permanently.

**Opening stock.** Products already in the shop need a starting batch — quantity plus its cost — entered when the product is created, or FIFO has no first layer.

### Complete foreign-key reference

Every relationship in one place. **`restrict`** means the parent cannot be deleted while children exist — the default for anything financial.

| Child table | Column | Parent | On delete |
|---|---|---|---|
| user_permissions | user_id | users | cascade |
| user_permissions | permission_id | permissions | cascade |
| categories | parent_id | categories *(self)* | restrict |
| products | category_id | categories | **restrict** |
| purchases | supplier_id | suppliers | **restrict** |
| purchases | user_id | users | restrict |
| purchase_items | purchase_id | purchases | cascade |
| purchase_items | product_id | products | **restrict** |
| sales | customer_id | customers | **restrict** |
| sales | user_id | users | restrict |
| sale_items | sale_id | sales | cascade |
| sale_items | product_id | products | **restrict** |
| stock_batches | product_id | products | **restrict** |
| stock_batches | purchase_item_id | purchase_items | cascade *(nullable)* |
| stock_movements | product_id | products | **restrict** |
| stock_movements | stock_batch_id | stock_batches | **restrict** |
| stock_movements | reverses_movement_id | stock_movements *(self)* | restrict *(nullable)* |
| sale_returns | sale_id | sales | **restrict** |
| sale_returns | customer_id | customers | restrict |
| sale_return_items | sale_return_id | sale_returns | cascade |
| sale_return_items | **sale_item_id** | sale_items | **restrict** |
| purchase_returns | purchase_id | purchases | **restrict** |
| purchase_returns | supplier_id | suppliers | restrict |
| purchase_return_items | purchase_return_id | purchase_returns | cascade |
| purchase_return_items | **purchase_item_id** | purchase_items | **restrict** |
| payments | user_id | users | restrict |
| expenses | expense_category_id | expense_categories | **restrict** |
| expenses | user_id | users | restrict |
| stock_adjustments | product_id | products | **restrict** |
| stock_adjustments | user_id | users | restrict |
| activity_logs | user_id | users | restrict |

**Polymorphic — no database FK, enforce in code:**
`payments.payable_type/payable_id` · `account_transactions.accountable_type/accountable_id` · `account_transactions.reference_type/reference_id` · `stock_movements.reference_type/reference_id/reference_item_id` · `stock_batches.source_type/source_id`

**Notes:**
- `cascade` is used only where the child has no meaning without the parent — deleting a purchase removes its lines. Everything else is `restrict`, because a product with stock history must never vanish.
- Products, customers, and suppliers with history are **deactivated** (`is_active = false`), never deleted. `restrict` enforces that.
- `users` is always `restrict` — a deleted employee would erase who made every document.
- With soft deletes, a "deleted" row still exists, so FKs stay valid. Only a **force delete** hits these rules.
- A force delete is reachable from one place: **Products → deleted list → "Delete permanently"**, admin only. It exists because a soft-deleted product keeps holding its SKU and its barcode, so a row typed in by mistake blocks those codes forever. It asks all seven `restrict` keys first and refuses in a sentence naming what it found — *"This product is on 2 sales and 1 purchase, so it cannot be destroyed"* — rather than letting the database answer with an integrity-constraint error. Counted with the query builder, not through relations: a foreign key does not know about soft deletes or the archived-period scope, and a check that asks a different question from the one MySQL is about to ask is worse than no check. A backup is taken first, outside the transaction. The product's own `activity_logs` rows go with it — `record_id` has no FK, so they would point at nothing — and one `purge` entry replaces them, naming who destroyed what.

---

### Concurrency — locking batches during consumption ⚠️

**The problem.** Two staff sell the same product at the same moment. Both read `quantity_remaining = 5`, both decide there is enough, both consume 4. Stock ends at −3, two sales claim the same units, and FIFO is corrupted with no way to tell which sale was wrong. Database transactions alone do **not** prevent this — the reads happen before either write.

**The fix.** Lock the batch rows for the duration of the transaction:

```php
DB::transaction(function () use ($productId, $qtyNeeded) {
    $batches = StockBatch::where('product_id', $productId)
        ->where('quantity_remaining', '>', 0)
        ->orderBy('received_at')->orderBy('sequence')
        ->lockForUpdate()          // ← SELECT ... FOR UPDATE
        ->get();
    // re-check availability AFTER the lock, then consume
});
```

Rules:
- **Every FIFO read that leads to a write must use `lockForUpdate()`** — sales, returns, purchase returns, adjustments, and edits.
- **Re-check availability after acquiring the lock**, never before. A check outside the lock is worthless.
- Lock batches in a **consistent order** (`product_id`, then `received_at, sequence`). A sale touching two products must lock them in the same order every time, or two concurrent sales can deadlock.
- Keep transactions **short** — no HTTP calls, no file writes, no waiting on user input while holding locks.
- The same applies to the `document_counters` row (Section 7b) and to any balance update on `customers` / `suppliers`.

Two people using the till at once is normal in a shop, so this is not a rare edge case — it is the ordinary situation.

---

## 6. Pricing & Discounts

> **Batch cost = the unit price typed. Always. Nothing ever changes it.**

| | Purchase | Sale |
|---|---|---|
| Line price | typed manually | **default or manual** |
| Batch cost | = typed price | — |
| Whole-invoice discount | **one signed column** | **none** |

```
Purchase:  grand_total  = Σ(qty × unit_price) − discount_amount
Sale:      total_amount = Σ(qty × unit_price)
```

**Why the discount doesn't touch cost:** Soran should see the number he typed. A batch reading 200,000 when he typed 200,000 is trustworthy; one reading 199,270 is not. The trade-off is slightly conservative costs — understating margin, which is the safe direction to be wrong in.

**The discount isn't lost** — it appears on the profit report:
```
Discounts received = Σ purchase discounts − Σ purchase-return discount shares
```

**Fractional supplier prices:** one real invoice has 27,645 for 6 units = 4,607.50 each. Soran types the **line total** (27,645); the system derives a whole-dinar unit price and puts the remainder in the signed `discount_amount`. No decimal columns anywhere.

---

## 6b. USD Entry Helper (purchases)

Some suppliers quote in dollars. Soran types `$` and the system converts — but **only IQD is ever stored**. There is no dual-currency system, no currency column on money fields, no historical rate lookup. It is a calculator on the entry form.

### How it works

1. The purchase form has an **exchange rate** field, pre-filled from `settings.usd_rate`, editable per purchase (market rates move, and the rate you actually paid at is the one that matters).
2. On each cart line, Soran picks the input currency — **IQD** (default) or **USD**.
3. If USD, he types either the unit price or the line total in dollars. The system converts and shows the IQD result immediately, before adding to the cart.
4. What gets saved is **IQD only**.

```
unit_price (IQD) = round(usd_amount × exchange_rate)
```

### Rounding — do it once, on the unit price

Round the **unit price** to whole dinars first, then multiply by quantity. Never convert the line total and divide.

$8.50 × 1,320 = 11,220 → clean.
$8.33 × 1,320 = 10,995.6 → unit price **10,996**.

Because `unit_price` becomes the batch cost, it must be a whole dinar that multiplies cleanly by quantity. If Soran types a **line total** in USD instead, convert it, divide by quantity, round to a whole unit price, and put the small remainder in the purchase's signed `discount_amount` — the same mechanism already used for fractional supplier prices.

### Schema

- `settings.usd_rate` — the default rate
- `purchases.exchange_rate` — nullable; the rate used on this purchase, stored for reference
- `purchase_items.entered_currency` (IQD/USD) and `entered_amount` — nullable, **reference only**

> Keep `entered_amount` even though it isn't used in any calculation. When a supplier says *"you bought this at $8.50"*, that's the number you need to check against — the IQD figure alone won't answer it.

### Rules

- **The stored IQD value is final.** If the rate changes tomorrow, nothing recalculates. Batch cost, profit, and balances are all fixed in dinars at the moment of entry.
- **Balances are always IQD.** A supplier quoting in dollars is still owed a dinar amount.
- Show the conversion on screen before saving, so a wrong rate is caught at entry rather than discovered in a profit report weeks later.
- Optional but useful: warn if the entered rate differs from `settings.usd_rate` by more than ~10%, which usually means a typo.

**Not included:** the sale side. Customers are billed in dinars. The same helper could be added to the sale cart later if needed, with no schema change beyond mirroring the two reference columns.

---

## 7. Returns

**Every return case is the same operation at a different scale.** Partial line, whole line, or whole sale — one form, one mechanism.

### The return screen

Lists every line with what's still returnable. Soran types quantities:

| Line | Sold | Already returned | Returnable | Return now | Refund |
|---|---|---|---|---|---|
| A | 3 | 0 | 3 | `[ 1 ]` | 260,000 |
| B | 2 | 0 | 2 | `[ 0 ]` | 0 |
| C | 1 | 0 | 1 | `[ 1 ]` | 45,000 |
| | | | | **Total** | **305,000** |

A **"return all"** button fills every box with the returnable quantity.

- *"I only want 2, not 3"* → type 1 on that line
- *"I don't want product C"* → type its full quantity
- *"Return everything"* → one click

### Rules

```
returnable = quantity − quantity_returned
```
Tracked **per line**, cumulatively. Multiple returns over time work naturally.

- **Never validate a sale return against stock on hand.** A returning customer is *adding* stock; the only limit is what they bought and haven't already returned.
- **Purchase returns are limited by the batch** — you can't send back goods you no longer hold.
- **Refund uses that line's unit price** — the same product on two lines refunds differently.
- **Returns are never blocked by the edit lock.** A return creates a new forward document; it doesn't rewrite history. Allowed any time the stock is there.

### Status after a return

A fully returned sale is **marked `returned`**, not voided or deleted. Both documents stay in the history, the money nets to zero, and the stock is fully restored. Sales and purchase history lists show a badge so a fully-returned document is obvious without opening it.

### Refunds — because customer balance cannot go negative

A refund first **clears what the customer owes**; anything left over is **paid back in cash**.

Customer owes 100,000 and returns goods worth 300,000:

| | |
|---|---|
| Applied to their balance | 100,000 → balance **0** |
| Paid back in cash | **200,000** |

The cash portion is recorded as money **leaving the till** — never as a negative number. Add a **`direction`** column to `payments` (`in` / `out`); the amount is always positive and the direction says which way it moved.

| Field | Value |
|---|---|
| amount | 200,000 |
| direction | **out** |
| payment_method | cash |
| payable | the sale return |

Reports read the direction, so cash in and cash out stay separate and legible. The balance never goes below zero.

If the customer owed nothing at all, the whole refund is cash.

> The alternative — letting the balance go negative as store credit — is rejected. Real cash goes back to the customer, and the books should say so.

### Purchase return when the purchase had a discount

**Default: credit the full typed unit price. The discount share is optional.**

Purchase subtotal 1,000,000, discount 50,000, grand total 950,000. Returning 1 unit typed at 200,000:

| Option | Credit | When to use |
|---|---|---|
| **Default** | **200,000** | Supplier credits the full listed price |
| Apply discount share | 190,000 | Supplier credits proportionally, as they invoiced |
| Type any amount | whatever they gave | Negotiated, or an odd number |

The return screen shows the credit pre-filled at **200,000**, with the calculated share (10,000) displayed beside it as a hint and a one-click way to apply it. Soran decides per return.

**Schema:** `purchase_return_items.discount_share` defaults to **0**, not to the calculated value.

> **Worth knowing:** if the supplier only credits 190,000 and you record 200,000, your balance sits 10,000 higher than theirs. That's fine when you know they'll credit in full — just be aware the two figures can drift if the choice is made carelessly. The `supplier_invoice_no` on each purchase is what lets you reconcile when it matters.

The discounts-received figure follows whatever was chosen:
```
Discounts received = Σ purchase discounts − Σ discount shares actually applied
```

### Return vs edit — different meanings

| | **Return** | **Edit** |
|---|---|---|
| What happened | Goods came back | You typed it wrong |
| Audit trail | Both documents visible | Sale silently changed |
| Time limit | None | 24h, untouched only |

Make **return** the obvious action on the sale page; keep edit tucked away. Using edit when goods came back erases information you'd want later — like which customers return often.

---

## 7b. Document Numbers

Every document has a human-readable number in one shared format: **`PREFIX-NNNNN`**.

| Document | Prefix | Example |
|---|---|---|
| Sale (invoice) | `INV` | `INV-12521` |
| Purchase | `PUR` | `PUR-54513` |
| Payment | `PAY` | `PAY-32323` |
| Sale return | `SRT` | `SRT-00184` |
| Purchase return | `PRT` | `PRT-00092` |
| Expense | `EXP` | `EXP-00451` |
| Stock adjustment | `ADJ` | `ADJ-00037` |

**Schema:** add `document_no` (unique, indexed) to each of those tables.

**document_counters** — id, prefix (**unique**), next_number, timestamps

**Generation rules:**
- One row per prefix; each sequence is independent.
- Increment inside the same transaction as the insert, using `SELECT ... FOR UPDATE` on the counter row so two users saving at once can't get the same number.
- **Never derive the number from `id`** — a deleted document would let its number be reused, and two documents with the same number is exactly the confusion the number exists to prevent.
- Numbers are **never reused, even after deletion**. A gap in the sequence is normal and is itself useful information.
- Zero-pad to 5 digits; let it overflow naturally past 99,999.

This is separate from `purchases.supplier_invoice_no`, which is the **supplier's** number on their paperwork — both are worth having when reconciling with a supplier.

---

## 8. Editing & Deleting — the lock rules

A document is editable **only within 24 hours** and **only if nothing downstream depends on it**. Otherwise it's locked, permanently.

This is what keeps the system safe: if nothing has touched the stock, reversing and re-applying a document affects nothing else, so no historical cost can silently change.

### Purchase — editable only if ALL are true
1. Within **24 hours** of creation
2. **Every batch untouched** — `quantity_remaining == quantity_in`
3. No purchase returns against it
4. New grand total ≥ amount already paid
5. Not in a closed period
6. User is **admin**, or has the `purchases.edit` permission

**Delete additionally requires** that no `stock_movements` row has *ever* referenced its batches — even ones since cancelled by a return. Otherwise those rows are orphaned. So: *"This purchase has been used in a sale. You can edit it, but not delete it."*

### Sale — editable only if ALL are true
1. Within **24 hours**
2. No sale returns against it
3. **No later stock movement for any product in this sale**
4. New grand total ≥ amount already paid
5. Not in a closed period
6. User is **admin**, or has the `sales.edit` permission

> **Why rule 3:** if this sale took 5 units from Batch 1 and a later sale took the rest, editing this one up to 8 would spill into Batch 2 at a different cost — while the *later* sale holds the cheaper units. FIFO order inverts and both are wrong.

### How an edit runs

One `DB::transaction()`: **reverse** (return units to their exact batches, delete movements, reverse ledger rows) → **re-apply** (recalculate, re-run FIFO, write new movements and ledger rows).

No chronological replay is needed — the lock conditions make it impossible for an edit to affect any other document.

### Locks are computed, never stored

> **Never add an `is_locked` column.** Compute it every time from batch state. A full customer return restores a batch to `remaining == in`, and the Edit button reappears by itself with no extra code. A stored flag would go stale immediately.

Put the logic in one place:
```
Purchase::canBeModified(): array   // ['allowed' => bool, 'reason' => string|null]
Sale::canBeModified(): array
```
Controllers call it **and re-check inside the transaction**. Views call it to show buttons and reasons. Never duplicate the conditions.

### Show the specific reason
- *"Locked: 5 units from this purchase have already been sold."*
- *"Locked: this sale has a return against it. Delete the return first."*
- *"Locked: more than 24 hours old."*

### Unwinding
Delete the dependent record first (if it's within its own 24h), and the parent unlocks. If the chain is all locked, it stays locked — the fix is a **stock adjustment**, not an edit.

### Closed periods
`settings.books_closed_before` (date). Nothing dated before it can be created, edited, or deleted. Once a month's profit has been reviewed, freeze it.

### Audit
Every edit writes an `activity_logs` row with before/after in the description and the full previous version in `old_values` JSON.

---

## 8d. Sold on a plan — storage and connection

Added when the system was first sold to a shop other than Soran's. Neither
feature exists on an install that was not sold this way: with `STORAGE_LIMIT_MB`
unset there is no meter, no banner and nothing is ever refused.

### Storage

`STORAGE_LIMIT_MB` in `.env` on the server, and nowhere else — the Settings
screen shows the meter but cannot raise it, because a limit the buyer can edit
is not a limit. What counts is what the shop's use actually puts on the seller's
disk: the database (`information_schema` on MySQL/MariaDB, the file on SQLite),
the backup directory, and uploads. Measured together, held for 60 seconds, and
re-measured immediately after a backup — the one thing that moves the figure
sharply.

Amber at 80%, red at 95%, with a banner on every page an admin opens. Staff are
not shown it: a counter assistant who sees a storage warning on every sale
learns to stop reading banners.

At 100% the shop can no longer save anything new. This is the most dangerous
thing in the system — a till that will not record a sale is worse for the
shopkeeper than a full disk — so what stays possible is the design:

- **Reading never stops.** Every screen, report and invoice already written.
- **Deleting never stops.** Space is freed by removing things; a block on both
  sides is a door locked from the inside.
- **Settings never stops**, so backup retention can be shortened, which is the
  one lever inside the system that actually frees space.
- **Signing in and out never stop.** A guest request is signing in, resetting a
  password or confirming an email — never the shop's data growing. This was
  nearly the worst bug in the system: Breeze's login route carries no name, so
  a name-based allowlist refused it and a shop that filled its plan was locked
  out of its own records, owner included. `actingAs()` hid it from the whole
  suite; a browser found it in one click. There is now a test that posts the
  login form.

### Connection

`navigator.onLine` says whether the machine has a network, not whether this
server is on the end of it — a shop on the router's wifi with the fibre cut
reads as perfectly online. So the browser's signal is the instant hint and
`/up` is asked directly to confirm, every 20 seconds while connected and every
5 while not.

Quiet when connected: a dot, because a green "Connected" on every page is a
green "Connected" nobody reads. When it drops, the dot turns red and gains the
word, and a bar crosses the top of the page — *"Nothing typed now will be saved
— wait for this to clear before ringing up a sale."* At a till, the difference
between a page that is working and a page that has lost the network is a sale,
and the browser hides it by default.

## 8b. Technical Standards

### Timezone
Set `APP_TIMEZONE=Asia/Baghdad` (UTC+3) and expose it as an editable **`settings.timezone`** on the settings page. Without it, a 10 PM sale is logged as the next day in UTC, which breaks daily reports, `occurred_at` ordering, and the 24-hour edit window.

### Soft deletes
Every financial and master table uses Laravel `SoftDeletes` (`deleted_at`): sales, purchases, both return types, payments, expenses, adjustments, products, customers, suppliers, categories, users.

- A "deleted" document disappears from lists but stays in the database — a hard-deleted invoice leaves a numbering gap nobody can explain later.
- **Soft-deleting a document must still reverse its effects** — restore batches, reverse ledger rows — exactly like an edit. It is a reversal plus a hidden record, not a way to skip the reversal.
- `document_no` stays consumed. Numbers are never reused.
- Restoring a soft-deleted document is **admin-only** and must re-check the lock conditions in Section 8, because the world may have moved on since.

### Bulk delete
A bulk-delete action is available on list pages, but it is a loop of the normal single-delete logic, not a mass `DELETE`:
- Each row runs its own `canBeModified()` check.
- Rows that fail are **skipped and reported** — *"12 deleted, 3 skipped: already used in sales."*
- Rows still referenced by a foreign key (a category holding products, a supplier with purchases) are refused with the reason.
- The whole batch runs in one transaction; any unexpected error rolls all of it back.

### Database indexes
The FIFO query runs on every sale line, so it must be indexed:

| Table | Index |
|---|---|
| `stock_batches` | `(product_id, quantity_remaining, received_at, sequence)` — the FIFO lookup |
| `stock_movements` | `(product_id, occurred_at, sequence)` · `(reference_type, reference_id)` · `(stock_batch_id)` |
| `sales` / `purchases` | `(sale_date)` / `(purchase_date)` · `(customer_id)` / `(supplier_id)` · unique `(document_no)` |
| `sale_items` / `purchase_items` | `(product_id)` · parent id |
| `account_transactions` | `(accountable_type, accountable_id, created_at)` |
| `payments` | `(payable_type, payable_id)` |
| `products` | unique `(sku)` · unique `(barcode)` · `(category_id)` |
| `activity_logs` | `(user_id, created_at)` · `(module, record_id)` |

### Backups
Financial records are the shop's only proof of who owes what.
- **Nightly `mysqldump`** on a cron schedule, kept **off the machine running the app** — a dead disk should not take both.
- Retain 30 daily and 12 monthly copies.
- **Test a restore before go-live and every few months after.** An untested backup is not a backup.
- Consider `spatie/laravel-backup`, which handles scheduling, compression, and remote upload.

---

## 8c. Settings & Appearance

Split into **three layers**, because they have different owners and different lifetimes.

### Layer 1 — Shop info (global, admin only)

Used on printed invoices, the login page, and the browser title.

| Setting | Example |
|---|---|
| `shop_name` | Soran Store |
| `shop_name_ku` / `_ar` / `_fa` | localised names for RTL invoices |
| `shop_address` | Sulaymaniyah, … |
| `shop_phone` / `shop_phone_2` | |
| `shop_email`, `shop_website` | |
| `shop_logo` | image path — printed on invoices |
| `invoice_footer` | e.g. return policy, thank-you line |

> Store logos as files with only the path in `settings` — never base64 in the database.

### Layer 2 — Appearance (global brand, admin only)

| Setting | Example |
|---|---|
| `primary_color` | `#0d6efd` |
| `secondary_color` | `#6c757d` |
| `font_family` | see the font note below |
| `sidebar_style` | expanded / collapsed |
| `default_theme` | light / dark — the starting point for new users |

**How to apply it:** emit the values as CSS custom properties in the layout `<head>`, then let Bootstrap read them. Never regenerate stylesheets or write CSS files at runtime.

```blade
<style>:root{
  --bs-primary: {{ setting('primary_color') }};
  --bs-body-font-family: {{ setting('font_family') }};
}</style>
```

> **Font choice matters more than usual here.** The UI must render Latin, Kurdish Sorani, Arabic, and Persian well. Many popular Latin fonts have poor or missing Arabic-script coverage and fall back to an ugly system font mid-sentence. Offer a short vetted list — **Noto Sans Arabic**, **Cairo**, **Vazirmatn**, **Tajawal** — rather than a free-text box. Self-host them so the shop works without internet.

### Layer 3 — User preferences (per user)

These belong to the person, not the shop. Add to `users`: `language`, `theme`, `items_per_page`.

| Preference | Default |
|---|---|
| `language` | English |
| `theme` | follows `default_theme` |
| `items_per_page` | 25 |

> **Why per user, not global:** two people share this system. One prefers Sorani, one English; one works in a bright shop, one at night. Forcing a single choice on both is the wrong call — and language already had to be per user anyway, since it controls RTL direction.

**Dark mode:** Bootstrap 5.3 has it built in — set `<html data-bs-theme="dark">`. No custom dark stylesheet needed. Offer light / dark / **auto** (follows the OS).

### Loading — cache it

Settings are read on every page but change perhaps twice a year. Never query them per request.

```php
Cache::rememberForever('settings', fn () => Setting::pluck('value', 'key'));
```

- **Clear the cache whenever settings are saved** — a stale cache after a logo change is confusing and looks broken.
- Share to all views via a **View Composer** or middleware, so `setting('shop_name')` works anywhere.
- User preferences load from the `users` row already in the session — no extra query.
- Provide a `setting($key, $default)` helper so views never touch the model directly.

### Also worth putting on this page

| Setting | Why |
|---|---|
| `timezone` | Section 8b — affects the 24h edit window |
| `usd_rate` | Section 6b |
| `books_closed_before` | Section 8 |
| `low_stock_threshold` | global default when a product has no `reorder_level` |
| `sku_prefix` | currently `SS` — configurable in case the shop is renamed |
| `date_format` | printed and displayed dates |
| Backup status | last backup time and a manual "Back up now" button (Section 8b) |

**Guard the whole page** behind a `settings.manage` permission — these values change invoices, costing, and the edit window across the entire system.

---

## 9. Pages

- [x] **Login / auth** — Breeze; roles **admin** (full) and **user** (per-user permissions)
- [x] **Dashboard** — totals, low-stock alerts, today's sales and expenses
- [x] **Products** — CRUD + opening stock (qty + cost), one category each, SKU (typed or auto `SS65`), barcode (typed or auto-generated)
- [x] **Categories** — CRUD, sub-categories, **filter products by one or several selected categories at once**, and bulk-move selected products into a category
- [x] **Suppliers** — CRUD + balance statement
- [x] **Customers** — CRUD + balance statement
- [x] **Users** — CRUD, admin only; **per-user permission checkboxes** (role sets defaults, admin adds/removes individually)
- [x] **Purchase** — cart interface, one discount column, partial payment, **USD entry helper** (Section 6b)
- [x] **Purchase History** — list/filter (including by status); badge for partly-returned and fully-returned; detail shows timeline (created → payments → returns)
- [x] **Sale** — cart interface, per-line pricing, no discount, partial payment
- [x] **Sales History** — list/filter (including by status); badge for partly-returned and fully-returned; detail shows timeline
- [x] **Sale Return** — partial or full, per line
- [x] **Purchase Return** — partial or full, per line, editable credit
- [x] **Payments** — record money in or out against a specific sale or purchase: a customer paying an invoice (`in`), paying a supplier (`out`), a cash refund to a customer (`out`), or cash back from a supplier (`in`)
- [x] **Expenses** — CRUD against a managed category list
- [x] **Expense Categories** — CRUD, admin only; deactivate rather than delete
- [x] **Stock Adjustments** — manual +/− with reason (damage, theft, miscount, fixing a locked document). Decreases consume FIFO batches; increases create a batch with an entered cost. **Without this, any mistake older than 24h is uncorrectable.**
- [x] **Statistics / Reports** — sales, purchases, profit, discounts received, top products, amounts due and owed
- [x] **Activity Log** — every login, create, update, delete
- [x] **Settings** (admin) — shop info, appearance/branding, timezone, USD rate, `books_closed_before`, backups (Section 8c)
- [x] **My preferences** (every user) — language, light/dark/auto theme, rows per page
- [x] **Recheck stock** (admin) — compare cached `products.quantity` against batch sums, list mismatches
- [x] **Printable invoices** — sale, purchase, sale return, purchase return

### Cart behaviour (Purchase and Sale — one shared component)

| | Purchase cart | Sale cart |
|---|---|---|
| Default price | last purchase price | product's `sale_price` |
| Manual price | price input on select | price input on select |
| Quantity | defaults to 1, typeable | same |
| In cart | edit price, edit qty, delete | same |

- **Barcode scanning** adds the product straight to the cart — search `barcode`, then `sku`, then name.
- Totals recalculate live on every change.
- Adding a product already in the cart **updates that line** — it does not create a second one. (Two lines for one product at different prices is entered deliberately.)
- First-time purchase of a product has no previous price, so manual entry is required.
- **Below-cost warning (sale cart):** if a manual price is below the cost of the batch that would be consumed, show a non-blocking warning — *"Below cost: this unit cost 200,000."* Soran may still sell below cost deliberately (clearance, damaged goods), so it warns rather than blocks.

---

## 9b. Page & Modal Design

### The shell

Fixed **left sidebar** (right in RTL) with grouped navigation, plus a slim topbar holding: global search, language switch, theme toggle, user menu. Sidebar collapses to icons on narrow screens. Only show nav items the user has permission for — never show a link that leads to "access denied".

### Modal or full page? — one rule

> **Modal for a single short form. Full page for anything with a table inside it.**

| Modal | Full page |
|---|---|
| Add/edit category | Sale · Purchase |
| Add/edit customer, supplier | Returns |
| Record a payment | Product create/edit (has image, opening stock) |
| Stock adjustment | All list pages |
| Confirm a delete | Document detail |
| Quick "add product" from inside the sale cart | Settings |

**Never put the sale or purchase screen in a modal.** It has a table, a running total, a barcode scanner, and keyboard navigation — all of which fight a modal's focus trap and cramped height.

Modal rules: one job each, no nested modals, no tabs inside, `Esc` closes, focus lands on the first field, and closing a dirty form asks before discarding.

### The sale/purchase screen — the most important page

Soran uses this a hundred times a day. Every other page can be ordinary; this one has to be fast.

**Layout:** product search on top, cart table in the middle, totals panel on the right (left in RTL), action buttons fixed at the bottom so they never scroll away.

**Keyboard-first — a barcode scanner is a keyboard.**
- Focus sits in the search box **by default and returns there after every add**. A scan then just works, with no clicking.
- Scan or type → matches `barcode`, then `sku`, then name.
- Exact barcode match adds straight to the cart at qty 1; a partial name match shows a dropdown.
- `Enter` adds · `↑ ↓` move rows · `Esc` clears search · `F2` (or similar) saves.
- Qty and price are inline editable in the row — no modal to change a number.
- Scanning the same product again increments its line rather than adding a second one.

**Show the running total large and always visible.** It is the number Soran reads out to the customer.

**Warn without blocking:** below-cost price, low stock, customer over their usual balance. Inline, quiet, non-modal.

### List pages — one pattern everywhere

Filters row (date range, status, category, search) · results table · pagination using the user's `items_per_page`.

- **Sort the newest first** on every transactional list. The thing you need is almost always the thing you just made.
- Show **status badges** (`partly returned`, `returned`, `unpaid`, `partly paid`) so the list answers questions without opening rows.
- Right-align every money column and use `number_format`. Misaligned digits are genuinely harder to read.
- Row actions: **View** always; **Edit/Delete only when unlocked** — otherwise render them disabled with the lock reason as a tooltip. Never hide them, or Soran will think the feature is missing.
- Keep a **Print** and an **Export CSV** on every list.

### Document detail page

Header (number, date, party, status badge) · lines table · totals · payments · **timeline** (created → payment → return → payment) · actions.

If the document is locked, show a **single quiet banner** stating the reason and what would unlock it — *"Locked: 3 units already sold. Delete those sales first."* Not a red alert; it is normal, not an error.

### Destructive actions

Confirm dialogs must state the **consequence**, not ask a generic question.

> ✅ "Delete purchase PUR-54513? Stock will drop by 14 units and the supplier balance will decrease by 950,000 IQD."
> ❌ "Are you sure?"

Type-to-confirm (typing the document number) only for deleting a document that moved stock. Everything else, a plain confirm.

### Feedback

- **Toasts** for success — brief, top-right (top-left in RTL), auto-dismiss.
- **Inline field errors** for validation; never a toast for a form error, because it vanishes before it can be fixed.
- **Disable the save button while submitting.** Double-clicking Save on a sale is the classic way to create a duplicate document.
- Long actions (backup, recheck stock) show progress, not a frozen screen.

### RTL

- Use Bootstrap's **logical properties** (`ms-*`, `me-*`), never `ml-*` / `mr-*`, so one stylesheet serves both directions.
- Icons with direction — arrows, chevrons, back buttons — must **mirror**. Clocks, logos, and product images must not.
- **Numbers and currency stay left-to-right** even inside RTL text.
- Test every screen in Sorani before calling it finished; RTL bugs are invisible in English.

### Print views

A separate minimal Blade layout: no sidebar, no buttons, black on white, logo and shop info from Settings. Use `@media print` and test in a real browser — screen-perfect tables often break across printed pages. Keep the customer's copy to one page where possible.

### Empty states and copy

An empty table is an instruction, not a blank space: *"No sales yet. Create your first sale."* with the button right there.

Follow the interface's own vocabulary consistently — a button that says **Save purchase** produces a toast that says **Purchase saved**. Name things by what Soran controls, never by how the system is built: *"Stock adjustment"*, not *"Create stock_movement record"*.

### Devices

Desktop first — this is a shop counter. But make list pages and the sale screen usable on a **tablet**, since stock counting happens on the shelves, not at the desk.

---

## 10. Worked Example — verify the build against this

**Purchase 1** — Bazaar Mobile · A: 4 @ 200,000 = 800,000 · B: 10 @ 20,000 = 200,000 · subtotal 1,000,000 · **discount 50,000** · grand total **950,000** · paid 600,000 → **owed 350,000**
Batches: **A1** 4 @ 200,000 · **B1** 10 @ 20,000 *(as typed — discount ignored)*

**Purchase 2** — Zagros · A: 6 @ 210,000 = **1,260,000** · unpaid → **owed 1,260,000**
Batch **A2** 6 @ 210,000

**Sale 1** — Rebin, on loan

| Line | Qty | Unit price | Total |
|---|---|---|---|
| A | 3 | 260,000 *(default)* | 780,000 |
| A | 2 | 250,000 *(manual)* | 500,000 |
| **Total** | | | **1,280,000** |

FIFO: line 1 → 3 × A1; line 2 → 1 × A1 + 1 × A2 → **COGS 1,010,000**
A1 → **0/4** · A2 → **5/6** · 🔒 Purchase 1 locked

**Sale return** — 2 units from line 2 → refund 2 × 250,000 = **500,000**
Back in reverse order: A2 gets 1 first, then A1 → A1 **1/4**, A2 **6/6** · COGS reversal **410,000** · Rebin owes **780,000**

**Purchase return** — 3 units to Zagros → credit 3 × 210,000 = **630,000** · A2 → **3/6** · Zagros owed **630,000**

**Purchase return** — 1 unit to Bazaar → credit **200,000** *(default: full unit price, discount share not applied)* · A1 → **0/4** · Bazaar owed **150,000**

### Final position

| Batch | Qty | Cost | Value |
|---|---|---|---|
| A2 | 3 | 210,000 | 630,000 |
| B1 | 10 | 20,000 | 200,000 |
| | | | **830,000** |

**Unit check (A):** 10 in − 5 sold + 2 returned − 4 to suppliers = **3** ✓

| Balance | |
|---|---|
| Bazaar Mobile | 150,000 |
| Zagros Trading | 630,000 |
| Rebin Karim | 780,000 |

| Profit | IQD |
|---|---|
| Revenue 1,280,000 − 500,000 | 780,000 |
| COGS 1,010,000 − 410,000 | −600,000 |
| Gross profit | 180,000 |
| Discounts received (50,000 − 0 applied) | +50,000 |
| **Net** | **230,000** |

Verify: 3 units net sold, all from A1 @ 200,000 = 600,000 ✓ · revenue 3 @ 260,000 = 780,000 ✓

---

## 10b. Acceptance Test — run this before go-live

Section 10 is the readable example. **This is the test.** Every value below is an assertion. Build it as an automated test suite, not a manual click-through, so it can be re-run after every change.

**Fixture:** Product **P**, sale price 30,000 · Supplier **A** · Supplier **B** · Customer **C**

---

### T1 — Purchase, same product twice, with discount

**PUR-1** (Supplier A), unpaid:

| Line | Qty | Unit | Total |
|---|---|---|---|
| 1 | 3 | 10,000 | 30,000 |
| 2 | 2 | 12,000 | 24,000 |
| Subtotal | | | 54,000 |
| Discount | | | −4,000 |
| **Grand total** | | | **50,000** |

- ✅ **B1** = 3 @ **10,000** and **B2** = 2 @ **12,000** — costs exactly as typed, discount not applied to either
- ✅ Supplier A balance = **50,000**
- ✅ `B1.sequence < B2.sequence` (same timestamp — this is what makes FIFO order deterministic)
- ✅ Two `stock_movements` rows, `reference_type = 'purchase'`, quantity +3 and +2

### T2 — Second purchase, paid in full

**PUR-2** (Supplier B): 4 @ 15,000 = **60,000**, paid in full
- ✅ **B3** = 4 @ 15,000 · ✅ Supplier B balance = **0** · ✅ Stock = **9**

---

### T3 — Sale spanning two batches

**INV-1**: 4 units @ 30,000 = **120,000**. Customer pays **100,000**.

- ✅ FIFO takes 3 × B1 @ 10,000 + 1 × B2 @ 12,000 → **COGS = 42,000**
- ✅ **Two** movement rows: `m1` (B1, −3) and `m2` (B2, −1), both with `reference_item_id` set
- ✅ B1 **0/3** · B2 **1/2** · B3 **4/4** · Stock **5**
- ✅ Customer C balance = **20,000**

---

### T4 — 🔴 THE CRITICAL TEST: three separate single-unit returns

Return 1 unit, three times, as three separate documents.

| Return | Batch restored | Cost reversed | State after |
|---|---|---|---|
| #1 | **B2** | 12,000 | B2 **2/2** |
| #2 | **B1** ← moves on | 10,000 | B1 **1/3** |
| #3 | **B1** | 10,000 | B1 **2/3** |

- ✅ Stock = **8**
- ✅ Every return movement has `reverses_movement_id` pointing at `m1` or `m2`
- ✅ **`quantity_remaining` never exceeds `quantity_in` on any batch** — assert this globally after every test

> **This step is where a wrong implementation reveals itself.** Re-deriving "reverse order" from scratch each time sends all three units to B2, giving `quantity_remaining = 4` on a batch whose `quantity_in` is 2, while B1 stays empty forever. Stock totals still look right, so nothing appears broken — but every later sale draws the wrong cost.

**Balance effects** — customer owed 20,000, each refund is 30,000:

| Return | Applied to balance | Paid out in cash |
|---|---|---|
| #1 | 20,000 → balance **0** | 10,000 |
| #2 | 0 | 30,000 |
| #3 | 0 | 30,000 |

- ✅ Balance floors at **0** and never goes negative
- ✅ Three `payments` rows with `direction = out`
- ✅ Sale status = `partly_returned` (3 of 4 units back)

---

### T5 — Delete return #3

- ✅ B1 back to **1/3** · ✅ Stock **7** · ✅ its movement row gone
- ✅ **30,000 cash back in**
- ✅ B1 matches its T4-step-2 state **exactly** — this proves `reverses_movement_id` reverses cleanly

### T6 — Purchase return against a fully-paid purchase

Return 2 units to **PUR-2** → credit 2 × 15,000 = **30,000**

- ✅ **B3** is deducted → **2/4** *(its own batch — NOT the oldest)*
- ✅ Supplier B balance stays **0**, never negative
- ✅ **30,000 recorded as cash in** from the supplier
- ✅ Stock **5**

### T7 — Stock adjustment out (damage)

1 unit, reason `damage`
- ✅ FIFO picks **B1** (oldest with stock) → **0/3**
- ✅ Written off at **10,000**, the true FIFO cost
- ✅ **No balance changes** — stock moves, money does not
- ✅ Stock **4**

### T8 — Oversell is blocked

Attempt a sale of 6 units
- ✅ Rejected with *"Not enough stock: 4 available"*
- ✅ **Nothing written** — no movements, no partial sale, no document number consumed

---

### Final assertions

| Batch | Remaining | Cost | Value |
|---|---|---|---|
| B1 | 0 / 3 | 10,000 | 0 |
| B2 | 2 / 2 | 12,000 | 24,000 |
| B3 | 2 / 4 | 15,000 | 30,000 |
| | **4 units** | | **54,000** |

| Balance | Expected |
|---|---|
| Customer C | **0** |
| Supplier A | **50,000** |
| Supplier B | **0** |

**Integrity check:** `SUM(stock_movements.quantity)` for P = +9 − 4 + 1 + 1 − 2 − 1 = **4** ✅ equals `products.quantity` and `SUM(quantity_remaining)`

**Profit:**

| | IQD |
|---|---|
| Revenue 120,000 − 60,000 returned | 60,000 |
| COGS 42,000 − 22,000 reversed | −20,000 |
| Gross profit | 40,000 |
| Discounts received | +4,000 |
| Damage write-off | −10,000 |
| **Net** | **34,000** |

Cross-check: 2 units net sold, both from B1 @ 10,000 = 20,000 ✓ · revenue 2 × 30,000 = 60,000 ✓

---

### Assertions to run globally, after every test

1. No batch has `quantity_remaining > quantity_in`, and none is negative
2. `products.quantity` == `SUM(stock_batches.quantity_remaining)` == `SUM(stock_movements.quantity)`
3. No customer or supplier balance is negative
4. Every balance equals the latest `account_transactions.balance_after`
5. Every `stock_movements` row for a sale or return has `reference_item_id` set
6. No `document_no` appears twice

### The same assertions, against the real shop — Settings → Data check

The six above guard the engine against the developer. `DataIntegrityService`
asks them of live data instead, where the risk is not a bug in the FIFO code but
a power cut mid-sale, a backup restored from the wrong hour, or a row edited
straight in phpMyAdmin. Seventeen checks in four groups, each one set-based SQL
rather than a loop, so five years of trading still answers in under a second.

Every finding is one of two kinds, and the page sorts by it:

- **Can be rebuilt** — a cache drifted. `products.quantity`, a balance, a
  document status: all derived, so the truth survives and the figure can be
  recomputed. Recheck stock is linked from the finding.
- **Needs a person** — two records that cannot both be right, and nothing else
  can say which. A batch against its own movements, a ledger that stops adding
  up, an invoice that disagrees with its lines.

Beyond the doc's six it also checks: each batch against its own movements *one
by one* (two batches wrong in opposite directions cancel in a total); the ledger
as a **running chain** rather than only its last line (a balance cache written
from a broken chain matches its final row perfectly, so the simpler check passes
with an entry missing from the middle); document and return totals against their
lines; `grand_total = total − discount`; nothing overpaid; nothing returned more
than sold; derived statuses; the Cash Customer at zero; services holding no
stock; counters ahead of every number used; and every polymorphic link this
section marks "no database FK, enforce in code".

Two rules the checks follow, both learned from false alarms:

- **Live documents only** for the totals and status checks. Reversing a sale
  removes its lines (`reverseStock()` ends with `items()->delete()`), so a
  deleted invoice keeps a total with nothing behind it — by design, since the
  lines it had are in the activity log's snapshot. Reported as damage it would
  cry wolf on every delete the shop makes.
- **A soft-deleted parent is not an orphan.** A movement belonging to a deleted
  sale is how a reversal is recorded. Only a parent gone from the table entirely
  counts.

The page is read-only, deliberately: a contradiction is evidence, and repairing
it before it has been read destroys the only record of what went wrong.

---

## 11. Build Order

1. Laravel install, Breeze auth, roles + permissions, localization, timezone, settings
2. Migrations for **all** tables, with indexes and soft deletes from the start
3. Master data CRUD — categories, products, suppliers, customers, users
4. **Purchase + `stock_batches`** — the foundation
5. **Sale + FIFO consumption + `stock_movements`** — reproduce Section 10 exactly before going further
6. Payments + `account_transactions` + balances
7. Returns (both kinds)
8. Lock rules + editing + deleting
9. Stock adjustments, expenses
10. Statistics, activity log, printable invoices
11. Backups configured and a restore tested — before go-live, not after

> **Do not build editing before create works.** Get Section 10b's acceptance test passing first — editing is a layer on top of correct create logic, and building both at once means debugging two hard things simultaneously.

**Unit-test these:** FIFO spanning multiple batches · **repeated partial returns on one line that spanned two batches** (return 1, then 1, then 1 — the third must land in the first batch, not the second) · deleting a return and confirming batches match their pre-return state exactly · cumulative `quantity_returned` · lock conditions · discount share on purchase returns · **two concurrent sales of the same product** (the locking test — run it with real parallel requests, not sequential calls).

---

## 12. Task Log

| Date | Done | Next |
|---|---|---|
| 2026-08-29 | **Delete permanently**, at Soran's request, on the deleted-products list and admin only — because a soft-deleted product goes on holding its SKU and its barcode, and a row typed in by mistake blocks those codes for good. Everything else this system calls delete can be undone from the screen it was done on; this one cannot, so it is hedged three ways: the seven `restrict` keys are asked *before* the button and the answer is shown on it (disabled, with the reason in a tooltip), a backup is taken first outside the transaction, and the press is held for two seconds and confirmed. The counts come from the query builder rather than from relations, because `stock_adjustments` is soft-deleted *and* carries the archived-period scope — counting it through Eloquent would report nothing while MySQL still refused, which is the exact gap between a sentence and a 500. The product's own log rows go with it and one `purge` entry replaces them. Needs `php artisan migrate`: `activity_logs.action` is an enum and gained a value. Suite: 464 tests, 463 passing, 1 skipped. | — |
| 2026-08-29 | **Data check** (Settings), at Soran's request after phpMyAdmin showed 169 adjustments where the screen showed 159 — which was ten soft-deleted rows, exactly as designed. Seventeen checks asking Section 10b's global assertions of the real shop instead of a test, split into what can be recalculated and what needs a person. The two that earn the page: each batch against its own movements one by one, because two batches wrong in opposite directions cancel in a total; and the ledger as a running chain, because a balance cache written from a broken chain matches its own final row perfectly — a test in `DataCheckTest` deletes an entry from the middle and asserts the simple check passes while the chain check fails. Every check is proved twice: silent on a shop that really traded, and naming the row when one is broken on purpose. Two false alarms found that way and fixed: a deleted sale keeps its total but loses its lines (`reverseStock()` deletes them, and nothing restores a sale), and a soft-deleted parent is not an orphan. Also: `translations:check` tokenises the source, so every status word and table name is written as a literal inside `__()` rather than as `__($variable)` — the same blind spot as the RecordHistory labels. Read-only by design. Suite: 491 tests, 490 passing, 1 skipped. | — |
| 2026-08-29 | **`as lines` broke the data check on the live shop.** LINES is reserved in MariaDB and ordinary in SQLite, so 494 tests passed and the page answered with a syntax error on data that was fine. Fixed twice over. Every alias the service invents now starts with `chk_`, which cannot collide with a reserved word in any engine present or future, and a test enforces that by tokenising the source — reading only the SQL strings, since `__('costed as if it were…')` is not an alias called `if`. And each check now runs inside `attempt()`: a check that throws costs that one check, not the page. It is shown as **Did not run** with the reason, never as "agrees", because a question that went unasked reported as a pass is worse than the crash it replaced. Verified the honest way this time — MariaDB 10.11 installed in the container, migrated, seeded and traded against: all 17 checks green on sound data and all 17 red on data broken on purpose, so every failure branch's SQL ran too (the window function in `ledger_chain` included). MariaDB removed afterwards; it puts `mysqldump` on PATH, which two BackupTest cases exist to test the absence of. Suite: 494 tests, 493 passing, 1 skipped. | — |
| 2026-08-29 | **Sold on a plan: storage and connection** (Section 8d). `STORAGE_LIMIT_MB` in .env and nowhere else — the buyer reads the meter, only the seller sets it. Counts the database, the backups and the uploads, since that is what the shop's use actually costs the seller. Amber at 80%, red at 95%, admin-only banner; at 100% nothing new can be saved, which Soran asked for explicitly. Everything about it is the blast radius rather than the block: reading, deleting, Settings and signing in all keep working, so a full shop can still trade on what it has, free space, and get back in. That last one was nearly a disaster — Breeze's login route has no name, my allowlist read names, and a full shop could not log in *at all*; `actingAs()` hid it from all 519 tests and a browser found it in one click. And a connection indicator, because `navigator.onLine` cannot tell a cut fibre from a working shop: a quiet dot that turns red and puts a bar across the page saying nothing typed now will be saved. Suite: 519 tests, 518 passing, 1 skipped. | — |
| 2026-08-19 | Design finalised — FIFO, pricing, returns, locking settled. Doc rewritten clean. | Scaffold Laravel, install Breeze, write migrations |
| 2026-08-19 | Added: per-user permissions, SKU/barcode rules, cash refunds, document numbering, USD entry helper | Same |
| 2026-08-19 | Review pass: batch locking (concurrency), `stock_adjustments` table, `document_no` everywhere, Cash Customer, timezone, soft deletes + bulk delete, indexes, backups, below-cost warning, stock-cache rule | — |
| 2026-08-19 | Expense categories table; sale/purchase `status`. **Design complete, no open questions.** | — |
| 2026-08-19 | Relationship audit: `reference_item_id`, `reverses_movement_id`, batch `source_type`, purchase movements, full FK reference. Added Section 10b acceptance test. | Scaffold Laravel and write migrations |
| 2026-08-20 | **Build steps 1–5 done.** Laravel 13 + Breeze scaffolded; all 25 migrations with indexes and soft deletes; all models; FIFO engine; purchase, sale, both returns, adjustments, payments and ledger services; permissions, settings, document numbering, SKU/barcode. **Section 10b acceptance test passes — 313 assertions.** Suite: 45 passing, 524 assertions. | Master data CRUD, Bootstrap 5 RTL shell, purchase/sale cart screens |
| 2026-08-20 | UI: Bootstrap 5 shell (sidebar, topbar, RTL, dark mode), master-data CRUD, dashboard, sale and purchase cart screens with barcode-first keyboard flow and the USD helper. Verified in a real browser. Suite: 50 passing, 562 assertions, 1 skipped. | Returns screens · payments · expenses · adjustments UI · reports · activity log · settings page · `lang/` files · print views |
| 2026-08-20 | **Section 9 page list complete.** Return screens (both kinds), payments, expenses and expense categories, stock adjustments, recheck stock, reports, activity logging, settings page, printable documents, and the interface translated into Sorani, Arabic and Persian at 100% coverage. Public registration removed. Suite: 73 passing, 751 assertions, 1 skipped. | Edit/delete flows (build step 8) · bulk delete · backups (step 11) · the concurrency test against real MySQL |
| 2026-08-22 | **Barcode labels.** Section 4 generates an internal EAN-13, so nothing is printed on the goods; "Print barcode" on a product page now opens a modal for the count, the label size and which fields appear, defaulting from Settings. The constraint that shaped it: a web page cannot choose a printer — `window.print()` hands the job to the OS. So the browser route (a page sized exactly to the stock, one page per copy) always works, and direct TSPL to a shared printer is the shortcut, possible only because the server runs on the same machine. Bars get 95/113 of the usable width so they keep the quiet zone EAN-13 needs; a label too narrow warns rather than refusing. The TSPL path itself is unverified here — this container has no printer. Suite: 233 passing, 2295 assertions, 1 skipped. | As below |
| 2026-08-22 | **A purchase return can be undone now**, which closes the last asymmetry in the build: a sale return could be deleted and its mirror image could not. It is the easier of the two, and the reason is worth keeping — a sale return puts units back on the shelf, so undoing it has to take them away again and somebody may already have bought them; a purchase return sends units away, so undoing it puts them back into the batch they left, which nobody else can have touched. What is left to guard is the closed period, the `purchase_returns.delete` permission, and the arithmetic: a batch may never end up holding more than it was bought with. Suite: 213 passing, 2132 assertions, 1 skipped. | As below |
| 2026-08-22 | The keypad became a **calculator**: `+ − × ÷ =` alongside the digits, because the sums done at a price field are real ones — taking a discount off (15000 − 500) or working back from a total a customer quotes (36000 ÷ 3). OK finishes a pending sum, so 20000 − 1500 then OK applies 18500 rather than 1500. A division that does not land whole shows its remainder and is rounded to whole dinars on apply. The grid is forced left-to-right: a keypad is not text, and without that it mirrored in Sorani to 9 8 7 with C on the wrong side — which the automated RTL check missed, because it was reading DOM order rather than where the keys actually sit. | As below |
| 2026-08-22 | **A price could never be more than one digit long.** Both carts called `render()` from their `input` handler, and `render()` replaces `cartBody.innerHTML` — so the focused field was destroyed after the first keystroke. Rows now update their derived figures in place. Added the keypad Soran asked for while reporting it: tapping a price or a quantity opens a modal with big buttons, and it takes the physical keyboard too — Enter applies, Escape cancels. F2 is ignored while it is open, or it would save the document from under a half-typed price. Suite: 199 passing, 2017 assertions, 1 skipped. | As below |
| 2026-08-22 | Two more at Soran's request. **Start fresh** (Settings → Danger zone) clears the testing period and keeps the catalogue: backup taken first, shop name typed to confirm, refused once `books_closed_before` is set. **Archive a period** exports it to a ZIP of spreadsheets and hides it from the lists — deleting nothing, because a months-old purchase can still own stock on the shelf, a sale's movements are the only record of its cost, and a balance is a running total of the ledger. Hiding is a local scope used only by the index screens, so reports and FIFO still see the whole history. Found and fixed a latent restore bug on the way: the SQLite dump wrote one table at a time, so `activity_logs` rows were inserted before the `users` table existed — every real restore would have failed. Suite: 194 passing, 1977 assertions, 1 skipped. | As below |
| 2026-08-21 | **Section 8c Layer 2 was saving but not applying** — three separate causes. The brand `<style>` block was emitted *before* the compiled stylesheet, so Bootstrap's own `:root` won on source order and neither the colour nor the font ever took effect. Bootstrap 5.3 compiles component colours from SCSS, so `.btn-primary` ignores `--bs-primary` regardless — the component variables are now set individually, with shades and a readable foreground derived from the chosen hex. The font stack was HTML-escaped by `{{ }}`, and a `<style>` block does not decode entities, so `&#039;Cairo&#039;` was invalid CSS; it is matched against the vetted list and printed raw. The four fonts were never bundled at all, so choosing one changed nothing even in principle — they are self-hosted now. And the logo was stored as a `/storage/…` URL that needs `storage:link`, which needs administrator rights on Windows; it is served from a route instead. Suite: 165 passing, 1744 assertions, 1 skipped. | As below |
| 2026-08-21 | Two additions beyond the doc, at Soran's request. **Backup configuration on the Settings page**: daily or weekly, at what time and on which day, both folders and both retention counts, with a folder checked for writability when it is saved rather than at 02:15 by a cron job nobody is watching. Settings beat `.env`, which stays the fallback. **Import & export** of master data — products, categories, suppliers, customers — as CSV with a UTF-8 BOM so Excel reads Kurdish names, with a preview before anything is written. It cannot write `quantity` or a balance: both are caches (Section 4), and a file that tries is reported rather than obeyed. Suite: 144 passing, 1598 assertions, 1 skipped. | As below |
| 2026-08-21 | **Build order complete.** Step 8: reverse-and-re-apply edit and delete for purchases and sales, with the cart screens reused as edit screens. Step 8b: bulk delete as a loop of the single-delete logic, skipping and reporting locked rows. Step 11: nightly backup, 30 daily / 12 monthly retention, an off-machine copy, a `backup:restore` command and `docs/BACKUP.md`. Three bugs found on the way: lock rule 3 compared microsecond `occurred_at` against second-precision `created_at` and locked untouched sales; the purchase lock reason said "1 units"; and the translation extractor read only the first literal of a concatenated message, so two plural branches had never been translated. Suite: 116 passing, 1394 assertions, 1 skipped. | The concurrency test and the `mysqldump` path, both against real MySQL · a restore drill on real data |

---

## 13. Open Questions

**Three small gaps found during the build.** None blocks progress; each was resolved the conservative way, and each is worth a decision when convenient.

**1. Opening stock has no `source_type` of its own.**
Section 5 requires a starting batch for products already in the shop, but Section 4 allows a batch to come only from a `purchase` or an `adjustment` — "never both, never neither." Opening stock is neither.
*Resolved as:* an `in` stock adjustment, which needs no schema change. Section 4's `reason` list has no `opening` value either, so it is recorded as `other` with the note "Opening stock".
*Worth deciding:* whether to add an `opening` reason so the adjustment report separates real corrections from initial setup. Adding one is a one-line enum change.

**2. `products` has no image column, but Section 9b implies one.**
Section 9b lists "Product create/edit (has image, opening stock)" as a full-page form, while the Section 4 schema has no image field.
*Resolved as:* no image. Section 4 wins; Section 9b's parenthesis should be corrected to say "opening stock" only.

**3. Deleting a return: how the refunded cash comes back.**
Section 10b T5 asserts "30,000 cash back in" when return #3 is deleted, without saying whether that is a new `direction = in` payment or the removal of the original `direction = out` one.
*Resolved as:* the original outbound payment is soft-deleted, per Section 8b's rule that a soft delete reverses the document's effects. The net till movement is identical, and no phantom inbound payment appears in the cash-in report. The acceptance test asserts the net change rather than a literal `in` row.

### Notes from the build — no decision needed

- **FIFO ordering needs sub-second timestamps.** `received_at` and `occurred_at` are `timestamp(6)`. Laravel formats a date binding as `Y-m-d H:i:s` at the connection, so two purchases entered in the same second tied on `received_at` and fell through to `sequence` — which is only meaningful *within* one purchase. With microseconds, Section 4's "order by `received_at`, `sequence`" rule holds exactly as written.
- **The permission catalogue was derived, not given.** Section 4 lists the default set and a few example keys but never the full list, so it is generated from the Section 9 page list: view/create/edit/delete per module, plus `settings.manage`, `stock.recheck`, `reports.view` and `activity_logs.view`. Reviewing it is worthwhile.
- **The concurrency test cannot pass in CI as configured.** The suite runs on SQLite, where `lockForUpdate()` is a silent no-op, so the test skips itself rather than reporting a meaningless pass. Run it against real MySQL before go-live: `DB_CONNECTION=mysql php artisan test --filter=ConcurrencyTest`.
- **The interface is translated, but a native speaker should review it.** All 542 strings are at 100% coverage in Sorani, Arabic and Persian, and `php artisan translations:check` keeps it that way — it fails when a new page adds an untranslated string, and `TranslationTest` runs it as an assertion. The accounting vocabulary is where a review matters most: *batch*, *FIFO cost*, *discount share*, *ledger* and *write-off* were each translated to be unambiguous rather than literal, and a shopkeeper's ear will judge them better than a dictionary.
- **Never pass a machine value to `__()`.** A bare key resolves to a translation *file*, so `__('auth')` returns Laravel's whole auth array rather than the word. Enum values and permission group names render through `Str::headline()` instead. This cost a broken page during the build.

### What Section 9 still needs

**Every page in the Section 9 list is built, and every step of the Section 11 build order is done.**

What remains needs a real server rather than more code:

- **The concurrency test**, which needs a real MySQL run (see above).
- **The TSPL path of label printing**, for the same reason: this container has no printer. The browser route is fully verified — the sheet, the sizes, the quiet zone and the field toggles all checked in a real browser — but the bytes sent to a shared XPrinter have never left a machine. Print one label through the browser first, then try the direct route with a single copy before trusting it with a roll.
- **The `mysqldump` path of the backup**, for the same reason. The service, the schedule, the retention, the off-machine copy and the restore all run end to end and are covered by tests, but on the test database's driver — the `mysqldump`/`mysql` branch has never been executed here, because this container has no MySQL. Run `php artisan backup:run` once on the shop's server and read `docs/BACKUP.md` before go-live.
- **A restore drill on real data.** `docs/BACKUP.md` has the five-step procedure. Section 8b is right that an untested backup is not a backup, and a restore tested against a seeded test database is not the same as one tested against the shop's.

### Asked for during the build, decided against

- **Deleting old transactions when archiving** was proposed and not built. A purchase from eight months ago can still own a batch with stock on the shelf, so deleting it makes real units vanish from the system; every sale's movements name the batch they came from, which is the only record of what those units cost, so deleting them makes every profit figure before the cut-off unknowable; and a balance is a running total of `account_transactions`, so deleting old entries leaves a debt nobody can explain. Archiving therefore exports and hides, and `books_closed_before` freezes. If volume ever genuinely becomes a problem — five years of a busy shop is roughly 200,000 stock movements, which the existing indexes answer instantly — the only safe deletion would be documents that are before the closed-books date AND whose batches are fully consumed AND whose balances are settled.


- **Partial restore** (restoring only `products` and `suppliers` from a backup) was proposed and not built. It cannot be made safe: those tables are one interlocking ledger with `stock_batches`, `stock_movements` and `account_transactions`, so restoring a subset leaves `products.quantity` disagreeing with the batch sums and a balance disagreeing with the ledger — silently, with nothing in the system to notice. Master-data import/export covers what the request was actually for (price updates, stocktake lists, sharing a list with an accountant) and cannot corrupt anything, because it never writes a cached column.

### Two things worth deciding

- **The permission catalogue is derived, not specified.** Section 4 gives the default set and a few examples; the ~55 keys now in `PermissionSeeder::CATALOGUE` were generated from the Section 9 page list. Since it governs who can do what, it is worth reading once.
- **Public registration was removed.** Breeze ships a `/register` route; Section 9 makes user creation admin-only, so leaving it would have let anyone reaching the shop's URL create an account. New staff are added at `/users`. Password reset also needs a real mail driver configured before it works — until then, an admin resets a password from the Users screen.

Resolved during design: negative customer balance (not allowed → cash refund) · negative supplier balance (not allowed → cash back) · roles (admin + user with per-user permissions) · walk-ins (Cash Customer) · editing rights (permission-based) · cash/till tracking (not included) · expense categories (managed list) · full returns (status `returned`, never voided).

Add new questions here as they come up during the build.
