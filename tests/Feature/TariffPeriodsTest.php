<?php
	namespace Tests\Feature;

	use App\Http\Controllers\NibeController;
	use App\Http\Controllers\OctopusController;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use App\Models\User;
	use App\Services\ControlSettings;
	use App\Services\HeatingBoostSchedule;
	use App\Services\TariffPeriods;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Http;
	use Illuminate\Support\Facades\Mail;
	use PHPUnit\Framework\Attributes\DataProvider;
	use ReflectionMethod;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class TariffPeriodsTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27 10:00', TariffPeriods::ZONE));
			Http::preventStrayRequests();
			Mail::fake();
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		public function test_tariff_editor_requires_sign_in_and_displays_the_defaults() : void
		{
			$this->put('/settings/tariff', $this->payload())->assertRedirect('/login');
			$this->actingAs(User::factory()->create());
			$this->get('/settings')->assertOk()->assertSee('Tariff periods')->assertSee('Dated extra windows')->assertSee('value="04:00"', false)->assertSee('value="19:00"', false)->assertSee('Save tariff periods');
			$this->assertNull(Setting::firstWhere('key', TariffPeriods::KEY));
		}

		public function test_saving_tariff_times_updates_peak_checks_and_existing_cosy_schedule_immediately() : void
		{
			Setting::create(['key' => 'agile_schedule', 'value' => json_encode(['cheapest_3_hours' => [['start' => 123, 'end' => 456]], 'cosy' => []])]);
			$this->actingAs(User::factory()->create());
			$payload = $this->payload();
			$payload['tariff']['daily']['peak'] = ['start' => '17:00', 'end' => '20:00'];
			$payload['tariff']['daily']['afternoon'] = ['start' => '12:00', 'end' => '15:00'];
			$this->put('/settings/tariff', $payload)->assertSessionHasNoErrors()->assertRedirect(route('settings').'#tariff-periods');
			$this->assertFalse(NibeController::isPeakImport($this->time('2026-10-27 16:30')));
			$this->assertTrue(NibeController::isPeakImport($this->time('2026-10-27 17:00')));
			$this->assertTrue(NibeController::isPeakImport($this->time('2026-10-27 19:35')));
			$this->assertFalse(NibeController::isPeakImport($this->time('2026-10-27 19:36')));
			$schedule = json_decode(Setting::where('key', 'agile_schedule')->value('value'), true);
			$this->assertSame([['start' => 123, 'end' => 456]], $schedule['cheapest_3_hours']);
			$this->assertSame($this->time('2026-10-27 11:45')->timestamp, $schedule['cosy'][1]['start']);
			$this->assertSame($this->time('2026-10-27 13:20')->timestamp, $schedule['cosy'][1]['end']);
			$this->assertSame(1, ActivityLog::where('controller', TariffPeriods::class)->count());
			Http::assertNothingSent();
			Mail::assertNothingSent();
		}

		public function test_editing_during_an_active_cosy_period_keeps_the_current_occurrence() : void
		{
			CarbonImmutable::setTestNow($this->time('2026-10-27 13:15'));
			$this->assertSame($this->time('2026-10-27 12:45')->timestamp, TariffPeriods::nextCosyWindows(CarbonImmutable::now(), true)[1]['start']);
		}

		public function test_dated_offers_take_precedence_apply_exact_boundaries_and_expire_without_a_job() : void
		{
			$this->storeEvents([
				$this->event('cheap', '2026-10-27T16:30', '2026-10-27T17:30'),
				$this->event('peak', '2026-10-27T13:30', '2026-10-27T14:00'),
			]);
			foreach (['2026-10-27 16:30', '2026-10-27 17:29:59'] as $clock)
			{
				$time = $this->time($clock);
				$this->assertFalse(NibeController::isPeakImport($time));
				$this->assertTrue(HeatingBoostSchedule::activeAt([], 15, 15, $time));
				CarbonImmutable::setTestNow($time);
				$this->assertTrue(NibeController::isBoostActive(15, 15));
			}
			$this->assertTrue(NibeController::isPeakImport($this->time('2026-10-27 17:30')));
			$this->assertTrue(NibeController::isPeakImport($this->time('2026-10-27 13:30')));
			$this->assertFalse(HeatingBoostSchedule::activeAt(['cosy' => [['start' => $this->time('2026-10-27 13:00')->timestamp, 'end' => $this->time('2026-10-27 16:00')->timestamp]]], 10, 10, $this->time('2026-10-27 13:45')));
			$this->assertFalse(NibeController::isPeakImport($this->time('2026-10-27 14:00')));
			$this->assertNull(TariffPeriods::extraTypeAt($this->time('2026-10-28 16:45')));
		}

		public function test_hot_water_checks_the_whole_duration_against_saving_sessions_and_uses_no_heating_offsets() : void
		{
			$this->storeEvents([$this->event('peak', '2026-10-27T12:00', '2026-10-27T13:00')]);
			$this->assertFalse(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 10:00'), 180));
			$this->assertTrue(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 09:00:12'), 180));
			$this->assertTrue(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 13:00:12'), 180));
			$this->assertFalse(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 18:40'), 180));
			$this->assertTrue(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 19:00'), 180));
		}

		public function test_a_cheap_override_only_removes_its_own_part_of_the_daily_peak() : void
		{
			$this->storeEvents([$this->event('cheap', '2026-10-27T16:00', '2026-10-27T18:00')]);
			$this->assertTrue(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 15:00'), 180));
			$this->assertFalse(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 15:01'), 180));
			$this->assertFalse(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 17:00'), 180));
		}

		public function test_midnight_and_clock_changes_preserve_london_wall_times() : void
		{
			foreach (['2026-10-24', '2026-10-25', '2027-03-28'] as $date)
			{
				[$start, $end] = TariffPeriods::window($this->time($date), ['start' => '22:00', 'end' => '07:00']);
				$this->assertSame('22:00', $start->format('H:i'));
				$this->assertSame('07:00', $end->format('H:i'));
				$this->assertSame($start->addDay()->toDateString(), $end->toDateString());
			}
			$this->storeEvents([$this->event('peak', '2026-10-27T23:30', '2026-10-28T01:30')]);
			$this->assertTrue(NibeController::isPeakImport($this->time('2026-10-28 00:30')));
			$this->assertFalse(TariffPeriods::hotWaterCanFinish($this->time('2026-10-27 22:00'), 120));
		}

		public function test_stale_tariff_edits_do_not_overwrite_another_sessions_changes() : void
		{
			$this->actingAs(User::factory()->create());
			$payload = $this->payload();
			$payload['tariff']['fetch'] = '17:00';
			$this->put('/settings/tariff', $payload)->assertSessionHasNoErrors();
			$payload['tariff']['fetch'] = '18:00';
			$this->put('/settings/tariff', $payload)->assertSessionHasErrors('tariff_revision');
			$this->assertSame('17:00', TariffPeriods::settings()['fetch']);
		}

		public function test_the_daily_fetch_time_comes_from_saved_settings() : void
		{
			$values = TariffPeriods::settings();
			$values['fetch'] = '17:45';
			Setting::create(['key' => TariffPeriods::KEY, 'value' => json_encode($values)]);
			TariffFetchController::$fetches = 0;
			CarbonImmutable::setTestNow($this->time('2026-10-27 17:44'));
			TariffFetchController::tryGetAgileRates();
			$this->assertSame(0, TariffFetchController::$fetches);
			CarbonImmutable::setTestNow($this->time('2026-10-27 17:45'));
			TariffFetchController::tryGetAgileRates();
			$this->assertSame(1, TariffFetchController::$fetches);
		}

		public function test_agile_daytime_analysis_still_has_six_hours_when_the_clocks_go_back() : void
		{
			$now = $this->time('2026-10-24 16:30');
			CarbonImmutable::setTestNow($now);
			[$start, $end] = TariffPeriods::window($now->addDay(), TariffPeriods::settings()['agile']['daytime']);
			$rates = [];

			for ($time = $start; $time->isBefore($end); $time = $time->addMinutes(30))
			{
				$rates[] =
				[
					'valid_from'    => $time->toIso8601String(),
					'valid_to'      => $time->addMinutes(30)->toIso8601String(),
					'value_inc_vat' => 10,
				];
			}

			$result = (new ReflectionMethod(OctopusController::class, 'findCheapestPeriods'))->invoke(null, $rates, ['daytime' => ['start' => $start, 'end' => $end]]);
			$this->assertCount(12, $result['daytime']['cheapest_6_hours']['window']);
			$this->assertSame($end->toIso8601String(), $result['daytime']['cheapest_6_hours']['window'][11]['valid_to']);
		}

		public function test_dated_windows_can_be_saved_reloaded_and_all_removed() : void
		{
			$this->actingAs(User::factory()->create());
			$payload = $this->payload();
			$payload['tariff']['extra'] = [$this->event('cheap', '2026-10-27T10:30', '2026-10-27T11:30')];
			$this->put('/settings/tariff', $payload)->assertSessionHasNoErrors();
			$this->get('/settings')->assertOk()->assertSee('value="Cheap offer"', false)->assertSee('value="2026-10-27T10:30"', false);
			$this->assertSame('cheap', TariffPeriods::extraTypeAt($this->time('2026-10-27 11:00')));
			$payload = $this->payload();
			unset($payload['tariff']['extra']);
			$this->put('/settings/tariff', $payload)->assertSessionHasNoErrors();
			$this->assertSame([], TariffPeriods::settings()['extra']);
			$this->assertNull(TariffPeriods::extraTypeAt($this->time('2026-10-27 11:00')));
		}

		public function test_operating_offsets_are_validated_against_the_saved_tariff_durations() : void
		{
			$this->actingAs(User::factory()->create());
			$settings = TariffPeriods::settings();
			$settings['daily']['morning']['end'] = '05:30';
			$settings['daily']['peak']['end'] = '17:00';
			Setting::create(['key' => TariffPeriods::KEY, 'value' => json_encode($settings)]);
			$this->put('/settings', ['revision' => ControlSettings::revision(ControlSettings::values()), 'values' => ['octopus__offsetToOff' => -120]])->assertSessionHasErrors('values.octopus__offsetToOff');
			$this->put('/settings', ['revision' => ControlSettings::revision(ControlSettings::values()), 'values' => ['nibe__peakImportStartOffsetMinutes' => 80, 'nibe__peakImportEndOffsetMinutes' => 0]])->assertSessionHasErrors('values.nibe__peakImportEndOffsetMinutes');
			$this->assertSame(-100, ControlSettings::get('octopus.offsetToOff'));
			$this->assertSame(0, ControlSettings::get('nibe.peakImportStartOffsetMinutes'));
		}

		#[DataProvider('invalidPeriods')]
		public function test_invalid_windows_are_rejected_without_any_partial_save(string $path, mixed $value, string $error) : void
		{
			$this->actingAs(User::factory()->create());
			$payload = $this->payload();
			data_set($payload, $path, $value);
			$this->put('/settings/tariff', $payload)->assertSessionHasErrors($error);
			$this->assertNull(Setting::firstWhere('key', TariffPeriods::KEY));
			$this->assertSame(0, ActivityLog::count());
		}

		public static function invalidPeriods() : array
		{
			return [
				'invalid time'            => ['tariff.daily.morning.start', '25:00', 'tariff.daily.morning.start'],
				'zero duration'           => ['tariff.daily.morning.end', '04:00', 'tariff.daily.morning.end'],
				'boost offsets too large' => ['tariff.daily.morning.end', '05:00', 'tariff.daily.morning.end'],
				'peak offsets too large'  => ['tariff.daily.peak.end', '16:20', 'tariff.daily.peak.end'],
				'overlapping daily'       => ['tariff.daily.afternoon.end', '17:00', 'tariff.daily.peak.start'],
				'overnight must cross'    => ['tariff.hot_water.overnight.end', '23:00', 'tariff.hot_water.overnight.end'],
				'reversed daytime'       => ['tariff.hot_water.quiet.end', '12:00', 'tariff.hot_water.quiet.end'],
				'short Agile search'      => ['tariff.agile.daytime.end', '12:00', 'tariff.agile.daytime.end'],
				'fetch before next range' => ['tariff.fetch', '09:00', 'tariff.agile.daytime.end'],
				'invalid event type'      => ['tariff.extra', [['name' => 'Offer', 'type' => 'unknown', 'start' => '2026-10-27T10:00', 'end' => '2026-10-27T11:00']], 'tariff.extra.0.type'],
				'event end before start'  => ['tariff.extra', [['name' => 'Offer', 'type' => 'cheap', 'start' => '2026-10-27T11:00', 'end' => '2026-10-27T10:00']], 'tariff.extra.0.end'],
				'missing spring hour'     => ['tariff.extra', [['name' => 'Offer', 'type' => 'cheap', 'start' => '2027-03-28T01:30', 'end' => '2027-03-28T03:00']], 'tariff.extra.0.start'],
				'overlapping events'      => ['tariff.extra', [['name' => 'Offer', 'type' => 'cheap', 'start' => '2026-10-27T10:00', 'end' => '2026-10-27T12:00'], ['name' => 'Saving', 'type' => 'peak', 'start' => '2026-10-27T11:00', 'end' => '2026-10-27T13:00']], 'tariff.extra.1.start'],
			];
		}

		private function payload() : array
		{
			$values = TariffPeriods::settings();

			return ['tariff_revision' => TariffPeriods::revision($values), 'tariff' => $values];
		}

		private function time(string $value) : CarbonImmutable
		{
			return CarbonImmutable::parse($value, TariffPeriods::ZONE);
		}

		private function event(string $type, string $start, string $end) : array
		{
			return ['name' => ucfirst($type).' offer', 'type' => $type, 'start' => $start, 'end' => $end];
		}

		private function storeEvents(array $events) : void
		{
			$settings = TariffPeriods::settings();
			$settings['extra'] = $events;
			Setting::create(['key' => TariffPeriods::KEY, 'value' => json_encode($settings, JSON_THROW_ON_ERROR)]);
		}
	}

	class TariffFetchController extends OctopusController
	{
		public static int $fetches = 0;

		public static function getAgileRates() : void
		{
			self::$fetches++;
		}
	}
