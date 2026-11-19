<div class="tariff-event">
	<div>
		<label for="tariff-event-{{ $index }}-name">Name</label>
		<input type="text" id="tariff-event-{{ $index }}-name" name="tariff[extra][{{ $index }}][name]" value="{{ $event['name'] ?? '' }}" maxlength="100" placeholder="Extra cheap afternoon" required>
	</div>
	<div>
		<label for="tariff-event-{{ $index }}-type">Type</label>
		<select id="tariff-event-{{ $index }}-type" name="tariff[extra][{{ $index }}][type]">
			<option value="cheap" @selected(($event['type'] ?? 'cheap') === 'cheap')>Cheap offer</option>
			<option value="peak" @selected(($event['type'] ?? '') === 'peak')>Peak / saving session</option>
		</select>
	</div>
	<div>
		<label for="tariff-event-{{ $index }}-start">Start (London)</label>
		<input type="datetime-local" id="tariff-event-{{ $index }}-start" name="tariff[extra][{{ $index }}][start]" value="{{ $event['start'] ?? '' }}" required>
	</div>
	<div>
		<label for="tariff-event-{{ $index }}-end">Finish (London)</label>
		<input type="datetime-local" id="tariff-event-{{ $index }}-end" name="tariff[extra][{{ $index }}][end]" value="{{ $event['end'] ?? '' }}" required>
	</div>
	<button class="button neutral remove-tariff-event" type="button">Remove</button>
</div>
