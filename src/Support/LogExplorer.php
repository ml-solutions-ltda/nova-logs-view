<?php

namespace MlSolutions\NovaLogsView\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class LogExplorer
{
    private const ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private const PROBLEM_LEVELS = ['WARNING', ...self::ERROR_LEVELS];

    public function __construct(
        private readonly LogFileRepository $files,
        private readonly LogEntryParser $parser,
    ) {}

    /** @return array<string, mixed> */
    public function search(array $filters): array
    {
        $availableFiles = $this->files->files();
        $selectedFiles = $availableFiles;

        if (($filters['file'] ?? '') !== '') {
            $selectedFiles = array_values(array_filter(
                $availableFiles,
                static fn (array $file): bool => hash_equals($file['id'], (string) $filters['file']),
            ));
        }

        $entries = [];
        $truncatedFiles = [];
        $maxEntries = (int) config('nova-logs-view.max_entries', 6000);
        $collectionLimit = $maxEntries * 2;

        foreach ($selectedFiles as $file) {
            $read = $this->files->read($file['id']);

            if ($read === null) {
                continue;
            }

            if ($read['truncated']) {
                $truncatedFiles[] = $file['name'];
            }

            $entries = array_merge($entries, $this->parser->parse($read['content'], $file, $read['start_offset']));

            if (count($entries) >= $collectionLimit) {
                break;
            }
        }

        usort($entries, static fn (array $left, array $right): int => $right['timestamp_unix'] <=> $left['timestamp_unix']);
        $entriesWereLimited = count($entries) > $maxEntries;
        $entries = array_slice($entries, 0, $maxEntries);

        $options = $this->options($entries, $availableFiles);
        $filtered = array_values(array_filter($entries, fn (array $entry): bool => $this->matches($entry, $filters)));
        $problems = $this->problems($filtered);
        $summary = $this->summary($filtered, $problems['groups']);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(
            max(10, (int) ($filters['per_page'] ?? config('nova-logs-view.default_per_page', 50))),
            (int) config('nova-logs-view.max_per_page', 100),
        );
        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageEntries = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return [
            'data' => array_map(
                fn (array $entry): array => $this->listEntry($entry, $problems['by_fingerprint']),
                $pageEntries,
            ),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'summary' => $summary,
            'options' => $options,
            'files' => $availableFiles,
            'limitations' => [
                'truncated' => $truncatedFiles !== [] || $entriesWereLimited,
                'truncated_files' => $truncatedFiles,
                'max_entries' => (int) config('nova-logs-view.max_entries', 6000),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id, string $fileId): ?array
    {
        $file = collect($this->files->files())->firstWhere('id', $fileId);
        $read = $this->files->read($fileId);

        if (! is_array($file) || $read === null) {
            return null;
        }

        $entry = collect($this->parser->parse($read['content'], $file, $read['start_offset']))
            ->first(static fn (array $entry): bool => hash_equals($entry['id'], $id));

        if (! is_array($entry)) {
            return null;
        }

        $entry['diagnostic'] = [
            'fingerprint' => $this->fingerprint($entry),
            'trace_hint' => $this->traceHint($entry),
        ];

        return $entry;
    }

    private function matches(array $entry, array $filters): bool
    {
        foreach (['level', 'channel', 'type'] as $key) {
            if (($filters[$key] ?? '') !== '' && strcasecmp((string) $entry[$key], (string) $filters[$key]) !== 0) {
                return false;
            }
        }

        $timestamp = (int) $entry['timestamp_unix'];

        if (($filters['severity'] ?? '') === 'failures' && ! in_array($entry['level'], self::ERROR_LEVELS, true)) {
            return false;
        }

        if (($filters['has_trace'] ?? '') === '1' && ! $entry['has_trace']) {
            return false;
        }

        if (($filters['fingerprint'] ?? '') !== '' && ! hash_equals(
            (string) $filters['fingerprint'],
            $this->fingerprint($entry),
        )) {
            return false;
        }

        $period = (string) ($filters['period'] ?? '');

        if ($period !== '' && $timestamp < CarbonImmutable::now()->subSeconds($this->periodSeconds($period))->getTimestamp()) {
            return false;
        }

        if (($filters['from'] ?? '') !== '' && $timestamp < $this->date((string) $filters['from'], false)) {
            return false;
        }

        if (($filters['to'] ?? '') !== '' && $timestamp > $this->date((string) $filters['to'], true)) {
            return false;
        }

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        if ($search !== '') {
            $haystack = mb_strtolower(implode(' ', [
                $entry['message'], $entry['type'], $entry['channel'], $entry['file'],
                json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]));

            if (! str_contains($haystack, $search)) {
                return false;
            }
        }

        return true;
    }

    private function date(string $value, bool $endOfDay): int
    {
        try {
            $date = CarbonImmutable::parse($value);

            return ($endOfDay ? $date->endOfDay() : $date->startOfDay())->getTimestamp();
        } catch (Throwable) {
            return $endOfDay ? PHP_INT_MAX : 0;
        }
    }

    /** @return array<string, mixed> */
    private function summary(array $entries, array $problems): array
    {
        $levels = $this->counts($entries, 'level');
        $types = array_slice($this->counts($entries, 'type'), 0, 8, true);
        $channels = array_slice($this->counts($entries, 'channel'), 0, 8, true);
        $activity = [];

        foreach ($entries as $entry) {
            $bucket = $entry['timestamp'] === null
                ? 'Sem data'
                : CarbonImmutable::parse($entry['timestamp'])->format('d/m H:00');

            if (! array_key_exists($bucket, $activity) && count($activity) >= 12) {
                continue;
            }

            $activity[$bucket] = ($activity[$bucket] ?? 0) + 1;
        }

        $activity = array_reverse($activity, true);

        $errors = array_sum(array_intersect_key($levels, array_flip(self::ERROR_LEVELS)));
        $latestFailure = collect($entries)
            ->filter(static fn (array $entry): bool => in_array($entry['level'], self::ERROR_LEVELS, true))
            ->max('timestamp');

        return [
            'total' => count($entries),
            'errors' => $errors,
            'warnings' => (int) ($levels['WARNING'] ?? 0),
            'failure_rate' => count($entries) === 0 ? 0 : round(($errors / count($entries)) * 100, 1),
            'unique_problems' => count($problems),
            'latest_failure_at' => is_string($latestFailure) ? $latestFailure : null,
            'levels' => $levels,
            'top_types' => $types,
            'top_channels' => $channels,
            'activity' => $activity,
            'top_problems' => array_slice($problems, 0, 6),
        ];
    }

    private function counts(array $entries, string $field): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $value = (string) $entry[$field];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    private function options(array $entries, array $files): array
    {
        $values = static function (array $entries, string $field): array {
            $values = array_values(array_unique(array_map(static fn (array $entry): string => (string) $entry[$field], $entries)));
            natcasesort($values);

            return array_values($values);
        };

        return [
            'files' => array_map(static fn (array $file): array => ['value' => $file['id'], 'label' => $file['name']], $files),
            'levels' => $values($entries, 'level'),
            'channels' => $values($entries, 'channel'),
            'types' => $values($entries, 'type'),
        ];
    }

    private function listEntry(array $entry, array $problemsByFingerprint): array
    {
        $fingerprint = $this->fingerprint($entry);
        $problem = $problemsByFingerprint[$fingerprint] ?? null;

        unset($entry['context'], $entry['trace'], $entry['timestamp_unix']);

        if (is_array($problem)) {
            $entry['diagnostic'] = [
                'fingerprint' => $fingerprint,
                'occurrences' => $problem['occurrences'],
                'first_seen' => $problem['first_seen'],
                'last_seen' => $problem['last_seen'],
                'trace_hint' => $problem['trace_hint'],
            ];
        }

        return $entry;
    }

    /** @return array{groups: array<int, array<string, mixed>>, by_fingerprint: array<string, array<string, mixed>>} */
    private function problems(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            if (! in_array($entry['level'], self::PROBLEM_LEVELS, true)) {
                continue;
            }

            $fingerprint = $this->fingerprint($entry);

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'level' => $entry['level'],
                    'type' => $entry['type'],
                    'message' => $entry['message'],
                    'occurrences' => 0,
                    'first_seen' => null,
                    'last_seen' => null,
                    'last_seen_unix' => 0,
                    'channels' => [],
                    'files' => [],
                    'trace_hint' => $this->traceHint($entry),
                ];
            }

            $group = &$groups[$fingerprint];
            $group['occurrences']++;
            $group['channels'][$entry['channel']] = true;
            $group['files'][$entry['file']] = true;

            if ($this->severity($entry['level']) > $this->severity($group['level'])) {
                $group['level'] = $entry['level'];
            }

            if ($entry['timestamp'] !== null) {
                if ($group['first_seen'] === null || $entry['timestamp_unix'] < CarbonImmutable::parse($group['first_seen'])->getTimestamp()) {
                    $group['first_seen'] = $entry['timestamp'];
                }

                if ($group['last_seen'] === null || $entry['timestamp_unix'] > $group['last_seen_unix']) {
                    $group['last_seen'] = $entry['timestamp'];
                    $group['last_seen_unix'] = $entry['timestamp_unix'];
                }
            }

            unset($group);
        }

