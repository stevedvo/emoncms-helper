<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\Models\EmonFeed;
	use App\Models\EmonFeedMap;
	use App\Models\FeedItem;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Collection;
	use Illuminate\Support\Facades\Log;
	 
	class EmonController extends Controller
	{
		public static function SyncEmonFeeds() : void
		{
			try
			{
				$localToRemoteFeedMap = EmonFeedMap::all()->keyBy("localFeedId");

				$localFeeds = EmonAPI::getFeedList("local");

				$localEmonFeeds = new Collection;

				$now = CarbonImmutable::now();
				$endTime = $now->subMinutes(5);
				// $startTime = $endTime->subHours(3);
				$startTime = $endTime->subMinutes(3);
				$startTimeMilliseconds = $startTime->format("U").$startTime->format("v");
				$endTimeMilliseconds = $endTime->format("U").$endTime->format("v");

				foreach ($localFeeds as $localFeed)
				{
					if ($localToRemoteFeedMap->has($localFeed['id']))
					{
						$localEmonFeed = new EmonFeed;
						$localEmonFeed->fill($localFeed);

						$localFeedData = EmonAPI::getFeedData("local", $localEmonFeed->id, $startTimeMilliseconds, $endTimeMilliseconds);

						foreach ($localFeedData as $localFeedDatum)
						{
							$feedItem = new FeedItem(
							[
								'localFeedId'  => $localEmonFeed->id,
								'timestamp'    => $localFeedDatum[0],
								'value'        => $localFeedDatum[1],
								'syncAttempts' => 0,
								'syncStatus'   => 'pending',
							]);

							$localEmonFeed->addFeedItem($feedItem);
						}

						$localEmonFeeds->put($localEmonFeed->id, $localEmonFeed);
					}
				}

				$localEmonFeeds->each(function(EmonFeed $localEmonFeed, int $localFeedId) use ($localToRemoteFeedMap, $startTimeMilliseconds, $endTimeMilliseconds)
				{
					$remoteFeedId = $localToRemoteFeedMap->get($localFeedId)->remoteFeedId;

					$remoteFeedData = EmonAPI::getFeedData("remote", $remoteFeedId, $startTimeMilliseconds, $endTimeMilliseconds);

					$remoteFeedItems = new Collection;

					foreach ($remoteFeedData as $remoteFeedDatum)
					{
						$feedItem = new FeedItem(
						[
							'remoteFeedId' => $remoteFeedId,
							'timestamp'    => $remoteFeedDatum[0],
							'value'        => $remoteFeedDatum[1],
						]);

						$remoteFeedItems->put($feedItem->timestamp, $feedItem);
					}

					$feedItemsToSync = new Collection;

					$localEmonFeed->getFeedItems()->each(function(FeedItem $localFeedItem, int $timestamp) use ($remoteFeedId, $remoteFeedItems, $localToRemoteFeedMap, $feedItemsToSync)
					{
						if (!$remoteFeedItems->has($timestamp))
						{
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
										$feedItemsToSync->push($localFeedItem);
									}
								}
								break;
								
								case "heatmeter_DeltaT":
								{
									// ignore nulls unless there is an actual flowT for this timestamp in which case make the null a 0
								}
								break;
								
								case "heatmeter_Power":
								case "heatmeter_FlowRate":
								{
									// null = 0
								}
								break;

								default:
									// code...
									break;
							}

						}
					});
				});
			}
			catch (Throwable $e)
			{
				Log::error(__CLASS__."->".__FUNCTION__."(): ".$e);
			}
		}
	}
