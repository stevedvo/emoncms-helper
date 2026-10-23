# Integrations and data

[Project overview](../README.md) · [Heat pump control](heat-pump-control.md) · [Configuration](configuration-and-operation.md) · [Implementation notes](implementation-notes.md)

Source entry points: [NIBE](../app/Http/Controllers/NibeController.php), [Emoncms](../app/Http/Controllers/EmonController.php), [weather](../app/Http/Controllers/WeatherController.php), [Octopus](../app/Http/Controllers/OctopusController.php), [Home Assistant](../app/Http/Controllers/HomeAssistantController.php), and [HTTP wrapper](../app/APIs/API.php).

## NIBE / myUplink

`NibeAPI` reads configuration and existing `settings` rows for `nibe_token_current` and `nibe_token_expiry`. Both rows must exist before its constructor succeeds. If the stored expiry is in the past, it requests a token using `client_credentials`, the configured client ID/secret, and scope `READSYSTEM WRITESYSTEM`, then updates the two rows. Refresh-token handling is commented out; `systemId` is loaded but device requests use `deviceId`.

The adapter GETs and PATCHes `/v2/devices/{deviceId}/points`. For a PATCH, every requested parameter must appear in the response with value `modified`, or the adapter throws an exception. It does not subsequently read back and verify the physical device settings.

`getParameterData()` checks for a successful HTTP status and a nonempty body, decodes JSON with explicit error handling, and requires a JSON list whose entries contain `parameterId`, `timestamp` and `value`. Valid readings, including zero values, are returned unchanged. A valid empty list `[]` retains its existing empty-data behaviour; an empty response body, JSON `null`, an error object, malformed JSON or a structurally incomplete point is rejected rather than returned as readings. All entries are checked before the list reaches the polling controller.

Failures identify the response problem, HTTP status and body length in bytes without copying the response body or access token into the new diagnostic. `getNibeData()` records that error and stops the current poll before storing readings, syncing Emon or running the five-minute control work. Existing readings and control settings remain unchanged. There is no immediate retry or fabricated empty response: the next scheduled one-minute poll makes a fresh request. Existing HTTP and connection exceptions continue to propagate to the polling controller. The shared wrapper resolves its Guzzle client through Laravel's container so tests can replace the transport without contacting the equipment.

`getNibeData()`:

1. Deduplicates the response using concatenated parameter ID, timestamp, and value.
2. Loads the `nibe_parameters` catalogue, keyed by parameter ID.
3. For recognised parameters, finds or creates a reading by parameter ID and source timestamp, storing the API `value` as `rawValue`. Source timestamps become UTC Unix seconds.
4. Adds new readings or readings whose local sync status is not `success` to the outgoing collection, keyed by catalogue title. Priority is excluded from this path.
5. Collects the eleven control inputs directly from the response, even if they are not catalogued.
6. Sends outgoing readings to local Emoncms. Only the title `calculated flow temp.` is also selected for direct remote publication.
7. Runs the five-minute work described in the control guide.

There is no unit conversion using the catalogue's `unit` or `designation`. Parameter titles become Emon input names, so exact titles matter. Multiple readings sharing an outgoing title overwrite earlier entries in that collection. A changed value with an unchanged source timestamp does not replace an existing database row because `firstOrCreate()` only uses parameter ID and timestamp for identity.

### Priority heartbeat

Every ten seconds, `priorityHeartbeat()` selects the last inserted reading for parameter `49994`, temporarily substitutes the current timestamp, and posts three binary inputs under node `nibe` to **both** Emoncms installations:

| Raw priority | `hot water` | `heating` | `cooling` |
| --- | ---: | ---: | ---: |
| 20 | 1 | 0 | 0 |
| 30 | 0 | 1 | 0 |
| 60 | 0 | 0 | 1 |
| Anything else | 0 | 0 | 0 |

