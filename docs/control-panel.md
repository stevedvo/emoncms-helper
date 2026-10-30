# Control panel and live operating settings

[Project overview](../README.md) · [Control logic](heat-pump-control.md) · [Configuration and operation](configuration-and-operation.md)

The control panel has **Overview** at `/`, **Settings** at `/settings`, **Activity Logs** at `/logs/activity`, and **API Logs** at `/logs/api`. All require sign-in. No public registration or default password is supplied.

Sign-in sessions allow **eight hours of inactivity** by default. Requests to the app refresh that window; signing out ends the session immediately. The duration is configured in minutes with `SESSION_LIFETIME=480` in `.env`, independently of the operating settings in the database. Existing installations with `SESSION_LIFETIME=120` must update that value to use the longer duration. If Laravel configuration is cached, run `php artisan config:clear` after changing it.

## First use

For an existing installation whose database tables already exist:

```text
php artisan settings:import
php artisan app:create-user
```

The first command imports missing operating parameters from the application's effective configuration. It never overwrites an existing database parameter. The second asks for a name, email address and password; password entry is hidden and requires confirmation and at least 12 characters. Existing accounts are not replaced. Users already in the Laravel `users` table can sign in without creating another account. All manually provisioned accounts are trusted control-panel operators; there is no separate read-only role.

Open the application's normal URL and sign in. A web server must serve the `public/` directory. The panel uses local CSS/JavaScript files and needs no Vite build, npm install, or external asset service. A new installation still needs the ordinary Laravel/database setup and migrations described in the operations guide. The log browser adds a `saved_log_searches` table; existing log tables are unchanged. On an existing installation, apply its migration before opening the log pages:

```text
php artisan migrate --path=database/migrations/2026_10_20_000000_create_saved_log_searches_table.php
```

The Settings page also imports any missing operating parameters when first opened. This supports existing installations and newly added parameter definitions without replacing values already saved. Importing preserves the current effective operating values; it does not send any command to the heat pump, Hive or Home Assistant.

## Where values live

The dedicated [parameter catalogue](../config/control-parameters.php) defines the editable keys, labels, groups, defaults, data types and validation bounds. [ControlSettings](../app/Services/ControlSettings.php) reads them with:

```php
ControlSettings::get('nibe.dmTarget');
```

The corresponding database row is:

```text
settings.key   = control.nibe.dmTarget
settings.value = -180
```

Values are JSON scalars, so booleans are stored as `true`/`false`, integers as numbers, and fractional parameters as numbers. The `control.` prefix keeps editable parameters separate from runtime settings such as tokens, `htgMode`, forecasts and radiator demand. An unregistered key cannot be edited through the form or accepted by the accessor.

Until a parameter has been imported, its effective Laravel configuration value is the compatibility fallback, followed by the catalogue's default for newly introduced settings. After import, the database takes precedence. Editing its old `.env` variable no longer changes that operating parameter. Existing environment entries may be retained as bootstrap fallbacks, or removed after confirming the import; secrets and service connection details should stay in environment configuration.

The numeric/boolean catalogue covers NIBE, Hive, weather and Emon operating settings, including hot-water temperatures and ownership, radiator demand hysteresis, UFH comfort thresholds and preheating, boost-window and peak-window offsets, room-sensor history and log retention. Tariff clock times are edited separately below these parameters. Under **Room temperatures**, **UFH: cheaper-window preheat margin** adds 1°C by default to both UFH thresholds during boost windows outside peak import. Normal thresholds are never lowered. Set the margin to zero to disable that extra preheat allowance. Active room compensation determines the mode from adjusted room targets without a second scheduled promotion.

Under **Hive**, **Radiator demand hysteresis (each side)** defaults to 0.5°C, with an allowed range of 0–2°C. Once a demand decision exists, radiators request heat at or below the adjusted off threshold minus this margin and stop requesting heat at or above the threshold plus the margin. Between those boundaries, the previous fresh decision is retained. With an adjusted threshold of 19.5°C, the switch points are 19.0°C on and 20.0°C off. Missing or unusable previous state initialises at the original threshold. Zero disables hysteresis. This retained radiator demand controls both its mode-selection on/off check and Hive's valve request; UFH and other later mode rules still apply. Edits take effect at the next five-minute calculation, without restarting the scheduler.

