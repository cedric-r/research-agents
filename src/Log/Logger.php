<?php

declare(strict_types=1);

namespace App\Log;

class Logger
{
    private const LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR'];

    private string $file;
    private string $channel;
    private string $correlationId;

    public function __construct(
        string $file,
        string $channel = 'SYSTEM',
        ?string $correlationId = null
    ) {
        $this->file = $file;
        $this->channel = strtoupper(mb_substr($channel, 0, 11));
        $this->correlationId = $correlationId ?? self::generateCorrelationId();
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);

        if (!in_array($level, self::LEVELS, true)) {
            $level = 'INFO';
        }

        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x0A\x0D]/', '', $message);

        $line = sprintf(
            "[%s] [%-11s] [%-5s] [%s] %s%s\n",
            $timestamp,
            $this->channel,
            $level,
            $this->correlationId,
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $written = @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log("Logger: Cannot write to {$this->file}");
        }
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->log('INFO', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->log('ERROR', $msg, $ctx);
    }

    public function warn(string $msg, array $ctx = []): void
    {
        $this->log('WARN', $msg, $ctx);
    }

    public function debug(string $msg, array $ctx = []): void
    {
        $this->log('DEBUG', $msg, $ctx);
    }

    public static function generateCorrelationId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}
