<?php
	namespace App\Console\Commands;

	use Throwable;
	use App\Mail\ActivityLogReport;
	use App\Models\ActivityLog;
	use App\Services\ActivityLogSummarizer;
	use Carbon\Carbon;
	use Illuminate\Console\Command;
	use Illuminate\Support\Facades\Mail;

	class AdministratorEmails extends Command
	{
		/**
		 * The name and signature of the console command.
		 *
		 * @var string
		 */
		protected $signature = "admin:emails";

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = "Send e-mails to site administrator";

		/**
		 * Execute the console command.
		 *
		 * @param ActivityLogSummarizer $activityLogSummarizer
		 * @return void
		 */
		public function handle(ActivityLogSummarizer $activityLogSummarizer) : void
		{
			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Command Start.",
			]);

			$activityLogs = null;

			try
			{
				$fromDateTime = Carbon::now()->subDays(1);
				$activityLogs = ActivityLog::where('level', "error")->where('created_at', ">", $fromDateTime->format("Y-m-d H:i:s"))->orderBy('created_at')->get();
				$activityLogSummary = $activityLogSummarizer->summarize($activityLogs);

				$activityLogReport = new ActivityLogReport("errors", $fromDateTime->format("c"), $activityLogSummary, $activityLogs->count());
				Mail::to(config("app.admin_email"))->send($activityLogReport);
			}
			catch (Throwable $e)
			{
				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "error",
					'message'    => "Mail sending error: ".$e->getMessage(),
				]);

				ActivityLog::create(
				[
					'controller' => __CLASS__,
					'method'     => __FUNCTION__,
					'level'      => "info",
					'message'    => "ActivityLogs: ".serialize($activityLogs),
				]);
			}

			ActivityLog::create(
			[
				'controller' => __CLASS__,
				'method'     => __FUNCTION__,
				'level'      => "info",
				'message'    => "Command Complete.",
			]);
		}
	}
