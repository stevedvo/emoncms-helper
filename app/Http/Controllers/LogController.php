<?php
	namespace App\Http\Controllers;

	use Throwable;
	use App\Http\Controllers\Controller;
	use App\Models\ActivityLog;
	use App\Models\ApiLog;

	class LogController extends Controller
	{
		static $daysToKeepInfoLogs    = 30;
		static $daysToKeepWarningLogs = 60;
		static $daysToKeepErrorLogs   = 90;
		static $daysToKeepApiLogs     = 90;

		public static function purgeOldLogEntries() : void
		{
			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Purge start.",
			]);

			static::purgeActivityLogs("info", static::$daysToKeepInfoLogs);
			static::purgeActivityLogs("warning", static::$daysToKeepWarningLogs);
			static::purgeActivityLogs("error", static::$daysToKeepErrorLogs);
			static::purgeApiLogs();

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Purge complete.",
			]);
		}

		public static function purgeActivityLogs(string $level, int $subDays) : void
		{
			try
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Purging ".$level." logs older than ".$subDays." days.",
				]);

				$purged = ActivityLog::where("level", $level)->where("created_at", "<", now()->subDays($subDays))->delete();

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => $purged." ".$level." logs purged.",
				]);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}
		}

		public static function purgeApiLogs() : void
		{
			try
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "Purging API logs older than ".static::$daysToKeepApiLogs." days.",
				]);

				$purged = ApiLog::where("created_at", "<", now()->subDays(static::$daysToKeepApiLogs))->delete();

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => $purged." API logs purged.",
				]);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => $e->getMessage(),
				]);
			}
		}
	}
