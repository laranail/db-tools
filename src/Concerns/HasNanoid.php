<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

/**
 * Auto-set a NanoID on the configured column at creating time.
 *
 * Default: 21-character URL-safe alphabet (the standard NanoID size,
 * collision probability ~1 per ~149 billion years at 1k IDs/hour).
 *
 * Override `nanoidColumn()` and/or `nanoidLength()` to customize.
 *
 * No external dependency — uses random_bytes() + a tight alphabet.
 */
trait HasNanoid
{
    private const NANOID_ALPHABET = '_-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function bootHasNanoid(): void
    {
        static::creating(static function ($model): void {
            $column = method_exists($model, 'nanoidColumn') ? $model->nanoidColumn() : 'nanoid';

            if (empty($model->{$column})) {
                $length = method_exists($model, 'nanoidLength') ? $model->nanoidLength() : 21;
                $model->{$column} = self::generateNanoid($length);
            }
        });
    }

    public function nanoidColumn(): string
    {
        return defined(static::class.'::NANOID_COLUMN') ? static::NANOID_COLUMN : 'nanoid';
    }

    public function nanoidLength(): int
    {
        return defined(static::class.'::NANOID_LENGTH') ? static::NANOID_LENGTH : 21;
    }

    private static function generateNanoid(int $length): string
    {
        $alphabet = self::NANOID_ALPHABET;
        $alphabetLen = strlen($alphabet);
        // Mask trick: smallest power-of-2 minus 1 that's >= alphabet size,
        // ensures uniform distribution at the cost of occasional rejection.
        $mask = (2 << (int) floor(log($alphabetLen - 1) / log(2))) - 1;
        $step = (int) ceil(1.6 * $mask * $length / $alphabetLen);

        $id = '';
        while (true) {
            $bytes = random_bytes($step);
            for ($i = 0; $i < $step; $i++) {
                $byte = ord($bytes[$i]) & $mask;
                if ($byte < $alphabetLen) {
                    $id .= $alphabet[$byte];
                    if (strlen($id) === $length) {
                        return $id;
                    }
                }
            }
        }
    }
}
