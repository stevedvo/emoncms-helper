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

		#[DataProvider('diagnosticTimes')]
		public function test_poll_id_diagnostics_run_only_on_five_minute_ticks_and_include_unmapped_and_unchanged_inputs(string $time, bool $expected) : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'));
			config()->set('nibe.dmOverride', false);
			config()->set('nibe.loadCompensationOn', false);
			$points =
			[
				['parameterId' => 40067, 'timestamp' => '2026-10-23T11:00:00Z', 'value' => null],
				['parameterId' => '40004', 'timestamp' => '2026-10-23T11:00:00Z', 'value' => 0],
				['parameterId' => 40004, 'timestamp' => '2026-10-23T11:01:00Z', 'value' => 1],
				['parameterId' => 40014, 'timestamp' => '2026-10-23T11:00:00Z', 'value' => 40],
				['parameterId' => 99999, 'timestamp' => '2026-10-23T11:00:00Z', 'value' => 'private-value-not-logged'],
			];
			$this->responses->append(new Response(200, [], json_encode($points, JSON_THROW_ON_ERROR)));
			NibeController::getNibeData();
			$logs = ActivityLog::where('method', 'getNibeData')->where('message', 'like', 'NIBE poll received %')->get();
			$this->assertCount($expected ? 1 : 0, $logs);

			if ($expected)
			{
				$this->assertSame('info', $logs->sole()->level);
				$this->assertStringContainsString('received 5 points; parameter IDs (4): [40004, 40014, 40067, 99999]', $logs->sole()->message);
				$this->assertStringContainsString('missing DM override parameter IDs: [40071, 40940, 43009, 44270, 47007, 47011, 47015, 48739, 49994]', $logs->sole()->message);
				$this->assertStringNotContainsString('private-value-not-logged', $logs->sole()->message);
			}

			$this->assertSame(0, NibeFeedItem::count());
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
			$this->assertCount(1, $this->requests);
			$this->assertSame('GET', $this->requests[0]['request']->getMethod());
		}

		public static function diagnosticTimes() : array
		{
			return [
				'control tick'        => ['2026-10-23 12:00:00', true],
				'ordinary minute'     => ['2026-10-23 12:01:00', false],
				'next control tick'   => ['2026-10-23 12:05:00', true],
			];
		}

		public function test_a_valid_empty_poll_logs_zero_points_and_all_missing_ids_before_the_existing_dm_error() : void
		{
			config()->set('nibe.dmOverride', true);
			config()->set('nibe.loadCompensationOn', false);
			$this->responses->append(new Response(200, [], '[]'));
			NibeController::getNibeData();
			$diagnostic = ActivityLog::where('message', 'like', 'NIBE poll received %')->sole();
			$error = ActivityLog::where('method', 'dmOverride')->where('level', 'error')->sole();
			$this->assertStringContainsString('received 0 points; parameter IDs (0): []', $diagnostic->message);
			$this->assertStringContainsString('missing DM override parameter IDs: [40004, 40067, 40071, 40940, 43009, 44270, 47007, 47011, 47015, 48739, 49994]', $diagnostic->message);
			$this->assertSame("No data for 'outdoor temp.'", $error->message);
			$this->assertLessThan($error->id, $diagnostic->id);
			$this->assertCount(1, $this->requests);
		}

		public function test_a_complete_poll_reports_no_missing_dm_inputs_even_without_export_candidates() : void
		{
			config()->set('nibe.dmOverride', false);
			config()->set('nibe.loadCompensationOn', false);
			$ids = [40004, 40014, 40067, 40071, 40940, 43009, 44270, 47007, 47011, 47015, 48739, 49994];
			$points = array_map(fn($id) => ['parameterId' => $id, 'timestamp' => '2026-10-23T12:00:00Z', 'value' => 40], $ids);
			$this->responses->append(new Response(200, [], json_encode($points, JSON_THROW_ON_ERROR)));
			NibeController::getNibeData();
			$diagnostic = ActivityLog::where('message', 'like', 'NIBE poll received %')->sole();
			$this->assertStringContainsString('received 12 points; parameter IDs (12): [', $diagnostic->message);
			$this->assertStringContainsString('missing DM override parameter IDs: [].', $diagnostic->message);
			$this->assertSame(1, ActivityLog::where('message', 'No new NIBE data found')->count());
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

		#[DataProvider('hotWaterControlPaths')]
		public function test_poll_routes_hot_water_to_exactly_one_control_path(bool $enabled, int $parameter) : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 09:00:00 UTC'));
			config()->set('nibe.hotWaterControl', $enabled);
			config()->set('nibe.loadCompensationOn', false);
			$points =
			[
				['parameterId' => 49994, 'timestamp' => '2026-10-23T09:00:00Z', 'value' => 30],
				['parameterId' => 40014, 'timestamp' => '2026-10-23T09:00:00Z', 'value' => 32],
			];
			$this->responses->append(new Response(200, [], json_encode($points)), new Response(200, [], json_encode([$parameter => 'modified'])));
			NibeController::getNibeData();
			$this->assertCount(2, $this->requests);
			$request = $this->requests[1]['request'];
			$this->assertSame('PATCH', $request->getMethod());
			$this->assertSame([$parameter => $enabled ? 1 : '1'], json_decode((string)$request->getBody(), true));
			$this->assertSame($enabled ? 'hotWaterControl' : 'hotWaterSet', Setting::where('key', $enabled ? 'hotWaterControl' : 'hotWaterSet')->sole()->key);
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
		}

		public static function hotWaterControlPaths() : array
		{
			return ['new direct control' => [true, 47041], 'original schedule control' => [false, 47655]];
		}

		public function test_live_poll_with_unchanged_priority_and_charging_can_request_hot_water() : void
		{
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 09:00:00 UTC'));
			config()->set('nibe.hotWaterControl', true);
			config()->set('nibe.loadCompensationOn', false);
			$points =
			[
				['parameterId' => 49994, 'timestamp' => '2026-10-23T06:00:00Z', 'value' => 30],
				['parameterId' => 40014, 'timestamp' => '2026-10-23T06:00:00Z', 'value' => 32],
				['parameterId' => 40940, 'timestamp' => '2026-10-23T08:59:00Z', 'value' => -166],
			];
			$this->responses->append(new Response(200, [], json_encode($points)), new Response(200, [], '{"47041":"modified"}'));
			NibeController::getNibeData();
			$this->assertCount(2, $this->requests);
			$this->assertSame([47041 => 1], json_decode((string)$this->requests[1]['request']->getBody(), true));
			$this->assertSame(0, ActivityLog::where('level', 'error')->count());
		}

		public function test_comfort_write_requires_the_modified_acknowledgement() : void
		{
			$this->responses->append(new Response(200, [], '{"47041":"rejected"}'));
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('47041 not modified');
			(new NibeAPI())->setParameterData([47041 => 0]);
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
