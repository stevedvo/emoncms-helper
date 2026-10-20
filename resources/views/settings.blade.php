@extends('layouts.control-panel')
@section('title', 'Settings')
@section('content')
	<div class="page-heading">
		<div>
			<div class="eyebrow">MAKE IT YOURS</div>
			<h1>Settings</h1>
			<p class="intro">Fine-tune the way your home is heated.</p>
		</div>
		<span class="pill neutral">{{ count($values) }} operating parameters</span>
	</div>
	<div class="notice information">
		<span class="notice-icon" aria-hidden="true">↻</span>
		<div><strong>No scheduler restart needed.</strong> Saved values are used on the next scheduled run. Heating decisions normally update every five minutes; Hive runs each minute.</div>
	</div>
	@if($errors->any())
		<div class="notice error" role="alert">
			<div>
				<strong>Your changes haven’t been saved.</strong>
				<ul>
					@foreach($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		</div>
	@endif
	<div class="settings-layout">
		<nav class="settings-nav" aria-label="Setting groups">
			<div class="small-label">ON THIS PAGE</div>
			@foreach($groups as $group => $definitions)
				<a href="#{{ Str::slug($group) }}">{{ $group }}<span>{{ count($definitions) }}</span></a>
			@endforeach
			<p>Credentials and connection details stay in your environment configuration.</p>
		</nav>
		<form class="settings-form" method="post" action="{{ route('settings.update') }}" id="settings-form">
			@csrf
			@method('PUT')
			<input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
			@foreach($groups as $group => $definitions)
				<section class="panel settings-section" id="{{ Str::slug($group) }}" aria-labelledby="heading-{{ Str::slug($group) }}">
					<div class="section-heading">
						<div>
							<div class="eyebrow">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</div>
							<h2 id="heading-{{ Str::slug($group) }}">{{ $group }}</h2>
						</div>
						@if($group === 'Legacy parameters')
							<span class="pill amber">Currently inactive</span>
						@endif
					</div>
					<div class="settings-grid">
						@foreach($definitions as $key => $definition)
							@php($field = \App\Services\ControlSettings::field($key))
							@php($value = old('values.'.$field, $values[$key]))
							<div class="setting-field {{ $definition['type'] === 'boolean' ? 'toggle-field' : '' }}">
								@if($definition['type'] === 'boolean')
									<div class="toggle-heading">
										<label for="{{ $field }}">{{ $definition['label'] }}</label>
										<div class="switch">
											<input type="hidden" name="values[{{ $field }}]" value="0">
											<input id="{{ $field }}" name="values[{{ $field }}]" type="checkbox" value="1" @checked((bool)$value) aria-describedby="help-{{ $field }}">
											<span class="switch-track" aria-hidden="true"></span>
										</div>
									</div>
								@else
									<label for="{{ $field }}">{{ $definition['label'] }}</label>
									<div class="input-unit">
										<input id="{{ $field }}" name="values[{{ $field }}]" type="number" value="{{ $value }}" min="{{ $definition['min'] }}" max="{{ $definition['max'] }}" step="{{ $definition['type'] === 'integer' ? '1' : '0.1' }}" required aria-describedby="help-{{ $field }}" @if($errors->has('values.'.$field)) aria-invalid="true" @endif>
										<span>{{ $definition['unit'] }}</span>
									</div>
								@endif
								<p id="help-{{ $field }}" class="field-help">{{ $definition['help'] }}</p>
								@error('values.'.$field)
									<p class="field-error">{{ $message }}</p>
								@enderror
							</div>
						@endforeach
					</div>
				</section>
			@endforeach
			<div class="save-bar">
				<div>
					<strong id="change-status" aria-live="polite">Ready when you are.</strong>
					<span>Changes are saved together and recorded in the activity log.</span>
				</div>
				<button class="button primary" type="submit">Save changes <span aria-hidden="true">↗</span></button>
			</div>
		</form>
	</div>
@endsection
@push('scripts')
	<script src="{{ asset('js/control-panel.js') }}" defer></script>
@endpush
