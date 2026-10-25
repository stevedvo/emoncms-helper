<?php
	namespace App\Http\Requests;

	use App\Services\ControlSettings;
	use Illuminate\Foundation\Http\FormRequest;

	class UpdateControlSettingsRequest extends FormRequest
	{
		public function authorize(): bool
		{
			return $this->user() !== null;
		}

		public function rules(): array
		{
			$definitions = ControlSettings::definitions();
			$fields = array_map([ControlSettings::class, 'field'], array_keys($definitions));
			$rules = ['revision' => ['required', 'string', 'size:64'], 'values' => ['required', 'array:'.implode(',', $fields), 'min:1']];

			foreach ($definitions as $key => $definition)
			{
				$type = $definition['type'] === 'number' ? 'numeric' : $definition['type'];
				$rules['values.'.ControlSettings::field($key)] = ['sometimes', 'required', $type];

				if ($type !== 'boolean')
				{
					$rules['values.'.ControlSettings::field($key)][] = 'min:'.$definition['min'];
					$rules['values.'.ControlSettings::field($key)][] = 'max:'.$definition['max'];
				}
			}
			return $rules;
		}

		public function attributes(): array
		{
			$attributes = [];

			foreach (ControlSettings::definitions() as $key => $definition)
			{
				$attributes['values.'.ControlSettings::field($key)] = $definition['label'];
			}

			return $attributes;
		}

		public function withValidator($validator): void
		{
			$validator->after(function ($validator)
			{
				if ($validator->errors()->isNotEmpty())
				{
					return;
				}

				$candidate = ControlSettings::values();

				foreach ($candidate as $key => $value)
				{
					$candidate[$key] = $this->input('values.'.ControlSettings::field($key), $value);
				}

				$order = function ($high, $low, $message, $strict = false) use ($validator, $candidate)
				{
					if ($strict ? $candidate[$high] <= $candidate[$low] : $candidate[$high] < $candidate[$low])
					{
						$validator->errors()->add('values.'.ControlSettings::field($high), $message);
					}
				};

				$order('nibe.loadCompTempOff', 'nibe.loadCompTempIntermittent', 'The radiator off threshold must be at least the intermittent threshold.');
				$order('nibe.loadCompTempIntermittent', 'nibe.loadCompTempOn', 'The intermittent threshold must be at least the on threshold.');
				$order('nibe.loadCompTempOn', 'nibe.loadCompTempLevel1', 'The on threshold must be at least the boost threshold.');
				$order('nibe.ufhTempIntermittent', 'nibe.ufhTempOn', 'The UFH intermittent threshold must be at least its continuous-heating threshold.');
				$order('nibe.hotWaterOvernightTemperature', 'nibe.hotWaterUrgentTemperature', 'The urgent hot water threshold must not exceed the overnight threshold.');
				$order('nibe.hotWaterAfternoonTemperature', 'nibe.hotWaterUrgentTemperature', 'The urgent hot water threshold must not exceed the afternoon threshold.');
				$order('nibe.hotWaterSundayTemperature', 'nibe.hotWaterUrgentTemperature', 'The urgent hot water threshold must not exceed the Sunday afternoon threshold.');

				if ($candidate['octopus.offsetToOn'] - $candidate['octopus.offsetToOff'] >= 120)
				{
					$validator->errors()->add('values.octopus__offsetToOff', 'The start and end offsets must leave a positive duration for the shortest two-hour boost window.');
				}

				if ($candidate['nibe.peakImportStartOffsetMinutes'] - $candidate['nibe.peakImportEndOffsetMinutes'] >= 180)
				{
					$validator->errors()->add('values.nibe__peakImportEndOffsetMinutes', 'The peak start and end offsets must leave a positive duration for the 16:00 to 19:00 peak window.');
				}

				$order('nibe.offsetMaximum', 'nibe.offsetMinimum', 'The maximum offset must be at least the minimum offset.');

				foreach (['nibe.offsetMaxInt', 'nibe.cheapModeOffsetMax'] as $key)
				{
					$order($key, 'nibe.offsetMinimum', 'This offset limit must be within the minimum and maximum offsets.');
					$order('nibe.offsetMaximum', $key, 'The maximum offset must include both special-mode limits.');
				}

				$order('nibe.dmTargetOff', 'nibe.dmTarget', 'The off DM target must be at least the normal DM target.');
				$order('nibe.dmTarget', 'nibe.dmTargetBoost', 'The normal DM target must be at least the boost DM target.');
				$order('nibe.runLevel1Temp', 'nibe.runLevel2Temp', 'The warm outdoor threshold must be at least the cool threshold.');
				$order('nibe.runLevel2Temp', 'nibe.tempFreqMin', 'The cool outdoor threshold must be at least the cold threshold.');
				$order('nibe.defrostDewpointMax', 'nibe.defrostDewpointMin', 'The maximum dew point must be higher than the minimum.', true);
				$order('hive.targetOnTemp', 'hive.targetOffTemp', 'The Hive on target must be higher than its off target.', true);
			});
		}

		public function parameters(): array
		{
			$values = $this->validated('values');
			$parameters = [];

			foreach (ControlSettings::definitions() as $key => $definition)
			{
				if (array_key_exists(ControlSettings::field($key), $values))
				{
					$parameters[$key] = $values[ControlSettings::field($key)];
				}
			}

			return $parameters;
		}
	}
