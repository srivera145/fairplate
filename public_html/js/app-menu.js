/* The menu: a bottom sheet over the item page, and the option rules.
 *
 * Everything here is an enhancement over markup that already works. Each menu
 * row is a real link to the item's own page, and the form on that page is a
 * real post. What this file adds is that the tap opens a sheet instead of
 * leaving the menu, and that the Add button is off until the required choices
 * have been made — a courtesy, because CartService::validateSelection() re-checks
 * every one of those rules on the server and that is the copy that decides.
 *
 * Two things worth knowing:
 *
 *   - the sheet's contents come from the item's own URL, fetched. There is no
 *     second copy of the item markup in this file, so the sheet and the page can
 *     never offer different options.
 *   - the category tabs highlight from an IntersectionObserver, not a scroll
 *     handler. The anchors already work; this only keeps the right tab lit.
 */
(() => {
	const sheet = document.getElementById('item-sheet');
	const body = sheet ? sheet.querySelector('[data-sheet-body]') : null;
	const title = document.getElementById('item-sheet-title');

	/* --- The item sheet --------------------------------------------------- */

	function wireForm(scope) {
		const form = scope.querySelector('[data-item-form]');

		if (!form) {
			return;
		}

		const submit = form.querySelector('[data-item-submit]');
		const groups = [...form.querySelectorAll('[data-option-group]')];

		/* The same arithmetic the server does, so the button tells the truth
		   before the post rather than after it. */
		const satisfied = () => groups.every((group) => {
			const chosen = group.querySelectorAll('input:checked').length;
			const min = Number(group.dataset.min || 0);
			const max = Number(group.dataset.max || 0);

			return chosen >= min && (max <= 0 || chosen <= max);
		});

		/* A checkbox group with a maximum stops accepting new ticks once it is
		   full, rather than letting somebody pick six and then be told no. */
		const enforceMax = (group) => {
			const max = Number(group.dataset.max || 0);

			if (max <= 0) {
				return;
			}

			const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
			const full = boxes.filter((box) => box.checked).length >= max;

			boxes.forEach((box) => {
				box.disabled = full && !box.checked;
			});
		};

		const paint = () => {
			groups.forEach(enforceMax);

			if (submit) {
				submit.disabled = !satisfied();
			}
		};

		form.addEventListener('change', paint);
		paint();
	}

	function openSheet(url, name) {
		if (!sheet || !body) {
			return false;
		}

		if (title && name) {
			title.textContent = name;
		}

		body.innerHTML = '<p class="text-muted">Loading…</p>';
		sheet.showModal();

		fetch(url + (url.includes('?') ? '&' : '?') + 'sheet=1', {
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
		}).then((response) => {
			if (!response.ok) {
				throw new Error('item responded ' + response.status);
			}

			return response.json();
		}).then((data) => {
			body.innerHTML = data.html;

			if (title && data.name) {
				title.textContent = data.name;
			}

			/* Deck wires its own components on load; freshly injected markup
			   needs the same pass or the quantity buttons do nothing. */
			if (window.Deck && typeof window.Deck.init === 'function') {
				window.Deck.init(body);
			}

			wireForm(body);
		}).catch(() => {
			/* The page under the link still works, so send them to it rather
			   than leaving a sheet that says "loading" forever. */
			sheet.close();
			window.location.href = url;
		});

		return true;
	}

	if (sheet) {
		document.addEventListener('click', (event) => {
			const link = event.target.closest('[data-item-link]');

			if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
				return;
			}

			const name = link.querySelector('.fw-semi');

			if (openSheet(link.getAttribute('href'), name ? name.textContent.trim() : '')) {
				event.preventDefault();
			}
		});

		sheet.addEventListener('click', (event) => {
			if (event.target.closest('[data-sheet-close]')) {
				sheet.close();
				return;
			}

			/* A tap on the backdrop is a tap on the dialog itself, never on
			   anything inside it. */
			if (event.target === sheet) {
				sheet.close();
			}
		});
	}

	/* The item page uses the same form, without any sheet around it. */
	wireForm(document);

	/* --- Category tabs ---------------------------------------------------- */

	const tabs = [...document.querySelectorAll('[data-menu-tab]')];
	const sections = tabs
		.map((tab) => document.getElementById(tab.dataset.menuTab))
		.filter(Boolean);

	if (tabs.length > 0 && sections.length > 0 && 'IntersectionObserver' in window) {
		const light = (anchor) => {
			tabs.forEach((tab) => {
				const on = tab.dataset.menuTab === anchor;
				tab.classList.toggle('btn-soft', on);
				tab.classList.toggle('btn-ghost', !on);
			});
		};

		const observer = new IntersectionObserver((entries) => {
			const visible = entries
				.filter((entry) => entry.isIntersecting)
				.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];

			if (visible) {
				light(visible.target.id);
			}
		}, { rootMargin: '-30% 0px -60% 0px' });

		sections.forEach((section) => observer.observe(section));
	}
})();
