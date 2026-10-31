# Heat pump control

[Project overview](../README.md) · [Integrations](integrations-and-data.md) · [Configuration](configuration-and-operation.md) · [Implementation notes](implementation-notes.md)

This guide describes the active code in [NibeController.php](../app/Http/Controllers/NibeController.php). All defaults are the checked-in values, not a claim about the live installation. Operating values are now read through `ControlSettings::get()` from the database and can be edited on the authenticated [Settings page](control-panel.md).

## Installation context

The owner clarified the following installation details on **20 October 2026**:

- The UFH wall thermostat is set to **23°C**, so at the usual room temperatures it is effectively always calling for heat and holding the UFH zone valve open. It has no Internet connection and locally controls only whether that valve is open or closed. It can still close the valve when its own setpoint is satisfied. The helper's UFH thresholds and preheat target govern the requested ASHP heating mode; they do not adjust this thermostat or directly operate its valve. The helper does not receive the valve's position.
- The NIBE has an owner-configured compressor curve. Observed behaviour is that degree minutes below approximately **-300** cause a higher compressor stage, typically about **+30Hz** above the speed otherwise selected from the curve. Below approximately **-420**, a further stage adds roughly another **30Hz**. The owner is less certain of the second threshold; both are installation observations, not verified universal NIBE specifications.

These compressor stages explain the intent of the checked-in DM targets: normal `on` targets **-180**, `boost` targets **-360** to encourage the first higher stage, and `extraBoost` targets **-450** to encourage the next. The helper steers DM through flow-temperature settings rather than writing DM or frequency directly, and NIBE retains control of the actual compressor response. A UFH shortfall requesting `boost` therefore participates in the same deliberate compressor-staging strategy as radiator demand.

## The control loop

`nibe:getData` calls `getNibeData()` every minute. It obtains all myUplink device points, stores recognised readings, and sends new or previously unsuccessful readings to Emoncms. When the current minute is divisible by five, it also runs these operations, in order:

1. `dmOverride()` if `nibe.dmOverride !== false`.
2. Weather forecast publication if `weather.useForecast !== false`.
3. UFH room-temperature forecast publication if `nibe.loadCompensationOn !== false`.
4. The hot-water requirement check, unconditionally.

Each of these functions catches and logs its own errors. An earlier error in the outer polling function can still prevent the five-minute work from being reached. There is no catch-up mechanism for a missed five-minute slot.

The override requires all eleven of these points in the current API response, even if some are only relevant to cooling:

| Parameter | Meaning in the code | Use |
| --- | --- | --- |
| `40004` | Outdoor temperature | Boost selection. |
| `40067` | Average outdoor temperature | Boost selection and final cooling decision. |
| `40071` | External flow temperature | Degree-minute steering calculation. |
| `40940` | Degree minutes | Current value to steer. |
| `43009` | Calculated heating flow temperature | Heating steering calculation. |
| `47007` | Heating curve | Required and read, but not subsequently used or changed. |
| `47011` | Heating offset | Primary heating adjustment. |
| `47015` | Minimum flow-line temperature | Additional adjustment when heating offset reaches its maximum. |
| `49994` | Priority | Current activity: `20` hot water, `30` heating, `60` cooling; `10` is treated as off in comments. |
| `44270` | Calculated cooling flow temperature | Cooling steering calculation. |
| `48739` | Cooling offset | Primary cooling adjustment. |

Missing any required point aborts the override and records an error. The current response is used directly for control; there is no source-timestamp freshness check here.

## Modes and degree-minute targets

The working model behind the calculation is accumulated temperature shortfall: when external flow is below the calculated requirement, degree minutes become more negative; when it is above the requirement, they recover. The helper changes the calculated requirement to influence that balance. The source formula and comments express this model; the exact compressor response remains NIBE's responsibility.

The ordered heating ladder is:

```text
off < intermittent < on < boost < extraBoost
```

The nudge helpers move one step, stopping at the ends. The `atLeast...` helpers impose a lower bound, while `htgModeAtMostOn()` lowers `boost` and `extraBoost` to `on`. Cooling is chosen separately at the end.

