<?php
class AnalyticsService {
    public function exportAnalytics($stats, $ptStats, $filename, $isSuperAdmin = false) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '_' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        
        fputcsv($out, ['PROGRAM PERFORMANCE' . ($isSuperAdmin ? ' (APPROVED PAPERS)' : '')]);
        fputcsv($out, $isSuperAdmin ? ['Program', 'Approved Papers Count'] : ['Program', 'Total Papers', 'Approved', 'For Revision']);
        
        foreach($stats as $row) {
            if ($isSuperAdmin) fputcsv($out, [$row['program'], $row['total_papers']]);
            else fputcsv($out, [$row['program'], $row['total_papers'], $row['approved'] ?? '', $row['revisions'] ?? '']);
        }
        fputcsv($out, []);
        fputcsv($out, ['PAPER TYPE DISTRIBUTION']);
        fputcsv($out, ['Type', 'Count']);
        foreach($ptStats as $row) fputcsv($out, [$row['paper_type'], $row['count']]);
        fclose($out);
        exit;
    }

    public function getAiInsight($stats, $apiKey = null) {
        if(empty($stats) || !function_exists('generate_analytics_insight')) return '';
        try { return generate_analytics_insight($stats, $apiKey); } catch(Exception $e) { return 'AI analysis temporarily unavailable.'; }
    }
}