<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use Illuminate\Http\Request;
	use Illuminate\Support\Collection;
	use Illuminate\Support\Facades\Log;

	class WeatherController extends Controller
	{
		public static function receiveWeatherData(Request $request) : void
		{
			try
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Receiving Weather Data",
				]);

				$setting = Setting::updateOrCreate(["key" => "weather_data"], ["value" => json_encode($request->all())]);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}
		}

		public static function getLatestWeatherData() : Collection
		{
			try
			{
				// Retrieve the setting where key is "weather_data"
				$setting = Setting::firstWhere("key", "weather_data");

				// Check if the setting is null
				if (is_null($setting))
				{
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "error",
						'message'    => "Weather Data not found",
					]);

					return new Collection();
				}

				// Decode the JSON value stored in the setting
				$settingValue = json_decode($setting->value, true);

				// Check if the decoded value is an array
				if (!is_array($settingValue))
				{
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "error",
						'message'    => "Weather Data not expected type",
					]);

					return new Collection();
				}

				// Create a collection and key it by the "datetime" field
				$weatherData = collect($settingValue)->keyBy("datetime");

				return $weatherData;
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);

				return new Collection();
			}
		}

		public static function getForecastAverageTemperature() : ?float
		{
			$averageTemperature = null;

			try
			{
				$weatherData = static::getLatestWeatherData();

				if ($weatherData->isEmpty())
				{
					throw new Exception("WeatherData is empty");
				}

				$now = CarbonImmutable::now();
				$targetTime = $now->setTime($now->hour+config('weather.lookAheadHours'), 0, 0, 0);

				$sumTemperatures = 0;
				$count = 0;

				foreach ($weatherData as $datetime => $datum)
				{
					$period = CarbonImmutable::parse($datetime);

					if (!$period->greaterThanOrEqualTo($targetTime))
					{
						continue;
					}

					$sumTemperatures+= $datum['temperature'];
					$count++;
				}

				if ($count == 0)
				{
					throw new Exception("No WeatherData after ".$targetTime->format("c"));
				}

				$averageTemperature = round($sumTemperatures/$count, 2);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}

			Log::info('$averageTemperature is '.$averageTemperature);
			// Log::info('$count is '.$count);

			return $averageTemperature;
		}
	}
