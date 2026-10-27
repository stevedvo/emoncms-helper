<?php
	namespace App\Services;

	use App\Models\ActivityLog;
	use App\Models\Setting;
	use Illuminate\Support\Facades\DB;
	use Illuminate\Validation\ValidationException;
	use InvalidArgumentException;

	class ControlSettings
	{
		public const PREFIX = 'control.';

		public static function definitions(): array
		{
			return config('control-parameters');
		}

		// Deliberately no process-lifetime cache: saved edits are visible on the next read.
		public static function get(string $key): bool|int|float
		{
			$definition = self::definitions()[$key] ?? throw new InvalidArgumentException('Unknown control parameter: '.$key);
			$stored = Setting::where('key', self::PREFIX.$key)->value('value');

			return self::cast($stored === null ? config($key, $definition['default']) : json_decode($stored, true, 512, JSON_THROW_ON_ERROR), $definition);
		}

		public static function values(bool $lock = false): array
		{
			$definitions = self::definitions();
			$query = Setting::whereIn('key', array_map(fn ($key) => self::PREFIX.$key, array_keys($definitions)));
			$stored = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('key');
			$values = [];

			foreach ($definitions as $key => $definition)
			{
				$row = $stored->get(self::PREFIX.$key);
				$values[$key] = self::cast($row ? json_decode($row->value, true, 512, JSON_THROW_ON_ERROR) : config($key, $definition['default']), $definition);
			}

			return $values;
		}

		public static function initialize(): int
		{
			return DB::transaction(function()
			{
				$created = 0;

				foreach (self::definitions() as $key => $definition)
				{
					if (Setting::where('key', self::PREFIX.$key)->exists())
					{
						continue;
					}

					$value = self::cast(config($key, $definition['default']), $definition);
					$row = Setting::firstOrCreate(['key' => self::PREFIX.$key], ['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
					$created += (int) $row->wasRecentlyCreated;
				}

				return $created;
			});
		}

		public static function revision(array $values): string
		{
			return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
		}

		public static function field(string $key): string
		{
			return str_replace('.', '__', $key);
		}

		public static function save(array $values, string $revision, int $userId): int
		{
			return DB::transaction(function() use ($values, $revision, $userId)
			{
				$current = self::values(true);

				if (!hash_equals(self::revision($current), $revision))
				{
					throw ValidationException::withMessages(['revision' => 'Settings changed in another session. Reload this page before saving again.']);
				}

				$changed = 0;

				foreach ($values as $key => $value)
				{
					$definition = self::definitions()[$key] ?? throw new InvalidArgumentException('Unknown control parameter: '.$key);
					$value = self::cast($value, $definition);

					if ($current[$key] === $value)
					{
						continue;
					}

					Setting::updateOrCreate(['key' => self::PREFIX.$key], ['value' => json_encode($value, JSON_THROW_ON_ERROR)]);

					ActivityLog::create(
					[
						'controller' => self::class, 'method' => 'save', 'level' => 'info',
						'message'    => 'User #'.$userId.' changed '.$definition['label'].' from '.json_encode($current[$key]).' to '.json_encode($value).'.',
					]);

					$changed++;
				}

				return $changed;
			});
		}

		private static function cast(mixed $value, array $definition): bool|int|float
		{
			if ($definition['type'] === 'boolean')
			{
				$boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

				if ($boolean === null || $value === null)
				{
					throw new InvalidArgumentException('Invalid boolean control value.');
				}

				return $boolean;
			}

			if (!is_numeric($value) || !is_finite((float)$value))
			{
				throw new InvalidArgumentException('Invalid numeric control value.');
			}

			return $definition['type'] === 'integer' ? (int)$value : (float)$value;
		}
	}
