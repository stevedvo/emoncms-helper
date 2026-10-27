<?php
	namespace Tests\Feature;

	use App\Http\Controllers\NibeController;
	use App\Models\Setting;
	use App\Models\User;
	use App\Services\ControlSettings;
	use Carbon\Carbon;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Http;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class PeakImportSettingsTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			Http::preventStrayRequests();
		}

		#[DataProvider('peakTimes')]
		public function test_default_peak_window_starts_at_1600_and_preserves_the_1835_finish(string $time, bool $expected) : void
		{
			$this->assertSame($expected, NibeController::isPeakImport(CarbonImmutable::parse($time)));
			Http::assertNothingSent();
		}

		public static function peakTimes() : array
		{
			return [
				'before former start' => ['2026-10-22 14:35:00 Europe/London', false],
				'former start is no longer peak' => ['2026-10-22 14:40:00 Europe/London', false],
				'last second before peak' => ['2026-10-22 15:59:59 Europe/London', false],
				'peak start included' => ['2026-10-22 16:00:00 Europe/London', true],
				'during peak' => ['2026-10-22 17:00:00 Europe/London', true],
				'peak end included' => ['2026-10-22 18:35:00 Europe/London', true],
				'after peak end' => ['2026-10-22 18:35:01 Europe/London', false],
				'nominal tariff end' => ['2026-10-22 19:00:00 Europe/London', false],
				'summer UTC start' => ['2026-10-22 15:00:00 UTC', true],
				'summer UTC end' => ['2026-10-22 17:35:00 UTC', true],
				'winter UTC before start' => ['2026-10-29 15:59:59 UTC', false],
				'winter UTC start' => ['2026-10-29 16:00:00 UTC', true],
				'winter UTC end' => ['2026-10-29 18:35:00 UTC', true],
				'winter UTC after end' => ['2026-10-29 18:35:01 UTC', false],
				'DST change day' => ['2026-10-25 16:00:00 UTC', true],
			];
		}

		public function test_both_offsets_are_editable_and_used_without_restarting_or_regenerating_boost_schedules() : void
		{
			$this->actingAs(User::factory()->create());
			$this->get('/settings')->assertOk()->assertSee('Peak window: start offset')->assertSee('Peak window: end offset');
			$time = CarbonImmutable::parse('2026-10-22 14:40:00 Europe/London');
			$this->assertFalse(NibeController::isPeakImport($time));
			$this->saveOffsets(-80, 0);
			$this->assertSame(-80, ControlSettings::get('nibe.peakImportStartOffsetMinutes'));
			$this->assertSame(0, ControlSettings::get('nibe.peakImportEndOffsetMinutes'));
			$this->assertTrue(NibeController::isPeakImport($time));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 19:00:00 Europe/London')));
			$this->assertFalse(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 19:00:01 Europe/London')));
			$this->saveOffsets(0, -25);
			$this->assertFalse(NibeController::isPeakImport($time));
			$this->assertFalse(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 18:40:00 Europe/London')));
			$this->assertNull(Setting::firstWhere('key', 'agile_schedule'));
			Http::assertNothingSent();
		}

		public function test_positive_offsets_move_both_boundaries_later() : void
		{
			Setting::create(['key' => 'control.nibe.peakImportStartOffsetMinutes', 'value' => '30']);
			Setting::create(['key' => 'control.nibe.peakImportEndOffsetMinutes', 'value' => '30']);
			$this->assertFalse(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 16:29:59 Europe/London')));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 16:30:00 Europe/London')));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 19:30:00 Europe/London')));
			$this->assertFalse(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 19:30:01 Europe/London')));
		}

		public function test_offset_limits_and_a_one_minute_window_are_allowed() : void
		{
			$this->actingAs(User::factory()->create());
			ControlSettings::initialize();
			$this->saveOffsets(-180, 180);
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 13:00:00 Europe/London')));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 22:00:00 Europe/London')));
			$this->saveOffsets(179, 0);
			$this->assertFalse(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 18:58:59 Europe/London')));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 18:59:00 Europe/London')));
			$this->assertTrue(NibeController::isPeakImport(CarbonImmutable::parse('2026-10-22 19:00:00 Europe/London')));
		}

		public function test_mutable_and_immutable_input_times_are_not_changed() : void
		{
			foreach ([Carbon::class, CarbonImmutable::class] as $clock)
			{
				$time = $clock::parse('2026-10-22 15:00:00 UTC');
				$this->assertTrue(NibeController::isPeakImport($time));
				$this->assertSame('2026-10-22 15:00:00 UTC', $time->format('Y-m-d H:i:s e'));
			}
		}

		private function saveOffsets(int $start, int $end) : void
		{
			$this->put('/settings',
			[
				'revision' => ControlSettings::revision(ControlSettings::values()),
				'values'   =>
				[
					'nibe__peakImportStartOffsetMinutes' => $start,
					'nibe__peakImportEndOffsetMinutes'   => $end,
				],
			])->assertRedirect('/settings')->assertSessionHasNoErrors();
		}
	}
