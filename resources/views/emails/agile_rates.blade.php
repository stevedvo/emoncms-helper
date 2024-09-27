<div>
	@foreach ($cheapestPeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		<p>Cheapest 1-Hour Period [{{ $periods['cheapest_1_hour']['average_cost'] }} p/kWh] Starts at {{ $periods['cheapest_1_hour']['window'][0]['valid_from_formatted'] }}</p>
		<p>Cheapest 2-Hour Period [{{ $periods['cheapest_2_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['cheapest_2_hours']['window'][0]['valid_from_formatted'] }}</p>
		<p>Cheapest 3-Hour Period [{{ $periods['cheapest_3_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['cheapest_3_hours']['window'][0]['valid_from_formatted'] }}</p>
		<br />
	@endforeach

	@foreach ($expensivePeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		<p>Most Expensive 2-Hour Period [{{ $periods['most_expensive_2_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['most_expensive_2_hours']['window'][0]['valid_from_formatted'] }}</p>
		<p>Most Expensive 3-Hour Period [{{ $periods['most_expensive_3_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['most_expensive_3_hours']['window'][0]['valid_from_formatted'] }}</p>
		<p>Most Expensive 4-Hour Period [{{ $periods['most_expensive_4_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['most_expensive_4_hours']['window'][0]['valid_from_formatted'] }}</p>
		<p>Most Expensive 5-Hour Period [{{ $periods['most_expensive_5_hours']['average_cost'] }} p/kWh] Starts at {{ $periods['most_expensive_5_hours']['window'][0]['valid_from_formatted'] }}</p>
	@endforeach
</div>
