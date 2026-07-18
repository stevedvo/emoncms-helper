<?php
	namespace App\Services;

	use DateTimeInterface;
	use Illuminate\Support\Collection;

	class ActivityLogSummarizer
	{
		/**
		 * Group activity logs into concise error types for the administrator report.
		 */
		public function summarize(Collection $activityLogs) : Collection
		{
			return $activityLogs->groupBy(fn($log) => $this->groupKey((string) $log->message))->map(function(Collection $logs) : array
			{
				$orderedLogs = $logs->sortBy(fn($log) => $this->timestamp($log->created_at));
				$firstLog    = $orderedLogs->first();
				$lastLog     = $orderedLogs->last();

				return
				[
					'type'       => $this->errorType((string) $firstLog->message),
					'count'      => $logs->count(),
					'sources'    => $this->sources($logs),
					'first_seen' => $this->timestamp($firstLog->created_at),
					'last_seen'  => $this->timestamp($lastLog->created_at),
				];
			})->sortByDesc('count')->values();
		}

		/**
		 * Return a stable key so variable details do not split known error types.
		 */
		protected function groupKey(string $message) : string
		{
			$type = $this->errorType($message);

			if ($type !== $this->normaliseMessage($message))
			{
				return strtolower($type);
			}

			return strtolower($this->normaliseMessage($message));
		}

		/**
		 * Categorise known errors. Add more specific rules above the cURL fallback.
		 */
		protected function errorType(string $message) : string
		{
			if (preg_match('/\bport\s+(\d{1,5})\b/i', $message, $matches) === 1)
			{
				return "Port ".$matches[1]." errors";
			}

			if (stripos($message, "Could not resolve host") !== false)
			{
				return "DNS resolution errors";
			}

			if (stripos($message, "Connection was reset") !== false)
			{
				return "Connection reset errors";
			}

			if (preg_match('/cURL error\s+(\d+):/i', $message, $matches) === 1)
			{
				return "cURL error ".$matches[1];
			}

			return $this->normaliseMessage($message);
		}

		/**
		 * Keep unknown errors separate, except for harmless whitespace/case differences.
		 */
		protected function normaliseMessage(string $message) : string
		{
			$message = trim((string) preg_replace('/\s+/', ' ', $message));

			return $message === "" ? "Uncategorised errors" : $message;
		}

		/**
		 * Count the errors produced by each controller and method.
		 */
		protected function sources(Collection $logs) : array
		{
			return $logs->groupBy(fn($log) => $this->sourceName($log->controller, $log->method))->map(fn(Collection $sourceLogs, string $name) =>
			[
				'name'  => $name,
				'count' => $sourceLogs->count(),
			])->sortByDesc('count')->values()->all();
		}

		/**
		 * Build a compact controller and method label for the report.
		 */
		protected function sourceName(?string $controller, ?string $method) : string
		{
			$controller = $controller ?: "Unknown controller";
			$method     = $method ?: "unknown method";
			$parts      = explode("\\", $controller);

			return end($parts)."::".$method;
		}

		/**
		 * Format a log timestamp consistently for display and sorting.
		 */
		protected function timestamp($value) : string
		{
			if ($value instanceof DateTimeInterface)
			{
				return $value->format("Y-m-d H:i:s");
			}

			return (string) $value;
		}
	}