The original reading timestamp is restored with `discardChanges()` before saving sync status. The heartbeat does not poll NIBE and does not check how old that priority is. Even the off state continues to be sent; its skip-return is commented out.

Only local sync increments `syncAttempts` and sets `syncStatus` to `success` or `failed`. Remote status is not persisted. For the three priority posts, the last boolean result determines stored success if no exception interrupts the loop; this is not an aggregate of all three results.

### Empty-data alert

An empty outgoing **non-priority** collection increments `nibeEmptyDataCount`. At exactly 15 consecutive empty checks, the code sends `Nibe Offline?` to the administrator. A nonempty outgoing collection resets the counter to zero before sync is attempted.

The counter measures a lack of new/retryable recognised readings, not network reachability or successful posting. An API exception before that check does not increment it. Failed local uploads can keep the collection nonempty and reset it. The email describes minutes because the normal polling cadence is once per minute. Sending happens inside the counter transaction; failure can roll back that increment.

## Emoncms

The two named environments are `local` (default `http://emonhp.local`) and `remote` (`https://emoncms.org`). `EmonAPI` supports:

| Operation | Endpoint and format |
| --- | --- |
| List feeds | GET `/feed/list.json`, read key in query string. |
| Read feed data | GET `/feed/data.json`, feed ID, start/end in milliseconds, interval fixed at 10 seconds. |
| Post inputs | POST `/input/post`, form fields `time` (Unix seconds), `node`, `fulljson`, `apikey`. |

`getFeedData()` requires a successful HTTP status, a nonempty body and valid JSON containing a list of two-element timestamp/value pairs. Each timestamp must be numeric and finite; values may be scalar or null. The whole list is validated before returning it. A valid empty list `[]`, zero readings and null gaps within a feed are preserved. An empty body, top-level JSON `null`, an error object, malformed JSON or an invalid sample raises a descriptive exception instead of reaching callers as feed data.

The new diagnostics identify the local/remote environment, feed ID, HTTP status and body length without copying the response body or read key. Existing HTTP and connection exceptions continue to propagate. There is no immediate retry; a later invocation makes a fresh request.

Input success requires a decoded response array containing `success` equal to the string `true` under the code's loose comparison. The helper posts **inputs** for NIBE and forecast values; the Emoncms processing that turns those inputs into feeds is configured externally.

### Indoor temperature and trend

Named readings resolve through `emon_feed_maps.localName` or `remoteName`. `getLatestEmonData()` searches a requested number of minutes ending at the current minute boundary, chooses the last non-null reading, and rounds to one decimal place. The main control loop uses editable `nibe.roomTemperatureLookbackMinutes` (default 180) for Rad/UFH data, so with defaults a returned reading can be nearly three hours old. There is no additional age check on the selected pair. The [dashboard](control-panel.md) separately reads the local feed list with a bounded read-only request; it does not trigger a control calculation.

Zero is a valid latest reading and does not produce a missing-data error. A failed read, empty list or list containing only null gaps is logged and returns null, allowing the existing room-temperature fallback to apply.

The dashboard resolves its Rad/UFH cards using the same local feed-ID mappings rather than assuming the Emon display name matches the mapping alias. Its response marks each identified feed with `room: rad` or `room: ufh`, which also selects the room-specific sample-age limit. Original feed names remain unchanged in the feed table.

Dashboard feed responses exclude NIBE exports using explicit local NIBE feed IDs, NIBE tags/name prefixes, and known NIBE names for otherwise unmapped/untagged feeds. Each retained entry has a `device` label derived from its parent tag or mapping, and entries are sorted by device/name. Room mappings also identify sibling feeds sharing a tag unique to one room, so humidity, battery and temperature forecast can be grouped with the right room sensor. These display rules do not alter any feeds, mappings, sync operations or stored history.

`getForecastRoomTemperatureData()` reads the last 60 minutes of local `UFH_temperature`, drops nulls, fits a least-squares straight line against time, and projects **two hours beyond the final sample**. A single point produces zero slope. It returns:

