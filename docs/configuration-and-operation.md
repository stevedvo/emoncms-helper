# Configuration and operation

[Project overview](../README.md) · [Heat pump control](heat-pump-control.md) · [Integrations](integrations-and-data.md) · [Implementation notes](implementation-notes.md)

Source entry points: [scheduler](../app/Console/Kernel.php), [NIBE configuration](../config/nibe.php), [application configuration](../config/app.php), and [dependency declarations](../composer.json).

## Runtime and initial setup

The repository declares PHP `^8.1`, Laravel `^10.10`, Guzzle `^7.2`, and Doctrine DBAL `^3.7`. Composer's lockfile specifies the resolved dependency versions. The database defaults to MySQL. Vite, Axios, and Laravel's frontend scaffolding are present, but the scheduled heating controller does not depend on a custom JavaScript interface.

A fresh deployment needs the following setup before it can perform the same role as an existing installation:

1. Install dependencies with `composer install`, using a compatible PHP runtime and extensions. Create a local `.env` from `.env.example` if one does not exist, configure the database/application/mail, and generate an application key with `php artisan key:generate` for a new installation.
2. Create a dedicated database and run `php artisan migrate` against that intended database. Existing installations should retain their data and application key. The seed file does not populate any integration records.
3. Configure the service credentials below. Existing operating environment variables are compatibility bootstrap inputs; run `php artisan settings:import` to preserve effective values in the database. Future operating edits belong on `/settings`. `.env.example` contains only standard Laravel entries; connection/credential variables must be added separately.
4. Provision the installation's `nibe_parameters` catalogue and `emon_feed_maps` mappings. Retain an existing installation's records or populate them from the actual device/Emoncms feed definitions. No operational seed, import file, or automatic catalogue/feed discovery is supplied.
5. Create `settings` rows `nibe_token_current` and `nibe_token_expiry`. The API constructor requires both. To use the implemented renewal path, the expiry must represent a time already in the past and valid myUplink client credentials must be configured. The repository does not provide an initial-token setup command.
6. Arrange the external weather sender, Emon input processing/feed setup, and Home Assistant webhook automation. Those external configurations are not included here.
7. Configure one scheduler runner and enable the required integration commands only once the connected systems and settings are ready.
8. Create a web-panel account with `php artisan app:create-user` if no suitable Laravel user already exists. The command asks for a private, confirmed password. Visit the normal application URL to sign in. See [the control-panel guide](control-panel.md).

At minimum, catalogue entries for priority `49994` and evaporator `44363` are needed for the stored-priority consumers and defrost history. Other recognised points enable logging/publication. The eleven current-response points required by the DM override are listed in the control guide. Exact feed names `Rad_temperature` and `UFH_temperature` are important for room control.

The web server should serve Laravel's `public/` directory. `/` is the authenticated overview; `/settings` provides operating-parameter editing; `/login` and POST `/logout` manage sessions. `/dashboard/feeds` provides authenticated, bounded local Emon reads. The existing `/api/weather_data` POST and Sanctum-authenticated `/api/user` GET remain separate from web sign-in.

## Scheduled commands

The definitions are in `app/Console/Kernel.php`. Timings below describe requested frequency; slow preceding commands or network requests can delay execution.

| Command | Schedule | Main effects |
| --- | --- | --- |
| `nibe:priorityHeartbeat` | Every 10 seconds | Reposts the latest stored priority to local and remote Emoncms. |
| `nibe:getData` | Every minute | Calls myUplink, stores/publishes readings; every fifth minute can write NIBE heating/cooling settings and hot-water schedule mode, and publishes forecasts. |
| `ha:adjustHiveThermostat` | Every minute | Sends a target temperature to the Home Assistant webhook. |
| `emon:sync` | Every 30 minutes | Compares selected local/remote feeds and stores differences; backlog upload is a placeholder. |
| `logs:cleanUp` | Daily at midnight | Deletes old activity/API logs by retention period. |
| `admin:emails` | Daily at 06:00 | Sends a summary of the previous 24 hours' error logs. |
| `octopus:getAgileRates` | Every 15 minutes | After the local 16:30 cutoff, retrieves rates, saves schedules and emails results until successful. |

