-- What monthly restaurant billing needs on top of the phase-3 statement table.
--
-- The status list is the phase-7 one. 'awaiting_custom_fee' becomes
-- 'needs_review', which is the same state under the name the billing job and
-- the admin alert use; nothing has ever written a statement row, so no data is
-- being renamed. 'free' and 'payment_failed' are new: a month under the first
-- tier is settled the moment it is counted and never becomes an invoice, and a
-- failed charge has to be a state the kitchen banner can ask about rather than
-- something only Stripe knows.
--
-- sales_subtotal_cents is what commission_equiv_cents was computed from. The
-- comparison is display-only and a restaurant is entitled to check it, which
-- needs the base as well as the result.
--
-- due_on carries the net-7 term. Stripe only accepts days_until_due on invoices
-- the customer pays by hand, and these are charged automatically, so the term
-- is ours to state and store rather than Stripe's to enforce.
--
-- The two URLs are Stripe's own, copied once when the invoice is finalized.
-- The statements page would otherwise make one API call per row on every view,
-- and a page that lists a year of history would be a page that needs Stripe to
-- be up.
ALTER TABLE restaurant_monthly_statements
    MODIFY COLUMN status ENUM(
        'draft', 'free', 'needs_review', 'invoiced', 'paid', 'payment_failed', 'void'
    ) NOT NULL DEFAULT 'draft',
    ADD COLUMN sales_subtotal_cents INT UNSIGNED NOT NULL DEFAULT 0 AFTER orders,
    ADD COLUMN due_on DATE NULL AFTER stripe_invoice_id,
    ADD COLUMN invoice_pdf_url VARCHAR(500) NULL AFTER due_on,
    ADD COLUMN hosted_invoice_url VARCHAR(500) NULL AFTER invoice_pdf_url,
    ADD INDEX idx_statements_invoice (stripe_invoice_id);

-- @down
ALTER TABLE restaurant_monthly_statements
    DROP INDEX idx_statements_invoice,
    DROP COLUMN hosted_invoice_url,
    DROP COLUMN invoice_pdf_url,
    DROP COLUMN due_on,
    DROP COLUMN sales_subtotal_cents,
    MODIFY COLUMN status ENUM(
        'draft', 'awaiting_custom_fee', 'invoiced', 'paid', 'void'
    ) NOT NULL DEFAULT 'draft';
