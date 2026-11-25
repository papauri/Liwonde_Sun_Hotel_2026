# STATUS: Liwonde Sun Hotel 2026 (hotel PMS / HMS)

**Read this first.** Any agent (Claude, Cline, another provider) should be able to continue from this file alone. Keep it
current during work. Last updated: **2026-11-24**.

This repo is a fork of the Rosalyn's platform (`../Rosalyns-hotel-2026`): most fixes go into both repos with the same
commit message.

Deeper trackers to read next:
- `CLAUDE.md` for the rules
- `.claude/PROJECT_CONTEXT.md`
- `.claude/BUILD_PLAN.md`
- Rosalyn's `.claude/TASKS.md`, the live task tracker shared by both hotels

## Token cost controls (owner 2026-11-17)
Measured 10-17 Nov: about 55 model calls per commit, 97% on Opus, department-lead runs averaging 60-105 steps with
250-470k context, main sessions up to 600k. Now enforced by config, not just rules:
- Agent frontmatter: `autoCompactWindow: 200000`; medium effort for ui-lead, ui-designer, artifacts-lead.
  NO turn caps: `maxTurns` removed from every agent 2026-11-18 (owner: caps left work half-done and cost more
  in the next session). Do not add them back (DEPARTMENTS.md cost rule 11).
- New `qa-quick` agent (Sonnet) for CSS/markup/docs-only diffs; it escalates anything with logic to `qa-auditor` (Opus).
- `.claude/settings.json`: `autoCompactWindow: 250000` for main sessions.
- `scripts/claude-hooks/read-guard.php`: blocks whole reads of files over 400 lines / 40 KB (use offset/limit).
- `scripts/port-commit.php` (in Rosalyn's): ports work already committed at Rosalyn's to Liwonde without an agent.
  Tested on 4 past agent-made ports (incl. drifted `config/database.php`): identical results.
- artifacts-lead (showcase) works the SYNC QUEUE once a week, not after each ship.
- Weekly usage report (owner 2026-11-18): `Rosalyns-hotel-2026/.claude/scripts/usage-report.js --save`
  (one copy; plain node, no AI calls) reads Claude Code's own session logs for ALL projects and writes
  `MSP/usage-reports/<date>.md` + a row in `MSP/usage-reports/HISTORY.md`. COST_LOG.md is retired.
  Baseline 10-16 Nov: 22,793 calls, 50 per hotel commit, 95% Opus, 53 agent runs past today's caps.

## Shipped 2026-11-25: Guides + Assistant + Manager Guides sync (owner prompted, profile Claude) - Liwonde e48fc69 / Rosalyn's 2b2f603
- The 21 entries pending at the start moved to "Synced 2026-11-25" in .claude/GUIDES_AI_SYNC.md (identical in both repos
  again); the Paid "i" (a1fda1b) and Status/Payment pill (8b9a348) entries arrived mid-sync and stay Pending.
- Guides: Hotel Settings card names, lock on VAT / bank / levy / service charge, cancel reasons, payment reminders +
  arrivals to chase, import questions, concurrency notes, PWA start page, stock count approval. Assistant: why-cancelled
  answer (staff-only), cancel card uses rh_cancel_reason_check, counts_not_approved, new read arrivals_to_chase.
- Owner decisions written in: cancel reason internal, guest emails unchanged; SMTP + copy lists stay unlocked.
- Manager Guides: Stock, Bookings, Housekeeping and Maintenance, Money and End of Day, Daily checklist, Staff and
  Permissions rebuilt (page counts kept). qa-auditor PASS. LATER lines in GUIDES_AI_SYNC.md Pending.

## Shipped 2026-11-25: PDF template preview / test PDF no longer edge to edge (owner PATCH, profile Claude) - Liwonde 7994a1f / Rosalyn's 5c44967
- Cause: the 7 stored *_document templates are old browser-styled copies; real sends already drop them for the print
  kit (rh_pdf_template_is_stock), but the editor preview + "Send test" printed them raw. booking-settings.php
  rh_template_preview_document_html() now makes the real send's choice. Invoice sample rows fixed (Invoice Total label
  was invisible); values no longer letter-spaced. Stock check still YES for all 7 at Liwonde; qa-auditor PASS.
- Owner: Rosalyn's stored copies could not be checked (no tests there). If one has edited wording, guests get the old
  layout too; "Reset to defaults" in the editor fixes it. Liwonde's logo file has a baked-in checkerboard.
- LATER: preview ignores is_active (real send prints the kit when inactive); sample "VAT (15%)" vs 17.5%.

## Shipped 2026-11-25: Bookings list Status / Payment pill popups (owner FEATURE, profile Claude) - Liwonde 8b9a348 / Rosalyn's 74b80e3
- Hover (tap on touch) the Status or Payment pill: why and when (who, reason, settlement, hold / pay-by time) and the
  money figures, via the shared "i" popup. admin/includes/booking-status-info.php (one read-only query per log table
  per page). qa-auditor PASS after 2 fixes (JSON tentative-log reason hidden, 44px tap area); browser 1920 + 390 ok.
- LATER: a tentative hold auto-expired by config/database.php `_expireStaleTentativeBookings` writes no log row, so its
  popup reads the derived "hold ran out" wording with System (automatic).

## Shipped 2026-11-25: booking Paid "i" + dashboard Money accuracy + "This week's movements" card (owner FEATURE + PATCH, profile Claude) - Liwonde a1fda1b / Rosalyn's 1687f9a
- Fault found: dashboard takings took money moved onto a credit note (refund rows, payment_method credit_note) off as
  refunds paid out; End of Day does not. Fixed in `$takingsByDay` (admin/dashboard.php) with the EOD cash rules. Liwonde:
  all 9 payment days now equal EOD "Cash collected" (11-07, 11-09, 10-24 were short). Rosalyn's figures may move after deploy.
- Paid "i": admin/includes/booking-paid-breakdown.php (same rows as getBookingFolioSummary; 41 Liwonde bookings, 0 mismatches).
- ASSUMPTION (BUILD_PLAN.md): "week's movements" = money movements. qa-auditor PASS, accounting lead APPROVE, browser 1400 + 390 ok.
- LATER: card hides with the Money card if the 7-days-before query fails; other booking types fold into Rooms on the card.

## Shipped 2026-11-25: Templates redesign + Suggest wording, payment reminders before arrival, FOH arrivals to chase, Recent activity filters (owner FEATURES, profile Claude session 04dedb99)
- ALL SHIPPED + ported. Owner still to do at Rosalyn's: deploy, then tick automated_email_master (0 since 2026-10-05; ticking it also starts overdue/quotation/tentative/expired/unpaid jobs, which default on). Guides/Assistant Pending lines added.
- Recent activity filters: Liwonde d9475a0 / Rosalyn's 8926fd4 (qa-auditor PASS; auto_email_log_filter_test 74/74, log_pager_test 125/0). LATER: Show after the All fallback drops All (admin/automated-emails.php ~580).
- Small fixes ("Fix the small things too"): Liwonde 7ee6eeb / Rosalyn's e92a3f4 - chase list shows the group balance ("Group balance, N rooms"), same effective lead for list + reminders (dedupe on original lead, any room's stage counts), email date = earliest arrival, already-reminded groups skip the LIMIT 200, group balance netted (floor 0). qa-auditor PASS; arrivals_chase 80/0, prearrival 81/0, dashboard_attention --db 390/0, bookings_filters 388/0; browser 1366/390 (15 rows = dashboard 15). LATER: group invoice + overdue reminder still floor each room at 0 (differs when a room is overpaid); CSV export + Balance due sort use each booking's own balance; a saved template using raw check_in_date shows the lead's date; "Make primary" during a claimed-but-unfinished send could resend (narrow).
- Rosalyn's DB (owner authorised, backups first): prearrival job on, days 30,15,4, reminder CC Gerald + P.Msukwa (backups/prearrival-reminders-before-20261125-001843.json); arrivals_chase on, CC same two, To empty = email_main (backups/arrivals-chase-before-20261125-005513.json).
- History of the build below.
- Owner: "Enable the AI assistant in the Templates ... ONLY if AI is connected ... default back to default messages ... pick up tags ... a few suggestions per template. Make email and PDF templates page appealing and easy to use".
- SHIPPED: templates page + Suggest wording, Liwonde b1148a5 / Rosalyn's c2ca585 (qa-auditor PASS, browser check 1366 + 390 ok). LATER: register scripts/tests/ai_template_suggest.php in run_tests.php (other session's file); suggest rate count is per session; ai_tpl_suggest_limit/tokens have no screen. Guides/Assistant Pending line still to add (GUIDES_AI_SYNC.md held by session 237c5c4c).
- Step 1 (frontend-specialist): template editor layout (grouped list + search instead of 28 tabs), inline JS moved to admin/js/email-templates.js. Step 2 (dept-ai-lead): "Suggest wording" per email template, server-gated (Assistant on + model ready + edit_templates), 3 versions, wording only (layout kept), tags only from includes/email-template-tags.php. Load Default unchanged.
- ASSUMPTION: suggestions on email templates only; PDF templates get the new layout but no suggestions (a saved PDF copy replaces the built-in document layout).
- Files: admin/booking-settings.php (template section only), admin/css/admin-booking-settings.css, NEW admin/js/email-templates.js, NEW admin/api/ai-template-suggest.php, NEW includes/ai/ai-template-suggest.php, NEW scripts/tests/ai_template_suggest.php. Nothing committed until QA passes.
- ALSO (owner FEATURE 2026-11-25, "Lets go with the new series ... add gerald and pmsukwa as cc to all the email reminders. Set the days according to the policy for rosalyn. I authorise the db update"): dept-accounting-lead builds job prearrival_payment_reminders (off by default; stages setting prearrival_payment_reminder_days default 30,15,4; current stage only, once each, stops when paid) + template prearrival_payment_reminder + setting automated_email_reminder_cc (open CC on guest reminder jobs, never on test sends).
- ASSUMPTION: days 30, 15, 4 (30 = last day at the 30% cancellation charge, 15 = mid 50% band, 4 = the 4-day payment processing window). CC applies to overdue, quotation expiry, tentative hold, unpaid confirmed, pre-arrival and the new series; not to notices, reviews, gym, reports.
- SHIPPED series: Liwonde 4db16f3 / Rosalyn's 9f58022. Rosalyn's DB written (backup backups/prearrival-reminders-before-20261125-001843.json): job on, days 30,15,4, CC Gerald + P.Msukwa. Rosalyn's automated_email_master = 0 (since 2026-10-05): nothing sends until the owner ticks it (it would also start the other guest jobs) - owner decision. FOH chase list + daily email: dept-accounting-lead building (owner pre-authorised the Rosalyn's settings for it).
- FOH chase (dashboard 'arrivals_owing' + bookings.php?chase=arrivals + daily email job arrivals_chase, off by default, To empty = email_main then email_reservations, optional CC; gated by the master switch): qa-auditor PASS, tests arrivals_chase 73/0, prearrival 64/0, dashboard_attention --db 390/0, bookings_filters 388/0; browser check 1366/390 ok (dashboard 15 = list 15). ASSUMPTIONS: today/overdue arrivals excluded; sends after trading-day opening; empty day = skipped row, no email; no recipient = failed, retried hourly x3. LATER: list Balance is per booking while dashboard/email show group total; cancelled lead picks another row; whitespace dashboard.php:2337, bookings-list-filters.php:1011.
- SHIPPED d9475a0 (was QUEUED; owner FEATURE 2026-11-25 "add filters then"): Automated Emails > Recent activity gets filters (job, result sent/skipped/failed, date from/to, search recipient/subject/booking) + 10/25/50/All pages instead of a fixed last 100, "?" on columns. Starts after the FOH chase build ships (same file admin/automated-emails.php).
- Series QA: qa-auditor FAIL (staff Confirm of an old pending booking skipped the 24h rule) -> fixed (timeline status_change check) + test -> PASS, 64/0. LATER: group email date from lead row vs day count from earliest arrival; candidates LIMIT 200; overpaid room in a group counts 0.
- NEXT (owner FEATURE 2026-11-25, "its important that FOH are notified to chase up payments" + "Yes daily email with the info rosaly email and optional cc to pmsukwa and gerald"): (a) dashboard Needs attention item "Arrivals with a balance to chase" (same owed rules as the series, red when arrival <= 4 days), (b) new daily timed email listing those arrivals, To = the hotel's own email, optional CC setting; at Rosalyn's switch it on with CC Gerald + P.Msukwa.
- After port, Rosalyn DB (owner authorised): automated_email_job_prearrival_payment=1, prearrival_payment_reminder_days=30,15,4, automated_email_reminder_cc = Gerald Msukwa + P.Msukwa staff emails (same as eod_report_cc_emails). Back up first.

