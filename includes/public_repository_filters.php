<?php
/**
 * The repository's public filter lists are identical for every visitor. Keep
 * one compressed snapshot in the existing system_settings table, so browsing
 * does not split and sort every approved paper's keywords on every request.
 * Publication, archive and restore refresh it; the one-hour age limit also
 * repairs a snapshot after an out-of-band database edit.
 */

const PUBLIC_REPOSITORY_FILTER_CACHE_KEY = 'public_repository_filters_v1';
const PUBLIC_REPOSITORY_FILTER_CACHE_SECONDS = 3600;

function public_repository_filter_data(mysqli $conn): array {
    try {
        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $key = PUBLIC_REPOSITORY_FILTER_CACHE_KEY;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && strncmp((string)$row['setting_value'], 'gz:', 3) === 0) {
            $packed = base64_decode(substr($row['setting_value'], 3), true);
            $json = $packed === false ? false : @gzuncompress($packed);
            $data = $json === false ? null : json_decode($json, true);
            if (is_array($data) && ($data['built_at'] ?? 0) >= time() - PUBLIC_REPOSITORY_FILTER_CACHE_SECONDS
                && isset($data['years'], $data['types'], $data['programs'], $data['keywords'])
                && array_key_exists('uncategorized', $data)
                && is_array($data['years']) && is_array($data['types'])
                && is_array($data['programs']) && is_array($data['keywords'])) {
                return $data;
            }
        }
    } catch (Throwable $e) {
        // A missing settings table on an old copy must not hide public papers.
        error_log('Repository filter cache read failed: ' . $e->getMessage());
    }
    return public_repository_filter_cache_refresh($conn);
}

function public_repository_filter_cache_refresh(mysqli $conn): array {
    $years = $conn->query("SELECT DISTINCT COALESCE(YEAR(research_date), year) AS year
                             FROM research_papers WHERE current_status = 'approved' ORDER BY year DESC")
                  ->fetch_all(MYSQLI_ASSOC);
    $types = $conn->query("SELECT DISTINCT paper_type FROM research_papers
                             WHERE current_status = 'approved' AND paper_type IS NOT NULL
                               AND paper_type != '' ORDER BY paper_type ASC")
                  ->fetch_all(MYSQLI_ASSOC);
    $used = $conn->query("SELECT DISTINCT program_category FROM research_papers
                            WHERE current_status = 'approved' AND program_category IS NOT NULL
                              AND program_category != ''")
                 ->fetch_all(MYSQLI_ASSOC);
    $programs = array_column($used, 'program_category');
    $uncategorized = (int)$conn->query("SELECT COUNT(*) FROM research_papers
                                         WHERE current_status = 'approved'
                                           AND (program_category IS NULL OR program_category = '')")
                               ->fetch_row()[0] > 0;

    $seen = [];
    $rows = $conn->query("SELECT keywords FROM research_papers
                            WHERE current_status = 'approved' AND keywords IS NOT NULL AND keywords <> ''");
    while ($row = $rows->fetch_assoc()) {
        foreach (explode(',', (string)$row['keywords']) as $keyword) {
            $keyword = trim(preg_replace('/\s+/u', ' ', $keyword));
            if ($keyword === '' || mb_strlen($keyword) < 3 || mb_strlen($keyword) > 32) { continue; }
            $key = mb_strtolower($keyword);
            if (!isset($seen[$key])) { $seen[$key] = ['label' => $keyword, 'n' => 0]; }
            $seen[$key]['n']++;
        }
    }
    uasort($seen, function ($a, $b) {
        return $b['n'] <=> $a['n'] ?: strcasecmp($a['label'], $b['label']);
    });

    $data = [
        'built_at' => time(),
        'years' => $years,
        'types' => $types,
        'programs' => $programs,
        'uncategorized' => $uncategorized,
        'keywords' => array_values($seen),
    ];

    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $packed = 'gz:' . base64_encode(gzcompress($json, 6));
        // system_settings.setting_value is TEXT. A larger list still works on
        // this request, but is rebuilt on the next one instead of truncating.
        if (strlen($packed) <= 60000) {
            $stmt = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value, description)
                                    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $key = PUBLIC_REPOSITORY_FILTER_CACHE_KEY;
            $description = 'Public repository filter snapshot';
            $stmt->bind_param('sss', $key, $packed, $description);
            $stmt->execute();
            $stmt->close();
        } else {
            public_repository_filter_cache_invalidate($conn);
            error_log('Repository filter cache exceeds system_settings TEXT size');
        }
    } catch (Throwable $e) {
        error_log('Repository filter cache write failed: ' . $e->getMessage());
    }
    return $data;
}

function public_repository_filter_cache_invalidate(mysqli $conn): void {
    $stmt = $conn->prepare('DELETE FROM system_settings WHERE setting_key = ?');
    $key = PUBLIC_REPOSITORY_FILTER_CACHE_KEY;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

/** Keep cache work from ever blocking publication or archive actions. */
function public_repository_filter_cache_after_change(mysqli $conn): void {
    try {
        public_repository_filter_cache_invalidate($conn);
        public_repository_filter_cache_refresh($conn);
    } catch (Throwable $e) {
        error_log('Repository filter cache refresh failed: ' . $e->getMessage());
    }
}
