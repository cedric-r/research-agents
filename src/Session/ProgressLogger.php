<?php

declare(strict_types=1);

namespace App\Session;

/**
 * JSON-line progress event logger for concurrent child processes.
 *
 * Children write progress events (started, llm_call, web_search, etc.)
 * to a shared session.log file. The parent (CLI REPL or SSE endpoint)
 * polls it via readNewLines() for real-time progress display.
 *
 * Thread-safe via FILE_APPEND | LOCK_EX on every write.
 *
 * @package App\Session
 */
class ProgressLogger
{
    private string $logFile;

    /**
     * @param string $logFile Absolute path to the session.log file
     */
    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;

        // Ensure parent directory exists (safety net — should already exist)
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Write a JSON progress event to the log file.
     *
     * Format: {"ts":"...","agent":"...","channel":"PROGRESS","event":"...","data":{...}}
     *
     * @param string $event Event type (started, llm_call, web_search, etc.)
     * @param string $agent Agent name
     * @param array  $data  Optional event-specific data
     */
    public function logEvent(string $event, string $agent = '', array $data = []): void
    {
        $entry = json_encode([
            'ts'      => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'agent'   => $agent,
            'channel' => 'PROGRESS',
            'event'   => $event,
            'data'    => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Read new lines from the log file since the given byte offset.
     *
     * Non-blocking — returns immediately with all new content.
     *
     * @param  int   $offset Byte offset to start reading from
     * @return array{lines: string[], new_offset: int}
     */
    public function readNewLines(int $offset): array
    {
        if (!file_exists($this->logFile)) {
            return ['lines' => [], 'new_offset' => 0];
        }

        $size = filesize($this->logFile);
        if ($size === false || $size <= $offset) {
            return ['lines' => [], 'new_offset' => $size !== false ? $size : 0];
        }

        $handle = fopen($this->logFile, 'rb');
        if ($handle === false) {
            return ['lines' => [], 'new_offset' => $offset];
        }

        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false || $content === '') {
            return ['lines' => [], 'new_offset' => $size];
        }

        $lines = array_filter(explode("\n", $content), fn(string $l): bool => $l !== '');
        return ['lines' => array_values($lines), 'new_offset' => $size];
    }
}
