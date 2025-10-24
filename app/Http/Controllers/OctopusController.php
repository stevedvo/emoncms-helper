<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\OctopusAPI;
	use App\Mail\AgileRates;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\Carbon;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Log;
	use Illuminate\Support\Facades\Mail;

	class OctopusController extends Controller
	{
		public static function tryGetAgileRates(): void
		{
			$currentTime = CarbonImmutable::now()->timezone("Europe/London");
			$cutoff = $currentTime->copy()->setTime(16, 30);

			$setting = Setting::firstWhere(["key" => "getAgileRatesSuccess"]);
			$settingValue = $setting?->value === "true";

			// Log::info("Current time: " . $currentTime);
			// Log::info("Setting value: " . var_export($settingValue, true));

			// Case 1: Before 16:00 and setting is true → reset to false
			if ($currentTime->lt($cutoff) && $settingValue)
			{
				Log::info("Before 16:30, resetting getAgileRatesSuccess to false");
				Setting::updateOrCreate(["key" => "getAgileRatesSuccess"], ["value" => "false"]);

				return;
			}

			// Case 2: After 16:00 and setting is false or missing → try to get rates
			if ($currentTime->gte($cutoff) && !$settingValue)
			{
				Log::info("After 16:30 and setting is false/missing, attempting getAgileRates()");
				static::getAgileRates();
			}
		}

		public static function getAgileRates() : void
		{
			try
			{
				$octopus = new OctopusAPI();
				$rates = $octopus->getAgileRates();

				if (!isset($rates['results']))
				{
					throw new Exception("Rates results not received");
				}

				if (!is_array($rates['results']))
				{
					throw new Exception("Rates results not in expected format");
				}

				$results = $rates['results'];

				foreach ($results as &$period)
				{
					$period['valid_from_formatted'] = Carbon::parse($period['valid_from'])->setTimezone("Europe/London")->format("Y-m-d H:i");
					$period['valid_to_formatted']   = Carbon::parse($period['valid_to'])->setTimezone("Europe/London")->format("Y-m-d H:i");
				}

				// Log::info($results);exit;

				// Define time ranges to check (adjust based on your needs)
				$cheapPeriodsToCheck =
				[
					'overnight' =>
					[
						'start' => Carbon::createFromTime(19, 0, 0), // 19:00
						'end'   => Carbon::createFromTime(7, 0, 0)->addDay(), // 07:00 next day
					],

					'daytime'   =>
					[
						'start' => Carbon::createFromTime(10, 0, 0)->addDay(), // 10:00 tomorrow
						'end'   => Carbon::createFromTime(16, 0, 0)->addDay(), // 16:00 tomorrow
					],
				];

				$expensivePeriodsToCheck =
				[
					'morning'   =>
					[
						'start' => Carbon::createFromTime(6, 0, 0)->addDay(), // 06:00 tomorrow
						'end'   => Carbon::createFromTime(12, 0, 0)->addDay(), // 12:00 tomorrow
					],

					// 'evening'   =>
					// [
					// 	'start' => Carbon::createFromTime(16, 0, 0)->addDay(), // 16:00 tomorrow
					// 	'end'   => Carbon::createFromTime(22, 0, 0)->addDay(), // 22:00 tomorrow
					// ],
				];

				$cheapestPeriods = static::findCheapestPeriods($results, $cheapPeriodsToCheck);
				$mostExpensivePeriods = static::findMostExpensivePeriods($results, $expensivePeriodsToCheck);

				try
				{
					static::saveCheapestPeriods($cheapestPeriods);

					$agileRates = new AgileRates($cheapestPeriods, $mostExpensivePeriods);

					Mail::to(config("app.admin_email"))->send($agileRates);

					Setting::updateOrCreate(["key" => "getAgileRatesSuccess"], ["value" => "true"]);
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

					Setting::updateOrCreate(["key" => "getAgileRatesSuccess"], ["value" => "false"]);

					// Output the results
					Log::info('$cheapestPeriods');
					Log::info($cheapestPeriods);
					Log::info('$mostExpensivePeriods');
					Log::info($mostExpensivePeriods);
					// exit;
				}
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

				Setting::updateOrCreate(["key" => "getAgileRatesSuccess"], ["value" => "false"]);
			}
		}

		private static function findCheapestPeriods(array $results, array $periodsToCheck) : array
		{
			$cheapestPeriods = [];

			// Get current time
			$currentTime = CarbonImmutable::now();
			$twentyFourHoursAhead = $currentTime->addDay();

			foreach ($periodsToCheck as $label => $timeRange)
			{
				// Filter the results based on the time range and only future periods up to 24 hours ahead
				$filteredResults = array_filter($results, function($item) use ($timeRange, $currentTime, $twentyFourHoursAhead)
				{
					$validFrom = Carbon::parse($item['valid_from']);

					// Ensure that the valid_from is after the current time and falls within the time range
					return $validFrom->greaterThanOrEqualTo($currentTime) &&
						   $validFrom->lessThan($twentyFourHoursAhead) &&
						   $validFrom->greaterThanOrEqualTo($timeRange['start']) &&
						   $validFrom->lessThan($timeRange['end']);
				});

				// Sort the filtered results by valid_from
				usort($filteredResults, function($a, $b)
				{
					return strtotime($a['valid_from']) <=> strtotime($b['valid_from']);
				});

				// Find cheapest 1-hour, 2-hour, and 3-hour windows
				$cheapestPeriods[$label] =
				[
					'cheapest_1_hour'  => static::findCheapestWindow($filteredResults, 2), // 1 hour = 2 periods (30 min each)
					'cheapest_2_hours' => static::findCheapestWindow($filteredResults, 4), // 2 hours = 4 periods (30 min each)
					'cheapest_3_hours' => static::findCheapestWindow($filteredResults, 6), // 3 hours = 6 periods (30 min each)
					'cheapest_6_hours' => static::findCheapestWindow($filteredResults, 12), // 6 hours = 12 periods (30 min each)
				];
			}

			return $cheapestPeriods;
		}

		private static function findCheapestWindow(array $results, int $windowSize) : array
		{
			$minAvgCost = PHP_INT_MAX;
			$cheapestWindow = [];

			for ($i = 0; $i <= count($results) - $windowSize; $i++)
			{
				$currentWindow = array_slice($results, $i, $windowSize);
				$totalCost = array_sum(array_column($currentWindow, 'value_inc_vat'));
				$avgCost = $totalCost / $windowSize; // Calculate average cost

				if ($avgCost < $minAvgCost)
				{
					$minAvgCost = $avgCost;
					$cheapestWindow = $currentWindow;
				}
			}

			return [
				'average_cost' => round($minAvgCost, 4),
				'window'       => $cheapestWindow,
			];
		}

		private static function findMostExpensivePeriods(array $results, array $periodsToCheck) : array
		{
			$mostExpensivePeriods = [];

			// Get current time
			$currentTime = CarbonImmutable::now();
			$twentyFourHoursAhead = $currentTime->addDay();

			foreach ($periodsToCheck as $label => $timeRange)
			{
				// Filter the results based on the time range and only future periods up to 24 hours ahead
				$filteredResults = array_filter($results, function($item) use ($timeRange, $currentTime, $twentyFourHoursAhead)
				{
					$validFrom = Carbon::parse($item['valid_from']);

					// Ensure that the valid_from is after the current time and falls within the time range
					return $validFrom->greaterThanOrEqualTo($currentTime) &&
						   $validFrom->lessThan($twentyFourHoursAhead) &&
						   $validFrom->greaterThanOrEqualTo($timeRange['start']) &&
						   $validFrom->lessThan($timeRange['end']);
				});

				// Sort the filtered results by valid_from
				usort($filteredResults, function($a, $b)
				{
					return strtotime($a['valid_from']) <=> strtotime($b['valid_from']);
				});

				// Find most expensive 2-hour, 3-hour, 4-hour, and 5-hour windows
				$mostExpensivePeriods[$label] =
				[
					'most_expensive_2_hours' => static::findMostExpensiveWindow($filteredResults, 4), // 2 hours = 4 periods (30 min each)
					'most_expensive_3_hours' => static::findMostExpensiveWindow($filteredResults, 6), // 3 hours = 6 periods (30 min each)
					'most_expensive_4_hours' => static::findMostExpensiveWindow($filteredResults, 8), // 4 hours = 8 periods (30 min each)
					'most_expensive_5_hours' => static::findMostExpensiveWindow($filteredResults, 10), // 5 hours = 10 periods (30 min each)
				];
			}

			return $mostExpensivePeriods;
		}

		private static function findMostExpensiveWindow(array $results, int $windowSize) : array
		{
			$maxAvgCost = PHP_INT_MIN;
			$mostExpensiveWindow = [];

			for ($i = 0; $i <= count($results) - $windowSize; $i++)
			{
				$currentWindow = array_slice($results, $i, $windowSize);
				$totalCost = array_sum(array_column($currentWindow, 'value_inc_vat'));
				$avgCost = $totalCost / $windowSize; // Calculate average cost

				if ($avgCost > $maxAvgCost)
				{
					$maxAvgCost = $avgCost;
					$mostExpensiveWindow = $currentWindow;
				}
			}

			return [
				'average_cost' => round($maxAvgCost, 4),
				'window'       => $mostExpensiveWindow,
			];
		}

		private static function saveCheapestPeriods(array $cheapestPeriods) : void
		{
			$schedule =
			[
				'cheapest_3_hours' =>
				[
					[
						'average_cost' => $cheapestPeriods['overnight']['cheapest_3_hours']['average_cost'],
						'start'        => Carbon::parse($cheapestPeriods['overnight']['cheapest_3_hours']['window'][0]['valid_from'])->addMinutes(-15)->getTimestamp(),
						'end'          => Carbon::parse($cheapestPeriods['overnight']['cheapest_3_hours']['window'][5]['valid_to'])->addMinutes(-60)->getTimestamp(),
					],
					[
						'average_cost' => $cheapestPeriods['daytime']['cheapest_3_hours']['average_cost'],
						'start'        => Carbon::parse($cheapestPeriods['daytime']['cheapest_3_hours']['window'][0]['valid_from'])->addMinutes(-15)->getTimestamp(),
						'end'          => Carbon::parse($cheapestPeriods['daytime']['cheapest_3_hours']['window'][5]['valid_to'])->addMinutes(-60)->getTimestamp(),
					],
				],

				'cheapest_6_hours' =>
				[
					[
						'average_cost' => $cheapestPeriods['overnight']['cheapest_6_hours']['average_cost'],
						'start'        => Carbon::parse($cheapestPeriods['overnight']['cheapest_6_hours']['window'][0]['valid_from'])->addMinutes(-15)->getTimestamp(),
						'end'          => Carbon::parse($cheapestPeriods['overnight']['cheapest_6_hours']['window'][11]['valid_to'])->addMinutes(-60)->getTimestamp(),
					],
					[
						'average_cost' => $cheapestPeriods['daytime']['cheapest_6_hours']['average_cost'],
						'start'        => Carbon::parse($cheapestPeriods['daytime']['cheapest_6_hours']['window'][0]['valid_from'])->addMinutes(-15)->getTimestamp(),
						'end'          => Carbon::parse($cheapestPeriods['daytime']['cheapest_6_hours']['window'][11]['valid_to'])->addMinutes(-60)->getTimestamp(),
					],
				],
			];

			$now = CarbonImmutable::now()->tz("Europe/London");
			$morningStart = $now->setTime(4, 0);

			if ($morningStart->isBefore($now))
			{
				$morningStart = $morningStart->addDay();
			}

			$morningEnd = $morningStart->addMinutes(180);

			$afternoonStart = $now->setTime(13, 0);

			if ($afternoonStart->isBefore($now))
			{
				$afternoonStart = $afternoonStart->addDay();
			}

			$afternoonEnd = $afternoonStart->addMinutes(180);

			$eveningStart = $now->setTime(22, 0);

			if ($eveningStart->isBefore($now))
			{
				$eveningStart = $eveningStart->addDay();
			}

			$eveningEnd = $eveningStart->addMinutes(120);

			$schedule['cosy'] =
			[
				[
					'average_cost' => 13.64,
					'start'        => $morningStart->addMinutes(-15)->getTimestamp(),
					'end'          => $morningEnd->addMinutes(-60)->getTimestamp(),
				],
				[
					'average_cost' => 13.64,
					'start'        => $afternoonStart->addMinutes(-15)->getTimestamp(),
					'end'          => $afternoonEnd->addMinutes(-60)->getTimestamp(),
				],
				[
					'average_cost' => 13.64,
					'start'        => $eveningStart->addMinutes(-15)->getTimestamp(),
					'end'          => $eveningEnd->addMinutes(-60)->getTimestamp(),
				],
			];

			$setting = Setting::updateOrCreate(["key" => "agile_schedule"], ["value" => json_encode($schedule)]);
		}
	}
