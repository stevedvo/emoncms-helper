<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\APIs\EmonAPI;
	use App\APIs\NibeAPI;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Models\NibeParameter;
	use Carbon\CarbonImmutable;
	use Illuminate\Database\Eloquent\Collection;
	use Illuminate\Support\Facades\Log;

	class NibeController extends Controller
	{
		public static function GetNibeData() : void
		{
			try
			{
				$now = CarbonImmutable::now();
				$api = new NibeAPI();

				$parameterData = collect($api->getParameterData())->unique(function(array $item)
				{
					return $item['parameterId'].$item['timestamp'].$item['value'];
				});

				$nibeParameters = NibeParameter::all()->keyBy("parameterId");

				$emonPostCollection = new Collection();

				$parameterData->each(function(array $datum, int $key) use ($emonPostCollection, $nibeParameters)
				{
					if ($nibeParameters->has($datum['parameterId']))
					{
						try
						{
							$nibeFeedItem = NibeFeedItem::firstOrCreate(
							[
								'parameterId'  => $datum['parameterId'],
								'timestamp'    => CarbonImmutable::parse($datum['timestamp'])->setTimezone("UTC")->format("U")
							],
							[
								'rawValue'     => $datum['value'],
								'syncAttempts' => 0,
								'syncStatus'   => "pending",
							]);

							if ($nibeFeedItem->wasRecentlyCreated || $nibeFeedItem->syncStatus != "success")
							{
								$emonPostCollection->put($nibeParameters->get($nibeFeedItem->parameterId)->title, $nibeFeedItem);
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
				});

				if ($emonPostCollection->isEmpty())
				{
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "info",
						'message'    => "No new NIBE data found",
					]);

					return;
				}

				static::syncNibeData($emonPostCollection->all());
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

		public static function syncNibeData(array $emonPostArray) : void
		{
			$syncSuccess = false;

			foreach ($emonPostArray as $title => $nibeFeedItem)
			{
				try
				{
					$syncSuccess = EmonAPI::postInputData("local", $nibeFeedItem->timestamp, "nibe", json_encode([$title => $nibeFeedItem->rawValue]));
				}
				catch (Throwable $e)
				{
					$syncSuccess = false;

					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => "error",
						'message'    => $e->getMessage(),
					]);
				}

				$nibeFeedItem->syncAttempts++;
				$nibeFeedItem->syncStatus = $syncSuccess ? "success" : "failed";
				$nibeFeedItem->save();
			}
		}
	}
