<?php
	namespace App\Services;

	use App\APIs\NibeAPI;
	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Carbon\CarbonImmutable;
	use Illuminate\Support\Facades\Cache;
	use Throwable;

	class HotWaterControl
	{
		public const STATE_KEY = 'hotWaterControl';
		public const COMFORT_PARAMETER = 47041;
		private const LIVE_PARAMETERS = [40004, 40014, 40067, 40071, 40940, 43009, 44270, 44363, 49994];
		private array $state;

		public function __construct(private NibeAPI $nibe)
		{
		}

		public static function handlesControl() : bool
		{
			return ControlSettings::get('nibe.hotWaterControl') || (self::storedState()['managed'] ?? false);
		}

		public function run(array $points, callable $heatingRequired, ?CarbonImmutable $now = null, ?CarbonImmutable $polledAt = null) : void
		{
			// Serialize control writes if scheduler processes overlap.
			Cache::lock('nibe-hot-water-control', 120)->get(function() use ($points, $heatingRequired, $now, $polledAt)
			{
				$this->state = self::storedState() +
				[
					'managed'         => false,
					'mode'            => null,
					'confirmedAt'     => 0,
					'pendingMode'     => null,
					'request'         => null,
					'completed'       => [],
					'retryAfter'      => 0,
					'lastCompletedAt' => 0,
					'reason'          => null,
				];

				$now = ($now ?? CarbonImmutable::now())->setTimezone('Europe/London');
				$this->state['lastEvaluatedAt'] = $now->getTimestamp();

				try
				{
					$this->tick(collect($points)->keyBy('parameterId')->all(), $heatingRequired, $now, $polledAt ?? $now);
				}
				catch (Throwable $e)
				{
					$this->note('Control failed: '.$e->getMessage(), 'error');
				}
				finally
				{
					$this->save();
				}
			});
		}

		private function tick(array $points, callable $heatingRequired, CarbonImmutable $now, CarbonImmutable $polledAt) : void
		{
			$enabled = ControlSettings::get('nibe.hotWaterControl');

			if (!$enabled && !$this->state['managed'])
			{
				return;
			}

			$this->state['managed'] = true;
			$this->state['lastPolledAt'] = $polledAt->getTimestamp();
			$this->state['lastTelemetryAt'] = null;

			// myUplink timestamps describe the last value change, not the last successful poll.
			// Require recent live telemetry for the snapshot, rather than recent changes to every value.
			foreach (self::LIVE_PARAMETERS as $parameter)
			{
				$reading = self::reading($points[$parameter] ?? null, $now);

				if ($reading !== null)
				{
					$this->state['lastTelemetryAt'] = max($this->state['lastTelemetryAt'] ?? 0, $reading['timestamp']);
				}
			}

			$maximumAge = ControlSettings::get('nibe.hotWaterDataMaxAgeMinutes') * 60;
			$pollAge = $now->getTimestamp() - $polledAt->getTimestamp();
			$telemetryAge = $now->getTimestamp() - ($this->state['lastTelemetryAt'] ?? 0);

			if ($pollAge < 0 || $pollAge > $maximumAge || $this->state['lastTelemetryAt'] === null || $telemetryAge > $maximumAge)
			{
				$this->note('Waiting for a recent controller poll with recent live telemetry; unchanged values may have older timestamps.', 'warning');

				return;
			}

			$priority = self::reading($points[49994] ?? null, $now);
			$charging = self::reading($points[40014] ?? null, $now);

			if ($priority === null || !in_array($priority['value'], [10.0, 20.0, 30.0, 60.0], true) || $charging === null || $charging['value'] < -5 || $charging['value'] > 100)
			{
				$this->note('Waiting for valid controller priority and hot water charging values in the current poll.', 'warning');

				return;
			}

			$reported = self::reading($points[self::COMFORT_PARAMETER] ?? null, $now);

			// Ignore readback older than the last write; myUplink may take time to reflect a PATCH.
			if ($reported !== null && in_array($reported['value'], [0.0, 1.0], true) && $reported['timestamp'] > $this->state['confirmedAt'])
			{
				$this->state['mode'] = (int)$reported['value'];
				$this->state['confirmedAt'] = $reported['timestamp'];

				if ($this->state['pendingMode'] === $this->state['mode'])
				{
					$this->state['pendingMode'] = null;
				}
			}

			if ($priority['value'] === 20.0)
			{
				if ($this->state['request'] !== null && $priority['timestamp'] <= ($this->state['request']['afterPrioritySample'] ?? 0))
				{
					$this->note('Waiting for a hot water priority sample newer than the recharge request.');

					return;
				}

				if ($this->state['pendingMode'] !== null)
				{
					// A failed/ambiguous PATCH may have reached the controller. Reconfirm economy after the run.
					$this->state['mode'] = null;
				}

				if ($this->state['request'] === null)
				{
					// Adopt an existing/manual/economy run without consuming a normal opportunity.
					$this->state['request'] = $this->request(null, $now);
					// An external standard request may have superseded our last economy confirmation.
					$this->state['mode'] = null;
				}

				$this->state['request']['startedAt'] ??= $now->getTimestamp();
				$this->state['request']['lastHotWaterSample'] = max($this->state['request']['lastHotWaterSample'] ?? 0, $priority['timestamp']);
				$this->state['pendingMode'] = null;
				$longRun = $now->getTimestamp() - $this->state['request']['startedAt'] > (ControlSettings::get('nibe.hotWaterDurationMinutes') + 60) * 60;
				$this->note($longRun ? 'Hot water is still running beyond its expected duration; leaving comfort mode unchanged.' : 'Hot water production observed; waiting for it to finish.', $longRun ? 'warning' : 'info');

				return;
			}

			$request = $this->state['request'];

			if ($request !== null && $request['startedAt'] !== null)
			{
				if ($priority['timestamp'] <= $request['lastHotWaterSample'])
				{
					$this->note('Waiting for a controller priority reading newer than the observed hot water production.');

					return;
				}

				$this->setMode(0, $now);

				if ($request['opportunity'] !== null)
				{
					[$period, $date] = explode(':', $request['opportunity']);
					$this->state['completed'][$period] = $date;
				}

				$this->state['request'] = null;
				$this->state['lastCompletedAt'] = $now->getTimestamp();
				$this->state['lastCompletedPriorityAt'] = $priority['timestamp'];
				$this->note('Recharge completed; economy mode confirmed.');

				return;
			}

			if ($request !== null)
			{
				$timedOut = $now->getTimestamp() - $request['requestedAt'] >= 30 * 60;
				$retryWouldEnterPeak = $this->state['pendingMode'] === 1 && !$this->canStart($now);

				if (!$enabled || $timedOut || $retryWouldEnterPeak)
				{
					$this->setMode(0, $now);
					$this->state['request'] = null;
					$this->state['retryAfter'] = $now->addMinutes(30)->getTimestamp();
					$this->note($timedOut ? 'Recharge did not start within 30 minutes; economy restored, retry delayed by 30 minutes.' : ($retryWouldEnterPeak ? 'Unconfirmed request cancelled to avoid the tariff peak; economy restored.' : 'Unstarted request cancelled because app-managed hot water was disabled.'), $timedOut ? 'warning' : 'info');
				}
				else
				{
					$this->setMode(1, $now);
					$this->note('Standard mode requested; waiting to observe hot water production.');
				}

				return;
			}

			if (!$enabled)
			{
				$this->setMode(0, $now);
				$this->state['managed'] = false;
				Setting::updateOrCreate(['key' => 'hotWaterSet'], ['value' => 'false']);
				$this->note('App-managed hot water disabled; economy confirmed and control handed back to the original routine. Restore the controller schedules manually.');

				return;
			}

			// A new low charging reading after completion can be a late shower; do not delay its daytime top-up.
			$freshAfternoonTopUp = $this->afternoonTopUp($now) && $charging['timestamp'] > ($this->state['lastCompletedPriorityAt'] ?? $this->state['lastCompletedAt']) && $charging['value'] < $this->afternoonThreshold($now);

			if ($now->getTimestamp() < $this->state['retryAfter'] || ($now->getTimestamp() - $this->state['lastCompletedAt'] < 30 * 60 && !$freshAfternoonTopUp))
			{
				$this->setMode(0, $now);
				$this->note('Waiting for the recharge recovery interval.');

				return;
			}

			[$opportunity, $reason] = $this->opportunity($charging['value'], $now, $heatingRequired);

			if ($opportunity === null)
			{
				$this->setMode(0, $now);
				$this->note($reason);

				return;
			}

			// Persist intent before sending: a crash or an ambiguous response must not lose the request.
			$this->state['request'] = $this->request($opportunity, $now);
			$this->state['request']['afterPrioritySample'] = $priority['timestamp'];
			$this->setMode(1, $now);
			$this->note($reason.' Charging '.$charging['value'].'°C; standard mode requested.');
		}

		private function opportunity(float $temperature, CarbonImmutable $now, callable $heatingRequired) : array
		{
			$minute = $now->hour * 60 + $now->minute;
			$urgent = $temperature < ControlSettings::get('nibe.hotWaterUrgentTemperature');

			if (!$this->canStart($now))
			{
				return [null, 'Deferring new hot water production to avoid the 16:00–19:00 tariff peak.'];
			}

			$overnight = $minute < 7 * 60 || $minute >= 22 * 60;
			$period = $overnight ? 'overnight' : 'afternoon';
			$date = ($minute >= 22 * 60 ? $now->addDay() : $now)->toDateString();
			$key = $period.':'.$date;

			// A completed overnight recharge is never repeated, including after an early shower.
			if ($overnight && ($this->state['completed']['overnight'] ?? null) === $date)
			{
				return [null, ucfirst($period).' recharge opportunity already completed.'];
			}

			if ($urgent)
			{
				return [$key, 'Urgent recharge below '.ControlSettings::get('nibe.hotWaterUrgentTemperature').'°C; peak check passed.'];
			}

			$threshold = $overnight ? ControlSettings::get('nibe.hotWaterOvernightTemperature') : $this->afternoonThreshold($now);

			if ($temperature >= $threshold)
			{
				return [null, 'Hot water is above the '.$period.' start threshold of '.$threshold.'°C.'];
			}

			if ($overnight)
			{
				return [$key, 'Overnight recharge below '.$threshold.'°C; hot water takes priority throughout 22:00–07:00.'];
			}

			if (($this->state['completed']['afternoon'] ?? null) === $date)
			{
				return $this->afternoonTopUp($now) ? [$key, 'Afternoon top-up below '.$threshold.'°C after an earlier recharge; peak check passed.'] : [null, 'Afternoon recharge already completed; additional normal top-ups close at 14:00.'];
			}

			if (!($minute >= 600 && $minute <= 840))
			{
				return [null, 'Waiting for the next hot water start window.'];
			}

			$demand = $heatingRequired($now);
			// Unknown room/forecast demand conservatively reserves the afternoon cheap window for heating.
			$reserveHeating = $demand !== false;
			$allowed = $reserveHeating ? $minute >= 600 && $minute <= 720 : $minute >= 780 && $minute <= 840;

			return $allowed ? [$key, $reserveHeating ? 'Recharging ahead of space heating'.($demand === null ? ' (heating demand unavailable).' : '.') : 'Using a cheap period because no space heating demand is expected.'] : [null, $reserveHeating ? 'Reserving cheap periods for space heating; waiting for the earlier hot water window.' : 'No space heating demand expected; waiting for a cheap hot water window.'];
		}

		private function afternoonTopUp(CarbonImmutable $now) : bool
		{
			return $now->hour >= 7 && $now->hour < 14 && ($this->state['completed']['afternoon'] ?? null) === $now->toDateString();
		}

		private function afternoonThreshold(CarbonImmutable $now) : float
		{
			return ControlSettings::get($now->isSunday() ? 'nibe.hotWaterSundayTemperature' : 'nibe.hotWaterAfternoonTemperature');
		}

		private function canStart(CarbonImmutable $now) : bool
		{
			$peakStart = $now->setTime(16, 0);
			$peakEnd = $now->setTime(19, 0);
			// Use the control interval's minute so a few seconds of scheduler latency do not exclude 13:00.
			$estimatedFinish = $now->startOfMinute()->addMinutes(ControlSettings::get('nibe.hotWaterDurationMinutes'));

			return $now->greaterThanOrEqualTo($peakEnd) || ($now->lessThan($peakStart) && $estimatedFinish->lessThanOrEqualTo($peakStart));
		}

		private function request(?string $opportunity, CarbonImmutable $now) : array
		{
			return
			[
				'opportunity'        => $opportunity,
				'requestedAt'        => $now->getTimestamp(),
				'startedAt'          => null,
				'lastHotWaterSample' => null,
			];
		}

		private function setMode(int $mode, CarbonImmutable $now) : void
		{
			if ($this->state['mode'] === $mode && $this->state['pendingMode'] === null)
			{
				return;
			}

			$this->state['pendingMode'] = $mode;
			$this->save();
			$this->nibe->setParameterData([self::COMFORT_PARAMETER => $mode]);
			$this->state['mode'] = $mode;
			$this->state['confirmedAt'] = $now->getTimestamp();
			$this->state['pendingMode'] = null;
		}

		public static function reading(?array $point, CarbonImmutable $now) : ?array
		{
			if ($point === null || !is_numeric($point['value'] ?? null) || !is_finite((float)$point['value']) || empty($point['timestamp']))
			{
				return null;
			}

			try
			{
				$timestamp = CarbonImmutable::parse($point['timestamp'])->getTimestamp();
				$age = $now->getTimestamp() - $timestamp;

				if ($age < 0)
				{
					return null;
				}

				return ['value' => (float)$point['value'], 'timestamp' => $timestamp];
			}
			catch (Throwable)
			{
				return null;
			}
		}

		private static function storedState() : array
		{
			$state = json_decode(Setting::where('key', self::STATE_KEY)->value('value') ?? 'null', true);

			return is_array($state) ? $state : [];
		}

		private function note(string $reason, string $level = 'info') : void
		{
			if ($this->state['reason'] === $reason)
			{
				return;
			}

			ActivityLog::create(
			[
				'controller' => self::class,
				'method'     => 'run',
				'level'      => $level,
				'message'    => $reason,
			]);

			$this->state['reason'] = $reason;
		}

		private function save() : void
		{
			Setting::updateOrCreate(['key' => self::STATE_KEY], ['value' => json_encode($this->state, JSON_THROW_ON_ERROR)]);
		}
	}
