<?php

declare(strict_types=1);

/**
 * Minimal .env loader — no Composer/vendor dependency, matching the
 * "Core PHP" project style. Loads KEY=VALUE pairs into getenv()/$_ENV.
 */
function env_load(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!is_file($path)) {
        $example = dirname($path) . '/.env.example';
        $path = is_file($example) ? $example : $path;
    }

    if (!is_file($path)) {
        $loaded = true;
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    $loaded = true;
}

function env_get(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return match (strtolower($value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        'empty' => '',
        default => $value,
    };
}
