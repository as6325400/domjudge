# Virtual Contest Feature - Implementation Plan

## Overview

Add a "Virtual Participation" mode to DOMjudge contests, similar to Codeforces virtual contests. Users can start a contest at any time (during or after the original contest) and experience it as if they were participating live, with ghost/shadow data from earlier participants.

---

## Design Decisions (Confirmed)

- **Architecture**: Same contest entity with a `VirtualParticipation` layer (not shadow contests)
- **Scoreboard time**: All relative (submittime - participant's own starttime)
- **Multiple virtuals**: Allowed (unlimited), but only one active at a time per team
- **Original participant can re-virtual**: Yes, shown as separate row (`TeamA (virtual #1)`)
- **Visibility rule**: Can only see participants who started earlier than you
- **Scoreboard filter**: User-switchable filter (LIVE / VIRTUAL / ALL), affects ranking + "First to solve"
- **Ghost behavior**: Dynamic replay (Codeforces-style) — at your relative minute N, you only see what ghosts had done by their minute N
- **Freeze for virtual**: No freeze. Virtual participants see full ghost data
- **Clarifications**: Virtual can view (at same relative timestamp), cannot send
- **Post-contest public scoreboard**: Only shows completed participations (LIVE + finished VIRTUAL)
- **Zero-submission hiding**: Hidden post-contest; hidden from virtual participants; shown during live contest on public board
- **Virtual mid-contest**: Allowed. LIVE participants cannot see virtual participants

---

## New Entity: `VirtualParticipation`

**File**: `webapp/src/Entity/VirtualParticipation.php`
**Table**: `virtual_participation`

| Column | Type | Description |
|--------|------|-------------|
| `vpid` | int (PK, auto) | Primary key |
| `cid` | int (FK → contest) | Contest reference |
| `teamid` | int (FK → team) | Team reference |
| `virtual_starttime` | decimal(32,9) | When this virtual participation started (wall clock) |
| `virtual_number` | int | 1-indexed counter per team per contest (virtual #1, #2...) |
| `is_completed` | bool | Whether the virtual duration has elapsed |

**Indexes**:
- UNIQUE `(cid, teamid, virtual_number)`
- INDEX `(cid, teamid, virtual_starttime)` — for querying "who started before me"
- INDEX `(cid, is_completed)` — for filtering completed participations

**Key methods**:
- `getVirtualEndtime(): float` — returns `virtual_starttime + contest_duration`
- `isActive(): bool` — returns `now >= virtual_starttime && now < virtual_endtime`
- `getRelativeTime(float $wallTime): float` — returns `wallTime - virtual_starttime`
- `getContestDuration(): float` — returns `contest.endtime - contest.starttime`

---

## Database Migration

**File**: `webapp/migrations/VersionYYYYMMDDHHMMSS.php`

### Schema changes:

1. **Create `virtual_participation` table** (see entity above)

2. **Add to `contest` table**:
   - `allow_virtual` BOOLEAN DEFAULT false — whether virtual participation is enabled

3. **Add to `submission` table**:
   - `vpid` INT NULLABLE (FK → virtual_participation) — links submission to virtual participation (NULL = LIVE submission)

4. **Modify `scorecache` table**:
   - Add `vpid` INT NULLABLE — to cache scores per virtual participation
   - Change PK from `(cid, teamid, probid)` to `(cid, teamid, probid, vpid)` (vpid=0 for LIVE)

5. **Modify `rankcache` table**:
   - Add `vpid` INT NULLABLE — to cache ranks per virtual participation
   - Change PK from `(cid, teamid)` to `(cid, teamid, vpid)` (vpid=0 for LIVE)

---

## Modified Files & Changes

### 1. Entity Layer

#### `webapp/src/Entity/Contest.php`
- Add `allowVirtual` property (bool, default false)
- Add `virtualParticipations` OneToMany relation
- Add method `getAllowVirtual(): bool`
- Add method `getDuration(): float` (endtime - starttime)
- Add method `getActiveVirtualParticipation(Team $team): ?VirtualParticipation`

#### `webapp/src/Entity/Submission.php`
- Add `virtualParticipation` ManyToOne relation (nullable)
- Add `getVirtualParticipation(): ?VirtualParticipation`
- Add `isVirtual(): bool`

#### `webapp/src/Entity/ScoreCache.php`
- Add `virtualParticipation` field to composite key
- Update getters to account for vpid

#### `webapp/src/Entity/RankCache.php`
- Add `virtualParticipation` field to composite key
- Update getters to account for vpid

### 2. Service Layer

#### `webapp/src/Service/ScoreboardService.php` ← **LARGEST CHANGE**

**`getScoreboard()` modifications**:
- Accept optional `?VirtualParticipation $viewerVp` parameter
- When viewer is virtual:
  - Collect all LIVE teams + all VPs with `virtual_starttime < viewer's virtual_starttime`
  - For each visible participation, compute ghost cutoff: `viewer_relative_time` = time elapsed since viewer's VP start
  - Filter ScoreCache data: only include entries where the ghost's relative solve time <= viewer's current relative time
  - Build separate score rows for each VP (same team can appear multiple times)
- When viewer is LIVE:
  - Behavior unchanged (no virtual participants visible)
- When viewer is PUBLIC (post-contest):
  - Show all completed LIVE + completed VIRTUAL participations
  - Hide zero-submission entries

**`calculateScoreRow()` modifications**:
- Accept optional `?VirtualParticipation $vp` parameter
- When VP is set:
  - Filter submissions by `vpid`
  - Calculate solvetime relative to `vp.virtual_starttime` instead of `contest.starttime`
  - Store in ScoreCache with vpid
- "First to solve" determination respects the current filter context

**`getScoreboardTwigData()` modifications**:
- Add `filter` parameter (LIVE / VIRTUAL / ALL)
- Add `viewerVp` parameter for ghost time cutoff
- Pass filter info to template

**New method `getGhostScoreboard()`**:
- Given a viewer VP and current time, build a scoreboard showing:
  - Ghost data up to the viewer's current relative time
  - Proper ranking based on visible data only

#### `webapp/src/Service/SubmissionService.php`

**`submitSolution()` modifications**:
- Check if team has an active VirtualParticipation for this contest
- If virtual: validate against VP's virtual time window (not contest's absolute times)
- Set `submission.virtualParticipation = activeVP`
- After judging, call `calculateScoreRow()` with VP context

**Time validation logic change**:
```
IF team has active VP for this contest:
    valid = (now >= vp.virtual_starttime) && (now < vp.virtual_endtime)
ELSE:
    valid = existing logic (contest starttime/endtime)
```

#### `webapp/src/Service/VirtualContestService.php` ← **NEW FILE**

New service to manage virtual participation lifecycle:

- `startVirtualParticipation(Contest $contest, Team $team): VirtualParticipation`
  - Validate: contest.allowVirtual is true
  - Validate: no active VP exists for this team **in this contest** (different contests are independent)
  - Calculate virtual_number (max existing + 1)
  - Create and persist VirtualParticipation entity
  - Return the new VP

- `getActiveVirtualParticipation(Contest $contest, Team $team): ?VirtualParticipation`
  - Query for active (not completed) VP

- `checkAndCompleteExpiredParticipations(): void`
  - Cron/event-driven: mark VPs as completed when time expires

- `getVisibleParticipations(Contest $contest, VirtualParticipation $viewerVp): array`
  - Return all LIVE teams + VPs with starttime < viewer's starttime

- `canStartVirtual(Contest $contest, Team $team): bool`
  - Check all preconditions

### 3. Controller Layer

#### `webapp/src/Controller/Team/VirtualContestController.php` ← **NEW FILE**

- `startAction(Contest): Response` — "Join Virtual" button handler
  - Show confirmation page with contest info
  - On confirm: call VirtualContestService::startVirtualParticipation()
  - Redirect to contest problem list

- `statusAction(Contest): JsonResponse` — return remaining virtual time for timer

#### `webapp/src/Controller/Team/SubmissionController.php`
- `createAction()`: detect active VP, pass to submitSolution

#### `webapp/src/Controller/Team/ScoreboardController.php`
- `scoreboardAction()`: pass filter parameter and viewer VP to service
- Add filter switching (LIVE / VIRTUAL / ALL) via query parameter

#### `webapp/src/Controller/Team/ClarificationController.php`
- For virtual participants:
  - Show clarifications where `clarification.submittime` (relative to contest start) <= viewer's current relative time
  - Disable the "send clarification" form

#### `webapp/src/Controller/Team/MiscController.php`
- Show virtual contest status on team dashboard
- Show "Join Virtual" button for eligible contests

#### `webapp/src/Controller/Jury/ContestController.php`
- Add `allowVirtual` field to contest edit form
- Show virtual participation stats in contest view

#### `webapp/src/Controller/PublicController.php` (or equivalent)
- Post-contest scoreboard: include completed virtual participations
- Respect zero-submission hiding rule

### 4. Form Layer

#### `webapp/src/Form/Type/ContestType.php`
- Add `allowVirtual` checkbox field

### 5. Template Layer

#### `webapp/templates/partials/scoreboard_table.html.twig`
- Add filter buttons (LIVE / VIRTUAL / ALL) at top of scoreboard
- Add visual indicator for virtual participants (e.g., `(virtual #1)` suffix, different row color/icon)
- "First to solve" marker respects current filter

#### `webapp/templates/team/scoreboard.html.twig`
- Include filter UI
- If viewer is virtual: show virtual contest timer (time remaining based on VP start)

#### `webapp/templates/team/misc/home.html.twig` (or equivalent dashboard)
- Show "Join Virtual" button for contests with allowVirtual=true
- Show active virtual participation status with timer

#### `webapp/templates/team/virtual_start.html.twig` ← **NEW FILE**
- Confirmation page before starting virtual contest
- Show contest name, duration, number of problems
- Warning: "Once started, cannot be paused"

#### `webapp/templates/team/clarification*.html.twig`
- Conditionally hide "Send clarification" button for virtual participants

#### `webapp/templates/jury/contest.html.twig`
- Show allowVirtual toggle in contest form
- Show virtual participation statistics

### 6. Utils Layer

#### `webapp/src/Utils/FreezeData.php`
- No changes needed for virtual (virtual participants don't have freeze)
- But scoreboard rendering needs to bypass freeze when building ghost data

#### `webapp/src/Utils/Scoreboard/Scoreboard.php`
- Modify constructor to accept participation type metadata
- `calculateScoreboard()`: handle multiple entries for same team (different VPs)
- Add method to apply ghost time filter

#### `webapp/src/Utils/Scoreboard/TeamScore.php`
- Add `?VirtualParticipation $virtualParticipation` property
- Add `getDisplayName(): string` — returns "TeamName (virtual #N)" if virtual

---

## Implementation Order (Suggested)

### Phase 1: Data Layer
1. Create `VirtualParticipation` entity
2. Write database migration
3. Modify `Contest` entity (add allowVirtual)
4. Modify `Submission` entity (add VP relation)
5. Modify `ScoreCache` and `RankCache` entities (add VP to keys)

### Phase 2: Core Services
6. Create `VirtualContestService`
7. Modify `SubmissionService` — VP-aware submission validation
8. Modify `ScoreboardService` — VP-aware score calculation (non-ghost, basic)

### Phase 3: Team-Side UI
9. Create `VirtualContestController` (start virtual)
10. Modify team dashboard (show join button, VP status)
11. Modify team submission flow (VP-aware)
12. Modify team clarification view (read-only for virtual, time-gated)

### Phase 4: Scoreboard
13. Implement ghost time filtering in ScoreboardService
14. Add scoreboard filter UI (LIVE / VIRTUAL / ALL)
15. Modify scoreboard templates (VP indicators, filter buttons)
16. Virtual contest timer in team view

### Phase 5: Jury & Public
17. Jury contest form (allowVirtual toggle)
18. Public scoreboard (post-contest: show completed VPs)

### Phase 6: Polish
19. Auto-complete expired VPs (command or event)
20. Edge cases: team in multiple VPs across contests, contest time extensions
21. API endpoints for virtual participation (optional)

---

## Key Edge Cases to Handle

1. **Team has active VP in contest A, tries to start VP in contest B**: **Allowed** — restriction is per-contest only, different contests are independent
2. **Contest time extended after VP started**: VP duration should be based on original duration at VP start time. Store duration in VP entity
3. **Team submits after VP ends but contest is still active**: Reject (VP time window is what matters)
4. **Jury disables allowVirtual while VPs are active**: Active VPs should be allowed to complete
5. **Contest deleted with active VPs**: CASCADE delete handles this
6. **Scoreboard cache invalidation**: VP score changes only affect VP-specific cache rows
