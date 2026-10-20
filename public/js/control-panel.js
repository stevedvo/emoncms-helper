(() =>
{
	const settingsForm = document.getElementById('settings-form');

	if (settingsForm)
	{
		let dirty = false;

		settingsForm.addEventListener('input', () =>
		{
			dirty = true;
			document.getElementById('change-status').textContent = 'You have unsaved changes.';
		});

		settingsForm.addEventListener('submit', () => { dirty = false; });

		window.addEventListener('beforeunload', event =>
		{
			if (dirty)
			{
				event.preventDefault(); event.returnValue = '';
			}
		});
	}

	const panel = document.getElementById('emon-panel');

	if (!panel)
	{
		return;
	}

	const status = document.getElementById('emon-status');
	const message = document.getElementById('emon-message');
	const rows = document.getElementById('emon-rows');
	const button = document.getElementById('refresh-feeds');
	let busy = false;
	let hasFeedData = false;
	const time = timestamp => timestamp ? new Date(timestamp * 1000).toLocaleString('en-GB', { timeZone: 'Europe/London', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : 'No sample time';
	const age = seconds => seconds == null ? 'No sample time' : seconds < 0 ? 'Future-dated sample' : seconds < 60 ? 'Less than a minute ago' : `${Math.floor(seconds / 60)} min ago`;
	const number = value => value == null ? '—' : new Intl.NumberFormat('en-GB', { maximumFractionDigits: 2 }).format(value);

	const cell = (value, className = '') =>
	{
		const element = document.createElement('td');
		element.textContent = value;
		element.className = className;

		return element;
	};

	async function refresh()
	{
		if (busy || document.hidden)
		{
			return;
		}

		busy = true;
		button.disabled = true;
		status.textContent = 'Refreshing…';

		try
		{
			const response = await fetch(panel.dataset.feedsUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });

			if (response.status === 401)
			{
				window.location.reload();

				return;
			}

			const data = await response.json();

			if (!response.ok)
			{
				throw new Error(data.message || 'Local Emon is unavailable. The dashboard will retry.');
			}

			rows.replaceChildren();

			for (const room of ['rad', 'ufh'])
			{
				const value = document.getElementById(`${room}-value`);
				value.textContent = '—';
				const unit = document.createElement('small'); unit.textContent = '°C'; value.append(unit);
				document.getElementById(`${room}-age`).textContent = 'No feed returned';
			}

			for (const feed of data.feeds)
			{
				const row = document.createElement('tr');
				const freshness = cell('', 'align-right');
				const badge = document.createElement('span');
				badge.className = `pill ${feed.stale ? 'amber' : 'green'}`;
				badge.textContent = feed.stale ? 'Check sample age' : 'Recent sample';
				freshness.append(badge);
				row.append(cell(feed.name, 'reading-name'), cell(`${number(feed.value)} ${feed.unit}`, 'numeric'), cell(time(feed.timestamp), 'muted'), freshness);
				rows.append(row);
				const room = feed.room === 'rad' || feed.room === 'ufh' ? feed.room : null;

				if (room)
				{
					const value = document.getElementById(`${room}-value`);
					value.textContent = number(feed.value);
					const unit = document.createElement('small'); unit.textContent = '°C'; value.append(unit);
					document.getElementById(`${room}-age`).textContent = `${age(feed.ageSeconds)}${feed.stale ? ' · Check sensor' : ''}`;
				}
			}

			if (!data.feeds.length)
			{
				const row = document.createElement('tr'); const empty = cell('No local Emon feeds were returned.', 'empty-state'); empty.colSpan = 4; row.append(empty); rows.append(row);
			}

			message.hidden = true;
			hasFeedData = true;
			status.textContent = `Checked ${new Date(data.checkedAt).toLocaleTimeString('en-GB', { timeZone: 'Europe/London', hour: '2-digit', minute: '2-digit' })} UK · refreshes every minute`;
		}
		catch (error)
		{
			message.textContent = error.message;
			message.hidden = false;
			status.textContent = 'Emon connection unavailable';

			if (!hasFeedData)
			{
				rows.replaceChildren();
				const row = document.createElement('tr'); const empty = cell('Local feed values could not be loaded. Use Refresh to try again.', 'empty-state'); empty.colSpan = 4; row.append(empty); rows.append(row);
			}
			else
			{
				for (const badge of rows.querySelectorAll('.pill'))
				{
					badge.className = 'pill amber'; badge.textContent = 'Last displayed value';
				}
			}

			for (const room of ['rad', 'ufh'])
			{
				document.getElementById(`${room}-age`).textContent = 'Connection unavailable · last displayed value';
			}
		}
		finally
		{
			busy = false;
			button.disabled = false;
		}
	}

	button.addEventListener('click', refresh);
	document.addEventListener('visibilitychange', () =>
	{
		if (!document.hidden)
		{
			refresh();
		}
	});

	refresh();
	window.setInterval(refresh, 60000);
})();
