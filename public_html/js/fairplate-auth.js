/* Phone OTP sign-in.
 *
 * Two steps on one card: ask for the number, then ask for the code. The server
 * decides everything; this only moves between the steps and reports what came
 * back. Where the user lands after signing in is the server's answer too, since
 * that depends on their role.
 */
(() => {
	const form = document.getElementById('phone-auth');

	if (!form) {
		return;
	}

	const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
	const phoneStep = document.getElementById('phone-step');
	const codeStep = document.getElementById('code-step');
	const phoneInput = document.getElementById('phone');
	const codeInput = document.getElementById('code');
	const sendButton = document.getElementById('phone-send');
	const verifyButton = document.getElementById('phone-verify');
	const errorBox = document.getElementById('auth-error');

	const showError = (message) => {
		errorBox.textContent = message;
		errorBox.hidden = false;
	};

	const clearError = () => {
		errorBox.hidden = true;
	};

	const post = async (url, body) => {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'Accept': 'application/json',
				'X-CSRF-Token': csrfToken,
			},
			body: JSON.stringify(body),
		});

		return response.json();
	};

	const busy = (button, isBusy) => {
		button.disabled = isBusy;
		button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
	};

	sendButton.addEventListener('click', async () => {
		clearError();
		busy(sendButton, true);

		try {
			const data = await post('/auth/phone/request', { phone: phoneInput.value });

			if (!data.success) {
				showError(data.message || 'Something went wrong.');
				return;
			}

			phoneStep.hidden = true;
			codeStep.hidden = false;
			codeInput.focus();
		} catch (error) {
			showError('Could not reach FairPlate. Check your connection.');
		} finally {
			busy(sendButton, false);
		}
	});

	verifyButton.addEventListener('click', async () => {
		clearError();
		busy(verifyButton, true);

		try {
			const data = await post('/auth/phone/verify', {
				phone: phoneInput.value,
				code: codeInput.value,
			});

			if (!data.success) {
				showError(data.message || 'Invalid code.');
				return;
			}

			window.location.href = data.redirect || '/app';
		} catch (error) {
			showError('Could not reach FairPlate. Check your connection.');
		} finally {
			busy(verifyButton, false);
		}
	});
})();
