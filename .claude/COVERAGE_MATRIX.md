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
| 1 | Booking engine | no | unswept | — | migration Phase 4 reconciliation unconfirmed (`check-availability.php` room_units→individual_rooms) |
| 2 | Reservations & front desk | no | unswept | — | never exercised against Liwonde data |
| 3 | Rooms | no | unswept | — | `individual_rooms` transform (15 units) unverified |
| 4 | POS & F&B / KDS | no | unswept | — | may be disabled for this property — see PROJECT_CONTEXT gap 4 |
| 5 | Stock & procurement | no | unswept | — | may be disabled for this property — 18 tables, 14 pages |
| 6 | Finance & accounting | no | partial | 2026-08-15 | EOD room-type revenue query fixed; finance smoke 21/21. `finance_sequences` counter reset still unverified (migration Phase 5) |
| 7 | Gym | no | unswept | — | memberships listed "likely off initially" |
| 8 | Conference & events | no | unswept | — | — |
| 9 | Guest communication | no | unswept | — | Liwonde SMTP/from-address in `config/email.php` unverified |
| 10 | Content & marketing | no | unswept | — | UTM/campaign attribution removal unconfirmed |
| 11 | Admin platform (auth/perms/users/logs/backups) | no | unswept | — | per-user permission re-assignment after cutover unverified |
| 12 | JSON API | no | unswept | — | `api_keys` regeneration unverified — old Liwonde key must not survive |
| 13 | Platform / PWA / performance | no | unswept | — | `config/base-url.php` on Liwonde domain unverified |
| 14 | Safety net (tests, migrations) | no | partial | 2026-08-15 | Both smoke tests now green (54/54, 21/21) against this install. `admin/migrations/` still empty; `sql_mode` lacks STRICT_TRANS_TABLES |

Update rules: scout sets **Mapped**; planner sets **Status**, **Last touched** and
**Open gaps** when a task's QA gate passes. A domain only reaches `swept` when every
PROJECT COMPLETE WHEN checklist line naming it is `[x]`.

Note on "Open gaps" above: these are transcribed from `MIGRATION_PLAN.md` and are
**unverified claims about work that may or may not have happened**, not confirmed defects.
The first scout pass over each domain should confirm or clear them.
