# App-managed hot water

The optional **Settings → Hot water → App-managed hot water** switch defaults to off. The original schedule-2 routine remains selected until it is enabled. Both paths run every five minutes, independently of the automatic space heating switch.

## Enable for a trial

1. Wait until no hot water recharge is running.
2. On the ASHP controller, disable both hot water schedules and select economy as the ordinary hot water comfort mode. Keep the existing economy and standard start/stop temperatures and periodic temperature-increase configuration.
3. Review the new Hot water settings, then enable App-managed hot water and save.
4. Check Activity Logs for `App\Services\HotWaterControl`. The first decision either requests standard or confirms the economy baseline. Saving the form itself does not send a controller command.

The app uses PATCH parameter **47041**, economy **0**, standard/normal **1**. A write is recorded as successful only after the API returns `{"47041":"modified"}`. A fresh controller readback, when supplied, can also reconcile the current mode. The app does not edit weekly schedules in this mode.

## Start decisions

All clock times use Europe/London, including summer time. Threshold comparisons are strictly below the configured value.

| Opportunity | Default threshold | Heating needed now or during the relevant cheap period | No heating demand expected |
| --- | --- | --- | --- |
| Overnight | 37°C | Check throughout 22:00–07:00 | Check throughout 22:00–07:00 |
| First afternoon recharge, Monday–Saturday | 33°C | Check 10:00–12:00 inclusive | Check 13:00–14:00 inclusive, subject to peak admission |
| First afternoon recharge, Sunday | 37°C | Same afternoon window | Same afternoon window |
| Repeat daytime top-ups after an afternoon recharge completes | 33°C Monday–Saturday; 37°C Sunday | Any daytime check before 14:00, subject to peak admission | Any daytime check before 14:00, subject to peak admission |

The overnight start window includes 22:00 and ends at 07:00 exclusive. At the normal five-minute cadence, 06:55 is the last start check. Space heating demand and the 22:00–00:00 or 04:00–07:00 cheap periods do not delay an overnight recharge. A run started before 07:00 always continues to completion afterwards.

An overnight opportunity belongs to the date of its morning: Saturday at 22:00 and Sunday before 07:00 share Sunday's opportunity, including both occurrences of 01:00 when the clocks go back. A completed overnight recharge is not repeated, even if a 06:45 shower drops the temperature below the urgent threshold. The next overnight opportunity opens at 22:00 for the following morning. An unsuccessful request does not consume an opportunity.

For the first afternoon recharge, heating priority 30 immediately reserves the cheap period. Otherwise, prediction evaluates current demand and the afternoon cheap period using the existing radiator hysteresis, cold-forecast additions, boost adjustments, UFH preheat targets, and uncompensated heating fallback. It uses the currently available room temperatures rather than forecasting their cooling over the intervening hours. Missing prediction data conservatively reserves the cheap period; it does not prevent an early afternoon hot water opportunity. Overnight decisions do not call the heating prediction.

After an afternoon recharge completes, another low temperature can request another top-up before 14:00. This applies on every day, using the applicable afternoon threshold, and can happen more than once. These repeat starts do not depend on heating demand or the first recharge's preferred window: for example, a 12:30 drop after a 10:00 recharge may start a top-up even while heating is needed. Repeat normal starts close at 14:00 exclusive, so 13:55 is normally the last five-minute check. Peak admission still applies. Completion dates remain recorded for logging and recognising repeat top-ups; the afternoon completion date no longer imposes a one-recharge limit.

Shared `HeatingDemand` and `HeatingBoostSchedule` calculations keep the live target rules and prediction aligned. Prediction does not update `htgMode`, `allowLoadCompensation`, the stored radiator decision, or heating settings.

## Urgent requests and peak admission

Below **20°C**, a recharge may be requested outside the normal windows, including an extra daytime recharge after an afternoon opportunity was used. The completed overnight opportunity still prevents a second recharge within the same 22:00–07:00 window. A 30-minute recovery interval after a completed recharge prevents immediate repeated requests from delayed temperature readings. Before 14:00, a repeat daytime top-up bypasses that interval if its below-threshold charging value has a source change timestamp newer than the controller's completion priority timestamp. This handles late showers, including a shower between the controller finishing and the app observing completion. Older state records fall back to the app's completion observation time. Failed-start retry delays are retained.

