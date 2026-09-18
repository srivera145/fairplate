/* The live orders board: poll, redraw, and make a noise about new orders.
 *
 * Everything this file touches is progressive. The board is already rendered by
 * the server when the page arrives, and Accept, Reject and Mark ready are real
 * forms inside a <details>, so a tablet that fails to run this script still
 * takes orders — it just needs someone to pull to refresh. What is lost without
 * the script is the automatic redraw and the chime, and nothing else.
 *
 * Two rules the board follows that are easy to miss:
 *
 *   - it will not redraw while a disclosure is open. Someone with the prep
 *     times showing is about to tap one, and swapping the markup under a
 *     half-committed tap is how an order gets a twenty-minute prep time nobody
 *     chose.
 *   - which orders are "new" is the server's answer, not a diff of what this
 *     page saw last. A tablet that slept through the lunch rush wakes up
 *     ringing for the orders it missed, rather than deciding they are old news.
 */
(() => {
	const board = document.getElementById('orders-board');

	if (!board) {
		return;
	}

	/* Five seconds, per the spec. Slow enough to be free, fast enough that a
	   customer's order is on the pass before they wonder. */
	const POLL_MS = 5000;

	/* The gap between repeats of the chime. Long enough not to become noise
	   somebody mutes, short enough not to be ignorable. */
	const CHIME_MS = 6000;

	const feedUrl = board.dataset.feed;
	const gate = document.getElementById('sound-gate');
	const enableButton = document.getElementById('sound-enable');
	const stopButton = document.getElementById('chime-stop');
	const stopWrap = document.getElementById('chime-stop-wrap');
	const offline = document.getElementById('board-offline');

	const chime = new Audio(board.dataset.chime || '/sounds/new-order.mp3');
	chime.preload = 'auto';

	let signature = board.dataset.signature || '';
	let soundReady = false;
	let chimeTimer = null;
	let ringing = false;

	/* Orders somebody has already been told about. Session storage, not local:
	   a tablet that is power-cycled overnight should start the next shift with
	   no assumptions, and an order still sitting in New the next morning
	   deserves to ring again. */
	const acknowledged = loadAcknowledged();

	function loadAcknowledged() {
		try {
			return new Set(JSON.parse(sessionStorage.getItem('kitchen-acked') || '[]'));
		} catch (error) {
			return new Set();
		}
	}

	function saveAcknowledged() {
		try {
			sessionStorage.setItem('kitchen-acked', JSON.stringify([...acknowledged]));
		} catch (error) {
			/* Private browsing, or storage full. The board works; the chime just
			   forgets what it was told between reloads. */
		}
	}

	/* --- Sound ----------------------------------------------------------- */

	/* Browsers refuse to play audio until the page has been touched, and they
	   refuse quietly. So the gate is a real button: the tap that dismisses it is
	   the gesture that unlocks playback, and it stays up until a play() has
	   actually resolved rather than merely been attempted. */
	function unlockSound() {
		chime.play().then(() => {
			chime.pause();
			chime.currentTime = 0;
			soundReady = true;

			if (gate) {
				gate.hidden = true;
			}

			if (ringing) {
				startChime();
			}
		}).catch(() => {
			if (gate) {
				gate.hidden = false;
			}
		});
	}

	function playOnce() {
		if (!soundReady) {
			return;
		}

		chime.currentTime = 0;
		chime.play().catch(() => {
			/* The tab lost its permission — put the gate back rather than
			   pretending the kitchen is being told about new orders. */
			soundReady = false;

			if (gate) {
				gate.hidden = false;
			}
		});
	}

	function startChime() {
		if (chimeTimer !== null) {
			return;
		}

		playOnce();
		chimeTimer = window.setInterval(playOnce, CHIME_MS);
	}

	function stopChime() {
		if (chimeTimer !== null) {
			window.clearInterval(chimeTimer);
			chimeTimer = null;
		}
	}

	function setRinging(unacknowledgedIds) {
		ringing = unacknowledgedIds.length > 0;

		if (stopWrap) {
			stopWrap.hidden = !ringing;
		}

		if (!ringing) {
			stopChime();
			return;
		}

		if (soundReady) {
			startChime();
		} else if (gate) {
			gate.hidden = false;
		}
	}

	/* --- Polling --------------------------------------------------------- */

	function detailsOpen() {
		return board.querySelector('details[open]') !== null;
	}

	function openDisclosureIds() {
		return [...board.querySelectorAll('details[open]')].map((element) => element.id);
	}

	function reopenDisclosures(ids) {
		ids.forEach((id) => {
			const element = id ? board.querySelector('#' + CSS.escape(id)) : null;

			if (element) {
				element.open = true;
			}
		});
	}

	async function poll() {
		let data;

		try {
			const response = await fetch(feedUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});

			if (!response.ok) {
				throw new Error('feed responded ' + response.status);
			}

			data = await response.json();
		} catch (error) {
			if (offline) {
				offline.hidden = false;
			}

			return;
		}

		if (offline) {
			offline.hidden = true;
		}

		const newIds = (data.new_order_ids || []).map(Number);
		setRinging(newIds.filter((id) => !acknowledged.has(id)));

		/* An order that left New is no longer something to remember having
		   announced, so the set never grows without bound. */
		[...acknowledged].forEach((id) => {
			if (!newIds.includes(id)) {
				acknowledged.delete(id);
			}
		});
		saveAcknowledged();

		if (data.signature === signature || typeof data.html !== 'string') {
			return;
		}

		if (detailsOpen()) {
			/* Someone is mid-decision. Try again in five seconds. */
			return;
		}

		const wasOpen = openDisclosureIds();
		board.innerHTML = data.html;
		reopenDisclosures(wasOpen);
		signature = data.signature;
	}

	/* --- Wiring ---------------------------------------------------------- */

	if (enableButton) {
		enableButton.addEventListener('click', unlockSound);
	}

	if (stopButton) {
		stopButton.addEventListener('click', () => {
			board.querySelectorAll('[data-new-order-id]').forEach((card) => {
				acknowledged.add(Number(card.dataset.newOrderId));
			});

			saveAcknowledged();
			setRinging([]);
		});
	}

	/* Any tap anywhere counts as the gesture a browser wants, so a kitchen that
	   started working before noticing the gate gets sound anyway. */
	document.addEventListener('pointerdown', function firstTouch() {
		document.removeEventListener('pointerdown', firstTouch);

		if (!soundReady) {
			unlockSound();
		}
	}, { once: true });

	window.setInterval(poll, POLL_MS);
	poll();
})();
