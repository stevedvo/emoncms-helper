<?php

namespace Tests\Unit;

use App\Services\ActivityLogSummarizer;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use stdClass;

class ActivityLogSummarizerTest extends TestCase
{
    /**
     * Verify variable timeout durations are grouped into one port category.
     */
    public function test_it_groups_variable_port_errors_and_sorts_by_count(): void
    {
        $logs = new Collection([
            $this->log(
                "cURL error 28: Failed to connect to emoncms.org port 443 after 21161 ms: Timed out",
                "2026-07-17 00:06:23",
                "App\\Http\\Controllers\\NibeController",
                "syncNibeData"
            ),
            $this->log(
                "cURL error 28: Failed to connect to emoncms.org port 443 after 21625 ms: Timed out",
                "2026-07-17 00:07:08",
                "App\\Http\\Controllers\\NibeController",
                "syncNibeData"
            ),
            $this->log(
                "cURL error 6: Could not resolve host: emoncms.org",
                "2026-07-17 00:08:00",
                "App\\Http\\Controllers\\EmonController",
                "getEmonFeeds"
            ),
        ]);

        $summary = (new ActivityLogSummarizer())->summarize($logs);

        $this->assertCount(2, $summary);
        $this->assertSame("Port 443 errors", $summary[0]['type']);
        $this->assertSame(2, $summary[0]['count']);
        $this->assertSame("2026-07-17 00:06:23", $summary[0]['first_seen']);
        $this->assertSame("2026-07-17 00:07:08", $summary[0]['last_seen']);
        $this->assertSame(
            [['name' => "NibeController::syncNibeData", 'count' => 2]],
            $summary[0]['sources']
        );
        $this->assertSame("DNS resolution errors", $summary[1]['type']);
    }

    /**
     * Verify known patterns are categorised while unknown messages remain distinct.
     */
    public function test_it_groups_known_errors_and_uses_exact_message_as_the_fallback(): void
    {
        $logs = new Collection([
            $this->log("cURL error 56: OpenSSL SSL_read: Connection was reset, errno 10054"),
            $this->log("cURL error 56: OpenSSL SSL_read: Connection was reset, errno 10054"),
            $this->log("App\\APIs\\NibeAPI::getParameterData(): Return value must be of type array, null returned"),
            $this->log("  app\\apis\\nibeapi::getparameterdata():   return value must be of type array, null returned  "),
            $this->log("A different application error"),
        ]);

        $summary = (new ActivityLogSummarizer())->summarize($logs);

        $this->assertCount(3, $summary);
        $this->assertSame("Connection reset errors", $summary[0]['type']);
        $this->assertSame(2, $summary[0]['count']);
        $this->assertSame(
            "App\\APIs\\NibeAPI::getParameterData(): Return value must be of type array, null returned",
            $summary[1]['type']
        );
        $this->assertSame(2, $summary[1]['count']);
        $this->assertSame("A different application error", $summary[2]['type']);
        $this->assertSame(1, $summary[2]['count']);
    }

    /**
     * Verify incomplete logs still produce a useful summary category and source.
     */
    public function test_it_handles_empty_messages_and_missing_source_details(): void
    {
        $summary = (new ActivityLogSummarizer())->summarize(new Collection([
            $this->log("", "2026-07-17 00:00:00", null, null),
            $this->log("   ", "2026-07-17 00:01:00", null, null),
        ]));

        $this->assertCount(1, $summary);
        $this->assertSame("Uncategorised errors", $summary[0]['type']);
        $this->assertSame(2, $summary[0]['count']);
        $this->assertSame(
            [['name' => "Unknown controller::unknown method", 'count' => 2]],
            $summary[0]['sources']
        );
    }

    /**
     * Create a lightweight activity log object for summarizer tests.
     */
    private function log(
        string $message,
        string $createdAt = "2026-07-17 00:00:00",
        ?string $controller = "App\\Http\\Controllers\\NibeController",
        ?string $method = "syncNibeData"
    ): stdClass {
        return (object) [
            'message' => $message,
            'created_at' => $createdAt,
            'controller' => $controller,
            'method' => $method,
        ];
    }
}
