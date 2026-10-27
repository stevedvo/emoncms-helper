(() =>
{
	const rows = document.getElementById('log-filter-rows');
	const template = document.getElementById('log-filter-template');
	const add = document.getElementById('add-log-filter');

	if (!rows || !template || !add)
	{
		return;
	}

	let nextIndex = rows.children.length;
	add.hidden = false;

	const update = () =>
	{
		add.disabled = rows.children.length >= 20;
		rows.querySelectorAll('.remove-filter').forEach(button => { button.hidden = false; });
	};

	add.addEventListener('click', () =>
	{
		if (rows.children.length >= 20)
		{
			return;
		}

		const row = template.content.cloneNode(true);

		row.querySelectorAll('[id], [name], [for]').forEach(element =>
		{
			['id', 'name', 'for'].forEach(attribute =>
			{
				if (element.hasAttribute(attribute))
				{
					element.setAttribute(attribute, element.getAttribute(attribute).replace('__INDEX__', nextIndex));
				}
			});
		});

		nextIndex++;
		rows.appendChild(row);
		update();
		rows.lastElementChild.querySelector('select').focus();
	});

	rows.addEventListener('click', event =>
	{
		const button = event.target.closest('.remove-filter');

		if (button)
		{
			button.closest('.log-filter-row').remove();
			update();
			add.focus();
		}
	});

	update();
})();
