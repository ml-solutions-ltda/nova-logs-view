<?php

declare(strict_types=1);

namespace MlSolutions\NovaLogsView\Tests;

use MlSolutions\NovaLogsView\Support\LogEntryParser;
use MlSolutions\NovaLogsView\Support\LogExplorer;
use MlSolutions\NovaLogsView\Support\LogFileRepository;
use MlSolutions\NovaLogsView\Support\LogRedactor;

final class NovaLogsVisibilityTest extends TestCase
{
    private string $logsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logsDirectory = sys_get_temp_dir().'/nova-logs-view-'.bin2hex(random_bytes(8));
        mkdir($this->logsDirectory, 0700, true);
        config()->set('nova-logs-view.root_path', $this->logsDirectory);
        config()->set('nova-logs-view.patterns', ['*.log']);
        config()->set('nova-logs-view.max_files', 10);
        config()->set('nova-logs-view.max_bytes_per_file', 1024 * 1024);
        config()->set('nova-logs-view.max_entries_per_file', 100);
        config()->set('nova-logs-view.max_entries', 100);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logsDirectory)) {
            foreach (new \FilesystemIterator($this->logsDirectory, \FilesystemIterator::SKIP_DOTS) as $file) {
                unlink($file->getPathname());
            }
        }

        if (is_dir($this->logsDirectory)) {
            rmdir($this->logsDirectory);
        }

        parent::tearDown();
    }

    public function test_it_only_discovers_allowed_regular_files_inside_the_log_directory(): void
    {
        file_put_contents($this->logsDirectory.'/laravel.log', '[2026-08-11 10:00:00] local.INFO: visible');
        file_put_contents($this->logsDirectory.'/notes.txt', 'not a log');
        file_put_contents($this->logsDirectory.'/.hidden.log', 'hidden');
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(6)).'.log';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->logsDirectory.'/linked.log');

        try {
            $files = (new LogFileRepository)->files();

            $this->assertCount(1, $files);
            $this->assertSame('laravel.log', $files[0]['name']);
            $this->assertArrayNotHasKey('path', $files[0]);
        } finally {
            unlink($outside);
        }
    }

    public function test_it_parses_laravel_multiline_and_json_logs_and_redacts_secrets(): void
    {
        $parser = new LogEntryParser(new LogRedactor(['password', 'token', 'authorization']));
        $content = implode("\n", [
            '[2026-08-11 10:00:00] production.ERROR: billing.failed {"token":"secret-value","customer":"synthetic"}',
            '#0 /var/www/app/Service.php(42): fail Bearer abc.def.ghi',
            'Cookie: session=private-value',
            json_encode([
                'observed_at_utc' => '2026-08-11T10:02:00Z',
                'level' => 'info',
                'component' => 'queue',
                'type' => 'metric',
                'metric' => 'queue_depth',
                'password' => 'never-expose',
            ], JSON_THROW_ON_ERROR),
        ]);
        $file = ['id' => 'file-id', 'name' => 'laravel.log'];

        $entries = $parser->parse($content, $file);

        $this->assertCount(2, $entries);
        $this->assertSame('ERROR', $entries[0]['level']);
        $this->assertSame('billing.failed', $entries[0]['type']);
        $this->assertSame('[REDACTED]', $entries[0]['context']['token']);
        $this->assertStringContainsString('Bearer [REDACTED]', $entries[0]['trace']);
        $this->assertStringContainsString('Cookie: [REDACTED]', $entries[0]['trace']);
        $this->assertSame('queue', $entries[1]['channel']);
        $this->assertSame('metric', $entries[1]['type']);
        $this->assertSame('[REDACTED]', $entries[1]['context']['password']);
    }

    public function test_it_separates_multiline_exception_context_and_hides_absolute_server_paths(): void
    {
        $parser = new LogEntryParser(new LogRedactor(['token']));
        $content = implode("\n", [
            '[2026-08-11 10:00:00] production.ERROR: Scheduled command failed. {"exception":"[object] (Exception at /var/www/app/Console/Kernel.php:42)',
            '[stacktrace]',
            '#0 /Users/example/project/admin/app/Service.php(21): run()',
            '"} []',
        ]);

        $entry = $parser->parse($content, ['id' => 'file-id', 'name' => 'laravel.log'])[0];

        $this->assertSame('Scheduled command failed.', $entry['message']);
        $this->assertSame(['exception' => 'Consulte o rastreamento sanitizado.'], $entry['context']);
        $this->assertStringContainsString('[PATH]/Kernel.php:42', $entry['trace']);
        $this->assertStringContainsString('[PATH]/Service.php(21)', $entry['trace']);
        $this->assertStringNotContainsString('/var/www', $entry['trace']);
        $this->assertStringNotContainsString('/Users/example', $entry['trace']);
        $this->assertStringNotContainsString('"} []', $entry['trace']);
    }

    public function test_it_filters_paginates_and_returns_sanitized_list_entries(): void
    {
        file_put_contents($this->logsDirectory.'/laravel.log', implode("\n", [
            '[2026-08-11 10:00:00] production.ERROR: billing.failed {"tenant":"synthetic"}',
            '[2026-08-11 10:01:00] production.INFO: queue.completed {"job":"Example"}',
        ]));
        $explorer = new LogExplorer(
            new LogFileRepository,
            new LogEntryParser(new LogRedactor(['token'])),
        );

        $result = $explorer->search(['level' => 'ERROR', 'search' => 'billing', 'per_page' => 10]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame(1, $result['summary']['errors']);
        $this->assertSame('billing.failed', $result['data'][0]['type']);
        $this->assertArrayNotHasKey('context', $result['data'][0]);
        $this->assertArrayNotHasKey('trace', $result['data'][0]);

        $detail = $explorer->detail($result['data'][0]['id'], $result['data'][0]['file_id']);
        $this->assertSame('synthetic', $detail['context']['tenant']);
    }

    public function test_it_groups_recurrent_problems_and_filters_by_diagnostic_fingerprint(): void
    {
        $recent = now()->subMinutes(10)->format('Y-m-d H:i:s');
        $earlier = now()->subMinutes(25)->format('Y-m-d H:i:s');
        $warning = now()->subMinutes(5)->format('Y-m-d H:i:s');
        file_put_contents($this->logsDirectory.'/laravel.log', implode("\n", [
            "[{$earlier}] production.ERROR: billing.failed order 123 550e8400-e29b-41d4-a716-446655440000",
            '#0 /var/www/app/Billing/Charge.php(42): charge()',
            "[{$recent}] production.ERROR: billing.failed order 456 550e8400-e29b-41d4-a716-446655440001",
            '#0 /var/www/app/Billing/Charge.php(99): charge()',
            "[{$warning}] production.WARNING: queue.delayed job 777",
        ]));
        $explorer = new LogExplorer(
            new LogFileRepository,
            new LogEntryParser(new LogRedactor(['token'])),
        );

        $result = $explorer->search(['per_page' => 10]);

        $this->assertSame(2, $result['summary']['unique_problems']);
        $this->assertSame(2, $result['summary']['top_problems'][0]['occurrences']);
        $this->assertSame('billing.failed', $result['summary']['top_problems'][0]['type']);
        $this->assertMatchesRegularExpression(
            '/^#0 \[PATH\]\/Charge\.php\((42|99)\): charge\(\)$/',
            $result['summary']['top_problems'][0]['trace_hint'],
        );
        $billingEntries = collect($result['data'])->where('type', 'billing.failed')->values();
        $this->assertCount(2, $billingEntries);
        $this->assertSame(2, $billingEntries[0]['diagnostic']['occurrences']);
        $this->assertSame($billingEntries[0]['diagnostic']['fingerprint'], $billingEntries[1]['diagnostic']['fingerprint']);

        $filtered = $explorer->search([
            'fingerprint' => $result['summary']['top_problems'][0]['fingerprint'],
            'per_page' => 10,
        ]);

        $this->assertSame(2, $filtered['meta']['total']);
        $this->assertSame(1, $filtered['summary']['unique_problems']);
    }

    public function test_it_supports_failure_trace_and_rolling_period_shortcuts(): void
    {
        $recent = now()->subMinutes(20)->format('Y-m-d H:i:s');
        $old = now()->subHours(2)->format('Y-m-d H:i:s');
        file_put_contents($this->logsDirectory.'/laravel.log', implode("\n", [
            "[{$old}] production.ERROR: old.failure",
            '#0 /var/www/app/Old.php(10): fail()',
            "[{$recent}] production.WARNING: recent.warning",
            '#0 /var/www/app/Recent.php(20): warn()',
            "[{$recent}] production.ERROR: recent.failure",
            '#0 /var/www/app/Recent.php(30): fail()',
            "[{$recent}] production.ERROR: trace.missing",
        ]));
        $explorer = new LogExplorer(
            new LogFileRepository,
            new LogEntryParser(new LogRedactor(['token'])),
        );

        $result = $explorer->search([
            'severity' => 'failures',
            'has_trace' => '1',
            'period' => '1h',
            'per_page' => 10,
        ]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('recent.failure', $result['data'][0]['type']);
        $this->assertSame(100.0, $result['summary']['failure_rate']);
        $this->assertNotNull($result['summary']['latest_failure_at']);
    }

    public function test_missing_and_partially_malformed_logs_return_a_usable_result(): void
    {
        config()->set('nova-logs-view.root_path', $this->logsDirectory.'/rotated-away');

        $this->assertSame([], (new LogFileRepository)->files());

        config()->set('nova-logs-view.root_path', $this->logsDirectory);
        file_put_contents($this->logsDirectory.'/laravel.log', implode("\n", [
            'malformed prefix that should be ignored',
            '{not-json}',
            '[2026-08-11 10:00:00] production.INFO: valid.event',
        ]));
        $explorer = new LogExplorer(
            new LogFileRepository,
            new LogEntryParser(new LogRedactor(['token'])),
        );

        $result = $explorer->search(['per_page' => 10]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('valid.event', $result['data'][0]['type']);
    }
}
