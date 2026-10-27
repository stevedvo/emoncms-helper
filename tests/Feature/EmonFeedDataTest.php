<?php
	namespace Tests\Feature;

	use App\APIs\EmonAPI;
	use App\Http\Controllers\EmonController;
	use App\Models\ActivityLog;
	use App\Models\FeedItem;
	use Carbon\CarbonImmutable;
	use GuzzleHttp\Client;
	use GuzzleHttp\Exception\ConnectException;
	use GuzzleHttp\Exception\ServerException;
	use GuzzleHttp\Handler\MockHandler;
	use GuzzleHttp\HandlerStack;
	use GuzzleHttp\Middleware;
	use GuzzleHttp\Psr7\Request;
	use GuzzleHttp\Psr7\Response;
	use Illuminate\Support\Facades\DB;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;
	use UnexpectedValueException;

	class EmonFeedDataTest extends TestCase
	{
		use ControlPanelDatabase;

		private MockHandler $responses;
		private array $requests = [];

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			(require database_path('migrations/2023_10_12_122805_create_api_logs_table.php'))->up();
			(require database_path('migrations/2023_10_24_081106_create_feed_items_table.php'))->up();
			CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-23 12:00:00 UTC'));
			config()->set('emon.emonHpLocalEndpoint', 'https://local.example.test');
			config()->set('emon.emonHpLocalReadKey', 'fake-local-read-key');
			config()->set('emon.emonCmsEndpoint', 'https://remote.example.test');
			config()->set('emon.emonCmsReadKey', 'fake-remote-read-key');
			$this->responses = new MockHandler();
			$stack = HandlerStack::create($this->responses);
			$stack->push(Middleware::history($this->requests));
			// Unexpected requests also stay inside the mock transport.
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

		public function test_valid_samples_preserve_zero_null_gaps_and_legacy_string_values() : void
		{
			$samples = [[1792756200000, 0], [1792756210000, null], ['1792756220000', '20.1'], [1792756230000, 'null']];
			$this->responses->append(new Response(200, [], json_encode($samples, JSON_THROW_ON_ERROR)));
			$this->assertSame($samples, EmonAPI::getFeedData('local', 6, '1792756200000', '1792756240000'));
			$request = $this->requests[0]['request'];
			$this->assertSame('GET', $request->getMethod());
			$this->assertSame('https://local.example.test/feed/data.json?apikey=fake-local-read-key&id=6&start=1792756200000&end=1792756240000&interval=10', (string)$request->getUri());
			$this->assertSame(0, ActivityLog::count());
		}

		public function test_a_valid_empty_remote_list_remains_an_empty_list() : void
		{
			$this->responses->append(new Response(200, [], '[]'));
			$this->assertSame([], EmonAPI::getFeedData('remote', 106, '1000', '2000'));
			$this->assertSame('https://remote.example.test/feed/data.json?apikey=fake-remote-read-key&id=106&start=1000&end=2000&interval=10', (string)$this->requests[0]['request']->getUri());
		}

		#[DataProvider('invalidResponses')]
		public function test_bad_responses_raise_a_diagnostic_without_exposing_the_body_or_key(int $status, string $body, string $reason) : void
		{
			$this->responses->append(new Response($status, [], $body));

			try
			{
				EmonAPI::getFeedData('local', 6, '1000', '2000');
				$this->fail('Unusable feed data must not reach callers as readings.');
			}
			catch (UnexpectedValueException $e)
			{
				$this->assertStringContainsString('Emon local feed #6 response', $e->getMessage());
				$this->assertStringContainsString($reason, $e->getMessage());
				$this->assertStringContainsString('HTTP '.$status, $e->getMessage());
				$this->assertStringContainsString(strlen($body).' bytes', $e->getMessage());
				$this->assertStringNotContainsString('private-response-secret', $e->getMessage());
				$this->assertStringNotContainsString('fake-local-read-key', $e->getMessage());
				$this->assertCount(1, $this->requests);
			}
		}

		public function test_latest_reading_returns_zero_without_a_missing_data_error() : void
		{
			$this->insertMapping(6, 'Rad_temperature');
			$this->responses->append(new Response(200, [], '[[1792756200000,21],[1792756210000,0],[1792756220000,null]]'));
			$this->assertSame('0', EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$this->assertSame(0, ActivityLog::count());
		}

		public function test_latest_reading_rounds_the_last_available_value_and_ignores_null_gaps() : void
		{
			$this->insertMapping(6, 'Rad_temperature');
			$this->responses->append(new Response(200, [], '[[1792756200000,21],[1792756210000,"20.24"],[1792756220000,null]]'));
			$this->assertSame('20.2', EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$this->assertSame(0, ActivityLog::count());
		}

		public function test_a_failed_latest_read_returns_null_and_a_later_read_can_recover() : void
		{
			$this->insertMapping(6, 'Rad_temperature');
			$this->responses->append(new Response(200, [], 'null'), new Response(200, [], '[[1792756200000,20]]'));
			$this->assertNull(EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$log = ActivityLog::sole();
			$this->assertSame('getLatestEmonData', $log->method);
			$this->assertSame('error', $log->level);
			$this->assertStringContainsString('Emon local feed #6 response was not a JSON list', $log->message);
			$this->assertSame('20', EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$this->assertSame(1, ActivityLog::count());
			$this->assertCount(2, $this->requests);
		}

		public function test_an_empty_feed_or_only_null_gaps_is_reported_as_missing_data() : void
		{
			$this->insertMapping(6, 'Rad_temperature');
			$this->responses->append(new Response(200, [], '[]'), new Response(200, [], '[[1792756200000,null]]'));
			$this->assertNull(EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$this->assertNull(EmonController::getLatestEmonData('Rad_temperature', 'local', 180));
			$this->assertSame(2, ActivityLog::where('message', 'like', 'Unable to get latest data.%')->count());
		}

		#[DataProvider('failedComparisonFeeds')]
		public function test_comparison_skips_a_bad_feed_without_creating_backlog_and_continues(string $environment, string $body) : void
		{
			$this->insertMapping(6, 'heatmeter_FlowT');
			$this->insertMapping(7, 'heatmeter_ReturnT');
			$this->responses->append(new Response(200, [], '[{"id":6},{"id":7}]'));

			if ($environment === 'remote')
			{
				$this->responses->append(new Response(200, [], '[[1792756200000,35]]'));
			}

			$this->responses->append(new Response(200, [], $body), new Response(200, [], '[[1792756200000,21]]'), new Response(200, [], '[]'));
			EmonController::getEmonFeeds();
			$this->assertSame(0, FeedItem::where('localFeedId', 6)->count());
			$item = FeedItem::sole();
			$this->assertSame(7, $item->localFeedId);
			$this->assertSame(107, $item->remoteFeedId);
			$this->assertEquals(21, $item->value);
			$this->assertSame('pending', $item->syncStatus);
			$log = ActivityLog::where('level', 'error')->sole();
			$this->assertStringContainsString('localFeed #6: Emon '.$environment.' feed #'.($environment === 'local' ? 6 : 106).' response', $log->message);
			$this->assertCount($environment === 'local' ? 4 : 5, $this->requests);
			foreach ($this->requests as $transaction)
			{
				$this->assertSame('GET', $transaction['request']->getMethod());
			}
		}

		public function test_http_errors_keep_their_existing_exception() : void
		{
			$this->responses->append(new Response(503, [], 'Service unavailable'));
			$this->expectException(ServerException::class);
			EmonAPI::getFeedData('local', 6, '1000', '2000');
		}

		public function test_connection_errors_keep_their_existing_exception() : void
		{
			$this->responses->append(new ConnectException('Emon connection timed out', new Request('GET', 'https://local.example.test')));
			$this->expectException(ConnectException::class);
			EmonAPI::getFeedData('local', 6, '1000', '2000');
		}

		private function insertMapping(int $localFeedId, string $name) : void
		{
			DB::table('emon_feed_maps')->insert(
			[
				'localFeedId'  => $localFeedId,
				'localName'    => $name,
				'remoteFeedId' => $localFeedId + 100,
				'remoteName'   => $name,
			]);
		}

		public static function failedComparisonFeeds() : array
		{
			return [
				'local null response'       => ['local', 'null'],
				'remote null response'      => ['remote', 'null'],
				'local malformed sample'    => ['local', '[[1792756200000,35],null]'],
				'remote malformed sample'   => ['remote', '[[1792756200000,35],null]'],
			];
		}

		public static function invalidResponses() : array
		{
			return [
				'empty body'               => [200, '', 'was empty'],
				'whitespace body'          => [200, " \r\n\t", 'was empty'],
				'no content'               => [204, '', 'was empty'],
				'JSON null'                => [200, 'null', 'not a JSON list'],
				'malformed JSON'           => [200, '{"private-response-secret":', 'invalid JSON'],
				'HTML page'                => [200, '<html>private-response-secret</html>', 'invalid JSON'],
				'JSON string'              => [200, '"private-response-secret"', 'not a JSON list'],
				'JSON boolean'             => [200, 'false', 'not a JSON list'],
				'JSON number'              => [200, '123', 'not a JSON list'],
				'error object'             => [200, '{"error":"private-response-secret"}', 'not a JSON list'],
				'empty object'             => [200, '{}', 'not a JSON list'],
				'object with numeric keys' => [200, '{"0":[1792756200000,20]}', 'not a JSON list'],
				'null sample'              => [200, '[null]', 'invalid sample at index 0'],
				'scalar sample'            => [200, '[1]', 'invalid sample at index 0'],
				'missing value'            => [200, '[[1792756200000]]', 'invalid sample at index 0'],
				'extra value'              => [200, '[[1792756200000,20,21]]', 'invalid sample at index 0'],
				'invalid timestamp'        => [200, '[["yesterday",20]]', 'invalid sample at index 0'],
				'null timestamp'           => [200, '[[null,20]]', 'invalid sample at index 0'],
				'infinite timestamp'       => [200, '[[1e999,20]]', 'invalid sample at index 0'],
				'complex value'            => [200, '[[1792756200000,{"error":"private-response-secret"}]]', 'invalid sample at index 0'],
				'bad later sample'         => [200, '[[1792756200000,20],null]', 'invalid sample at index 1'],
				'unexpected redirect'      => [302, 'private-response-secret', 'unexpected status'],
			];
		}
	}
