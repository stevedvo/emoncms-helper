@extends('layouts.control-panel')
@section('title', 'Overview')
@section('content')
	@php($display = fn($value) => is_numeric($value) ? rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') : '—')
	<div class="page-heading">
		<div>
			<div class="eyebrow">AT A GLANCE</div>
			<h1>A warmer welcome home.</h1>
			<p class="intro">Your heat pump, rooms and controls in one place.</p>
		</div>
		<a href="{{ route('settings') }}" class="button secondary">Adjust settings <span aria-hidden="true">↗</span></a>
	</div>
	<section class="system-banner" aria-label="System summary">
		<div>
			<span class="status-dot" aria-hidden="true"></span>
			<strong>{{ $priority }}</strong>
			<span class="banner-separator">/</span>
			<span>Control mode: {{ $mode ? Str::headline($mode) : 'Not yet recorded' }}</span>
		</div>
		<span class="pill {{ $controlEnabled ? 'green' : 'neutral' }}">Automatic control {{ $controlEnabled ? 'enabled' : 'disabled' }}</span>
	</section>
	<section class="metric-grid" aria-label="Key readings">
		<article class="metric-card">
			<div class="metric-label">MAIN BUILDING <span>RAD</span></div>
			<div class="metric-value" id="rad-value">—<small>°C</small></div>
			<div class="metric-foot" id="rad-age">Waiting for Emon</div>
		</article>
		<article class="metric-card">
			<div class="metric-label">ANNEXE <span>UFH</span></div>
			<div class="metric-value" id="ufh-value">—<small>°C</small></div>
			<div class="metric-foot" id="ufh-age">Waiting for Emon</div>
		</article>
		<article class="metric-card">
			<div class="metric-label">OUTDOORS <span>NIBE</span></div>
			<div class="metric-value">{{ $display($readings->get(40004)?->rawValue) }}<small>°C</small></div>
			<div class="metric-foot">
				Average {{ $display($readings->get(40067)?->rawValue) }}°C ·
				<span>
					@if($readings->get(40004))
						{{ \Carbon\CarbonImmutable::createFromTimestamp($readings[40004]->timestamp)->diffForHumans() }}
					@else
						No reading yet
					@endif
				</span>
			</div>
		</article>
		<article class="metric-card">
			<div class="metric-label">DEGREE MINUTES <span>DM</span></div>
			<div class="metric-value">{{ $display($readings->get(40940)?->rawValue) }}</div>
			<div class="metric-foot">
				@if($readings->get(40940))
					Sample {{ \Carbon\CarbonImmutable::createFromTimestamp($readings[40940]->timestamp)->diffForHumans() }}
				@else
					No reading yet
				@endif
			</div>
		</article>
	</section>
	<div class="overview-grid">
		<section class="panel zone-panel">
			<div class="section-heading">
				<div>
					<div class="eyebrow">SHARING THE HEAT</div>
					<h2>Zone demand</h2>
				</div>
				<span class="pill neutral">Latest decision</span>
			</div>
			<div class="zone-row">
				<span class="zone-icon" aria-hidden="true">▥</span>
				<div>
					<strong>Radiator zone</strong>
					<span>Main building</span>
				</div>
				<span class="zone-state">{{ $radiatorDemand }}</span>
			</div>
			<div class="zone-row">
				<span class="zone-icon" aria-hidden="true">≋</span>
				<div>
					<strong>UFH zone</strong>
					<span>Annexe</span>
				</div>
				<span class="zone-state">Included in overall mode</span>
			</div>
			<p class="panel-note">
				A warm radiator zone can stay off while the annexe calls for heat.
				@if($demandAge !== null && $demandAge >= 0)
					Decision calculated {{ floor($demandAge / 60) }} min ago.
				@endif
			</p>
		</section>
		<section class="panel activity-summary">
			<div class="eyebrow">PAST 24 HOURS</div>
			<h2>System notes</h2>
			<div class="count-row">
				<div>
					<strong>{{ number_format($warnings->get('warning', 0)) }}</strong>
					<span>Warnings</span>
				</div>
				<div>
					<strong>{{ number_format($warnings->get('error', 0)) }}</strong>
					<span>Errors</span>
				</div>
			</div>
			<p class="panel-note">Counts include connection and control messages. Detailed messages remain in the activity log and administrator report.</p>
		</section>
	</div>
	<section class="panel reading-panel">
		<div class="section-heading">
			<div>
				<div class="eyebrow">FROM YOUR HEAT PUMP</div>
				<h2>NIBE readings</h2>
			</div>
			<span class="source-note">
				@if($latestAt)
					Latest sample {{ \Carbon\CarbonImmutable::createFromTimestamp($latestAt)->setTimezone('Europe/London')->format('j M, H:i:s') }} UK
				@else
					Waiting for first poll
				@endif
			</span>
		</div>
		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>Parameter</th>
						<th>Value</th>
						<th>Sample time (UK)</th>
						<th class="align-right">Local Emon sync</th>
					</tr>
				</thead>
				<tbody>
					@forelse($readings->sortBy('title') as $reading)
						<tr>
							<td>
								<span class="reading-name">{{ $reading->title ?: 'Parameter '.$reading->parameterId }}</span>
								<span class="reading-id">#{{ $reading->parameterId }}</span>
							</td>
							<td class="numeric">{{ $display($reading->rawValue) }} <span class="unit">{{ $reading->unit }}</span></td>
							<td class="muted">{{ \Carbon\CarbonImmutable::createFromTimestamp($reading->timestamp)->setTimezone('Europe/London')->format('j M, H:i:s') }}</td>
							<td class="align-right">
								<span class="pill {{ $reading->syncStatus === 'success' ? 'green' : 'amber' }}">{{ ucfirst($reading->syncStatus) }}</span>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="4" class="empty-state">No NIBE readings yet. Values will appear after the scheduled data poll.</td>
						</tr>
					@endforelse
				</tbody>
			</table>
		</div>
	</section>
	<section class="panel reading-panel" id="emon-panel" data-feeds-url="{{ route('dashboard.feeds') }}">
		<div class="section-heading">
			<div>
				<div class="eyebrow">FROM YOUR ENERGY MONITOR</div>
				<h2>Emon feeds</h2>
			</div>
			<div class="feed-actions">
				<span class="source-note" id="emon-status" role="status">Connecting to local Emon…</span>
				<button type="button" class="button compact secondary" id="refresh-feeds">Refresh</button>
			</div>
		</div>
		<div id="emon-message" class="feed-message" hidden></div>
		<div class="table-scroll">
			<table>
				<thead>
					<tr>
						<th>Feed</th>
						<th>Value</th>
						<th>Last sample (UK)</th>
						<th class="align-right">Freshness</th>
					</tr>
				</thead>
				<tbody id="emon-rows">
					<tr>
						<td colspan="4" class="empty-state">Loading the latest local feed values…</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
	<section class="panel changes-panel">
		<div class="section-heading">
			<div>
				<div class="eyebrow">THE SMALL ADJUSTMENTS</div>
				<h2>Recent settings changes</h2>
			</div>
		</div>
		@forelse($changes as $change)
			<div class="change-row">
				<span class="change-dot" aria-hidden="true"></span>
				<p>{{ $change->message }}</p>
				<time>{{ $change->created_at->setTimezone('Europe/London')->format('j M, H:i') }}</time>
			</div>
		@empty
			<p class="empty-state">No settings changes recorded yet. Your current operating values are preserved when first imported.</p>
		@endforelse
	</section>
	<p class="refresh-note">Emon feeds refresh every minute. <a href="{{ route('dashboard') }}">Reload the overview</a> to update stored NIBE readings and control decisions. Sample times show when a value was observed, not when this page was opened.</p>
@endsection
@push('scripts')
	<script src="{{ asset('js/control-panel.js') }}" defer></script>
@endpush
