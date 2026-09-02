# COVERAGE MATRIX — Liwonde Sun Hotel 2026

Owned by `build-planner` (Status column) and `codebase-scout` (Mapped column).
One row per core functional domain in `.claude/CORE_SYSTEM_BRIEF.md`. This is how the build
loop guarantees it touches **every aspect of the system**, not just the loud parts.

Status values: `unswept` · `mapped` (SYSTEM_MAP.md section exists) · `partial` (some tasks
done, gaps remain) · `swept` (all checklist items for this domain done + QA-passed).

Nothing has been mapped or swept yet — this repo was forked from the Rosalyn platform and
has had no build-loop pass of its own. Every domain starts at `unswept`, so the first
planning cycle must open with `codebase-scout` runs, not build tasks.

| # | Domain | Mapped | Status | Last touched | Open gaps |
|---|--------|--------|--------|--------------|-----------|
| 1 | Booking engine | no | unswept | 2026-09-02 | R2: `booking.php` has ZERO ballena/editorial classes (P2.1); per-request DDL+writes in `config/database.php` hit every booking page load (R2 BLOCKED 0/14) |
| 2 | Reservations & front desk | no | unswept | 2026-09-02 | R3: live has 1 booking / 0 payments — effectively unexercised against real data. |
| 3 | Rooms | no | unswept | 2026-09-02 | `individual_rooms` transform (15 units) unverified; R2: `rooms-gallery/showcase` barely use the editorial vocabulary (4 refs each) |
| 4 | POS & F&B / KDS | no | unswept | 2026-09-02 | R3: **ENABLED on live** (pos + 4 station modules, switched on 2026-08-16). Full build scope, not reducible. |
| 5 | Stock & procurement | no | unswept | 2026-09-02 | R3: **ENABLED on live** (2026-08-16). 18 tables, 14 pages all in scope. `stock_payments` still absent. |
| 6 | Finance & accounting | no | partial | 2026-08-15 | EOD room-type revenue query fixed; finance smoke 21/21. `finance_sequences` counter reset still unverified (migration Phase 5) |
| 7 | Gym | no | unswept | 2026-09-02 | memberships listed "likely off initially"; R2: `gym-classes.php` missing from the module map (P1.3); `images/gym/hero.jpg` 404s as og:image (P1.1) |
| 8 | Conference & events | no | unswept | — | — |
| 9 | Guest communication | no | unswept | 2026-09-02 | SMTP/from-address unverified; R2 CONFIRMED: `guest_lifecycle_emails.php` is not in `setup-cron.sh`, so pre-arrival/post-stay mail cannot fire (P2.3) |
| 10 | Content & marketing | no | unswept | 2026-09-02 | UTM removal confirmed clean; R2: 5 broken image paths incl. 3 og:image/JSON-LD (P1.1); zero alt attributes on guest `<img>` (P1.2) |
| 11 | Admin platform (auth/perms/users/logs/backups) | no | unswept | 2026-09-02 | perms re-assignment unverified; R2: 8 module-map holes (P1.3); module gate bypassed for role=admin (BLOCKED 11); no automated backup cron (P2.3) |
| 12 | JSON API | no | unswept | 2026-09-02 | R3 RESOLVED: `api_keys` is EMPTY (0 rows) — nothing stale survived, nothing to rotate. |
| 13 | Platform / PWA / performance | no | unswept | 2026-09-02 | base-url unverified; R2: 39MB images / 0 WebP / single 8.1MB JPEG (P2.2); 23 information_schema probes + 4 writes per request (BLOCKED 0/14) |
| 14 | Safety net (tests, migrations) | no | partial | 2026-09-02 | `sql_mode` lacks STRICT_TRANS_TABLES. R2: smoke tests NOT runnable here (no `.env`/`database.local.php`) so lint is the only live gate (BLOCKED 14); no CI workflow (P2.4); `admin/migrations/` empty because the app self-migrates |

Update rules: scout sets **Mapped**; planner sets **Status**, **Last touched** and
**Open gaps** when a task's QA gate passes. A domain only reaches `swept` when every
PROJECT COMPLETE WHEN checklist line naming it is `[x]`.

Note on "Open gaps" above: these are transcribed from `MIGRATION_PLAN.md` and are
**unverified claims about work that may or may not have happened**, not confirmed defects.
The first scout pass over each domain should confirm or clear them.
