# Tariff periods and dated Octopus offers

[Control panel](control-panel.md) · [Heat pump control](heat-pump-control.md) · [Hot water](hot-water-control.md)

Use **Settings → Tariff periods**, below the operating parameters. It has its own **Save tariff periods** button. All times use **Europe/London**, following the summer/winter clock changes. No migration or scheduler restart is needed.

If the deployment caches Laravel configuration, clear or rebuild that cache once when deploying the new defaults file. Subsequent tariff edits are read live from the database.

## Daily tariff and control offsets

| Period | Default actual tariff times |
| --- | --- |
| Morning cheap | 04:00–07:00 |
| Afternoon cheap | 13:00–16:00 |
| Evening cheap | 22:00–00:00 the following day |
| Peak | 16:00–19:00 |

The existing **Weather and boosts** offsets remain separate. Boost offsets (-15/-100 minutes by default) apply to both daily cheap periods and dated cheap offers. Peak-control offsets (0/-25 by default) apply to both daily peaks and dated peak/saving sessions, retaining the default daily 16:00–18:35 heating-control interval. Hot-water admission uses the actual daily and dated tariff boundaries without those offsets.

The **radiator boost hold** uses the full cheap-period finish once radiator temperature demand has activated during an offset-adjusted boost and NIBE is space heating. For example, the default morning boost ends at 05:20 but an armed radiator valve hold ends at 07:00. This does not extend boost temperature adjustments, UFH preheating or heating intensity; it retains the valve demand while space heating continues. UFH-only boosts do not arm it. An actual dated cheap offer can retain its hold through the full offer inside the normal daily peak; dated peak controls still take precedence. See [radiator demand](heat-pump-control.md#3-apply-radiator-zone-load-compensation).

Saving tariff periods refreshes the Cosy entries of an existing `agile_schedule` immediately, including a currently running boost window where applicable. It preserves the calculated cheapest-three/six-hour entries and their costs. Normal nightly generation uses the same timing service. If no schedule has been provisioned yet, regular boost selection still waits for a successful initial generation; dated offers work independently of that stored schedule.

Daily periods must not overlap. A finish earlier than the start means the next day; equal times are rejected. Existing offsets must leave each daily control window with a positive duration shorter than a day. Offset edits on the main operating-parameter form are also checked against the saved daily times and upcoming dated windows. Offsets that eliminate a dated heating window are rejected; expired short offers do not block unrelated edits. Editing boost offsets alone retains the existing next-generation behaviour for stored daily Cosy entries, while dated heating windows read the offsets live. Use the tariff form to refresh Cosy entries immediately.

Recurring clock-time validation uses 15 January 2026 as an arbitrary winter anchor, away from clock changes. That date has no tariff or control significance: actual dated offers and live periods always use their own dates.

## Dated extra windows

Choose **Add dated window**, enter a name, type, start and finish, then save. Both dates and times are required, so a temporary offer cannot accidentally repeat every day. Actual tariff windows and their adjusted heating windows include their start and exclude their finish. Heating eligibility stops at the adjusted finish, which may be before or after the entered tariff finish. Expired rows can remain for reference or be removed later; no cleanup job is needed for them to stop applying.

- **Cheap offer:** enables boost/preheat target adjustments and overrides the ordinary daily heating peak during its offset-adjusted interval. For example, a 16:30–18:30 offer gives a 16:15–16:50 heating window with default -15/-100 offsets. The actual 16:30–18:30 interval is still used for hot-water admission and a first recharge outside its normal quiet window when no space-heating demand is expected.
- **Peak / saving session:** applies radiator/UFH peak controls and suppresses scheduled boost/preheating during its offset-adjusted interval. A 10:00–12:00 session gives a 10:00–11:35 heating-control window with default 0/-25 offsets. New hot-water starts and retries must avoid the full actual 10:00–12:00 session. A running recharge continues normally.

Dated actual tariff windows cannot overlap each other. If their heating offsets create an overlap, dated peak controls win over dated cheap controls regardless of row order. Dated heating controls take precedence over daily heating controls. Hot water always uses the actual intervals: a cheap offer from 16:00 to 18:00 replaces only that part of a daily 16:00–19:00 peak, so a proposed recharge finishing after 18:00 must still avoid the remaining peak. These controls retain the existing room-temperature protection and do not promise that all electricity consumption stops during a saving session.

The hot-water heating-demand prediction samples the offset-adjusted daily and dated cheap heating windows, evaluating the same boost/peak decisions as live heating. This includes preheat time before an offer's actual start. Its horizon ends at the next overnight hot-water start during the day, or at the overnight finish during the night. It uses current room readings, without predicting their future cooling.

## Other editable timing preferences

**Hot water start windows** retain the overnight 22:00–07:00 preference, which must cross midnight. Early and quiet now specify only earliest starts: 10:00 when heating needs the cheap period, or 13:00 otherwise. Both remain open continuously until **daily peak start minus Recharge duration allowance**, inclusive. With a 16:00 peak, 120 minutes gives a 14:00 latest start; 180 minutes gives 13:00. There is no fixed 12:00 finish or one-hour gap. Repeat normal daytime top-ups can start from the overnight finish through the same calculated cutoff when heating demand is expected or unknown; with no heating expected they wait until the quiet afternoon start, unless urgent. Peak and duration edits change the cutoff live. The two earliest starts remain separately editable preferences. Saving sessions can block earlier starts, and dated cheap offers retain their additional first-recharge opportunities.

The Settings page displays the calculated latest daytime start using the saved peak time and recharge allowance. Old saved `hot_water.early.end` and `hot_water.quiet.end` values are ignored on read and disappear from the record on its next save; no database migration or scheduler restart is needed. The first-recharge earliest starts and overnight window are preserved.

**Post-peak catch-up** runs from the actual daily peak finish until the configured overnight hot-water start, default 19:00–22:00. It uses the normal weekday/Sunday afternoon threshold when space-heating demand is expected or unknown, including after an earlier afternoon recharge. The charging temperature must have stayed below the threshold since before peak ended. A fresh drop after peak waits for the overnight start and any evening heating reservation. With no heating expected, normal recharges wait until the overnight start (default 22:00), when the overnight threshold applies; urgent recharges can start earlier. This allows a temperature drop after the pre-peak cutoff to request hot water after peak when the cheap period is needed for heating. It uses the saved tariff finish directly, without heating peak offsets; saving sessions can still defer a start whose estimated run would overlap them. Runs already in progress continue through the overnight boundary.

**Agile rate-search windows** default to overnight 19:00–07:00, daytime 10:00–16:00, expensive morning 06:00–12:00 and peak 16:00–19:00. Overnight starts on the fetch date; daytime, expensive morning and peak start the following day. The first two must allow at least six hours within the fetch-to-next-day horizon, the expensive morning search at least five hours in its configured range, and peak at least half an hour. Expensive morning and peak use their full configured ranges, including times more than 24 hours after the fetch. Older saved settings acquire the peak default automatically, without a migration. These ranges use London clock time throughout, including during BST. They affect the next analysis/email, not the actual Cosy tariff. The **Daily rates fetch from** preference defaults to 16:30; the existing success latch and every-15-minute command cadence are retained.

The rates email includes the returned peak half-hour prices and the most expensive windows that fit. A three-hour peak has no four- or five-hour result; these are shown as unavailable. Missing or non-consecutive rates do not create invalid windows or cause missing-index errors. If required cheapest control windows are missing, the previous schedule is retained and the next scheduled fetch retries with a descriptive error log.

## Storage and audit

`TariffPeriods` reads JSON from `settings.octopusTariffPeriods` on each operation, falling back to `config/octopus-periods.php` until the first save. The record contains `daily`, `hot_water`, `agile`, `fetch` and `extra`. This is separate from the numeric/boolean `control.*` catalogue and from the generated `agile_schedule`.

Newly generated schedule entries include `tariff_end` alongside the offset-adjusted `start` and `end`, so the radiator hold retains the actual cheap finish even if an offset setting changes later. Existing schedule entries remain usable: until regeneration, the hold reconstructs their actual finish using the current end offset. Dated offers use their explicitly saved finish. The active radiator hold stores its own expiry in `settings.radiatorHeatDemand`; tariff edits do not rewrite an already-armed expiry, although peak controls can cancel it.

Tariff edits are audited in Activity Logs, require an authenticated operator and use a revision check to reject stale form submissions. Saving sends no equipment commands or emails. The original controller-managed hot-water schedules and the legacy schedule-2 routine are not rewritten by this editor.
