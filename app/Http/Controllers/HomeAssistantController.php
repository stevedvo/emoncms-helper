<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\APIs\HomeAssistantAPI;
	use App\Models\ActivityLog;
	use App\Models\NibeFeedItem;
	use App\Services\RadiatorHeatDemand;

	class HomeAssistantController extends Controller
	{
		public static function adjustHiveThermostat() : void
		{
			try
			{
				$latestPriorityNibeFeedItem = NibeFeedItem::where('parameterId', "49994")->orderBy('id', "desc")->first();

				if (!($latestPriorityNibeFeedItem instanceof NibeFeedItem))
				{
					throw new Exception("NibeFeedItem not found");
				}

				$priority = (int)$latestPriorityNibeFeedItem->rawValue;
				$radiatorHeatRequired = $priority === 30 ? RadiatorHeatDemand::current() : null;
				$targetTemp = config("hive.targetOffTemp");

				// Cooling retains its existing behaviour. Unknown heating demand falls back to priority.
				if ($priority === 60 || ($priority === 30 && $radiatorHeatRequired !== false))
				{
					$targetTemp = config("hive.targetOnTemp");
				}

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Priority is '$priority'; radiatorHeatRequired is ".json_encode($radiatorHeatRequired)."; targetTemp is ".$targetTemp."degC",
				]);

				$homeAssistant = app(HomeAssistantAPI::class);
				$homeAssistant->adjustHiveThermostat($targetTemp);
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
