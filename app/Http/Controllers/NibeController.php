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

				$nibeParameters = NibeParameter::all()->keyBy("parameterId");

				$api = new NibeAPI();
				$parameterData = $api->getParameterData($nibeParameters);

				$nibeFeedItems = new Collection();
				$emonPostArray = [];

				foreach ($parameterData as $datum)
				{
					$nibeFeedItem = NibeFeedItem::create(
					[
						'parameterId'  => $datum['parameterId'],
						'timestamp'    => $now->format("U"),
						'rawValue'     => $datum['rawValue'],
						'syncAttempts' => 0,
						'syncStatus'   => "pending",
					]);

					$nibeFeedItems->push($nibeFeedItem);
					$emonPostArray[$nibeParameters->get($nibeFeedItem->parameterId)->title] = $nibeFeedItem->rawValue;
				}

				static::syncNibeData($now, $emonPostArray, $nibeFeedItems);
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

		public static function syncNibeData(CarbonImmutable $timestamp, array $emonPostArray, Collection $nibeFeedItems) : void
		{
			$syncSuccess = false;

			try
			{
				$syncSuccess = EmonAPI::postInputData("local", $timestamp->format("U"), "nibe", json_encode($emonPostArray));
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

			$nibeFeedItems->each(function(NibeFeedItem $nibeFeedItem, int $i) use ($syncSuccess)
			{
				$nibeFeedItem->syncAttempts++;
				$nibeFeedItem->syncStatus = $syncSuccess ? "success" : "failed";
				$nibeFeedItem->save();
			});
		}
	}
