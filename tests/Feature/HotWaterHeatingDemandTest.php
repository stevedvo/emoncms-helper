<?php
	namespace Tests\Feature;

	use App\Http\Controllers\NibeController;
	use App\Models\Setting;
	use App\Services\HeatingBoostSchedule;
	use App\Services\RadiatorHeatDemand;
	use App\Services\TariffPeriods;
	use Carbon\CarbonImmutable;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class HotWaterHeatingDemandTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 10:00', 'Europe/London'));
			config()->set('weather.useForecast', true);
			config()->set('nibe.loadCompensationOn', true);
			DemandPredictionController::$rooms = ['Rad_temperature' => 24.0, 'UFH_temperature' => 22.0];
			$forecast = [];

			for ($hour = 0; $hour <= 30; $hour++)
			{
				$forecast[] = ['datetime' => CarbonImmutable::now()->addHours($hour)->toIso8601String(), 'temperature' => 14, 'humidity' => 70];
			}

			Setting::create(['key' => 'weather_data', 'value' => json_encode($forecast)]);
			$start = CarbonImmutable::now()->setTime(12, 45);
			Setting::create(['key' => 'agile_schedule', 'value' => json_encode(
			[
				'cosy'             => [['start' => $start->getTimestamp(), 'end' => $start->addHours(2)->getTimestamp()]],
				'cheapest_3_hours' => [],
				'cheapest_6_hours' => [],
			])]);
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		#[DataProvider('roomDemand')]
		public function test_current_and_upcoming_targets_cover_both_zones_without_changing_live_state(?float $rad, ?float $ufh, bool $expected) : void
		{
			DemandPredictionController::$rooms = ['Rad_temperature' => $rad, 'UFH_temperature' => $ufh];
			Setting::create(['key' => 'allowLoadCompensation', 'value' => 'false']);
			RadiatorHeatDemand::record(false);
			$before = Setting::pluck('value', 'key')->all();
			$this->assertSame($expected, DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
			$this->assertSame($before, Setting::pluck('value', 'key')->all());
		}

		public static function roomDemand() : array
		{
			return
			[
				'both rooms warm'           => [24, 22, false],
				'radiator demand now'       => [20, 22, true],
				'radiator boost demand'     => [22, 22, true],
				'UFH preheat demand'        => [24, 20.5, true],
				'missing Rad uses fallback' => [null, 22, false],
			];
		}

		public function test_missing_ufh_data_is_unknown_when_radiators_are_satisfied() : void
		{
			DemandPredictionController::$rooms['UFH_temperature'] = null;
			$this->assertNull(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		public function test_actual_heating_priority_reserves_the_dip_even_without_other_readings() : void
		{
			$points = [['parameterId' => 49994, 'value' => 30, 'timestamp' => CarbonImmutable::now()->toIso8601String()]];
			$this->assertTrue(DemandPredictionController::predict($points, CarbonImmutable::now()));
		}

		public function test_uncompensated_forecast_and_scheduled_promotion_match_the_heating_rules() : void
		{
			config()->set('nibe.loadCompensationOn', false);
			config()->set('nibe.allowBoosts', false);
			$this->assertFalse(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
			config()->set('nibe.allowBoosts', true);
			$this->assertTrue(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
			config()->set('weather.useForecast', false);
			config()->set('nibe.allowBoosts', false);
			$this->assertTrue(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		public function test_missing_schedule_or_forecast_reserves_the_dip_as_unknown() : void
		{
			Setting::where('key', 'agile_schedule')->delete();
			$this->assertNull(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
			Setting::where('key', 'weather_data')->delete();
			$this->assertNull(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		public function test_evening_decision_also_checks_the_following_morning_boost() : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::now()->setTime(22, 0));
			DemandPredictionController::$rooms['UFH_temperature'] = 20.5;
			$start = CarbonImmutable::now()->addDay()->setTime(3, 45);
			Setting::where('key', 'agile_schedule')->update(['value' => json_encode(['cosy' => [['start' => $start->getTimestamp(), 'end' => $start->addHours(2)->getTimestamp()]]])]);
			$this->assertTrue(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		public function test_prediction_follows_an_edited_afternoon_cheap_period() : void
		{
			DemandPredictionController::$rooms['Rad_temperature'] = 22;
			$values = TariffPeriods::settings();
			$values['daily']['afternoon'] = ['start' => '17:00', 'end' => '20:00'];
			$values['daily']['peak'] = ['start' => '20:00', 'end' => '23:00'];
			$values['daily']['evening'] = ['start' => '23:00', 'end' => '02:00'];
			TariffPeriods::save($values, TariffPeriods::revision(TariffPeriods::settings()), 1);
			$this->assertTrue(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		public function test_prediction_includes_dated_cheap_offers_and_saving_sessions() : void
		{
			DemandPredictionController::$rooms['Rad_temperature'] = 22;
			$values = TariffPeriods::settings();
			$values['extra'] = [['name' => 'Saving session', 'type' => 'peak', 'start' => '2026-10-24T12:30', 'end' => '2026-10-24T16:00']];
			Setting::create(['key' => TariffPeriods::KEY, 'value' => json_encode($values)]);
			$this->assertFalse(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
			$values['extra'][] = ['name' => 'Extra cheap morning', 'type' => 'cheap', 'start' => '2026-10-24T10:30', 'end' => '2026-10-24T11:30'];
			Setting::where('key', TariffPeriods::KEY)->update(['value' => json_encode($values)]);
			$this->assertTrue(DemandPredictionController::predict($this->points(), CarbonImmutable::now()));
		}

		#[DataProvider('scheduleSelection')]
		public function test_shared_boost_selection_preserves_uncompensated_temperature_branches(float $outdoor, float $average, ?string $activeWindow, bool $expected) : void
		{
			config()->set('weather.useForecast', false);
			$start = CarbonImmutable::now()->subHour()->getTimestamp();
			$end = CarbonImmutable::now()->addHour()->getTimestamp();
			$schedules = ['cheapest_3_hours' => [], 'cheapest_6_hours' => []];

			if ($activeWindow !== null)
			{
				$schedules[$activeWindow][] = ['start' => $start, 'end' => $end];
			}

			$this->assertSame($expected, HeatingBoostSchedule::activeAt($schedules, $outdoor, $average, CarbonImmutable::now()));
		}

		public static function scheduleSelection() : array
		{
			return
			[
				'cold constant'             => [0, 5, null, true],
				'cold average warm now'     => [15, 5, null, true],
				'warm stops boost'          => [12, 10, 'cheapest_6_hours', false],
				'high average three hours'  => [5, 12, 'cheapest_3_hours', true],
				'high average wrong window' => [5, 12, 'cheapest_6_hours', false],
				'cool average six hours'    => [5, 9, 'cheapest_6_hours', true],
				'cool now six hours'        => [9, 6, 'cheapest_6_hours', true],
				'cool both constant'        => [8, 6, null, true],
			];
		}

		private function points() : array
		{
			$points = [];

			foreach ([49994 => 10, 40004 => 10, 40067 => 10] as $id => $value)
			{
				$points[] = ['parameterId' => $id, 'value' => $value, 'timestamp' => CarbonImmutable::now()->toIso8601String()];
			}

			return $points;
		}
	}

	class DemandPredictionController extends NibeController
	{
		public static array $rooms;

		public static function predict(array $points, CarbonImmutable $now) : ?bool
		{
			return static::hotWaterHeatingRequired($points, $now);
		}

		protected static function hotWaterRoomTemperature(string $feed) : ?float
		{
			return self::$rooms[$feed];
		}
	}