## Shipped 2026-11-25: concurrency guardrails A-E (owner FEATURE "Do all required", profile Claude) - Liwonde 659b381 2f1464e 82c91f3 21a89d7 dbd24f3 7338934 5f75cae 474434f 2706650 / Rosalyn's d0a5896
- Plan: `.claude/CONCURRENCY_PLAN.md`. Two people working on the same thing can no longer overwrite each other:
  - Save refused when someone else changed it first (names who/when where known): Edit Booking, room types, rooms,
    menu items (food + drink), stock items. Helper `includes/stale-edit.php` (hash of the form's columns, re-read FOR UPDATE).
  - Stock count: one open count per area + shift (named lock). Import: one open job per file, a file committed while the
    job was open is refused, answers made on an old screen are refused (per-key hash).
  - Settings save only what changed (`includes/settings-save-changed.php`, `_settings_base`, never secrets): Automated
    emails, WhatsApp, Facebook, Hotel details, Review sources.
  - "Also open right now" banner (`admin/js/edit-presence.js`, presence `?editing=`): Edit Booking, stock count, import, stock item.
- Tests registered in run_tests.php: stale_edit_test, stale_edit_pages, stock_count_start_race, import_open_job,
  import_lock_gaps, settings_changed_only. No DB schema change.
- LATER (19b): email settings/templates on booking-settings.php save only what changed (owner 2026-11-25: later).
- LATER: committed_at compared to the second; count_row does not re-check its row inside the save; WhatsApp token/phone-id
  pair looser than Facebook's; room-type save still writes image_url/display_order; edit-booking hash read separate from
  the $booking load; ir_stale_hashes outside a transaction; chosen-item counts compare an unsorted ID list; banner keeps
  polling after 400/403; leave-page ping uses a relative path; data-import presence does not check rh_import_enabled;
  settings display cache (getSetting) can differ from the base map (hotel-details-settings.php ~380); review sources: an
  old page with an invalid Place ID skips the base check; hotel-details mirror-key save result unchecked; the unlock is
  used even when nothing saved; availability toggles can undo someone else's switch; stock item refusals name nobody.

## Shipped 2026-11-25: email templates - no fixed quote note, automatic plain text, tag glossary, payment policy (owner FEATURE + PATCH, profile Claude) - Liwonde f845c7e / Rosalyn's 3df06db
- Cause of "payment is due upon arrival" in a Rosalyn's quotation: a fixed note in create-booking.php's automatic quotation, not a template. Removed; staff type the note.
- Plain text made from the HTML when text_body is empty; tag glossary per template; Payment policy box in Booking Settings (Cancellation card) + {{payment_policy}} tag.
- LATER: a policy with line breaks puts `<br />` into subjects that use the tag; plain-text link text runs into the next block; no change-log label for payment_policy.

## Shipped 2026-11-25: Import step 3 shows items already on the menu + no request bursts (owner PATCH, profile Claude) - Liwonde f3a2bd4 / Rosalyn's 388027a
- Rows already on the menu at the same price were invisible ("0 of N on the menu"); counts and groups now show them.
  The import page sends at most 2 requests at once (host answered bursts with HTTP 508). Display only.
- LATER: the file upload fetch skips the 2-at-once queue; a group where every row is already on the menu still shows
  the "optional" badge.

## Shipped 2026-11-25: Import count time + later imports as regular counts (owner "Yes", profile Claude) - Liwonde 088124a / Rosalyn's c645b6f
- 1) "When was this counted?" date+time on the import: lines use stock at that time (current - stock_adjustments since),
  snapshot_at / counted_at = that time, so sales after the physical count are not lost. 2) Once an approved opening
  count exists, imports file a REGULAR count (is_opening=0): shortages need a real reason (count stays draft).
- Shipped and ported (status line updated by the Hotel Settings session).

## Shipped 2026-11-25: Import reads "Unit" + "Total Per Unit" sheets safely (owner PATCH, profile Claude)
- Owner fault: Rosalyn's "Bar Nov (3).xlsx" (headings Unit / Total Per Unit / Served by instead of "Unit Bought As")
  read 94 bottles of beer as 94 crates at the crate price (beer/cider/minerals MWK 87.2M vs 3.78M, no question) and
  asked far more questions than "Bar Nov (1).xlsx". Liwonde bd504f2 / Rosalyn's ec74e01 (includes/import/analyze,
  pack-rule, resolve, validator, value-check + data-import.js labels).
- Now: Unit Crate/Case/Pack + Total Per Unit > 1 + served by bottle/can/each -> one "in crates or in bottles?" question
  per sheet, nothing ready until answered ("in bottles" = price / N; a row with its own per-one Unit Price keeps it as
  written). "Bottle(750ML)" / "Shot(30ML)" read as unit + size. Decimal counts suggest "in bottles". Old layout unchanged.
- Tests: import_engine_test 699 passed, import_stock_test 277 passed; qa-auditor PASS after one fix round (double
  divide on two-price rows).
- Follow-ups shipped (owner "fix the small leftovers") Liwonde a9a3c9c / Rosalyn's f937d03: all six LATER items above
  fixed (sizes in "Unit Bought As" kept, price-disagree question per item, plain cost note, "items" wording, old saved
  answers kept, invented-value check on the unit word). import_engine_test 710, import_stock_test 277, qa-auditor PASS.
- LATER: pack-rule.php new function sits between import_pack_canon and its doc comment; before the price question is
  answered the cost note already says "your answer"; "Crate (330ML)" with no pack-size column reads 330 ml as the size.

## Shipped 2026-11-25: Hotel Settings tidy, one look for every section (owner PATCH, profile Claude) - Liwonde f0b932f / Rosalyn's 597dad6
- Wording, markup and CSS only (ui-lead, qa-quick PASS, field-name compare identical): sentence-case card titles, one
  on/off status block, solid "Save ..." buttons, one help-text style, inline colours/margins to classes, SMTP two per
  row. Owner 2026-11-25: SMTP and the email copy lists stay UNLOCKED (decision; don't add them to the settings lock).
- LATER: admin-booking-settings.css rules (.settings-card h4, label small, > small.help-text) also restyle
  whatsapp-settings / automated-emails slightly (cosmetic; scope with .bs-group if the owner minds); Email templates
  section and cancellation-policy rows still have inline styles; .bs-warning literal colours.
- Settings lock (5a8c3d6 / Rosalyn's 4d6a64c): settings_lock_test failed once (5 fails from a page_harness first-write
  error) while another session ran DB tests on the same Liwonde DB; 2 later runs 33/0. Concurrent DB test runs clash.

## Shipped 2026-11-25: Hotel Settings foldable + password lock on VAT / bank / levy / service charge (owner FEATURE, profile Claude)
- Owner: sections clearly labelled and foldable; high-value settings greyed out, need the password again to edit,
  greyed again after save. includes/settings-lock.php + admin/api/settings-unlock.php: own password (finance_settings
  only) opens ONE group (vat / bank / levy / service charge) for 10 minutes; the save is refused server-side without
  it and uses it up. 5 wrong passwords per 15 min per session; unlocks and failures in System Logs, never the password.
  Locked: bank name/account name/number/branch + restaurant service charge (Hotel details), Tourism levy form, VAT form
  (Accounting Dashboard). Folding: every group, card and Hotel details sub-group (admin/js/settings-fold.js), opens on
  jump/search/#hash/save message. Tests: settings_lock_test, settings_pages, settings_change_log, hotel_details.
- LATER: dead VAT confirm-modal JS in accounting-dashboard.php; VAT unlock used up before the save-succeeded check;
  wrong-password limit is per session only; no warning beyond the status line when the 10 minutes run out while typing.
  Owner to decide: lock the hidden copy email lists and the SMTP password too.

## Shipped 2026-11-25: Hotel Settings says what the copy email lists do (owner PATCH, profile Claude)
- Help text only: Email "Backup staff copy emails" (hidden BCC of every email the system sends while the switch is on;
  guests never see them; reports are separate), Payments & documents "End-of-day report CC", Automated Emails station
  report CC. No logic change.
- Owner-authorised Rosalyn's LIVE settings write 2026-11-25 (that exact change only): the two owner-given
  addresses added to email_admin_backup ('' -> both), eod_report_cc_emails ('' -> both) and
  automated_job_station_reports_cc (existing address -> + both). Existing recipients kept; switch
  email_bcc_admin was already on. Logged in System Logs -> Settings changes. Company statement CC not touched
  (guests see it). Old values backed up as JSON in the session scratchpad.

## Shipped 2026-11-25: mandatory cancel / no-show reasons with ready-made lists (owner FEATURE, profile Claude)
- Owner: cancels don't say why (Rosalyn's RBH2026633499). Cause: booking-details Cancel forms post a hidden
  "Cancelled by admin"; list cancel reason optional; a pending/tentative cancel's reason only lands in event metadata
  (cancellation_log row only for confirmed). Build (dept-rooms-lead): required reason (min 5, "Cancelled by admin"
  refused) on booking-details + bookings list cancel, reason shown once in History / audit / System Logs.
  Owner addition 2026-11-24: a pick-list of drafted reasons on every cancel prompt (includes/cancel-reasons.php),
  and the same (required + presets) for no-show, the guest website cancel ("Prefer not to say" counts), and
  conference/events/gym cancels. ASSUMPTION: guest site gets a required pick incl. "Prefer not to say".
  OWNER DECISION 2026-11-25: the reason stays internal; guest emails as before (rh_cancel_guest_email_reason():
  "Cancelled by admin", holds "Tentative hold cancelled by hotel"; guest's own website reason unchanged).
  Order: dept-rooms-lead (rooms, no-show, guest site, shared mechanism) -> dept-events-gym-lead (conference/events/gym).
  qa-auditor PASS (after one fix round). Tests: cancel_reason_required 95/0, cancel_reason_events 83/0, booking_history
  88/0, bookings_bulk 116/0, cancellation_policy 132/0, booking_lookup_group 48/0, linked_gym 21/0 (email_send_log 4
  failures pre-existing: static checks on bookings.php, same at HEAD). Commit "Cancel reasons required", ported.
  LATER: gym member_save for a brand-new member saved as Cancelled skips the reason; Edit-cancel of a member doesn't
  void the unpaid bill (pre-existing, accounting); stale Save Notes can overwrite the appended reason line; class roster
  removal has no reason; process-checkin cancel has no calling screen.

## Shipped 2026-11-24: Stock Items "Fill from bottle sizes" + "Shots per bottle" column (owner FEATURE, profile Claude)
- Owner: Rosalyn's ingredients bought as bottles, sold in shots showed "2.28 bottle" (2026-11-21 import saved no count
  measure). Spec + owner answers: `.claude/STOCK_MEASURE_AUTO_PLAN.md`. Liwonde 2cdb039 / Rosalyn's d089216 (+ docs
  f039260 / d79e6cd). Button: preview -> tick -> Apply; N = item bottle size / Drink measures list size its menu servings
  use (rh_smb_n_from_ml rule); new = ticked, different or sold in several measures = unticked, skips say what to set.
  Tests: stock_measure_auto 49/0, stock_measure_bulk 65/0, stock_count_measures 103/0, stock_qty_words 39/0,
  stock_list_filters 85/0. qa-auditor FAIL (DB error text in apply JSON) -> fixed -> shipped. Not yet run at Rosalyn's
  (owner/staff press the button; expect many "No bottle size" skips there).
