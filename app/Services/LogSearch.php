<?php
	namespace App\Services;

	use App\Models\ActivityLog;
	use App\Models\ApiLog;
	use Carbon\CarbonImmutable;
	use Illuminate\Database\Eloquent\Builder;

	class LogSearch
	{
		public const PAGE_SIZES = [100, 250, 500, 1000];

		public const OPERATORS = ['contains' => 'contains', 'not_contains' => 'does not contain', 'equals' => 'equals', 'not_equals' => 'does not equal'];

		public static function fields(string $type) : array
		{
			return $type === 'activity' ? ['controller' => 'Controller', 'method' => 'Method', 'level' => 'Level', 'message' => 'Message'] : ['method' => 'Method', 'endpoint' => 'Endpoint', 'payload' => 'Payload'];
		}

		public static function criteria(array $input) : array
		{
			$filters = array_values(array_filter($input['filters'] ?? [], fn ($filter) => isset($filter['value']) && $filter['value'] !== ''));

			return
			[
				'filters'  => $filters,
				'from'     => $input['from'] ?? null,
				'to'       => $input['to'] ?? null,
				'per_page' => (int)($input['per_page'] ?? 250),
			];
		}

		public static function query(string $type, array $criteria) : Builder
		{
			$query = $type === 'activity' ? ActivityLog::query() : ApiLog::query();

			foreach ($criteria['filters'] as $filter)
			{
				// Column names and operators are checked before becoming part of SQL.
				abort_unless(array_key_exists($filter['field'], self::fields($type)) && array_key_exists($filter['operator'], self::OPERATORS), 422);
				$column = "LOWER(COALESCE(".$filter['field'].", ''))";
				$value = mb_strtolower($filter['value'], 'UTF-8');

				if (in_array($filter['operator'], ['contains', 'not_contains'], true))
				{
					// Search literal %, _ and ! characters rather than treating them as wildcards.
					$value = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
					$operator = $filter['operator'] === 'contains' ? 'LIKE' : 'NOT LIKE';
					$query->whereRaw($column.' '.$operator." ? ESCAPE '!'", [$value]);
				}
				else
				{
					$query->whereRaw($column.($filter['operator'] === 'equals' ? ' = ?' : ' != ?'), [$value]);
				}
			}

			foreach (['from' => '>=', 'to' => '<'] as $field => $operator)
			{
				if (!empty($criteria[$field]))
				{
					$date = CarbonImmutable::parse($criteria[$field], 'Europe/London')->startOfDay();
					$date = $field === 'to' ? $date->addDay() : $date;
					$query->where('created_at', $operator, $date->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
				}
			}

			return $query->orderByDesc('id');
		}
	}
