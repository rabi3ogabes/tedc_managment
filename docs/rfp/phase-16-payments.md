# Phase 16 — Course purchasing and payments

Closes: PAY-01 (catalogue, cart, checkout), PAY-03 (entity bundles), PAY-04 (prices per trainee category) — available; PAY-02 (Ministry payment gateway) — partly: the integration is built to a **specification we had to assume**, see below. Everything sits behind the `payments` feature flag (off by default) and Settings → Payments.

## Never handling card data
The buyer is redirected to the gateway's hosted payment page and comes back. TEDC stores only the order, the payment reference, the amount and a PII-free status. The return page only *shows* what the server knows; **money is believed only from the gateway's signed server-to-server message** (`POST /payments/callback/{gateway}`), checked with an HMAC over the raw body, applied once (`payment_events` keeps one row per gateway event, so the same message twice changes nothing, and a forged message can never use up a real event id). An amount that differs from the order is not accepted and finance is told.

## Prices (PAY-04)
`price_lists` per group (a group's own list wins) or per programme: ordered rules `{category, price, match?}` where the category is `ministry_staff` (government school), `private_school`, `external` (the people approved from an external request), `entity` (per seat) or `any`, and `match` is an optional list of the same eligibility conditions programmes use (job title, region, …). The first rule that fits sets the price, so a course can be free for ministry staff and paid for others. No price list = free. VAT is configurable per list (default from Settings), refund policy per list. The catalogue and programme page show **Free for you** or the price (VAT included) to a signed-in person and the range to a visitor; the admin editor previews the price as each category.

## Cart, seats and checkout (PAY-01)
Adding a group to the cart holds its seats for a few minutes (`seat_holds`; counted by `TrainingGroup::seatsAvailable()` so nobody else can take them; the group row is locked while seats are counted, so two buyers cannot take the last one). Checkout re-prices, re-checks the discount code, creates the order (30 minutes to pay), turns the cart hold into an order hold and sends the buyer to the gateway. On a verified success the order is paid, the hold is released and the person is registered (still through the normal registration engine; a paid seat skips the manager's approval when the setting is on), an invoice (INV-YYYY-000001, bilingual PDF, numbers without gaps) is issued and a receipt notification sent. Failure, cancel, amount mismatch or running out of time release the seats and tell the buyer. A free or fully discounted order is confirmed at once. `tedc:payments-tick` (every minute) expires carts and unpaid orders; `tedc:payments-daily` reconciles and sweeps vouchers.

Discount codes: percent or amount, scope (everything, a programme or a group), dates, total limit, per-person limit; counted once per paid order.

## Entities (PAY-03)
`entity_accounts` with administrators (a private school, a company…). An administrator fills the entity's cart (N seats of a group at the entity price), pays, and gets one **voucher** per seat. Vouchers are given to people by employee number or e-mail (a list can be pasted), each person is notified, and redeems the code to register — their eligibility is checked like anyone's, and the seat that was reserved for the voucher becomes theirs without being counted twice. Unused vouchers expire (and free their seats); administrators are warned before. A usage dashboard shows bought, used, unused, expired and spent.

## Refunds and credit notes
Policy per list: full refund until *N* days before the start, a percentage until *M* days before, nothing after. A person (or an entity, for **unused** vouchers only) requests; users with `refunds.approve` approve or refuse (a refusal needs a reason); approval asks the gateway to return the money, cancels the registration or withdraws the voucher (the seat is released and the waiting list moves), marks the order refunded or partly refunded and issues a credit note (CN-YYYY-000001). A gateway failure leaves the refund *failed* and tells finance.

## Reconciliation and reports
Each day the gateway's statement is compared with the payments recorded: a payment whose callback never arrived is completed, an amount or status that differs, a payment unknown to the platform or missing at the gateway is listed and finance is told. Finance reports: gross, VAT, discounts, refunds, net, outstanding; revenue by programme, category and entity; Excel / PDF / CSV.

## Roles and permissions
New role **Finance Officer** (`finance_officer`). Permissions `pricing.manage`, `orders.view`, `refunds.approve`, `entity_accounts.manage`, `finance.reports` — given to finance and the head of training; the training supervisor has all but refund approval; employees and trainers have none.

## Web / mobile
Web: price on cards and programme page, group selector and Add to cart, cart drawer with discount code and checkout, payment return page (and the training gateway page), My orders (invoices, credit notes, refund request, voucher redemption), Entity account (buy, vouchers, usage), Prices editor per programme/group with preview, Payments & finance workspace (orders, payments, refunds queue, discount codes, entities, reconciliation, reports, settings). Mobile: price and Add to cart on the programme, cart and checkout through the gateway in the system browser, orders and voucher redemption.

## What the Ministry must supply (PAY-02)
The e-payment gateway's real specification and credentials: endpoint URLs, merchant id, the shared secret and signing method, the redirect and callback field names, refund and statement APIs, test environment. `MoeEpayGateway` assumes JSON over HTTPS, HMAC-SHA256 hex signatures over the raw body, statuses SUCCESS/FAILED/CANCELLED, `POST /payments`, `POST /payments/{id}/refund`, `GET /transactions?date=`. It has been tested against a mock only. Also: the seller's legal name and tax number for invoices, the VAT rate (default 0), and the refund policy the Ministry wants.

## Known limits
- Gamification rewards cannot yet mint a discount code automatically (codes are created by hand).
- Entity bundles are per group; a bundle of several courses at a combined price is not modelled (add several groups to the cart).
- Invoices are PDFs only (no e-invoicing integration); credit notes cover refunds, not manual price corrections.
- The web cart is for one currency (QAR) and one buyer at a time; paying for another person's seat as an individual is not supported (use an entity account).
- Concurrency is guarded by a row lock and verified sequentially in tests, not under parallel load.