        foreach ($groups as &$group) {
            $group['channels'] = array_keys($group['channels']);
            $group['files'] = array_keys($group['files']);
        }
        unset($group);

        uasort($groups, static fn (array $left, array $right): int => [$right['occurrences'], $right['last_seen_unix']] <=> [$left['occurrences'], $left['last_seen_unix']]
        );

        $groups = array_map(static function (array $group): array {
            unset($group['last_seen_unix']);

            return $group;
        }, $groups);

        return ['groups' => array_values($groups), 'by_fingerprint' => $groups];
    }

    private function fingerprint(array $entry): string
    {
        $message = mb_strtolower((string) $entry['message']);
        $message = (string) preg_replace('/\b[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\b/i', '{id}', $message);
        $message = (string) preg_replace('/\b[0-9a-hjkmnp-tv-z]{20,32}\b/i', '{id}', $message);
        $message = (string) preg_replace('/\b\d+\b/u', '{n}', $message);
        $message = (string) preg_replace('/\s+/u', ' ', trim($message));
        $trace = mb_strtolower((string) ($this->traceHint($entry) ?? ''));
        $trace = (string) preg_replace('/(?::|\()\d+\)?/', '{line}', $trace);

        return substr(hash('sha256', mb_strtolower((string) $entry['type']).'|'.$message.'|'.$trace), 0, 24);
    }

    private function traceHint(array $entry): ?string
    {
        if (! is_string($entry['trace'] ?? null) || trim($entry['trace']) === '') {
            return null;
        }

        $fallback = null;

        foreach (preg_split('/\R/', $entry['trace']) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $fallback ??= $line;

            if (preg_match('/^#\d+\s+/', $line) && str_contains($line, '[PATH]')) {
                return mb_substr($line, 0, 300);
            }
        }

        return $fallback === null ? null : mb_substr($fallback, 0, 300);
    }

    private function severity(string $level): int
    {
        return match ($level) {
            'EMERGENCY' => 6,
            'ALERT' => 5,
            'CRITICAL' => 4,
            'ERROR' => 3,
            'WARNING' => 2,
            default => 1,
        };
    }

    private function periodSeconds(string $period): int
    {
        return match ($period) {
            '1h' => 3600,
            '6h' => 6 * 3600,
            '24h' => 24 * 3600,
            '7d' => 7 * 86400,
            '30d' => 30 * 86400,
            default => PHP_INT_MAX,
        };
    }
}
