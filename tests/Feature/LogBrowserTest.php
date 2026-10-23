<?php
	namespace Tests\Feature;

	use App\Models\ActivityLog;
	use App\Models\ApiLog;
	use App\Models\SavedLogSearch;
	use App\Models\User;
	use Illuminate\Support\Facades\Http;
	use Illuminate\Support\Facades\Schema;
	use PHPUnit\Framework\Attributes\DataProvider;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class LogBrowserTest extends TestCase
	{
		use ControlPanelDatabase;

		protected function setUp() : void
		{
			parent::setUp();
			$this->prepareControlPanelDatabase();
			Http::preventStrayRequests();
			Schema::drop('activity_logs');
			(require database_path('migrations/2023_10_12_123526_create_activity_logs_table.php'))->up();
			(require database_path('migrations/2023_10_12_122805_create_api_logs_table.php'))->up();
			(require database_path('migrations/2026_10_20_000000_create_saved_log_searches_table.php'))->up();
		}

		public function test_browsing_and_saved_search_changes_require_sign_in() : void
		{
			foreach (['activity', 'api'] as $type)
			{
				$this->get('/logs/'.$type)->assertRedirect('/login');
				$this->post('/logs/'.$type.'/searches', ['name' => 'Test'])->assertRedirect('/login');
				$this->delete('/logs/'.$type.'/searches/1')->assertRedirect('/login');
			}
		}

		public function test_activity_filters_combine_fields_and_repeat_message_conditions() : void
		{
			$this->actingAs(User::factory()->create());
			$match = $this->activity('UFH heat requested');
			$this->activity('UFH fallback requested');
			$this->activity('Radiators requested');
			$this->activity('UFH heat requested', ['method' => 'otherMethod']);
			$this->activity('UFH heat requested', ['level' => 'warning']);
			$this->activity('UFH heat requested', ['controller' => 'OtherController']);
			$filters = [
				$this->filter('controller', 'contains', 'NIBECONTROLLER'),
				$this->filter('method', 'equals', 'calculateHeatingMode'),
				$this->filter('level', 'equals', 'INFO'),
				$this->filter('message', 'contains', 'ufh'),
				$this->filter('message', 'not_contains', 'fallback'),
			];
			$this->search('activity', ['filters' => $filters])->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$match->id]);
		}

		public function test_saved_search_changes_require_csrf_protection() : void
		{
			$user = User::factory()->create();
			$this->actingAs($user)->post('/logs/activity/searches', ['name' => 'Keep me'])->assertSessionHasNoErrors();
			$saved = SavedLogSearch::sole();
			$this->app['env'] = 'local';
			$this->post('/logs/activity/searches', ['name' => 'Unwanted search'])->assertStatus(419);
			$this->delete('/logs/activity/searches/'.$saved->id)->assertStatus(419);
			$this->assertSame(1, SavedLogSearch::count());
		}

		public function test_negative_matching_includes_null_and_empty_fields() : void
		{
			$this->actingAs(User::factory()->create());
			$this->activity('Contains fallback');
			$null = $this->activity(null);
			$empty = $this->activity('');
			$normal = $this->activity('Normal decision');
			$this->search('activity', ['filters' => [$this->filter('message', 'not_contains', 'fallback')]])
				->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$normal->id, $empty->id, $null->id]);
		}

		public function test_search_treats_wildcards_and_controller_backslashes_literally() : void
		{
			$this->actingAs(User::factory()->create());
			$match = $this->activity('Room_100%! reached');
			$this->activity('RoomX100x reached');
			$this->search('activity', ['filters' => [$this->filter('message', 'contains', '_100%!'), $this->filter('controller', 'equals', 'App\\Http\\Controllers\\NibeController')]])
				->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$match->id]);
			$this->search('activity', ['filters' => [$this->filter('message', 'not_equals', 'Room_100%! reached')]])
				->assertOk()->assertDontSeeText('Room_100%! reached')->assertSeeText('RoomX100x reached');
		}

		public function test_api_fields_and_payload_are_searchable_and_rendered_as_text() : void
		{
			$this->actingAs(User::factory()->create());
			$match = ApiLog::create(['method' => 'POST', 'endpoint' => '/weather', 'payload' => '<script>alert("test")</script> solar 0', 'ignored' => true]);
			ApiLog::create(['method' => 'GET', 'endpoint' => '/other', 'payload' => 'solar 0']);
			$this->search('api', ['filters' => [$this->filter('method', 'equals', 'post'), $this->filter('endpoint', 'contains', 'weather'), $this->filter('payload', 'contains', '0')]])
				->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$match->id])
				->assertSee('Ignored')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
		}

		public function test_activity_messages_are_escaped() : void
		{
			$this->actingAs(User::factory()->create());
			$this->activity('<img src=x onerror=alert(1)>');
			$this->get('/logs/activity')->assertOk()->assertSee('&lt;img', false)->assertDontSee('<img src=x', false);
		}

		public function test_blank_conditions_are_ignored_but_zero_is_searched() : void
		{
			$this->actingAs(User::factory()->create());
			$zero = $this->activity('0');
			$this->activity('Normal');
			$this->search('activity', ['filters' => [$this->filter('controller', 'contains', ''), $this->filter('message', 'equals', '0')]])
				->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$zero->id]);
		}

		public function test_pagination_keeps_filters_and_orders_newest_entries_first() : void
		{
			$this->actingAs(User::factory()->create());
			$first = $this->activity('Keep 1');

			foreach (range(2, 26) as $number)
			{
				$this->activity('Keep '.$number);
			}

			$this->activity('Excluded');
			$criteria = ['filters' => [$this->filter('message', 'contains', 'Keep')], 'per_page' => 25];
			$this->search('activity', $criteria)->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 25 && $logs->hasMorePages() && str_contains($logs->nextPageUrl(), 'Keep'));
			$this->search('activity', $criteria + ['page' => 2])->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$first->id]);
		}

		public function test_uk_date_range_includes_the_whole_day_when_clocks_change() : void
		{
			config()->set('app.timezone', 'UTC');
			$this->actingAs(User::factory()->create());
			$this->activity('Before', ['created_at' => '2026-10-24 22:59:59']);
			$start = $this->activity('Start', ['created_at' => '2026-10-24 23:00:00']);
			$end = $this->activity('End', ['created_at' => '2026-10-25 23:59:59']);
			$this->activity('After', ['created_at' => '2026-10-26 00:00:00']);
			$this->search('activity', ['from' => '2026-10-25', 'to' => '2026-10-25'])
				->assertOk()->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$end->id, $start->id]);
		}

		#[DataProvider('logTypes')]
		public function test_both_log_screens_default_to_250_and_offer_the_requested_sizes(string $type) : void
		{
			$this->actingAs(User::factory()->create());
			$this->seedLogs($type, 301);
			$this->get('/logs/'.$type)->assertOk()
				->assertViewHas('logs', fn ($logs) => $logs->count() === 250 && $logs->total() === 301 && $logs->lastPage() === 2)
				->assertViewHas('pageSizes', [100, 250, 500, 1000])
				->assertSee('<option value="250" selected>', false)
				->assertSee('Showing 1–250 of 301')
				->assertSee('Go to page');
		}

		#[DataProvider('logPageSizes')]
		public function test_all_requested_sizes_paginate_both_types_of_log(string $type, int $size) : void
		{
			$this->actingAs(User::factory()->create());
			$this->seedLogs($type, 1001);
			$this->search($type, ['per_page' => $size])->assertOk()
				->assertViewHas('logs', fn ($logs) => $logs->count() === $size && $logs->perPage() === $size && $logs->total() === 1001 && $logs->lastPage() === (int)ceil(1001 / $size))
				->assertSee('<option value="'.$size.'" selected>', false);
		}

		#[DataProvider('logTypes')]
		public function test_page_jumps_keep_filters_dates_size_and_newest_first_order(string $type) : void
		{
			$this->actingAs(User::factory()->create());
			$this->seedLogs($type, 525, 'Keep');
			$this->seedLogs($type, 1, 'Excluded');
			$criteria =
			[
				'filters'  => [$this->filter($type === 'activity' ? 'message' : 'endpoint', 'contains', 'Keep')],
				'from'     => '2026-10-23',
				'to'       => '2026-10-23',
				'per_page' => 100,
			];
			$response = $this->search($type, $criteria + ['page' => 4])->assertOk()
				->assertSee('name="page" min="1" max="6" value="4"', false)
				->assertSee('name="per_page" value="100"', false)
				->assertSee('name="from" value="2026-10-23"', false)
				->assertSee('name="filters[0][value]" value="Keep"', false);
			$logs = $response->viewData('logs');
			$this->assertSame(525, $logs->total());
			$this->assertSame(range(225, 126), $logs->pluck('id')->all());
			parse_str(parse_url($logs->nextPageUrl(), PHP_URL_QUERY), $query);
			$this->assertSame($criteria['filters'], $query['filters']);
			$this->assertSame('2026-10-23', $query['from']);
			$this->assertSame('2026-10-23', $query['to']);
			$this->assertSame('100', $query['per_page']);
			$this->assertSame('5', $query['page']);
			$this->assertSame('results-heading', parse_url($logs->nextPageUrl(), PHP_URL_FRAGMENT));
			$this->search($type, $criteria + ['page' => 6])->assertOk()->assertViewHas('logs', fn ($logs) => $logs->count() === 25 && !$logs->hasMorePages());
			$this->search($type, array_replace($criteria, ['per_page' => 500]))->assertOk()->assertViewHas('logs', fn ($logs) => $logs->currentPage() === 1 && $logs->count() === 500);
		}

		#[DataProvider('logTypes')]
		public function test_saved_search_pagination_keeps_its_name_and_allows_a_temporary_size_override(string $type) : void
		{
			$this->actingAs(User::factory()->create());
			$this->seedLogs($type, 525, 'Keep');
			$this->seedLogs($type, 1, 'Excluded');
			$this->post('/logs/'.$type.'/searches',
			[
				'name'     => 'Keep these logs',
				'filters'  => [$this->filter($type === 'activity' ? 'message' : 'endpoint', 'contains', 'Keep')],
				'per_page' => 250,
			])->assertSessionHasNoErrors()->assertRedirect();
			$saved = SavedLogSearch::sole();
			$response = $this->search($type, ['saved_search' => $saved->id, 'per_page' => 100, 'page' => 3])->assertOk()
				->assertSee('Loaded: Keep these logs')
				->assertSee('name="saved_search" value="'.$saved->id.'"', false);
			$logs = $response->viewData('logs');
			$this->assertSame(100, $logs->perPage());
			$this->assertSame(3, $logs->currentPage());
			$this->assertSame(525, $logs->total());
			$this->assertSame(250, $saved->fresh()->criteria['per_page']);
			$this->get($logs->nextPageUrl())->assertOk()->assertSee('Loaded: Keep these logs')
				->assertViewHas('logs', fn ($logs) => $logs->currentPage() === 4 && $logs->perPage() === 100);
		}

		#[DataProvider('logTypes')]
		public function test_pages_beyond_the_end_return_to_the_last_available_page(string $type) : void
		{
			$this->actingAs(User::factory()->create());
			$this->seedLogs($type, 301);
			$response = $this->search($type, ['per_page' => 100, 'page' => 99])->assertRedirect();
			parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
			$this->assertSame('4', $query['page']);
			$this->assertSame('100', $query['per_page']);
			$this->get($response->headers->get('Location'))->assertOk()
				->assertViewHas('logs', fn ($logs) => $logs->currentPage() === 4 && $logs->count() === 1);
		}

		public function test_empty_results_still_render_and_an_old_page_returns_to_page_one() : void
		{
			$this->actingAs(User::factory()->create());
			$this->get('/logs/activity')->assertOk()->assertSee('No entries found')->assertDontSee('Go to page');
			$response = $this->search('activity', ['page' => 99])->assertRedirect();
			$this->get($response->headers->get('Location'))->assertOk()->assertSee('No entries found');
		}

		public static function logTypes() : array
		{
			return [['activity'], ['api']];
		}

		public static function logPageSizes() : array
		{
			return [['activity', 100], ['activity', 250], ['activity', 500], ['activity', 1000], ['api', 100], ['api', 250], ['api', 500], ['api', 1000]];
		}

		public function test_named_search_can_be_saved_reloaded_and_updated_across_sessions() : void
		{
			$user = User::factory()->create();
			$this->actingAs($user);
			$filters = [$this->filter('message', 'contains', 'UFH')];
			$this->post('/logs/activity/searches', ['name' => 'UFH decisions', 'filters' => $filters, 'from' => '2026-01-01', 'per_page' => 25])->assertSessionHasNoErrors()->assertRedirect();
			$saved = SavedLogSearch::sole();
			$this->assertSame($user->id, $saved->user_id);
			$this->assertSame($filters, $saved->criteria['filters']);
			$match = $this->activity('UFH heat');
			$this->activity('Rad heat');
			$this->app['session']->flush();
			$this->actingAs($user);
			$this->search('activity', ['saved_search' => $saved->id])->assertOk()->assertSee('Loaded: UFH decisions')->assertViewHas('logs', fn ($logs) => $logs->pluck('id')->all() === [$match->id]);
			$this->search('activity', ['saved_search' => $saved->id])->assertOk()->assertSee('<option value="25" selected>', false);
			$this->post('/logs/activity/searches', ['name' => 'UFH decisions', 'filters' => [$this->filter('level', 'equals', 'warning')]])->assertSessionHasNoErrors();
			$this->assertSame(1, SavedLogSearch::count());
			$this->assertSame('level', $saved->fresh()->criteria['filters'][0]['field']);
			$this->delete('/logs/activity/searches/'.$saved->id)->assertRedirect('/logs/activity');
			$this->assertSame(0, SavedLogSearch::count());
			$this->assertSame(2, ActivityLog::count());
		}

		public function test_saved_searches_are_private_and_separate_for_each_log_type() : void
		{
			$owner = User::factory()->create();
			$this->actingAs($owner)->post('/logs/api/searches', ['name' => 'Private API search'])->assertSessionHasNoErrors();
			$saved = SavedLogSearch::sole();
			$this->search('activity', ['saved_search' => $saved->id])->assertNotFound();
			$this->delete('/logs/activity/searches/'.$saved->id)->assertNotFound();
			$this->actingAs(User::factory()->create());
			$this->get('/logs/api')->assertOk()->assertDontSee('Private API search');
			$this->search('api', ['saved_search' => $saved->id])->assertNotFound();
			$this->delete('/logs/api/searches/'.$saved->id)->assertNotFound();
			$this->post('/logs/api/searches', ['name' => 'Private API search'])->assertSessionHasNoErrors();
			$this->assertSame(2, SavedLogSearch::count());
			$this->assertTrue(SavedLogSearch::whereKey($saved->id)->exists());
		}

		#[DataProvider('invalidCriteria')]
		public function test_invalid_or_unbounded_searches_are_rejected(array $criteria) : void
		{
			$this->actingAs(User::factory()->create());
			$this->getJson('/logs/activity?'.http_build_query($criteria))->assertUnprocessable();
			$this->postJson('/logs/activity/searches', $criteria + ['name' => 'Invalid'])->assertUnprocessable();
			$this->assertSame(0, SavedLogSearch::count());
		}

		public static function invalidCriteria() : array
		{
			return [
				'unknown column' => [['filters' => [['field' => 'password', 'operator' => 'contains', 'value' => 'secret']]]],
				'column injection' => [['filters' => [['field' => 'message) OR 1=1 --', 'operator' => 'contains', 'value' => 'x']]]],
				'unknown operator' => [['filters' => [['field' => 'message', 'operator' => 'raw', 'value' => 'x']]]],
				'invalid value' => [['filters' => [['field' => 'message', 'operator' => 'contains', 'value' => ['x']]]]],
				'too many filters' => [['filters' => array_fill(0, 21, ['field' => 'message', 'operator' => 'contains', 'value' => 'x'])]],
				'too much text' => [['filters' => [['field' => 'message', 'operator' => 'contains', 'value' => str_repeat('x', 1001)]]]],
				'bad date' => [['from' => 'not-a-date']],
				'reversed dates' => [['from' => '2026-10-21', 'to' => '2026-10-20']],
				'unbounded page' => [['per_page' => 100000]],
				'unsupported page size' => [['per_page' => 101]],
				'fractional page size' => [['per_page' => 250.5]],
				'bad page' => [['page' => -1]],
			];
		}

		public function test_saved_search_name_is_required_and_log_type_is_restricted() : void
		{
			$this->actingAs(User::factory()->create());
			$this->postJson('/logs/activity/searches', ['name' => ' '])->assertUnprocessable();
			$this->get('/logs/users')->assertNotFound();
			$this->post('/logs/users/searches', ['name' => 'Test'])->assertNotFound();
			$this->assertSame(0, SavedLogSearch::count());
		}

		public function test_invalid_html_form_input_returns_to_a_usable_search_page() : void
		{
			$this->actingAs(User::factory()->create());
			$this->post('/logs/activity/searches', ['name' => ['invalid'], 'from' => ['invalid'], 'filters' => [['field' => 'message', 'operator' => 'contains', 'value' => ['invalid']]]])
				->assertRedirect('/logs/activity')->assertSessionHasErrors();
			$this->get('/logs/activity')->assertOk()->assertSee('Please check your search.');
			$this->search('activity', ['from' => '2026-10-21', 'to' => '2026-10-20'])->assertRedirect('/logs/activity')->assertSessionHasErrors();
			$this->get('/logs/activity')->assertOk();
		}

		private function seedLogs(string $type, int $count, string $message = 'Entry') : void
		{
			$rows = [];

			foreach (range(1, $count) as $number)
			{
				$row = $type === 'activity' ?
				[
					'controller' => 'App\\Http\\Controllers\\NibeController',
					'method'     => 'calculateHeatingMode',
					'level'      => 'info',
					'message'    => $message.' '.$number,
				] :
				[
					'method'   => 'GET',
					'endpoint' => '/'.$message.'/'.$number,
					'payload'  => null,
				];
				$rows[] = $row + ['created_at' => '2026-10-23 12:00:00', 'updated_at' => '2026-10-23 12:00:00'];
			}

			$model = $type === 'activity' ? ActivityLog::class : ApiLog::class;

			foreach (array_chunk($rows, 200) as $chunk)
			{
				$model::insert($chunk);
			}
		}

		private function activity(?string $message, array $attributes = []) : ActivityLog
		{
			$log = new ActivityLog();
			$log->forceFill($attributes + ['controller' => 'App\\Http\\Controllers\\NibeController', 'method' => 'calculateHeatingMode', 'level' => 'info', 'message' => $message]);
			$log->save();

			return $log;
		}

		private function filter(string $field, string $operator, string $value) : array
		{
			return ['field' => $field, 'operator' => $operator, 'value' => $value];
		}

		private function search(string $type, array $criteria)
		{
			return $this->get('/logs/'.$type.'?'.http_build_query($criteria));
		}
	}