- Follow-up PATCH (owner "do as critical"): same DB-error-text fix in the older "Set count measure" apply
  (admin/includes/stock-measure-bulk-lib.php).

## Shipped 2026-11-24: Housekeeping + Maintenance layout Phase A (owner PATCH, profile Claude)
- Plan + owner answers: `.claude/HK_MAINT_LAYOUT_PLAN.md`. Liwonde 2323967 / Rosalyn's 75d564c.
- Both pages: KPI chips on one row, grid directly under them, compact "Needs action" / "Rooms needing work" list with a
  per-row Assign (existing actions only), workload chip row; shared CSS `admin/css/ops-pages.css`. Maintenance list now
  shows only rooms out of service or with an open job (was every occupied room: 15 -> 0 at Liwonde).
- Tests: guest_left 50/0, rooms_hk_12a 34/0, maintenance_clash 17/0, ops_timeline 114/0, extend 35/0, dashboard_counts
  143/0, dashboard_attention 334/0. qa-auditor FAIL (ticks < 44px) -> fixed -> PASS. Browser 1440 + 390, no side-scroll.
- NEXT: Phase B (Calendar's Housekeeping / Maintenance tabs, same layout): calendar files must be free of other sessions.
- LATER: quickAssignRoom() JS now unused; old card CSS left in room-maintenance.css; the per-row select shows
  "Unassigned" if a task's assignee is not in the staff list.
- PWA check 2026-11-24 (owner question, read-only): updates reach installed apps (no-cache fetches, no-cache sw files,
  hourly update check). Gaps, not fixed: bar/coffee boards (bds.php/cds.php) never auto-reload after a deploy
  (offline-queue.js:330 matches only kds.php) and kds.php only reloads once hidden; admin/sw.js falls back to a missing
  admin/offline.php (built-in offline page shows instead).

## Shipped 2026-11-24: bookings list overhaul (owner FEATURE, ClaudeWorkTwo)
- Owner: "Better sorting and filtering for bookings page and better UI ... add the calendar view too"; owner chose the
  Room Timeline INSIDE the bookings page and "everything else" (saved filter sets, CSV, staff filter). Owner 2026-11-24:
  a new table is fine on both hotels (not needed: saved sets use `admin_user_preferences`).
- Plan + every decision: `.claude/BOOKINGS_LIST_PLAN.md` (spec-checked; three ships A / B / C).
- Ship A DONE (helpers, source + made-by filters, server wiring, CSV follows the screen sort + Balance due column;
  new default order: upcoming soonest arrival first, history newest first). Tests bookings_filters 357/0,
  booking_created_by 27/0, bookings_bulk 116/0, bookings_list_http --db 656/0 (993/0 vs HEAD baseline), explain: no
  full scan. reviewer-critical PASS (qa-auditor + accounting checklists).
- Ship B DONE 2026-11-24 (filter bar + quick dates + phone Filters fold, removable chips + Clear all, tab row + More
  menu, Balance due column + "?" helpers, remembered sort per user per device, pager 10/25/50/All, saved filter sets in
  admin_user_preferences key bookings_saved_filters_v1 via NEW admin/api/bookings-saved-filters.php). Proof:
  bookings_list_http --db 723/0, bookings_saved_filters --db 76/0, bookings_filters 357/0, bulk 116/0, view_only 93/0,
  booking_pay_state 23/0, browser B1 181/181 at 1920/1280/768/390, Balance due exactly once per owing row at every width
  390-2560, no page side-scroll; test rows cleaned. reviewer-critical PASS.
- OWNER DECIDED 2026-11-24 ("yes move the ref and check out columns earlier"), being built with Ship C: Balance due column shows only where it fits with 60px spare (measured bands, bookings.css ~3689);
  1920 with the sidebar open (1343px box) needs 1401px, so the amount shows under the total there and the column header
  (the only balance sort) is hidden. Showing it needs the older Ref / Check Out drop point moved. Bands were measured on
  Liwonde data: check Rosalyn's at 1280 / 1920 by eye after the port.
- LATER: 1025-1395px windows: the table scrolls inside its own box (older); chip x 28px / saved-filter delete 36px with a
  mouse (44px on touch); two saves at the same instant can get "try again"; 390 "left to pay" cut by Mark paid (older).
- Ship C DONE 2026-11-24: List | Calendar switch on bookings.php shows the existing Room Timeline in a lazy same-origin
  iframe of calendar.php?embed=1 (header/nav/SW/Assistant/global search dropped in embed, CSRF + Alert kept, every exit
  to the top window, sign-out -> login.php?redirect=bookings.php?view=calendar, sw.js skips embed pages, List reloads
  after a change in the frame, ?view=calendar kept on refresh/Back, mapped filters room type / room / In house etc.,
  "list only" note for the rest). Owner column decision built: Ref + Check Out drop up to a 1462px box so Balance due
  is a column at 1920 with the sidebar open. Proof: bookings_filters 388/0, calendar_timeline 50/0, actions 56/0,
  ops_timeline 114/0, grid_select 68/0, calendar fixture 83 evals 0 fail at 1920/1280/768 touch/390 touch, list fixture
  181/181, width sweep Balance due once; one Liwonde booking moved and moved back (DB checked). reviewer-critical PASS
  after one inline fix (44px switch, no global search in the frame).
- LATER (Ship C): tablet at 60% display size gets the 14-day desktop timeline in the frame; presence ping ignores
  activity inside the frame; offline load of the frame goes to login; calendar fixture step 42 moves a real Liwonde
  booking (guarded by the header title) and a mid-run failure leaves it moved: check RBH2026757704 = VILLA 3, 27-29 Nov.
- Guides: docs/guides/07-reception-bookings.html needs the new filter bar, tabs + More, saved filters, Calendar switch
  and the calendar selection changes (next owner-prompted Guides + Assistant sync).
## Shipped 2026-11-24: calendar Unblock / Extend / booked count judge off-screen nights (owner PATCH, ClaudeWorkTwo)
- Owner: "Unblock and Extend on the calendar look only at the dates on screen". Cause: offers + count read only st.data
  (room-timeline.js 243-255, 1600-1642); calendar-data.php drops nights outside the loaded range.
- Fix: room-timeline.js pickFacts fetches missing nights from api/calendar-timeline.php (<=31-day windows, cached until
  st.data changes, late responses dropped); "(checking...)" with Unblock / Extend disabled; failure = "(could not check
  every night)" + Check again. Unblock sends only a room's own-block nights (confirm names them; others left as is),
  still via blocked-dates.php grid_apply. Blocked Dates grid: same gap fixed (reads blocked-dates.php?month=, GET only).
  room-timeline.css: .tl-bulkbar text wraps (overflowed at 390). Unblock now offered only for a room's own block.
- Proof: grid_select 84/0, calendar_timeline 50/0, actions 56/0, blocked_dates_grid 63/0, ops_timeline 114/0; browser
  with real API data + injected block, grid_apply intercepted (payload only the own-block nights), bar fits 390/768.
  The real block -> off-screen unblock round trip did NOT run (permission check refused it); owner chose to ship
  ("commit now", 2026-11-24). reviewer-critical PASS.
- LATER: clear st.pickFx in blockSaved / blockPicks (a failed reload keeps old checks); a block split across two loaded
  ranges gives two Extend items (server merges); off-screen checks do not expire (another tab's change unseen until
  reload; server re-checks).

## Shipped 2026-11-24: calendar grid PATCH (owner, ClaudeWorkTwo)
- Owner: clicking a day selects a different day; a selection (e.g. 30 nights) is lost on next days / next month so it
  cannot be extended. Cause proven: the hit test divided pointer px by JS dayW, but the portal display size zooms the
  page with CSS zoom (admin/css/page-zoom.css:8; tablets default 60%): at 90% 18/28 clicks wrong, at 60% 27/28.
- Fix: night under the pointer read from the drawn grid (room-timeline-select.js colOf / colsMoved; room-timeline.js
  dayUnder / nightsMoved for move, nights handles, block extend; room-timeline-ops.js job extend); selection kept across
  ranges / months; Shift-click, Shift+Arrow and long press ADD and grow the room's own run; room-name / day-head clicks
  set the anchor; long press adds the pressed night and no longer runs a menu item on lift (admin-context-menu.js);
  Blocked Dates keeps the Shift anchor across months; the bar names off-screen nights. Calendar, Blocked Dates,
  Housekeeping, Maintenance.
- Proof: grid_select_test 68/0, calendar_timeline 50/0, actions 56/0, ops_timeline 114/0, blocked_dates_grid 63/0;
  browser clicks at 60/80/90/100% 112/0 wrong; owner's 30 nights -> next month -> Shift-click case + touch 390/768;
  one real Liwonde block across two months 0 -> 7 rows -> 0. reviewer-critical PASS after one fix round.
- LATER: Unblock / Extend / booked count use only on-screen dates (server preview still checks Block); where() merges
  nights over 3 rooms; stale Blocked Dates anchor after a save; long-press lift moved >10px not swallowed; iPad Safari
  zoom unchecked.

