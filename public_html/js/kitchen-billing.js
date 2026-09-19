/* The billing payment form.
 *
 * Mounts Stripe's Payment Element against the SetupIntent the server already
 * created and hands the confirmation straight back to Stripe. Nothing here
 * touches a card number, a routing number or a mandate — the element is an
 * iframe on Stripe's own origin, which is the entire reason this file is six
 * lines of wiring rather than a form.
 *
 * Nothing is charged. confirmSetup saves the method against the customer; the
 * first invoice against it is raised by the billing job weeks later.
 */
(() => {
	const mount = document.querySelector('[data-billing-method]');

	if (!mount || typeof window.Stripe !== 'function') {
		return;
	}

	const saveButton = document.querySelector('[data-billing-save]');
	const errorLine = document.getElementById('billing-error');
	const returnUrl = new URL(mount.dataset.returnUrl, window.location.origin).toString();

	const stripe = window.Stripe(mount.dataset.publishableKey);
	const elements = stripe.elements({
		clientSecret: mount.dataset.clientSecret,
		appearance: { theme: 'flat' },
	});

	elements.create('payment', { layout: 'tabs' }).mount(mount);

	function showError(message) {
		if (!errorLine) {
			return;
		}

		errorLine.textContent = message || '';
		errorLine.hidden = !message;
	}

	if (!saveButton) {
		return;
	}

	saveButton.addEventListener('click', async () => {
		saveButton.disabled = true;
		showError('');

		const { error } = await stripe.confirmSetup({
			elements: elements,
			confirmParams: { return_url: returnUrl },
		});

		/* Stripe only returns from confirmSetup when it did not redirect, and
		   it only fails to redirect when something went wrong. A successful
		   setup leaves this page for the return URL and never comes back. */
		if (error) {
			showError(error.message || 'We could not save that payment method. Check the details and try again.');
			saveButton.disabled = false;
		}
	});
})();
