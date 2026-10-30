(() =>
{
	const form = document.getElementById('tariff-settings-form');

	if (!form)
	{
		return;
	}

	const events = document.getElementById('tariff-events');
	const template = document.getElementById('tariff-event-template');
	const status = document.getElementById('tariff-change-status');
	let nextIndex = Math.max(-1, ...Array.from(events.querySelectorAll('[name]'), input => Number(input.name.match(/\[extra\]\[(\d+)\]/)?.[1] ?? -1))) + 1;
	let dirty = false;
	const changed = () =>
	{
		dirty = true;
		status.textContent = 'You have unsaved tariff changes.';
	};

	document.getElementById('add-tariff-event').addEventListener('click', () =>
	{
		const row = template.content.cloneNode(true);

		for (const element of row.querySelectorAll('[id], [name], [for]'))
		{
			for (const attribute of ['id', 'name', 'for'])
			{
				if (element.hasAttribute(attribute))
				{
					element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', nextIndex));
				}
			}
		}

		nextIndex++;
		events.append(row);
		events.lastElementChild.querySelector('input').focus();
		changed();
	});

	events.addEventListener('click', event =>
	{
		if (event.target.closest('.remove-tariff-event'))
		{
			event.target.closest('.tariff-event').remove();
			changed();
		}
	});

	form.addEventListener('input', changed);
	form.addEventListener('submit', () => { dirty = false; });
	window.addEventListener('beforeunload', event =>
	{
		if (dirty)
		{
			event.preventDefault();
			event.returnValue = '';
		}
	});
})();