## Shipped 2026-11-24: Guides + Assistant sync (owner prompted, ClaudeWorkOne)
- Every Pending entry in `.claude/GUIDES_AI_SYNC.md` (2026-11-20 to 2026-11-24) is now in "Synced 2026-11-24";
  Pending is empty. Commit "Guides + Assistant sync 2026-11-24" in both repos (Rosalyn's = port).
- Staff guides: 01, 05, 06, 07, 08, 13, 22, 23, 99, faq. Assistant: stock quantities also in "bottles + shots"
  words (ai-lookups, ai-insights, ai-agents, ai-decisions; 3 checks). Manager Guides: stock, pos-kds, bookings,
  housekeeping, money-eod, daily-checklist, staff-access rebuilt (2 pages each, checklist 1).
- Tests: ai_assistant_test 1253 / 1 fail / 6 skip. The fail ("staff active today", 3 logged instead of 2) is
  test data: an "Aitest Temp" account with logged actions left by a run killed for low memory. Review: one
  reviewer-critical pass (2 guide lines fixed inline).
- LATER lines: GUIDES_AI_SYNC.md "Known leftovers".

## In progress 2026-11-24: dashboard first-login information (owner FEATURE, ClaudeWorkOne)
- Owner: dashboard misses vital info for someone who first logs in; mock-up approved
  (https://claude.ai/artifact/VGrVZnzhATWGmQeJDRUXWp). Plan + spec-check fixes + owner answers:
  `.claude/DASHBOARD_FIRSTLOGIN_PLAN.md` (binding).
- Owner decisions 2026-11-24: handover note goes to EVERYONE until each marks it read; welcome card shows until "Got it";
  VIP chip dropped (no VIP field); maintenance notice stays the existing top bar (task A5 not built).
- Phase A SHIPPED 2026-11-24, Liwonde 3102daa / port Rosalyn's 5d944bb (last sign-in line, End of Day day chip, Android/iOS login labels): reviewer-critical PASS;
  tests login_email 27/0, log_pager_test 125/0, dashboard_firstlogin 22/0 + --db 34/0, dashboard_figure_perms 89/0.
  LATER: timed send time read from automated_email_log.updated_at, not sent_at; a manual send during day D counts as closed.
- Phase B SHIPPED 2026-11-24, Liwonde d78bb24 / port Rosalyn's 4de91ec (since line + ?since= filters on Bookings/Payments, guest chips, donut free tonight, Money
  "Taken this week", "?" tips): reviewer-critical PASS; dashboard_attention --db 362/0, bookings_filters 253/0,
  finance_filters 168/0, dashboard_firstlogin 32/0 + --db 48/0, dashboard_figure_perms 98/0, dashboard_counts 143/0,
  guest_history 21/0. KNOWN OLD FAIL: guest_history --db "refunds shown in full (40,000)" fails on HEAD too (predates
  Ship M2 test update; not touched). LATER: "?" button nested inside card links; no "?" on since line/day chip;
  rh_dash_pay_scope_types duplicates the payments.php type-scope rule; $moneyShort unused.
- Phase C SHIPPED 2026-11-24, Liwonde fe55e0e / port Rosalyn's 1239b3a: migration 091 (shift_handover_notes,
  shift_handover_reads) applied on Liwonde only (backup backups/2026/11/db-20261123-172246.sql.gz); handover note button +
  top item in Needs attention (everyone until each marks read, 48 h expiry, not in the badge count, log names id only);
  first-login welcome card until Got it. reviewer-critical PASS; dashboard_firstlogin 56/0 + --db 115/0,
  system_logs_activity 120/0, dashboard_attention 330/0, dashboard_figure_perms 98/0, view_only 80/0, licence_staff 53/0,
  licence_client 247/0; browser check admin + receptionist passed.
- 091 RUN ON ROSALYN'S 2026-11-24 (owner: "Run the migration to rosalyn now"): dry run showed 091 the only pending,
  backup backups/2026/11/db-20261123-180341.sql.gz first, then `--run` -> 091 APPLIED, 0 pending.
- Phase C LATER items fixed 2026-11-24 (owner: "Finish the left for later"; Liwonde 87436ce / port Rosalyn's 64942fa):
  browser counts the 500 limit per character like the server (emoji note of 308 chars saves; maxlength 1000);
  "All clear" hidden while a note shows and back after the last note is read or found expired; view_only.php asserts
  handover-notes.php refused / dashboard-seen.php allowed under view only, trial and licence read-only. Browser check
  test notes + 7 activity rows removed from Liwonde. reviewer-critical PASS; view_only 86/0, dashboard_attention 334/0,
  dashboard_firstlogin 56/0. Gap closed 2026-11-24 (owner: "fix the small gap"): NEW scripts/tests/view_only_http.php
  --db (Liwonde only) runs php -S + dev-router, signs in a view-only test user over HTTP: handover write -> 403 view only,
  nothing saved; Got it still works; same write saved once view only is off; restores all state. 9/0, reviewer-critical PASS.

## Shipped 2026-11-23: calendar side panel opens like the Assistant chat (owner PATCH, layout only)
- Owner: panel should squeeze the page like the chat panel, not cover it, and not sit under the Assistant / notice
  buttons (they hid Open booking / More). Covers Calendar timeline + Housekeeping / Maintenance / Room Service grids.
- How: `admin/js/room-timeline.js` `syncDock()` (shared via `RoomTimeline.util`, used by `room-timeline-ops.js`) sets
  `html.rh-tl-open` (buttons move left of the panel) and `html.rh-tl-docked` (1025px+ and the page keeps 600px:
  `#rh-admin-page` padding-right = `--rh-tl-w`); the grid redraws to fit, keeping the selected bar + focus.
  CSS in `admin/css/room-timeline.css`; notice pill re-places on `rh-dock-change` (`admin/includes/ai-panel.php`).
- Checked: headless browser at 1476x1197 (docked) and 1100x800 (overlay, buttons clear), Calendar + Housekeeping;
  reviewer-critical PASS. LATER: with both the Assistant and this panel docked, alerts/bulk bar clear only the
  Assistant's width; docking the Assistant while this panel is open does not redraw the grid.

## Also shipped 2026-11-23 by ClaudeWorkTwo (lines added for them)
- Maintenance windows from the panel + hotel backups skip cp_ tables: Liwonde 46efbca / Rosalyn's 974003a.
- Update from Remote, hotel side: Liwonde 3fccfba / Rosalyn's 1bad20c. ClaudeWorkTwo is continuing (git pull instead
  of uapi) in includes/licence.php, scripts/deploy-check.php: see the board.

## Shipped 2026-11-23: one grid for Calendar / Blocked Dates / Maintenance / Housekeeping (owner FEATURE)
- Plan, owner words + answers, phases: `.claude/GRID_UNIFY_PLAN.md` (read it first). Phases: 1 selection engine (SHIPPED),
  1b right-click everywhere in admin, 2 booking-clash check (skip booked nights), 3 Extend.
- Phase 1 shipped: Liwonde 0bb811a, Rosalyn's port 790990b (both pushed): new admin/js/admin-context-menu.js (`RhContextMenu.open/register`),
  admin/js/room-timeline-select.js, test scripts/tests/grid_select_test.mjs 13/0. Calendar: rectangle drag across rooms,
  Shift/Ctrl, room name, day head; right-click New booking / Block / Unblock (preview_block + grid_apply) / New maintenance
  job. HK + Maintenance timelines: multi-select bars -> list bulk actions; picked days -> add form prefilled (one POST
  per room/day, form read once). Lists: Shift range + right-click rows. No save handler / SQL changed.
  Tests: calendar_timeline 50/0, calendar_timeline_actions 55/0, ops_timeline 114/0, blocked_dates_grid 62/0, block_rooms 42/0.
  QA FAIL (multi-add re-read live form) -> fixed inline -> pages load headless.
- NOT done in Phase 1: Blocked Dates still on its own grid (blocked-dates-grid.js; moving it breaks blocked_dates_grid
  test's markup checks) - with menu + bulk bar added.
- ASSUMPTIONS: new maintenance jobs 08:00-17:00; max 60 HK tasks / 30 maintenance rooms per add; a mouse click on a room
  name or calendar block now selects (Enter opens the link).
- LATER: maintenance multi-room add uses earliest..latest picked day for every room; a retry after a lost reply can add
  a duplicate task/job.
- SHIPPED 2026-11-23: Phase 1b Liwonde 3addb8a / Rosalyn's 6464350; Blocked Dates patch Liwonde 53c7d34 / Rosalyn's
  2bd5082 (both QA'd, fixes applied: copy toast escaped, class-disabled actions skipped, tap/keyboard picks store records).
- Phase 2 SHIPPED 2026-11-23 per plan + Fable check + owner answers 2026-11-23 (in GRID_UNIFY_PLAN.md: maintenance refuse;
  type block = block only the free rooms; generated jobs block_room=0 on booked nights; booking drag YES in Phase 3).
  2026-11-23 Phase 2 SHIPPED (see commit; QA FAIL on XSS -> fixed: clash messages shown as text) : new includes/room-night-clash.php (helper, locks,
  maintenance check) + includes/block-save.php (one block engine; API create/move; rh_block_room_ids moved here);
  admin/blocked-dates.php (grid_apply/preview/block_date/block_multiple via engine, Block Date Range confirm),
  blocked-dates-grid.js + room-timeline.js confirm text, api/blocked-dates.php, admin/room-maintenance.php (add/update
  refuse; auto + bulk block_room=0 under locks), includes/maintenance-recurring.php, api/maintenance-schedules.php,
  includes/ai/ai-actions.php (card preview/verify). New tests room_night_clash_test, block_clash_test,
  maintenance_clash_test, block_booking_race (+ clash_test_lib, race child), registered in run_tests.php.
- Full suite 2026-11-23 (run twice, killed at 42/73 and 46/73 by low laptop memory; 47-73 run one by one): every grid,
  block, booking and clash suite green (clash 26/0, block clash 22/0, maintenance clash 17/0, race 17/0, Blocked Dates
  grid 63/0, Block Rooms 42/0). Failures OUTSIDE Phase 2 code, not caused by it: import_export_test 100/1 (xlsx
  endpoint returns the HTML page), stock_count_bigform 28/1 (420-line draft saves as approved, not submitted),
  stock_count_approval 124/1 + Menu portions Recipes check (both pre-existing), documents_flow flaky (97/6 once, clean
  once). Owner told; not fixed here.
- 2026-11-23 SHIPPED Phase 2b (stay date/room changes lock + clear clash refusal): Liwonde 77536cd / Rosalyn's dca1e25
  (ported as a patch: Rosalyn's RBH prefix + its database.php drift kept). Critical review PASS.
- 2026-11-23 SHIPPED test fixes (owner: "all of them fixed, no compromise"): the six failing suites above fixed at the
  cause, no check weakened, no app code changed: Liwonde 10bc317 / Rosalyn's 69f6f31. documents_flow: one silent child
  death never captured; now retried once on lock/connection errors only, real errors printed (3 runs 103/0).
  Untracked test PDFs in invoices/ deleted 2026-11-23 (owner). LATER: test runs still write PDFs there (numbers reused after rollback).
- 2026-11-23 SHIPPED Phase 3 Liwonde f7fa487 / Rosalyn's a7a8340 (Extend for blocks / maintenance / housekeeping, Excel-style end handles, booking drag ->
  Change dates prefilled): critical review PASS; extend_test 35/0 + grid, clash, stay-change, timeline suites green;
  headless check of the four pages at 1280 + 390. Blocked Dates: no handle on a month's last night (run may continue
  into next month; Extend... instead). LATER (review): HK preview over-counts two tasks sharing room+type;
  maintenance/HK bulk + reconcile lock in another order (deadlock rolls back safely); Extend has no undo.
- NEXT after Phase 2 (owner OK 2026-11-23): Phase 2b, stay date changes lock + clear clash message (GRID_UNIFY_PLAN.md). Was Fable LATER:
  needs owner OK). Room picker change listener not guarded against double add (harmless).
