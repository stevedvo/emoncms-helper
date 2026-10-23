# Implementation notes

[Project overview](../README.md) · [Heat pump control](heat-pump-control.md) · [Integrations](integrations-and-data.md) · [Configuration](configuration-and-operation.md)

These observations explain the checked-in implementation, including radiator demand (18 October 2026) and the control panel/live settings (19 October 2026). They are not a claim that every edge case has occurred in service. The latter work imports effective operating parameters into the configured database but does not send commands to the connected heating equipment.

## Active behaviour that is easy to misread

| Observation | Source | Consequence |
| --- | --- | --- |
| `intermittent` and `on` share a DM target. | `NibeController::calculateTargetDm`, `dmOverride` | Their difference is the allowed offset/minimum-flow response, not a different target. |
| Mode calculation starts again at intermittent. | `calculateHeatingMode` | The saved mode is an output record, not state used to initialise the next decision. |
| All forecast-enabled temperature branches currently choose Cosy. | `isBoostActive` | The apparent outside-temperature schedule ladder does not currently change the selected schedule in this branch. |
| A warm high-six forecast retains Cosy eligibility. | `isBoostActive`, `calculateHeatingMode` | UFH preheating remains available outside peak import and the radiator +1°C boost adjustment applies in active windows. Fallback promotion requires inactive room compensation and `allowBoosts`. |
| The warm-forecast -1°C radiator adjustment is disabled for the 22 October 2026 trial. | `calculateHeatingMode` | The initial warm-forecast mode nudge remains active. The zero adjustment also lets the cold-forecast additions pass their guard even with a warm high forecast. Raising `runLevel1Temp` alone does not restore the disabled room-band reduction. |
| Scheduled boost promotion is a fallback when room compensation is inactive. | `calculateHeatingMode` | An enabled setting and usable Rad data make room compensation active. Adjusted room targets then select the mode without a second scheduled promotion. When compensation is disabled or data unavailable, the legacy promotion still requires `allowBoosts` and an active window. |
| Boost permission does not gate room-temperature adjustments. | `calculateHeatingMode` | A boost window can still shift room bands +1°C with `allowBoosts = false`. Room demand can independently select boost/extraBoost. |
| Peak downward nudges are commented out. | `calculateHeatingMode` | Peak reduction mainly comes from the -2°C room-band shift; the remaining cold-forecast peak rule can impose a minimum intermittent mode. |
| Peak controls default to 16:00–18:35 London time. | `isPeakImport`, live peak-offset settings | The former 14:40 early reduction is removed. The same window suppresses UFH preheating and enables the cold-forecast peak minimum. Both offsets are editable independently of boost schedule generation. |
| Default cold comparisons both use 3°C. | `calculateHeatingMode`, `config/nibe.php` | The +2°C room adjustment wins below 3°C; the +1°C cold adjustment is unreachable with defaults. |
| Default UFH comparisons are <=19.5°C and <=20°C. | `calculateHeatingMode` | The two thresholds are now database-backed settings, and logs report their current values. |
| Forecast-average lookahead is a starting offset. | `WeatherController::getForecastAverageTemperature` | The value summarises data from six hours ahead onward, not the coming six hours. |
| UFH forecast temperature is not used to select Hive's target. | `HomeAssistantController::adjustHiveThermostat` | Recorded priority and a fresh radiator-demand decision select the target; the unused prediction lookup/disabled experiments have been removed from this method. |
| Radiator demand is captured before later mode rules. | `calculateHeatingMode`, `RadiatorHeatDemand` | Preserving a running cycle or a UFH minimum can keep NIBE heating while Hive keeps a warm radiator zone off. |
| Disabling DM override does not disable other NIBE writes. | `getNibeData`, `checkHotWaterRequirement` | The five-minute hot-water schedule routine remains active. |

## Unfinished or unused pieces

- `EmonController::postEmonFeeds()` contains only a proposed algorithm in comments. There is no implementation of backlog upload, maximum retry attempts, or status transitions for that path.
- `NibeToEmonFeedMap` and its table exist, but the active NIBE posting path uses parameter titles rather than that mapping.
- `nibe.boostMaxCost` / `NIBE_BOOSTS_MAX_COST` is defined but never read by the active application logic. Neither dynamic window costs nor Cosy's stored 13.64 cost enforce a maximum price.
- `minRadZoneTemp` is loaded, but its separate minimum-room rule is behind `if (false)`.
- The initial temperature-based mode selection is commented out. Additional cold-outdoor mode raises, heating-curve test mode, and forecast-based pre-emptive cooling are disabled.
- The early return intended to avoid raising an intermittent offset already at its maximum is commented out; the subsequent clamp is active.
- The priority-heartbeat off-state early return is commented out, so off is resent too.
- `ApiLog::markAsProcessed()` and the API-log hash/ignored/processed fields are not used by an active processing pipeline found in this review.
- `command:test` currently invokes a protected method from another class and logs the resulting error. It is a scratch command, not a dry-run interface.
- Schedule 1 hot-water parameter mappings and off/luxury comfort constants are defined but unused by the active hot-water check.
- The authenticated control panel now provides settings editing and an overview, with `settings:import` for operating parameters and `app:create-user` for login accounts. There is still no integration-catalogue/feed-map seeder or queued integration job pipeline.

