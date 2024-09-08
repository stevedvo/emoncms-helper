<div>
	@foreach ($cheapestPeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		<p>Cheapest 1-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost (inc VAT)</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($periods['cheapest_1_hour']['window'] as $slot)
					<tr>
						<td style="padding: 5px;">{{ $periods['cheapest_1_hour']['average_cost'] }} p/kWh</td>
						<td style="padding: 5px;">{{ $slot['valid_from'] }}</td>
						<td style="padding: 5px;">{{ $slot['valid_to'] }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>

		<p>Cheapest 2-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost (inc VAT)</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($periods['cheapest_2_hours']['window'] as $slot)
					<tr>
						<td style="padding: 5px;">{{ $periods['cheapest_2_hours']['average_cost'] }} p/kWh</td>
						<td style="padding: 5px;">{{ $slot['valid_from'] }}</td>
						<td style="padding: 5px;">{{ $slot['valid_to'] }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>

		<p>Cheapest 3-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost (inc VAT)</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($periods['cheapest_3_hours']['window'] as $slot)
					<tr>
						<td style="padding: 5px;">{{ $periods['cheapest_3_hours']['average_cost'] }} p/kWh</td>
						<td style="padding: 5px;">{{ $slot['valid_from'] }}</td>
						<td style="padding: 5px;">{{ $slot['valid_to'] }}</td>
					</tr>
				@endforeach
			</tbody>
		</table>

	@endforeach
</div>