- 2026-11-23 (ClaudeWorkOne) Phase 1b BUILT, uncommitted, in QA: admin/includes/admin-footer.php loads admin-context-menu.js
  + new admin/js/admin-row-context.js on every admin page (right-click a list row = that row's own buttons / More items,
  Open, Open in new tab, Copy reference; native menu on inputs, text, links, Shift, [data-no-ctx]; POS/KDS excluded).
- 2026-11-23 Blocked Dates PATCH (owner reports), uncommitted, in QA: blocked-dates-grid.js keeps picks across months
  (SPA month swaps; sessionStorage + rh:content-updated); Block Date Range picker rebuilt after SPA swaps (admin-spa
  _cleanup removed its calendar); Block Date Range Individual level = tick several rooms -> preview_block + grid_apply.
  Weekends "not selectable": not reproduced on Blocked Dates or the Calendar timeline (asked owner which screen).
  Tests: blocked_dates_grid 62/0, block_rooms 42/0, grid_select 13/0, headless checks.
- Phase 2+3 spec written (.claude/GRID_UNIFY_PLAN.md "Phase 2+3 build spec"), incl. owner's drag-to-extend/shorten
  (2026-11-23). Fable spec check running; then owner answers to its open questions, then Opus build Phase 2.

## Shipped 2026-11-23: import reads "Shots per bottle" / "Bottle size (ml)" / "Shot size" columns (owner FEATURE, #8)
- Owner 2026-11-23: "Is the stock import able to recognise a column that mentions how many shots in a bottle or glasses in a casket ...?" -> "yes". Spec: `.claude/IMPORT_PLAN.md` last section.
- Files: includes/import/{default-aliases (seed v11), mapper, measure, validator, analyze, resolve, review, drinks, commit-stock}.php, admin/includes/import-schema.php (kind ask_phrase), admin/js/data-import.js, tests (engine, import_stock_child, import_drink_prices_child).
- Tests 2026-11-23: engine 655/0, import_stock 265/0, drink prices 162/0, stock_count_measures 103/0. Job 2402 unchanged (read-only snapshot before/after).
- QA fix round 2026-11-23 (done, uncommitted): open count lines = item keeps its measure even for a corrected N (resolve + commit re-check);
  typed step 3 sizes vs file values = blocking measure-file question (import_file_typed_check); "Volume (ml)"/"Size ml" etc. asked (seed v12).
  Tests: engine 661/0, import_stock 267/0, drink prices 162/0, stock_count_measures 103/0, stock_measure_bulk 65/0. Job 2402 untouched.
- QA re-check: 1 more blocker ("use the file's size" not followed on the menu side) -> fixed inline in drinks.php
  ($fileUnitOverTyped / $fileMeasOverTyped first in the shot/glass/bottle order) -> PASS. engine 661/0 (one run showed
  660/1, not reproduced: possible flaky check), drink prices 162/0.
