<?php

namespace App\Domain\Semantic;

/**
 * Vectors are stored L2-normalised as little-endian float32, so cosine
 * similarity is a plain dot product.
 */
final class VectorCodec
{
    /**
     * @param list<float> $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $norm, $vector);
    }

    /**
     * @param list<float> $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $binary): array
    {
        $values = unpack('g*', $binary);

        return $values === false ? [] : array_values($values);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
