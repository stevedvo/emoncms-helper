<div>
    @if ($totalCount == 0)
		<p>Nothing to report</p>
	@else
		<p>
			<strong>{{number_format($totalCount)}}</strong>
			new log {{Str::plural('entry', $totalCount)}} since {{$fromDateTimeString}},
			grouped into <strong>{{number_format(count($data))}}</strong>
			{{Str::plural('type', count($data))}}.
		</p>
		<table cellspacing="0" cellpadding="5" border="1" style="border-collapse: collapse;">
			<thead>
				<tr>
					<th align="left">type</th>
					<th align="right">count</th>
					<th align="left">sources</th>
					<th align="left">first seen</th>
					<th align="left">last seen</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($data as $summary)
					<tr>
						<td style="padding: 5px;">{{$summary['type']}}</td>
						<td align="right" style="padding: 5px;">{{number_format($summary['count'])}}</td>
						<td style="padding: 5px;">
							@foreach ($summary['sources'] as $source)
								{{$source['name']}} ({{number_format($source['count'])}})@unless($loop->last)<br>@endunless
							@endforeach
						</td>
						<td style="padding: 5px;">{{$summary['first_seen']}}</td>
						<td style="padding: 5px;">{{$summary['last_seen']}}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif
</div>