| Field | Meaning |
| --- | --- |
| `tempEarliest` | First valid sample, rounded to one decimal. |
| `tempCurrent` | Last valid sample, rounded to one decimal. |
| `tempCurrentTimestamp` | Last sample's timestamp in seconds. |
| `tempForecast` | Two-hour linear projection, rounded to one decimal. |
| `tempIncreasing` | Whether rounded last temperature exceeds rounded first temperature. |

Missing required fields produce null. `syncRoomTemperatureForecastWithEmon()` posts `temperature forecast` to local node `emonth2_23`, using the **last sample's timestamp**, not the future forecast time. The prediction is currently for logging/graphing: the main mode bands use measured Rad temperature and the UFH floor uses measured UFH temperature. Hive control does not fetch or use this prediction.

`getLatestRoomTemperatureData()` is another five-minute UFH lookup helper with no active application caller found.

### Comparing local and remote feeds

Every 30 minutes, `emon:sync` calls `getEmonFeeds()` and then `postEmonFeeds()`.

The comparison includes only local IDs **1–8 and 14–19**, with a mapping in `emon_feed_maps`. It compares a rolling period ending five minutes ago and starting `emonSyncPeriodHours` earlier (default three hours). Data timestamps are milliseconds. Equal timestamp/value pairs are skipped; local values missing or different remotely can be inserted into `feed_items` as `pending`, unless a row already exists for that local feed and timestamp.

If either environment returns unusable feed data, the comparison logs the failure and skips that feed without adding backlog entries for it. Other feeds are still processed. In particular, a failed remote read is not treated as an empty remote history requiring every local sample to be queued.

Handling depends on the mapping's exact `localName`:

| Names | Behaviour |
| --- | --- |
| `heatmeter_Energy`, `heatmeter_FlowT`, `heatmeter_ReturnT`, `total_Power`, `total_Energy`, `heatmeter_DeltaT` | Record differences unless the value contains the text `null`. |
| `heatmeter_Power`, `heatmeter_FlowRate` | Replace values containing text `null` with zero and record differences. |
| Rad/UFH `temperature`, `humidity`, `battery` names | Record non-`null` differences only in the first ten seconds of each epoch-aligned ten-minute period. |
| Other names | No backlog row is saved. |

The null checks use `strpos()` against the value, rather than a consistent native-null test. The sparse-room-feed calculation is `floor(timestampMs / 100000) % 6 == 0`, followed by a ten-second window check.

**Backlog upload is unfinished.** `postEmonFeeds()` contains only comments. This command records differences but does not repair remote feeds. This is separate from the working direct NIBE/forecast/priority input-posting paths.

## Weather

`POST /api/weather_data` calls `receiveWeatherData()` and stores the entire parsed request as JSON in `settings.weather_data`. A new submission replaces the previous one. There is no weather-provider client or producer configuration in this repository, so an external sender must supply the forecast.

The expected shape, inferred from the consuming code, is a top-level array like:

```json
[
  {"datetime": "2026-10-17T12:00:00+01:00", "temperature": 11.2, "humidity": 82},
  {"datetime": "2026-10-17T13:00:00+01:00", "temperature": 12.0, "humidity": 78}
]
```

Temperatures are treated as °C and humidity as percent. Use an explicit timestamp offset and chronological order. `getLatestWeatherData()` keys entries by `datetime` but does not sort them; repeated datetimes collapse. The endpoint does not validate this schema, enforce chronological order, check age, or require authentication. It inherits the API rate limit of 60 requests per minute per user/IP. There is no custom acknowledgement payload; ingestion errors are caught and logged.

The derived values are:

