<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\OctopusAPI;
	use App\Mail\AgileRates;
	use App\Models\ActivityLog;
	use Carbon\Carbon;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Log;
	use Illuminate\Support\Facades\Mail;

	class OctopusController extends Controller
	{
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

				// Log::info($results);

				// Define time ranges to check (adjust based on your needs)
				$periodsToCheck =
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

				$cheapestPeriods = self::findCheapestPeriods($results, $periodsToCheck);

				// Output the results
				// Log::info('$cheapestPeriods');
				// Log::info($cheapestPeriods);

				$agileRates = new AgileRates($cheapestPeriods);
				Mail::to(config("app.admin_email"))->send($agileRates);
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

		private static function findCheapestPeriods($results, $periodsToCheck) : array
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
					'cheapest_1_hour'  => self::findCheapestWindow($filteredResults, 2), // 1 hour = 2 periods (30 min each)
					'cheapest_2_hours' => self::findCheapestWindow($filteredResults, 4), // 2 hours = 4 periods (30 min each)
					'cheapest_3_hours' => self::findCheapestWindow($filteredResults, 6), // 3 hours = 6 periods (30 min each)
				];
			}

			return $cheapestPeriods;
		}

		private static function findCheapestWindow($results, $windowSize) : array
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
				'average_cost' => $minAvgCost,
				'window'       => $cheapestWindow,
			];
		}
	}
