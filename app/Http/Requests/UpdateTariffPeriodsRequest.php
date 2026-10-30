<?php
	namespace App\Http\Requests;

	use App\Services\ControlSettings;
	use App\Services\TariffPeriods;
	use Carbon\CarbonImmutable;
	use Illuminate\Foundation\Http\FormRequest;

	class UpdateTariffPeriodsRequest extends FormRequest
	{
		public function authorize() : bool
		{
			return $this->user() !== null;
		}

		protected function prepareForValidation() : void
		{
			$tariff = $this->input('tariff');

			if (is_array($tariff))
			{
				$tariff['extra'] = $tariff['extra'] ?? [];
				$this->merge(['tariff' => $tariff]);
			}
		}

		public function rules() : array
		{
			$rules =
			[
				'tariff_revision'      => ['required', 'string', 'size:64'],
				'tariff'               => ['required', 'array:daily,hot_water,agile,fetch,extra'],
				'tariff.fetch'         => ['required', 'date_format:H:i'],
				'tariff.extra'         => ['present', 'array', 'max:50'],
				'tariff.extra.*'       => ['required', 'array:name,type,start,end'],
				'tariff.extra.*.name'  => ['required', 'string', 'max:100'],
				'tariff.extra.*.type'  => ['required', 'in:cheap,peak'],
				'tariff.extra.*.start' => ['required', 'date_format:Y-m-d\TH:i'],
				'tariff.extra.*.end'   => ['required', 'date_format:Y-m-d\TH:i', 'after:tariff.extra.*.start'],
			];

			foreach (['daily', 'hot_water', 'agile'] as $group)
			{
				$names = array_keys(config('octopus-periods.'.$group));
				$rules['tariff.'.$group] = ['required', 'array:'.implode(',', $names)];

				foreach ($names as $name)
				{
					$path = 'tariff.'.$group.'.'.$name;
					$rules[$path] = ['required', 'array:start,end'];
					$rules[$path.'.start'] = ['required', 'date_format:H:i'];
					$rules[$path.'.end'] = ['required', 'date_format:H:i', 'different:'.$path.'.start'];
				}
			}

			return $rules;
		}

		public function attributes() : array
		{
			$attributes =
			[
				'tariff.fetch'         => 'daily rates fetch time',
				'tariff.extra.*.name'  => 'dated window name',
				'tariff.extra.*.type'  => 'dated window type',
				'tariff.extra.*.start' => 'dated window start',
				'tariff.extra.*.end'   => 'dated window finish',
			];

			foreach (['daily' => 'tariff', 'hot_water' => 'hot water', 'agile' => 'rate search'] as $group => $label)
			{
				foreach (array_keys(config('octopus-periods.'.$group)) as $name)
				{
					$attributes['tariff.'.$group.'.'.$name.'.start'] = $name.' '.$label.' start';
					$attributes['tariff.'.$group.'.'.$name.'.end'] = $name.' '.$label.' finish';
				}
			}

			return $attributes;
		}

		public function withValidator($validator) : void
		{
			$validator->after(function($validator)
			{
				if ($validator->errors()->isNotEmpty())
				{
					return;
				}

				$values = $this->input('tariff');
				$day = CarbonImmutable::parse('2026-01-15', TariffPeriods::ZONE);

				foreach ($values['daily'] as $name => $period)
				{
					[$start, $end] = TariffPeriods::window($day, $period);
					$duration = $end->diffInMinutes($start);
					$adjustment = $name === 'peak' ? ControlSettings::get('nibe.peakImportEndOffsetMinutes') - ControlSettings::get('nibe.peakImportStartOffsetMinutes') : ControlSettings::get('octopus.offsetToOff') - ControlSettings::get('octopus.offsetToOn');

					if ($duration + $adjustment <= 0 || $duration + $adjustment >= 1440)
					{
						$validator->errors()->add('tariff.daily.'.$name.'.end', 'The '.$name.' period and its control offsets must leave a positive window shorter than 24 hours.');
					}
				}

				foreach (['early', 'quiet'] as $name)
				{
					if ($values['hot_water'][$name]['end'] <= $values['hot_water'][$name]['start'])
					{
						$validator->errors()->add('tariff.hot_water.'.$name.'.end', 'Daytime hot water windows must finish after they start on the same day.');
					}
				}

				if ($values['hot_water']['overnight']['end'] >= $values['hot_water']['overnight']['start'])
				{
					$validator->errors()->add('tariff.hot_water.overnight.end', 'The overnight hot water window must cross midnight.');
				}

				foreach (['overnight' => 360, 'daytime' => 360, 'expensive' => 300] as $name => $minimum)
				{
					[$start, $end] = TariffPeriods::window($name === 'overnight' ? $day : $day->addDay(), $values['agile'][$name]);
					$fetch = TariffPeriods::clock($day, $values['fetch']);
					$horizon = $fetch->addDay();
					$usableStart = $start->isBefore($fetch) ? $fetch : $start;
					$usableEnd = $end->isAfter($horizon) ? $horizon : $end;

					if ($usableEnd->lessThanOrEqualTo($usableStart) || $usableEnd->diffInMinutes($usableStart) < $minimum)
					{
						$validator->errors()->add('tariff.agile.'.$name.'.end', 'The '.$name.' rate-search range must allow at least '.($minimum / 60).' hours between the fetch time and the same time the following day.');
					}
				}

				$daily = [];

				foreach ($values['daily'] as $name => $period)
				{
					[$start, $end] = TariffPeriods::window($day, $period);

					foreach ($daily as [$otherStart, $otherEnd])
					{
						foreach ([-1, 0, 1] as $shift)
						{
							if ($start->isBefore($otherEnd->addDays($shift)) && $end->isAfter($otherStart->addDays($shift)))
							{
								$validator->errors()->add('tariff.daily.'.$name.'.start', 'Daily tariff periods must not overlap. Use dated windows for temporary overrides.');
							}
						}
					}

					$daily[] = [$start, $end];
				}

				$events = [];

				foreach ($values['extra'] as $index => $event)
				{
					$start = CarbonImmutable::parse($event['start'], TariffPeriods::ZONE);
					$end = CarbonImmutable::parse($event['end'], TariffPeriods::ZONE);

					if ($start->format('Y-m-d\TH:i') !== $event['start'] || $end->format('Y-m-d\TH:i') !== $event['end'])
					{
						$validator->errors()->add('tariff.extra.'.$index.'.start', 'This local time does not exist when the clocks change. Choose a valid London time.');
					}

					foreach ($events as [$previousStart, $previousEnd])
					{
						if ($start->isBefore($previousEnd) && $end->isAfter($previousStart))
						{
							$validator->errors()->add('tariff.extra.'.$index.'.start', 'Dated tariff windows must not overlap.');
						}
					}

					$events[] = [$start, $end];
				}
			});
		}

		public function periods() : array
		{
			$values = $this->validated('tariff');
			$values['extra'] = array_values($values['extra']);

			return $values;
		}
	}
