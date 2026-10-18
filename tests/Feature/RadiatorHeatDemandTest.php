<?php

namespace Tests\Feature;

use App\APIs\HomeAssistantAPI;
use App\Http\Controllers\HomeAssistantController;
use App\Http\Controllers\NibeController;
use App\Models\ActivityLog;
use App\Models\NibeFeedItem;
use App\Models\Setting;
use App\Services\RadiatorHeatDemand;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RadiatorHeatDemandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use an isolated database; never migrate or query the installation's database.
        config()->set('database.default', 'radiator_control_testing');
        config()->set('database.connections.radiator_control_testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        config()->set('nibe.dmOverride', true);
        config()->set('nibe.loadCompensationOn', true);
        config()->set('nibe.allowBoosts', true);
        config()->set('nibe.loadCompTempOff', 21.5);
        config()->set('nibe.loadCompTempIntermittent', 21);
        config()->set('nibe.loadCompTempOn', 20);
        config()->set('nibe.loadCompTempLevel1', 19);
        config()->set('nibe.dmTargetBoostTemp', 3);
        config()->set('nibe.runLevel1Temp', 12);
        config()->set('nibe.coolingStartTemp', 24);
        config()->set('weather.useForecast', false);
        config()->set('hive.targetOnTemp', 30);
        config()->set('hive.targetOffTemp', 16);
        config()->set('hive.radiatorDemandMaxAgeMinutes', 10);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 02:00:00 UTC'));

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('key');
            $table->text('value');
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('controller');
            $table->string('method');
            $table->string('level');
            $table->text('message');
        });
        Schema::create('nibe_feed_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('parameterId');
            $table->bigInteger('timestamp');
            $table->decimal('rawValue');
            $table->integer('syncAttempts');
            $table->string('syncStatus');
        });
        Schema::create('emon_feed_maps', function (Blueprint $table) {
            $table->integer('localFeedId');
            $table->string('localName');
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('zoneScenarios')]
    public function test_room_demand_controls_hive_independently_of_overall_mode(
        float $rad, float $ufh, int $priority, bool $boost, bool $peak,
        ?float $forecast, string $expectedMode, bool $expectedDemand, int $expectedTarget
    ): void {
        if ($forecast !== null) {
            $this->setForecast($forecast);
        }
        ControlledNibeController::prepare($rad, $ufh, $boost, $peak);

        $this->assertSame($expectedMode, ControlledNibeController::calculateHeatingMode($priority, 10, 10));
        $this->assertSame($expectedDemand, RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, $expectedTarget);
        $this->assertSame(0, ActivityLog::where('level', 'error')->count());
    }

    public static function zoneScenarios(): array
    {
        return [
            'UFH only, intermittent' => [22, 19.8, 10, false, false, null, 'intermittent', false, 16],
            'UFH only, on' => [22, 19.4, 10, false, false, null, 'on', false, 16],
            'UFH exactly 20' => [22, 20, 10, false, false, null, 'intermittent', false, 16],
            'UFH exactly 19.5' => [22, 19.5, 10, false, false, null, 'on', false, 16],
            'already heating must not create Rad demand' => [22, 19.8, 30, false, false, null, 'intermittent', false, 16],
            'both zones' => [20.5, 19.8, 10, false, false, null, 'on', true, 30],
            'Rad only' => [20.5, 20.5, 10, false, false, null, 'on', true, 30],
            'Rad exactly off threshold' => [21.5, 20.5, 10, false, false, null, 'intermittent', true, 30],
            'Rad above off threshold' => [21.6, 20.5, 10, false, false, null, 'off', false, 16],
            'boost promotion must not create Rad demand' => [24, 19.8, 10, true, false, null, 'intermittent', false, 16],
            'boost adjusted Rad threshold' => [22.4, 20.5, 10, true, false, null, 'boost', true, 30],
            'peak adjusted Rad threshold' => [20, 19.8, 10, false, true, null, 'intermittent', false, 16],
            'peak threshold equality' => [19.5, 20.5, 10, false, true, null, 'intermittent', true, 30],
            'warm forecast and UFH demand' => [21, 19.8, 10, false, false, 14, 'intermittent', false, 16],
            'warm forecast threshold equality' => [20.5, 20.5, 10, false, false, 14, 'intermittent', true, 30],
            'cold forecast threshold equality' => [23.5, 20.5, 10, false, false, 2, 'intermittent', true, 30],
            'cold peak minimum must not create Rad demand' => [22, 20.5, 10, false, true, 2, 'intermittent', false, 16],
        ];
    }

    public function test_defrost_cap_and_cooling_do_not_change_the_radiator_demand_decision(): void
    {
        ControlledNibeController::prepare(18, 19.4, false, false, true);
        $this->assertSame('on', ControlledNibeController::calculateHeatingMode(30, 10, 10));
        $this->assertTrue(RadiatorHeatDemand::current());

        ControlledNibeController::prepare(25, 20.5);
        $this->assertSame('cooling', ControlledNibeController::calculateHeatingMode(60, 25, 25));
        $this->assertFalse(RadiatorHeatDemand::current());
        $this->assertHiveTarget(60, 30);
    }

    public function test_radiators_can_join_and_leave_a_running_ufh_heating_cycle(): void
    {
        foreach ([[22, 16], [21.5, 30], [22.1, 16]] as [$rad, $target]) {
            ControlledNibeController::prepare($rad, 19.8);
            $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(30, 10, 10));
            $this->assertHiveTarget(30, $target);
        }
    }

    public function test_disallowed_boost_promotion_preserves_existing_room_band_adjustments(): void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(22.4, 20.5, true);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(30, 10, 10));
        $this->assertTrue(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 30);
    }

    public function test_missing_room_data_replaces_a_previous_off_decision_with_unknown(): void
    {
        RadiatorHeatDemand::record(false);
        ControlledNibeController::prepare(null, 19.8);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(30, 10, 10));
        $this->assertNull(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 30);
    }

    public function test_disabled_load_compensation_does_not_reuse_an_off_decision(): void
    {
        RadiatorHeatDemand::record(false);
        config()->set('nibe.loadCompensationOn', false);
        ControlledNibeController::prepare(22, 19.8);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(30, 10, 10));
        $this->assertNull(json_decode(Setting::firstWhere('key', RadiatorHeatDemand::SETTING_KEY)->value, true)['required']);
        $this->assertHiveTarget(30, 30);
    }

    public function test_disabling_dm_override_ignores_a_previously_saved_decision(): void
    {
        RadiatorHeatDemand::record(false);
        config()->set('nibe.dmOverride', false);
        $this->assertHiveTarget(30, 30);
    }

    public function test_a_repeated_decision_refreshes_its_calculation_time(): void
    {
        ControlledNibeController::prepare(22, 19.8);
        ControlledNibeController::calculateHeatingMode(30, 10, 10);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
        ControlledNibeController::calculateHeatingMode(30, 10, 10);
        $decision = json_decode(Setting::firstWhere('key', RadiatorHeatDemand::SETTING_KEY)->value, true);
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $decision['calculatedAt']);
        $this->assertFalse($decision['required']);
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_or_missing_decisions_fall_back_and_log_the_reason(?string $stored): void
    {
        if ($stored !== null) {
            Setting::create(['key' => RadiatorHeatDemand::SETTING_KEY, 'value' => $stored]);
        }
        $this->assertHiveTarget(30, 30);
        $this->assertStringContainsString('using priority-based Hive control', ActivityLog::where('level', 'warning')->first()->message);
    }

    public static function invalidDecisions(): array
    {
        return [
            'missing' => [null],
            'invalid JSON' => ['{'],
            'non-object' => ['false'],
            'missing timestamp' => ['{"required":false}'],
            'string timestamp' => ['{"required":false,"calculatedAt":"1792288800"}'],
            'string boolean' => ['{"required":"false","calculatedAt":1792288800}'],
            'unknown demand' => ['{"required":null,"calculatedAt":1792288800}'],
        ];
    }

    public function test_stale_and_future_decisions_fall_back_while_the_age_boundary_is_valid(): void
    {
        RadiatorHeatDemand::record(false);
        $recordedAt = CarbonImmutable::now();
        CarbonImmutable::setTestNow($recordedAt->addMinutes(10));
        $this->assertHiveTarget(30, 16);
        CarbonImmutable::setTestNow($recordedAt->addMinutes(10)->addSecond());
        $this->assertHiveTarget(30, 30);
        CarbonImmutable::setTestNow($recordedAt->subSecond());
        $this->assertHiveTarget(30, 30);
    }

    public function test_the_freshness_limit_and_hive_targets_are_configurable(): void
    {
        config()->set('hive.radiatorDemandMaxAgeMinutes', 15);
        config()->set('hive.targetOffTemp', 15);
        config()->set('hive.targetOnTemp', 28);
        RadiatorHeatDemand::record(false);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
        $this->assertHiveTarget(30, 15);
        RadiatorHeatDemand::record(true);
        $this->assertHiveTarget(30, 28);
    }

    public function test_hot_water_idle_and_cooling_retain_their_targets_without_a_decision(): void
    {
        $this->assertHiveTarget(20, 16);
        $this->assertHiveTarget(10, 16);
        $this->assertHiveTarget(60, 30);
        $this->assertSame(0, ActivityLog::where('level', 'warning')->count());
    }

    public function test_a_missing_initial_room_fallback_disables_compensation_without_throwing(): void
    {
        $this->assertNull(RoomTemperatureProbe::read());
        $this->assertSame('false', Setting::firstWhere('key', 'allowLoadCompensation')->value);
    }

    private function setForecast(float $temperature): void
    {
        config()->set('weather.useForecast', true);
        $rows = [];
        for ($hour = 0; $hour <= 30; $hour++) {
            $rows[] = [
                'datetime' => CarbonImmutable::now()->addHours($hour)->format('c'),
                'temperature' => $temperature,
                'humidity' => 70,
            ];
        }
        Setting::create(['key' => 'weather_data', 'value' => json_encode($rows)]);
    }

    private function assertHiveTarget(int $priority, int $expectedTarget): void
    {
        NibeFeedItem::create([
            'parameterId' => 49994, 'timestamp' => CarbonImmutable::now()->getTimestamp(),
            'rawValue' => $priority, 'syncAttempts' => 0, 'syncStatus' => 'success',
        ]);
        $api = Mockery::mock(HomeAssistantAPI::class);
        $api->shouldReceive('adjustHiveThermostat')->once()->with($expectedTarget);
        $this->app->instance(HomeAssistantAPI::class, $api);
        HomeAssistantController::adjustHiveThermostat();
    }
}