| Value | Calculation and interpretation |
| --- | --- |
| “Current” dew point | Take the **first stored row**, then `temperature - (100 - humidity) / 5`. It is an approximation using that forecast row, not a local dew-point measurement. |
| Forecast average | Start at the current hour plus `lookAheadHours` (default six), rounded to the hour. Include every row at/after that point, with weights 42, 41, 40, ... in input order. Return the weighted average rounded to two decimals. It is not the average over the next six hours. |
| Next-day high | From rows between now and now +24 hours inclusive, take the highest six temperatures and average them. Fewer available rows use the smaller sample. It is a rolling window, not the next calendar day. |
| Next-day low | Same rolling window, taking the lowest six. |

The forecast-average loop has no end limit and no clamp on weights. More than 42 selected rows introduces zero and then negative weights; hourly input with a suitable horizon is an implicit assumption.

Every five minutes when forecasts are enabled, `syncForecastWithEmon()` posts `forecast avg. temp.`, `forecast high temp.`, and `forecast low temp.` to local node `weather` with the current timestamp. Failure/null at an earlier value stops publication of later values in that call.

## Octopus and schedules

`OctopusAPI` GETs the configured `AGILE_RATES_URL` appended to `https://api.octopus.energy/v1/`. The expected response has a `results` array of half-hour periods with `valid_from`, `valid_to`, and `value_inc_vat`. The code reads only that response page; it does not follow pagination.

The scheduled command runs every 15 minutes but only fetches from **16:30 Europe/London onward** while `getAgileRatesSuccess` is not the string `true`. Before 16:30, a true flag is reset to false. A successful run saves the schedule, sends the rates email, then marks true. Failure leaves/sets false so the next scheduled invocation retries. Email failure therefore retries rate retrieval and schedule generation too.

Rate analysis uses default application time (checked-in UTC) for its ranges:

- Overnight: today 19:00 through tomorrow 07:00.
- Daytime: tomorrow 10:00 through 16:00.
- Expensive morning: tomorrow 06:00 through 12:00.

Only period starts from now inclusive to now +24 hours exclusive are considered. Within each range, periods are sorted by start and sliding windows select the lowest average VAT-inclusive cost for 1, 2, 3, and 6 hours. Morning analysis selects the highest-cost 2, 3, 4, and 5 hours. Each half-hour has equal weight; equal-cost ties keep the first window. Consecutive array entries are assumed to be contiguous half-hours without an explicit gap/duration check.

`saveCheapestPeriods()` stores JSON in `settings.agile_schedule` with keys `cheapest_3_hours`, `cheapest_6_hours`, and `cosy`. The cheapest schedules each have overnight/daytime entries. Every entry contains `average_cost`, `start`, and `end`, with start/end as integer Unix seconds.

Control timing offsets are editable in **Settings → Weather and boosts** as **Boost window: start offset** (`octopus.offsetToOn`, default -15 minutes) and **Boost window: end offset** (`octopus.offsetToOff`, default -100 minutes). Both apply to the cheapest-three-hour, cheapest-six-hour and Cosy schedules. Negative values move a boundary earlier; positive values move it later. The defaults still start 15 minutes early and finish 100 minutes early: a selected three-hour tariff window gives 95 minutes of boost eligibility, and six hours gives 275 minutes. The cost remains the average of the full selected tariff window.

Both offsets accept whole minutes from -180 to +180. The Settings form rejects combinations that leave the shortest nominal window (the two-hour evening Cosy window) with zero or negative duration. `saveCheapestPeriods()` reads the database values on every generation. Saving an offset does not rewrite the existing `agile_schedule`; changes take effect at the next successful tariff refresh/schedule generation, normally the daily evening refresh, without a scheduler restart.

Cosy windows are generated separately from fixed **Europe/London** times, using the next occurrence of each start:

| Nominal window | Stored control window with default offsets |
| --- | --- |
| 04:00–07:00 | 03:45–05:20 |
| 13:00–16:00 | 12:45–14:20 |
| 22:00–00:00 | 21:45–22:20 |

Each Cosy entry has hard-coded `average_cost = 13.64`; the value is not used to authorise a boost. On a typical successful evening refresh, the morning and afternoon windows are tomorrow and the evening window is tonight. These dates are persisted and do not roll forward without another successful generation.