**UFH: boost shortfall** sets X (default 1°C, allowed range 0.1–5°C). UFH requests at least `boost` when its measured temperature is **more than X°C below the allow-heating threshold plus the active preheat margin**. With defaults this means below 19°C normally, or below 20°C during preheating. Exactly X°C shortfall leaves the normal on/intermittent checks in control. Shortfall-based boost can apply during peak periods against the normal target, and does not depend on the scheduled boost-promotion switch. The defrost cap and cooling override still take precedence. Both settings are read live, so no scheduler restart is needed. See [UFH control](heat-pump-control.md#6-protect-a-cool-ufh-zone-and-preheat-during-boost-windows) for timing and forecast details.

Under **Weather and boosts**, **Boost window: start offset** defaults to -15 minutes and **Boost window: end offset** to -100 minutes. Negative values move a boundary earlier; positive values move it later. They apply to all three-hour, six-hour and Cosy boost windows when the schedule is next generated, normally at the next successful evening tariff refresh. Saving these parameters alone does not rewrite a schedule already stored. Whole-minute values from -180 to +180 are allowed, provided all configured boost windows retain a positive duration. Saving the separate tariff form refreshes existing Cosy entries immediately.

**Peak window: start offset** defaults to 0 minutes relative to the daily tariff peak start (default 16:00 Europe/London); **Peak window: end offset** defaults to -25 minutes relative to the daily peak finish (default 19:00). Together these give a **16:00–18:35 inclusive** peak control window, removing the former 14:40 early start while retaining the existing finish. Both accept whole-minute values from -180 to +180, with negative values moving a boundary earlier and positive values later. Validation requires the end to remain after the start. These settings affect the radiator -2°C peak adjustment, UFH preheat suppression and the cold-forecast peak minimum-mode rule at the next calculation. They do not require a restart or boost schedule regeneration. Set the end offset to zero if a 19:00 finish is wanted.

**Warm forecast threshold** still lowers the initial mode when the high-six forecast average exceeds it. During the **22 October 2026 trial**, the separate -1°C radiator-band adjustment is disabled in code, so changing this setting does not restore that reduction. With forecasts enabled, Cosy windows remain eligible: UFH preheating remains available outside peak import, and the full radiator +1°C boost adjustment applies in active windows. The trial also lets cold-forecast radiator additions apply despite a warm daytime high. **Allow scheduled boost promotion** controls fallback direct mode promotion only when room compensation is disabled or usable Rad data is unavailable. With active room compensation, the setting has no effect on mode selection: adjusted room targets determine demand, including boost/extraBoost when needed. Turning it off does not disable the room-temperature adjustments.

The separate **Tariff periods** section below the operating parameters has its own save button. It edits daily cheap/peak times, dated offers/saving sessions, hot-water start preferences, Agile rate-search ranges and the daily fetch time. All use London time. Tariff times are stored together in `settings.octopusTariffPeriods`; existing numeric/boolean parameters remain in `control.*` rows. Saving tariff periods refreshes existing Cosy entries immediately without changing the calculated cheapest schedules. Dated heating windows use the same boost/peak offsets as daily heating; hot water uses actual tariff times. All dated windows expire automatically. See [tariff periods](tariff-periods.md) for precedence, boundaries and examples.

The two inactive legacy NIBE parameters remain clearly labelled as unused. Other algorithm constants, including radiator room-band adjustment amounts and the original controller-managed hot-water schedules, retain their existing implementation.

## When changes take effect

There is no process-lifetime cache in `ControlSettings::get()`: each access reads the database. The NIBE controller reloads its parameter properties at each polling/control calculation rather than retaining the first values with `??=`. Room-data caches reset for each polling invocation.

Consequently:

- Mode and degree-minute steering normally use edits at the next five-minute calculation.
- Peak-window offsets apply at the next evaluation, normally the next five-minute control calculation.
- Hive reads its operating values on the next minute invocation.
- Emon comparison history applies at the next comparison.
- Retention periods apply at the next cleanup.
- Boost-window offsets apply when the tariff/boost schedule is next generated, rather than at the next heating calculation.

No scheduler restart or configuration-cache rebuild is needed for **subsequent operating-setting edits**. Deploying new PHP code or changing credentials, connection configuration or command schedules still follows the installation's normal deployment process. The change does not reschedule tasks or force an immediate heating cycle. A calculation already in progress can overlap a save and may read settings on either side of that commit; the next complete calculation sees the new values.

## Saving and validation

The form submits to authenticated, CSRF-protected `PUT /settings`. Server validation checks types, numeric limits and related values, including:

- Radiator and UFH temperature-band ordering.
- Positive steering horizon and offset factor.
- Minimum/maximum offsets and inclusion of special-mode limits.
- Normal, off and boost DM target ordering.
- Outdoor thresholds, dew-point bounds and Hive target ordering.
- Whole-minute boost-window offsets that leave a positive duration for the shortest window.

Edits are saved in one database transaction. A validation error saves nothing. A revision token detects a form based on older parameter values, so one browser session cannot silently overwrite another session's edits. On a stale-form error, reload the page before editing again. An invalid form retains its entered values for correction, but retains its old revision as well.

Changed parameters are logged with operator ID, label and old/new values; secrets are excluded by the editable-key catalogue. Unchanged parameters create no change record. Runtime records and credentials cannot be changed by submitting extra field names.

## Overview data

The overview shows NIBE priority, selected helper mode, radiator-demand status, automatic-control enablement, Rad/UFH temperature cards, outdoor temperature, degree minutes, latest stored NIBE points, additional local Emon feeds grouped by device, warning/error counts, and recent settings edits.

The Rad/UFH cards initially contain placeholders and are filled by the browser's Emon refresh. Room feeds are identified by `emon_feed_maps.localFeedId` for the `Rad_temperature` and `UFH_temperature` aliases, matching the control loop's mappings. Emon's actual display names can differ or both be `temperature`. Those raw names remain visible in the feed table. If a room has no mapping, an exact canonical name is accepted as a fallback; once mapped, an unrelated feed with a matching name cannot replace it. A mapped feed missing from the response leaves its card explicitly unavailable.

The Emon list omits NIBE exports identified by their local NIBE feed mapping, NIBE device tag or `nibe_`-style name prefix. Untagged, unmapped feeds matching known NIBE parameter/export names are also omitted; independently mapped sensors and other tagged devices are preserved even when their parameter names coincide. This affects only the dashboard response, never Emon feed storage, uploads or chart history.

Remaining entries are sorted by parent device and then feed name, with a device heading in the table. Rad/UFH mappings give friendly **Radiator zone · Main building** and **UFH zone · Annexe** headings for temperature, humidity, battery and sibling feeds sharing a tag unique to that room (including a temperature forecast). Other devices use their Emon tag. When tags are absent, known mapping prefixes provide room, heat-meter or electricity-meter groups; unidentified parents appear under **Other feeds**. Identically named measurements on different devices remain separate entries. Empty results show **No additional Emon feeds to show.**

NIBE readings come from the helper's database, selecting the last inserted row per parameter and joining catalogue labels/units. The page does not trigger a myUplink poll. It shows **source sample times** in Europe/London; opening the page does not make an old sample current. Reload the overview to refresh the stored NIBE/state sections.

Immediately before **NIBE readings**, the **Hot Water** section shows the latest stored **hot water charging** (40014) and **hot water top** (40013) temperatures in °C, with their last value-change times. A **Control status** table presents the `hotWaterControl` setting as labelled rows: the latest decision, control ownership, named comfort and pending modes, active recharge details, evaluation/poll/telemetry times, completed recharge dates and retry timing. Times use Europe/London with explicit BST/GMT labels; missing values and unreadable saved state are identified without breaking the page. The header separately shows whether app-managed hot water is enabled in Settings. This section is read-only and updates when the overview is reloaded, without issuing heat-pump commands.

The browser separately fetches `/dashboard/feeds` immediately and every minute while visible. That authenticated endpoint reads local Emon `/feed/list.json` with the configured read key. The request has a two-second connection timeout and four-second total timeout. It does not call the heat-pump or thermostat write paths. No key or raw service exception is returned to the browser.

Room-feed freshness uses the configured room-sensor history; other feed samples older than five minutes are marked for attention. Missing values remain missing, including when the legitimate numeric value is zero. If Emon fails, the stored NIBE page remains available, the connection message explains the problem, and any previously displayed Emon values are labelled as last displayed values. Refresh/retry does not claim those values are current. The read adapter is separate from the existing integration wrapper and does not add dashboard reads to `api_logs`.

## Browsing logs and saving searches

Open **Activity Logs** or **API Logs** in the sidebar. Activity entries can be filtered by controller, method, level and message. API entries instead expose their stored method, endpoint and payload; their ignored/processed/pending status is displayed alongside expandable payload text. Log content is displayed as escaped text, including HTML appearing in a payload or message.

Each condition has a field, a match operator and text. Choose **contains**, **does not contain**, **equals**, or **does not equal**. Use **Add filter** to combine up to 20 conditions, including multiple conditions on the same field. All non-empty conditions must match (AND). For example, select `message contains UFH` and `message does not contain fallback` to inspect UFH decisions while excluding fallback entries. Text matching ignores case; `%`, `_`, `!` and controller namespace backslashes are literal characters. Empty database fields count as empty text, so they match a negative condition unless excluded by another condition. Blank filter inputs are ignored; `0` remains a valid search term.

Optional **From date** and **Through date** include whole UK calendar days, including daylight-saving transitions. Results show the newest inserted entries first, with UK timestamps. **Entries per page** offers **100, 250, 500 or 1000**, defaulting to **250**. Choose a size and run **Search logs** to apply it, starting at the first page. Existing saved searches and bookmarks using 25 or 50 remain supported; their current size appears alongside the new choices when loaded.

Results show the matching total and the displayed entry range. At the bottom, **Page … of …** and **Go to page** let you jump directly to any page, alongside **Newer entries** and **Older entries**. Navigation preserves the applied filters, dates, page size and loaded saved-search name, and takes you straight to the results heading. If a requested page is beyond the available results, the browser returns to the last page. Page totals reflect the matching records when the request runs; the list is not a frozen snapshot across requests.

Use **Clear filters** to return to all entries at the default page size. Searches read stored records without polling integrations or issuing equipment commands; ordinary log-retention rules still apply. Counting and searching broad log histories may take longer; date limits can narrow the work.

After running a search, enter a name and choose **Save applied search**. This saves the filters currently used for the displayed results, including the date range and page size. Editing filter inputs alone does not change the saved criteria: run the search first. Saving under an existing name updates that search for the same log type. Clicking a saved name reloads its criteria and runs them against the current log records, starting at the first page. A page-size override while viewing a saved search affects the current results without changing the stored search unless it is saved again. The date range is saved as specific calendar dates rather than a moving time window.

Saved searches belong to the signed-in account, are stored in the database, and remain available after signing out or restarting the browser/scheduler. Activity and API searches have separate lists. **Delete** removes only the named saved search; log entries remain unchanged. One account cannot load, replace or delete another account's searches.

## Source and verification

| Source | Responsibility |
| --- | --- |
| `config/control-parameters.php`, `app/Services/ControlSettings.php` | Allowed parameters, typed reads, initial import, revision check and audited writes. |
| `app/Http/Requests/UpdateControlSettingsRequest.php` | Field and relationship validation. |
| `app/Http/Controllers/SettingsController.php` | Settings page and save flow. |
| `app/Http/Controllers/LogBrowserController.php`, `app/Http/Requests/LogSearchRequest.php`, `app/Services/LogSearch.php` | Validated log filters, pagination and private saved-search actions. |
| `app/Models/SavedLogSearch.php`, `database/migrations/2026_10_20_000000_create_saved_log_searches_table.php` | Persistent named criteria, scoped to account and log type. |
| `resources/views/logs/`, `public/js/log-search.js` | Log pages, repeatable filters and saved-search forms. |
| `app/Http/Controllers/SessionController.php` | Session sign-in/out; login is rate limited to six attempts per minute per IP. |
| `app/Http/Controllers/DashboardController.php`, `app/Services/LocalEmonFeeds.php` | Stored NIBE/state overview and bounded live Emon reads. |
| `resources/views/layouts/control-panel.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/settings.blade.php`, `resources/views/login.blade.php` | Responsive pages and accessible, labelled forms. |
| `public/css/control-panel.css`, `public/js/control-panel.js` | Styling, unsaved-edit notice, and Emon refresh/error handling. |
| `app/Console/Commands/ImportControlSettings.php`, `app/Console/Commands/CreateControlPanelUser.php` | Initial import and manual account provisioning. |

The test suite uses isolated in-memory SQLite connections and mocked outbound requests. It covers import preservation, typed/live control reads, cache refresh, login/logout/throttling, unauthenticated requests, CSRF protection, validation with no partial writes, stale-form rejection, secret exclusion, dashboard empty/readings states, zero-valued Emon data and service failures. Browser checks used a separate demonstration database and a local fake Emon service; no live heating commands were sent.

Log-browser tests additionally cover combined/repeated conditions, negative matches against empty fields, literal wildcard characters, case-insensitive text matching, escaped log content, date boundaries across a UK clock change, pagination, invalid-input recovery, saved-search persistence and ownership, and CSRF protection. Browser checks use separate demonstration records; read-only query checks also run against the local MySQL connection.
