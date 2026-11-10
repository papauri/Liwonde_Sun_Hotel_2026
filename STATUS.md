# STATUS: Liwonde Sun Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-10**.

This repo is a fork of the Rosalyn's platform (`../Rosalyns-hotel-2026`): most fixes go into both repos with the same
commit message.

Deeper trackers to read next:
- `CLAUDE.md` for the rules
- `.claude/PROJECT_CONTEXT.md`
- `.claude/BUILD_PLAN.md`
- Rosalyn's `.claude/TASKS.md`, the live task tracker shared by both hotels

## Hard rules
- **Liwonde is LIVE**, and so is Rosalyn's.
  - The local `.env` points at the live database.
  - Run only pure (no database) tests and `php -l`.
  - Do not run `scripts/tests/licence_client.php`: it rewrites the `licence_*` rows in `site_settings`.
  - Never edit `.env`.
  - The schema is locked: any DDL is the owner's decision.
- There is uncommitted POS / stock work in this repo that belongs to another session. Never stage it together with
  other work, and never stash it:
  - `admin/includes/pos-shift-totals.php`
  - `restaurant-payment-sync.php`
  - `staff-activity-lib.php`
  - `admin/pos*.php`
  - `shift-close-report.php`
  - `stock-dashboard.php`
  - `stock-orders.php`
  - `includes/station-hours.php`
  - migration `079`
  - two tests

## Shipped 2026-11-10: "View only" permission and view-only free trials
Another session also works in this folder: always stage files by name. Files:
- `includes/view-only.php` (new)
- `scripts/tests/view_only.php` (new, 32 pure tests)
- `includes/licence-guard.php`: `rh_view_only_guard()` runs after the staff-session guard; `rh_licence_write_allowed()`
  takes an optional allowlist.
- `admin/includes/admin-header.php`: banner and greying.
- `admin/includes/permissions.php`: the new permission `view_only`, listed last, in no role's defaults.

**What it does:**
- A view-only person can open and read everything, but every change is refused by the server. Change buttons are greyed
  out by a small script.
- Everyone is view only while the licence panel has set the business's free trial to view only (`trial_view_only` in the
  signed answer). Otherwise only people with the `view_only` permission explicitly ticked are view only.
- **Inert until switched on:** no role has it. Liwonde's trial has had view only switched ON on the panel since
  2026-11-10 10:43.
- Still to do: the Assistant-lead check, and a staff-guide page in `docs/guides/` (no guide yet).

## Shipped 2026-11-10: view-only trial fix, after the owner's report
The owner reported that, on Liwonde's view-only trial, Export and New booking could still be clicked. Causes:
- Exports were allowed on purpose.
- The greying skipped every `<header>` and `<nav>`, which hold the dashboard's "New booking" button.
- It only looked at `a.btn` links, so it missed the Bookings toolbar links.

The fix touches `includes/view-only.php` and `scripts/tests/view_only.php` (80 pure tests). It is identical in Rosalyn's:
- **Server:** during a view-only TRIAL, exports and downloads are refused (`rh_view_only_is_export`). That covers the CSV
  exports, any `?export=` / `?download=` on a staff page (system logs, backups, quotations), reports-export and the
  end-of-day PDF. Opening one invoice or receipt (`document-download.php`) still works. People who are view only by
  permission may still export.
- **Greying:** it now skips only the site's own header and menu. It checks every link: by its target (create- / add- /
  new- / edit- pages, exports in a trial) and, for button-like links, by its words, title or icon (trash, plus, pen).
- **Known gap:** POS and KDS do not load the admin header, so they show no banner or greying. The server still refuses
  every change there. `admin/pos.php` has another session's uncommitted edits, so it was left alone.
- Pushed with the owner's OK (both hotels).

## Live now
The site runs `origin/main` at `d32ba8f`.

## In progress: licence modules + staff managed from the licence panel (owner 2026-11-10)
This work was shipped on 2026-11-10 after an independent review; its fixes are included. The same change is in Rosalyn's.

New files:
- `includes/licence-staff.php`: runs the staff changes the licence panel sends inside its signed answer, then reports the
  results and the staff list back to the panel's `api/hms-staff.php`. Never a password.
- `scripts/tests/licence_staff.php`: 50 pure tests.

Edits:
- `includes/licence.php`:
  - `rh_licence_module_allowed()` / `rh_licence_module_in()`: the licence is the ceiling for modules.
  - The staff step runs after the hourly check and after the panel's nudge.
- `includes/licence-guard.php`: `rh_staff_session_guard()` runs first on every staff request. A deactivated user is
  signed out, and a role change counts at once.
- `config/database.php`: `moduleEnabled()` is ANDed with `rh_module_licence_allows()`, which applies only to signed-in
  staff. The guest site and cron jobs are never limited.
- `admin/admin-init.php` and `admin/includes/permissions.php`: administrators now follow the licence for modules.
- `includes/ai/ai-core.php`: the Assistant follows the licence.
- `scripts/licence-check.php`: runs the staff step and prints counts.
- `admin/licence.php`: wording.
- `admin/includes/staff-invite-lib.php`: an invitation made for the panel always links to `site_url`.

Owner decisions (2026-11-10):
- The licence limits modules for admins too.
- The staff list may go to the panel.
- The hotel admin can undo a deactivation made from the panel.
- `finance` is always on.

Next steps:
1. After the push, the panel's `staff.php?id=2` should show Liwonde's staff after the next check-in.

## Incident note
On 2026-11-10 `scripts/tests/licence_client.php` was run against Liwonde by mistake.
- It rewrote and then restored the `licence_*` rows.
- The status was checked afterwards: active / ok, with 13 modules.
- At worst, staff saw a "licence could not be verified" banner for about a minute.
