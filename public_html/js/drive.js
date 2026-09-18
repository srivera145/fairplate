/* The driver app: position, offers, countdown, wait pay.
 *
 * Four small jobs, and all four are progressive. The page works without this
 * file: going online is a form, Accept and Decline are forms, every delivery
 * step is a form, and the server enforces every deadline this script draws.
 * What is lost without it is the offer arriving on its own, the ring counting
 * down, and the wait-pay figure ticking up — decoration over facts the server
 * already owns.
 *
 * Three rules this file follows that are easy to get wrong:
 *
 *   Position is reported only while online. The switch on the page is the
 *   consent, and the server independently refuses a ping from a driver who is
 *   offline, so a tab left open on a phone in a drawer stops reporting whether
 *   or not this script noticed.
 *
 *   The countdown is drawn from the server's seconds, not counted by this page.
 *   Every poll brings a fresh figure and the ring is re-seeded from it. A phone
 *   whose clock is four minutes out, or which was asleep for thirty seconds,
 *   still shows the time that is actually left.
 *
 *   The card is never redrawn while one of its buttons is being pressed. A
 *   driver mid-tap on Accept must not have the markup swapped underneath them,
 *   which is the same rule the kitchen board follows for its disclosures.
 */
(() => {
	const config = document.getElementById('drive-config');

	if (!config) {
		return;
	}

	const slot = document.querySelector('[data-offer-slot]');
	const locationNote = document.querySelector('[data-location-note]');

	const offerUrl = config.dataset.offerUrl || '';
	const locationUrl = config.dataset.locationUrl || '';
	const offerPollMs = Math.max(1, Number(config.dataset.offerPollSeconds || 3)) * 1000;

	let pingMs = Math.max(5, Number(config.dataset.pingSeconds || 15)) * 1000;
	let online = config.dataset.online === '1';

	const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

	/* --- Position --------------------------------------------------------- */

	/* One position, then a request. Not watchPosition: a watch fires whenever
	   the phone feels like it, which on a moving car is several times a second,
	   and the server is being told every ten or fifteen seconds either way. A
	   timer that asks for a fresh fix each time costs less battery than a stream
	   this file would spend most of its time throwing away. */
	function readPosition() {
		return new Promise((resolve, reject) => {
			if (!navigator.geolocation) {
				reject(new Error('no geolocation'));
				return;
			}

			navigator.geolocation.getCurrentPosition(resolve, reject, {
				enableHighAccuracy: true,
				/* Longer than the gap between pings: a fix that takes twenty
				   seconds is still worth sending, and a phone in a city centre
				   sometimes takes that long. */
				timeout: 20000,
				maximumAge: 5000,
			});
		});
	}

	function noteLocation(message) {
		if (!locationNote) {
			return;
		}

		locationNote.textContent = message;
		locationNote.hidden = message === '';
	}

	async function sendPosition() {
		if (!online || !locationUrl) {
			return;
		}

		let position;

		try {
			position = await readPosition();
		} catch (error) {
			/* Permission refused, or no fix. Say so once — a driver who has
			   blocked location will otherwise wonder why no offers arrive, and
			   dispatch genuinely cannot rank them without a position. */
			noteLocation(
				error && error.code === 1
					? 'Location is off for this site. FairPlate cannot send you offers without it.'
					: 'Waiting for a location fix…'
			);
			return;
		}

		noteLocation('');

		try {
			const response = await fetch(locationUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-Token': csrfToken,
				},
				credentials: 'same-origin',
				body: JSON.stringify({
					lat: position.coords.latitude,
					lng: position.coords.longitude,
				}),
			});

			const data = await response.json();

			/* The server decides whether this driver is still online, and how
			   often to ask next. Going from waiting to carrying speeds the pings
			   up without a reload. */
			if (data.online === false) {
				online = false;
				return;
			}

			if (data.next_ping_seconds) {
				const next = Math.max(5, Number(data.next_ping_seconds)) * 1000;

				if (next !== pingMs) {
					pingMs = next;
					restartPinging();
				}
			}
		} catch (error) {
			/* A dropped connection between two traffic lights. The next ping
			   says the same thing. */
		}
	}

	let pingTimer = null;

	function restartPinging() {
		if (pingTimer !== null) {
			window.clearInterval(pingTimer);
		}

		pingTimer = window.setInterval(sendPosition, pingMs);
	}

	/* --- The countdown ring ----------------------------------------------- */

	/* Seeded from the server on every poll and ticked locally in between, so the
	   ring is smooth without the page ever being the authority on how much time
	   is left. */
	let deadline = null;
	let countdownWindow = 1;

	function seedCountdown(secondsLeft, windowSeconds) {
		deadline = Date.now() + Math.max(0, Number(secondsLeft || 0)) * 1000;
		countdownWindow = Math.max(1, Number(windowSeconds || 1));
		drawCountdown();
	}

	function drawCountdown() {
		const ring = document.querySelector('[data-countdown-ring]');
		const label = document.querySelector('[data-countdown-label]');

		if (!ring || deadline === null) {
			return;
		}

		const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
		const fraction = Math.max(0, Math.min(1, left / countdownWindow));

		ring.style.setProperty('--value', String(Math.round(fraction * 100)));

		if (label) {
			label.textContent = String(left);
		}

		/* Under ten seconds the ring turns. A driver who has been looking at the
		   road needs the card itself to say it is nearly gone. */
		ring.classList.toggle('is-urgent', left <= 10);
	}

	function clearCountdown() {
		deadline = null;
	}

	/* --- The offer poll ---------------------------------------------------- */

	let shownOfferId = null;
	let submitting = false;

	/* A tap on Accept or Decline navigates, but the request takes a moment on a
	   cellular connection, and a poll landing in that moment must not replace
	   the form that is mid-submit. */
	document.addEventListener('submit', (event) => {
		if (event.target && event.target.closest('[data-offer]')) {
			submitting = true;
		}
	}, true);

	function showOffer(html, offerId, secondsLeft, windowSeconds) {
		if (!slot) {
			return;
		}

		if (offerId !== shownOfferId) {
			slot.innerHTML = html;
			slot.hidden = false;
			shownOfferId = offerId;

			/* An offer taking over the screen while the page is scrolled halfway
			   down a list is an offer somebody misses. */
			window.scrollTo({ top: 0, behavior: 'auto' });
		}

		seedCountdown(secondsLeft, windowSeconds);
	}

	function hideOffer() {
		if (!slot || shownOfferId === null) {
			return;
		}

		slot.innerHTML = '';
		slot.hidden = true;
		shownOfferId = null;
		clearCountdown();
	}

	async function pollOffers() {
		if (!offerUrl || submitting) {
			return;
		}

		let data;

		try {
			const response = await fetch(offerUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});

			if (!response.ok) {
				return;
			}

			data = await response.json();
		} catch (error) {
			return;
		}

		/* The server says where this driver should be. An offer accepted in
		   another tab, or a delivery already under way, is the page's cue to
		   stop showing a switch while food waits. */
		if (data.redirect) {
			window.location.href = data.redirect;
			return;
		}

		if (!data.offer) {
			hideOffer();
			return;
		}

		showOffer(data.html, Number(data.offer_id), data.seconds_left, data.window_seconds);
	}

	/* --- Wait pay ---------------------------------------------------------- */

	/* Hidden until the free minutes are used up, then counting. Showing a
	   stationary zero for ten minutes would read as a promise of nothing; this
	   appears at the moment the waiting starts being paid for. */
	function drawWaitPay() {
		const element = document.querySelector('[data-wait]');

		if (!element) {
			return;
		}

		const arrivedAt = Date.parse(String(element.dataset.arrivedAt || '').replace(' ', 'T') + 'Z');

		if (Number.isNaN(arrivedAt)) {
			return;
		}

		const freeMinutes = Number(element.dataset.freeMinutes || 0);
		const perMinCents = Number(element.dataset.perMinCents || 0);
		const capCents = Number(element.dataset.capCents || 0);

		const waited = Math.floor((Date.now() - arrivedAt) / 60000);
		const billable = Math.max(0, waited - freeMinutes);

		if (billable <= 0) {
			element.hidden = true;
			return;
		}

		const cents = Math.min(billable * perMinCents, capCents);
		/* Integer cents all the way to the screen, the way every other money
		   figure in FairPlate is built. */
		const money = '$' + Math.floor(cents / 100) + '.' + String(cents % 100).padStart(2, '0');
		const capped = cents >= capCents;

		element.hidden = false;
		element.textContent = capped
			? 'Wait pay ' + money + ' — that is the cap for this order.'
			: 'Wait pay ' + money + ', ' + billable + ' min past the free ' + freeMinutes + '.';
	}

	/* --- Wiring ------------------------------------------------------------ */

	if (online && locationUrl) {
		sendPosition();
		restartPinging();
	}

	if (offerUrl) {
		pollOffers();
		window.setInterval(pollOffers, offerPollMs);
	}

	/* The ring and the wait counter are the two things that have to look alive,
	   so they share one second-by-second tick rather than two. */
	window.setInterval(() => {
		drawCountdown();
		drawWaitPay();
	}, 1000);

	drawCountdown();
	drawWaitPay();

	/* Coming back from a locked screen: ask for a position and an offer straight
	   away rather than waiting out whatever was left of the interval. */
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState !== 'visible') {
			return;
		}

		if (online) {
			sendPosition();
		}

		pollOffers();
	});

	/* A card rendered by the server already carries its own seconds; seed from
	   them so the ring is right before the first poll returns. */
	const initial = document.querySelector('[data-offer]');

	if (initial) {
		shownOfferId = Number(initial.dataset.offerId);
		seedCountdown(initial.dataset.secondsLeft, initial.dataset.windowSeconds);
	}
})();
