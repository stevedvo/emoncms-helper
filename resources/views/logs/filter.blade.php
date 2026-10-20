<div class="log-filter-row">
	<div><label for="filter-{{ $index }}-field">Field</label><select id="filter-{{ $index }}-field" name="filters[{{ $index }}][field]">@foreach($fields as $key => $label)<option value="{{ $key }}" @selected(($filter['field'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
	<div><label for="filter-{{ $index }}-operator">Match</label><select id="filter-{{ $index }}-operator" name="filters[{{ $index }}][operator]">@foreach($operators as $key => $label)<option value="{{ $key }}" @selected(($filter['operator'] ?? 'contains') === $key)>{{ $label }}</option>@endforeach</select></div>
	<div class="log-filter-value"><label for="filter-{{ $index }}-value">Text</label><input id="filter-{{ $index }}-value" name="filters[{{ $index }}][value]" value="{{ is_string($filter['value'] ?? null) ? $filter['value'] : '' }}" maxlength="1000" placeholder="Any value"></div>
	<button class="text-button remove-filter" type="button" aria-label="Remove this filter" hidden>Remove</button>
</div>
