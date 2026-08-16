<?php

function workflow_statuses(): array {
    return [
        'draft',
        'pending_faculty',
        'pending_admin',
        'pending_admin_l1',
        'pending_admin_l2',
        'pending_head_academic',
        'pending_super_admin',
        'approved',
        'declined',
        'archived'
    ];
}

function workflow_stage_definitions(): array {
    return [
        1 => ['label' => 'Faculty', 'statuses' => ['pending_faculty']],
        2 => ['label' => 'Research Coordinator', 'statuses' => ['pending_admin_l1', 'pending_admin']],
        3 => ['label' => 'HAP', 'statuses' => ['pending_head_academic', 'pending_admin_l2']],
        4 => ['label' => 'Director', 'statuses' => ['pending_super_admin', 'approved']],
    ];
}

function workflow_process_statuses(): array {
    return ['pending_faculty', 'pending_admin', 'pending_admin_l1', 'pending_admin_l2', 'pending_head_academic', 'pending_super_admin'];
}

function workflow_final_statuses(): array {
    return ['approved', 'declined', 'archived'];
}

function workflow_is_process_status(string $status): bool {
    return in_array($status, workflow_process_statuses(), true);
}

function workflow_is_final_status(string $status): bool {
    return in_array($status, workflow_final_statuses(), true);
}

function workflow_status_category(string $status, bool $hasFeedback = false): string {
    if ($status === 'approved') {
        return 'all approved';
    }

    if ($status === 'draft' && $hasFeedback) {
        return 'all declined';
    }

    if ($status === 'draft') {
        return 'all draft';
    }

    if (workflow_is_process_status($status)) {
        return 'all process';
    }

    return 'all';
}

function workflow_status_badge_text(string $status, bool $hasFeedback = false): string {
    if ($status === 'approved') {
        return 'APPROVED';
    }
    if ($status === 'draft' && $hasFeedback) {
        return 'DECLINED';
    }
    if ($status === 'draft') {
        return 'DRAFT';
    }
    return 'ON PROCESS';
}

/**
 * Steps for the per-paper progress tracker, as [label, state] pairs where
 * state is done | current | todo.
 *
 * The review chain is: Research Adviser (the faculty member who created the
 * student's account) -> Research Coordinator -> Approved. The Coordinator is
 * the final approval gate; the Head of Academic Programs and the Director do
 * not approve, they only view what has already been approved.
 *
 * The legacy pending_head_academic / pending_super_admin statuses can still
 * exist on papers that were mid-flight before this change, so they are mapped
 * onto the final node rather than dropped.
 */
function workflow_progress_steps(string $status, bool $hasFeedback = false): array {
    $labels = ['Research Adviser', 'Research Coordinator', 'Approved'];

    if ($status === 'approved')                        $reached = 3;
    // Past the Coordinator under the old chain — awaiting publication.
    elseif (in_array($status, ['pending_head_academic', 'pending_admin_l2', 'pending_super_admin'], true)) $reached = 3;
    elseif (in_array($status, ['pending_admin', 'pending_admin_l1'], true)) $reached = 2;
    elseif ($status === 'pending_faculty')             $reached = 1;
    else                                               $reached = 0; // draft / returned

    $steps = [];
    foreach ($labels as $i => $label) {
        $n = $i + 1;
        if ($status === 'approved')  $state = 'done';
        elseif ($n < $reached)       $state = 'done';
        elseif ($n === $reached)     $state = 'current';
        else                         $state = 'todo';
        $steps[] = ['label' => $label, 'state' => $state];
    }
    return $steps;
}

function workflow_status_badge_class(string $status, bool $hasFeedback = false): string {
    if ($status === 'approved') {
        return 'approved';
    }
    if ($status === 'draft' && $hasFeedback) {
        return 'declined';
    }
    if ($status === 'draft') {
        return 'draft';
    }
    return 'on-process';
}

/**
 * How long a student has to withdraw a submission after it reaches a reviewer.
 */
const SUBMISSION_CANCEL_HOURS = 24;

