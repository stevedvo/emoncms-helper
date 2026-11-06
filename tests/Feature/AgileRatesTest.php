<?php
	namespace Tests\Feature;

	use App\Http\Controllers\OctopusController;
	use App\Mail\AgileRates;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use App\Services\TariffPeriods;
	use Carbon\CarbonImmutable;
	use GuzzleHttp\Client;
	use GuzzleHttp\Handler\MockHandler;
	use GuzzleHttp\HandlerStack;
	use GuzzleHttp\Psr7\Response;
	use Illuminate\Support\Facades\Mail;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class AgileRatesTest extends TestCase
	{
		use ControlPanelDatabase;

		private MockHandler $responses;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			(require database_path('migrations/2023_10_12_122805_create_api_logs_table.php'))->up();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 16:30', TariffPeriods::ZONE));
			config()->set('octopus.baseUrl', 'https://octopus.example.test/');
			config()->set('octopus.agileRates', 'rates');
			config()->set('app.admin_email', 'owner@example.test');
			Mail::fake();
			$this->responses = new MockHandler();
			$stack = HandlerStack::create($this->responses);
			$this->app->bind(Client::class, function($app, array $parameters) use ($stack)
			{
				return new Client($parameters['config'] + ['handler' => $stack]);
			});
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		#[DataProvider('fetchDates')]
		public function test_email_includes_the_full_next_day_peak_beyond_24_hours(string $date) : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse($date, TariffPeriods::ZONE));
			$this->fetch($this->rates());
			$tomorrow = CarbonImmutable::now(TariffPeriods::ZONE)->addDay()->toDateString();

			Mail::assertSent(AgileRates::class, function(AgileRates $mail) use ($tomorrow)
			{
				$peak = $mail->expensivePeriods['peak'];
				$this->assertCount(6, $peak['rates']);
				$this->assertSame($tomorrow.' 16:00', $peak['rates'][0]['valid_from_formatted']);
				$this->assertSame($tomorrow.' 19:00', $peak['rates'][5]['valid_to_formatted']);
				$this->assertSame([40, 41, 42, 43, 44, 45], array_column($peak['rates'], 'value_inc_vat'));
				$this->assertSame(42.5, $peak['most_expensive_3_hours']['average_cost']);
				$this->assertSame(43.5, $peak['most_expensive_2_hours']['average_cost']);
				$this->assertSame([], $peak['most_expensive_4_hours']['window']);
				$this->assertNull($peak['most_expensive_5_hours']['average_cost']);
				$html = $mail->render();
				$this->assertStringContainsString('Peak period: '.$tomorrow.' 16:00', $html);
				$this->assertStringContainsString($tomorrow.' 18:30', $html);
				$this->assertStringContainsString('Most Expensive 3-Hour Period [42.5 p/kWh]', $html);
				$this->assertStringContainsString('Most Expensive 4-Hour Period: unavailable', $html);

				return true;
			});
			$this->assertSame('true', Setting::where('key', 'getAgileRatesSuccess')->value('value'));
			$this->assertNotNull(Setting::where('key', 'agile_schedule')->value('value'));
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
		}

		public static function fetchDates() : array
		{
			return
			[
				'winter'       => ['2026-11-06 16:30'],
				'summer'       => ['2026-07-06 16:30'],
				'clock back'   => ['2026-10-24 16:30'],
				'clock ahead'  => ['2027-03-27 16:30'],
				'late retry'   => ['2026-11-06 23:45'],
			];
		}

		#[DataProvider('incompletePeakRates')]
		public function test_short_or_gapped_peak_data_renders_without_invalid_windows(array $missing, int $expected) : void
		{
			$rates = array_values(array_filter($this->rates(), function(array $rate) use ($missing)
			{
				$time = CarbonImmutable::parse($rate['valid_from'])->setTimezone(TariffPeriods::ZONE);

				return $time->toDateString() !== '2026-11-07' || !in_array($time->format('H:i'), $missing, true);
			}));
			$this->fetch($rates);
			Mail::assertSent(AgileRates::class, function(AgileRates $mail) use ($expected)
			{
				$peak = $mail->expensivePeriods['peak'];
				$this->assertCount($expected, $peak['rates']);
				$this->assertSame([], $peak['most_expensive_2_hours']['window']);
				$this->assertSame([], $peak['most_expensive_3_hours']['window']);
				$this->assertNull($peak['most_expensive_3_hours']['average_cost']);
				$html = $mail->render();
				$this->assertStringContainsString('Most Expensive 3-Hour Period: unavailable', $html);

				if ($expected === 0)
				{
					$this->assertStringContainsString('No half-hour rates available for this peak period.', $html);
				}

				return true;
			});
			$this->assertSame('true', Setting::where('key', 'getAgileRatesSuccess')->value('value'));
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
		}

		public static function incompletePeakRates() : array
		{
			return
			[
				'one missing half hour' => [['17:00'], 5],
				'only one half hour'    => [['16:30', '17:00', '17:30', '18:00', '18:30'], 1],
				'no peak data'          => [['16:00', '16:30', '17:00', '17:30', '18:00', '18:30'], 0],
			];
		}

		public function test_peak_email_reads_saved_times_without_changing_the_heating_peak() : void
		{
			$settings = TariffPeriods::settings();
			$settings['agile']['peak'] = ['start' => '17:00', 'end' => '20:00'];
			Setting::create(['key' => TariffPeriods::KEY, 'value' => json_encode($settings)]);
			$this->fetch($this->rates());
			Mail::assertSent(AgileRates::class, function(AgileRates $mail)
			{
				$this->assertSame('2026-11-07 17:00', $mail->expensivePeriods['peak']['start']);
				$this->assertSame('2026-11-07 20:00', $mail->expensivePeriods['peak']['end']);
				$this->assertCount(6, $mail->expensivePeriods['peak']['rates']);

				return true;
			});
			$this->assertSame('16:00', TariffPeriods::settings()['daily']['peak']['start']);
		}

		public function test_missing_cheap_rates_preserve_the_schedule_and_leave_a_clear_retry_error() : void
		{
			$previous = '{"cosy":[]}';
			Setting::create(['key' => 'agile_schedule', 'value' => $previous]);
			$this->fetch([]);
			Mail::assertNothingSent();
			$this->assertSame($previous, Setting::where('key', 'agile_schedule')->value('value'));
			$this->assertSame('false', Setting::where('key', 'getAgileRatesSuccess')->value('value'));
			$this->assertStringContainsString('Insufficient consecutive Agile rates', ActivityLog::where('level', 'error')->sole()->message);
		}

		private function fetch(array $rates) : void
		{
			$this->responses->append(new Response(200, [], json_encode(['results' => array_reverse($rates)])));
			OctopusController::getAgileRates();
		}

		private function rates() : array
		{
			$now = CarbonImmutable::now(TariffPeriods::ZONE);
			$end = $now->startOfDay()->addDays(2);
			$rates = [];

			for ($time = $now->startOfDay(); $time->isBefore($end); $time = $time->addMinutes(30))
			{
				$peak = $time->toDateString() === $now->addDay()->toDateString() && $time->hour >= 16 && $time->hour < 19;
				$rates[] =
				[
					'valid_from'    => $time->toIso8601String(),
					'valid_to'      => $time->addMinutes(30)->toIso8601String(),
					'value_inc_vat' => $peak ? 40 + ($time->hour - 16) * 2 + (int)($time->minute / 30) : 10,
				];
			}

			return $rates;
		}
	}
