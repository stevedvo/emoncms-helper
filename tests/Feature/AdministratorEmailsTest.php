<?php
	namespace Tests\Feature;

	use App\Mail\ActivityLogReport;
	use App\Models\ActivityLog;
	use Carbon\Carbon;
	use Illuminate\Support\Facades\Mail;
	use Tests\Support\ControlPanelDatabase;
	use Tests\TestCase;

	class AdministratorEmailsTest extends TestCase
	{
		use ControlPanelDatabase;

		public function test_daily_report_uses_the_renamed_summariser_and_groups_missing_local_feed_data() : void
		{
			$this->prepareControlPanelDatabase();
			Mail::fake();
			config()->set('app.admin_email', 'admin@example.test');
			$now = Carbon::now();

			foreach ([6, 7] as $feedId)
			{
				(new ActivityLog())->forceFill(
				[
					'controller' => 'App\\Http\\Controllers\\EmonController',
					'method'     => 'getLatestEmonData',
					'level'      => 'error',
					'message'    => 'Unable to get latest data. $environment = local, $feedItem[$envFeedId] = '.$feedId.', $startTimeMilliseconds = '.($feedId * 1000),
					'created_at' => $now->copy()->subMinutes($feedId),
				])->save();
			}

			(new ActivityLog())->forceFill(
			[
				'controller' => 'App\\Http\\Controllers\\EmonController',
				'method'     => 'getLatestEmonData',
				'level'      => 'error',
				'message'    => 'Unable to get latest data. $environment = local, older error',
				'created_at' => $now->copy()->subDays(2),
			])->save();

			$this->artisan('admin:emails')->assertExitCode(0);
			Mail::assertSent(ActivityLogReport::class, function(ActivityLogReport $report)
			{
				$this->assertTrue($report->hasTo('admin@example.test'));
				$this->assertSame(2, $report->totalCount);
				$this->assertCount(1, $report->data);
				$this->assertSame('Missing local feed data', $report->data[0]['type']);
				$this->assertSame(2, $report->data[0]['count']);

				return true;
			});
			Mail::assertSentCount(1);
			$this->assertSame([], ActivityLog::where('message', 'like', 'Mail sending error:%')->pluck('message')->all());
		}
	}
