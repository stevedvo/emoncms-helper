<?php

namespace Tests\Feature;

use App\Mail\ActivityLogReport;
use Tests\TestCase;

class ActivityLogReportTest extends TestCase
{
    /**
     * Verify the email displays grouped counts instead of individual log rows.
     */
    public function test_the_report_renders_group_counts_instead_of_individual_logs(): void
    {
        $report = new ActivityLogReport(
            "errors",
            "2026-07-16T06:00:00+01:00",
            collect([
                [
                    'type' => "Port 443 errors",
                    'count' => 159,
                    'sources' => [
                        ['name' => "NibeController::syncNibeData", 'count' => 159],
                    ],
                    'first_seen' => "2026-07-17 00:06:23",
                    'last_seen' => "2026-07-17 18:25:56",
                ],
                [
                    'type' => "DNS resolution errors",
                    'count' => 2,
                    'sources' => [
                        ['name' => "EmonController::getEmonFeeds", 'count' => 2],
                    ],
                    'first_seen' => "2026-07-17 01:00:00",
                    'last_seen' => "2026-07-17 02:00:00",
                ],
            ]),
            161
        );

        $html = $report->render();

        $this->assertStringContainsString("161", $html);
        $this->assertStringContainsString("grouped into <strong>2</strong>", $html);
        $this->assertStringContainsString("Port 443 errors", $html);
        $this->assertStringContainsString("NibeController::syncNibeData (159)", $html);
    }

    /**
     * Verify the email retains its empty-period message.
     */
    public function test_the_report_handles_an_empty_period(): void
    {
        $html = (new ActivityLogReport(
            "errors",
            "2026-07-16T06:00:00+01:00",
            collect(),
            0
        ))->render();

        $this->assertStringContainsString("Nothing to report", $html);
    }
}