## Data freshness and recovery limitations

The implementation relies on expected incoming data without checking age everywhere:

- DM steering uses the current API response but does not reject old point timestamps.
- Heartbeat and Hive select priority by descending database ID and can continue acting on an old reading. Heartbeat gives that value a current Emon timestamp.
- The defrost lookback filters database `created_at`, not the source reading's timestamp. A newly inserted old point counts as recent; an unchanged point first inserted more than an hour ago does not.
- Room lookups allow a 180-minute history, and weather data can remain stored indefinitely. Dew point uses the first stored weather row even if it is no longer current.
- Local NIBE upload failures are retried only when the API returns that reading again; there is no independent full backlog drain. A title-keyed outgoing collection can also replace an earlier candidate for that title. Remote upload status is not stored.
- The offline alert counts empty outgoing collections rather than failed network calls. Its text and counter should not be interpreted as proof that NIBE has been offline for exactly 15 minutes.
- The hot-water flag is undated. Missing an evening reset can leave the flag set across days, and a later reset changes the current day's schedule entry rather than necessarily restoring the originally changed entry.
- The rates success latch resets during a pre-16:30 invocation. If the runner misses that entire part of the day, a stale true latch can prevent a later fetch. Stored schedules also have no automatic expiry/renewal fallback.

## Boundary and provisioning details

The missing-Rad fallback now uses a null-safe lookup. If no saved room reading exists, it returns null and sets `allowLoadCompensation` to false. A successful mode calculation records unknown radiator demand, preventing a prior false demand from being treated as current. Even when compensation is configured off, `calculateHeatingMode()` still calls the Rad lookup before checking that switch.

`getLatestEmonData()` uses a loose `$latestValue == null` check, which treats zero as missing for its error log, although the numeric value may still be returned. Other paths have different null handling. Historical comparisons search for the literal text `null`; native nulls and the text `null` do not necessarily behave alike. The cooling dew-point check also uses a loose null comparison, skipping zero dew point.

The weighted forecast uses 42, 41, ... without limiting the number of rows. Zero/negative weights are possible with long or frequent forecasts. Cheapest-window selection assumes contiguous half-hours and later indexes specific elements without first validating that enough periods were returned. The API adapter does not follow Octopus pagination. These assumptions make the external payload size/order significant.

Stored schedule start/end values are integers. `CarbonImmutable::parse()` accepts the generated integer timestamps in the installed dependency; a documentation check confirmed this. Numeric strings are not interchangeable with those integers in that parser, so a manually edited JSON schedule should retain numeric values rather than quoted timestamps.

The migrations create no unique index on `settings.key` or NIBE `(parameterId, timestamp)`. Application-level find/create logic is not a database uniqueness guarantee under overlapping runs. No overlap prevention is configured in the scheduler. Models for the catalogue/mapping tables retain default Eloquent ID/timestamp assumptions even where the corresponding schema omits those columns; their current read usage does not supply a ready-made write/import interface.

The degree-minute formula divides by `minutesToDm` and `offsetFactor`. The Settings page now validates positive values, threshold ordering and compatible offset limits before saving. Direct database edits or imported legacy values bypass form validation and should be checked separately. The first minimum-flow preparation write is not clamped to the later escalation cap. Cooling's dew-point +1 override is not re-clamped, and its projected temperature uses hard-coded 2 rather than `offsetFactor`.

## Integration and operational limits

The shared HTTP wrapper disables certificate verification, sets no explicit timeout, and logs complete GET endpoint strings up to 255 characters, potentially including Emon read keys. API logs record request preparation, not responses or confirmed outcomes. Form credentials and bearer headers are not logged by this wrapper.

The weather POST endpoint accepts unvalidated, unauthenticated input under the ordinary API throttle. It has no request signature, source-age check, or explicit error acknowledgement. External network restrictions, if any, are deployment configuration rather than repository logic.

Caught integration errors commonly leave a successful command exit status and an end marker. For priority sync, stored local success reflects the last binary-input post rather than all three posts. For DM steering, `htgMode` is saved before PATCH confirmation. Hive sends every minute without checking whether the target changed or reading it back.

Daily schedules use UTC by default, while peak/hot-water/Cosy/fetch-cutoff rules explicitly use London time. Tariff window searches use application time and the email displays London labels. The summer-time difference is documented in the operations guide; this review did not normalise it.

Automated tests now cover radiator-demand capture, overall mode selection for the new zone-control scenarios, persistence/freshness, and Hive targets using controlled inputs and a mocked Home Assistant API, alongside the existing reporting tests. This is regression evidence for those paths, not validation of the physical control strategy. Other integration/data limitations above remain.
