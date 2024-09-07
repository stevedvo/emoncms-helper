<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\OctopusAPI;
	use App\Models\ActivityLog;
	use Illuminate\Support\Facades\Log;

	class OctopusController extends Controller
	{
		public static function getAgileRates() : void
		{
			try
			{
				$octopus = new OctopusAPI();
				$rates = $octopus->getAgileRates();

				// Log::info($rates);

				if (!isset($rates['results']))
				{
					throw new Exception("Rates results not received");
				}

				if (!is_array($rates['results']))
				{
					throw new Exception("Rates results not in expected format");
				}

				$results = $rates['results'];

				// Define time ranges to check (adjust based on your needs)
				$periodsToCheck =
				[
				    'night_time' =>
				    [
				    	'start' => '19:00',
				    	'end'   => '07:00',
				    ],

				    'day_time'   =>
				    [
				    	'start' => '10:00',
				    	'end'   => '16:00',
				    ],
				];

				$cheapestPeriods = self::findCheapestPeriods($results, $periodsToCheck);

				// Output the results
				Log::info($cheapestPeriods);

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

		private static function findCheapestPeriods($results, $periodsToCheck)
		{
		    $cheapestPeriods = [];

		    // Get current time
		    $now = time();

		    foreach ($periodsToCheck as $label => $timeRange)
		    {
		        // Filter the results based on the time range and only future periods
		        $filteredResults = array_filter($results, function ($item) use ($timeRange, $now)
		        {
		            $validFrom = strtotime($item['valid_from']);
		            return $validFrom >= $now && $validFrom >= strtotime($timeRange['start']) && $validFrom < strtotime($timeRange['end']);
		        });

		        // Sort the filtered results by valid_from
		        usort($filteredResults, function ($a, $b)
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

		private static function findCheapestWindow($results, $windowSize)
		{
		    $minCost = PHP_INT_MAX;
		    $cheapestWindow = [];

		    for ($i = 0; $i <= count($results) - $windowSize; $i++)
		    {
		        $currentWindow = array_slice($results, $i, $windowSize);
		        $totalCost = array_sum(array_column($currentWindow, 'value_inc_vat'));

		        if ($totalCost < $minCost)
		        {
		            $minCost = $totalCost;
		            $cheapestWindow = $currentWindow;
		        }
		    }

		    return [
		        'total_cost' => $minCost,
		        'window'     => $cheapestWindow,
		    ];
		}
	}
