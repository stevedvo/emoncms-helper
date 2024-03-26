<div>
    @if (count($data) == 0)
		<p>Nothing to report</p>
	@else
		<p>New log entries since {{$fromDateTimeString}}</p>
		<table cellspacing="0" cellpadding="5" border="1">
			<thead>
				<td>controller</td>
				<td>method</td>
				<td>level</td>
				<td>message</td>
				<td>created_at</td>
			</thead>
			<tbody>
				@foreach ($data as $log)
					<tr>
						<td style="padding: 5px;">{{$log->controller}}</td>
						<td style="padding: 5px;">{{$log->method}}</td>
						<td style="padding: 5px;">{{$log->level}}</td>
						<td style="padding: 5px;">{{$log->message}}</td>
						<td style="padding: 5px;">{{$log->created_at}}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	@endif
</div>
