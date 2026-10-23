<?php
	namespace Tests\Feature;

	use App\APIs\NibeAPI;
	use App\Http\Controllers\NibeController;
	use App\Models\ActivityLog;
	use App\Models\ApiLog;
	use App\Models\NibeFeedItem;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use GuzzleHttp\Client;
	use GuzzleHttp\Exception\ConnectException;
	use GuzzleHttp\Exception\ServerException;
	use GuzzleHttp\Handler\MockHandler;
	use GuzzleHttp\HandlerStack;
	use GuzzleHttp\Middleware;
	use GuzzleHttp\Psr7\Request;
	use GuzzleHttp\Psr7\Response;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;
	use UnexpectedValueException;

	class NibeParameterDataTest extends TestCase
	{
		use ControlPanelDatabase;

		private MockHandler $responses;
		private array $requests = [];

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			(require database_path('migrations/2023_10_12_122805_create_api_logs_table.php'))->up();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 12:00:00 UTC'));
			config()->set('nibe.functionUrl', 'https://nibe.example.test/v2');
			config()->set('nibe.deviceId', 'test-device');
			config()->set('weather.useForecast', false);
			Setting::create(['key' => 'nibe_token_current', 'value' => 'fake-test-token']);
			Setting::create(['key' => 'nibe_token_expiry', 'value' => (string)CarbonImmutable::now()->addHour()->getTimestamp()]);
			$this->responses = new MockHandler();
			$stack = HandlerStack::create($this->responses);
			$stack->push(Middleware::history($this->requests));
			// Every request, including any unexpected downstream call, stays inside the mock handler.
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

		public function test_valid_points_and_zero_values_are_returned_unchanged() : void
		{
			$points =
			[
				['parameterId' => 40004, 'timestamp' => '2026-10-23T12:00:00Z', 'value' => 0, 'unit' => '°C'],
				['parameterId' => '40940', 'timestamp' => '2026-10-23T12:00:00Z', 'value' => -180.5],
				['parameterId' => 47011, 'timestamp' => '2026-10-23T12:00:00Z', 'value' => '0'],
			];
			$this->responses->append(new Response(200, ['Content-Type' => 'application/json'], json_encode($points, JSON_THROW_ON_ERROR)));
			$this->assertSame($points, (new NibeAPI())->getParameterData());
			$this->assertCount(1, $this->requests);
			$request = $this->requests[0]['request'];
			$this->assertSame('GET', $request->getMethod());
			$this->assertSame('https://nibe.example.test/v2/devices/test-device/points', (string)$request->getUri());
			$this->assertSame('Bearer fake-test-token', $request->getHeaderLine('Authorization'));
			$this->assertSame(1, ApiLog::count());
			$this->assertSame(0, ActivityLog::count());
		}

		public function test_a_valid_empty_list_remains_distinct_from_an_invalid_response() : void
		{
			$this->responses->append(new Response(200, [], '[]'));
			$this->assertSame([], (new NibeAPI())->getParameterData());
		}

		#[DataProvider('invalidResponses')]
		public function test_unusable_responses_raise_a_clear_error_without_exposing_the_body(int $status, string $body, string $reason) : void
		{
			$this->responses->append(new Response($status, [], $body));

			try
			{
				(new NibeAPI())->getParameterData();
				$this->fail('An unusable NIBE response must not reach the controller as readings.');
			}
			catch (UnexpectedValueException $e)
			{
				$this->assertStringContainsString($reason, $e->getMessage());
				$this->assertStringContainsString('HTTP '.$status, $e->getMessage());
				$this->assertStringContainsString(strlen($body).' bytes', $e->getMessage());
				$this->assertStringNotContainsString('private-response-secret', $e->getMessage());
				$this->assertCount(1, $this->requests);
			}
		}

		#[DataProvider('invalidResponses')]
		public function test_failed_polls_log_the_reason_and_leave_readings_and_control_state_untouched(int $status, string $body, string $reason) : void
		{
			Setting::create(['key' => 'htgMode', 'value' => 'on']);
			Setting::create(['key' => 'nibeEmptyDataCount', 'value' => '7']);
			$settings = Setting::pluck('value', 'key')->all();
			NibeFeedItem::create(
			[
				'parameterId'  => 40004,
				'timestamp'    => CarbonImmutable::now()->subMinute()->getTimestamp(),
				'rawValue'     => 12,
				'syncAttempts' => 0,
				'syncStatus'   => 'success',
			]);
			$this->responses->append(new Response($status, [], $body));
			NibeController::getNibeData();
			$this->assertSame($settings, Setting::pluck('value', 'key')->all());
			$this->assertSame(1, NibeFeedItem::count());
			$this->assertSame(1, ActivityLog::count());
			$log = ActivityLog::sole();
			$this->assertSame('getNibeData', $log->method);
			$this->assertSame('error', $log->level);
			$this->assertStringContainsString($reason, $log->message);
			$this->assertStringNotContainsString('private-response-secret', $log->message);
			$this->assertCount(1, $this->requests);
		}

		public function test_a_later_request_can_succeed_after_a_bad_response() : void
		{
			$this->responses->append(new Response(200, [], 'null'));
			$this->responses->append(new Response(200, [], '[{"parameterId":40004,"timestamp":"2026-10-23T12:00:00Z","value":0}]'));
			$api = new NibeAPI();

			try
			{
				$api->getParameterData();
				$this->fail('The null response should fail.');
			}
			catch (UnexpectedValueException $e)
			{
				$this->assertStringContainsString('not a JSON list', $e->getMessage());
			}

			$this->assertSame(0, $api->getParameterData()[0]['value']);
			$this->assertCount(2, $this->requests);
		}

		public function test_http_errors_keep_their_existing_exception() : void
		{
			$this->responses->append(new Response(503, [], 'Service unavailable'));
			$this->expectException(ServerException::class);
			(new NibeAPI())->getParameterData();
		}

		public function test_connection_errors_keep_their_existing_exception() : void
		{
			$this->responses->append(new ConnectException('NIBE connection timed out', new Request('GET', 'https://nibe.example.test/v2/devices/test-device/points')));
			$this->expectException(ConnectException::class);
			(new NibeAPI())->getParameterData();
		}

		public static function invalidResponses() : array
		{
			return [
				'empty body' => [200, '', 'response was empty'],
				'whitespace body' => [200, " \r\n\t", 'response was empty'],
				'no content' => [204, '', 'response was empty'],
				'JSON null' => [200, 'null', 'not a JSON list of points'],
				'malformed JSON' => [200, '{"private-response-secret":', 'invalid JSON'],
				'HTML page' => [200, '<html>private-response-secret</html>', 'invalid JSON'],
				'JSON string' => [200, '"private-response-secret"', 'not a JSON list of points'],
				'JSON boolean' => [200, 'false', 'not a JSON list of points'],
				'JSON number' => [200, '123', 'not a JSON list of points'],
				'error object' => [200, '{"error":"private-response-secret"}', 'not a JSON list of points'],
				'empty object' => [200, '{}', 'not a JSON list of points'],
				'object with numeric keys' => [200, '{"0":{"parameterId":40004,"timestamp":"2026-10-23T12:00:00Z","value":1}}', 'not a JSON list of points'],
				'null point' => [200, '[null]', 'invalid point at index 0'],
				'scalar point' => [200, '[1]', 'invalid point at index 0'],
				'missing parameter ID' => [200, '[{"timestamp":"2026-10-23T12:00:00Z","value":1}]', 'invalid point at index 0'],
				'missing timestamp' => [200, '[{"parameterId":40004,"value":1}]', 'invalid point at index 0'],
				'missing value' => [200, '[{"parameterId":40004,"timestamp":"2026-10-23T12:00:00Z"}]', 'invalid point at index 0'],
				'invalid second point' => [200, '[{"parameterId":40004,"timestamp":"2026-10-23T12:00:00Z","value":12},null]', 'invalid point at index 1'],
				'unexpected redirect' => [302, 'private-response-secret', 'unexpected status'],
			];
		}
	}
