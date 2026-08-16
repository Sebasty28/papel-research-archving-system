# Two-Level Admin Approval Workflow

## Visual Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         PAPER SUBMISSION WORKFLOW                        │
└─────────────────────────────────────────────────────────────────────────┘

    ┌──────────┐
    │ STUDENT  │
    │ Submits  │
    └────┬─────┘
         │
         ▼
    ┌─────────────┐
    │   DRAFT     │ ◄─── Can be declined back to this stage
    └─────┬───────┘
          │
          ▼
    ┌──────────────────┐
    │ STAGE 1: FACULTY │
    │ Research Adviser │
    │ pending_faculty  │
    └────────┬─────────┘
             │ Approve
             ▼
    ┌──────────────────────┐
    │ STAGE 2: ADMIN L1    │
    │ Research Coordinator │
    │ pending_admin_l1     │ ◄─── NEW STAGE
    └────────┬─────────────┘
             │ Approve
             ▼
    ┌──────────────────────┐
    │ STAGE 3: ADMIN L2    │
    │ HAP (Head Academic)  │
    │ pending_admin_l2     │ ◄─── NEW STAGE
    └────────┬─────────────┘
             │ Approve
             ▼
    ┌──────────────────────────┐
    │ STAGE 4: HEAD            │
    │ Head of Academic Affairs │
    │ pending_head_academic    │
    └────────┬─────────────────┘
             │ Approve
             ▼
    ┌──────────────────────┐
    │ STAGE 5: DIRECTOR    │
    │ Super Admin          │
    │ pending_super_admin  │
    └────────┬─────────────┘
             │ Approve
             ▼
    ┌──────────────┐
    │   APPROVED   │
    │  (Published) │
    └──────────────┘
```

## Progress Tracker Visual

```
┌─────────────────────────────────────────────────────────────────────┐
│                         PROGRESS TRACKER                             │
└─────────────────────────────────────────────────────────────────────┘

When paper is at "pending_admin_l1":

    ✅          🟡          ⚪          ⚪          ⚪
  Faculty    Admin L1    Admin L2     Head     Director
  ─────────────────────────────────────────────────────
  Completed  Current     Pending     Pending   Pending


When paper is at "pending_admin_l2":

    ✅          ✅          🟡          ⚪          ⚪
  Faculty    Admin L1    Admin L2     Head     Director
  ─────────────────────────────────────────────────────
  Completed  Completed   Current     Pending   Pending


When paper is "approved":

    ✅          ✅          ✅          ✅          ✅
  Faculty    Admin L1    Admin L2     Head     Director
  ─────────────────────────────────────────────────────
  Completed  Completed  Completed  Completed  Completed
```

## User Roles & Permissions

```
┌────────────────────────────────────────────────────────────────┐
│                      USER ROLE MATRIX                           │
├────────────────┬───────────────────┬──────────────────────────┤
│ Role           │ Can Review        │ Forwards To              │
├────────────────┼───────────────────┼──────────────────────────┤
│ Student        │ N/A               │ Submits to Faculty       │
├────────────────┼───────────────────┼──────────────────────────┤
│ Faculty        │ pending_faculty   │ Admin L1                 │
├────────────────┼───────────────────┼──────────────────────────┤
│ Admin Level 1  │ pending_admin_l1  │ Admin L2 (HAP)          │
├────────────────┼───────────────────┼──────────────────────────┤
│ Admin Level 2  │ pending_admin_l2  │ Head of Academic Affairs │
├────────────────┼───────────────────┼──────────────────────────┤
│ Head Academic  │ pending_head_...  │ Director (Super Admin)   │
├────────────────┼───────────────────┼──────────────────────────┤
│ Super Admin    │ pending_super_... │ Approved (Published)     │
└────────────────┴───────────────────┴──────────────────────────┘
```

## Database Status Values

```
┌──────────────────────────────────────────────────────────────┐
│                    STATUS ENUM VALUES                         │
├──────────────────────┬───────────────────────────────────────┤
│ Status               │ Description                           │
├──────────────────────┼───────────────────────────────────────┤
│ draft                │ Initial state / Declined              │
│ pending_faculty      │ Waiting for Faculty approval          │
│ pending_admin_l1     │ Waiting for Admin L1 approval (NEW)   │
│ pending_admin_l2     │ Waiting for Admin L2 approval (NEW)   │
│ pending_head_academic│ Waiting for Head approval             │
│ pending_super_admin  │ Waiting for Director approval         │
│ approved             │ Published in archive                  │
│ declined             │ Rejected (back to draft)              │
│ archived             │ Moved to archive                      │
└──────────────────────┴───────────────────────────────────────┘
```

## Admin Level Comparison

```
┌─────────────────────────────────────────────────────────────────┐
│              ADMIN LEVEL 1 vs ADMIN LEVEL 2                      │
├──────────────────────┬──────────────────────────────────────────┤
│ Admin Level 1        │ Admin Level 2                            │
│ (Research Coord.)    │ (HAP)                                    │
├──────────────────────┼──────────────────────────────────────────┤
│ First admin review   │ Second admin review                      │
│ Reviews after Faculty│ Reviews after Admin L1                   │
│ Checks compliance    │ Final admin verification                 │
│ Can decline to draft │ Can decline to draft                     │
│ Forwards to Admin L2 │ Forwards to Head of Academic Affairs     │
└──────────────────────┴──────────────────────────────────────────┘
```

## Notification Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    NOTIFICATION MESSAGES                         │
└─────────────────────────────────────────────────────────────────┘

Faculty Approves:
  → Student: "Paper approved by Faculty. Forwarded to Research 
              Coordinator (Level 1)."

Admin L1 Approves:
  → Student: "Paper approved by Research Coordinator (Level 1). 
              Forwarded to HAP (Level 2)."

Admin L2 Approves:
  → Student: "Paper approved by HAP (Level 2). Forwarded to 
              Head of Academic Affairs."

Head Approves:
  → Student: "Paper approved by Head of Academic Affairs. 
              Forwarded to Director."

Director Approves:
  → Student: "Paper approved by Director. Your paper is now 
              published!"
```

## Key Benefits

```
✅ Two-stage admin review ensures thorough quality control
✅ Clear separation of responsibilities
✅ HAP provides final administrative oversight
✅ Better tracking and accountability
✅ Maintains existing workflow for other roles
```

## Migration Impact

```
BEFORE:
Faculty → Admin → Head → Director → Approved
         (1 stage)

AFTER:
Faculty → Admin L1 → Admin L2 → Head → Director → Approved
         (2 stages)  (HAP)
```
