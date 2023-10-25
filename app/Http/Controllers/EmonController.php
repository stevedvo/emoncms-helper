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
			try
			{
				$localToRemoteFeedMap = EmonFeedMap::all()->keyBy("localFeedId");

				$localFeeds = EmonAPI::getFeedList("local");

				$now = CarbonImmutable::now();
				$endTime = $now->subMinutes(5);
				$startTime = $endTime->subHours(config("emon.emonSyncPeriodHours"));
				$startTimeMilliseconds = $startTime->format("U").$startTime->format("v");
				$endTimeMilliseconds = $endTime->format("U").$endTime->format("v");

				$existingFeedItems = FeedItem::whereBetween("timestamp", [$startTimeMilliseconds, $endTimeMilliseconds])->get();

				foreach ($localFeeds as $localFeed)
				{
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
									$remoteFeedItemsMatched++;
									continue;
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
									case "UFH_temperature":
									case "UFH_humidity":
									case "UFH_battery":
									case "Rad_temperature":
									case "Rad_humidity":
									case "Rad_battery":
									{
										if ($localFeedItem->value != "null")
										{
											$localFeedItem->save();
											$newFeedItemsToSync++;
										}
									}
									break;

									case "heatmeter_DeltaT":
									{
										// need to ignore nulls unless there is an actual flowT for this timestamp in which case make the null a 0
										$localFeedItem->save();
										$newFeedItemsToSync++;
									}
									break;

									case "heatmeter_Power":
									case "heatmeter_FlowRate":
									{
										if ($localFeedItem->value == "null")
										{
											$localFeedItem->value = 0;
										}

										$localFeedItem->save();
										$newFeedItemsToSync++;
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
	}