Every new request and retry of an unconfirmed start must fit before the actual **16:00–19:00 tariff peak**, using **Recharge duration allowance**, default **180 minutes**. This check is independent of the space heating peak offsets. With 180 minutes, 13:00 is the latest admitted afternoon start; with 120 minutes, 14:00 is possible for a first afternoon or urgent recharge. Normal repeats still close before 14:00. The current control minute is used, allowing ordinary seconds of scheduler latency at the boundary.

Requests which cannot fit are deferred. At 19:00, the next control check reassesses an urgent need using the current temperature. A running recharge always continues, even into a heating dip or the tariff peak. The allowance reduces the risk of peak operation; it is not a guarantee that an unusually long run will finish beforehand.

## Recharge lifecycle and recovery

State persists as JSON in `settings.hotWaterControl`: management ownership, last confirmed mode/time, pending write, request/start observations, completed opportunity dates, recovery time and last decision reason. `lastCompletedPriorityAt` records the source timestamp of the controller priority change used to confirm completion. `lastEvaluatedAt`, `lastPolledAt` and `lastTelemetryAt` record Unix seconds for the latest evaluation, actual API response and most recent live telemetry change. The evaluation timestamp advances even if the decision is unchanged, so the row's `updated_at` also shows continuing evaluations. A cache lock serializes overlapping control runs.

- Require valid numeric priority **49994** and charging temperature **40014** in a recent successful controller poll. myUplink timestamps can describe the last change to a value: an unchanged priority or temperature can legitimately have an old timestamp. The age check therefore applies to the actual poll and the latest change among known live telemetry points (outdoor/flow temperatures, degree minutes, evaporator temperature, priority and charging), rather than to every individual value. Both ages must be within the configured limit, default ten minutes. A recent comfort-setting change alone does not establish live telemetry. The republished priority heartbeat is never used as evidence of fresh controller data.
- Save the request and write intent before sending standard. A successful PATCH does not mean production has started.
- Wait for a priority-20 sample newer than the priority sample used to request the recharge.
- While priority is 20, never send an economy PATCH. This also protects an existing manual, periodic or rare economy recharge when the app begins observing it.
- Once production has been observed, wait for a newer non-20 priority sample, confirm economy, then mark an app-requested opportunity completed. An adopted run does not consume a normal opportunity.
- Retry failed writes on a subsequent control check without recording a false success. An ambiguous start response followed by production still requires economy confirmation afterwards.
- If an unstarted request reaches 30 minutes, restore economy and delay retries for 30 minutes. A run exceeding the configured duration plus an hour produces a warning and continues. Invalid or stale readings cause no mode writes.

Completion assumes the configured controller gives hot water exclusive priority until the recharge finishes. If this controller behaviour changes to alternating heating and hot water, completion detection must be revisited.

Activity logs record changes in decision reason, starts, completion, restoration, timeouts and errors. Identical waiting decisions are not logged every five minutes. API logs retain the underlying HTTP exchanges.

The start threshold is strictly below the configured temperature. In the first overnight trial on 25 October 2026, the API reported 37.0°C from 00:12 UTC (01:12 BST); 36.5°C first arrived at 02:02 UTC (02:02 GMT), after the original 02:00 start cutoff. A chart joining those sparse readings can suggest an earlier threshold crossing. The threshold remains strictly below 37°C by default, but the overnight start window was subsequently extended to 22:00–07:00. Replaying those readings now requests a recharge at the 02:05 check, provided the overnight opportunity has not already completed.

## Return to the original control

Prefer to change over while idle. Turn App-managed hot water off and save. If a recharge is running, the new service retains ownership until production finishes and economy is confirmed. An unstarted request is cancelled. After cleanup, it clears the old `hotWaterSet` flag and hands back to the original routine on a subsequent check. Failed cleanup or stale readings keep the handover pending.

Confirm the handover message in Activity Logs, then manually restore the original controller schedule times and comfort settings, including the original Sunday setting. The app switch does not restore or disable controller schedules.

No database migration, scheduler restart, or change to the installation's saved operating settings is needed to deploy the code. The new controls remain off until enabled through Settings.
