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

The existing **Weather and boosts** offsets remain separate. Boost offsets (-15/-100 minutes by default) apply to the daily cheap periods when constructing Cosy boost windows. Peak-control offsets (0/-25 by default) apply to the daily peak, retaining the default 16:00–18:35 heating-control interval. Hot-water peak admission uses the actual daily tariff boundaries without those offsets.

Saving tariff periods refreshes the Cosy entries of an existing `agile_schedule` immediately, including a currently running boost window where applicable. It preserves the calculated cheapest-three/six-hour entries and their costs. Normal nightly generation uses the same timing service. If no schedule has been provisioned yet, regular boost selection still waits for a successful initial generation; dated offers work independently of that stored schedule.

Daily periods must not overlap. A finish earlier than the start means the next day; equal times are rejected. Existing offsets must leave each control window with a positive duration shorter than a day. Offset edits on the main operating-parameter form are also checked against the saved tariff times. Editing boost offsets alone retains the existing next-generation behaviour; use the tariff form to refresh Cosy entries immediately.

## Dated extra windows

Choose **Add dated window**, enter a name, type, start and finish, then save. Both dates and times are required, so a temporary offer cannot accidentally repeat every day. Each window includes its start and excludes its finish. Expired rows can remain for reference or be removed later; no cleanup job is needed for them to stop applying.

- **Cheap offer:** enables the existing boost/preheat target adjustments during its exact interval. It also overrides the ordinary daily peak during that interval. The regular boost offsets are not applied. A first app-managed hot-water recharge may use an extra cheap offer outside its normal quiet window if no space-heating demand is expected and the full peak-admission check passes.
- **Peak / saving session:** applies the existing radiator/UFH peak controls and suppresses scheduled boost/preheating during its exact interval. A new app-managed hot-water request or retry is deferred if its expected duration overlaps the session. A running recharge continues normally.

Dated windows cannot overlap each other. They take precedence over the daily tariff. For example, a cheap offer from 16:00 to 18:00 replaces only that part of a daily 16:00–19:00 peak: a proposed hot-water run finishing after 18:00 must still avoid the remaining peak. These controls retain the existing room-temperature protection and do not promise that all electricity consumption stops during a saving session.

The hot-water heating-demand prediction samples the configured cheap periods and dated cheap offers, evaluating the same boost/peak decisions as live heating. Its horizon ends at the next overnight hot-water start during the day, or at the overnight finish during the night. It uses current room readings, without predicting their future cooling.

## Other editable timing preferences

**Hot water start windows** are independent preferences: overnight 22:00–07:00; early 10:00–12:00 when heating needs the cheap period; quiet 13:00–14:00 otherwise. Defaults preserve the existing behaviour. Overnight must cross midnight; daytime windows must finish later on the same day. Repeat normal daytime top-ups start from the overnight finish and close at the quiet finish. Changing the tariff alone does not silently move these preferences; adjust them alongside it where wanted.

**Agile rate-search windows** default to overnight 19:00–07:00, daytime 10:00–16:00 and expensive 06:00–12:00. Overnight starts on the fetch date; daytime and expensive start the following day. The first two must allow at least six hours, and the expensive search at least five hours, for the existing analysis. These ranges now use London clock time throughout, including during BST. They affect the next analysis/email, not the actual Cosy tariff. The **Daily rates fetch from** preference defaults to 16:30; the existing success latch and every-15-minute command cadence are retained.

## Storage and audit

`TariffPeriods` reads JSON from `settings.octopusTariffPeriods` on each operation, falling back to `config/octopus-periods.php` until the first save. The record contains `daily`, `hot_water`, `agile`, `fetch` and `extra`. This is separate from the numeric/boolean `control.*` catalogue and from the generated `agile_schedule`.

Tariff edits are audited in Activity Logs, require an authenticated operator and use a revision check to reject stale form submissions. Saving sends no equipment commands or emails. The original controller-managed hot-water schedules and the legacy schedule-2 routine are not rewritten by this editor.
