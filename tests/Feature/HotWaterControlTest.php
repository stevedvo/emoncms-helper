<?php
	namespace Tests\Feature;

	use App\APIs\NibeAPI;
	use App\Http\Controllers\NibeController;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use App\Services\HotWaterControl;
	use Carbon\CarbonImmutable;
	use Mockery;
	use PHPUnit\Framework\Attributes\DataProvider;
	use RuntimeException;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class HotWaterControlTest extends TestCase
	{
		use ControlPanelDatabase;

		private array $patches = [];
		private bool $failPatch = false;
		private NibeAPI $api;
		private HotWaterControl $control;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			config()->set('nibe.hotWaterControl', true);
			config()->set('weather.useForecast', false);
			$this->at('2026-10-24 10:00');
			$api = Mockery::mock(NibeAPI::class);
			$api->shouldReceive('setParameterData')->andReturnUsing(function(array $parameters)
			{
				$this->patches[] = $parameters;

				if ($this->failPatch)
				{
					throw new RuntimeException('Simulated controller failure.');
				}
			});
			$this->control = new HotWaterControl($api);
			$this->api = $api;
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		#[DataProvider('startWindows')]
		public function test_start_windows_thresholds_and_peak_admission(string $time, float $temperature, ?bool $heating, bool $start) : void
		{
			$this->at($time);
			$this->poll(10, $temperature, $heating);
			$this->assertSame([[47041 => $start ? 1 : 0]], $this->patches);
			$this->assertSame($start, $this->state()['request'] !== null);
		}

		public static function startWindows() : array
		{
			return
			[
				'heating permits evening'        => ['2026-10-24 22:00', 36, true, true],
				'quiet evening'                  => ['2026-10-24 22:00', 36, false, true],
				'quiet evening threshold'        => ['2026-10-24 22:00', 37, false, false],
				'early night'                    => ['2026-10-24 00:00', 36, true, true],
				'02:00 overnight check'          => ['2026-10-24 02:00', 36, true, true],
				'after old overnight cutoff'     => ['2026-10-24 02:05', 36, true, true],
				'quiet night permits recharge'   => ['2026-10-24 00:00', 36, false, true],
				'morning dip permits recharge'   => ['2026-10-24 04:30', 36, true, true],
				'early shower without recharge'  => ['2026-10-24 06:45', 36, true, true],
				'last scheduled overnight check' => ['2026-10-24 06:55', 36, false, true],
				'overnight closes at 07:00'       => ['2026-10-24 07:00', 36, true, false],
				'overnight not yet open'         => ['2026-10-24 21:55', 36, true, false],
				'early afternoon'                => ['2026-10-24 10:00', 32, true, true],
				'last early afternoon check'     => ['2026-10-24 12:00', 32, true, true],
				'heating avoids afternoon dip'   => ['2026-10-24 13:00', 32, true, false],
				'quiet afternoon waits'          => ['2026-10-24 10:00', 32, false, false],
				'quiet afternoon'                => ['2026-10-24 13:00', 32, false, true],
				'scheduler seconds at 13:00'      => ['2026-10-24 13:00:12', 32, false, true],
				'late afternoon risks peak'      => ['2026-10-24 13:05', 32, false, false],
				'ordinary afternoon threshold'   => ['2026-10-24 10:00', 33, true, false],
				'Sunday higher threshold'        => ['2026-10-25 10:00', 36, true, true],
				'Sunday exact threshold'         => ['2026-10-25 10:00', 37, true, false],
				'urgent morning'                 => ['2026-10-24 08:00', 19, true, true],
				'urgent last fitting start'       => ['2026-10-24 13:00', 19, true, true],
				'urgent too close to peak'       => ['2026-10-24 14:30', 19, true, false],
				'urgent during peak'             => ['2026-10-24 18:55', 19, true, false],
				'urgent after actual peak'       => ['2026-10-24 19:00', 19, true, true],
				'urgent strict threshold'        => ['2026-10-24 08:00', 20, true, false],
				'unknown permits overnight'      => ['2026-10-24 22:00', 36, null, true],
				'unknown permits early recharge' => ['2026-10-24 10:00', 32, null, true],
			];
		}

		public function test_request_waits_for_start_and_running_cycle_survives_boundaries_and_restart() : void
		{
			$this->at('2026-10-24 22:00');
			$this->poll(10, 36, false);
			$this->at('2026-10-24 22:05');
			$this->poll(10, 36, false);
			$this->assertSame([[47041 => 1]], $this->patches);
			$this->at('2026-10-24 22:10');
			$this->poll(20, 37);
			$this->at('2026-10-25 00:05');
			$this->control = new HotWaterControl($this->api);
			$this->poll(20, 40);
			$this->assertSame([[47041 => 1]], $this->patches);
			$this->at('2026-10-25 01:00');
			$this->poll(30, 44);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
			$this->assertSame('2026-10-25', $this->state()['completed']['overnight']);
			$this->at('2026-10-25 01:35');
			$this->poll(10, 36);
			$this->assertCount(2, $this->patches);
			$this->assertNull($this->state()['request']);
		}

		#[DataProvider('earlyShowerTemperatures')]
		public function test_completed_overnight_recharge_is_not_repeated_after_an_early_shower(float $temperature) : void
		{
			$this->at('2026-10-24 22:00');
			$this->poll(30, 36);
			$this->at('2026-10-24 22:05');
			$this->poll(20, 36);
			$this->at('2026-10-25 00:30');
			$this->poll(30, 44);
			$this->assertSame('2026-10-25', $this->state()['completed']['overnight']);
			$this->at('2026-10-25 06:45');
			$this->poll(30, $temperature);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
			$this->assertSame('Overnight recharge opportunity already completed.', $this->state()['reason']);
			$this->at('2026-10-25 22:00');
			$this->poll(30, 36);
			$this->assertSame([47041 => 1], $this->patches[2]);
			$this->assertSame('overnight:2026-10-26', $this->state()['request']['opportunity']);
		}

		public static function earlyShowerTemperatures() : array
		{
			return ['below overnight threshold' => [36], 'below urgent threshold' => [19]];
		}

		public function test_overnight_start_does_not_depend_on_the_heating_prediction() : void
		{
			$this->at('2026-10-24 23:00');
			$this->control->run($this->points(30, 36), function()
			{
				throw new RuntimeException('The overnight decision must not request a heating prediction.');
			});
			$this->assertSame([[47041 => 1]], $this->patches);
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
		}

		public function test_late_overnight_recharge_continues_beyond_07_and_is_recorded_against_its_night() : void
		{
			$this->at('2026-10-25 06:45');
			$this->poll(30, 36);
			$this->at('2026-10-25 06:50');
			$this->poll(20, 36);
			$this->at('2026-10-25 07:30');
			$this->poll(20, 40);
			$this->assertSame([[47041 => 1]], $this->patches);
			$this->at('2026-10-25 08:30');
			$this->poll(30, 44);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
			$this->assertSame('2026-10-25', $this->state()['completed']['overnight']);
			$this->assertArrayNotHasKey('afternoon', $this->state()['completed']);
		}

		public function test_never_requests_economy_while_priority_is_20_even_when_disabled_or_overlong() : void
		{
			$this->poll(20, 30);
			config()->set('nibe.hotWaterControl', false);
			$this->at('2026-10-24 18:00');
			$this->poll(20, 42);
			$this->assertSame([], $this->patches);
			$this->assertTrue(HotWaterControl::handlesControl());
			$this->at('2026-10-24 18:05');
			$this->poll(10, 44);
			$this->assertSame([[47041 => 0]], $this->patches);
			$this->at('2026-10-24 18:10');
			$this->poll(10, 44);
			$this->assertFalse(HotWaterControl::handlesControl());
			$this->assertCount(1, $this->patches);
		}

		public function test_economy_and_unchanged_decisions_are_not_repeated() : void
		{
			$this->poll(10, 40);
			$this->at('2026-10-24 10:05');
			$this->poll(10, 40);
			$this->assertSame([[47041 => 0]], $this->patches);
			$this->assertSame(1, ActivityLog::count());
		}

		public function test_adopting_an_external_run_invalidates_the_old_economy_confirmation() : void
		{
			$this->poll(10, 40);
			$this->at('2026-10-24 10:05');
			$this->poll(20, 32);
			$this->assertSame([[47041 => 0]], $this->patches);
			$this->at('2026-10-24 12:00');
			$this->poll(10, 44);
			$this->assertSame([[47041 => 0], [47041 => 0]], $this->patches);
			$this->assertSame([], $this->state()['completed']);
		}

		public function test_failed_standard_patch_is_retried_without_recording_success() : void
		{
			$this->failPatch = true;
			$this->poll(10, 32);
			$this->assertNull($this->state()['mode']);
			$this->assertSame(1, $this->state()['pendingMode']);
			$this->assertNotNull($this->state()['request']);
			$this->failPatch = false;
			$this->at('2026-10-24 10:05');
			$this->poll(10, 32);
			$this->assertSame(1, $this->state()['mode']);
			$this->assertNull($this->state()['pendingMode']);
			$this->assertSame([[47041 => 1], [47041 => 1]], $this->patches);
		}

		public function test_failed_restore_is_retried_before_completing_the_opportunity() : void
		{
			$this->poll(10, 32);
			$this->at('2026-10-24 10:05');
			$this->poll(20, 32);
			$this->failPatch = true;
			$this->at('2026-10-24 12:00');
			$this->poll(30, 44);
			$this->assertSame([], $this->state()['completed']);
			$this->assertSame(1, $this->state()['mode']);
			$this->failPatch = false;
			$this->at('2026-10-24 12:05');
			$this->poll(30, 44);
			$this->assertSame('2026-10-24', $this->state()['completed']['afternoon']);
			$this->assertSame([[47041 => 1], [47041 => 0], [47041 => 0]], $this->patches);
		}

		public function test_ambiguous_start_followed_by_production_still_restores_economy() : void
		{
			$this->poll(10, 40);
			$this->failPatch = true;
			$this->at('2026-10-24 10:05');
			$this->poll(10, 32);
			$this->failPatch = false;
			$this->at('2026-10-24 10:10');
			$this->poll(20, 32);
			$this->at('2026-10-24 12:00');
			$this->poll(10, 44);
			$this->assertSame([[47041 => 0], [47041 => 1], [47041 => 0]], $this->patches);
		}

		public function test_failed_start_times_out_without_consuming_an_opportunity() : void
		{
			$this->poll(10, 32);
			$this->at('2026-10-24 10:30');
			$this->poll(10, 32);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
			$this->assertNull($this->state()['request']);
			$this->assertSame([], $this->state()['completed']);
			$this->at('2026-10-24 10:35');
			$this->poll(10, 32);
			$this->assertCount(2, $this->patches);
			$this->at('2026-10-24 11:00');
			$this->poll(10, 32);
			$this->assertSame([47041 => 1], $this->patches[2]);
		}

		public function test_a_failed_start_is_not_retried_when_it_would_enter_peak() : void
		{
			$this->failPatch = true;
			$this->at('2026-10-24 13:00');
			$this->poll(10, 19);
			$this->failPatch = false;
			$this->at('2026-10-24 13:05');
			$this->poll(10, 19);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
			$this->assertNull($this->state()['request']);
		}

		public function test_economy_failure_is_retried_and_does_not_record_a_success() : void
		{
			$this->failPatch = true;
			$this->poll(10, 40);
			$this->assertNull($this->state()['mode']);
			$this->assertSame(0, $this->state()['pendingMode']);
			$this->failPatch = false;
			$this->at('2026-10-24 10:05');
			$this->poll(10, 40);
			$this->assertSame([[47041 => 0], [47041 => 0]], $this->patches);
			$this->assertSame(0, $this->state()['mode']);
		}

		public function test_running_recharge_is_not_stopped_at_the_tariff_peak() : void
		{
			$this->at('2026-10-24 13:00');
			$this->poll(10, 32, false);
			$this->at('2026-10-24 13:05');
			$this->poll(20, 32);
			$this->at('2026-10-24 16:05');
			$this->poll(20, 42);
			$this->assertSame([[47041 => 1]], $this->patches);
		}

		public function test_clock_change_does_not_offer_a_completed_overnight_opportunity_twice() : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T00:00:00Z'));
			$this->poll(10, 36);
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T00:05:00Z'));
			$this->poll(20, 36);
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T00:30:00Z'));
			$this->poll(10, 44);
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T01:05:00Z'));
			$this->poll(10, 36);
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
		}

		#[DataProvider('unusableReadings')]
		public function test_missing_stale_invalid_and_future_readings_never_cause_writes(string $kind) : void
		{
			$points = $this->points(10, 32);

			if ($kind === 'missing')
			{
				array_pop($points);
			}
			elseif ($kind === 'invalid')
			{
				$points[0]['value'] = 'unknown';
			}
			elseif ($kind === 'unknown priority')
			{
				$points[0]['value'] = 99;
			}
			elseif ($kind === 'stale')
			{
				foreach ($points as &$point)
				{
					$point['timestamp'] = CarbonImmutable::now()->subMinutes(11)->toIso8601String();
				}
				unset($point);
			}
			else
			{
				$points[0]['timestamp'] = CarbonImmutable::now()->addMinute()->toIso8601String();
			}

			$this->control->run($points, fn() => true);
			$this->assertSame([], $this->patches);
		}

		public static function unusableReadings() : array
		{
			return ['missing' => ['missing'], 'invalid' => ['invalid'], 'unknown priority' => ['unknown priority'], 'stale' => ['stale'], 'future' => ['future']];
		}

		public function test_old_unchanged_priority_and_charging_values_are_usable_in_a_live_poll() : void
		{
			$points = $this->points(30, 32);

			foreach ($points as &$point)
			{
				$point['timestamp'] = CarbonImmutable::now()->subHours(3)->toIso8601String();
			}
			unset($point);
			$points[] = ['parameterId' => 40940, 'value' => -166, 'timestamp' => CarbonImmutable::now()->subMinute()->toIso8601String()];
			$this->control->run($points, fn() => true);
			$this->assertSame([[47041 => 1]], $this->patches);
			$this->assertNotNull($this->state()['request']);
		}

		public function test_a_recent_setting_change_does_not_make_old_live_telemetry_fresh() : void
		{
			$points = $this->points(30, 32);

			foreach ($points as &$point)
			{
				$point['timestamp'] = CarbonImmutable::now()->subHours(3)->toIso8601String();
			}
			unset($point);
			$points[] = ['parameterId' => 47041, 'value' => 0, 'timestamp' => CarbonImmutable::now()->toIso8601String()];
			$this->control->run($points, fn() => true);
			$this->assertSame([], $this->patches);
		}

		public function test_a_poll_held_too_long_before_evaluation_cannot_change_the_controller() : void
		{
			$this->control->run($this->points(30, 32), fn() => true, null, CarbonImmutable::now()->subMinutes(11));
			$this->assertSame([], $this->patches);
		}

		public function test_identical_decisions_still_record_the_latest_evaluation_time() : void
		{
			$this->poll(10, 40);
			$first = $this->state()['lastEvaluatedAt'];
			$this->at('2026-10-24 10:05');
			$this->poll(10, 40);
			$this->assertGreaterThan($first, $this->state()['lastEvaluatedAt']);
			$this->assertSame(CarbonImmutable::now()->getTimestamp(), $this->state()['lastPolledAt']);
			$this->assertSame(1, ActivityLog::count());
			$this->assertSame([[47041 => 0]], $this->patches);
		}

		public function test_recorded_clock_change_night_starts_when_below_threshold_arrives_after_02() : void
		{
			// Real source change times from the first trial; the priority stayed at heating overnight.
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T01:00:00Z'));
			$points =
			[
				['parameterId' => 49994, 'value' => 30, 'timestamp' => '2026-10-25T00:18:41Z'],
				['parameterId' => 40014, 'value' => 37, 'timestamp' => '2026-10-25T00:09:12Z'],
				['parameterId' => 40940, 'value' => -158, 'timestamp' => '2026-10-25T00:54:40Z'],
			];
			$this->control->run($points, fn() => true);
			$this->assertStringContainsString('start threshold of 37', $this->state()['reason']);
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25T02:05:00Z'));
			$points[1] = ['parameterId' => 40014, 'value' => 36.5, 'timestamp' => '2026-10-25T01:59:55Z'];
			$this->control->run($points, fn() => true);
			$this->assertStringContainsString('Overnight recharge below 37', $this->state()['reason']);
			$this->assertSame([[47041 => 0], [47041 => 1]], $this->patches);
			$this->assertSame('overnight:2026-10-25', $this->state()['request']['opportunity']);
		}

		public function test_old_non_hot_water_sample_cannot_complete_an_observed_run() : void
		{
			$this->poll(20, 32);
			$points = $this->points(10, 44);
			$this->control->run($points, fn() => true);
			$this->assertSame([], $this->patches);
			$this->assertNotNull($this->state()['request']);
		}

		public function test_manual_mode_readback_is_reconciled_without_trusting_pre_patch_values() : void
		{
			$this->poll(10, 40);
			$this->at('2026-10-24 10:05');
			$points = $this->points(10, 40);
			$points[] = ['parameterId' => 47041, 'value' => 1, 'timestamp' => CarbonImmutable::now()->toIso8601String()];
			$this->control->run($points, fn() => true);
			$this->assertSame([[47041 => 0], [47041 => 0]], $this->patches);
		}

		public function test_fresh_readback_can_resolve_an_ambiguous_restore_response() : void
		{
			$this->failPatch = true;
			$this->poll(10, 40);
			$this->failPatch = false;
			$this->at('2026-10-24 10:05');
			$points = $this->points(10, 40);
			$points[] = ['parameterId' => 47041, 'value' => 0, 'timestamp' => CarbonImmutable::now()->toIso8601String()];
			$this->control->run($points, fn() => true);
			$this->assertSame([[47041 => 0]], $this->patches);
			$this->assertSame(0, $this->state()['mode']);
			$this->assertNull($this->state()['pendingMode']);
		}

		public function test_disabling_an_unstarted_request_cleans_up_before_legacy_control() : void
		{
			Setting::create(['key' => 'hotWaterSet', 'value' => 'true']);
			$this->poll(10, 32);
			config()->set('nibe.hotWaterControl', false);
			$this->at('2026-10-24 10:05');
			$this->poll(10, 32);
			$this->assertTrue(HotWaterControl::handlesControl());
			$this->at('2026-10-24 10:10');
			$this->poll(10, 32);
			$this->assertFalse(HotWaterControl::handlesControl());
			$this->assertSame('false', Setting::where('key', 'hotWaterSet')->value('value'));
			$this->assertSame([[47041 => 1], [47041 => 0]], $this->patches);
		}

		public function test_out_of_order_hot_water_priority_does_not_confirm_a_new_start() : void
		{
			$this->poll(10, 32);
			$this->at('2026-10-24 10:05');
			$points = $this->points(20, 32);
			$points[0]['timestamp'] = CarbonImmutable::now()->subMinutes(6)->toIso8601String();
			$this->control->run($points, fn() => true);
			$this->assertNull($this->state()['request']['startedAt']);
			$this->assertSame([[47041 => 1]], $this->patches);
		}

		public function test_urgent_recharge_can_override_a_used_opportunity_but_has_a_recovery_interval() : void
		{
			$this->poll(10, 32);
			$this->at('2026-10-24 10:05');
			$this->poll(20, 32);
			$this->at('2026-10-24 11:00');
			$this->poll(10, 44);
			$this->at('2026-10-24 11:05');
			$this->poll(10, 19);
			$this->assertCount(2, $this->patches);
			$this->at('2026-10-24 11:30');
			$this->poll(10, 19);
			$this->assertSame([47041 => 1], $this->patches[2]);
		}

		public function test_shorter_duration_allows_a_later_afternoon_start_and_ignores_heating_peak_offsets() : void
		{
			config()->set('nibe.hotWaterDurationMinutes', 120);
			config()->set('nibe.peakImportEndOffsetMinutes', -25);
			$this->at('2026-10-24 14:00');
			$this->poll(10, 32, false);
			$this->assertSame([[47041 => 1]], $this->patches);
		}

		private function poll(int $priority, float $temperature, ?bool $heating = true) : void
		{
			$this->control->run($this->points($priority, $temperature), fn() => $heating);
		}

		private function points(int $priority, float $temperature) : array
		{
			return
			[
				['parameterId' => 49994, 'value' => $priority, 'timestamp' => CarbonImmutable::now()->toIso8601String()],
				['parameterId' => 40014, 'value' => $temperature, 'timestamp' => CarbonImmutable::now()->toIso8601String()],
			];
		}

		private function at(string $time) : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'Europe/London'));
		}

		private function state() : array
		{
			return json_decode(Setting::where('key', HotWaterControl::STATE_KEY)->value('value'), true, 512, JSON_THROW_ON_ERROR);
		}
	}
