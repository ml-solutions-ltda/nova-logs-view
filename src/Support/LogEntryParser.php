<?php

namespace MlSolutions\NovaLogsView\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class LogEntryParser
{
    public function __construct(private readonly LogRedactor $redactor) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content, array $file, int $startOffset = 0): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $entries = [];
        $current = null;
        $offset = $startOffset;
        $limit = max(1, (int) config('nova-logs-view.max_entries_per_file', 2500));

        foreach ($lines as $line) {
            $lineOffset = $offset;
            $offset += strlen($line) + 1;

            if (trim($line) === '') {
                if ($current !== null) {
                    $current['trace_lines'][] = '';
                }

                continue;
            }

            $parsed = $this->parseJsonLine($line, $file, $lineOffset)
                ?? $this->parseLaravelHeader($line, $file, $lineOffset);

            if ($parsed !== null) {
                if ($current !== null) {
                    $entries[] = $this->finish($current);
                }

                $current = $parsed;

                continue;
            }

            if ($current !== null) {
                $current['trace_lines'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $this->finish($current);
        }

        return array_slice($entries, -$limit);
    }

    /** @return array<string, mixed>|null */
    private function parseLaravelHeader(string $line, array $file, int $offset): ?array
    {
        if (! preg_match('/^\[(?<timestamp>[^\]]+)]\s+(?<environment>[^.\s]+)\.(?<level>[A-Za-z]+):\s*(?<message>.*)$/s', $line, $matches)) {
            return null;
        }

        $message = trim($matches['message']);
        $context = [];

        if (preg_match('/^(?<message>.*?)(?<context>\{.*})$/s', $message, $suffix)) {
            $decoded = json_decode($suffix['context'], true);

            if (is_array($decoded)) {
                $message = trim($suffix['message']);
                $context = $decoded;
            }
        }

        return $this->entry(
            file: $file,
            offset: $offset,
            timestamp: $matches['timestamp'],
            level: $matches['level'],
            channel: $matches['environment'],
            message: $message,
            context: $context,
        );
    }

    /** @return array<string, mixed>|null */
    private function parseJsonLine(string $line, array $file, int $offset): ?array
    {
        $decoded = json_decode(trim($line), true);

        if (! is_array($decoded)) {
            return null;
        }

        $timestamp = $this->first($decoded, ['timestamp', 'datetime', 'observed_at_utc', '@timestamp', 'time']);
        $level = $this->first($decoded, ['level', 'level_name', 'severity']) ?? 'INFO';
        $channel = $this->first($decoded, ['channel', 'environment', 'component', 'service']) ?? 'application';
        $message = $this->first($decoded, ['message', 'msg', 'description'])
            ?? $this->first($decoded, ['event', 'type', 'metric'])
            ?? 'Evento estruturado';

        $context = $decoded;
        unset(
            $context['timestamp'], $context['datetime'], $context['observed_at_utc'], $context['@timestamp'], $context['time'],
            $context['level'], $context['level_name'], $context['severity'],
            $context['channel'], $context['environment'], $context['component'], $context['service'],
            $context['message'], $context['msg'], $context['description'],
        );

        return $this->entry($file, $offset, $timestamp, $level, $channel, (string) $message, $context);
    }

    /** @return array<string, mixed> */
    private function entry(
        array $file,
        int $offset,
        mixed $timestamp,
        mixed $level,
        mixed $channel,
        string $message,
        array $context,
    ): array {
        $normalizedTimestamp = $this->timestamp($timestamp);

        return [
            'id' => hash('sha256', $file['id'].':'.$offset),
            'file_id' => $file['id'],
            'file' => $file['name'],
            'timestamp' => $normalizedTimestamp,
            'timestamp_unix' => $normalizedTimestamp === null ? 0 : CarbonImmutable::parse($normalizedTimestamp)->getTimestamp(),
            'level' => $this->level((string) $level),
            'channel' => trim((string) $channel) ?: 'application',
            'type' => $this->type($message, $context),
            'message' => $message,
            'context' => $context,
            'trace_lines' => [],
        ];
    }

    /** @param array<string, mixed> $entry */
    private function finish(array $entry): array
    {
        $trace = trim(implode("\n", $entry['trace_lines']));
        unset($entry['trace_lines']);

        if ($entry['context'] === [] && preg_match(
            '/^(?<message>.*?)\s+\{"exception":"(?<exception>.*)$/s',
            (string) $entry['message'],
            $matches,
        )) {
            $entry['message'] = trim($matches['message']);
            $trace = trim($matches['exception'].($trace === '' ? '' : "\n".$trace));
            $trace = (string) preg_replace('/"}(?:\s+\[\])?\s*$/s', '', $trace);
            $entry['context'] = ['exception' => 'Consulte o rastreamento sanitizado.'];
        }

        $entry['message'] = $this->redactor->redact((string) $entry['message']);
        $entry['context'] = $this->redactor->redact($entry['context']);
        $entry['trace'] = $trace === '' ? null : $this->redactor->redact($trace);
        $entry['has_context'] = $entry['context'] !== [];
        $entry['has_trace'] = $entry['trace'] !== null;

        return $entry;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function level(string $level): string
    {
        $level = strtoupper(trim($level));

        return match ($level) {
            'WARN', 'WARNING' => 'WARNING',
            'ERR', 'ERROR' => 'ERROR',
            'CRIT', 'CRITICAL', 'ALERT', 'EMERGENCY' => $level === 'CRIT' ? 'CRITICAL' : $level,
            'DEBUG', 'INFO', 'NOTICE' => $level,
            default => 'INFO',
        };
    }

    /** @param array<string, mixed> $context */
    private function type(string $message, array $context): string
    {
        foreach (['event', 'type', 'metric', 'exception'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                return mb_substr(trim((string) $context[$key]), 0, 120);
            }
        }

        if (preg_match('/^(?<type>[A-Za-z][A-Za-z0-9_.:-]{2,119})(?:\s|$)/', $message, $matches)) {
            return $matches['type'];
        }

        if (preg_match('/(?<type>[A-Za-z_\\\\]+(?:Exception|Error))/', $message, $matches)) {
            return mb_substr($matches['type'], 0, 120);
        }

        return 'application';
    }

    /** @param array<string, mixed> $data */
    private function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }
}
