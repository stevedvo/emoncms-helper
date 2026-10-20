<?php

namespace Tests\Feature;

use App\Http\Controllers\NibeController;
use App\Models\ActivityLog;
use App\Models\NibeFeedItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\ControlSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ControlPanelDatabase;
use Tests\TestCase;

class ControlPanelTest extends TestCase
{
	use ControlPanelDatabase;

	protected function setUp(): void
	{
		parent::setUp();
		$this->prepareControlPanelDatabase();
		Http::preventStrayRequests();
		config()->set('emon.emonHpLocalEndpoint', 'http://emon.test');
		config()->set('emon.emonHpLocalReadKey', 'private-read-key');
	}

	public function test_control_panel_and_feed_data_require_authentication(): void
	{
		foreach (['/', '/settings', '/dashboard/feeds'] as $url) {
			$this->get($url)->assertRedirect(route('login'));
		}
		$this->put('/settings', ['values' => ['nibe__dmTarget' => -200]])->assertRedirect(route('login'));
		$this->getJson('/dashboard/feeds')->assertUnauthorized();
		$this->assertSame(0, Setting::count());
	}

	public function test_sign_in_and_sign_out_use_the_existing_user_accounts(): void
	{
		$user = User::factory()->create(['email' => 'owner@example.test', 'password' => Hash::make('a-private-test-password')]);
		$this->get('/login')->assertOk()->assertSee('Sign in');
		$this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
		$this->assertGuest();
		$this->post('/login', ['email' => $user->email, 'password' => 'a-private-test-password'])->assertRedirect(route('dashboard'));
		$this->assertAuthenticatedAs($user);
		$this->post('/logout')->assertRedirect(route('login'));
		$this->assertGuest();
	}

