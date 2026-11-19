# Experimental NIBE compressor staging observations

[Heat pump control](heat-pump-control.md#installation-context) · [Project overview](../README.md)

Recorded on **5 November 2026**, from the owner's experiments and a read-only check of this installation's stored NIBE readings. This refines the earlier approximate -300 DM observation recorded on 20 October. These are installation observations and a working interpretation, not a verified universal NIBE algorithm. The installed controller/outdoor-unit models, firmware versions and relevant controller settings were not established during this check.

## Working interpretation

The installation uses a custom compressor curve. The owner's original explanation was that the higher stages add 30 Hz each. The observed **25 → 55 → 85 Hz** sequence fits that explanation, but the historical follow-up below shows that **neither a universal +30 Hz increment nor a fixed 55 Hz first higher stage explains all the checked cycles**.

The stage transitions supported by the checked readings are:

| Direction and current stage | Approximate DM trigger | Requested change |
| --- | ---: | --- |
| DM falling; running at the curve-derived request | Around -320 | Enter the first higher stage; observed entries include 25 → 55, 35 → 60 and 45 → 65 Hz. |
| DM falling; first higher stage already active | Around -420 | Enter the second higher stage; the complete example cycles below request 85 Hz. |
| DM recovering; second higher stage active after having been below -420 | Around -320 | Return to the first higher stage. |
| DM recovering; first higher stage active after having been below -320 | Around -220 | Return to the curve-derived request. |

One possible explanation is that the first higher stage lies approximately halfway between the curve-derived request and an upper request of 85 Hz: `(baseline + 85) / 2`. This gives 55 Hz from 25, 60 Hz from 35 and 65 Hz from 45. **This is an inference from a few cycles, not a confirmed formula or a verified maximum-frequency setting.** It does not establish what would happen from a 32 Hz baseline, or whether the upper request changes with operating conditions or controller settings.

This description implies approximately **100 DM of hysteresis for each higher stage**: enter/release at -320/-220 for the first stage and -420/-320 for the second. That is an interpretation of the observations. The previous stage and whether DM is falling or recovering matter; a single DM reading does not uniquely identify the stage.

Exact equality, internal rounding, sampling delays, ramp limits, frequency caps and the interaction with a changing curve-derived baseline have not been established. Treat the stated crossings as approximate, rather than relying on strict `<` or `>` behaviour at the exact boundary.

## Corroboration from stored readings

The local catalogue identifies these points:

| Parameter | Stored title | Meaning |
| --- | --- | --- |
| `40940` | `degree minutes` | DM. |
| `40782` | `requested compr. frequency` | Requested compressor frequency, Hz, EB101. |
| `44701` | `current compr. frequency` | Measured/current compressor frequency, Hz, EB101. |
| `49994` | `priority` | Operating priority; 30 denotes space heating. |

A check of 2,640 stored points for those four IDs, received between **2 November 2026 00:00 and 5 November 2026 14:30**, found the following requested-frequency changes on **5 November**. Times below are source timestamps in **Europe/London (GMT)**. The latest reported priority remained 30 through the morning sequence and the afternoon step.

| Requested-frequency change time | Requested frequency | Nearest stored DM sample | Interpretation supported |
| --- | --- | --- | --- |
| 04:04:13 | 25 → 55 Hz | -320 at 04:04:13 | First +30 Hz step near -320 DM. |
| 04:57:13 | 55 → 85 Hz | -420 at 04:56:13, one minute before | Second +30 Hz step near -420 DM. |
| 05:35:16 | 85 → 55 Hz | -314 at 05:35:16 | One -30 Hz step during recovery past roughly -320 DM. |
| 06:32:18 | 55 → 25 Hz | -220 at 06:32:18 | Remaining -30 Hz step near -220 DM. |
| 14:00:41 | 25 → 55 Hz | -318 at 13:58:39; -321 at 14:01:41 | A repeat of the first +30 Hz step, consistent with a crossing near -320 DM. |

These are exact 30 Hz changes in the **requested** value for this 25 Hz baseline. They provide local evidence for all four stage transitions, but do not prove an invariant increment or exact boundary semantics. DM and frequency updates are not simultaneous, and the stored readings are not a continuous trace. The 04:57 DM sample is a minute older than the frequency change; the afternoon DM crossing is bracketed by separate samples.

The measured compressor frequency can lag the request. For example, following the 14:00:41 request for 55 Hz, a current-frequency sample at 14:01:22 was 28 Hz. Use `40782` to examine a controller request and `44701` to examine the physical response; do not treat them as interchangeable.

### Historical follow-up: different curve requests

A further read-only check on **5 November 2026** examined the requested-frequency history, which spans January 2024 onwards, for entries to the higher stages from requests above 25 Hz. Two useful complete morning sequences were found in **March 2026**. These are consecutive stored requested-frequency values at the listed transitions, paired with source-timestamped DM readings; all times below are **Europe/London (GMT)**.

| Date and time | Requested frequency | Nearest stored DM sample | Interpretation |
| --- | --- | --- | --- |
| 14 March 03:13:39 | 40 → 45 Hz | -174 at 03:10:15; -172 at 03:15:15 | Last stored baseline change before escalation. |
| 14 March 04:03:36 | 45 → 65 Hz | -321 at the same time | First higher stage: +20 Hz, not +30 Hz or a fixed 55 Hz. |
| 14 March 04:49:56 | 65 → 85 Hz | -420 at the same time | Second higher stage: another +20 Hz. |
| 14 March 05:35:57 | 85 → 67 Hz | -318 at the same time | Return to the first higher stage. |
| 14 March 06:04:18 | 67 → 51 Hz | -218 at the same time | Return to a baseline that has risen during the cycle. |
| 25 March 02:07:46 | 30 → 35 Hz | -175 at 02:07:17; -173 at 02:12:17 | Last stored baseline change before escalation. |
| 25 March 03:39:59 | 35 → 60 Hz | -312 at 03:35:59; -322 at 03:40:59 | First higher stage: +25 Hz. |
| 25 March 04:25:20 | 60 → 85 Hz | -421 at the same time | Second higher stage: another +25 Hz. |
| 25 March 05:39:44 | 85 → 62 Hz | -318 at the same time | Return to the first higher stage. |
| 25 March 06:20:05 | 62 → 40 Hz | -225 at 06:15:05; -218 at 06:21:05 | Return to a baseline that has risen during the cycle. |

Priority was continuously reported as **30 (space heating)** throughout both sequences: on 14 March from the previous evening until 06:27:02, and throughout the checked 02:00–07:00 interval on 25 March. This avoids confusing a return from hot-water production with a DM-triggered frequency increase.

The upward sequences **45 → 65 → 85** and **35 → 60 → 85**, alongside November's **25 → 55 → 85**, support the midpoint interpretation above. Recovery also shows that the lower requests need not stay fixed throughout a cycle: the March examples return via 67/62 Hz to 51/40 Hz. The exact curve-derived baseline at each higher-stage transition is not separately recorded, so those recovery values cannot establish an exact formula or rounding rule.

The history is sparse and may omit intervening changes; the listed baseline is the last stored request, not an independent measurement of the curve calculation at the transition. Historical controller settings and firmware have not been reconstructed. The evidence therefore supports variable first-stage requests and similar DM entry/release levels, while the proposed midpoint mechanism remains provisional. In particular, a large frequency change during a priority switch or a gap in the DM history is not sufficient evidence for a fixed increment.

## Manufacturer documentation checked

The official [NIBE SMO 40 UK installer manual, IHB EN 1918-6, menu 5.1.23, printed page 47](https://installer.nibe.eu/download/18.69d23679185eaf109d92e24/1676544183750/SMO%2040-IHB-231764-6.pdf#page=47) documents custom compressor curves and temperature-dependent minimum/maximum frequencies for different demands. It supports the general curve-based control concept, but the material reviewed does not verify these particular DM triggers or 30 Hz increments. It is background for a named controller family, not confirmation that the same manual or firmware applies to this installation.

The local telemetry is therefore stronger corroboration of this installation's transitions than the manufacturer material located in this check. The historical examples add evidence from different baselines; model/firmware details, controller frequency settings and further cycles would help establish the underlying frequency rule.

## Relevance to the helper

The checked-in default targets are -180 DM for normal/intermittent operation, -360 for boost, and -450 for extraBoost. Those targets reflect the intention to encourage the normal, first higher and second higher compressor stages respectively; see [mode targets](heat-pump-control.md#modes-and-degree-minute-targets). The app steers DM through flow-temperature settings and does not write the compressor frequency or implement the staging transitions itself.

This is separate from the helper's radiator room-temperature hysteresis for Hive valve control. An ASHP stage can remain active while DM recovers between its entry and release levels, independently of the current radiator valve decision.
