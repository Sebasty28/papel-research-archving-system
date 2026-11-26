<?php
/** Shared by viewers; serialize each chart so simultaneous refreshes spend once. */
function analytics_insight_interval(string $key): int {
    return strpos($key, 'overall-') === 0 ? 3600 : 86400;
}

function analytics_chart_insight(string $scope, string $key, array $set, bool $force = false): array {
    $interval = analytics_insight_interval($key);
    if (!$set['data']) return ['text' => '', 'expires' => time() + $interval];
    $dir = sys_get_temp_dir() . '/papel-insights-' . hash('sha256', __DIR__);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Insight cache is unavailable.');
    }
    $file = $dir . '/' . hash('sha256', 'v1|' . $scope . '|' . $key) . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) throw new RuntimeException('Insight cache is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Insight cache is busy.');
        $cached = json_decode(stream_get_contents($handle), true);
        // Reuse existing hourly entries for the longer chart schedule.
        if (is_array($cached)) {
            $cached['expires'] = ($cached['expires'] ?? 0) + $interval - ($cached['interval'] ?? 3600);
            $cached['interval'] = $interval;
        }
        if (!$force && is_array($cached) && ($cached['expires'] ?? 0) > time()) return $cached;
        $text = generate_chart_insight($set['label'], $set['data']);
        // Retain the last good insight and back off for the same interval on failure.
        $entry = ['text' => $text !== '' ? $text : ($cached['text'] ?? ''),
                  'expires' => time() + $interval, 'interval' => $interval, 'failed' => $text === ''];
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($entry));
        fflush($handle);
        return $entry;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
