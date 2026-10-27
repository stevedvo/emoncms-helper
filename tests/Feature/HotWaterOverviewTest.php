<?php
	namespace Tests\Feature;

	use App\Models\NibeFeedItem;
	use App\Models\Setting;
	use App\Models\User;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\DB;
	use Illuminate\Support\Facades\Http;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class HotWaterOverviewTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			Http::preventStrayRequests();
			$this->actingAs(User::factory()->create());
		}

		public function test_hot_water_precedes_nibe_readings_and_handles_missing_values() : void
		{
			$this->get('/')->assertOk()->assertSeeInOrder(['Hot Water', 'Hot water charging', 'Hot water top', 'Control status', 'Not yet recorded.', 'NIBE readings'])
				->assertSee('App control disabled')->assertSee('No reading yet');
			$this->assertSame(0, Setting::count());
			Http::assertNothingSent();
		}

		public function test_latest_temperatures_and_control_state_are_readable_without_changing_saved_values() : void
		{
			config()->set('nibe.hotWaterControl', true);
			DB::table('nibe_parameters')->insert(
			[
				['parameterId' => 40014, 'title' => 'hot water charging', 'unit' => 'degC'],
				['parameterId' => 40013, 'title' => 'hot water top', 'unit' => 'degC'],
			]);
			foreach ([[40014, 35.5, '2026-10-26T07:00:00Z'], [40014, 41.5, '2026-10-26T08:00:00Z'], [40013, 46.5, '2026-10-26T07:55:00Z']] as [$id, $temperature, $time])
			{
				NibeFeedItem::create(
				[
					'parameterId' => $id,
					'timestamp'   => CarbonImmutable::parse($time)->getTimestamp(),
					'rawValue'    => $temperature,
					'syncStatus'  => 'success',
				]);
			}

			$state =
			[
				'managed'                 => true,
				'mode'                    => 1,
				'confirmedAt'             => $this->stamp('2026-10-26T07:00:00Z'),
				'pendingMode'             => 0,
				'request'                 =>
				[
					'opportunity'         => 'overnight:2026-10-26',
					'requestedAt'         => $this->stamp('2026-10-26T06:40:00Z'),
					'startedAt'           => $this->stamp('2026-10-26T06:45:00Z'),
					'lastHotWaterSample'  => $this->stamp('2026-10-26T06:41:00Z'),
					'afterPrioritySample' => $this->stamp('2026-10-26T06:35:00Z'),
				],
				'completed'               =>
				[
					'overnight' => '2026-10-25',
					'afternoon' => '2026-10-25',
				],
				'retryAfter'              => 0,
				'lastCompletedAt'         => $this->stamp('2026-10-25T12:00:00Z'),
				'lastCompletedPriorityAt' => $this->stamp('2026-10-25T11:58:00Z'),
				'reason'                  => 'Hot water production observed; waiting for it to finish.',
				'lastEvaluatedAt'         => $this->stamp('2026-10-26T08:00:00Z'),
				'lastPolledAt'            => $this->stamp('2026-10-26T07:59:00Z'),
				'lastTelemetryAt'         => $this->stamp('2026-10-26T07:58:00Z'),
			];
			Setting::create(['key' => 'hotWaterControl', 'value' => json_encode($state)]);
			$before = Setting::pluck('value', 'key')->all();
			$this->get('/')->assertOk()->assertSee('App control enabled')->assertSee('41.5')->assertSee('46.5')->assertDontSee('35.5')
				->assertSee('26 Oct 2026, 08:00:00 GMT')->assertSee('Standard / normal')->assertSee('Economy')->assertSee('Production observed')
				->assertSee('Overnight · 26 Oct 2026')->assertSee('Hot water production observed; waiting for it to finish.')
				->assertSee('Last completed overnight recharge')->assertSee('25 Oct 2026')->assertSee('No retry delay')
				->assertDontSee((string)$state['lastEvaluatedAt'])->assertDontSee('afterPrioritySample');
			$this->assertSame($before, Setting::pluck('value', 'key')->all());
			$this->assertSame(3, NibeFeedItem::count());
			Http::assertNothingSent();
		}

		public function test_partial_idle_state_preserves_economy_and_distinguishes_the_two_01_hours() : void
		{
			Setting::create(['key' => 'hotWaterControl', 'value' => json_encode(
			[
				'managed'         => false,
				'mode'            => 0,
				'request'         => null,
				'lastCompletedAt' => $this->stamp('2026-10-25T00:15:00Z'),
				'lastEvaluatedAt' => $this->stamp('2026-10-25T01:15:00Z'),
			])]);
			$this->get('/')->assertOk()->assertSee('Economy')->assertSee('None')->assertSee('Not recorded')
				->assertSee('25 Oct 2026, 01:15:00 BST')->assertSee('25 Oct 2026, 01:15:00 GMT');
		}

		public function test_requested_recharge_and_retry_time_are_shown_without_claiming_production_started() : void
		{
			Setting::create(['key' => 'hotWaterControl', 'value' => json_encode(
			[
				'mode'        => null,
				'pendingMode' => 1,
				'request'     =>
				[
					'opportunity' => 'afternoon:2026-10-26',
					'requestedAt' => $this->stamp('2026-10-26T10:00:00Z'),
					'startedAt'   => null,
				],
				'retryAfter'  => $this->stamp('2026-10-26T10:30:00Z'),
			])]);
			$this->get('/')->assertOk()->assertSee('Not confirmed')->assertSee('Standard / normal')->assertSee('Requested; waiting for production')
				->assertSee('Afternoon · 26 Oct 2026')->assertSee('26 Oct 2026, 10:30:00 GMT');
		}

		#[DataProvider('invalidStates')]
		public function test_unreadable_saved_state_does_not_break_the_overview(string $value) : void
		{
			Setting::create(['key' => 'hotWaterControl', 'value' => $value]);
			$this->get('/')->assertOk()->assertSee('Unavailable: the saved control state could not be read.')->assertSee('NIBE readings');
		}

		public static function invalidStates() : array
		{
			return ['bad JSON' => ['{'], 'scalar' => ['false'], 'list' => ['[1,2]']];
		}

		public function test_malformed_nested_fields_are_safe_and_decision_text_is_escaped() : void
		{
			$reason = '<script>alert("untrusted")</script>';
			Setting::create(['key' => 'hotWaterControl', 'value' => json_encode(
			[
				'reason'          => $reason,
				'mode'            => ['bad'],
				'request'         => 'bad',
				'completed'       => 'bad',
				'lastEvaluatedAt' => ['bad'],
			])]);
			$this->get('/')->assertOk()->assertSee($reason)->assertDontSee($reason, false)->assertSee('Unknown')->assertSee('Not recorded');
		}

		private function stamp(string $time) : int
		{
			return CarbonImmutable::parse($time)->getTimestamp();
		}
	}
