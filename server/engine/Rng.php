<?php
// Seeded, serializable RNG (xorshift128, 32-bit). State lives inside the game
// state JSON so every game is replayable from its seed + action log.

final class Rng
{
    /** @return array{0:int,1:int,2:int,3:int} */
    public static function seedState(int $seed): array
    {
        $st = [];
        $x = $seed & 0xFFFFFFFF;
        if ($x === 0) { $x = 0x9E3779B9; }
        for ($i = 0; $i < 4; $i++) {
            $x ^= ($x >> 16);
            $x = ($x * 0x45D9F3B) & 0xFFFFFFFF;
            $x ^= ($x >> 16);
            $x = ($x * 0x45D9F3B) & 0xFFFFFFFF;
            $x ^= ($x >> 16);
            if ($x === 0) { $x = 0x1234567 + $i; }
            $st[] = $x;
        }
        return $st;
    }

    public static function next(array &$s): int
    {
        $t = $s[3];
        $sx = $s[0];
        $s[3] = $s[2];
        $s[2] = $s[1];
        $s[1] = $sx;
        $t = ($t ^ (($t << 11) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $t ^= ($t >> 8);
        $s[0] = ($t ^ $sx ^ ($sx >> 19)) & 0xFFFFFFFF;
        return $s[0];
    }

    /** Uniform int in [0, $n). Rejection sampling: no modulo bias. */
    public static function int(array &$s, int $n): int
    {
        if ($n <= 1) { return 0; }
        $limit = intdiv(0x100000000, $n) * $n;
        do {
            $v = self::next($s);
        } while ($v >= $limit);
        return $v % $n;
    }

    /** Fisher-Yates; returns a new shuffled array (list in, list out). */
    public static function shuffle(array &$s, array $arr): array
    {
        $arr = array_values($arr);
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = self::int($s, $i + 1);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }
        return $arr;
    }
}