/**
 * Whether a submitted paper can still be withdrawn by its author.
 *
 * The two stages work in opposite directions, deliberately:
 *
 *   - Waiting on the Research Adviser: withdrawing is allowed for the first 24
 *     hours. A grace period for second thoughts, which closes so the adviser is
 *     not reviewing a moving target.
 *
 *   - Adviser approved, waiting on the Research Coordinator: withdrawing
 *     unlocks only once the Coordinator has held it for 24 hours. The paper has
 *     already earned an approval, so it is not pulled back on a whim - the
 *     option appears when it has genuinely been left waiting.
 *
 * Past the Coordinator the paper is published, and withdrawing is no longer the
 * student's call.
 *
 * @return array{allowed:bool, stage:?string, started:?int, deadline:?int, unlocks:?int, reason:string}
 */
function submission_cancel_state(array $paper, mysqli $conn): array {
    $blank = ['allowed' => false, 'stage' => null, 'started' => null,
              'deadline' => null, 'unlocks' => null, 'reason' => ''];

    $paperId = (int)($paper['paper_id'] ?? 0);
    $status  = (string)($paper['current_status'] ?? '');
    if ($paperId <= 0) {
        return array_merge($blank, ['reason' => 'Unknown paper.']);
    }

    if ($status === 'pending_faculty') {
        $stage = 'faculty';
        // The clock starts when the paper was handed to the adviser.
        $q = $conn->prepare(
            "SELECT UNIX_TIMESTAMP(submitted_at) AS t
               FROM approval_workflow
              WHERE paper_id = ? AND review_level = 'faculty' AND status = 'pending'
              ORDER BY workflow_id DESC LIMIT 1"
        );
        $q->bind_param('i', $paperId);
    } elseif ($status === 'pending_admin' || $status === 'pending_admin_l1') {
        $stage = 'coordinator';
        // The clock starts when the adviser approved and passed it on.
        $q = $conn->prepare(
            "SELECT UNIX_TIMESTAMP(reviewed_at) AS t
               FROM approval_workflow
              WHERE paper_id = ? AND review_level = 'faculty' AND status = 'approved'
              ORDER BY workflow_id DESC LIMIT 1"
        );
        $q->bind_param('i', $paperId);
    } else {
        return array_merge($blank, ['reason' => 'This paper is no longer waiting on a reviewer.']);
    }

    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $started = $row && $row['t'] ? (int)$row['t'] : null;

    if ($started === null) {
        // Nothing to measure from - fall back to the upload date rather than
        // leaving the window unbounded in either direction.
        $started = !empty($paper['upload_date']) ? strtotime($paper['upload_date']) : null;
        if (!$started) {
            return array_merge($blank, ['stage' => $stage, 'reason' => 'This submission cannot be withdrawn.']);
        }
    }

    $mark = $started + (SUBMISSION_CANCEL_HOURS * 3600);

    if ($stage === 'faculty') {
        // A window that closes.
        $allowed = time() < $mark;
        return [
            'allowed'  => $allowed,
            'stage'    => $stage,
            'started'  => $started,
            'deadline' => $mark,
            'unlocks'  => null,
            'reason'   => $allowed ? '' :
                'The ' . SUBMISSION_CANCEL_HOURS . '-hour window to withdraw this submission has passed. '
                . 'Ask your Research Adviser if you need it returned.',
        ];
    }

    // A window that opens: the Coordinator has to have had it long enough.
    $allowed = time() >= $mark;
    return [
        'allowed'  => $allowed,
        'stage'    => $stage,
        'started'  => $started,
        'deadline' => null,
        'unlocks'  => $mark,
        'reason'   => $allowed ? '' :
            'Your Research Adviser has approved this. You will be able to withdraw it if the '
            . 'Research Coordinator has not acted by ' . date('M j, g:i a', $mark) . '.',
    ];
}

/** Who should be told that a submission was withdrawn. */
function submission_cancel_audience(int $paperId, int $authorId, mysqli $conn): array {
    $ids = [];

    // Everyone who has acted on this paper, or is waiting to.
    $q = $conn->prepare("SELECT DISTINCT reviewer_id FROM approval_workflow WHERE paper_id = ?");
    $q->bind_param('i', $paperId);
    $q->execute();
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $ids[] = (int)$r['reviewer_id'];
    }

    // The adviser is told even if they had not opened it yet.
    $adviser = creator_of($authorId);
    if ($adviser) $ids[] = (int)$adviser;

    // Never notify the student about their own action.
    return array_values(array_filter(array_unique($ids), function ($id) use ($authorId) {
        return $id > 0 && $id !== $authorId;
    }));
}