	public function test_sign_in_attempts_are_throttled(): void
	{
		for ($attempt = 0; $attempt < 6; $attempt++) {
			$this->post('/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertStatus(302);
		}
		$this->post('/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertStatus(429);
	}

	public function test_first_settings_visit_imports_current_values_and_hides_secrets_and_runtime_state(): void
	{
		$this->actingAs(User::factory()->create());
		config()->set('nibe.dmTarget', -225);
		config()->set('nibe.clientSecret', 'secret-not-for-the-page');
		Setting::create(['key' => 'nibe_token_current', 'value' => 'private-bearer-token']);
		Setting::create(['key' => 'weather_data', 'value' => 'private-weather-state']);
		$this->get('/settings')->assertOk()->assertSee('value="-225"', false)
			->assertSee('UFH: allow heating at')->assertSee('No scheduler restart needed.')
			->assertSee('Radiator demand hysteresis (each side)')
			->assertDontSee('secret-not-for-the-page')->assertDontSee('private-bearer-token')->assertDontSee('private-weather-state');
		$this->assertSame(count(ControlSettings::definitions()), Setting::where('key', 'like', 'control.%')->count());
		$this->assertSame(-225, ControlSettings::get('nibe.dmTarget'));
	}

	public function test_import_command_preserves_saved_values_and_runtime_state(): void
	{
		Setting::create(['key' => 'control.nibe.dmTarget', 'value' => '-230']);
		Setting::create(['key' => 'nibe_token_current', 'value' => 'keep-this-token']);
		$this->artisan('settings:import')->assertSuccessful();
		config()->set('nibe.dmTarget', 'an-obsolete-invalid-environment-value');
		$this->artisan('settings:import')->expectsOutput('Imported 0 operating parameters. Existing database settings were preserved.')->assertSuccessful();
		$this->assertSame(-230, ControlSettings::get('nibe.dmTarget'));
		$this->assertSame('keep-this-token', Setting::firstWhere('key', 'nibe_token_current')->value);
	}

	public function test_saved_values_are_typed_audited_and_used_without_restarting_the_process(): void
	{
		$user = User::factory()->create();
		$this->actingAs($user);
		ControlSettings::initialize();
		$this->assertSame(-180, NibeController::calculateTargetDm(30, 'on', 10));
		$this->put('/settings', $this->payload([
			'nibe__dmTarget' => '-210', 'nibe__offsetFactor' => '1.5', 'weather__useForecast' => '0',
		]))->assertRedirect('/settings')->assertSessionHasNoErrors();
		$this->assertSame(-210, ControlSettings::get('nibe.dmTarget'));
		$this->assertSame(1.5, ControlSettings::get('nibe.offsetFactor'));
		$this->assertFalse(ControlSettings::get('weather.useForecast'));
		$this->assertSame(-180, config('nibe.dmTarget'));
		$this->assertSame(-210, NibeController::calculateTargetDm(30, 'on', 10));
		$this->assertSame(3, ActivityLog::where('controller', ControlSettings::class)->where('method', 'save')->count());
		$this->assertStringContainsString('User #'.$user->id, ActivityLog::where('method', 'save')->first()->message);
	}

	#[DataProvider('invalidSettings')]
	public function test_invalid_updates_do_not_partially_save(array $invalid): void
	{
		$this->actingAs(User::factory()->create());
		ControlSettings::initialize();
		$before = ControlSettings::values();
		$this->put('/settings', $this->payload(array_merge(['nibe__dmTarget' => -210], $invalid)))
			->assertSessionHasErrors();
		$this->assertSame($before, ControlSettings::values());
		$this->assertSame(0, ActivityLog::count());
	}

	public static function invalidSettings(): array
	{
		return [
			'zero steering horizon' => [['nibe__minutesToDm' => 0]],
			'zero offset factor' => [['nibe__offsetFactor' => 0]],
			'invalid boolean' => [['nibe__dmOverride' => 'maybe']],
			'fractional integer' => [['hive__targetOnTemp' => 29.5]],
			'negative radiator hysteresis' => [['hive__radiatorHysteresis' => -0.1]],
			'excessive radiator hysteresis' => [['hive__radiatorHysteresis' => 2.1]],
			'unordered room bands' => [['nibe__loadCompTempOff' => 18]],
			'unordered UFH bands' => [['nibe__ufhTempOn' => 21]],
            'negative UFH preheat margin' => [['nibe__ufhBoostTempAdjust' => -0.1]],
            'excessive UFH preheat margin' => [['nibe__ufhBoostTempAdjust' => 3.1]],
			'zero UFH boost shortfall' => [['nibe__ufhBoostTempShortfall' => 0]],
			'excessive UFH boost shortfall' => [['nibe__ufhBoostTempShortfall' => 5.1]],
			'start offset below range' => [['octopus__offsetToOn' => -181]],
			'end offset above range' => [['octopus__offsetToOff' => 181]],
			'fractional start offset' => [['octopus__offsetToOn' => -15.5]],
			'empty shortest boost window' => [['octopus__offsetToOff' => -135]],
			'reversed shortest boost window' => [['octopus__offsetToOff' => -136]],
			'unordered outdoor bands' => [['nibe__runLevel2Temp' => 14]],
			'unordered dew points' => [['nibe__defrostDewpointMax' => -4]],
			'offset limit outside range' => [['nibe__offsetMinimum' => -2]],
			'unordered Hive targets' => [['hive__targetOffTemp' => 31]],
			'boost weaker than normal' => [['nibe__dmTargetBoost' => -100]],
			'secret parameter' => [['nibe__clientSecret' => 'not-allowed']],
			'runtime state' => [['radiatorHeatDemand' => false]],
		];
	}

	public function test_a_stale_form_cannot_overwrite_a_newer_edit(): void
	{
		$this->actingAs(User::factory()->create());
		ControlSettings::initialize();
		$payload = $this->payload(['nibe__dmTarget' => -220]);
		Setting::where('key', 'control.nibe.dmTarget')->update(['value' => '-200']);
		$this->put('/settings', $payload)->assertSessionHasErrors('revision');
		$this->assertSame(-200, ControlSettings::get('nibe.dmTarget'));
		$this->get('/settings')->assertSee('name="revision" value="'.$payload['revision'].'"', false);
	}

	public function test_settings_updates_require_csrf_protection(): void
	{
		$this->actingAs(User::factory()->create());
		$this->app['env'] = 'local';
		$this->put('/settings', $this->payload(['nibe__dmTarget' => -220]))->assertStatus(419);
		$this->assertSame(-180, ControlSettings::get('nibe.dmTarget'));
	}

	public function test_empty_dashboard_and_stored_readings_render_without_calling_external_services(): void
	{
		$this->actingAs(User::factory()->create());
		$this->get('/')->assertOk()->assertSee('No NIBE readings yet.');
		DB::table('nibe_parameters')->insert(['parameterId' => 40004, 'title' => 'Outdoor temperature', 'unit' => '°C']);
		NibeFeedItem::create(['parameterId' => 40004, 'timestamp' => now()->timestamp, 'rawValue' => 12.3, 'syncStatus' => 'success']);
		$this->get('/')->assertOk()->assertSee('Outdoor temperature')->assertSee('12.3')->assertSee('Sample time (UK)');
		Http::assertNothingSent();
	}

	public function test_emon_feed_endpoint_returns_latest_values_and_preserves_zero(): void
	{
		$this->actingAs(User::factory()->create());
		Http::fake(['emon.test/*' => Http::response([
			['id' => 1, 'name' => 'total_Power', 'value' => '0', 'time' => now()->timestamp, 'apikey' => 'must-not-be-returned'],
			['id' => 2, 'name' => 'Rad_temperature', 'value' => 21.4, 'time' => now()->subHours(2)->timestamp],
			['id' => 3, 'name' => 'UFH_temperature', 'value' => null, 'time' => now()->subHours(4)->timestamp],
		])]);
		$response = $this->getJson('/dashboard/feeds')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
		$feeds = collect($response->json('feeds'))->keyBy('name');
		$this->assertEquals(0, $feeds['total_Power']['value']);
		$this->assertNull($feeds['total_Power']['room']);
		$this->assertSame('rad', $feeds['Rad_temperature']['room']);
		$this->assertSame('ufh', $feeds['UFH_temperature']['room']);
		$this->assertFalse($feeds['Rad_temperature']['stale']);
		$this->assertTrue($feeds['UFH_temperature']['stale']);
		$response->assertDontSee('must-not-be-returned')->assertDontSee('private-read-key');
		Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/feed/list.json'));
	}

	public function test_dashboard_room_feeds_use_mapped_ids_when_display_names_are_shared() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature'],
			['localFeedId' => 14, 'localName' => 'UFH_temperature', 'remoteFeedId' => 114, 'remoteName' => 'UFH_temperature'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => '17', 'name' => 'temperature', 'tag' => 'Main building', 'value' => '21.4', 'time' => now()->subHours(2)->timestamp],
			['id' => '14', 'name' => 'temperature', 'tag' => 'Annexe', 'value' => '0', 'time' => now()->subHours(2)->timestamp],
		])]);
		$response = $this->getJson('/dashboard/feeds')->assertOk();
		$feeds = collect($response->json('feeds'))->keyBy('id');
		$this->assertSame('rad', $feeds[17]['room']);
		$this->assertSame('ufh', $feeds[14]['room']);
		$this->assertSame('temperature', $feeds[17]['name']);
		$this->assertEquals(21.4, $feeds[17]['value']);
		$this->assertEquals(0, $feeds[14]['value']);
		$this->assertFalse($feeds[17]['stale']);
		$this->assertFalse($feeds[14]['stale']);
	}

	public function test_mapped_room_ids_take_precedence_over_unrelated_matching_names() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature'],
			['localFeedId' => 14, 'localName' => 'UFH_temperature', 'remoteFeedId' => 114, 'remoteName' => 'UFH_temperature'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 17, 'name' => 'UFH_temperature', 'value' => 21.4, 'time' => now()->timestamp],
			['id' => 14, 'name' => 'Rad_temperature', 'value' => 19.8, 'time' => now()->timestamp],
			['id' => 99, 'name' => 'Rad_temperature', 'value' => 30, 'time' => now()->subHours(2)->timestamp],
		])]);
		$feeds = collect($this->getJson('/dashboard/feeds')->assertOk()->json('feeds'))->keyBy('id');
		$this->assertSame('rad', $feeds[17]['room']);
		$this->assertSame('ufh', $feeds[14]['room']);
		$this->assertNull($feeds[99]['room']);
		$this->assertTrue($feeds[99]['stale']);
	}

	public function test_missing_mapped_room_feed_is_not_replaced_by_a_name_match() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert(['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature']);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 99, 'name' => 'Rad_temperature', 'value' => 30, 'time' => now()->timestamp],
		])]);
		$this->getJson('/dashboard/feeds')->assertOk()->assertJsonPath('feeds.0.room', null);
	}

	public function test_dashboard_omits_nibe_exports_but_keeps_independent_sensor_readings() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('nibe_parameters')->insert(['parameterId' => 40004, 'title' => 'outdoor temp.', 'unit' => '°C']);
		DB::table('nibe_to_emon_feed_maps')->insert([
			['parameterId' => 40004, 'title' => 'outdoor temp.', 'localFeedId' => null, 'localName' => 'outdoor history'],
			['parameterId' => 40067, 'title' => 'avg. outdoor temp', 'localFeedId' => 201, 'localName' => 'renamed export'],
		]);
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature'],
			['localFeedId' => 206, 'localName' => 'heatmeter_FlowT', 'remoteFeedId' => 206, 'remoteName' => 'heatmeter_FlowT'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 201, 'name' => 'renamed reading', 'tag' => 'Custom tag', 'value' => 10],
			['id' => 202, 'name' => 'heating', 'tag' => 'NIBE', 'value' => 1],
			['id' => 203, 'name' => 'nibe_priority', 'value' => 30],
			['id' => 204, 'name' => 'outdoor temp.', 'value' => 10],
			['id' => 205, 'name' => 'outdoor temp.', 'tag' => 'Garden sensor', 'value' => 10],
			['id' => 206, 'name' => 'outdoor temp.', 'value' => 10],
			['id' => 207, 'name' => 'outdoor history', 'value' => 10],
			['id' => 17, 'name' => 'temperature', 'tag' => 'Room sensor', 'value' => 21.4],
		])]);
		$feeds = collect($this->getJson('/dashboard/feeds')->assertOk()->json('feeds'));
		$this->assertEqualsCanonicalizing([17, 205, 206], $feeds->pluck('id')->all());
		$this->assertSame('rad', $feeds->firstWhere('id', 17)['room']);
	}

	public function test_room_measurements_are_grouped_with_siblings_under_their_parent_device() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature'],
			['localFeedId' => 18, 'localName' => 'Rad_humidity', 'remoteFeedId' => 118, 'remoteName' => 'Rad_humidity'],
			['localFeedId' => 14, 'localName' => 'UFH_temperature', 'remoteFeedId' => 114, 'remoteName' => 'UFH_temperature'],
			['localFeedId' => 15, 'localName' => 'UFH_humidity', 'remoteFeedId' => 115, 'remoteName' => 'UFH_humidity'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 300, 'name' => 'temperature forecast', 'tag' => 'emonth_annexe', 'value' => 20.1],
			['id' => 14, 'name' => 'temperature', 'tag' => 'emonth_annexe', 'value' => 19.8],
			['id' => 17, 'name' => 'temperature', 'tag' => 'emonth_main', 'value' => 21.4],
			['id' => 15, 'name' => 'humidity', 'tag' => 'emonth_annexe', 'value' => 65],
			['id' => 18, 'name' => 'humidity', 'tag' => 'emonth_main', 'value' => 55],
		])]);
		$feeds = collect($this->getJson('/dashboard/feeds')->assertOk()->json('feeds'));
		$this->assertSame([18, 17, 15, 14, 300], $feeds->pluck('id')->all());
		$this->assertSame(['Radiator zone · Main building', 'UFH zone · Annexe'], $feeds->pluck('device')->unique()->values()->all());
		$this->assertNull($feeds->firstWhere('id', 18)['room']);
		$this->assertSame('rad', $feeds->firstWhere('id', 17)['room']);
		$this->assertSame('ufh', $feeds->firstWhere('id', 14)['room']);
	}

	public function test_device_tags_and_mapping_fallbacks_group_feeds_without_guessing_an_unknown_parent() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 1, 'localName' => 'heatmeter_Energy', 'remoteFeedId' => 1, 'remoteName' => 'heatmeter_Energy'],
			['localFeedId' => 7, 'localName' => 'total_Power', 'remoteFeedId' => 7, 'remoteName' => 'total_Power'],
			['localFeedId' => 18, 'localName' => 'Rad_humidity', 'remoteFeedId' => 118, 'remoteName' => 'Rad_humidity'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 1, 'name' => 'energy', 'tag' => 'Shared meter', 'value' => 10],
			['id' => 7, 'name' => 'power', 'tag' => 'Shared meter', 'value' => 0],
			['id' => 18, 'name' => 'humidity', 'value' => 55],
			['id' => 99, 'name' => 'temperature', 'value' => null],
			['id' => 100, 'name' => 'power', 'tag' => 'Device 2', 'value' => 20],
			['id' => 101, 'name' => 'power', 'tag' => 'Device 10', 'value' => 30],
		])]);
		$feeds = collect($this->getJson('/dashboard/feeds')->assertOk()->json('feeds'))->keyBy('id');
		$this->assertSame('Shared meter', $feeds[1]['device']);
		$this->assertSame('Shared meter', $feeds[7]['device']);
		$this->assertEquals(0, $feeds[7]['value']);
		$this->assertSame('Radiator zone · Main building', $feeds[18]['device']);
		$this->assertSame('Other feeds', $feeds[99]['device']);
		$this->assertNull($feeds[99]['value']);
		$this->assertLessThan($feeds->keys()->search(101), $feeds->keys()->search(100));
	}

	public function test_shared_tags_do_not_assign_an_unmapped_feed_to_an_arbitrary_room() : void
	{
		$this->actingAs(User::factory()->create());
		DB::table('emon_feed_maps')->insert([
			['localFeedId' => 17, 'localName' => 'Rad_temperature', 'remoteFeedId' => 117, 'remoteName' => 'Rad_temperature'],
			['localFeedId' => 14, 'localName' => 'UFH_temperature', 'remoteFeedId' => 114, 'remoteName' => 'UFH_temperature'],
		]);
		Http::fake(['emon.test/*' => Http::response([
			['id' => 17, 'name' => 'temperature', 'tag' => 'Shared sensors', 'value' => 21.4],
			['id' => 14, 'name' => 'temperature', 'tag' => 'Shared sensors', 'value' => 19.8],
			['id' => 99, 'name' => 'humidity', 'tag' => 'Shared sensors', 'value' => 60],
		])]);
		$feeds = collect($this->getJson('/dashboard/feeds')->assertOk()->json('feeds'))->keyBy('id');
		$this->assertSame('Radiator zone · Main building', $feeds[17]['device']);
		$this->assertSame('UFH zone · Annexe', $feeds[14]['device']);
		$this->assertSame('Shared sensors', $feeds[99]['device']);
	}

	public function test_a_response_containing_only_nibe_exports_has_no_additional_feeds() : void
	{
		$this->actingAs(User::factory()->create());
		Http::fake(['emon.test/*' => Http::response([
			['id' => 200, 'name' => 'temperature', 'tag' => 'nibe', 'value' => 10],
		])]);
		$this->getJson('/dashboard/feeds')->assertOk()->assertJsonPath('feeds', []);
	}

	public function test_emon_failure_has_a_clear_message_without_disclosing_service_details(): void
	{
		$this->actingAs(User::factory()->create());
		Http::fake(['*' => Http::response('private-read-key private exception details', 500)]);
		$this->getJson('/dashboard/feeds')->assertStatus(503)->assertSee('Local Emon is unavailable')->assertDontSee('private-read-key')->assertDontSee('private exception details');
		$this->get('/')->assertOk();
	}

	public function test_account_command_creates_a_hashed_password_and_does_not_replace_existing_accounts(): void
	{
		$this->artisan('app:create-user', ['email' => 'new@example.test'])
			->expectsQuestion('Name', 'Home owner')
			->expectsQuestion('Password (at least 12 characters)', 'a-private-test-password')
			->expectsQuestion('Confirm password', 'a-private-test-password')
			->assertSuccessful();
		$user = User::where('email', 'new@example.test')->firstOrFail();
		$this->assertTrue(Hash::check('a-private-test-password', $user->password));
		$this->assertNotSame('a-private-test-password', $user->password);
		$this->artisan('app:create-user', ['email' => 'new@example.test'])
			->expectsQuestion('Name', 'Another name')
			->expectsQuestion('Password (at least 12 characters)', 'another-test-password')
			->expectsQuestion('Confirm password', 'another-test-password')
			->assertFailed();
		$this->assertSame(1, User::count());
	}

	private function payload(array $values): array
	{
		return ['revision' => ControlSettings::revision(ControlSettings::values()), 'values' => $values];
	}
}
