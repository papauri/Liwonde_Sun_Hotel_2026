# COVERAGE MATRIX — Liwonde Sun Hotel 2026

Owned by `build-planner` (Status column) and `codebase-scout` (Mapped column).
One row per core functional domain in `.claude/CORE_SYSTEM_BRIEF.md`. This is how the build
loop guarantees it touches **every aspect of the system**, not just the loud parts.

Status values: `unswept` · `mapped` (SYSTEM_MAP.md section exists) · `partial` (some tasks
done, gaps remain) · `swept` (all checklist items for this domain done + QA-passed).

All 14 domains were **mapped 2026-09-02** into `SYSTEM_MAP.md` (tokenizer-extracted table
references validated against the live schema). Mapping is done; sweeping is not — most domains
have had targeted fixes but no end-to-end exercise.

**Reconciled 2026-09-03.** Gaps closed in Rounds 2–3 are struck from the table below rather
than left standing. **No domain reaches `swept`**, and the reason is the same for all 14:
nothing has been exercised through a web server, and the live site is 12 commits behind at
`6781081`. `mapped+fixed` below means "gaps found and patched in the tree, unverified at
runtime" — it is not a step toward `swept` on its own.

| # | Domain | Mapped | Status | Last touched | Open gaps |
|---|--------|--------|--------|--------------|-----------|
| 1 | Booking engine | **yes** | mapped+fixed | 2026-09-03 | ~~ballena classes~~ (P2.1 superseded — palette swapped instead); ~~per-request DDL~~ (P1.7a, flag off by default). **VAT recording fix is undeployed**; booking #109 still has no tax split; the 30→32-column INSERT has never run through HTTP |
| 2 | Reservations & front desk | **yes** | mapped | 2026-09-03 | R3: live has 1 booking / 0 payments — still effectively unexercised. No check-in, folio or front-desk flow has been run at all |
| 3 | Rooms | **yes** | mapped | 2026-09-02 | `individual_rooms` transform (15 units) still unverified; `room_inspections` now created by migration 001; ~~editorial vocabulary count~~ (metric withdrawn, see C2) |
| 4 | POS & F&B / KDS | **yes** | mapped+fixed | 2026-09-03 | ~~0 sellable items~~ (187 imported); ~~refund/tip asymmetry~~ (fixed, **undeployed**). Recipes + tables are **demo data** (B2/B3); KDS/BDS/CDS ticket flow never exercised |
| 5 | Stock & procurement | **yes** | mapped+fixed | 2026-09-03 | FIFO deduction proven at the DB layer (5 batches, no phantom `TMP-R-`); `stock_payments` **still absent — owner decision B5**; goods-received flow untested |
| 6 | Finance & accounting | **yes** | partial | 2026-09-03 | EOD room-type query fixed; finance smoke 21/21 but **invalidated by later code changes**. `finance_sequences` was reset to 1 after the E2E run — safe only while `payments` is empty. Numbering untested against real data |
| 7 | Gym | **yes** | mapped+fixed | 2026-09-02 | ~~"likely off initially"~~ (R3: gym is ON); ~~`gym-classes.php` gate hole~~ (P1.3); ~~`images/gym/hero.jpg` 404~~ (P1.1, deployed). Gym flows themselves untested |
| 8 | Conference & events | **yes** | mapped | 2026-09-03 | **`events` = 0 rows behind a live page** (C1); no enquiry or quotation flow has been run |
| 9 | Guest communication | **yes** | mapped+fixed | 2026-09-03 | ~~lifecycle job unscheduled~~ (P2.3 wrote it — **but the crontab is still not installed on the server**, A5); recipients moved to Liwonde. **Sending identity still `info@promanaged-it.com` — BLOCKED, A4.** No email has ever been sent end to end |
| 10 | Content & marketing | **yes** | mapped+fixed | 2026-09-03 | ~~5 broken image paths~~ (P1.1, deployed); ~~alt attributes~~ (P1.2 WITHDRAWN — false finding); ~~fabricated testimonials~~ (deleted). **The guard that hides the now-empty section is undeployed, so the live homepage shows a heading over nothing**; `guest_services` = 0 rows (C1) |
| 11 | Admin platform | **yes** | mapped+fixed | 2026-09-03 | ~~8 module-map holes~~ (P1.3, 7 added + 1 deliberate exclusion); ~~no backup cron~~ (written, **not installed** — A5). **Module gate still bypassed for `role=admin`** (untested — nothing is disabled); permission re-assignment unverified |
| 12 | JSON API | **yes** | mapped | 2026-09-02 | ~~key rotation~~ (R3: `api_keys` empty, nothing to rotate). `admin/api-keys.php` should be checked against the live column shape (`api_key`/`api_key_plain`/`client_*`) before the first key is created |
| 13 | Platform / PWA / performance | **yes** | partial | 2026-09-03 | ~~23 information_schema probes + 4 writes per request~~ (P1.7a). **39 MB images / 0 WebP / 8.1 MB JPEG stays open by owner decision** (C5). base-url unverified; no responsive or touch-target sweep has run (C3) |
| 14 | Safety net (tests, migrations) | **yes** | partial | 2026-09-03 | ~~`admin/migrations/` empty~~ (runner + 001 authored, applied to live). **`sql_mode` still lacks `STRICT_TRANS_TABLES`** (B1). **No CI** (P2.4 scrapped — nothing gates a push to a public repo). **QA gate is lint-only and stays that way until a staging DB exists** (A2) |

Update rules: scout sets **Mapped**; planner sets **Status**, **Last touched** and
**Open gaps** when a task's QA gate passes. A domain only reaches `swept` when every
PROJECT COMPLETE WHEN checklist line naming it is `[x]`.

~~Note on "Open gaps" above: these are transcribed from `MIGRATION_PLAN.md` and are unverified
claims.~~ **No longer true as of 2026-09-03** — every remaining entry has been confirmed against
the code, the live schema or the live site. The `MIGRATION_PLAN.md` transcriptions were either
verified, fixed, or withdrawn as false findings (P1.2 alt text, P1.5 reduced motion, P2.1's
class-count metric — all three were greps mistaken for findings; see the accuracy note in
`BUILD_PLAN.md`).

**What `swept` now requires, and why nothing has it:** a domain reaches `swept` when its
checklist lines are `[x]` *and* it has been exercised at runtime. The runtime half is blocked
for all 14 domains at once by two owner actions — deploy `main` (Gate A1) and stand up a
staging database (Gate A2). Until those land, the ceiling for every row is `mapped+fixed`.
