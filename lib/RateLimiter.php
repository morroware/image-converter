<?php
declare(strict_types=1);

namespace Castle;

/**
 * Lightweight file-backed per-IP rate limiter.
 * Stores counters in a single JSON file; auto-prunes stale buckets.
 * No database required.
 */
final class RateLimiter
{
    private string $storePath;
    private int $limitPerMin;

    public function __construct(?string $storePath = null, ?int $limitPerMin = null)
    {
        $this->storePath = $storePath ?? (CASTLE_OUTPUT_DIR . '/.ratelimit.json');
        $this->limitPerMin = $limitPerMin ?? (int)CASTLE_RATE_LIMIT_PER_MIN;
    }

    public function hit(): bool
    {
        if ($this->limitPerMin <= 0) {
            return true; // disabled
        }

        $ip     = $this->clientIp();
        $bucket = (int) floor(time() / 60); // 1-minute buckets
        $data   = $this->load();

        // Prune old buckets (> 1 min).
        foreach ($data as $k => $row) {
            if (!is_array($row) || ($row['b'] ?? 0) < $bucket) {
                unset($data[$k]);
            }
        }

        $entry = $data[$ip] ?? ['b' => $bucket, 'c' => 0];
        if ($entry['b'] !== $bucket) {
            $entry = ['b' => $bucket, 'c' => 0];
        }
        $entry['c']++;

        $data[$ip] = $entry;
        $this->save($data);

        return $entry['c'] <= $this->limitPerMin;
    }

    public function remaining(): int
    {
        $ip = $this->clientIp();
        $data = $this->load();
        $bucket = (int) floor(time() / 60);
        $row = $data[$ip] ?? null;
        if (!$row || ($row['b'] ?? 0) !== $bucket) {
            return $this->limitPerMin;
        }
        return max(0, $this->limitPerMin - (int)$row['c']);
    }

    private function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP']  ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR']   ?? null,
            $_SERVER['REMOTE_ADDR']            ?? null,
        ];
        foreach ($candidates as $c) {
            if (!$c) continue;
            $first = trim(explode(',', $c)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return '0.0.0.0';
    }

    private function load(): array
    {
        if (!is_file($this->storePath)) return [];
        $raw = @file_get_contents($this->storePath);
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function save(array $data): void
    {
        $dir = dirname($this->storePath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($this->storePath, json_encode($data), LOCK_EX);
    }
}
