/* Order tracking: the stepper, and the driver's position.
 *
 * Two polls, both of them the server's answer rather than this page's guess.
 *
 * The stepper poll asks for the same markup the page was rendered with and swaps
 * it in when the signature changed, so there is one description of a step and it
 * lives in a view file. That is the same arrangement the kitchen board uses.
 *
 * The location poll asks the server where the driver is. It does not decide
 * whether it is allowed to know: the endpoint refuses an order that is not this
 * customer's and answers "nothing yet" before pickup and after delivery,
 * whatever this file asks for. When it says the order is no longer trackable
 * this stops asking, which is politeness rather than protection.
 *
 * The map is Google's, loaded only if a key is configured. Without one the panel
 * falls back to a line of text saying when the driver was last seen, which is
 * the useful part of a map anyway.
 */
(() => {
	const tracking = document.getElementById('tracking');

	if (!tracking) {
		return;
	}

	const pollMs = Math.max(5, Number(tracking.dataset.pollSeconds || 10)) * 1000;
	const statusUrl = tracking.dataset.statusUrl;
	const locationUrl = tracking.dataset.locationUrl;

	let signature = '';
	let trackable = tracking.dataset.trackable === '1';

	/* --- The stepper ------------------------------------------------------ */

	async function pollStatus() {
		try {
			const response = await fetch(statusUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});

			if (!response.ok) {
				return;
			}

			const data = await response.json();

			if (data.signature !== signature && typeof data.html === 'string') {
				tracking.innerHTML = data.html;
				signature = data.signature;
			}

			/* Becoming trackable, or stopping being trackable, changes what the
			   page should be showing. The server has already re-rendered the
			   panel above; the map is a whole section, so reload rather than
			   build one here. */
			if (data.trackable !== trackable) {
				window.location.reload();
			}
		} catch (error) {
			/* A dropped connection is not worth a message on a page whose whole
			   job is to wait. The next poll will say the same thing. */
		}
	}

	/* --- The driver ------------------------------------------------------- */

	const mapBox = document.getElementById('driver-map');
	const driverStatus = document.querySelector('[data-driver-status]');
	let map = null;
	let marker = null;
	let mapsReady = null;

	function loadMaps(key) {
		if (mapsReady) {
			return mapsReady;
		}

		mapsReady = new Promise((resolve, reject) => {
			if (window.google && window.google.maps) {
				resolve(window.google.maps);
				return;
			}

			const script = document.createElement('script');
			script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key);
			script.async = true;
			script.onload = () => resolve(window.google.maps);
			script.onerror = () => reject(new Error('maps failed to load'));
			document.head.appendChild(script);
		});

		return mapsReady;
	}

	async function place(lat, lng) {
		if (!mapBox) {
			return;
		}

		const key = mapBox.dataset.mapsKey;

		if (!key) {
			return;
		}

		let maps;

		try {
			maps = await loadMaps(key);
		} catch (error) {
			mapBox.hidden = true;
			return;
		}

		const position = { lat: lat, lng: lng };

		if (!map) {
			map = new maps.Map(mapBox, {
				center: position,
				zoom: 14,
				disableDefaultUI: true,
				zoomControl: true,
			});

			const dropLat = Number(mapBox.dataset.dropLat);
			const dropLng = Number(mapBox.dataset.dropLng);

			if (Number.isFinite(dropLat) && Number.isFinite(dropLng) && dropLat !== 0) {
				new maps.Marker({
					position: { lat: dropLat, lng: dropLng },
					map: map,
					title: 'Your address',
				});
			}
		}

		if (!marker) {
			marker = new maps.Marker({ position: position, map: map, title: 'Your driver' });
		} else {
			marker.setPosition(position);
		}

		map.panTo(position);
	}

	function seenAt(recordedAt) {
		if (!recordedAt) {
			return '';
		}

		const stamp = new Date(recordedAt.replace(' ', 'T') + 'Z');

		return Number.isNaN(stamp.getTime())
			? ''
			: stamp.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
	}

	async function pollLocation() {
		if (!trackable) {
			return;
		}

		try {
			const response = await fetch(locationUrl, {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
			});

			if (!response.ok) {
				/* A 403 means this is not our order to watch. Nothing to retry. */
				trackable = false;
				return;
			}

			const data = await response.json();

			if (!data.available) {
				if (driverStatus) {
					driverStatus.textContent = 'Waiting for your driver’s first position…';
				}

				return;
			}

			await place(data.lat, data.lng);

			if (driverStatus) {
				const at = seenAt(data.recorded_at);
				driverStatus.textContent = at
					? 'Your driver was here at ' + at + '.'
					: 'Your driver is on the way.';
			}
		} catch (error) {
			/* Same as the stepper: the next poll says it again. */
		}
	}

	/* --- Wiring ----------------------------------------------------------- */

	window.setInterval(pollStatus, pollMs);
	pollStatus();

	if (trackable) {
		window.setInterval(pollLocation, pollMs);
		pollLocation();
	}
})();
