<div>
	@foreach ($cheapestPeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		<p>Cheapest 1-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['cheapest_1_hour']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['cheapest_1_hour']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['cheapest_1_hour']['window'][1]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

		<p>Cheapest 2-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['cheapest_2_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['cheapest_2_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['cheapest_2_hours']['window'][3]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

		<p>Cheapest 3-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['cheapest_3_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['cheapest_3_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['cheapest_3_hours']['window'][5]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

	@endforeach

	@foreach ($expensivePeriods as $timeLabel => $periods)
		<p>{{ ucfirst($timeLabel) }}</p>

		<p>Most Expensive 2-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['most_expensive_2_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_2_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_2_hours']['window'][3]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

		<p>Most Expensive 3-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['most_expensive_3_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_3_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_3_hours']['window'][5]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

		<p>Most Expensive 4-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['most_expensive_4_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_4_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_4_hours']['window'][7]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

		<p>Most Expensive 5-Hour Period</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<tr>
					<th>Average Cost</th>
					<th>Valid From</th>
					<th>Valid To</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding: 5px;">{{ $periods['most_expensive_5_hours']['average_cost'] }} p/kWh</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_5_hours']['window'][0]['valid_from_formatted'] }}</td>
					<td style="padding: 5px;">{{ $periods['most_expensive_5_hours']['window'][9]['valid_to_formatted'] }}</td>
				</tr>
			</tbody>
		</table>

	@endforeach
</div>