/** Supply sensor/window inputs while exercising the production decision order and persistence. */
class ControlledNibeController extends NibeController
{
    private static bool $boost;
    private static bool $peak;
    private static bool $defrost;

    public static function prepare(?float $rad, float $ufh, bool $boost = false, bool $peak = false, bool $defrost = false): void
    {
        foreach (['loadCompensationOn', 'loadCompTempOff', 'loadCompTempIntermittent', 'loadCompTempOn', 'loadCompTempLevel1'] as $property) {
            static::${$property} = null;
        }
        static::initConfig();
        static::$roomTemperature = ['Rad_temperature' => $rad, 'UFH_temperature' => $ufh];
        self::$boost = $boost;
        self::$peak = $peak;
        self::$defrost = $defrost;
        Setting::updateOrCreate(['key' => 'allowLoadCompensation'], ['value' => $rad === null ? 'false' : 'true']);
    }

    protected static function getRoomTemperature(string $feedName, bool $updateSettings = true): ?float
    {
        return static::$roomTemperature[$feedName];
    }

    public static function isBoostActive(float $outdoorTemp, float $avgOutdoorTemp): bool
    {
        return self::$boost;
    }

    public static function isPeakImport(CarbonInterface $time): bool
    {
        return self::$peak;
    }

    public static function isReducingDefrosts(): bool
    {
        return self::$defrost;
    }
}

class RoomTemperatureProbe extends NibeController
{
    public static function read(): ?float
    {
        static::$roomTemperature = null;
        return parent::getRoomTemperature('Rad_temperature');
    }
}
