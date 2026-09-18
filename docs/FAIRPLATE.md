# FairPlate — Product Spec

## What it is
A local food delivery marketplace launching in Tallahassee, FL. It is an
alternative to DoorDash/Uber Eats. Domain placeholder: fairplate.app.

## Business rules
1. Restaurants pay NO commission and have NOTHING deducted from orders.
   They pay one flat monthly fee based on last month's completed-order
   tier. Months under 41 orders are free.
2. Menu prices match in-store prices. Restaurant specials show in the app.
3. The customer covers every per-order cost, so no order ever costs
   FairPlate money.
4. FairPlate revenue = restaurant tier fees + platform fees +
   memberships.
5. Drivers are independent and multi-app. Every offer shows the
   guaranteed payout (base + miles + tip) and both distances before the
   driver accepts. Guaranteed pay never drops after acceptance.
6. Tips go 100% to the driver.

## Customer charge lines
- Food subtotal
- Sales tax (restaurant's tax_rate)
- Driver pay: base + per-mile × route miles (restaurant→customer),
  minimum payout applies
- Wait pay: per-minute after the free minutes at the restaurant, capped
- Tip
- Platform fee: non-members only; members $0
- Service fee: exact card-processing gross-up. Label is always
  "Service fee," never "surcharge" or "card fee."

## Pricing formulas (integer cents, PricingService only)
  driver_guaranteed = max(base + round(per_mile × miles), min_payout)
  wait_pay = min(max(0, wait_minutes − free_minutes) × per_min, wait_cap)
    wait_minutes = whole minutes from arrived_at_restaurant to picked_up
  S = subtotal + tax + driver_guaranteed + wait_pay + tip + platform_fee
  charge_total = ceil((S + processing_fixed_cents) / (1 − processing_pct))
  service_fee = charge_total − S

- Checkout:
  - Displayed total = computed with wait_pay = 0.
  - Authorized amount = computed with wait_pay = wait_cap.
- Delivery: recompute with the actual wait_pay and capture. The capture
  can never exceed the authorization.
- Tip increase within 24h of delivery: separate charge = tip_delta
  grossed up the same way. The full tip_delta goes to the driver.
- Route miles are locked at checkout.

## Settings (seed values; all editable in admin)
driver_base_cents=300
driver_per_mile_cents=100
driver_min_payout_cents=500
driver_wait_free_minutes=10
driver_wait_per_min_cents=20
driver_wait_cap_cents=300
processing_pct=0.029
processing_fixed_cents=30
platform_fee_cents=199
membership_price_cents=999
comparison_commission_pct=0.25
offer_timeout_seconds=45
dispatch_max_rounds=5
dispatch_max_minutes=8
tip_adjust_window_hours=24
location_ping_online_seconds=15
location_ping_active_seconds=10

## Restaurant fee tiers (seed)
  0–40 → 0
  41–100 → 24900
  101–250 → 54900
  251–500 → 99900
  501+ → custom (admin sets custom_fee_cents; no auto-invoice until set)
- founding_discount_pct is applied to the tier fee.
- Billing uses America/New_York calendar months.

## Money flow (Stripe Connect, separate charges and transfers, manual
## capture)
- On delivery capture:
  - Restaurant gets subtotal + tax IN FULL.
  - Driver gets driver_guaranteed + wait_pay + tip.
  - Platform keeps the platform fee. The service fee covers processing.
- Record the actual Stripe fee per charge from the balance transaction.
- Refunds:
  - Cancel before pickup: release the authorization, no transfers.
  - After pickup: the driver is still paid; the platform absorbs the
    refund unless admin marks it a restaurant error (then reverse only
    the restaurant's portion).
  - Record processing absorbed on refunds.

## Order statuses
placed → accepted | rejected → ready → driver_assigned →
arrived_at_restaurant → picked_up → arrived_at_customer → delivered.
Also: cancelled, needs_attention.
(ready and driver_assigned may occur in either order.)

## Roles
customer, restaurant_staff, driver, admin

## Global constraints
- PHP 8.2, Keel conventions only. Deck CSS, mobile first, minimum 44px
  tap targets. No Tailwind.
- Views are regular HTML/PHP with no large echoed HTML or JS blocks.
- Money is always integer cents. No floats in money code.
- No pricing math outside PricingService. No hardcoded prices, rates, or
  tiers.
- No commission, percentage fee, or processing deduction on restaurant
  sales anywhere. comparison_commission_pct is display-only.
- Pricing settings are snapshotted onto each order. Later setting
  changes never alter existing orders.
- Prepared statements, CSRF on every form, role middleware on every
  route.
- Store timestamps in UTC; display in America/New_York.
- Secrets only in .env.
- Customers see the driver's location only during their own active
  delivery.
