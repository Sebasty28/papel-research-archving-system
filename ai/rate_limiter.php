<?php
/**
 * RateLimiter - Database-backed per-user rate limiting for AI endpoints.
 *
 * Uses MySQL to track request counts per user per action within a sliding time window.
 * The table is auto-created on first use — no manual migration needed.
 *
 * Usage:
 *   require_once __DIR__ . '/rate_limiter.php';
 *   $limiter = new RateLimiter(db());
 *   $check = $limiter->check($userId, 'chatbot', 20, 600);  // 20 requests per 600 seconds
 *   if (!$check['allowed']) { echo $check['message']; exit; }
 */

class RateLimiter
{
    private $conn;
    private static $tableCreated = false;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->ensureTable();
    }

    /**
     * Check if a user is allowed to make a request.
     *
     * @param int    $userId      The user's ID
     * @param string $action      Action identifier (e.g. 'chatbot', 'ai_extract')
     * @param int    $maxRequests  Maximum requests allowed in the window
     * @param int    $windowSecs   Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'message' => string]
     */
    public function check(int $userId, string $action, int $maxRequests, int $windowSecs): array
    {
        // 1. Purge expired entries outside the window (keep table clean)
        $this->purgeOld($action, $windowSecs);

        // 2. Count requests in the current window
        $cutoff = date('Y-m-d H:i:s', time() - $windowSecs);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) as cnt FROM ai_rate_limits WHERE user_id = ? AND action = ? AND created_at>= ?"
        );
        $stmt->bind_param('iss', $userId, $action, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $count = (int) $result['cnt'];
        $stmt->close();

        $remaining = max(0, $maxRequests - $count);

        if ($count>= $maxRequests) {
            // Find when the oldest request in the window expires
            $stmtOldest = $this->conn->prepare(
                "SELECT created_at FROM ai_rate_limits WHERE user_id = ? AND action = ? AND created_at>= ? ORDER BY created_at ASC LIMIT 1"
            );
            $stmtOldest->bind_param('iss', $userId, $action, $cutoff);
            $stmtOldest->execute();
            $oldest = $stmtOldest->get_result()->fetch_assoc();
            $stmtOldest->close();

            $retryAfter = $windowSecs;
            if ($oldest) {
                $oldestTime = strtotime($oldest['created_at']);
                $retryAfter = max(1, ($oldestTime + $windowSecs) - time());
            }

            $minutes = ceil($retryAfter / 60);
            $windowMinutes = round($windowSecs / 60);

            return [
                'allowed'   => false,
                'remaining' => 0,
                'retry_after' => $retryAfter,
                'message'   => "Rate limit exceeded. You can send a maximum of {$maxRequests} requests every {$windowMinutes} minutes. Please try again in {$minutes} minute(s)."
            ];
        }

        // 3. Record this request
        $stmt = $this->conn->prepare(
            "INSERT INTO ai_rate_limits (user_id, action, created_at) VALUES (?, ?, NOW())"
        );
        $stmt->bind_param('is', $userId, $action);
        $stmt->execute();
        $stmt->close();

        return [
            'allowed'   => true,
            'remaining' => $remaining - 1,
            'message'   => ''
        ];
    }

    /**
     * Purge entries older than the window to keep the table small.
     */
    private function purgeOld(string $action, int $windowSecs): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSecs);
        $stmt = $this->conn->prepare(
            "DELETE FROM ai_rate_limits WHERE action = ? AND created_at < ?"
        );
        $stmt->bind_param('ss', $action, $cutoff);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Auto-create the rate_limits table if it doesn't exist.
     * Uses a static flag so it only runs once per request.
     */
    private function ensureTable(): void
    {
        if (self::$tableCreated) {
            return;
        }

        $this->conn->query("
            CREATE TABLE IF NOT EXISTS ai_rate_limits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_action_time (user_id, action, created_at),
                INDEX idx_action_time (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::$tableCreated = true;
    }
}
