@extends('layouts.control-panel')
@section('title', $type === 'activity' ? 'Activity Logs' : 'API Logs')
@section('content')
	@php
		$formFilters = old('filters', $criteria['filters'] ?: array_map(fn ($field) => ['field' => $field, 'operator' => 'contains', 'value' => ''], array_keys($fields)));
		$formFilters = is_array($formFilters) ? array_slice($formFilters, 0, 20) : [];
		$formFrom = old('from', $criteria['from']);
		$formTo = old('to', $criteria['to']);
		$formName = old('name', $selected?->name);
	@endphp
	<div class="page-heading">
		<div>
			<div class="eyebrow">WHAT’S BEEN HAPPENING</div>
			<h1>{{ $type === 'activity' ? 'Activity Logs' : 'API Logs' }}</h1>
			<p class="intro">Search your system’s history and keep useful searches for later.</p>
		</div>
		<span class="pill neutral">Newest first</span>
	</div>
	@if($errors->any())
		<div class="notice error" role="alert">
			<div>
				<strong>Please check your search.</strong>
				<ul>
					@foreach($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		</div>
	@endif
	<section class="panel log-search-panel" aria-labelledby="search-heading">
		<div class="section-heading">
			<div>
				<div class="eyebrow">FIND ENTRIES</div>
				<h2 id="search-heading">Search filters</h2>
			</div>
		</div>
		<form method="get" action="{{ route('logs.index', ['type' => $type]) }}" id="log-search-form" class="log-search-form">
			<p class="field-help">All non-empty filters must match. Text matching ignores case; “does not contain” includes empty fields. Add another filter to include and exclude text in the same field.</p>
			@if($type === 'api')
				<p class="field-help">API entries record method, endpoint and payload; controller, level and message are available in Activity Logs.</p>
			@endif
			<div id="log-filter-rows">
				@foreach($formFilters as $filter)
					@include('logs.filter', ['index' => $loop->index, 'filter' => is_array($filter) ? $filter : []])
				@endforeach
			</div>
			<button id="add-log-filter" class="text-button" type="button" hidden>+ Add filter</button>
			<div class="log-date-filters">
				<div>
					<label for="log-from">From date (UK)</label>
					<input id="log-from" type="date" name="from" value="{{ is_string($formFrom) ? $formFrom : '' }}">
				</div>
				<div>
					<label for="log-to">Through date (UK)</label>
					<input id="log-to" type="date" name="to" value="{{ is_string($formTo) ? $formTo : '' }}">
				</div>
				<div>
					<label for="log-per-page">Entries per page</label>
					<select id="log-per-page" name="per_page">
						@foreach($pageSizes as $size)
							<option value="{{ $size }}" @selected((int)old('per_page', $criteria['per_page']) === $size)>{{ $size }}</option>
						@endforeach
					</select>
				</div>
			</div>
			<div class="log-actions">
				<button class="button primary" type="submit">Search logs <span aria-hidden="true">↗</span></button>
				<a class="button secondary" href="{{ route('logs.index', ['type' => $type]) }}">Clear filters</a>
			</div>
		</form>
	</section>
	<section class="panel saved-search-panel" aria-labelledby="saved-heading">
		<div class="section-heading">
			<div>
				<div class="eyebrow">KEEP THE USEFUL ONES</div>
				<h2 id="saved-heading">Saved searches</h2>
			</div>
			@if($selected)
				<span class="pill neutral">Loaded: {{ $selected->name }}</span>
			@endif
		</div>
		<div class="saved-search-content">
			<form method="post" action="{{ route('logs.searches.store', ['type' => $type]) }}" class="save-search-form">
				@csrf
				@foreach($criteria['filters'] as $index => $filter)
					@foreach($filter as $key => $value)
						<input type="hidden" name="filters[{{ $index }}][{{ $key }}]" value="{{ $value }}">
					@endforeach
				@endforeach
				<input type="hidden" name="from" value="{{ $criteria['from'] }}">
				<input type="hidden" name="to" value="{{ $criteria['to'] }}">
				<input type="hidden" name="per_page" value="{{ $criteria['per_page'] }}">
				<div>
					<label for="search-name">Search name</label>
					<input id="search-name" name="name" value="{{ is_string($formName) ? $formName : '' }}" maxlength="100" required placeholder="e.g. UFH decisions" aria-describedby="save-search-help">
				</div>
				<button class="button secondary" type="submit">Save applied search</button>
			</form>
			<p id="save-search-help" class="field-help">Saves the filters used for the results below. Run your search after editing filters, then save it. Using an existing name updates that saved search.</p>
			@if($searches->isEmpty())
				<p class="field-help">No saved searches for these logs yet.</p>
			@else
				<ul class="saved-search-list">
					@foreach($searches as $search)
						<li>
							<a href="{{ route('logs.index', ['type' => $type, 'saved_search' => $search->id]) }}">{{ $search->name }}</a>
							<form method="post" action="{{ route('logs.searches.destroy', ['type' => $type, 'search' => $search->id]) }}">
								@csrf
								@method('DELETE')
								<button class="text-button" type="submit" aria-label="Delete saved search {{ $search->name }}">Delete</button>
							</form>
						</li>
					@endforeach
				</ul>
			@endif
		</div>
	</section>
	<section class="panel log-results" aria-labelledby="results-heading">
		<div class="section-heading">
			<div>
				<div class="eyebrow">MATCHING ENTRIES</div>
				<h2 id="results-heading">{{ $logs->isEmpty() ? 'No entries found' : 'Showing '.$logs->firstItem().'–'.$logs->lastItem().' of '.number_format($logs->total()) }}</h2>
			</div>
			<span class="small-label">TIMES SHOWN IN UK TIME</span>
		</div>
		@if($logs->isEmpty())
			<p class="log-empty">Try changing or clearing your filters.</p>
		@else
			<div class="log-table-wrap" tabindex="0" role="region" aria-label="Log entries">
				<table class="log-table">
					<thead>
						<tr>
							<th scope="col">Time / ID</th>
							@if($type === 'activity')
								<th scope="col">Controller / method</th>
								<th scope="col">Level</th>
								<th scope="col">Message</th>
							@else
								<th scope="col">Method / endpoint</th>
								<th scope="col">Status</th>
								<th scope="col">Payload</th>
							@endif
						</tr>
					</thead>
					<tbody>
						@foreach($logs as $log)
							<tr>
								<td class="log-time">
									<time datetime="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->timezone('Europe/London')->format('d M Y H:i:s') ?? 'Unknown' }}</time>
									<span class="field-help">#{{ $log->id }}</span>
								</td>
								@if($type === 'activity')
									<td class="log-origin">
										<strong>{{ $log->controller ?? '—' }}</strong>
										<span>{{ $log->method ?? '—' }}</span>
									</td>
									<td>
										<span class="pill {{ $log->level === 'warning' ? 'amber' : ($log->level === 'error' ? 'log-error' : 'neutral') }}">{{ $log->level ?? '—' }}</span>
									</td>
									<td>
										<div class="log-message">{{ $log->message ?? '—' }}</div>
									</td>
								@else
									<td class="log-origin">
										<strong>{{ $log->method ?? '—' }}</strong>
										<span>{{ $log->endpoint ?? '—' }}</span>
									</td>
									<td>
										<span class="pill {{ $log->ignored ? 'amber' : 'neutral' }}">{{ $log->ignored ? 'Ignored' : ($log->processed ? 'Processed' : 'Pending') }}</span>
										@if($log->processed)
											<span class="field-help">{{ \Carbon\CarbonImmutable::parse($log->processed)->timezone('Europe/London')->format('d M Y H:i:s') }}</span>
										@endif
									</td>
									<td>
										@if($log->payload === null)
											<span class="field-help">No payload</span>
										@else
											<details class="log-payload">
												<summary>View payload</summary>
												<pre>{{ $log->payload }}</pre>
											</details>
										@endif
									</td>
								@endif
							</tr>
						@endforeach
					</tbody>
				</table>
			</div>
		@endif
		@if($logs->hasPages())
			<nav class="log-pagination" aria-label="Log pages">
				@if($logs->previousPageUrl())
					<a class="button secondary" rel="prev" href="{{ $logs->previousPageUrl() }}">← Newer entries</a>
				@endif
				@if($logs->nextPageUrl())
					<a class="button secondary" rel="next" href="{{ $logs->nextPageUrl() }}">Older entries →</a>
				@endif
				<form method="get" action="{{ route('logs.index', ['type' => $type]) }}#results-heading" class="log-page-jump">
					@foreach($criteria['filters'] as $index => $filter)
						@foreach($filter as $key => $value)
							<input type="hidden" name="filters[{{ $index }}][{{ $key }}]" value="{{ $value }}">
						@endforeach
					@endforeach
					<input type="hidden" name="from" value="{{ $criteria['from'] }}">
					<input type="hidden" name="to" value="{{ $criteria['to'] }}">
					<input type="hidden" name="per_page" value="{{ $criteria['per_page'] }}">
					@if($selected)
						<input type="hidden" name="saved_search" value="{{ $selected->id }}">
					@endif
					<label for="log-page">Page</label>
					<input id="log-page" type="number" name="page" min="1" max="{{ $logs->lastPage() }}" value="{{ $logs->currentPage() }}" required aria-describedby="log-page-count">
					<span id="log-page-count">of {{ number_format($logs->lastPage()) }}</span>
					<button class="button secondary" type="submit">Go to page</button>
				</form>
			</nav>
		@endif
	</section>
	<template id="log-filter-template">
		@include('logs.filter', ['index' => '__INDEX__', 'filter' => []])
	</template>
@endsection
@push('scripts')
	<script src="{{ asset('js/log-search.js') }}" defer></script>
@endpush
