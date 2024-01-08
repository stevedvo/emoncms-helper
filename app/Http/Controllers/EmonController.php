<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\Models\ActivityLog;
	use App\Models\EmonFeedMap;
	use App\Models\FeedItem;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Collection;
	 
	class EmonController extends Controller
	{
		public static function SyncEmonFeeds() : void
		{
			// 1st task: get the feeds from local and from remote and compare them to find any that are missing/incorrect
			static::GetEmonFeeds();

			// 2nd task: find any feed items which have not been sync'd from local to remote and attempt to sync
			static::PostEmonFeeds();
		}

		public static function GetEmonFeeds() : void
		{
			try
			{
				$localToRemoteFeedMap = EmonFeedMap::all()->keyBy("localFeedId");
				$ignoreLocalFeedIds   = [20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43];

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

		public static function PostEmonFeeds() : void
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