Although forecast-enabled heating currently uses the fixed Cosy entries, their generation still depends on a successful Octopus response and enough rate periods for the cheapest-window indexing that runs first. The email displays calculated cheapest/most-expensive windows; it does not display the adjusted Cosy control schedule.

## Home Assistant / Hive

Each minute, `adjustHiveThermostat()` reads the latest inserted NIBE priority and requests:

| Priority | Radiator heat demand | Hive target default |
| --- | --- | ---: |
| Heating `30` | Fresh true | 30°C (`hive.targetOnTemp`). |
| Heating `30` | Fresh false | 16°C (`hive.targetOffTemp`), allowing UFH-only heating. |
| Heating `30` | Missing, invalid, unknown, stale, or feature disabled | 30°C, retaining priority-based behaviour and logging a warning. |
| Cooling `60` | Any | 30°C, retaining existing cooling behaviour. |
| Anything else, including hot water `20` | Any | 16°C. |

`RadiatorHeatDemand` stores JSON in `settings.radiatorHeatDemand`, with `required` (boolean or null) and `calculatedAt` (Unix seconds). The mode calculation records this every five minutes, independently of whether an offset PATCH is necessary. A decision older than `hive.radiatorDemandMaxAgeMinutes` (default 10), dated in the future, or with invalid fields is ignored. An age of exactly ten minutes is accepted. Disabled DM override or load compensation also bypasses gating. Null means radiator-temperature demand could not be evaluated; it does not mean the radiator zone is warm.