- LATER: not-in-a-measure path (resolve.php:2052): a typed size beats the file size without a question (menu box shows
  "file", so visible); a confirmed "No of shots" is asked again next import (#10 learning covers it); N column not saved
  on rows counted in bottles; import messages say "Stock Items" where the hotel says "Ingredients" (fix in #11).

## Shipped 2026-11-22: import saves the count measure (N shots/glasses per bottle) on the item, no guess (owner FEATURE)
- Owner: "Yes as long as the counts are set like shot, double, glass whatever update these accordingly. No guess work".
- At commit, a row converted from a measure saves big = stock unit, small = measure word, exact N via rh_stock_measure_save
  (same transaction) only when N is certain: answered N, or unit ml / measure ml with both from the file, typed in step 3 or
  the Drink measures list entry of that exact name, exact at 4 dp (750/25, 750/60 yes; 750/35, 1000/30 no + reason).
  Two measures on one item = none. Existing different measure = blocking Keep / Change (not applied while open count lines
  use the old measure). Step 4 lists the items + reasons. Replaces the ASSUMPTION "typed shot size is import-only".
- QA FAIL (from a768fe6): served-by "Shot 30", "Tot 35", "Double", "Glass of wine", "Wine glass" skipped the bottles-or-shots
  question (count read as bottles silently) -> fixed (measure.php import_count_in_read/_exact, pack-rule.php:128 measure
  word without unit word = portion, resolve.php matched item unit) -> PASS. Tests engine 634/0, import_stock 264/0,
  drink_prices 155/0, stock_count_measures 103/0, stock_qty_words 39/0, stock_to_menu 43/0.
- Liwonde e9bb924, port dbd8e05. (6)+(7): Liwonde 75ef370, port 28288a2 (database.php hunk via git apply). All pushed.
- Job 2402: owner answered its 3 questions 2026-11-22 13:49-13:53 (159 ready, can_confirm; saves 25 shots a bottle).
- LATER (do with the next import build): "Shot 30" ignores the 30 and uses the 25 ml list shot (should not save / should
  ask when the cell shows a different number); question wording "glass of wines".
- OWNER RULE 2026-11-23: never touch Rosalyn's DB / run migrations until the owner confirms the exact list ("just about
  stocks"). Approved: stock_counts.import_job_id (auto on first confirmed import); import_aliases seed top-ups v10-v12
  (new word rows only, auto on first Import page use; owner "ok" 2026-11-23). NEVER run admin/migrations there.
  Flow (owner 2026-11-23): build, review, commit to Liwonde first; THEN list the Rosalyn's DB changes at the end; port
  after the owner's yes. Don't ask mid-build.
- (11) SHIPPED 2026-11-23: Liwonde 512c702, Rosalyn's port 28b55bf (owner OK for the column). Was: Rosalyn's port WAITING for owner OK on the runtime column
  stock_counts.import_job_id (nullable, added by code on first use like is_opening; owner asked 2026-11-23; if no, link via
  the "Import #id" in the count notes instead). New includes/import/diff-report.php; report frozen in
  import_jobs.result_json.diff_report (built in the commit transaction, try/catch: a report failure never stops a valid
  import); shown on stock-count.php (opening counts from an import: chips, pager, Excel/CSV download, formula-safe) + import
  result page; import messages use import_stock_page_name() ("Ingredients" / "Stock Items"). Tests import_diff_report 32/0,
  engine 661/0, import_stock 267/0, drink prices 162/0, count measures 103/0; stock_count_approval 124/1 pre-existing (no
  stock items on Liwonde). QA PASS. LATER: cap rows while adding (5000); two imports adding the column at once -> second
  count unlinked; delete.php:12 comment stale; CSV negatives get a leading apostrophe.
- (11) ASKED (owner 2026-11-23: "when the import is submitted for review it reports any discrepancies with the file that
  was uploaded ... in summary"): at commit build + store a "Differences from the uploaded file" report (counts converted,
  costs worked out from packs, fixes, rows left out / not in opening count + why, value check file vs imported totals,
  answers given, typed vs file menu prices, count measures saved/kept/not saved); shown on the opening count (stock-count.php,
  for the approver) and the import result page, with a spreadsheet download. Frozen at commit.
- (10) SHIPPED 2026-11-23: Liwonde daddac2, Rosalyn's port 6ecd0a1 (owner yes). (QA FAIL: learned answer lost to another field's seed alias; grammar question dropped
  pre-#10 options -> fixed (person's latest answer wins; any other field match keeps its exact pre-#10 question; 2719-heading
  before/after check) -> PASS. Tests engine 680/0, ai_import_suggest 43/0, import_stock 277/0, drink prices 162/0, diff
  report 32/0. Rosalyn's DB changes: NONE (rows only when a person answers). LATER: col_use no lock; no screen to undo a
  learned "not this". Was (spec IMPORT_PLAN.md A92-A94): includes/import/header-grammar.php (new) +
  mapper/structure/analyze/review/review-view/drinks/measure.php, admin/includes/import-schema.php, includes/ai/ai-import-suggest.php,
  admin/data-import.php (POST col_use), admin/js/data-import.js. No new table/column, no seed bump; new import_aliases kind
  `field_not` / `field_yes` written only by a person's answer. Tests engine 677/0, ai_import_suggest 43/0, import_stock 275/0,
  drink prices 162/0, diff report 32/0; headless step 2 at 1400/400 PASS (test job + learned rows removed). Job 2402 untouched.
- (10) was QUEUED after (8) QA (owner 2026-11-23: "extend it to read other wordings too ... not always hard coded ... with AI it
  can also help ... works perfectly without it"): header grammar "[measure word] per/in/to/from/each [container word]" from
  the hotel's own word lists + abbreviations (btl, gls, cskt, ctn); size/volume/capacity/ml/cl -> unit_ml / measure_ml; no
  container named = ask. A person's column answer saved as a hotel-learned alias (import_aliases is_hotel_learned=1).
  Assistant: unread headers + sample values in the SAME single call -> "Suggested: ..." + "Use this" (validated, never auto).
  Value cross-checks unchanged whatever the source.
- (9) SHIPPED 2026-11-23 (owner "Build #2"): Stock Items bulk "Set count measure" (tick / Tick all shown -> bigger unit,
  measure, N, kept in; preview set / same / different (only with "Change these too") / skipped + reason; rh_stock_measure_save
  per item; refused while open count lines use the old measure; stale preview refused). Why: Rosalyn's 2026-11-21 import
  items have no measure, so counts show "2.28 bottle". New admin/includes/stock-measure-bulk-lib.php +
  admin/js/stock-ingredients-measure-bulk.js. Tests stock_measure_bulk 65/0, stock_count_measures 103/0, stock_qty_words
  39/0, stock_list_filters 85/0. QA PASS. LATER: skip/warn kg/litre units; warn when typed bottle ml != item volume_ml;
  report Apply failures apart from skips.
- (8) SHIPPED (see the Shipped 2026-11-23 #8 block above): import reads columns "Shots per bottle" / "Glasses per casket" / "Tots per bottle"
  (measures per unit, word from header), "Bottle/Casket size (ml)", "Shot size / ml per shot" as certain sources; a row whose
  own cells disagree = ask; "No of shots" must not map to row_no. Fold in LATER "Shot 30" (cell number != list ml = ask).
- (6)+(7) SHIPPED 2026-11-23: (6) rh_stock_qty_words adds the total in smalls once a whole big shows ("2 bottles + 7 shots
  (67 shots)", "short by 1 bottle + 3 shots (33 shots)"), stock-count.php JS the same; (7) "Show profit without VAT" tick box
  on import step 3 + stock-to-menu (vat_mode inclusive: round(price/(1+r),2) - cost; exclusive = same; off = no box; server
  computes; Stock permission gate kept on stock-to-menu). Profit itself stays VAT-included (owner). Tests stock_qty_words
  39/0, count_measures 103/0, import_drink_prices 160/0, stock_to_menu 47/0, import_engine 634/0. QA PASS.
  LATER: dashboard.php:1593 low-stock detail shows brackets inside brackets; import step 3 sends profit_ex_vat to every
  data_import user (as cost/profit there already).
- QUEUED (owner 2026-11-22): (6) stock screens show both units "2 bottles + 7 shots (57 shots)" via rh_stock_qty_words +
  stock-count.php live Difference JS; (7) profit stays VAT-inclusive (owner decision) + a "Show profit without VAT" tick box
  on import step 3 menu table and admin/stock-to-menu.php (price / (1 + hotel VAT rate) - cost; display only).

## Shipped 2026-11-22: import asks "bottles or shots?" for measure-served drinks + "57 shots = 2 bottles + 7 shots" (owner)
- Owner: "how come it just says 2.8 bottles everywhere instead of saying how many shots". Owner answers 2026-11-22: in their
  bar sheet the count for shot drinks is SHOTS LEFT; show "57 shots = 2 bottles + 7 shots" (exact on hover).
- FOUND: job 2402 (Bar Nov) read premier brandy 57 as 57 BOTTLES (Stock Value = 57 x 4,000 shot price) -> opening count
  25x too high for 66 shot rows. Job 2402 now shows 3 blocking questions (60 shot, 9 casket, 1 carton), suggestion = shots.
- One BLOCKING question per served-by group when the count cell is a plain number (row override: POST count_row); Stock
  Value evidence = a suggestion needing a click; "in shots" reuses Step U2 d (count measure, else step 3 shot/bottle ml,
  else ask); "66 shots" cells / measure headings skip it (no double conversion); costs never divided by N.
- Tests engine 613/0, drink_prices 150/0, import_stock 259/0, ai_import_suggest 38/0, stock_to_menu 43/0. QA (opus) PASS.
- Liwonde a768fe6, Rosalyn's port 80f4a9b (both pushed; owner deploys).
- LATER: unanswered rows still show "57" next to the question; N from step 3 sizes is NOT saved as the item's count measure
  (after import, stock screens show e.g. "2.28 bottles", not bottles + shots); count_row has no row lock (as answer).

## Shipped 2026-11-22: admin phone + tablet polish (owner PATCH, CSS + class tagging only)
- Owner: Ingredients and other pages not mobile friendly; cards should be compact like the Bookings rows; ticks and buttons
  uniform; NOTHING changes above 1024px.
- Round 2 (ClaudeWorkTwo): 32b8840 / port 1f483b2 (details in the commit message + GUIDES_AI_SYNC.md). LATER: Bookings
  rows ~713px at 820 tablet; row-button names go stale if a page swaps button text in place; Credit Notes sort by Issued
  has no visible column on phones.
- admin/js/admin-mobile.js: markCompactRows() tags card-mode rows only (tick / primary / status / money / actions cells,
  data-short labels, icon-button counts). Nothing is moved or renamed. Guarded to <=1024px. Opt-outs: table class
  bookings-table, receipts-table, rh-own-rows, and tables inside .pos-acct-close-form / .pos-acct-sales-wrap (own rules).
  Page opt-in classes on a td: rh-ph-primary, rh-ph-status, rh-ph-meta, rh-ph-money, rh-ph-skip.
- admin/css/admin-uniform.css (appended, every rule inside max-width media): compact list rows for table.mobile-enhanced
  (tick left, primary bold + status, muted "Label value" lines, up to 3 icon buttons on line 1 else a wrapping row,
  "Tick all" slim row from the thead), 18px ticks (bulk tick keeps its 44px area), 40px / 8px toolbars and header buttons,
  chip/tab rows scroll in one row at <=640px, stock-toolbar grid.
- housekeeping.css, room-maintenance.css: room cards as compact rows (Quick Assign + Guest has left side by side).
- stock-ingredients.php: Yield % cell tagged rh-ph-skip (hidden in card rows only; value stays in Edit).
- Test targets: Liwonde only. Test DB has no ingredients: checked with a client-side fixture (no DB writes).
- Ship gate: php -l + node --check, independent review PASS, browser check 68 pages at 390 (no sideways scroll, 0 console
  errors), 20 at 820, 8 at 1440 (desktop unchanged). Commit 90602cb, port c0b6177 (Rosalyn's).
- LATER: side-row icon buttons hide their text label on touch (name only in title); title attrs stay after resize to
  desktop; an icon-only value cell is treated as empty (product-management-mode.php:429); Individual Rooms / Invoices /
  Stock Clear rows still ~190px; some 44px buttons remain (Bookings, Backup, Restaurant Tables); Menu Management rows
  unseen with data. Untracked invoices/*.pdf and the deleted Database/*.txt are not part of this.

## Shipped 2026-11-22: global admin UI - uniform controls + toolbar rhythm (owner FEATURE, CSS/markup only)
- Owner: "fix the UI of these sort of things globally, the spacing ... make sure its uniform" (Blocked Dates month bar,
  raw "Room type" select).
- admin/css/admin-components.css: :where() base for bare selects/inputs/textareas in .content/.admin-content/modals
  (40px, 44px touch/narrow, one radius/border/font, custom arrow, native arrow off) + .rh-toolbar / .rh-toolbar-notes;
  old .filter-bar/.toolbar/.search-bar/.table-toolbar rows on the same spec. blocked-dates.php (classes only) + .css,
  bookings.css (filter bar), room-timeline.css (label + select on one line). POS/KDS do not load this sheet.
- Checked headless 1400/400 (+1920 Blocked Dates): blocked-dates, payments, calendar, ingredients, stock dashboard/reports,
  accounting, menu-management, bookings. qa-quick: FAIL (double arrow where a sheet sets appearance:auto) -> fixed -> checked.
- LATER: table-cell selects/inputs now 34px; Payments KPI cards very tall (was so before); ai-settings.php not checked
  (blank in headless run); room-calendar day width varies per load (JS, not this change).
- Liwonde dc4ce68, Rosalyn's port 5308220 (both pushed; owner deploys).

## Shipped 2026-11-22: Import step 3 "Put items on the menu" - drinks AND food, grouped, editable price (owner FEATURE)
- Owner: "easily add all imported stock to the menu with the price ... math logic correct ... steps as simple ... especially
  step 3"; then "Not just drinks, any sort of menu item ... harnesses AI if available but works perfectly without it ... 100%
  confident ... group accordingly". Owner chose: ONE table in step 3, nothing ticked by default, "Put all N on the menu".
- Step 3 section groups rows by the file category; group Menu (Drinks/Food) + menu category; row: Sold as, sizes (drinks),
  cost of one, selling price (editable, drinks_set mode price, parsed like stock-to-menu), profit, status, On menu tick;
  Put all / Put all in this group. Food rows go to the food menu + till with stock link. Step 4 = one summary line.
- Certain signals only (already on a menu > file/stock category match > drink-only measures); unclear = "Choose: Drinks or
  Food", untickable until answered; an item already on a menu never changes menu (row answer for the other menu = blocked).
  Ambiguous words (can, bottle, water...) decide nothing. Assistant: menu + category suggested inside the same single call,
  shown "Suggested ...", applied only by "Use this"; the served-by reading is a suggestion only.
- Math: profit = price - cost rounded 2 dp (as stock-to-menu); money shown 2 dp (2,521 vs 2,521.01 were both right).
  REPORTED, not changed: profit compares a VAT-inclusive price with cost (owner decides).
- Tests import_drink_prices 142/0, ai_import_suggest 38/0, import_engine 592/0, stock_to_menu 43/0. QA (opus): FAIL on 1
  blocker (group answer beat "already on a menu") -> fixed in the one fix round -> PASS. Job 2402 still loads (152 rows).
- Liwonde 78f3802, Rosalyn's port 275badb (both pushed; owner deploys). Alias seed v10 (menu words) applies itself.
- LATER: kilo food item with blank served-by takes 1 kg per sale; seed v10 alias words; supplies with a served-by cell but
  no price show "Needs price".

## Shipped 2026-11-22: Blocked Dates calendar - block / unblock single rooms on a month grid (owner FEATURE)
- Owner: "Fix the logic and UI for blocked rooms to also look like calendar so we can individually block individual rooms. Make sure logic is perfect."
- admin/blocked-dates.php opens on a Calendar view (List view kept at ?view=list): rows All rooms / each room type / each room
  (a joined room = one row, same lanes as the Calendar via rh_calendar_load), columns = the month's nights. Click, drag,
  shift-click or click a room name to select nights, then Block (type + reason) or Unblock. New action grid_apply: one
  transaction under the blocked_dates lock, all or nothing; past nights refused; joined room = both rooms; re-block updates
  type/reason; unblock removes only the row's own block and says when a room-type / all-rooms block still covers the night.
  Booked nights show a blue bar, booked + blocked a red ring. Block Rooms permission enforced (grid view-only without it).
- Logic fixes: inactive room refused with a clear message; List filter dates validated; filtering by one room no longer
  lists other room types' blocks; unblock id precedence bug; refresh after a modal save now also works from an empty list.
- New admin/js/blocked-dates-grid.js; CSS in admin/css/blocked-dates.css (44px targets on touch). Tests:
  scripts/tests/blocked_dates_grid.php 28/28 (not yet in run_tests.php: another profile has that file open), block_rooms 42/42.
  QA (opus): FAIL on 2 blockers (type-row unblock message, 44px touch targets) -> fixed in the one fix round, re-tested.
- Liwonde 8efdd57, Rosalyn's port e44ff36 (both pushed; owner deploys).
- Follow-up 2026-11-22: past nights mixed into a selection are now named in the message ("N past night(s) ... skipped");
  blocked_dates_grid.php (29/29) registered in run_tests.php. QA PASS. Liwonde bb127b6, Rosalyn's port 45595e0.
- Gaps fixed 2026-11-22 (owner "fix gaps and check block logic again and individual room logic"): a past night is counted
  once per row (also when a joined room is posted as both members); the unblock "none were blocked" message now names
  skipped past nights; a half-blocked joined room (old data) counts as newly blocked when completed, not "already blocked".
  Re-audit: room-type / all-rooms blocks stop type sales (checkRoomAvailability), room blocks take one room off the
  type count and stop that room (checkIndividualRoomAvailability), stay changes re-check both (rh_validate_booking_stay_change),
  same-type room moves rightly ignore type blocks (the booking already holds the type). Tests: blocked_dates_grid.php 43/43
  (new "Availability follows the blocks" section, rolled back), block_rooms 42/42. QA PASS. Liwonde 8b60ae5, Rosalyn's port c3fccbe.
- Public booking calendar 2026-11-22 (owner "fix the calendar so fully blocked types show greyed out and the other issue"):
  booking.php now also greys a night when every room of a type is off (each blocked, Maintenance / Out of Order, or a
  maintenance window; joined type = every pair has a room off), via new includes/blocked-nights.php. js/booking.js check-out
  picker: check-out ON a blocked date allowed (night before counts), dates crossing a blocked night greyed once check-in is
  picked. "Other issue" read as the test that could skip: the availability tests now search for free nights. Tests 50/50,
  browser check passed (seeded + removed 16 ZZ blocks on Liwonde), QA PASS. Liwonde 7f33c58, Rosalyn's port 959faa4.
  LATER: clearing check-in does not redraw the check-out picker; getDateRange (pre-existing) uses toISOString.
- Messages name rooms + dates 2026-11-22 (owner "when blocking dont say blocked rows, specify the room names and the date"):
  calendar summary, Block/Unblock confirm and the result now read "Blocked 6 night(s): Villa 2 on 3 to 5 Jan 2028; ..."
  (back-to-back nights joined, "Room 101" for numbered rooms); List-view form messages name the room/type and date; "row"
  wording removed from legend/help/tooltips. api/blocked-dates.php (key API) messages unchanged. Tests 53/53, block_rooms
  42/42, browser check passed. QA PASS. Liwonde c319a62, Rosalyn's port c10fd66.
- Logs + booking-aware logic 2026-11-22 (owner "make sure there is proper logic and logs for these things as it works
  directly with bookings"): new includes/block-log.php. Every block / unblock (calendar, List-view forms, bulk unblock,
  integration API) writes one Staff activity row ('Blocked rooms' / 'Unblocked rooms': who, rooms + nights, type, reason,
  bookings already on those nights, "via the integration API (client)") and a note on each affected booking's History
  ("Room blocked on a night of this stay" / "Block removed ..."). Calendar saves log inside their transaction. Block now
  asks the server first (preview_block, read only) and the confirm lists the bookings that will NOT be moved. API PUT
  (delete + re-create) is one transaction now (a failed re-create used to lose the old block). Tests 62/62, block_rooms
  42/42, booking_history 88/88, system_logs_activity 116/116, browser check passed.
  QA: FAIL on 1 blocker (API date-list unblock read type blocks as the room's own -> false log/note), fixed.
  Liwonde 8593d48, Rosalyn's port 25eb701. LATER: the API still blocks one member of a joined pair on its own (the
  screen blocks both); a same-date API PUT notes the booking twice; generic page_action row still written too.

## Shipped 2026-11-21: Assistant Settings "Fit limits to the cap" + layout fixes (owner FEATURE)
- Liwonde 57e21db, Rosalyn's port 1b96445. Owner: "for Budgets and limits be able to auto set to the best capacity to fit
  budget and fix the UI in assistant settings".
- Budgets and limits: Monthly cap first; "Fit limits to the cap" fills Daily limit per person + Most tokens for one question
  (rh_ai_fit_limits): people with access + nightly checks, 31 days, one question past the daily limit, Primary price,
  10% kept back. Shows what the current limits could cost (Liwonde now: 2,000,000/day = up to ~US$106/month vs US$5 cap).
  Nothing saved until Save limits. The cap itself still never stops chat (owner decision).
- UI: pickers no longer cut off, sub-headings in the panel font, Models table readable and fits, prices $0.30,
  "Nightly checks" in Usage, dashboard link as a button.
- LATER: the 1,000-token floor can put a tiny cap over (shown as "over"); page is long (8 panels) - a jump bar if asked.

## Shipped 2026-11-21: Assistant Settings model prices automatic, "Monthly cap" (owner FEATURE)
- Liwonde 0637b87, Rosalyn's port 74871bb. Owner: "Check AI selector in AI settings and no need for us to set AI prices,
  you do it automatically, we just set max cap"; owner chose the cap stays information only (never stops chat).
- Models table: no price boxes; prices read automatically (Google page; public LiteLLM price list for Anthropic, OpenAI,
  xAI, DeepSeek and Google models the page lacks) on the daily refresh, Check for newer models and Test connection.
  A model with no price yet cannot be ticked. A known price replaces any old typed one. "Monthly aim" -> "Monthly cap".
- Selector check: tick/untick/role logic works. Liwonde's two Anthropic rows (Opus 5.5, Haiku 5.5) have no price because no
  Anthropic key is saved there (never synced); they get prices once a key passes Test connection.
- LATER: cache the 5 MB price list once per daily run (now read once per provider); Test connection waits up to 20 s more.

## Shipped 2026-11-22: Put stock items on the drinks menu (owner FEATURE)
- Owner: "be able to add menu items from stock imported in the simplest way"; chose BOTH: Stock Items list action and a
  Menu Management shortcut. (Same day: Rosalyn's "no add to menu in step 4" = job 780's file had no Selling price
  column, owner's slip; checked read-only on Rosalyn's import_jobs.)
- New page admin/stock-to-menu.php (+ admin/includes/stock-to-menu-lib.php, js/css): per item name, Sold as, price (VAT
  incl.), category, cost of one, profit, status (new / price change / same / cannot + why) -> "Add N to the menu".
  Stock Items: tick column + "Tick all shown" + "Put N on the menu"; Menu Management Drinks tab: "Add from stock".
  Permission menu + modules pos+stock. Import's write part refactored to import_drinks_menu_write(), shared.
- Liwonde 49ddf7d, Rosalyn's port 1af07fa.
- Tests stock_to_menu 27/0, import_drink_prices 105/0, permission_tiers 169/0, menu_room_service 23/0; menu_portions
  170/1 pre-existing (needs a stock item; test DB empty). QA PASS.
- Minor fixes (owner 2026-11-22 "Fix minor points"; Liwonde 17d28dc, Rosalyn's port fb2cf48): commas only as thousands groups ("1,50", "0,500" refused
  with a plain message), ticked ids POSTed in one field (cap 5000, said plainly), costs/profit only for stock_management
  (never sent to Menu-only users), import stop wording says "Nothing was imported" once. Tests 43/0, 106/0. QA PASS.
- LATER (was): price "1,50" reads as 150.00 (commas dropped as thousands); 1000+ ticks capped in the URL; import link error
  text now says "Nothing was saved"; Menu-only users see stock costs; staff-activity label for drink_menu_from_stock;
  Stock Items rows tall at 1400.

## Shipped 2026-11-21: Stock quantities shown as "bottles + shots" (owner FEATURE, display only)
- Owner asked why stock shows 4 decimals (stored 6 dp so a shot = 0.033333 bottle adds up), then: "show bottles + shots".
- config/database.php rh_stock_qty_words / rh_stock_qty_html (exact figure on hover) / rh_stock_qty_sort_attr (raw
  data-sort-value for the shared sorter) / rh_stock_measure_attach: "2 bottles + 7 shots" for items with a count
  measure, "short by ..." below zero, "over by / short by" for count differences, no measure = max 2 dp + unit.
- Screens: Stock Overview, Ingredients, Stock Reports, Reorder, Batches, Wastage, Stock Count (incl. live Difference),
  Dashboard low stock. Stored figures, inputs, CSV/Excel exports and logs unchanged. Tests stock_qty_words 39/39,
  stock_count_measures 103/0, stock_list_filters 85/0; stock_count_approval 124/1 pre-existing (0 stock items).
  QA: FAIL on sort order -> fixed in the one fix round (data-sort-value on every changed cell), checked.
- Liwonde dced1a0, Rosalyn's port 8686663 (config/database.php hunk applied with git apply; Rosalyn's RBH lines kept).
- LATER: Reorder "Suggested" and reports delta columns still plain numbers.

## Shipped 2026-11-21: Import page UI - clear step plan, foldable sections, pinned footer (owner FEATURE, UI only)
- Owner: "fix the UI of the import process ... cannot fold things ... endless scrolling with an unclear step plan ...
  Logic is fine but the UI can be better".
- Step bar with one-line descriptions + ticks; "On this step" box per step (numbered actions, live counts, Open all /
  Fold all); every big block a native <details> fold with Required/Optional/Done badge + one-line summary (needs-you
  open, optional/done folded; remembered per job in localStorage); Back/Continue/Confirm and import pinned in a footer
  (same single confirm handler and can_confirm rule); one-sentence intros + "More about this". No post()/payload change.
- Tests import_engine 592/0, import_drink_prices 105/0; import_export_test 100/1 = pre-existing (test DB has 0 stock
  items after the owner's Clear Stock Data 2026-11-20 18:00). QA PASS.
- Liwonde 79d0a4a, Rosalyn's port d14bca5.
- LATER: Assistant bubble can overlap the footer's right end on desktop; footer ResizeObserver may loop briefly when
  pinning; scroll/resize listeners never removed; pagers 10/25/50/100 not All.

## Shipped 2026-11-21: Import step 3 sizes - tidy table + mass set + per-row "Sold as" (owner FEATURE)
- Owner: "fix the UI table perfectly and also be able to mass set like if default shot size is 30ml and you have a lot of
  bottles to bundle or even change within whether by glass or shot".
- Compact table (short "Menu in step 4" badge, dashes for not-applicable cells, cards on narrow screens); ticks per row +
  "Tick all shown" (search/Show/Group, all pages) + bar "Change several drinks at once" (Sold as, shot, glass, bottle
  size) -> POST sizes_bulk, each value checked as on its row, one transaction, changed/skipped reported.
- "Sold as" override (answers _sizes.r.<key>.sold; shot / glass / whole unit / Drink measures list names) is read only
  in import_drinks_build: menu side only (cost, link, tickability). Opening count, count measure and the Drink measures
  list are untouched (tested). Tests import_drink_prices 105/0, engine 592/0. QA PASS. Rosalyn's port 6fb1abe.
- LATER: Tick all shown posts every key (PHP max_input_vars 1000 would cut past ~995 drinks: send all=1 + filter);
  card-mode tick box 22px (<44px); a bulk save builds the list ~5 times; rows-per-page is 10/25/50/100, not All.

## Shipped 2026-11-21: Import step 4 "Drinks for the menu" - Tick all / Untick all (owner FEATURE)
- Owner: "Be able to select all and add to menu during a stock import".
- New drinks_set mode `tick_all` (includes/import/drinks.php): ticks every tickable drink the list shows (Search + Show,
  every page); Untick all clears them. Menu permission still required; commit re-checks ticks. Tests in
  import_drink_prices (67 pass). Port to Rosalyn's: drinks.php, admin/data-import.php, admin/js/data-import.js.
- Ported to Rosalyn's 3c3b30e (owner OK 2026-11-21, together with the Shot size below).

## Shipped 2026-11-21: Import step 3 "Shot size (ml)" in the sizes section (owner FEATURE)
- Owner: "set the shot size in advance in step 3 ... same as Glass size (ml) ... without messing up any stock count logic
  ... dynamic ... especially if we also have AI assistance ON".
- Shot rows (new alias kind shot_words: shot, tot; aliases seed v9) get a group + row shot size; order row > served-by
  cell > Assistant reading (same single call per import) > group > item's count measure > Drink measures list (25 ml).
  Shot rows are listed even when the bottle size is known. Spec + order: .claude/IMPORT_PLAN.md "Shot size in Step 3".
- Link: list size = today's "shot" link; other size = ml line (shot ml / bottle ml per sale; till tile "35 ml").
  Never changes the opening count, measure_per_big or the Drink measures list (tested). Tests: import_drink_prices 87/0,
  import_engine_test 592/0. QA PASS. ASSUMPTION: a typed shot size is for this import only, not hotel-wide.
- LATER: group shot size beats a count measure (such rows then need a bottle size); no warning when a bottle typed later
  is smaller than the shot; "Tot 35ml" now groups as m:shot (an in-progress import's old ml:35 group answer is dropped);
  till tile says "35 ml" not "Shot · 35 ml".
- Ported to Rosalyn's 3c3b30e (owner OK 2026-11-21).

## Shipped 2026-11-21: Menu Management Room Service tab + drink categories (owner FEATURE)
- Liwonde 7c341f4, Rosalyn's port 6ee2bc2.
- Owner: "missing a room service menu in menu management; drinks need to be split in categories within the drinks menu".
  Owner answers: live drinks all sit in ONE category; Room Service tab = all items with an "On room service" switch.
- Third tab "Room service" (?tab=room-service): food + drinks by category, switch saves at once (POST
  `toggle_room_service`: web row + till copy, one transaction), filter On / Not on / All. Drinks tab: category buttons +
  "Move selected to category" (POST `menu_move_category`, via `rh_menu_pos_sync_item` so the till copy + stock link
  follow; same-name in target skipped; one transaction per item; logged). Drinks moves open to anyone with page access;
  food moves + Delete selected/all stay with Clear Stock Data holders (server-enforced).
- Tests: new scripts/tests/menu_room_service.php 23/0; menu_portions.php 170/1 (the 1 = pre-existing stock-recipes
  inactive-category check). qa-auditor: 1 blocker (server check on food moves) fixed + test added. Browser check
  desktop + 390px. Test clean-up also removed Liwonde's empty old "ZZ Test Category" (id 988177).
- LATER: move log text is cut to 140 chars in System Logs; the menu lock is held for a whole bulk move (an add/update
  made at the same time waits up to 10 s).

## Shipped 2026-11-21: POS top bar spacing (owner PATCH)
- Owner: top bar "very zoomed, too squeezed, not enough spacing". At 1181-1700 CSS px (e.g. 1920 screen at 125%) the
  stats row was a 6-column grid with 7 stats (Card wrapped) and 7px labels. Fix: appended block at the end of
  `admin/css/pos-overrides.css` (roomier brand/tiles; stats one row, one column each). Liwonde 733adeb, Rosalyn's
  2505b27. Review PASS; browser 1536/1300/1200/1920/1024.

## Shipped 2026-11-21: POS drink tiles show the serving and other servings' prices (owner FEATURE)
- Owner: "show whether it's a shot or glass for drinks and how much, and in small how much the smaller item is" + "price
  of the smaller serving and also price of the bottle". Bar / coffee-bar tiles whose stock link is ONE stock item show
  "Shot · 25 ml" / "Bottle · 750 ml" / "Can · 330 ml" under the name and, in small under the price, the menu price of the
  same drink's other till items (real prices only, 86'd left out, max 3). Cart line shows the serving.
- `admin/includes/pos-drink-servings.php` (new), `admin/pos.php`, `admin/css/pos-overrides.css`. Liwonde 829c657,
  Rosalyn's 2274258. Test `scripts/tests/pos_drink_servings_test.php` 29/0; QA (opus) PASS; browser 1400 + 390.
- Data: tiles stay plain until a drink is linked to its stock item (one line) and Stock has the bottle size or the
  bottle/shot measure. Liwonde's menu has almost no drinks now, so it was checked with made-up drinks (removed).
- Follow-up (owner 2026-11-21 "fix the room service bottle gap too"): other servings carry show_pos/show_rs and the tile lists the ones for the till's mode, so a Room-Service-only bottle shows in Room Service mode. Test 34/0, QA PASS, browser both modes.

## Shipped 2026-11-21: Assistant "Invalid function name" fault (PATCH, owner report)
- Gemini refused every question (HTTP 400): the privacy vault's last pass rewrote tool NAMES when a vaulted guest
  word matched one (`group_breakdown` -> `[GUEST_1]_breakdown`). Fixed in `includes/ai/ai-vault.php` (names, schemas,
  call ids/signatures left alone; all text still scrubbed) + guard in `includes/ai/ai-providers.php` (invalid-named
  tools dropped and logged). Liwonde 687dab0, Rosalyn's port 4b42333. qa-auditor PASS; 4 new checks pass.
- Follow-up 450455f (port 79287ed): briefing links wrap in the panel, docblock + once-per-request warning,
  hidden username field on the key forms, Gemini 4 test fixed. Its unpriced-model rule cut the Models top 10
  to 4 and was undone in bdd6519 (port 004aab9); the stale "not announced" test now checks notice == table.
- `ai_assistant_test.php` is >1h against the remote DB; run sections with AI_TEST_OWNER_ROUND / *_ONLY flags and
  SKIPSW=1 SKIPRQ=1 (the claim-check self-test then fails only on its count). 2026-11-21: owner round to end 514/1.
- Shipped (owner screenshot): named guest "money unpaid" said none, "(ID [number removed])" in booking lists,
  "total for all rooms" = MWK 0.00. Now answered locally from the booking-screen folio figures (no provider call);
  booking-id-only "(ID n)" dropped before the scrub; claim check no longer swaps in a guest-credit 0.00 or cuts
  names at "DR.". Files: includes/ai/ai-money.php, ai-desk.php, ai-claims.php, ai-agents.php + test
  (AI_TEST_MONEY_1121_ONLY: 234/0; sync 292/0; owner round 524/1 count floor). qa-auditor PASS.
  Liwonde f933bd8, Rosalyn's port de2daae.
  LATER: (1) claim check: a money claim whose label has no money word now accepts any number in the evidence
  ("total for all rooms is MWK 0.00" passes if a 0 is anywhere); move the money-word rule into
  rh_ai_claim_candidate (ai-claims.php ~1226), not rh_ai_claim_labelled (~1080). (2) Same-name guests are merged
  under "N bookings under this name" (references listed). (3) rh_ai_money_last_search_ids does not re-check
  search_bookings (answer never names the guest). (4) Hotel-wide "total unpaid" with a booking in focus answers
  for that booking only.

## Shipped 2026-11-21: Housekeeping, Maintenance, Room Service like the calendar (owner FEATURE)
- Owner answers 2026-11-21: pages = "All three"; scope = "Same look + room timeline". Design:
  `.claude/CALENDAR_UI_DECISION.md` §10.
- Each page: calendar toolbar, filter cards, Timeline | List (phone opens List), side drawer with the page's own
  buttons. Housekeeping = tasks by due day; Maintenance = every open job From..To (hatched if it blocks the room; the
  calendar still draws only block_room = 1); Room Service = today's orders by room x hour, late edge = KDS 600 s rule,
  still one timer. Calendar maintenance blocks open `room-maintenance.php?id=N&view=timeline`.
- Files: `admin/js/room-timeline-ops.js`, `admin/css/room-timeline-ops.css`, `admin/includes/ops-timeline-{view,data}.php`,
  `admin/api/{housekeeping,maintenance,room-service}-timeline.php` (read-only GET, page permission + module gate),
  seam `cfg.mode` in `admin/js/room-timeline.js`. Test `scripts/tests/ops_timeline_test.php` 114/0 (read-only);
  calendar_timeline 50/0, calendar_timeline_actions 55/0.
- qa-auditor (opus) PASS; browser 1400/768/390 no console errors (Liwonde had no open maintenance jobs or RS orders).
  LATER items fixed same day (owner: "fix the left for later changes"): phone `.tl-hk-link` 44x44 with the phone
  room column 64 -> 92px (CSS `--tl-room-w` + `roomW` in room-timeline.js / room-timeline-ops.js, keep all three
  equal), room numbers wrap; "Hover for insight" / "Quick Insights" -> "Click to see these" / "Details". No change
  needed: compact rows are mouse-only; the RS list already gets the shared pager. qa-quick PASS.
  Model: owner 2026-11-21 keeps Sonnet leads; Opus only for money/stock/bookings/auth/security logic (RULES.md).
- Commits: git log "Ops pages like the calendar" (Liwonde) / "Port of Liwonde" (Rosalyn's).

## Shipped 2026-11-21: Room Timeline calendar (owner FEATURE)
- `admin/calendar.php` now opens a room x date timeline. The old month grid is at `?view=month`. Tabs: Calendar / Housekeeping / Maintenance. Phone (3 days + Agenda) and tablet (7 days) layouts.
- Design: `.claude/CALENDAR_UI_DECISION.md`. Data: `admin/includes/calendar-data.php`, shared by both views. JSON: `admin/api/calendar-timeline.php`. JS: `admin/js/room-timeline.js`.
- Step B: moves, assigns and nights changes go through the existing bookings.php actions, with a confirm before each save.
- Liwonde f011c65 + 1f3658e, Rosalyn's cadda61 + f8a9ec2. Tests `scripts/tests/calendar_timeline_test.php` (50) and `calendar_timeline_actions_test.php` (55), both read-only.
- LATER items are in Rosalyn's TASKS.md. The guides and Assistant entries are pending in `.claude/GUIDES_AI_SYNC.md`.

## Shipped 2026-11-21: Menu Management Delete selected + Delete all (owner FEATURE)
- Tick boxes, Select all shown, Delete selected and Delete all (food/drinks/both, typed DELETE) on admin/menu-management.php,
  only for an active administrator with Clear Stock Data (data_reset, rh_sc_authorize); server re-checks (403).
  Same per-item rule as one-item Delete (rh_mm_delete_one). System Logs rows menu_items_bulk_deleted / menu_items_all_deleted.
- Liwonde d2aff50, Rosalyn's port 5ac2f4a. Tests menu_portions.php section 4 (rolled back): 170/1; the 1 fail is the
  pre-existing "Stock > Recipes: inactive category" check (stock-recipes.php, untouched). qa-auditor PASS; browser check desktop + phone.
- ASSUMPTION: product-catalog mode (non-restaurant presets, includes/product-management-mode.php) not covered.
- LATER: 44px hit area for the tick boxes / phone buttons; "nothing was changed" wording if logActivity ever throws after deletes.

## Clean-up 2026-11-21 (owner-approved, `cleanup` skill)
- Nothing sensitive exposed in git. Secret-looking values in `scripts/tests` are made-up test data (kept).
- To the Recycle Bin (local, not in git): 1,134 old `scripts/tests/sessions` files, 1 log older than 30 days, the 7
  August migration dumps in `Database/` (incl. a copy of Rosalyn's live schema + seed), 8 backups older than 30 days.
- Kept: `images/gym/personal-training.jpg` (used here; 8.1 MB, could be compressed), `includes/test-platform-mail.php`
  (real feature), all tests (per-area regression tests), invoices, cache, vendor.

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

## Shipped: the Assistant set up from the licence panel (owner 2026-11-11)
The owner sells the Assistant as a package with the HMS. The licence panel now holds each hotel's AI provider key and
models, tests them there, and sends a tested set-up (panel `public/ai.php`, see `../msp-control-panel/STATUS.md`).
The same change is in both hotels (identical files):
- `includes/licence-ai.php` (new): runs a staff command of kind `ai_setup` from the signed licence answer. The AI key in
  the answer is encrypted for this site only (key derived from its licence key). The hotel tests the key and every chosen
  model ITSELF first and changes nothing if that fails; then saves the key the Assistant's usual way (`rh_ai_store_key`,
  needs `APP_ENCRYPTION_SALT`), refreshes the model list, gives the chosen models their roles (`rh_ai_save_selection`),
  sets the monthly aim and, if asked, switches the Assistant on. Activity log: "by the system provider (licence panel)".
- `includes/licence-staff.php`: accepts `ai_setup` (it has no username); the staff report carries an `ai` block (on/off,
  provider, key's last 4, models, monthly aim, this month's usage). Never the key.
- `scripts/tests/licence_ai.php` (new, 30 pure tests; `licence_staff.php` still 53/53).
- Admins here may still change the Assistant in Assistant Settings; the panel never re-applies anything until the owner
  sends a new set-up. No schema change.
- Pushed with the owner's OK (both hotels first, then the panel). Not yet run against a real provider: the first
  real send from the panel is that test.

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
