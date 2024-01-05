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
				$parameterData = $api->getParameterData();

				$nibeParameters = NibeParameter::all()->keyBy("parameterId");

				$emonPostArray = [];

				foreach ($parameterData as $datum)
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

							if ($nibeFeedItem->wasRecentlyCreated)
							{
								$emonPostArray[$nibeParameters->get($nibeFeedItem->parameterId)->title] = $nibeFeedItem;
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

				Log::info($emonPostArray);exit;

				// static::syncNibeData($emonPostArray);
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
					/* need to confirm what needs to go into the json_encode function */
					// $syncSuccess = EmonAPI::postInputData("local", $nibeFeedItem->timestamp, "nibe", json_encode($emonPostArray));
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

				$nibeFeedItem->syncAttempts++;
				$nibeFeedItem->syncStatus = $syncSuccess ? "success" : "failed";
				$nibeFeedItem->save();
			}
		}
	}
