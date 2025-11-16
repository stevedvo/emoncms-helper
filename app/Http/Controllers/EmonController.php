<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\Models\ActivityLog;
	use App\Models\EmonFeedMap;
	use App\Models\FeedItem;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Collection;
	use Illuminate\Support\Facades\Log;

	class EmonController extends Controller
	{
		public static function syncEmonFeeds() : void
		{
			// 1st task: get the feeds from local and from remote and compare them to find any that are missing/incorrect
			static::getEmonFeeds();

			// 2nd task: find any feed items which have not been sync'd from local to remote and attempt to sync
			static::postEmonFeeds();
		}

		public static function getEmonFeeds() : void
		{
			try
			{
				$localToRemoteFeedMap = EmonFeedMap::all()->keyBy("localFeedId");
				$ignoreLocalFeedIds   = [20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 62, 64, 65, 66, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80, 81, 82, 83];

				$localFeeds = EmonAPI::getFeedList("local");

				$now = CarbonImmutable::now();
				$endTime = $now->subMinutes(5);
				$startTime = $endTime->subHours(config("emon.emonSyncPeriodHours"));
				$startTimeMilliseconds = $startTime->format("Uv");
				$endTimeMilliseconds = $endTime->format("Uv");

				$existingFeedItems = FeedItem::whereBetween("timestamp", [$startTimeMilliseconds, $endTimeMilliseconds])->get();

				foreach ($localFeeds as $localFeed)
				{
					if (in_array($localFeed['id'], $ignoreLocalFeedIds))
					{
						continue;
					}

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => "Checking localFeed #".$localFeed['id'],
					]);

					$localFeedItemsCount = 0;
					$remoteFeedItemsMatched = 0;
					$existingFeedItemsCount = 0;
					$newFeedItemsToSync = 0;

					try
					{
						if (!$localToRemoteFeedMap->has($localFeed['id']))
						{
							ActivityLog::create(
							[
								'controller' => __CLASS__,
								'method'     => __FUNCTION__,
								'level'      => "warning",
								'message'    => "localFeed #".$localFeed['id']." not recognised",
							]);

							continue;
						}

						$remoteFeedId = $localToRemoteFeedMap->get($localFeed['id'])->remoteFeedId;

						$remoteFeedItems = new Collection;
						$feedItemsToSync = new Collection;

						$localFeedData = EmonAPI::getFeedData("local", $localFeed['id'], $startTimeMilliseconds, $endTimeMilliseconds);
						$remoteFeedData = EmonAPI::getFeedData("remote", $remoteFeedId, $startTimeMilliseconds, $endTimeMilliseconds);

						foreach ($remoteFeedData as $remoteFeedDatum)
						{
							$remoteFeedItem = new FeedItem(
							[
								'remoteFeedId' => $remoteFeedId,
								'timestamp'    => $remoteFeedDatum[0],
								'value'        => $remoteFeedDatum[1],
							]);

							$remoteFeedItems->put($remoteFeedItem->timestamp, $remoteFeedItem);
						}

						foreach ($localFeedData as $localFeedDatum)
						{
							try
							{
								$localFeedItemsCount++;

								$localFeedItem = new FeedItem(
								[
									'localFeedId'  => $localFeed['id'],
									'timestamp'    => $localFeedDatum[0],
									'value'        => $localFeedDatum[1],
									'syncAttempts' => 0,
									'syncStatus'   => "pending",
								]);

								if ($remoteFeedItems->has($localFeedItem->timestamp))
								{
									if ($remoteFeedItems->get($localFeedItem->timestamp)->value == $localFeedItem->value)
									{
										$remoteFeedItemsMatched++;
										continue;
									}
								}

								$existingFeedItem = $existingFeedItems->where("localFeedId", $localFeedItem->localFeedId)->where("timestamp", $localFeedItem->timestamp)->first();

								if ($existingFeedItem instanceof FeedItem)
								{
									$existingFeedItemsCount++;
									continue;
								}

								$localFeedItem->remoteFeedId = $remoteFeedId;

								switch ($localToRemoteFeedMap->get($localFeedItem->localFeedId)->localName)
								{
									case "heatmeter_Energy":
									case "heatmeter_FlowT":
									case "heatmeter_ReturnT":
									case "total_Power":
									case "total_Energy":
									case "heatmeter_DeltaT":
									{
										if (strpos($localFeedItem->value, "null") === false)
										{
											$localFeedItem->save();
											$newFeedItemsToSync++;
										}
									}
									break;

									case "heatmeter_Power":
									case "heatmeter_FlowRate":
									{
										if (strpos($localFeedItem->value, "null") !== false)
										{
											$localFeedItem->value = 0;
										}

										$localFeedItem->save();
										$newFeedItemsToSync++;
									}
									break;

									case "UFH_temperature":
									case "UFH_humidity":
									case "UFH_battery":
									case "Rad_temperature":
									case "Rad_humidity":
									case "Rad_battery":
									{
										if (strpos($localFeedItem->value, "null") === false)
										{
											// we could sync' data from every 10s but these values do not change that frequently
											// so only save values for sync'ing if timestamp is exactly in a 10th minute
											$tenthMinute = floor($localFeedItem->timestamp / 100000);

											if ($tenthMinute % 6 === 0)
											{
												$min = $tenthMinute * 100000;
												$max = $min + 9999;

												if ($min <= $localFeedItem->timestamp && $localFeedItem->timestamp <= $max)
												{
													$localFeedItem->save();
													$newFeedItemsToSync++;
												}
											}
										}
									}
									break;

									default:
										// code...
										break;
								}
							}
							catch (Throwable $e)
							{
								ActivityLog::create(
								[
									'controller' => __CLASS__,
									'method'     => __FUNCTION__,
									'level'      => "error",
									'message'    => "localFeed #".$localFeed['id'].": ".$e->getMessage(),
								]);
							}
						}
					}
					catch (Throwable $e)
					{
						ActivityLog::create(
						[
							'controller' => __CLASS__,
							'method'     => __FUNCTION__,
							'level'      => "error",
							'message'    => "localFeed #".$localFeed['id'].": ".$e->getMessage(),
						]);
					}

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => "localFeed #".$localFeed['id'].": fetched ".$localFeedItemsCount."; remote matched ".$remoteFeedItemsMatched."; existing ".$existingFeedItemsCount."; new to sync ".$newFeedItemsToSync,
					]);
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
			}
		}

		public static function getLatestRoomTemperatureData() : ?float
		{
			$latestTemp = null;

			try
			{
				$roomFeed = EmonFeedMap::where('localName', "UFH_temperature")->first();

				// Log::info($roomFeed);

				if ($roomFeed instanceof EmonFeedMap)
				{
					$endTime = CarbonImmutable::now()->startOfMinute();
					$startTime = $endTime->subMinutes(5);
					$startTimeMilliseconds = $startTime->format("Uv");
					$endTimeMilliseconds = $endTime->format("Uv");

					$localFeedData = EmonAPI::getFeedData("local", $roomFeed['localFeedId'], $startTimeMilliseconds, $endTimeMilliseconds);
					$latestTemp = ($pair = collect($localFeedData)->last(fn($pair) => !is_null($pair[1]))) ? round($pair[1], 1) : null;

					// Log::info($latestTemp);
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
			}

			return $latestTemp;
		}

		public static function getForecastRoomTemperatureData() : ?array
		{
			$latestData = [];

			try
			{
				$roomFeed = EmonFeedMap::where('localName', "UFH_temperature")->first();

				// Log::info($roomFeed);

				if ($roomFeed instanceof EmonFeedMap)
				{
					$endTime = CarbonImmutable::now()->startOfMinute();
					$startTime = $endTime->subMinutes(60);
					$startTimeMilliseconds = $startTime->format("Uv");
					$endTimeMilliseconds = $endTime->format("Uv");

					$localFeedData = EmonAPI::getFeedData("local", $roomFeed['localFeedId'], $startTimeMilliseconds, $endTimeMilliseconds);
					// Log::info($localFeedData);

		            // Filter out nulls and reindex
		            $cleanData = collect($localFeedData)->filter(fn($pair) => !is_null($pair[1]))->values();

		            if ($cleanData->isNotEmpty())
		            {
		                // Earliest & latest values
		                $earliestTemp = round($cleanData->first()[1], 1);
		                $latestTemp   = round($cleanData->last()[1], 1);

		                $latestData['tempEarliest']         = $earliestTemp;
		                $latestData['tempCurrent']          = $latestTemp;
		                $latestData['tempCurrentTimestamp'] = $cleanData->last()[0] / 1000; // convert from milliseconds

		                // --- Linear regression fit (least squares) ---
		                // Each $pair is [timestampMs, value]
		                $n = $cleanData->count();

		                // Work in seconds for smaller numbers
		                $xs = $cleanData->map(fn($p) => $p[0] / 1000)->all();
		                $ys = $cleanData->map(fn($p) => $p[1])->all();

		                $xMean = array_sum($xs) / $n;
		                $yMean = array_sum($ys) / $n;

		                $num = 0.0;
		                $den = 0.0;

		                for ($i = 0; $i < $n; $i++)
		                {
		                    $num += ($xs[$i] - $xMean) * ($ys[$i] - $yMean);
		                    $den += ($xs[$i] - $xMean) ** 2;
		                }

		                $slope = $den != 0 ? $num / $den : 0; // °C per second
		                $intercept = $yMean - $slope * $xMean;

		                // Forecast h hours into the future
		                $h = 2;
		                $futureTime = ($cleanData->last()[0] / 1000) + 3600 * $h;
		                $forecastTemp = round($intercept + $slope * $futureTime, 1);

		                $latestData['tempForecast'] = $forecastTemp;
		                $latestData['tempIncreasing'] = $latestTemp > $earliestTemp;
		            }
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
			}

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => '$latestData: '.serialize($latestData),
			]);

			$requiredKeys = ['tempEarliest', 'tempCurrent', 'tempCurrentTimestamp', 'tempForecast', 'tempIncreasing'];

			foreach ($requiredKeys as $key)
			{
				if (!isset($latestData[$key]))
				{
					return null;
				}
			}

			return $latestData;
		}

		public static function postEmonFeeds() : void
		{
			try
			{
				// get from feed_items where syncStatus is 'pending' or 'failed' and syncAttempts <= maxSyncAttempts
				// find any where value is null, mark as 'ignored' and do no further processing on these
				// group by remoteFeedId
				// for each remoteFeedId, create a payload in the correct format to POST to remoteFeedId
				// POST the batch
				// if successful, mark each feed item syncStatus in the batch as 'success'
				// else mark each as 'failed'
				// increment syncAttempts
				// maybe add an index to feed_items since it's a bit slow
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
	}
