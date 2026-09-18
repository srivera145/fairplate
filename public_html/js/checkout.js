/* Checkout: the tip, the breakdown, and the card.
 *
 * This file does no arithmetic. Not "very little" — none. Changing the tip posts
 * the *choice* to /app/checkout/quote, and what comes back is the whole
 * breakdown as markup plus the two totals, already worked out by PricingService.
 * The page swaps the markup in. That is what makes the number on the button and
 * the amount the card is held for the same number by construction: they came
 * from the same request, and there is no second opinion in here to disagree with
 * it.
 *
 * The same request moves the PaymentIntent to the new amount, so a tip changed
 * after the card was typed changes what Stripe holds without the card having to
 * be typed again.
 *
 * Paying confirms with Stripe and returns to /app/checkout/complete. No order is
 * created by that redirect; the webhook does it. This file could not create one
 * if it tried.
 */
(() => {
	const mount = document.querySelector('[data-checkout]');

	if (!mount || typeof window.Stripe !== 'function') {
		return;
	}

	const payButton = document.querySelector('[data-checkout-pay]');
	const payTotal = document.querySelector('[data-pay-total]');
	const errorLine = document.getElementById('payment-error');
	const breakdownBox = document.getElementById('checkout-breakdown');
	const csrf = document.querySelector('meta[name="csrf-token"]');

	const quoteUrl = mount.dataset.quoteUrl;
	const returnUrl = new URL(mount.dataset.returnUrl, window.location.origin).toString();

	const stripe = window.Stripe(mount.dataset.publishableKey);
	let elements = null;

	function showError(message) {
		if (!errorLine) {
			return;
		}

		errorLine.textContent = message || '';
		errorLine.hidden = !message;
	}

	function mountElements(clientSecret) {
		elements = stripe.elements({
			clientSecret: clientSecret,
			appearance: { theme: 'flat' },
		});

		elements.create('payment', { layout: 'tabs' }).mount(mount);
	}

	mountElements(mount.dataset.clientSecret);

	/* --- The tip ---------------------------------------------------------- */

	const presets = [...document.querySelectorAll('[data-tip-preset]')];
	const customToggle = document.querySelector('[data-tip-custom-toggle]');
	const customField = document.querySelector('[data-tip-custom-field]');
	const customInput = document.querySelector('[data-tip-custom-input]');
	const customApply = document.querySelector('[data-tip-custom-apply]');

	function lightTip(activeButton) {
		[...presets, customToggle].filter(Boolean).forEach((button) => {
			const on = button === activeButton;
			button.classList.toggle('btn-primary', on);
			button.classList.toggle('btn-outline', !on);
		});
	}

	async function requote(payload, activeButton) {
		showError('');

		if (payButton) {
			payButton.disabled = true;
		}

		try {
			const response = await fetch(quoteUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-Token': csrf ? csrf.content : '',
				},
				body: JSON.stringify(payload),
			});

			if (!response.ok) {
				throw new Error('quote responded ' + response.status);
			}

			const data = await response.json();

			if (breakdownBox && typeof data.html === 'string') {
				breakdownBox.innerHTML = data.html;
			}

			if (payTotal && typeof data.estimate_cents === 'number') {
				payTotal.textContent = usd(data.estimate_cents);
			}

			/* A re-quote can hand back a different PaymentIntent — Stripe
			   refuses an amount change on one that has moved on, so the server
			   makes a fresh one. Re-mounting is the only way the element can
			   know about it. */
			if (data.client_secret && data.client_secret !== mount.dataset.clientSecret) {
				mount.dataset.clientSecret = data.client_secret;
				mount.innerHTML = '';
				mountElements(data.client_secret);
			}

			lightTip(activeButton);

			if (payButton) {
				payButton.disabled = !data.ok;
			}
		} catch (error) {
			showError('We could not update your total. Check your connection and try again.');

			if (payButton) {
				payButton.disabled = false;
			}
		}
	}

	/* Cents to "$12.34". Formatting, not arithmetic: the integer came from the
	   server and is only being punctuated. */
	function usd(cents) {
		const sign = cents < 0 ? '-$' : '$';
		const whole = Math.floor(Math.abs(cents) / 100);
		const part = String(Math.abs(cents) % 100).padStart(2, '0');

		return sign + whole + '.' + part;
	}

	presets.forEach((button) => {
		button.addEventListener('click', () => {
			if (customField) {
				customField.hidden = true;
			}

			requote({ tip_mode: 'percent', tip_basis_points: Number(button.dataset.tipPreset) }, button);
		});
	});

	if (customToggle && customField) {
		customToggle.addEventListener('click', () => {
			customField.hidden = false;
			lightTip(customToggle);

			if (customInput) {
				customInput.focus();
				customInput.select();
			}
		});
	}

	function applyCustomTip() {
		if (!customInput) {
			return;
		}

		requote({ tip_mode: 'custom', tip_dollars: customInput.value }, customToggle);
	}

	if (customApply) {
		customApply.addEventListener('click', applyCustomTip);
	}

	if (customInput) {
		customInput.addEventListener('change', applyCustomTip);
		customInput.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyCustomTip();
			}
		});
	}

	/* --- Paying ----------------------------------------------------------- */

	if (payButton) {
		payButton.addEventListener('click', async () => {
			if (!elements) {
				return;
			}

			payButton.disabled = true;
			showError('');

			const { error } = await stripe.confirmPayment({
				elements: elements,
				confirmParams: { return_url: returnUrl },
			});

			/* confirmPayment only returns when it failed — a success has already
			   navigated to return_url by the time this line could run. */
			showError(error && error.message
				? error.message
				: 'That payment did not go through. Try another card.');

			payButton.disabled = false;
		});
	}
})();
