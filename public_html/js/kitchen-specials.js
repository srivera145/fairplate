/* Live preview of the special being typed.
 *
 * The wording has to match SpecialsController::describe(), which is what the
 * customer menu will render. Two copies of a sentence is a poor arrangement and
 * this is the cheaper half of it: the preview on a saved special is rendered by
 * the PHP, so if these ever disagree the page shows both and the difference is
 * visible rather than theoretical.
 *
 * Without this script the preview shows its starting text and the special still
 * saves; the panel is a convenience, not part of the form.
 */
(() => {
	const form = document.querySelector('[data-special-form]');

	if (!form) {
		return;
	}

	const find = (name) => form.querySelector('[data-special-' + name + ']');

	const type = find('type');
	const value = find('value');
	const item = find('item');
	const title = find('title');
	const description = find('description');
	const unit = find('unit');

	const previewTitle = form.querySelector('[data-preview-title]');
	const previewLine = form.querySelector('[data-preview-line]');
	const previewDescription = form.querySelector('[data-preview-description]');

	/* Cents in, dollars out, two decimals, no float on the way. */
	const money = (typed) => {
		const match = String(typed).trim().replace(/[$,\s]/g, '').match(/^(\d*)(?:\.(\d{0,2}))?$/);

		if (!match || (!match[1] && !match[2])) {
			return '0.00';
		}

		return (match[1] || '0') + '.' + (match[2] || '').padEnd(2, '0');
	};

	const percent = (typed) => {
		const match = String(typed).trim().replace(/[%\s]/g, '').match(/^(\d{1,2})(?:\.(\d{0,2}))?$/);

		if (!match) {
			return '0';
		}

		const fraction = (match[2] || '').replace(/0+$/, '');

		return match[1] + (fraction ? '.' + fraction : '');
	};

	const update = () => {
		const scope = item && item.value !== '0'
			? item.options[item.selectedIndex].text
			: 'your order';

		let line;

		if (type.value === 'percent') {
			line = percent(value.value) + '% off ' + scope;
		} else if (type.value === 'amount') {
			line = '$' + money(value.value) + ' off ' + scope;
		} else {
			line = scope + ' for $' + money(value.value);
		}

		if (unit) {
			unit.textContent = type.value === 'percent' ? '%' : '$';
		}

		previewTitle.textContent = title.value.trim() || 'Your special';
		previewLine.textContent = line;
		previewDescription.textContent = description.value.trim();
	};

	['input', 'change'].forEach((event) => form.addEventListener(event, update));
	update();
})();
