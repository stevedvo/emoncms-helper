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
        config()->set('hive.radiatorHysteresis', 0.5);
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
            'UFH boost must not create Rad demand' => [24, 19.8, 10, true, false, null, 'boost', false, 16],
            'boost adjusted Rad threshold' => [22.4, 20.5, 10, true, false, null, 'boost', true, 30],
            'peak adjusted Rad threshold' => [20, 19.8, 10, false, true, null, 'intermittent', false, 16],
            'peak threshold equality' => [19.5, 20.5, 10, false, true, null, 'intermittent', true, 30],
            'warm forecast keeps radiator demand' => [21, 19.8, 10, false, false, 14, 'on', true, 30],
            'warm forecast keeps base threshold equality' => [21.5, 20.5, 10, false, false, 14, 'intermittent', true, 30],
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
        foreach ([[22, 16, 'intermittent'], [21.5, 16, 'intermittent'], [21, 30, 'on'], [21.6, 30, 'intermittent'], [22, 16, 'intermittent']] as [$rad, $target, $mode]) {
            ControlledNibeController::prepare($rad, 19.8);
            $this->assertSame($mode, ControlledNibeController::calculateHeatingMode(30, 10, 10));
            $this->assertHiveTarget(30, $target);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
        }
    }

    public function test_peak_adjusted_radiator_demand_holds_its_state_between_both_boundaries() : void
    {
        foreach ([[19.6, false], [19.5, false], [19.1, false], [19, true], [19.5, true], [19.6, true], [19.9, true], [20, false], [19.6, false]] as [$rad, $required])
        {
            ControlledNibeController::prepare($rad, 19.8, false, true);
            ControlledNibeController::calculateHeatingMode(30, 10, 10);
            $this->assertSame($required, RadiatorHeatDemand::current(), 'Radiator temperature '.$rad);
            $this->assertHiveTarget(30, $required ? 30 : 16);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
        }

        $message = ActivityLog::where('controller', RadiatorHeatDemand::class)->where('method', 'calculate')->latest('id')->first()->message;
        $this->assertStringContainsString('adjusted threshold 19.5; hysteresis +/-0.5; on at 19; off at 20', $message);
        $this->assertStringContainsString('previous false; required false', $message);
    }

    public function test_hysteresis_follows_changes_to_the_adjusted_radiator_threshold() : void
    {
        ControlledNibeController::prepare(21.2, 19.8);
        ControlledNibeController::calculateHeatingMode(30, 10, 10);
        $this->assertTrue(RadiatorHeatDemand::current());

        ControlledNibeController::prepare(21.2, 19.8, false, true);
        ControlledNibeController::calculateHeatingMode(30, 10, 10);
        $this->assertFalse(RadiatorHeatDemand::current());

        ControlledNibeController::prepare(21.2, 19.8, true);
        ControlledNibeController::calculateHeatingMode(30, 10, 10);
        $this->assertTrue(RadiatorHeatDemand::current());
    }

    public function test_real_peak_window_keeps_the_radiator_target_until_1600() : void
    {
        config()->set('nibe.loadCompTempOff', 21);
        config()->set('nibe.loadCompTempIntermittent', 20);
        $start = CarbonImmutable::parse('2026-10-22 14:35:00 Europe/London');
        CarbonImmutable::setTestNow($start);
        RadiatorHeatDemand::record(true);

        // Reproduce a warm radiator retaining demand until the actual peak begins.
        foreach (range(0, 85, 5) as $minutes)
        {
            CarbonImmutable::setTestNow($start->addMinutes($minutes));
            ScheduledPeakNibeController::prepare(21.2, 20.5);
            $this->assertSame('intermittent', ScheduledPeakNibeController::calculateHeatingMode(30, 10, 10));
            $required = $minutes < 85;
            $this->assertSame($required, RadiatorHeatDemand::current());
            $this->assertHiveTarget(30, $required ? 30 : 16);
        }
    }

    public function test_real_peak_window_allows_ufh_preheating_before_1600() : void
    {
        config()->set('nibe.allowBoosts', false);

        foreach ([['14:40:00', 'intermittent'], ['15:59:59', 'intermittent'], ['16:00:00', 'off']] as [$time, $mode])
        {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 '.$time.' Europe/London'));
            ScheduledPeakNibeController::prepare(26, 20.8, true);
            $this->assertSame($mode, ScheduledPeakNibeController::calculateHeatingMode(10, 10, 10));
            $this->assertFalse(RadiatorHeatDemand::current());
            $this->assertHiveTarget(30, 16);
        }
    }

    public function test_radiator_mode_demand_and_valve_demand_use_the_same_hysteresis() : void
    {
        RadiatorHeatDemand::record(false);
        foreach ([[21.2, 'off', false], [21, 'on', true], [21.6, 'intermittent', true], [22, 'off', false]] as [$rad, $mode, $required])
        {
            ControlledNibeController::prepare($rad, 20.5);
            $this->assertSame($mode, ControlledNibeController::calculateHeatingMode(10, 10, 10));
            $this->assertSame($required, RadiatorHeatDemand::current());
        }
    }

    public function test_hysteresis_margin_is_live_and_zero_restores_the_original_comparison() : void
    {
        RadiatorHeatDemand::record(false);
        $this->assertFalse(RadiatorHeatDemand::calculate(19.2, 19.5));
        $margin = Setting::create(['key' => 'control.hive.radiatorHysteresis', 'value' => '0.2']);
        $this->assertTrue(RadiatorHeatDemand::calculate(19.2, 19.5));
        RadiatorHeatDemand::record(true);
        $this->assertTrue(RadiatorHeatDemand::calculate(19.6, 19.5));

        $margin->update(['value' => '0']);
        $this->assertFalse(RadiatorHeatDemand::calculate(19.6, 19.5));
        $this->assertTrue(RadiatorHeatDemand::calculate(19.5, 19.5));
        RadiatorHeatDemand::record(false);
        $this->assertTrue(RadiatorHeatDemand::calculate(19.5, 19.5));
    }

    public function test_fractional_hysteresis_boundaries_are_inclusive() : void
    {
        Setting::create(['key' => 'control.hive.radiatorHysteresis', 'value' => '0.1']);
        RadiatorHeatDemand::record(false);
        $this->assertTrue(RadiatorHeatDemand::calculate(19.2, 19.3));
        RadiatorHeatDemand::record(true);
        $this->assertFalse(RadiatorHeatDemand::calculate(19.4, 19.3));
    }

    #[DataProvider('invalidDecisions')]
    public function test_hysteresis_initialises_at_the_original_threshold_without_a_valid_decision(?string $stored) : void
    {
        if ($stored !== null)
        {
            Setting::create(['key' => RadiatorHeatDemand::SETTING_KEY, 'value' => $stored]);
        }

        $this->assertTrue(RadiatorHeatDemand::calculate(19.5, 19.5));
        $this->assertFalse(RadiatorHeatDemand::calculate(19.6, 19.5));
        $this->assertSame(0, ActivityLog::where('level', 'warning')->count());
    }

    public function test_hysteresis_discards_stale_and_future_decisions() : void
    {
        RadiatorHeatDemand::record(false);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));
        $this->assertFalse(RadiatorHeatDemand::calculate(19.5, 19.5));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $this->assertTrue(RadiatorHeatDemand::calculate(19.5, 19.5));

        RadiatorHeatDemand::record(true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->subSecond());
        $this->assertFalse(RadiatorHeatDemand::calculate(19.6, 19.5));
    }

    public function test_disallowed_boost_promotion_preserves_existing_room_band_adjustments(): void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(22.4, 21.5, true);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(30, 10, 10));
        $this->assertTrue(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 30);
    }

    public function test_room_parameters_change_on_the_next_calculation_without_restarting(): void
    {
        ControlledNibeController::prepare(22, 20.5);
        $this->assertSame('off', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        Setting::create(['key' => 'control.nibe.loadCompTempOff', 'value' => '22.5']);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertTrue(RadiatorHeatDemand::current());

        ControlledNibeController::prepare(24, 20.5);
        $this->assertSame('off', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        Setting::create(['key' => 'control.nibe.ufhTempIntermittent', 'value' => '21']);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());
    }

    #[DataProvider('ufhPreheatScenarios')]
    public function test_ufh_preheats_only_outside_peak_and_keeps_its_normal_protection(float $ufh, bool $boost, bool $peak, string $expectedMode) : void
    {
        // Isolate the UFH thresholds from the separate, optional boost mode promotion.
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, $ufh, $boost, $peak);
        $this->assertSame($expectedMode, ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 16);
    }

    public static function ufhPreheatScenarios() : array
    {
        return [
            'normal above protection' => [20.1, false, false, 'off'],
            'normal intermittent boundary' => [20, false, false, 'intermittent'],
            'normal on boundary' => [19.5, false, false, 'on'],
            'preheat above normal threshold' => [20.1, true, false, 'on'],
            'preheat on boundary' => [20.5, true, false, 'on'],
            'preheat intermittent band' => [20.6, true, false, 'intermittent'],
            'preheat intermittent boundary' => [21, true, false, 'intermittent'],
            'preheat sufficiently warm' => [21.1, true, false, 'off'],
            'peak does not preheat' => [20.1, true, true, 'off'],
            'peak keeps early start' => [20, true, true, 'intermittent'],
            'peak keeps continuous protection' => [19.5, true, true, 'on'],
            'peak without boost keeps early start' => [19.8, false, true, 'intermittent'],
        ];
    }

    public function test_ufh_preheat_margin_is_live_and_can_be_disabled() : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, 20.8, true);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $margin = Setting::create(['key' => 'control.nibe.ufhBoostTempAdjust', 'value' => '0']);
        $this->assertSame('off', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $margin->update(['value' => '2']);
        $this->assertSame('boost', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());
    }

    #[DataProvider('ufhBoostScenarios')]
    public function test_ufh_shortfall_requests_at_least_boost_without_creating_radiator_demand(float $ufh, bool $preheat, bool $peak, string $expectedMode) : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, $ufh, $preheat, $peak);
        $this->assertSame($expectedMode, ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 16);
    }

    public static function ufhBoostScenarios() : array
    {
        return [
            'normal below boost shortfall' => [19.1, false, false, 'on'],
            'normal exact shortfall is not more than X' => [19, false, false, 'on'],
            'normal over boost shortfall' => [18.9, false, false, 'boost'],
            'preheat below boost shortfall' => [20.1, true, false, 'on'],
            'preheat exact shortfall is not more than X' => [20, true, false, 'on'],
            'preheat over boost shortfall' => [19.9, true, false, 'boost'],
            'peak suppresses the preheat boost threshold' => [19.9, true, true, 'intermittent'],
            'peak exact normal shortfall' => [19, true, true, 'on'],
            'peak retains boost for a large comfort shortfall' => [18.9, true, true, 'boost'],
            'large UFH shortfall does not request extraBoost' => [16, false, false, 'boost'],
        ];
    }

    public function test_ufh_boost_shortfall_setting_is_live_and_logged() : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, 19.3);
        $this->assertSame('on', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $shortfall = Setting::create(['key' => 'control.nibe.ufhBoostTempShortfall', 'value' => '0.5']);
        $this->assertSame('boost', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $message = ActivityLog::where('message', 'like', 'UFH_temperature shortfall%')->latest('id')->first()->message;
        $this->assertStringContainsString('shortfall 0.7 > 0.5', $message);
        $this->assertStringContainsString('target 20, temperature 19.3, preheat +0', $message);
        $shortfall->update(['value' => '2']);
        $this->assertSame('on', ControlledNibeController::calculateHeatingMode(10, 10, 10));
    }

    public function test_fractional_ufh_boost_boundary_uses_the_configured_target_and_margin() : void
    {
        config()->set('nibe.allowBoosts', false);
        Setting::create(['key' => 'control.nibe.ufhTempIntermittent', 'value' => '20.2']);
        Setting::create(['key' => 'control.nibe.ufhBoostTempAdjust', 'value' => '0.3']);
        Setting::create(['key' => 'control.nibe.ufhBoostTempShortfall', 'value' => '1.1']);
        ControlledNibeController::prepare(26, 19.4, true);
        $this->assertSame('on', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        ControlledNibeController::prepare(26, 19.3, true);
        $this->assertSame('boost', ControlledNibeController::calculateHeatingMode(10, 10, 10));
    }

    public function test_ufh_boost_preserves_higher_demand_and_obeys_the_defrost_cap() : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(18, 18.9);
        $this->assertSame('extraBoost', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        ControlledNibeController::prepare(26, 18.9, false, false, true);
        $this->assertSame('on', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());
        $this->assertHiveTarget(30, 16);
    }

    public function test_ufh_preheat_uses_configured_base_thresholds_and_logs_the_adjustment() : void
    {
        config()->set('nibe.allowBoosts', false);
        Setting::create(['key' => 'control.nibe.ufhTempOn', 'value' => '20']);
        Setting::create(['key' => 'control.nibe.ufhTempIntermittent', 'value' => '20.5']);
        Setting::create(['key' => 'control.nibe.ufhBoostTempAdjust', 'value' => '0.5']);
        ControlledNibeController::prepare(26, 20.7, true);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $message = ActivityLog::where('message', 'like', 'UFH_temperature%')->latest('id')->first()->message;
        $this->assertStringContainsString('UFH_temperature <= 21', $message);
        $this->assertStringContainsString('temperature 20.7, preheat +0.5', $message);
    }

    public function test_forecasts_do_not_shift_the_ufh_thresholds_outside_boost_windows() : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, 19.8, false, true);
        $this->setForecast(14);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertFalse(RadiatorHeatDemand::current());

        Setting::where('key', 'weather_data')->delete();
        $this->setForecast(2);
        ControlledNibeController::prepare(26, 20.1);
        $this->assertSame('off', ControlledNibeController::calculateHeatingMode(10, 10, 10));
    }

    public function test_missing_ufh_or_disabled_compensation_skips_preheating() : void
    {
        config()->set('nibe.allowBoosts', false);
        ControlledNibeController::prepare(26, null, true);
        $this->assertSame('off', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertSame(0, ActivityLog::where('message', 'like', 'UFH_temperature%')->count());

        config()->set('nibe.loadCompensationOn', false);
        ControlledNibeController::prepare(26, 20.1, true);
        $this->assertSame('intermittent', ControlledNibeController::calculateHeatingMode(10, 10, 10));
        $this->assertSame(0, ActivityLog::where('message', 'like', 'UFH_temperature%')->count());
    }

    public function test_cooling_still_overrides_ufh_preheating() : void
    {
        ControlledNibeController::prepare(26, 19.8, true);
        $this->assertSame('cooling', ControlledNibeController::calculateHeatingMode(60, 25, 25));
        $this->assertFalse(RadiatorHeatDemand::current());
        $this->assertHiveTarget(60, 30);
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

    public static function prepare(?float $rad, ?float $ufh, bool $boost = false, bool $peak = false, bool $defrost = false): void
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

class ScheduledPeakNibeController extends ControlledNibeController
{
    public static function isPeakImport(CarbonInterface $time) : bool
    {
        return NibeController::isPeakImport($time);
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
