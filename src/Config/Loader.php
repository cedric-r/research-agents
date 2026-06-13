<?php

/**
 * ResearchAgents -- multi-agent research and debate system.
 * Copyright (C) 2026 Cedric Raguenaud
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */



declare(strict_types=1);

namespace App\Config;

class Loader
{
    /**
     * Load and validate a config file (JSON or PHP array format).
     *
     * @param string $path     Path to config file (.json or .php extension)
     * @param array  $required List of required field names
     * @param array  $types    Field name => PHP type string (e.g., 'provider' => 'string')
     * @return array           Validated config key-value pairs
     * @throws ConfigException On file, parse, or validation errors (aggregated)
     */
    public function load(string $path, array $required = [], array $types = []): array
    {
        if (!file_exists($path)) {
            throw new ConfigException("Config file not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $config = $this->loadJson($path);
        } elseif ($extension === 'php') {
            $config = $this->loadPhpArray($path);
        } else {
            throw new ConfigException(
                "Unsupported config file format: .{$extension} (expected .json or .php)"
            );
        }

        $errors = $this->validate($config, $required, $types);

        if (!empty($errors)) {
            throw new ConfigException(
                "Config validation failed for {$path}:\n" . implode("\n", $errors)
            );
        }

        return $config;
    }

    /**
     * Load and decode a JSON config file.
     */
    private function loadJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ConfigException("Cannot read config file: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(
                "Invalid JSON in {$path}: " . $e->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new ConfigException(
                "Config file {$path} must contain a JSON object, got " . gettype($decoded)
            );
        }

        return $decoded;
    }

    /**
     * Load a PHP array config file via closure-scoped include.
     *
     * The closure prevents variables defined in the included file from
     * leaking into the caller's scope.
     */
    private function loadPhpArray(string $path): array
    {
        $config = (function () use ($path): mixed {
            return include $path;
        })();

        if (!is_array($config)) {
            throw new ConfigException(
                "Config file {$path} must return an array, got " . gettype($config)
            );
        }

        return $config;
    }

    /**
     * Validate required fields and types, returning accumulated error strings.
     *
     * Never returns the decoded config values in error messages to prevent
     * leaking sensitive fields (e.g., api_key).
     */
    private function validate(array $config, array $required, array $types): array
    {
        $errors = [];

        foreach ($required as $field) {
            if (!array_key_exists($field, $config)) {
                $errors[] = "  - Missing required field: '{$field}'";
                continue;
            }

            if (isset($types[$field])) {
                $expectedType = $types[$field];
                $actualType = gettype($config[$field]);

                if ($actualType !== $expectedType) {
                    $errors[] = "  - Field '{$field}' must be {$expectedType}, got {$actualType}";
                } elseif ($expectedType === 'string' && trim((string) $config[$field]) === '') {
                    $errors[] = "  - Field '{$field}' must be a non-empty string";
                }
            }
        }

        return $errors;
    }
}
