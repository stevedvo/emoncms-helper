<?php
	namespace Tests\Feature;

	use App\Http\Controllers\OctopusController;
	use App\Models\Setting;
	use App\Models\User;
	use App\Services\ControlSettings;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Http;
	use Illuminate\Support\Facades\Mail;
	use ReflectionMethod;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class OctopusScheduleSettingsTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			Http::preventStrayRequests();
			Mail::fake();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 00:00:00 UTC'));
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		public function test_default_offsets_preserve_the_existing_cheapest_and_cosy_windows() : void
		{
			$schedule = $this->generateSchedule();
			$expected = [
				'cheapest_3_hours' => [['2026-10-20 23:45', '2026-10-21 01:20'], ['2026-10-21 09:45', '2026-10-21 11:20']],
				'cheapest_6_hours' => [['2026-10-20 23:45', '2026-10-21 04:20'], ['2026-10-21 09:45', '2026-10-21 14:20']],
				'cosy'             => [['2026-10-20 02:45', '2026-10-20 04:20'], ['2026-10-20 11:45', '2026-10-20 13:20'], ['2026-10-20 20:45', '2026-10-20 21:20']],
			];

			foreach ($expected as $group => $windows)
			{
				foreach ($windows as $index => [$start, $end])
				{
					$this->assertSame(CarbonImmutable::parse($start, 'UTC')->timestamp, $schedule[$group][$index]['start']);
					$this->assertSame(CarbonImmutable::parse($end, 'UTC')->timestamp, $schedule[$group][$index]['end']);
					$this->assertSame(CarbonImmutable::parse($end, 'UTC')->addMinutes(100)->timestamp, $schedule[$group][$index]['tariff_end']);
				}
			}

			Http::assertNothingSent();
			Mail::assertNothingSent();
		}

		public function test_saved_offsets_are_used_on_the_next_generation_without_restarting() : void
		{
			$before = $this->generateSchedule();
			$this->actingAs(User::factory()->create());
			$this->get('/settings')->assertOk()->assertSee('Boost window: start offset')->assertSee('Boost window: end offset');
			$this->put('/settings',
			[
				'revision' => ControlSettings::revision(ControlSettings::values()),
				'values'   => ['octopus__offsetToOn' => '15', 'octopus__offsetToOff' => '30'],
			])->assertRedirect('/settings')->assertSessionHasNoErrors();
			$this->assertSame(15, ControlSettings::get('octopus.offsetToOn'));
			$this->assertSame(30, ControlSettings::get('octopus.offsetToOff'));
			$this->assertSame($before, json_decode(Setting::firstWhere('key', 'agile_schedule')->value, true));
			$after = $this->generateSchedule();

			foreach ($before as $group => $windows)
			{
				foreach ($windows as $index => $window)
				{
					$this->assertSame($window['start'] + 30 * 60, $after[$group][$index]['start']);
					$this->assertSame($window['end'] + 130 * 60, $after[$group][$index]['end']);
					$this->assertSame($window['average_cost'], $after[$group][$index]['average_cost']);
					$this->assertSame($window['tariff_end'], $after[$group][$index]['tariff_end']);
				}
			}

			Http::assertNothingSent();
			Mail::assertNothingSent();
		}

		public function test_zero_offsets_keep_the_nominal_window_boundaries() : void
		{
			Setting::create(['key' => 'control.octopus.offsetToOn', 'value' => '0']);
			Setting::create(['key' => 'control.octopus.offsetToOff', 'value' => '0']);
			$schedule = $this->generateSchedule();
			$this->assertSame(CarbonImmutable::parse('2026-10-21 00:00:00 UTC')->timestamp, $schedule['cheapest_3_hours'][0]['start']);
			$this->assertSame(CarbonImmutable::parse('2026-10-21 03:00:00 UTC')->timestamp, $schedule['cheapest_3_hours'][0]['end']);
			$this->assertSame(120 * 60, $schedule['cosy'][2]['end'] - $schedule['cosy'][2]['start']);
		}

		private function generateSchedule() : array
		{
			$periods = [];

			foreach (['overnight' => '2026-10-21 00:00:00 UTC', 'daytime' => '2026-10-21 10:00:00 UTC'] as $name => $start)
			{
				$start = CarbonImmutable::parse($start);
				$window = [];

				foreach (range(0, 11) as $index)
				{
					$window[] = ['valid_from' => $start->addMinutes(30 * $index)->format('c'), 'valid_to' => $start->addMinutes(30 * ($index + 1))->format('c')];
				}

				$periods[$name] = [
					'cheapest_3_hours' => ['average_cost' => 10, 'window' => array_slice($window, 0, 6)],
					'cheapest_6_hours' => ['average_cost' => 12, 'window' => $window],
				];
			}

			// Exercise only schedule persistence, without fetching tariff data or sending the rates email.
			(new ReflectionMethod(OctopusController::class, 'saveCheapestPeriods'))->invoke(null, $periods);

			return json_decode(Setting::firstWhere('key', 'agile_schedule')->value, true, 512, JSON_THROW_ON_ERROR);
		}
	}
