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

namespace App\Output;

/**
 * ANSI formatting utility for CLI output.
 *
 * Provides color constants and helper methods shared between
 * research.php and repl.php for consistent terminal output.
 *
 * Readline-safe variants (RL_*) use \x01/\x02 wrapping to prevent
 * cursor-position miscounting in readline() prompts (Pitfall 1).
 *
 * @package App\Output
 */
class Formatter
{
    // Foreground colors
    public const GREEN  = "\e[32m";
    public const CYAN   = "\e[36m";
    public const YELLOW = "\e[33m";
    public const RED    = "\e[31m";
    public const WHITE  = "\e[37m";
    public const RESET  = "\e[0m";

    // Text styles
    public const BOLD   = "\e[1m";
    public const DIM    = "\e[2m";

    // Readline-safe variants (\x01/\x02 wrapped for cursor positioning)
    public const RL_CYAN  = "\x01\e[36m\x02";
    public const RL_BOLD  = "\x01\e[1m\x02";
    public const RL_RESET = "\x01\e[0m\x02";

    /**
     * Format a section header: Bold Cyan.
     */
    public static function section(string $title): string
    {
        return self::BOLD . self::CYAN . $title . self::RESET;
    }

    /**
     * Format a winner name: Bold Green.
     */
    public static function winner(string $name): string
    {
        return self::BOLD . self::GREEN . $name . self::RESET;
    }

    /**
     * Format a score value colored by threshold.
     *
     * Green for >= 8, Yellow for >= 5, Red for < 5.
     * Values are formatted to 1 decimal place.
     */
    public static function score(float $value): string
    {
        $formatted = number_format($value, 1);
        return match (true) {
            $value >= 8.0 => self::GREEN . $formatted . self::RESET,
            $value >= 5.0 => self::YELLOW . $formatted . self::RESET,
            default       => self::RED . $formatted . self::RESET,
        };
    }

    /**
     * Format an error message: Dim Red.
     */
    public static function error(string $msg): string
    {
        return self::DIM . self::RED . $msg . self::RESET;
    }

    /**
     * Format an agent name with a cycled color based on crc32 hash.
     *
     * Colors cycle through GREEN, CYAN, YELLOW for variety.
     */
    public static function agentName(string $name): string
    {
        $colors = [self::GREEN, self::CYAN, self::YELLOW];
        $index = abs(crc32($name)) % count($colors);
        return $colors[$index] . $name . self::RESET;
    }

    /**
     * Format a command name: Bold Yellow.
     */
    public static function command(string $cmd): string
    {
        return self::BOLD . self::YELLOW . $cmd . self::RESET;
    }

    /**
     * Generate a dimmed separator line of 72 dashes.
     */
    public static function separator(): string
    {
        return self::DIM . str_repeat('-', 72) . self::RESET;
    }

    /**
     * Get the readline-safe prompt string: bold cyan "research> ".
     *
     * Uses \x01/\x02 wrapped codes so readline correctly tracks cursor position.
     */
    public static function prompt(): string
    {
        return self::RL_CYAN . 'research> ' . self::RL_RESET;
    }

    /**
     * Format a session date by recency.
     *
     * Green if less than 1 hour old, Yellow if less than 1 day, Dim otherwise.
     *
     * @param  string $dateIso ISO 8601 date string
     * @return string          Colored date string
     */
    public static function sessionAge(string $dateIso): string
    {
        try {
            $date = new \DateTimeImmutable($dateIso);
            $now = new \DateTimeImmutable();
            $diffSeconds = $now->getTimestamp() - $date->getTimestamp();
        } catch (\Throwable) {
            return self::DIM . $dateIso . self::RESET;
        }

        return match (true) {
            $diffSeconds < 3600  => self::GREEN . $dateIso . self::RESET,  // < 1 hour
            $diffSeconds < 86400 => self::YELLOW . $dateIso . self::RESET, // < 1 day
            default              => self::DIM . $dateIso . self::RESET,    // older
        };
    }

    /**
     * Format a progress status line with colored agent name and event text.
     *
     * @param  string $agent Agent name
     * @param  string $event Event description
     * @return string        Formatted status line
     */
    public static function statusLine(string $agent, string $event): string
    {
        return '  [' . self::agentName($agent) . '] ' . $event . self::RESET;
    }
}
