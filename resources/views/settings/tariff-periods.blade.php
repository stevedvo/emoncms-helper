<section class="panel settings-section tariff-settings" id="tariff-periods" aria-labelledby="tariff-heading">
	<div class="section-heading">
		<div>
			<div class="eyebrow">PLAN AROUND YOUR TARIFF</div>
			<h2 id="tariff-heading">Tariff periods</h2>
		</div>
		<span class="pill neutral">London time</span>
	</div>
	<p class="panel-note">Set the actual tariff times here. Heating uses your boost and peak-control offsets for both daily and dated windows. Hot-water admission uses the actual tariff times. Dated windows take precedence over the daily tariff.</p>
	<form method="post" action="{{ route('settings.tariff.update') }}" id="tariff-settings-form">
		@csrf
		@method('PUT')
		<input type="hidden" name="tariff_revision" value="{{ old('tariff_revision', $tariffRevision) }}">
		@foreach(['daily' => 'Daily tariff periods', 'hot_water' => 'Hot water start windows', 'agile' => 'Agile rate-search windows'] as $group => $heading)
			<div class="tariff-subsection">
				<h3>{{ $heading }}</h3>
				@if($group === 'daily')
					<p class="field-help">Morning, afternoon and evening are the cheap periods. A finish earlier than the start means the following day.</p>
				@elseif($group === 'hot_water')
					<p class="field-help">These are hot-water preferences, separate from the tariff. Early is used when space heating needs the cheap period; quiet is used otherwise. Repeat daytime top-ups close at the quiet-window finish. Every start must still pass the tariff peak check.</p>
				@else
					<p class="field-help">Used for the next tariff analysis and rates email. Overnight starts today; daytime and expensive start tomorrow. These do not define the Cosy tariff.</p>
				@endif
				<div class="tariff-time-grid">
					@foreach($tariff[$group] as $name => $period)
						<div class="tariff-time-window">
							<h4>{{ ucfirst($name) }}</h4>
							@foreach(['start' => 'Start', 'end' => 'Finish'] as $boundary => $label)
								<div>
									<label for="tariff-{{ $group }}-{{ $name }}-{{ $boundary }}">{{ $label }}</label>
									<input type="time" id="tariff-{{ $group }}-{{ $name }}-{{ $boundary }}" name="tariff[{{ $group }}][{{ $name }}][{{ $boundary }}]" value="{{ old('tariff.'.$group.'.'.$name.'.'.$boundary, $period[$boundary]) }}" required>
								</div>
							@endforeach
						</div>
					@endforeach
				</div>
			</div>
		@endforeach
		<div class="tariff-subsection">
			<label for="tariff-fetch">Daily rates fetch from</label>
			<input type="time" id="tariff-fetch" name="tariff[fetch]" value="{{ old('tariff.fetch', $tariff['fetch']) }}" required>
		</div>
		<div class="tariff-subsection">
			<h3>Dated extra windows</h3>
			<p class="field-help">Add extra cheap offers or peak/saving sessions. Heating follows the adjusted start and finish; hot water uses the actual times. Expired entries may be removed whenever convenient. Entered tariff windows cannot overlap; if heating offsets create an overlap, peak controls take priority. Cheap offers enable preheat targets and may allow a first hot-water recharge without heating demand. Peak sessions defer new hot-water starts that would overlap them; running recharges continue.</p>
			<div id="tariff-events">
				@foreach(old('tariff.extra', $tariff['extra']) as $index => $event)
					@include('settings.tariff-event', ['index' => $index, 'event' => $event])
				@endforeach
			</div>
			<button class="button amber" type="button" id="add-tariff-event">Add dated window</button>
			<noscript><p>Enable JavaScript to add or remove dated windows.</p></noscript>
		</div>
		<div class="save-bar">
			<div>
				<strong id="tariff-change-status" aria-live="polite">Tariff periods ready.</strong>
				<span>Saved separately from the operating parameters above. Existing Cosy schedules are refreshed immediately.</span>
			</div>
			<button class="button primary" type="submit">Save tariff periods</button>
		</div>
	</form>
	<template id="tariff-event-template">
		@include('settings.tariff-event', ['index' => '__INDEX__', 'event' => ['name' => '', 'type' => 'cheap', 'start' => '', 'end' => '']])
	</template>
</section>
