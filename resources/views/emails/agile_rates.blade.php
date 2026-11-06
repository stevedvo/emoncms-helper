<div>
	@foreach ($cheapestPeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		@foreach (['cheapest_1_hour' => '1-Hour', 'cheapest_2_hours' => '2-Hour', 'cheapest_3_hours' => '3-Hour', 'cheapest_6_hours' => '6-Hour'] as $key => $duration)
			@if (!empty($periods[$key]['window']))
				<p>Cheapest {{ $duration }} Period [{{ $periods[$key]['average_cost'] }} p/kWh] Starts at {{ $periods[$key]['window'][0]['valid_from_formatted'] }}</p>
			@else
				<p>Cheapest {{ $duration }} Period: unavailable — not enough consecutive half-hour rates.</p>
			@endif
		@endforeach
		<br />
	@endforeach

	@foreach ($expensivePeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		@foreach (['most_expensive_2_hours' => '2-Hour', 'most_expensive_3_hours' => '3-Hour', 'most_expensive_4_hours' => '4-Hour', 'most_expensive_5_hours' => '5-Hour'] as $key => $duration)
			@if (!empty($periods[$key]['window']))
				<p>Most Expensive {{ $duration }} Period [{{ $periods[$key]['average_cost'] }} p/kWh] Starts at {{ $periods[$key]['window'][0]['valid_from_formatted'] }}</p>
			@endif
		@endforeach
		<br />
	@endforeach
</div>
