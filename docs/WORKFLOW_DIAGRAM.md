# The approval chain

What happens to a paper between a student pressing Submit and a reader finding it
in the public repository. Read from the code on 12 November 2026; every status
below is cited to the line that writes it, so this file can be checked rather
than believed.

> **This replaces a five-stage diagram.** Until today this file described a chain
> of Faculty, Admin L1, Admin L2, Head of Academic Programs and Director, each
> approving in turn. That chain was never finished and was deliberately dropped:
> approval ends at the Research Coordinator. The old diagram is in the git
> history if you need to see what was once planned.

---

## 1. The chain

Two people approve. Nobody else does.

```
   ┌─────────┐
   │ STUDENT │  app/student/student_upload_ai.php
   └────┬────┘
        │ Submit
        ▼
   ┌───────────────────┐
   │ draft             │ ◄──────────────────┐  returned, or withdrawn,
   │ nothing filed yet │                    │  or never submitted
   └────┬──────────────┘                    │
        │                                   │
        ▼                                   │
   ┌──────────────────────────┐             │
   │ pending_faculty          │             │
   │ SUBJECT ADVISER reads it │─── Return ──┤
   └────┬─────────────────────┘             │
        │ Approve                           │
        ▼                                   │
   ┌──────────────────────────────┐         │
   │ pending_admin                │         │
   │ RESEARCH COORDINATOR reads it│─ Return ┘
   └────┬─────────────────────────┘
        │ Approve
        ▼
   ┌──────────────────────────────────────┐
   │ approved                             │
   │ published in the public repository   │
   └────┬─────────────────────────────────┘
        │ the Director may archive it
        ▼
   ┌──────────┐
   │ archived │  row moved to papers_archive, restorable
   └──────────┘
```

The **Head of Academic Programs** and the **Director** read and monitor what comes
out of this. They have desks, analytics and the whole archive, and no approve
button in the chain. Adding a third approval step is a decision for the owner, not
a tidy-up: see "The approval chain" in `CLAUDE.md`.

---

## 2. Every status, and what writes it

| Status | Set by | Means |
|---|---|---|
| `draft` | the upload form's Save as draft, and every return | Never submitted, **or** sent back with feedback. The two are told apart by whether an `approval_workflow` row exists. |
| `pending_faculty` | submit, in `app/student/student_upload_ai.php` | Waiting on the Subject Adviser. |
| `pending_admin` | [faculty_review_dashboard.php:70](../app/faculty/faculty_review_dashboard.php#L70) | The adviser has approved. Waiting on the Research Coordinator. |
| `approved` | [admin_review_dashboard.php:62](../app/admin/admin_review_dashboard.php#L62), which passes `'approved'` as the target to `PaperService::approvePaper()` | Published. Readable in the public repository. |
| `archived` | [super_admin_review_dashboard.php:32](../app/admin/super_admin_review_dashboard.php#L32), Director only | Moved to `papers_archive` and restorable from line 40 of the same file. |

Both approvals go through [`PaperService::approvePaper()`](../app/models/PaperService.php),
which writes the `approval_workflow` row, sets the new status, notifies the
student, and on the final approval only stamps `analytics.time_to_approval`.

### Statuses still in the column that nothing writes

`pending_admin_l1`, `pending_admin_l2`, `pending_head_academic` and
`pending_super_admin` are the old chain. Papers filed under it still carry them,
so the readers keep accepting them: `workflow_process_statuses()` lists them, and
`workflow_progress_steps()` maps anything past the Coordinator to "awaiting
publication" rather than to a step that no longer exists
([config/workflow.php:73-81](../config/workflow.php#L73-L81)). Do not write one.

---

## 3. Going backwards

### Return, by either reviewer

`PaperService::declinePaper()` puts the paper back to `draft`, attaches the
feedback to its `approval_workflow` row, and **deletes both manuscript files from
Drive** (`purge_paper_drive_files()`), because the student is going to attach a
corrected file anyway. So a returned paper is a draft with feedback and no PDF.

This is why `draft` cannot be read as "never handed in". Analytics counts a
submission as:

```sql
current_status <> 'draft' OR EXISTS (SELECT 1 FROM approval_workflow ...)
```

### Withdraw, by the author

`app/student/student_cancel_submission.php`, ruled on by
`submission_cancel_state()` in `config/workflow.php`. The window is **asymmetric**,
and deliberately so: 24 hours either way (`SUBMISSION_CANCEL_HOURS`).

| The paper is with | The window |
|---|---|
| the Subject Adviser | **Closes** 24 hours after it reached them. Mistakes are caught early; after that, ask the adviser to return it. |
| the Research Coordinator | **Opens** 24 hours after the adviser approved. The adviser's approval is not to be undone on a whim, but a paper nobody has looked at is not to be stuck either. |

Withdrawing deletes only the `pending` workflow row, keeps the approvals already
given, and keeps the Drive files. The window is re-checked in the handler, not
trusted from the page, since the button may have been drawn hours earlier.

---

## 4. One person, two posts

A member of staff may hold a second position (`users.secondary_position`), a
Research Coordinator who also advises being the case that prompted it. Such a
person may approve at **both** steps of this chain, which the owner chose
deliberately on 12 November 2026. For those papers the second step adds nothing,
since one person signs off their own review.

The place to change that, if it is ever to change, is `config/workflow.php`.

---

## 5. Who sits where

| Role | Desk | In the chain? |
|---|---|---|
| Student | `app/student/student_dashboard.php` | Submits, withdraws, revises |
| Subject Adviser (`faculty`) | `app/faculty/faculty_review_dashboard.php` | **Approves, step 1** |
| Research Coordinator (`admin`, level 1) | `app/admin/admin_review_dashboard.php` | **Approves, step 2, publishes** |
| Head of Academic Programs (`admin` level 2, or `head_academic`) | `app/faculty/head_review_dashboard.php` | Reads and monitors |
| Director (`super_admin`) | `app/admin/super_admin_review_dashboard.php` | Reads, archives, restores |
| Librarian | `app/librarian/librarian_manage_guests.php` | Not in the chain; grants manuscript access |

All four review desks are one include, `includes/review_console.php`, configured
with `$RC`. The progress bar a student sees is `workflow_progress_steps()`, whose
three labels are Subject Adviser, Research Coordinator, Approved.

> `includes/progress_tracker.php` still draws the retired four-stage tracker.
> Nothing requires it. Do not start.
