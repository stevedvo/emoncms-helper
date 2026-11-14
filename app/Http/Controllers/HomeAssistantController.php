<?php
	namespace App\Http\Controllers;

	use Exception;
	use Throwable;
	use App\Services\ControlSettings;
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
				if (!ControlSettings::get('nibe.heatRadZone'))
				{
					$targetTemp = ControlSettings::get('hive.targetOffTemp');
					app(HomeAssistantAPI::class)->adjustHiveThermostat($targetTemp);
					RadiatorHeatDemand::record(false, null, true);
					ActivityLog::create(
					[
						'controller' => __CLASS__,
						'method'     => __FUNCTION__,
						'level'      => 'info',
						'message'    => 'Heat Rad Zone is off. Requested Hive target '.$targetTemp.'degC through Home Assistant.',
					]);

					return;
				}

				$latestPriorityNibeFeedItem = NibeFeedItem::where('parameterId', "49994")->orderBy('id', "desc")->first();

				if (!($latestPriorityNibeFeedItem instanceof NibeFeedItem))
				{
					throw new Exception("NibeFeedItem not found");
				}

				$priority = (int)$latestPriorityNibeFeedItem->rawValue;
				$radiatorHeatRequired = $priority === 30 ? RadiatorHeatDemand::current() : null;
				$targetTemp = ControlSettings::get('hive.targetOffTemp');

				// Cooling retains its existing behaviour. Unknown heating demand falls back to priority.
				if ($priority === 60 || ($priority === 30 && $radiatorHeatRequired !== false))
				{
					$targetTemp = ControlSettings::get('hive.targetOnTemp');
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