| Mode | Target formula | Default target | Practical distinction |
| --- | --- | ---: | --- |
| `off` | `dmTargetOff` | +30 | Steer toward the off target. While NIBE priority is hot water (`20`), subtract 60, giving -30. |
| `intermittent` | `dmTarget` | -180 | Maintain demand subject to a restricted offset; permit cycling when that limit is reached. |
| `on` | `dmTarget` | -180 | Same target, with normal offset range and minimum-flow escalation available. |
| `boost` | `dmTargetBoost` | -360 | Request a larger heat deficit. |
| `extraBoost` | `dmTarget + dmTargetBoost + 90` | -450 | Request a still larger heat deficit; the +90 is hard-coded. |
| `cooling` | `dmTargetCooling` | +75 | Use calculated cooling flow temperature and cooling offset. |

An unrecognised mode returns target 0 and logs a warning. The `outdoorTemp` argument to `calculateTargetDm()` does not currently affect its result.

The modes influence NIBE through temperature settings. There is no exact compressor-frequency mapping and no guarantee that selecting `off` immediately stops heating. The stored `htgMode` is the helper's chosen mode, not a measurement of NIBE's actual priority.

## How the heating mode is chosen

`calculateHeatingMode()` starts fresh at `intermittent` on each calculation. It does not resume from the previously stored mode. The following is the active execution order; the numbered “adjustment check” comments in the source are historical and are not execution order.

### 1. Read forecast summaries and allow for a warm day

