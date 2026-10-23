<?php
	namespace Tests\Feature;

	use App\Http\Controllers\NibeController;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use GuzzleHttp\Client;
	use GuzzleHttp\Handler\MockHandler;
	use GuzzleHttp\HandlerStack;
	use GuzzleHttp\Middleware;
	use GuzzleHttp\Psr7\Response;
	use Illuminate\Database\Eloquent\Collection;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class CoolingOffsetTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			(require database_path('migrations/2023_10_12_122805_create_api_logs_table.php'))->up();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 12:00:00 UTC'));
			config()->set('nibe.functionUrl', 'https://nibe.example.test/v2');
			config()->set('nibe.deviceId', 'test-device');
			config()->set('nibe.dmTargetCooling', 75);
			config()->set('nibe.offsetFactor', 2);
			config()->set('nibe.cheapMode', false);
			Setting::create(['key' => 'nibe_token_current', 'value' => 'fake-test-token']);
			Setting::create(['key' => 'nibe_token_expiry', 'value' => (string)CarbonImmutable::now()->addHour()->getTimestamp()]);
		}

		protected function tearDown() : void
		{
			CarbonImmutable::setTestNow();
			parent::tearDown();
		}

		#[DataProvider('coolingScenarios')]
		public function test_cooling_writes_respect_saved_limits_after_weather_dewpoint_adjustments(int $current, int $minimum, int $maximum, float $dewpoint, int $offsetChange, int $expected) : void
		{
			Setting::create(['key' => 'control.nibe.offsetMinimum', 'value' => (string)$minimum]);
			Setting::create(['key' => 'control.nibe.offsetMaximum', 'value' => (string)$maximum]);
			$weather =
			[
				'datetime'    => CarbonImmutable::now()->format('c'),
				'temperature' => $dewpoint + 2,
				'humidity'    => 90,
			];
			Setting::create(['key' => 'weather_data', 'value' => json_encode([$weather], JSON_THROW_ON_ERROR)]);
			$requests = [];
			$stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"48739":"modified"}')]));
			$stack->push(Middleware::history($requests));
			$this->app->bind(Client::class, function($app, array $parameters) use ($stack)
			{
				return new Client($parameters['config'] + ['handler' => $stack]);
			});

			CoolingOffsetController::dmOverride(new Collection(
			[
				'40004' => 26,
				'40067' => 26,
				'40071' => 18 + 2 * $offsetChange,
				'40940' => 75,
				'43009' => 25,
				'47007' => 1,
				'47011' => 0,
				'47015' => 10,
				'49994' => 60,
				'44270' => 18,
				'48739' => $current,
			]));

			$this->assertSame([], ActivityLog::where('level', 'error')->pluck('message')->all());
			$this->assertCount($expected === $current ? 0 : 1, $requests);

			if ($expected !== $current)
			{
				$request = $requests[0]['request'];
				$this->assertSame('PATCH', $request->getMethod());
				$this->assertSame('https://nibe.example.test/v2/devices/test-device/points', (string)$request->getUri());
				$this->assertSame(['48739' => $expected], json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR));
			}
		}

		public static function coolingScenarios() : array
		{
			return [
				'dewpoint cannot raise 10 to 11'           => [10, -10, 10, 19, 0, 10],
				'dewpoint still raises an allowed offset' => [9, -10, 10, 19, -2, 10],
				'dewpoint respects a saved lower maximum' => [6, -8, 6, 19, 0, 6],
				'dewpoint corrects above a new maximum'   => [10, -8, 6, 19, 0, 6],
				'projected dewpoint still holds offset'   => [3, -10, 10, 17, -2, 3],
				'hold respects a saved higher minimum'    => [-10, -6, 10, 17, -2, -6],
				'normal adjustment respects minimum'     => [-9, -10, 10, 10, -3, -10],
				'normal adjustment respects maximum'     => [9, -10, 10, 10, 3, 10],
			];
		}
	}

	// Exercise the real adjustment and weather lookup with cooling mode selected.
	class CoolingOffsetController extends NibeController
	{
		public static function calculateHeatingMode(int $priority, float $outdoorTemp, float $avgOutdoorTemp) : string
		{
			return 'cooling';
		}
	}
