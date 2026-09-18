/* Drag to reorder the menu.
 *
 * Deck ships a sortable adapter, but its no-library path is the HTML5 drag and
 * drop API, which does not fire on touch — and the whole point of this app is a
 * tablet. So this is pointer events, which are the same code for a finger, a
 * stylus and a mouse.
 *
 * This is an enhancement and nothing depends on it. Every list it touches also
 * has up and down buttons that post a form, so a kitchen can reorder its menu
 * with the script blocked, with a keyboard, or with a screen reader. If you are
 * changing this file, that is the property to keep.
 *
 * Markup contract:
 *   <ul data-sortable="items"> a reorderable list; the value is the collection
 *   <li data-id="12">          one row, carrying its record id
 *   [data-drag]                the handle; dragging starts nowhere else
 *
 * Lists nest — items live inside categories — so every lookup starts from the
 * handle and walks out to its nearest list, never down from the page.
 */
(() => {
	const lists = document.querySelectorAll('[data-sortable]');

	if (lists.length === 0) {
		return;
	}

	const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

	/* How far a finger has to travel before this is a drag and not a tap. Below
	   it, the handle behaves like the button it looks like. */
	const DRAG_THRESHOLD_PX = 6;

	let dragging = null;
	let list = null;
	let started = false;

	function rowFrom(target) {
		return target.closest('[data-id]');
	}

	function listFor(row) {
		return row.parentElement.closest('[data-sortable]');
	}

	function siblings() {
		return [...list.children].filter((child) => child !== dragging && child.dataset.id);
	}

	/* The row the pointer is currently over, and which half of it. */
	function moveTo(clientY) {
		for (const sibling of siblings()) {
			const box = sibling.getBoundingClientRect();

			if (clientY < box.top || clientY > box.bottom) {
				continue;
			}

			const below = clientY - box.top > box.height / 2;
			list.insertBefore(dragging, below ? sibling.nextSibling : sibling);
			return;
		}
	}

	function onPointerDown(event) {
		const handle = event.target.closest('[data-drag]');

		if (!handle || event.button > 0) {
			return;
		}

		const row = rowFrom(handle);

		if (!row) {
			return;
		}

		dragging = row;
		list = listFor(row);
		started = false;

		const startY = event.clientY;

		const onMove = (moveEvent) => {
			if (!started && Math.abs(moveEvent.clientY - startY) < DRAG_THRESHOLD_PX) {
				return;
			}

			if (!started) {
				started = true;
				dragging.classList.add('is-dragging');
				list.classList.add('is-drop-target');
				/* Stop the page scrolling under the finger once this is a drag
				   rather than a tap. */
				handle.setPointerCapture(moveEvent.pointerId);
			}

			moveEvent.preventDefault();
			moveTo(moveEvent.clientY);
		};

		const onUp = () => {
			document.removeEventListener('pointermove', onMove);
			document.removeEventListener('pointerup', onUp);
			document.removeEventListener('pointercancel', onUp);

			if (started) {
				dragging.classList.remove('is-dragging');
				list.classList.remove('is-drop-target');
				save(list);
			}

			dragging = null;
			list = null;
			started = false;
		};

		document.addEventListener('pointermove', onMove, { passive: false });
		document.addEventListener('pointerup', onUp);
		document.addEventListener('pointercancel', onUp);
	}

	async function save(sorted) {
		const order = [...sorted.children]
			.map((child) => child.dataset.id)
			.filter(Boolean);

		try {
			const response = await fetch('/kitchen/menu/sort', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-CSRF-Token': csrfToken,
				},
				body: JSON.stringify({ type: sorted.dataset.sortable, order }),
			});

			if (!response.ok) {
				throw new Error('sort responded ' + response.status);
			}
		} catch (error) {
			/* The server is the record, so a reload is the honest way to show
			   what the order actually is rather than leaving the screen showing
			   an order that was never saved. */
			window.location.reload();
		}
	}

	document.addEventListener('pointerdown', onPointerDown);
})();