If forecasts are enabled, obtain the average of the highest six and lowest six available temperatures in the next 24 hours, plus the weighted future average. These are explained in [the weather reference](integrations-and-data.md#weather).

A **warm forecast** means the high-six average is strictly greater than `runLevel1Temp` (default 12°C). This nudges the initial `intermittent` down to `off`.

The **22 October 2026 trial** disables only the separate -1°C radiator room-band adjustment described below. This initial mode nudge is still active, and later room demand or other rules can raise the mode again.

### 2. Determine the boost window and peak window

`isBoostActive()` evaluates the stored `agile_schedule`. With forecasts enabled, all currently active outdoor-temperature branches choose `cosy`, including when the high-six forecast average exceeds `runLevel1Temp`. A warm forecast no longer vetoes the stored Cosy windows. Without forecasts, outdoor temperatures choose between the stored cheapest-three-hour, cheapest-six-hour, or continuous boost cases. See [boost schedule selection](#boost-schedule-selection).

`isPeakImport()` uses **16:00 through 18:35 inclusive, Europe/London** by default. The former 80-minute early start has been removed, so the -2°C radiator peak adjustment no longer applies from 14:40. The 25-minute early finish is preserved.

Both boundaries are editable under **Settings → Weather and boosts**: **Peak window: start offset** (`nibe.peakImportStartOffsetMinutes`, default 0) is added to the daily peak start (default 16:00), and **Peak window: end offset** (`nibe.peakImportEndOffsetMinutes`, default -25) is added to the daily peak finish (default 19:00). Negative values move a boundary earlier; positive values move it later. Whole-minute offsets from -180 to +180 are allowed, provided the resulting window has positive duration. Input times are converted to London time without changing the caller's time object, so the window follows British summer/winter time.

The peak offsets are read at each evaluation, so saved changes apply at the next control calculation without a restart or schedule regeneration. The same peak flag controls the radiator -2°C adjustment, suppression of UFH preheating, and the cold-forecast peak minimum-mode rule. The underlying daily tariff times are editable separately under **Tariff periods**. Dated saving sessions use the same peak-control offsets; dated cheap offers use the boost offsets, overriding the daily heating peak and enabling boost eligibility during their adjusted interval. Dated peak controls take priority if the adjusted windows overlap. Hot-water admission continues to use actual tariff times. See [tariff periods](tariff-periods.md). The Octopus boost-window offsets also determine stored daily Cosy windows; changing peak offsets does not extend those stored boost windows.

### 3. Apply radiator-zone load compensation

Read `Rad_temperature` from local Emoncms, searching back `roomTemperatureLookbackMinutes` (default 180 minutes) and taking the last non-null value, rounded to one decimal place. A usable reading is stored as `RoomTemperature[Rad_temperature]` and sets `allowLoadCompensation` to the string `true`. If unavailable, the saved temperature is used and `allowLoadCompensation` becomes `false`.

The room-temperature bands apply only when load compensation is enabled and that setting is exactly the string `true`. The fallback value therefore does not enable the bands on its own. A first-ever failure with no saved temperature returns null and disables load compensation for that calculation.

Every band boundary is shifted by the same adjustment `A`:

| Condition | Addition to A |
| --- | ---: |
| In the peak window | -2°C |
| Warm forecast | 0°C during the 22 October trial; the previous -1°C assignment is commented out. |
| Boost window active, including during a warm forecast | +1°C |
| Low-six forecast average < `dmTargetBoostTemp` (default 3°C), including with a warm high forecast during the current trial | +2°C |
| Otherwise low-six forecast average < hard-coded 3°C, including with a warm high forecast during the current trial | +1°C |

With the defaults, the +1°C cold-forecast branch cannot be reached: both cold comparisons use 3°C, so the +2°C branch wins. Changing `dmTargetBoostTemp` below 3°C makes a +1°C band possible.

The boost-window +1°C adjustment is based on `boostActive` even if `allowBoosts` is false. That flag controls fallback scheduled mode promotion when room compensation is inactive; active room compensation selects intensity from the adjusted room targets without a second scheduled promotion. During the current trial, a warm forecast contributes zero to the room-band adjustment, so the full +1°C boost adjustment applies. For a base radiator off threshold of 21.5°C, with no peak or cold-forecast adjustment, the adjusted centre is therefore 22.5°C inside a boost window and 21.5°C outside it, before hysteresis is applied.

The cold-forecast additions are guarded by `highForecastRoomTempAdjust === 0`. Since the trial sets that value to zero for both warm and cool high forecasts, this guard now always passes: a cold low-six average can raise the radiator bands even if the high-six average exceeds `runLevel1Temp`. This is a consequence of disabling the warm room-band adjustment.

The original -1°C assignment remains commented out for possible reinstatement. Restoring it and raising `nibe.runLevel1Temp` would move the warm-forecast cut-off; changing that setting alone during this trial does not restore the room-band reduction. The setting still affects the initial mode nudge and forecast-disabled boost selection.

The radiator temperature is tested against these bands in descending order:

| Radiator temperature T | Result at this stage, with default thresholds |
| --- | --- |
| T > 21.5 + A | Set `off`. |
| 21 + A < T <= 21.5 + A | Ensure at least `intermittent`. |
| 20 + A < T <= 21 + A | Ensure at least `on`. |
| 19 + A < T <= 20 + A | Ensure at least `boost`. |
| T <= 19 + A | Set `extraBoost`. |

Equality falls into the colder, more demanding band. A positive adjustment requests warmer rooms; a negative adjustment permits cooler rooms. The intensity bands have no separate deadband or mode-persistence timer. The radiator demand on/off decision uses the hysteresis below before these intensity bands are applied.

Let `C = loadCompTempOff + A` and `H = hive.radiatorHysteresis` (default **0.5°C each side**). A fresh previous radiator-demand decision is read from the settings table:

| Previous radiator demand | New decision |
| --- | --- |
| Off | Switch on at `T <= C - H`; otherwise remain off. |
| On | Switch off at `T >= C + H`; otherwise remain on. |
| Missing, unknown, invalid, stale or future-dated | Initialise using the original comparison `T <= C`. |

For an adjusted threshold of **19.5°C**, an established off decision remains off until **19.0°C**, and an established on decision remains on until **20.0°C**. Between these boundaries, keep the previous decision. This is a 1°C total band. Zero margin restores the original `T <= C` comparison, including equality. Fractional switching boundaries are rounded to six decimal places. The current peak, boost and forecast adjustments move the centre of the band on each calculation.

If this retained radiator decision is false, the radiator stage selects `off`. If true, the existing intermittent/on/boost/extraBoost intensity comparisons apply, with at least intermittent even above `C` while demand is retained. A radiator whose valve is held off cannot alone start a heating cycle. Cycle preservation, UFH demand, and peak minimum-mode rules can raise the overall heat-pump mode without changing the temperature decision. If the room bands cannot be evaluated, radiator demand is null (unknown).

The decision and calculation time are saved at the end of each calculation, so hysteresis persists between scheduler invocations. The existing decision-age limit applies to the previous state (default ten minutes, inclusive); after a longer gap the next valid temperature initialises a new decision. Activity logs show the temperature, adjusted centre, margin, switching boundaries, previous state and result. The margin is editable under **Settings → Hive** and applies at the next control calculation without a scheduler restart.

An independent **radiator boost hold** keeps Hive's radiator circuit open once the temperature decision is true during an active scheduled boost and NIBE priority is heating (`30`). It lasts until that occurrence's actual cheap-period end, or its adjusted boost end if that is later. With the default morning period and -100-minute end offset, boost adjustments finish at **05:20**, but an armed radiator hold lasts until **07:00**. UFH-only heat cannot arm the hold. Radiators can join later in the boost if their own temperature decision becomes true.

The stored temperature decision continues to follow the adjusted targets and hysteresis during this hold. It can become false and select `off`; the existing priority-30 cycle-preservation rule then keeps the overall mode at least `intermittent`. The valve hold itself never promotes the mode or starts an idle pump. Hive still uses its off target during hot water or idle operation, and its existing cooling target during cooling.

The hold expires at the exact end timestamp, checked by Hive's minute task, without waiting for another mode calculation. Peak/saving-session controls cancel it, except that an actual dated cheap offer can retain its valve hold through its full cheap interval inside the normal daily peak. Dated peak controls still take precedence. Unknown temperature demand, disabled compensation/DM override, cooling, or an expired decision clears the hold at the next calculation. Normal freshness safeguards apply throughout. The expiry is captured when the hold is armed; repeated raw false decisions do not extend it. Continuous forecast-disabled boost branches have no finite window and do not arm a timed hold.

### 4. Promote the mode during an allowed boost

As of **23 October 2026**, this step is a fallback for inactive room compensation. The controller calculates one `$loadCompensationActive` flag from the enabled `nibe.loadCompensationOn` setting and the successful-Rad-lookup `allowLoadCompensation` flag. The same flag gates the radiator and UFH room rules.

With active room compensation, this step is skipped: the adjusted radiator targets, UFH preheat thresholds and UFH shortfall select the required mode, so a room-derived boost is not promoted a second time. A boost window alone does not start an idle pump when both rooms are satisfied. Strong radiator demand can still select `extraBoost`, and a UFH shortfall can still select `boost`.

When room compensation is disabled or usable Rad data is unavailable, `allowBoosts !== false` and an active boost window permit the existing promotion:

| Mode before promotion | Mode after promotion |
| --- | --- |
| `off` | `intermittent` |
| `intermittent` | `boost` |
| `on` | `boost` |
| `boost` | `extraBoost` |
| `extraBoost` | `extraBoost` |

This retains the pre-room-compensation behaviour when room control cannot be used. Merely enabling load compensation is not enough to suppress fallback promotion: the Rad lookup must also allow it. The fallback still respects the promotion setting and active-window check, and records the `Boost is active` log message when it runs.

### 5. Preserve a running heating cycle

If the calculated mode is `off` but priority is `30` (already heating), change to `intermittent`. This avoids deliberately shortening the current heating cycle. It is not an unconditional minimum runtime.

### 6. Protect a cool UFH zone and preheat during boost windows

When radiator-based load compensation is allowed, read `UFH_temperature` using the configured room-sensor history (default 180 minutes). The normal UFH thresholds remain minimum comfort protection, including during expensive periods. With the default editable UFH thresholds:

- Below **19°C** (more than 1°C below the normal 20°C target), ensure at least `boost`.
- Otherwise, at or below **19.5°C**, ensure at least `on`.
- Otherwise, at or below **20°C**, ensure at least `intermittent`.
- Above 20°C, leave the mode alone.

When `boostActive` is true and `peakImport` is false, add `nibe.ufhBoostTempAdjust` (default **1°C**) to both thresholds. This requests heat earlier during the existing boost windows so the annexe can store heat ahead of less favourable periods:

| Period | Ensure at least `boost` | Otherwise ensure at least `on` | Otherwise ensure at least `intermittent` |
| --- | --- | --- | --- |
| Normal or peak, with defaults | UFH < 19°C | UFH <= 19.5°C | UFH <= 20°C |
| Boost outside peak, with a 1°C preheat margin | UFH < 20°C | UFH <= 20.5°C | UFH <= 21°C |

The UFH target for the shortfall calculation is `nibe.ufhTempIntermittent` (the **allow heating at** setting, default 20°C), plus any active preheat margin. When `target - measured temperature > nibe.ufhBoostTempShortfall` (default **1°C**), ensure at least `boost` before testing the existing on/intermittent bands. The comparison is strict: a 1°C shortfall with X = 1°C does not trigger this rule. The calculated shortfall is rounded to six decimal places to avoid floating-point noise at exact boundaries. The rule applies both within and outside boost windows, including during peak periods; only the preheat margin is suppressed during peak periods. It never requests `extraBoost` on its own and never lowers a higher mode already requested elsewhere.

All four parameters are editable under **Room temperatures** in Settings and are read on every calculation. The preheat margin accepts 0–3°C; setting it to zero removes the extra preheat allowance in every window. **UFH: boost shortfall** accepts 0.1–5°C. Like radiator room-band demand, UFH preheating and shortfall-based boost operate even when `allowBoosts` is false. That flag governs step 4's fallback scheduled promotion, which only runs when room compensation is inactive.

UFH does **not** copy the radiator's negative peak adjustment or its cold-forecast temperature additions. The former warm-forecast radiator reduction is disabled during the current trial. Neither a peak period nor a warm forecast lowers the UFH early-start protection. A peak period always suppresses the extra UFH preheat margin, even if a boost window overlaps it. This does not veto a higher mode requested by another rule, such as radiator demand, cycle preservation or the cold-forecast peak minimum.

Preheating uses the existing boost-window decision, including its weather/schedule handling. With forecasts enabled, a warm forecast retains Cosy eligibility, so UFH can still receive its preheat margin inside a valid stored Cosy window outside peak import. A usable weighted forecast and stored schedule are still required. These windows are the app's existing timing signals, not a new live-price comparison. The aim is to reduce the likelihood of needing peak-period heating; the normal comfort thresholds can still request heating outside boost windows, and no room-temperature outcome is guaranteed by the software alone.

This read uses `updateSettings = false`; it neither falls back to saved UFH temperature nor changes `allowLoadCompensation`. Missing UFH data skips this step. UFH raises the overall mode to at least `intermittent`, `on` or `boost`, preserving higher radiator/boost demand and the independent radiator valve decision. Log messages report the measured UFH temperature, effective threshold and preheat margin; shortfall-based boost also records the target, shortfall and configured X. Later defrost/cooling rules retain their existing precedence.

When a valid UFH temperature is above the effective allow-heating threshold, an information-level message containing `no independent demand` records the temperature, preheat margin and unchanged mode at this stage. This means UFH is not requesting heat independently; it does not mean the ASHP is off, since other rules may already have requested heating. The later peak, defrost and cooling checks can still change the mode.

### 7. Apply the remaining peak-period rule

During the peak window, if the low-six forecast average is below `dmTargetBoostTemp`, ensure at least `intermittent`. The other peak branches only log: their downward mode nudges are commented out. The active peak reduction mainly comes from the -2°C room-band adjustment in step 3.

### 8. Cap demand when reducing defrosts

`isReducingDefrosts()` finds the minimum numeric evaporator reading (`44363`) among database rows **created in the last 60 minutes**. It returns true if either:

- The minimum is below `defrostEvaporatorMax - 5` (default: below -20°C), regardless of dew point; or
- Dew point is strictly between `defrostDewpointMin` and `defrostDewpointMax` (defaults -3°C and 5°C), and the minimum is below `defrostEvaporatorMax` (default -15°C).

True caps `boost` or `extraBoost` at `on`; lower modes stay unchanged. Missing evaporator readings, unavailable dew point for the second condition, or an exception return false. This is a heuristic based on temperatures, not a defrost event counter.

### 9. Override everything with cooling when warm enough

If NIBE's average outdoor temperature is strictly above `coolingStartTemp` (default 24°C), return `cooling`. This final rule overrides all heating decisions, including the defrost cap. Forecast-based pre-emptive cooling is commented out.

At the end of a successful mode calculation, `RadiatorHeatDemand::record()` stores the separate radiator temperature decision, calculation timestamp and any boost-hold expiry in `settings.radiatorHeatDemand`. This happens on every calculation, including unchanged or unknown decisions, before the offset calculation or any possible early return in that later work. Hive uses a fresh decision together with the unexpired hold to gate heating of the radiator zone; see [Home Assistant / Hive](integrations-and-data.md#home-assistant--hive).

The overall result is logged and saved as `settings.htgMode` before calculating and sending temperature changes. A later write failure can leave that setting describing a mode whose requested settings were not applied.

## Boost schedule selection

With forecasts enabled:

1. Read and decode `settings.agile_schedule`.
2. Require a non-null weighted forecast temperature; otherwise log an error and return false.
3. Choose `cosy` in every active outdoor-temperature branch.
4. Retain `cosy` when the high-six forecast average exceeds `runLevel1Temp`; this no longer causes an early false return.
5. Return true only strictly inside one of the stored Cosy start/end pairs.

The dormant `constant` schedule branch is unreachable from the current forecast-enabled assignments. Exact schedule start and end instants are excluded. Persisted schedule dates matter; these are timestamped windows, not a daily repeating clock rule. Warm-day eligibility enables the radiator +1°C room-band adjustment and, outside peak import, the UFH preheat margin. Direct scheduled mode promotion requires inactive room compensation as well as `allowBoosts`; with active room compensation the adjusted room targets determine demand.

With forecasts disabled, the following defaults describe the active branches. “3h” and “6h” mean membership in the corresponding stored schedule, whose actual control windows are shortened by offsets. The code performs comparisons in the order shown; changing threshold order can change the table's interpretation.

| NIBE average outdoor temperature | Current outdoor temperature | Boost active? |
| --- | --- | --- |
| >= 12°C | >= `dmTargetOffTemp` (12°C) | No. |
| >= 12°C | Otherwise | 3h schedule. |
| >= 9°C and < 12°C | >= `dmTargetOffTemp` | No. |
| >= 9°C and < 12°C | Otherwise >= `runLevel1Temp` (12°C) | 3h schedule; unreachable with default off threshold 12°C. |
| >= 9°C and < 12°C | Otherwise | 6h schedule. |
| >= 6°C and < 9°C | >= `dmTargetOffTemp` | No. |
| >= 6°C and < 9°C | Otherwise >= `runLevel1Temp` | 3h schedule; unreachable with defaults. |
| >= 6°C and < 9°C | Otherwise >= `runLevel2Temp` (9°C) | 6h schedule. |
| >= 6°C and < 9°C | Otherwise | Yes, continuously. |
| < 6°C | Any | Yes, continuously. |

`boostMaxCost` is not consulted. Exceptions in this function log an error and return false. Generation of the schedules is documented under [Octopus and schedules](integrations-and-data.md#octopus-and-schedules).

## Converting the target into temperature changes

`dmOverride()` uses the external flow temperature, current degree minutes, and current calculated flow temperature to estimate a new offset:

```text
required DM change per minute = (target DM - current DM) / minutesToDm
desired calculated flow      = external flow - required DM change per minute
offset change                = round((desired calculated flow - current calculated flow) / offsetFactor)
```

The defaults are a 30-minute horizon and 2°C per offset step. The code uses PHP `round()`, not floor, despite an older comment saying “round down”. A negative change lowers calculated heating flow temperature and encourages degree minutes to recover; a positive change raises it and encourages a larger deficit.

For example, with external flow 32°C, calculated flow 30°C, current DM -120, and target -180:

```text
desired calculated flow = 32 - ((-180 - -120) / 30) = 34°C
offset change           = round((34 - 30) / 2) = +2
```

This is an estimate recalculated every five minutes, not a promise that the target will be reached in exactly 30 minutes.

### Heating limits and minimum flow temperature

Normal heating offset limits default to -10 and +10. Each candidate offset is clamped to the applicable range.

For `intermittent`, or **any** mode while `cheapMode !== false`, the upper limit is dynamic:

1. Calculate an offset change toward `dmTargetOff`, using the same formula.
2. Add that change to the current heating offset and clamp to the normal range.
3. Take the larger of this candidate and the special mode limit: `offsetMaxInt` (default 0), or `cheapModeOffsetMax` (default -4) when cheap mode is enabled.

This preserves scope to steer very high degree minutes back down while otherwise limiting sustained demand. Cheap mode changes adjustment limits; it does not directly choose a heating mode or test a price.

For non-cooling modes:

- **Zero offset change:** clamp the heating offset to the applicable range if necessary.
- **Positive change in intermittent/cheap mode:** adjust the heating offset up to the dynamic maximum, without raising minimum flow temperature. The older early return in this branch is commented out.
- **Positive change in other modes:** increase heating offset. If this first reaches its maximum, also set minimum flow temperature to the rounded current calculated flow temperature. If the offset was already at its maximum, increase minimum flow temperature by `offsetChange * offsetFactor`, capped at 60°C.
- **Negative change:** if minimum flow temperature is above the 10°C baseline and is determining calculated flow, lower it first, down to 10°C. If it is above baseline but below calculated flow, reset it to 10°C and lower the heating offset. Otherwise lower the heating offset directly.

Intermittent/cheap operation does not automatically reset an already raised minimum flow temperature to 10°C. Reductions follow the negative-change branch. The initial “prepare minimum flow” write uses rounded calculated flow without an explicit 10–60°C clamp; the 60°C cap applies to the later escalation branch.

In a heating mode, the cooling offset moves one step toward zero only when average outdoor temperature is below `coolingStartTemp - 1` (default 23°C). Some unchanged-minimum-flow branches return early, so this reset is not guaranteed on every pass.

### Cooling adjustment

Cooling uses the same offset-change formula with calculated cooling flow temperature, initially clamping `coolingOffsetCurrent + offsetChange` to the normal offset limits.

It then checks approximate dew point:

- If calculated flow is already below dew point, request current cooling offset +1.
- Otherwise, if `calculatedFlow + 2 * offsetChange` would be below dew point, hold the current offset.

After the dew-point adjustment, the final cooling offset is clamped again to the effective minimum and maximum offsets before deciding whether to PATCH it. At the maximum, the +1 request therefore holds the offset at that limit rather than sending an invalid value such as 11.

The projection uses hard-coded 2 rather than configurable `offsetFactor`. The dew-point branch uses a loose null comparison, so zero dew point is skipped. This is an approximate check, not a complete condensation-control model.

While cooling, the heating offset moves one step toward zero only above `coolingStartTemp + 1` (default 25°C). Minimum heating flow temperature is not changed by the cooling branch.

Only accumulated changes are PATCHed to myUplink: heating offset `47011`, minimum flow temperature `47015`, and/or cooling offset `48739`. An empty change set returns without a write. The heating curve and degree-minute value are never written by this loop.

## Worked mode examples

These use defaults, valid room data, no previous radiator-demand decision, no cold-forecast addition or defrost cap unless stated, and average outdoor temperature below the cooling threshold. Warm-forecast examples reflect the 22 October trial.

| Situation | Decision sequence | Final mode |
| --- | --- | --- |
| Rad 20.5°C, no weather/boost/peak adjustment | Start intermittent; room band raises to at least on. | `on` |
| Rad exactly 20°C, no adjustments | Equality enters the band above 19°C. | `boost` |
| Rad 21.7°C, idle, UFH above 20°C, no adjustments | Room band sets off; no later rule raises it. | `off` |
| Same 21.7°C, but already heating | Room band sets off; cycle preservation raises it. | `intermittent` |
| Rad 20.5°C, inside boost window, no cold/warm forecast | A = +1; room band requests boost; active room compensation skips scheduled promotion. | `boost` |
| Same boost example, but defrost reduction true | Boost is capped after the comfort/boost rules. | `on` |
| Peak window, Rad 20.5°C, UFH 20.3°C, no cold forecast or boost | A = -2; off threshold becomes 19.5°C; idle pump stays off. | `off` |
| Same peak example, UFH 19.4°C | UFH floor overrides the off decision. | `on` |
| Boost window outside peak, Rad 24°C, UFH 20.4°C, default 1°C UFH margin | Radiators remain off; UFH preheat threshold of 20.5°C raises the mode to on. Hive retains its radiator-off target. | `on` |
| Same preheat example, UFH 19.9°C, default X = 1°C | The UFH target is 21°C; a 1.1°C shortfall requests boost, without radiator demand. | `boost` |
| Warm forecast, outside boost and peak windows, Rad 20.8°C, UFH above 20°C, idle | Start nudged off; warm room adjustment is disabled; A = 0; radiator room band raises the mode to on. | `on` |
| Warm forecast, inside a Cosy window outside peak, Rad 20.8°C, UFH above 21°C | Warm room adjustment is disabled; A = +1; radiator room band requests boost; no second scheduled promotion. UFH has no independent demand. | `boost` |
| Boost window, Rad 26°C, UFH 21.2°C, idle pump | Both adjusted room targets are satisfied; active compensation skips scheduled promotion. | `off` |
| Boost window, room compensation disabled or Rad data unavailable, allowed boosts, no warm forecast | Initial intermittent mode is promoted by the fallback scheduled boost. | `boost` |
| Any heating decision, average outdoor temperature 24.1°C | Last rule selects cooling. | `cooling` |

The examples demonstrate code paths rather than results from a physical heat-pump test. `RadiatorHeatDemandTest` exercises the production mode calculation with controlled sensor/window inputs and verifies the resulting persisted radiator demand and Hive targets, including UFH-only demand and threshold adjustments.
