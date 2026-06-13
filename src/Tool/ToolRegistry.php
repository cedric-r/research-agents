<?php

declare(strict_types=1);

namespace App\Tool;

use App\Log\Logger;

/**
 * Tool registration and dispatch by name.
 *
 * Tools are registered by name with a callable handler and a JSON schema
 * describing accepted parameters. ResearchAgent calls run() to dispatch
 * without needing to know the tool class.
 */
class ToolRegistry
{
    /** @var array<string, array{handler: callable, schema: array}> */
    private array $tools = [];

    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Register a tool by name.
     *
     * @param string $name       Unique tool identifier (e.g., 'web_search')
     * @param array  $definition Must contain 'handler' (callable) and 'schema' (array)
     * @throws \RuntimeException If handler is not callable or name already registered
     */
    public function register(string $name, array $definition): void
    {
        if (!isset($definition['handler']) || !is_callable($definition['handler'])) {
            throw new \RuntimeException(
                "Tool '{$name}' must define a callable 'handler'"
            );
        }

        if (!isset($definition['schema']) || !is_array($definition['schema'])) {
            throw new \RuntimeException(
                "Tool '{$name}' must define an array 'schema'"
            );
        }

        if (isset($this->tools[$name])) {
            throw new \RuntimeException("Tool '{$name}' is already registered");
        }

        $this->tools[$name] = [
            'handler' => $definition['handler'],
            'schema'  => $definition['schema'],
        ];

        if ($this->logger) {
            $this->logger->info('Tool registered', ['tool' => $name]);
        }
    }

    /**
     * Run a registered tool by name with the given parameters.
     *
     * @param  string $name   Tool name (must be registered)
     * @param  array  $params Parameters passed to the handler
     * @return string         Handler result
     * @throws \RuntimeException If tool not found or handler fails
     */
    public function run(string $name, array $params = []): string
    {
        if (!isset($this->tools[$name])) {
            throw new \RuntimeException("Tool '{$name}' is not registered");
        }

        if ($this->logger) {
            $this->logger->info('Tool run started', [
                'tool'   => $name,
                'params' => $this->summarizeParams($params),
            ]);
        }

        $handler = $this->tools[$name]['handler'];

        try {
            $result = call_user_func($handler, $params);
            $resultStr = (string) $result;

            if ($this->logger) {
                $this->logger->info('Tool run completed', [
                    'tool' => $name,
                ]);
            }

            return $resultStr;
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Tool run failed', [
                    'tool'  => $name,
                    'error' => $e->getMessage(),
                ]);
            }
            throw new \RuntimeException(
                "Tool '{$name}' execution failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Return schemas for all registered tools.
     *
     * @return array<string, array> Tool name => schema array
     */
    public function getSchemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $name => $definition) {
            $schemas[$name] = $definition['schema'];
        }
        return $schemas;
    }

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Summarize params for logging — truncate long strings, redact sensitive fields.
     *
     * @param array $params
     * @return array
     */
    private function summarizeParams(array $params): array
    {
        $summary = [];
        foreach ($params as $key => $value) {
            if (is_string($value) && strlen($value) > 100) {
                $summary[$key] = mb_substr($value, 0, 100) . '...';
            } else {
                $summary[$key] = $value;
            }
        }
        return $summary;
    }
}