This demand uses hysteresis around the adjusted radiator room threshold, before subsequent rules promote the overall mode. With `hive.radiatorHysteresis` set to its default 0.5°C per side, a previous false demand becomes true at threshold minus 0.5°C, and a previous true demand becomes false at threshold plus 0.5°C; equality switches at either boundary. Inside the band the previous fresh decision is retained. Missing or unusable previous state initialises at the original threshold. Zero margin restores the original comparison. The same retained decision gates the radiator stage of mode selection, keeping its heat request consistent with the valve request. See [radiator demand](heat-pump-control.md#3-apply-radiator-zone-load-compensation) for the full calculation.

The decision is not inferred from NIBE's current activity or whether the UFH check happened to change the mode. During UFH-only heating, a later radiator demand change can therefore open or close the radiator circuit at Hive's next minute check without restarting the heat-pump cycle.

The request is PUT `http://homeassistant.local:8123/api/webhook/hive_thermostat_adjust` with JSON `{"temperature": 30}` or the configured target. This appears intended to coordinate a zone's demand with the heat pump's actual activity; the Home Assistant automation and physical valve/wiring behaviour are outside this repository.

There is no stored-priority age check, setpoint-change check, or read-back of Hive state. The adapter accepts an integer target and does not inspect the response body beyond HTTP success. Each request logs the priority, radiator decision, and chosen target. The Home Assistant adapter is resolved through Laravel's container so automated tests can substitute it without sending a webhook.

## Hot water

Every five minutes, independently of the DM-override switch, `checkHotWaterRequirement()` reads the current API point `40014` (hot-water charging temperature). On Monday–Saturday, between **09:30 and 14:00 Europe/London inclusive**, a value below 33°C and `hotWaterSet != 'true'` causes today's **schedule 2 comfort mode** to be set to normal (`1`), then sets the flag true.

After **18:00**, if the flag is true, today's schedule 2 comfort mode is reset to economy (`0`) and the flag becomes false. At five-minute cadence the first normal reset opportunity is 18:05. Sundays return without changing either flag or schedule. This changes a day's schedule comfort mode; it does not directly start hot-water production or create the schedule's start/end times.

Schedule 2 parameter IDs are Monday `47659`, Tuesday `47658`, Wednesday `47657`, Thursday `47656`, Friday `47655`, Saturday `47654`, Sunday `47653`. Schedule 1 IDs and off/luxury comfort values are also defined but are not used by this routine.

The flag has no date. If the evening reset is missed, it can carry into a later day and suppress that day's boost; a later reset targets that later day's schedule entry.

## Database reference

| Table / model | Contents and current use |
| --- | --- |
| `settings` / `Setting` | Runtime text state plus `control.*` JSON-scalar operating parameters. The latter are typed, validated and editable through the authenticated control panel. No unique constraint on `key` in the migration. |
| `nibe_parameters` / `NibeParameter` | Parameter catalogue: primary `parameterId`, `title`, `designation`, `unit`. Gates which readings are stored and names Emon inputs. |
| `nibe_feed_items` / `NibeFeedItem` | ID, timestamps, parameter ID, optional local feed ID, source timestamp in seconds, decimal raw value, sync attempts/status. A non-unique `(parameterId, timestamp)` index speeds lookups. |
| `emon_feed_maps` / `EmonFeedMap` | Local/remote feed IDs and names. Used for named room readings and historical comparisons. |
| `feed_items` / `FeedItem` | Local/remote IDs, timestamp in milliseconds, value, sync bookkeeping for the unfinished repair path. |
| `nibe_to_emon_feed_maps` / `NibeToEmonFeedMap` | Mapping structure present, but no active application caller found. NIBE publication currently uses catalogue titles instead. |
| `activity_logs` / `ActivityLog` | Controller, method, level, message, and creation/update timestamps. |
| `api_logs` / `ApiLog` | HTTP method, endpoint (first 255 characters), optional JSON payload; hash/ignored/processed fields exist but are not part of an active processing pipeline. |
| Standard Laravel tables | Users, password resets, failed jobs, personal access tokens. The control panel uses existing users for session sign-in and provides a CLI account-creation command; it has no public registration. |

Migrations define structure only. `DatabaseSeeder` does not insert operational data. Feed-map and catalogue migrations omit normal model IDs/timestamps, while their models retain Eloquent defaults; the active code reads these tables, so future tooling that writes through those models needs to account for the schema.

## HTTP logging and administrator emails

`API.php` builds Guzzle requests and inserts an API log **before** sending. JSON payloads are logged; form payloads and headers are not. Responses, HTTP status codes, and request durations are not stored there. The constructor disables TLS certificate verification. There are no explicit request timeouts or application-level HTTP retries. GET endpoints can include Emon read keys in logged query strings.

Most command/controller errors are caught and stored in `activity_logs`. Command start/end markers do not guarantee all child operations succeeded, and caught failures often leave a successful command exit status.

At 06:00 application time, `admin:emails` selects error-level activity logs newer than one day ago, groups them through `ActivityLogSummariser::summarise()`, and sends `Activity Log Report [errors]`. Grouping precedence is port number, DNS resolution, connection reset, missing local feed data, cURL error number, then whitespace-normalised/case-insensitive exact message. Groups and source counts sort descending; first/last seen timestamps are included. Warnings are not included. An empty period still sends “Nothing to report”.

Messages containing `Unable to get latest data. $environment = local` share the **Missing local feed data** category even when feed IDs and query timestamps differ. Matching ignores case and allows whitespace variations. Remote-feed failures and other messages retain their existing grouping. This category reports a lack of usable readings within the requested window, rather than diagnosing why a sensor stopped reporting; the control loop's default room-temperature lookback is 180 minutes.

Other mail subjects are `Agile Rates` and `Nibe Offline?`. All use `app.admin_email` as recipient and `mail.from.address` as sender. Calls use synchronous `Mail::send()`; the Queueable traits do not make them queued jobs.

Daily log cleanup uses the editable `logs.*RetentionDays` operating settings (defaults: info 30, warning 60, errors/API 90 days). No cleanup for NIBE readings or feed backlog is implemented. `ApiLog::markAsProcessed()` exists but has no active caller found.
