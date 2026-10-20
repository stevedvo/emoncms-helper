# Heat pump control

[Project overview](../README.md) · [Integrations](integrations-and-data.md) · [Configuration](configuration-and-operation.md) · [Implementation notes](implementation-notes.md)

This guide describes the active code in [NibeController.php](../app/Http/Controllers/NibeController.php). All defaults are the checked-in values, not a claim about the live installation. Operating values are now read through `ControlSettings::get()` from the database and can be edited on the authenticated [Settings page](control-panel.md).

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

### 2. Determine the boost window and peak window

`isBoostActive()` evaluates the stored `agile_schedule`. With forecasts enabled, all currently active outdoor-temperature branches choose `cosy`; a warm forecast suppresses boosting entirely. Without forecasts, outdoor temperatures choose between the stored cheapest-three-hour, cheapest-six-hour, or continuous boost cases. See [boost schedule selection](#boost-schedule-selection).

`isPeakImport()` uses **14:40 through 18:35 inclusive, Europe/London**, calculated as 16:00 minus 80 minutes to 19:00 minus 25 minutes. This is a fixed clock window, not a lookup of the day's actual prices.

### 3. Apply radiator-zone load compensation

Read `Rad_temperature` from local Emoncms, searching back `roomTemperatureLookbackMinutes` (default 180 minutes) and taking the last non-null value, rounded to one decimal place. A usable reading is stored as `RoomTemperature[Rad_temperature]` and sets `allowLoadCompensation` to the string `true`. If unavailable, the saved temperature is used and `allowLoadCompensation` becomes `false`.

The room-temperature bands apply only when load compensation is enabled and that setting is exactly the string `true`. The fallback value therefore does not enable the bands on its own. A first-ever failure with no saved temperature returns null and disables load compensation for that calculation.

Every band boundary is shifted by the same adjustment `A`:

| Condition | Addition to A |
| --- | ---: |
| In the peak window | -2°C |
| Warm forecast | -1°C |
| Boost window active, provided there is no warm forecast | +1°C |
| Low-six forecast average < `dmTargetBoostTemp` (default 3°C), provided there is no warm forecast | +2°C |
| Otherwise low-six forecast average < hard-coded 3°C, provided there is no warm forecast | +1°C |

With the defaults, the +1°C cold-forecast branch cannot be reached: both cold comparisons use 3°C, so the +2°C branch wins. Changing `dmTargetBoostTemp` below 3°C makes a +1°C band possible.

The boost-window +1°C adjustment is based on `boostActive` even if `allowBoosts` is false. That flag controls the later explicit mode promotion, not the room-band adjustment.

The radiator temperature is tested against these bands in descending order:

| Radiator temperature T | Result at this stage, with default thresholds |
| --- | --- |
| T > 21.5 + A | Set `off`. |
| 21 + A < T <= 21.5 + A | Ensure at least `intermittent`. |
| 20 + A < T <= 21 + A | Ensure at least `on`. |
| 19 + A < T <= 20 + A | Ensure at least `boost`. |
| T <= 19 + A | Set `extraBoost`. |

Equality falls into the colder, more demanding band. A positive adjustment requests warmer rooms; a negative adjustment permits cooler rooms. There is no separate thermostat deadband or mode-persistence timer in this calculation.

At this stage, the controller separately captures whether `T <= loadCompTempOff + A`: true means the radiator zone warrants at least intermittent heating, false means it is warm enough to remain off. Later boost promotion, cycle preservation, UFH demand, and peak minimum-mode rules can raise the overall heat-pump mode without changing this radiator decision. If the room bands cannot be evaluated, radiator demand is null (unknown).

### 4. Promote the mode during an allowed boost

When `allowBoosts !== false` and the boost window is active:

| Mode before promotion | Mode after promotion |
| --- | --- |
| `off` | `intermittent` |
| `intermittent` | `boost` |
| `on` | `boost` |
| `boost` | `extraBoost` |
| `extraBoost` | `extraBoost` |

This happens after the room bands, so a boost window can affect both the thresholds and the resulting mode.

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

All four parameters are editable under **Room temperatures** in Settings and are read on every calculation. The preheat margin accepts 0–3°C; setting it to zero removes the extra preheat allowance in every window. **UFH: boost shortfall** accepts 0.1–5°C. Neither setting disables the separate scheduled mode promotion in step 4. Like radiator room-band demand, UFH preheating and shortfall-based boost operate even when `allowBoosts` is false, because that flag controls step 4's scheduled promotion rather than room-temperature demand.

UFH does **not** copy the radiator's negative peak/warm-forecast adjustments or its cold-forecast temperature additions. Neither a peak period nor a warm forecast lowers the UFH early-start protection. A peak period always suppresses the extra UFH preheat margin, even if a boost window overlaps it. This does not veto a higher mode requested by another rule, such as radiator demand, scheduled mode promotion, cycle preservation or the cold-forecast peak minimum.

Preheating uses the existing boost-window decision, including its weather/schedule handling: a warm forecast that makes `isBoostActive()` return false also disables UFH preheating. These windows are the app's existing timing signals, not a new live-price comparison. The aim is to reduce the likelihood of needing peak-period heating; the comfort thresholds still permit it when needed, and no room-temperature outcome is guaranteed by the software alone.

This read uses `updateSettings = false`; it neither falls back to saved UFH temperature nor changes `allowLoadCompensation`. Missing UFH data skips this step. UFH raises the overall mode to at least `intermittent`, `on` or `boost`, preserving higher radiator/boost demand and the independent radiator valve decision. Log messages report the measured UFH temperature, effective threshold and preheat margin; shortfall-based boost also records the target, shortfall and configured X. Later defrost/cooling rules retain their existing precedence.

### 7. Apply the remaining peak-period rule

During the peak window, if the low-six forecast average is below `dmTargetBoostTemp`, ensure at least `intermittent`. The other peak branches only log: their downward mode nudges are commented out. The active peak reduction mainly comes from the -2°C room-band adjustment in step 3.

### 8. Cap demand when reducing defrosts

`isReducingDefrosts()` finds the minimum numeric evaporator reading (`44363`) among database rows **created in the last 60 minutes**. It returns true if either:

- The minimum is below `defrostEvaporatorMax - 5` (default: below -20°C), regardless of dew point; or
- Dew point is strictly between `defrostDewpointMin` and `defrostDewpointMax` (defaults -3°C and 5°C), and the minimum is below `defrostEvaporatorMax` (default -15°C).

True caps `boost` or `extraBoost` at `on`; lower modes stay unchanged. Missing evaporator readings, unavailable dew point for the second condition, or an exception return false. This is a heuristic based on temperatures, not a defrost event counter.

### 9. Override everything with cooling when warm enough

If NIBE's average outdoor temperature is strictly above `coolingStartTemp` (default 24°C), return `cooling`. This final rule overrides all heating decisions, including the defrost cap. Forecast-based pre-emptive cooling is commented out.

At the end of a successful mode calculation, `RadiatorHeatDemand::record()` stores the separate radiator decision and calculation timestamp in `settings.radiatorHeatDemand`. This happens on every calculation, including unchanged or unknown decisions, before the offset calculation or any possible early return in that later work. Hive uses a fresh decision to gate heating of the radiator zone; see [Home Assistant / Hive](integrations-and-data.md#home-assistant--hive).

The overall result is logged and saved as `settings.htgMode` before calculating and sending temperature changes. A later write failure can leave that setting describing a mode whose requested settings were not applied.

## Boost schedule selection

With forecasts enabled:

1. Read and decode `settings.agile_schedule`.
2. Require a non-null weighted forecast temperature; otherwise log an error and return false.
3. Choose `cosy` in every active outdoor-temperature branch.
4. Return false if the high-six forecast average exceeds `runLevel1Temp`.
5. Return true only strictly inside one of the stored Cosy start/end pairs.

The dormant `constant` schedule branch is unreachable from the current forecast-enabled assignments. Exact schedule start and end instants are excluded. Persisted schedule dates matter; these are timestamped windows, not a daily repeating clock rule.

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

The projection uses hard-coded 2 rather than configurable `offsetFactor`. The dew-point branch uses a loose null comparison, so zero dew point is skipped. Its +1 override is not clamped again. This is an approximate check, not a complete condensation-control model.

While cooling, the heating offset moves one step toward zero only above `coolingStartTemp + 1` (default 25°C). Minimum heating flow temperature is not changed by the cooling branch.

Only accumulated changes are PATCHed to myUplink: heating offset `47011`, minimum flow temperature `47015`, and/or cooling offset `48739`. An empty change set returns without a write. The heating curve and degree-minute value are never written by this loop.

## Worked mode examples

These use defaults, valid room data, no defrost cap unless stated, and average outdoor temperature below the cooling threshold.

| Situation | Decision sequence | Final mode |
| --- | --- | --- |
| Rad 20.5°C, no weather/boost/peak adjustment | Start intermittent; room band raises to at least on. | `on` |
| Rad exactly 20°C, no adjustments | Equality enters the band above 19°C. | `boost` |
| Rad 21.7°C, idle, UFH above 20°C, no adjustments | Room band sets off; no later rule raises it. | `off` |
| Same 21.7°C, but already heating | Room band sets off; cycle preservation raises it. | `intermittent` |
| Rad 20.5°C, inside boost window, allowed boosts, no cold/warm forecast | A = +1; room band requests boost; explicit boost promotion raises again. | `extraBoost` |
| Same boost example, but defrost reduction true | ExtraBoost is capped after the comfort/boost rules. | `on` |
| Peak window, Rad 20.5°C, UFH 20.3°C, no cold forecast or boost | A = -2; off threshold becomes 19.5°C; idle pump stays off. | `off` |
| Same peak example, UFH 19.4°C | UFH floor overrides the off decision. | `on` |
| Boost window outside peak, Rad 24°C, UFH 20.4°C, default 1°C UFH margin | Radiators remain off; scheduled promotion allows intermittent; UFH preheat threshold of 20.5°C raises the mode to on. Hive retains its radiator-off target. | `on` |
| Same preheat example, UFH 19.9°C, default X = 1°C | The UFH target is 21°C; a 1.1°C shortfall requests boost, without radiator demand. | `boost` |
| Warm forecast, Rad 20.8°C, UFH above 20°C, idle | Start nudged off; A = -1; off threshold is 20.5°C; boosts suppressed. | `off` |
| Any heating decision, average outdoor temperature 24.1°C | Last rule selects cooling. | `cooling` |

The examples demonstrate code paths rather than results from a physical heat-pump test. `RadiatorHeatDemandTest` exercises the production mode calculation with controlled sensor/window inputs and verifies the resulting persisted radiator demand and Hive targets, including UFH-only demand and threshold adjustments.
