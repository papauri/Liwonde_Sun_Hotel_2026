# Liwonde Sun Hotel 2026: map card (updated 2026-11-24)
WHAT: Hotel website + PMS (bookings, front desk, POS, stock, accounting, gym/events, AI Assistant). THE TEST PLATFORM: changes are built and tested here first, then ported to the live sister repo ../Rosalyns-hotel-2026.
STACK: Vanilla PHP 7.4+ (no framework, no build step), PDO/MySQL via .env -> config/database.php, PHPMailer, TCPDF, plain HTML/CSS/JS, PWA service workers; cPanel hosting; GitHub main.
LIVE SYSTEM: yes   (test data here, but every change is ported to live Rosalyn's; details: CLAUDE.md "Standing rails", never copied here)
RUN / TEST / LINT: lint `php -l <file>`; tests `php scripts/run_tests.php` (here only, never in Rosalyn's); one test `php scripts/tests/<name>.php`;
  browser check `php -S 0.0.0.0:8000` + `node scripts/tests/browser_check.mjs steps.json` (stop the server after). Ship gate: .claude/DEPARTMENTS.md.
CRITICAL AREAS:   (paths are prefixes)
- time -> booking.php, check-availability.php, includes/booking, admin/create-booking.php, admin/edit-booking.php, admin/bookings.php, admin/includes/bookings-, admin/api/bookings-, admin/check-in, admin/housekeeping, admin/individual-rooms.php, admin/room-management.php, admin/blocked, gym.php, gym-schedule.php, admin/gym, conference.php, events.php, admin/conference, admin/events
- money -> admin/pos, admin/invoices.php, admin/payments, admin/end-of-day.php, admin/accounting, admin/credit, admin/receipt, admin/includes/finance-, admin/shift, invoice-view.php, invoices/, quotations/
- quantities -> admin/stock, admin/procurement, admin/suppliers, admin/menu, admin/kds, admin/data-import.php, admin/includes/import-schema.php, admin/includes/stock-sheet-import-lib.php, admin/includes/stock-measure-, admin/js/stock-ingredients-measure-
- access -> admin/admin-init.php, admin/includes/permissions.php, admin/user, admin/module-presets.php, login.php, api/
- security -> .htaccess, config/, includes/licence
- messages -> includes/email, admin/email-templates.php, config/email.php, PHPMailer/
- data -> admin/migrations/, Database/, scripts/, admin/backup
- integrations -> includes/ai/, api/
- state -> sw.js, public-sw.js, manifest.php, offline.php, config/cache.php, config/page-cache.php
KEY FILES:
- CLAUDE.md - hotel rules, live vs test, flow Liwonde -> Rosalyn's
- .claude/CORE_SYSTEM_BRIEF.md - the 14 domains -> primary files (every agent's step 0)
- .claude/DEPARTMENTS.md - department leads, cost rules, ship gate
- .claude/CODE_INDEX.md - file/function index (~100 KB: Grep it, never read whole)
- .claude/SYSTEM_MAP.md - feature -> file -> endpoint -> table
- .claude/TASKS.md - open and shipped work
- config/database.php - DB connection + shared helpers (big: offset reads only)
CONVENTIONS: department leads (.claude/agents/dept-*) own their areas and run /build-loop; money changes are gated by dept-accounting-lead; reviews qa-quick (CSS/markup/docs only) or qa-auditor.
DEEPER MAPS: .claude/CORE_SYSTEM_BRIEF.md | .claude/SYSTEM_MAP.md | .claude/CODE_INDEX.md | .claude/DEPARTMENTS.md