Other registered commands are `schedule:test` (writes a scheduler-test message to Laravel's log) and `command:test` (developer scratch command). The latter currently tries to call protected `NibeController::getRoomTemperature()` from outside its class, so it catches/logs a visibility error; it is not a controller simulation or a dependable diagnostics command. The standard `inspire` console command also remains.

To inspect definitions without executing integration jobs:

```text
php artisan list
php artisan schedule:list
php artisan route:list
```

Laravel can be hosted under a persistent `php artisan schedule:work` process or invoked by the operating system each minute with `php artisan schedule:run`. On Windows, configure the chosen runner with the correct PHP executable and project working directory. The ten-second event uses Laravel's sub-minute repeat support; a `schedule:run` invocation can remain active until the end of its minute.

Do not configure both runner methods simultaneously for the same installation. The repository does not use `withoutOverlapping()`, `onOneServer()`, or `runInBackground()`. Within a scheduler invocation, due commands run sequentially; HTTP delays can postpone later jobs and heartbeat repeats. Running multiple scheduler processes can duplicate work.

Calling `php artisan nibe:getData` manually performs its real integrations, including the five-minute writes when its time condition is met. **`NIBE_DM_OVERRIDE=false` is not a global read-only mode**: it disables the DM steering path, but polling/publication, hot-water schedule changes, Hive commands, and emails remain separate active paths.

## Time zones

`config/app.php` sets the application timezone to **UTC**, with no environment-variable override in that file. The scheduler uses the application timezone unless configuration is changed elsewhere. The repository mixes this with explicitly London-based operations:

| Operation | Time basis in checked-in code |
| --- | --- |
| Daily cleanup and 06:00 administrator report | Application timezone, default UTC. The report is therefore 07:00 UK time during BST. |
| Five-minute control gate | Minute number of application time. |
| Peak-import window | Explicit Europe/London, 16:00–18:35 inclusive by default; both boundaries have editable minute offsets. |
| Rate-fetch cutoff/reset | Explicit Europe/London, 16:30. |
| Cheapest/most-expensive rate search ranges | Application timezone, default UTC. |
| Cosy schedule starts and hot-water checks | Explicit Europe/London. |
| Emailed rate labels | Explicit Europe/London. |
| Persisted control schedule timestamps | Integer Unix seconds, compared as instants. |
| Feed reads / historical comparisons | Unix milliseconds; NIBE readings and input posts use seconds. |

Consequently, rate-search clock ranges and displayed London labels differ by one hour during BST. Changing only the host operating-system timezone does not change Laravel's checked-in UTC setting. The documentation records this behaviour without changing it.

## NIBE configuration

The following table retains the legacy environment-variable mapping and checked-in defaults for import/bootstrap. Credentials continue to use `config/nibe.php`; operating keys are now read through `ControlSettings::get()` from `control.<group>.<key>` rows in `settings`. Imported database values take precedence over environment/config values. Temperatures are treated as °C; offset values are NIBE offset steps. Boolean operating values are returned as real booleans. See [live settings](control-panel.md) for the complete editable catalogue and validation.

| Environment variable | Config key (`nibe.` prefix) | Default | Purpose |
| --- | --- | --- | --- |
| `NIBE_CLIENT_ID` | `clientId` | None | myUplink client credentials. |
| `NIBE_CLIENT_SECRET` | `clientSecret` | None | myUplink client credentials. |
| `NIBE_SYSTEM_ID` | `systemId` | None | Loaded by adapter; not used in current device requests. |
| `NIBE_DEVICE_ID` | `deviceId` | None | Device ID in GET/PATCH point URLs. |
| `NIBE_DM_OVERRIDE` | `dmOverride` | false | Enable five-minute mode/DM steering. |
| `NIBE_DM_TARGET` | `dmTarget` | -180 | Intermittent/on target; also part of extraBoost target. |
| `NIBE_DM_TARGET_OFF` | `dmTargetOff` | 30 | Off target; subtract 60 during hot water. Also used for dynamic intermittent/cheap limit. |
| `NIBE_DM_TARGET_BOOST` | `dmTargetBoost` | -360 | Boost target; also part of extraBoost target. |
| `NIBE_DM_TARGET_COOLING` | `dmTargetCooling` | 75 | Cooling target. |
| `NIBE_MINUTES_TO_DM` | `minutesToDm` | 30 | Steering horizon in minutes; must be nonzero. |
| `NIBE_OFFSET_FACTOR` | `offsetFactor` | 2 | Estimated calculated-flow change per offset step; must be nonzero. |
| `NIBE_OFFSET_MINIMUM` | `offsetMinimum` | -10 | Normal lower offset bound. |
| `NIBE_OFFSET_MAXIMUM` | `offsetMaximum` | 10 | Normal upper offset bound. |
| `NIBE_OFFSET_MAXIMUM_INTERMITTENT` | `offsetMaxInt` | 0 | Special upper-limit baseline for intermittent mode. |
| `NIBE_CHEAP_MODE` | `cheapMode` | false | Apply restrictive offset/minimum-flow behaviour across modes; does not look up a price. |
| `NIBE_CHEAP_MODE_OFFSET_MAXIMUM` | `cheapModeOffsetMax` | -4 | Cheap-mode upper-limit baseline. |
| `NIBE_BOOSTS_ALLOWED` | `allowBoosts` | false | Enable explicit mode promotion during a boost window. Does not disable the room-band boost adjustment. |
| `NIBE_BOOSTS_MAX_COST` | `boostMaxCost` | 10 | Defined but unused in active decisions. |
| `NIBE_LOAD_COMPENSATION_ON` | `loadCompensationOn` | true | Enable room-band/UFH logic when Rad data allows it; also UFH forecast publication. |
| `NIBE_LOAD_COMP_TEMP_OFF` | `loadCompTempOff` | 21.5 | Base upper room threshold. |
| `NIBE_LOAD_COMP_TEMP_INTERMITTENT` | `loadCompTempIntermittent` | 21 | Base threshold above which intermittent is sufficient. |
| `NIBE_LOAD_COMP_TEMP_ON` | `loadCompTempOn` | 20 | Base threshold above which on is sufficient. |
| `NIBE_LOAD_COMP_TEMP_LEVEL_1` | `loadCompTempLevel1` | 19 | Base threshold above which boost is sufficient; at/below requests extraBoost. |
| `NIBE_MINIMUM_RAD_ZONE_TEMPERATURE` | `minRadZoneTemp` | 19 | Read into config cache in controller, but its separate minimum-room rule is disabled. |
| `NIBE_COOLING_START_TEMP` | `coolingStartTemp` | 24 | Average outdoor temperature threshold for final cooling selection. |
| `NIBE_DEFROST_DEWPOINT_MINIMUM` | `defrostDewpointMin` | -3 | Strict lower dew-point threshold for defrost cap. |
| `NIBE_DEFROST_DEWPOINT_MAXIMUM` | `defrostDewpointMax` | 5 | Strict upper dew-point threshold for defrost cap. |
| `NIBE_DEFROST_EVAPORATOR_MAXIMUM` | `defrostEvaporatorMax` | -15 | Evaporator-minimum threshold; severe trigger is another 5°C lower. |
| `NIBE_DM_TARGET_OFF_TEMP` | `dmTargetOffTemp` | 12 | Current-outdoor cutoff in forecast-disabled boost selection. Does not directly select off in current normal flow. |
| `NIBE_DM_TARGET_BOOST_TEMP` | `dmTargetBoostTemp` | 3 | Low-forecast +2°C band adjustment and peak minimum-mode rule. |
| `NIBE_RUN_LEVEL_1_TEMP` | `runLevel1Temp` | 12 | Warm-high forecast reduction of initial mode; the -1°C radiator-band reduction is disabled during the 22 October trial. No warm-forecast veto on Cosy eligibility. Also used in forecast-disabled boost selection. |
| `NIBE_RUN_LEVEL_2_TEMP` | `runLevel2Temp` | 9 | Boost-selection comparisons; forecast-enabled branches currently still choose Cosy. |
| `NIBE_TEMP_FREQ_MIN` | `tempFreqMin` | 6 | Boost-selection comparisons; forecast-enabled branches currently still choose Cosy. |

The myUplink token and function URLs are fixed in `config/nibe.php` to `https://api.myuplink.com/oauth/token` and `https://api.myuplink.com/v2`.

The UFH thresholds are now editable as `nibe.ufhTempOn` (default 19.5°C) and `nibe.ufhTempIntermittent` (default 20°C), and room-sensor history as `nibe.roomTemperatureLookbackMinutes` (default 180). Log retention is editable under `logs.infoRetentionDays`, `warningRetentionDays`, `errorRetentionDays` and `apiRetentionDays` (defaults 30/60/90/90).

Boost schedule offsets are editable as `octopus.offsetToOn` and `octopus.offsetToOff` (defaults -15/-100 minutes) under **Weather and boosts**. They apply when the schedule is next generated. Peak control offsets are separately editable as `nibe.peakImportStartOffsetMinutes` and `nibe.peakImportEndOffsetMinutes` (defaults 0/-25 minutes), added to 16:00/19:00 London time and read at each evaluation. These affect radiator peak reduction, UFH preheat suppression and the cold-forecast peak minimum; they do not rewrite the boost schedule. Negative offsets move boundaries earlier, positive later, and the Settings form requires a positive peak-window duration. The remaining hard-coded constants include the nominal peak clock times, extraBoost's +90, room-band adjustments, the 60-minute evaporator window, minimum-flow baseline/cap 10/60°C, two-hour UFH extrapolation, and hot-water schedule/time/temperature rules.

## Other integration configuration

| File | Environment variable or fixed value | Default / role |
| --- | --- | --- |
| `config/emon.php` | `EMON_HP_LOCAL_ENDPOINT` | `http://emonhp.local`. |
| | `EMON_HP_LOCAL_READ_KEY` | Local feed reads; no default. |
| | `EMON_HP_LOCAL_READWRITE_KEY` | Local input posts; no default. |
| | `EMON_CMS_READ_KEY` | Remote feed reads; no default. |
| | `EMON_CMS_READWRITE_KEY` | Remote input posts; no default. |
| | `EMON_SYNC_PERIOD_HOURS` | 3-hour rolling comparison window. |
| | Fixed remote endpoint | `https://emoncms.org`. |
| `config/octopus.php` | `AGILE_RATES_URL` | Relative rates path/query appended to the fixed API base; no default. Supply the actual tariff/region path with sufficient half-hour results. |
| | Fixed API base | `https://api.octopus.energy/v1/`. |
| `config/weather.php` | `WEATHER_USE_FORECAST` | true; controls forecast use in heating/boost selection and Emon weather publication. Dew-point calls still exist outside this switch. |
| | `WEATHER_LOOKAHEAD_HOURS` | 6; offset to the start of the weighted future average. |
| `config/hive.php` | `HIVE_TARGET_ON_TEMP` | 30°C target during cooling, or heating with radiator demand (including unknown/stale-demand fallback). |
| | `HIVE_TARGET_OFF_TEMP` | 16°C target during heating with fresh false radiator demand, or for other priorities. |
| | `HIVE_RADIATOR_DEMAND_MAX_AGE_MINUTES` | 10; maximum age of a radiator-demand decision used to gate Hive during heating. Exactly this age is accepted; older/future/unknown/invalid decisions fall back to priority-based control. |
| `config/homeAssistant.php` | Fixed webhook base/path | `http://homeassistant.local:8123/api/webhook/` + `hive_thermostat_adjust`; edit config to change. |
| `config/app.php` | `APP_ADMIN_EMAIL` | `test@example.com`; recipient of all three notification types. |
| `config/mail.php` | Standard `MAIL_*` variables | Mail transport, credentials, sender; SMTP is the default transport. |
| `config/database.php` | Standard `DB_*` variables | Database connection. |

The project's `.env` is ignored by Git. Keep credentials and connection details there or in deployment environment configuration; their changes still follow the normal cache rebuild/restart procedure. Operating parameters now come from the database without a process-lifetime cache. NIBE's parameter properties reload for each calculation, and room-data caches reset per polling invocation. Once operating values are imported, changing their old `.env` entries does not override the saved database values.

## Persistent settings

Values are stored as text, not typed model casts. In particular, the boolean flags below use literal strings `true` and `false`.

This paragraph and the table below describe runtime state. The separate `control.*` rows use JSON scalars and are typed by `ControlSettings`; they are the only values editable through the Settings page. Tokens, forecast payloads and other runtime state are excluded from that page.

| `settings.key` | Written/read by | Meaning |
| --- | --- | --- |
| `nibe_token_current` | NIBE API | Current bearer token; must exist initially. |
| `nibe_token_expiry` | NIBE API | Expiry as Unix seconds; must exist initially. |
| `weather_data` | Weather POST / weather readers | Full forecast JSON; replaced on each submission. |
| `agile_schedule` | Octopus / boost selection | JSON with cheapest and Cosy windows as integer Unix seconds. |
| `getAgileRatesSuccess` | Octopus | Fetch/email success latch, reset before the daily cutoff. |
| `RoomTemperature[Rad_temperature]` | Room lookup | Last usable Rad temperature, used as a fallback value. |
| `allowLoadCompensation` | Room lookup / mode selection | Whether a usable Rad reading was obtained for room compensation. |
| `htgMode` | DM override | Most recently selected helper mode, saved before applying settings. |
| `radiatorHeatDemand` | Mode calculation / Hive control | JSON `required` (boolean or null) and `calculatedAt` (Unix seconds); separate radiator demand before mode promotions. Refreshed on every successful mode calculation, even if the decision is unchanged. |
| `hotWaterSet` | Hot-water check | Shared, undated flag tracking a normal-mode schedule change pending reset. |
| `nibeEmptyDataCount` | Polling / offline monitor | Consecutive empty non-priority outgoing collections. |

Token rows require provisioning. Most other rows are created on their write path, but their readers may run first: a migrated empty database alone is not ready for normal service. `RoomTemperature[<feedName>]` is a general pattern in the lookup helper; the active setting-updating caller is the Rad lookup. The UFH floor uses the non-updating lookup.

## Diagnosing behaviour

For an unexpected heating level, follow the calculation rather than treating the final mode as the entire explanation:

1. Check `activity_logs` for the relevant `getNibeData`, `calculateHeatingMode`, `dmOverride`, and API errors around that time.
2. Check the selected `htgMode`, Rad reading and `allowLoadCompensation`, actual UFH reading, high/low forecast averages, stored boost-window dates, and the London peak window. Then apply the rules in the documented order.
3. Check whether the defrost cap applied and whether average outdoor temperature selected cooling last.
4. Compare DM target/current DM, calculated/external flow temperature, heating offset, and minimum flow temperature. An intermittent limit or raised minimum flow can explain why a mode does not produce the expected temperature change.
5. Check the outgoing PATCH in `api_logs` and subsequent device readings. An API log proves a request was prepared, not that the device applied it.

Common symptoms:

| Symptom | Relevant checks |
| --- | --- |
| No DM adjustments | `NIBE_DM_OVERRIDE`, all eleven required response points, timing gate, API/database errors, and whether calculated changes are zero. |
| No priority/Hive updates | Catalogue contains `49994`; a stored reading exists; inspect age and errors. |
| Hive stays off while NIBE is heating | Check the radiator-demand decision and adjusted Rad threshold. A fresh false decision keeps Hive at its off target while the UFH or other mode rules can keep the heat pump running. |
| Hive turns on despite a warm Rad zone | Check fallback warnings, demand age and field types, `NIBE_DM_OVERRIDE`, `NIBE_LOAD_COMPENSATION_ON`, and whether adjusted thresholds currently request radiator heating. |
| Boost missing | Forecast retrieval, schedule dates/types, strict start/end boundaries, and explicit promotion flag. A warm high-six forecast no longer vetoes Cosy windows when forecasts are enabled. |
| Boost occurs with boosts disallowed | Room-band +1°C adjustment still uses `boostActive`; room demand alone can also select boost modes. |
| No load compensation | Local feed mappings, usable Rad data within the 180-minute query, saved fallback, and exact `true` string. |
| Excessively low demand during boost | Defrost cap, dynamic offset limits, cheap mode, and current minimum-flow setting. |
| Weather looks current but decisions are odd | Forecast row order, source dates/horizon, humidity/temperature fields, and positive weighting range. No age validation is built in. |
| Remote historical gaps persist | Backlog posting is unimplemented; `emon:sync` only records differences. |
| No new schedule | Fetch latch/cutoff, rates URL/page coverage, enough window samples, email success, and clock zones. |
| Hot-water boost missed | Sunday exclusion, <33°C threshold, 09:30–14:00 window, undated flag, and API failures. |
| Clean command exit but task failed | Read `activity_logs`; many failures are caught without a nonzero command exit. |

## Verification and maintenance

After the control-panel feature, `php vendor/bin/phpunit` passed **68 tests, 279 assertions**, using PHP 8.2.11. The suite covers import preservation, typed live settings, validation, authentication, CSRF, stale forms, dashboard data/error states, same-process parameter refresh, and the radiator/UFH/Hive scenarios. Test databases are isolated in-memory SQLite connections and outbound calls are mocked. Browser checks used a separate demonstration database. The installation's operating values were imported without sending live heating commands; these checks do not validate physical circulation or equipment response.

To keep these guides accurate, update the decision-order tables and examples when changing `calculateHeatingMode()`, target/offset descriptions when changing `dmOverride()` or `calculateTargetDm()`, configuration tables when changing `config/`, and schedule/time-zone tables when changing the console kernel or date handling.
