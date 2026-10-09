<?php

namespace MlSolutions\NovaLogsView\Support;

final class LogRedactor
{
    /** @var array<int, string> */
    private array $keys;

    /** @param array<int, string>|null $keys */
    public function __construct(?array $keys = null)
    {
        $this->keys = array_map(
            static fn (string $key): string => strtolower($key),
            $keys ?? (array) config('nova-logs-view.redacted_keys', []),
        );
    }

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $itemKey => $item) {
                $redacted[$itemKey] = $this->redact($item, (string) $itemKey);
            }

            return $redacted;
        }

        if (is_object($value)) {
            return $this->redact((array) $value, $key);
        }

        if (! is_string($value)) {
            return $value;
        }

        return $this->redactString($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));

        foreach ($this->keys as $sensitiveKey) {
            if ($normalized === str_replace(['-', '.'], '_', $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function redactString(string $value): string
    {
        $patterns = [
            '/((?:authorization|cookie|set-cookie)\s*[:=]\s*)[^\r\n]+/i' => '$1[REDACTED]',
            '/(bearer\s+)[a-z0-9._~+\/-]+=*/i' => '$1[REDACTED]',
            '/([?&](?:token|api_key|access_token|secret)=)[^&\s]+/i' => '$1[REDACTED]',
            '/((?:password|passwd|pwd|token|api[_-]?key|client[_-]?secret)\s*[=:]\s*)[^\s,;"\'}]+/i' => '$1[REDACTED]',
            '/("(?:password|token|access_token|refresh_token|api_key|client_secret)"\s*:\s*")[^"]*(")/i' => '$1[REDACTED]$2',
            '~(?<![A-Za-z0-9:])/(?:[^/\s:(),]+/)+(?<file>[^/\s:(),]+)(?<line>\(\d+\)|:\d+)?~' => '[PATH]/$1$2',
            '~(?<![A-Za-z0-9])(?:[A-Z]:\\\\)(?:[^\\\s:(),]+\\\\)+(?<file>[^\\\s:(),]+)(?<line>\(\d+\)|:\d+)?~i' => '[PATH]/$1$2',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
