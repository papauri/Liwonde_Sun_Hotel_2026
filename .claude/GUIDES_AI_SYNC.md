# Guides + Assistant sync — what's been done since the last sync

Owner 2026-11-14: "Keep the guides and AI Assistant always for last. Have a document that just updates
the guide and Assistant on what's been done. We will do these when prompted at the end of the day or
when prompted."

## Rules
- **Never run this on your own.** Guides (`guides-kb-lead`) and the Assistant (`dept-ai-lead`) are
  updated ONLY when the owner says so ("do the sync", "end of day", "update guides/Assistant").
  Real work always ships first and never waits for them.
- **Every ship adds one entry below** (orchestrator, in the same commit as the ship): commit hashes
  (Rosalyn's / Liwonde), what staff now SEE or DO differently, and what the Assistant reads that
  changed (tables, columns, settings keys, statuses, helpers, page URLs, permission keys). Pure fixes
  with no visible change and no Assistant overlap: one line "no sync needed".
- **When prompted:** one `guides-kb-lead` brief (haiku) for all Guides items, one `dept-ai-lead`
  brief for all Assistant items (only if a grep of `includes/ai/` finds an overlap), ONE combined
  review, ship both hotels, then move the done entries to "Synced" with the sync commit hash.
- Both hotels share this list; the file lives in both repos (keep them identical).
- **Manager Guides (owner 2026-11-19: "always up to date"):** illustrated 2-page PDFs in
  `OneDrive\MSP\Manager Guides` (outside both repos; area map + last-synced line in its README.md).
  Every sync also checks each Pending entry against that map; where a manager-level fact changed
  (a step, rule, permission, button or "coming soon" item), one `manager-guides-lead` brief (haiku)
  makes the matching `source\*.html` edit, then `php build.php` there and a page-count/visual check (2 pages
  each). Record "Manager Guides: <which> refreshed" or "Manager Guides: no change" in the Synced entry.

## Pending (oldest first)

### Ship M: guest credit is not a refund, VAT counted once (2026-11-18, owner) - hashes in TASKS.md
- Guides (End of Day page/PDF/email/WhatsApp, Reports page + CSV export, timed Accounting Summary CSV): "Refunds"
  now counts only money really paid back to a guest; money moved onto a credit note is no longer a refund. New
  "Guest credit" lines: issued, used, voided, expired unused, still owed (Reports adds "Guest credit at start").
  "Moved to credit notes" and "Credit notes redeemed" lines are gone. "VAT on payments counted" is now "VAT
  Collected" (VAT on money received less VAT on real refunds); "VAT inside credit-note payments (information only)"
  shows beside it. Payments list, Accounting dashboard and guest history keep the old refund total until Ship M2.
- Assistant: new helpers rh_refund_payout_sql, rh_vat_once_sql, rh_vat_credit_note_info_sql (finance-refunds.php)
  and admin/includes/guest-credit.php (rh_guest_credit_summary / rh_guest_credit_lines); rh_eod_refunds_to_credit and
  rh_eod_credit_notes_redeemed removed. includes/ai/ai-insights.php ~315 still says "Moved to credit notes" - check at sync.

### Ship M2 + step 13: refunds = payouts on Payments / Accounting / guest history; one payment label everywhere (2026-11-19, owner, Bundle A) - hashes in TASKS.md
- Guides: Payments "Refunds" card and Accounting dashboard "Refunds" (KPI, pop-up, reasons, trend, latest list) count
  only money paid back in cash, bank or mobile money; money moved onto a credit note shows on its own "Moved to
  guest credit" line, never in the refunds total. Accounting Net reads "Gross − refunds − moved to guest credit"
  (same figure as before). Guest history and Booking details: "Refunded" = paid back only, "Moved to guest credit"
  on its own line; Received / balances unchanged.
- Guides: Bookings Paid / Unpaid tabs, their counts, the Payment filter (part paid / refunded), the sort and the CSV
  export follow the badge (from the money: Paid in full / Part paid (MWK X left) / Unpaid / Refunded). The same
  words on booking lookup (guest page), Individual Rooms booking cards, the Bookings stats pop-up and the calendar
  tooltip (coloured like the list's pill, no Title Case).
- Assistant: includes/ai/ai-insights.php ~303/315 ("Moved to credit notes" wording, still on the settled refund
  total), includes/ai/ai-decisions.php ~1626 (refund figures on the settled rule) and includes/ai/ai-tools.php
  ~1864 (money.refunded should mean payouts only, with moved-to-credit-notes separate) - align to
  rh_refund_payout_sql / rh_refund_to_credit_sql at sync. Bookings "paid / unpaid" answers should use
  rh_bk_pay_key_sql (admin/includes/bookings-list-filters.php), not stored payment_status.

### Step 14: POS till reuses a Fire whose reply was lost; void lock order (2026-11-19, owner, Bundle C) - hashes in TASKS.md
- Guides (POS till): if the connection drops just after Fire, the next Pay or edit picks up the tab that was already
  sent (no second tab); an edited cart whose Fire had already landed shows that sent order instead of adding to it -
  the cashier re-adds anything extra. No new screens.
- Assistant: nothing to change (no AI wording touched).

### Bundle B: group emails, invoice and quotation list every room (2026-11-19, owner) - hashes in TASKS.md
- Guides: group invoice email + booked-rate lines show each room's guest (only when entered); invoice PDF room line
  shows "(N nights)"; a group quotation lists every live room and its total is the sum of the rooms (cancelled rooms
  left out); reminder, pre-arrival, check-in, cancellation, no-show, extend and modified emails list the group's rooms
  with their own dates; a cancelled / no-show room shows its state and "fee kept", never as payable. Convert to
  invoice: an old one-room quote is compared room to room, a new group quote with the group stay total.
- Assistant: quotation answers should use the group stay total for group quotes (includes/quotation-convert.php
  rh_qc_quote_amount); room lists from includes/group-room-list.php.

### Stock Step U3: Change main unit (bottle <-> shot / glass) (2026-11-19, owner, Bundle D) - Liwonde a1dba0e, Rosalyn's see TASKS.md
- Guides (08 Stock; 18 Staff Access): Stock Items > item > Change main unit switches the item between its two measures
  (needs a count measure first, e.g. bottle and shot, 30 in one). Preview lists every place that changes (on hand,
  cost of one, batches, recipes, open counts, open orders) with old -> new and the stock value before and after
  (always the same); Confirm applies it at once; History / System Logs show who, when, old -> new. New permission
  "Change main unit" (stock_change_unit, Restaurant & Stock, default admin + manager). A count made before a unit
  change can no longer be taken back on Clear Stock Data (correct it with a new count).
- Manager Guides: stock.html "Coming soon: Change main unit" becomes a real short section; staff-access.html risky
  permissions table: Change main unit no longer "Coming soon". Rebuild stock + staff-access, check 2 pages each.
- Assistant: stock answers should give quantities in the item's current unit; a unit change is in stock_unit_changes.
### Stock: "Show it in" takes a custom amount (2026-11-19, owner) - Liwonde bcb7d15, Rosalyn's 23da18b
- Guides (08 Stock): Add / Edit ingredient > Bottle size and drink measures > Show it in: last option "Other
  amount (type it)": type a name and ml (e.g. double, 35) to see how many are in one bottle and the cost of one;
  Use this for counting fills the count measure with it (saved with Save). For recipes and the menu too, add the
  measure under Drink measures. Manager Guides: stock.html measures paragraph, one line.
- Assistant: nothing to change.
### Import: Drinks for the menu (2026-11-19, owner, drinks profit projection P1) - Liwonde (hash at ship), Rosalyn's see TASKS.md
- Guides (08 Stock + Import guide): step 4 Confirm now lists "Drinks for the menu" when the file has a selling price:
  name, sold as, price (VAT included, used exactly, never added), category (file's column, else Bar; per row or
  "Set for all"), cost of one, profit of one, below-cost warning. Nothing goes on the menu unless ticked; a drink
  already on the menu shows "Update price X -> Y". Ticking needs Import + Menu permissions. Ticked drinks go on the
  drinks menu and the till (bar) with a one-measure stock link; History / System Logs show old -> new.
- Manager Guides: stock.html import paragraph, one or two lines. Rebuild stock, check 2 pages.
- Assistant: answer "which drinks did the import offer for the menu" (AI_REQUEST to the AI lead; data in the
  import job's answers `_drinks` + activity log `drink_menu_import`).
## Synced 2026-11-16 (guides + Assistant: every entry from 2026-11-14 to 2026-11-16 below, including the 2026-11-15/16 entries further down; hashes in TASKS.md) - entries kept for reference

### Import stock check: value column warning + stock check workbook (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, review / confirm / result / history): when the sheet's value column (e.g. "Stock Value")
  disagrees with Quantity x Unit Cost, a warning shows how many rows and how much more/less (MWK), with the rows (10 per
  page). "Download stock check" gives a workbook: Summary, "<column> wrong" (biggest gap first, red = sheet shows more,
  green = less, implied quantity, blank Manager's explanation column) and Missing prices (items with stock but no cost,
  same count as the "no cost" list before Import). Offered in review, after Import and from Import history; logged as
  stock_check_export. Never changes what is imported.
- Assistant: result_json gains value_check / stock_check keys (includes/ai/ai-import.php reads result_json) - check at sync.

### Import wizard reads more column headings (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, step 2): the wizard now recognises our own Stock > Ingredients export headings and common
  variants (Current Quantity level / Current Qty = Balance; Unit Cost / Cost/Unit / Average cost = Unit price; Max qty /
  Order up to = Par; Next Expiry = Expiry; Lead Time greyed). "Min Qty / Minimum Quantity level" is greyed when the sheet
  has a Reorder Point column, otherwise used as the reorder level. Headings with total / value / amount / days are only
  used on an exact match, otherwise asked.
- Assistant: no change in what it reads (vocabulary only); no sync needed beyond noting new field ids min_qty/lead_time.

### Wipe all stock (full reset) permission + section (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Settings > Clear Stock Data, User Management > permissions): new permission "Wipe all stock (full reset)"
  (super admins always; a plain administrator only when a super admin switches it on; never managers/staff). Clear
  Stock Data opens for it and shows a top section "Wipe all stock and every record": preview, then tick I understand +
  type WIPE ALL + own password; removes every stock table's rows plus Import history; full backup + snapshot; the
  stock part can be undone from Past clears; logged as stock_wipe_all. The older sections stay on Clear Stock Data.
- Assistant: AI files and admin/system-logs.php:561 still check only data_reset; check at sync time whether the
  Assistant should mention or respect stock_wipe_all.

### Import wizard: tick all + honest not-counted reasons (2026-11-16, owner ask) — hashes in TASKS.md
- Guides (Stock > Import, step 3/4 and result): two buttons for items already in stock, "Set quantity for every matched
  item" and "Use the file's unit cost for every matched item" (each shows how many first, each can be cleared again);
  before Import a warning lists every item with stock that will NOT be in the opening count, by reason (no cost in the
  file / file cost not ticked / quantity not ticked / row skipped / already in another unfinished opening count) with a
  fix button where one helps; the result screen lists "no price" and "not ticked" separately.
- Assistant: includes/ai/ai-import.php:322 "will_update" leaves out quantity-only ticks; result_json has a new key
  stock_not_counted_not_ticked (ai-import.php:200/323/594 read the result) - check at sync time.

### Stock count wipe + Delete on Import history (2026-11-15, owner ask) — hashes in TASKS.md
- Guides (Settings > Clear Stock Data, Stock > Import): Clear Stock Data has a new section "Stock counts and the stock
  they added": lists every count, wipes all counts plus the batches/adjustments their approval added and puts item
  quantities back; full backup + snapshot first, logged as stock_count_wipe; refused (with the item named) when stock
  has moved since or an item that keeps stock had its cost averaged with the count; tick "I understand" + type WIPE +
  own password; no Undo on the page (restore from the full backup). Optional tick also removes the Import history
  entries that created the counts. Import page: Delete on finished import entries (committed/failed/cancelled) for
  holders of Clear Stock Data (administrator, super admin only unless switched on); type DELETE + own password; removes
  the entry and its stored file (backup copy kept in backups/import-delete/), does NOT undo the stock it brought in,
  the "already imported" check forgets that file; logged as import_history_delete.
- Assistant: check at sync time whether any read tool reads stock_counts, import_jobs or the data_reset permission
  wording (grep includes/ai/ai-import.php, ai-tools.php, ai-insights.php); no action tools added.

### Assistant: stay questions by first name (2026-11-15, step 8a) — hashes in TASKS.md
- Guides: staff can ask the Assistant "when did <first name> check in / out", "has X left / arrived", "who checked them
  in / out", "who cancelled X's booking", "was X a no-show", "did X extend", "was X moved". The answer comes from the
  booking's History with the time and the staff member. Lower-case names and the hotel's name in the question are fine;
  if several guests share the name it asks one question listing names, references and dates. Guide 14 / Assistant page.
- Assistant: done in this entry (ai-desk.php, ai-vault.php, ai-tools.php, ai-focus.php, ai-agents.php, evals). LATER:
  in the model path "when did X check out" can still offer a check-out card; room-number questions still go to the
  model; an undone check-in still shows the earlier check-in step; 'expired' is not a search_bookings status; fix round
  verified by tests (1217 pass / 7 known data-dependent), not re-reviewed; no live model check.

### Booking Details dialog compact on phones + tablets, row click opens it (2026-11-14, step 8) — 0a860b9 / 142aee6
- Guides: on the Bookings page, clicking or tapping anywhere on a booking row (not a button) opens Booking Details, on
  phone, tablet and desktop. On phones and tablets the dialog starts with one summary block (guest, room, dates and
  nights, total, balance, booked rate x nights); tapping it or "Full Details" opens the full booking page. Guide 07.
- Assistant: no sync needed (layout only; no table, setting, status, URL or permission changed).

### Assistant sync (2026-11-14, NEXT_BUILD_ORDER step 7) — SHIPPED (hashes: see TASKS.md line)
- Guides: staff can now ask the Assistant "what is the cancellation fee on <booking>" (fee from the booking's locked
  policy, booked rate x nights, tier and days before arrival; pending/tentative/expired = no fee; an already cancelled
  booking shows the fee kept and what is still owed), "what does each room in this group owe", and "who changed the
  <name> setting" (needs staff activity + System Logs). The Assistant quotes a fee but never lowers or waives one:
  that stays on Booking Details. Mention in guide 14 / the Assistant page at the next guides sync.
- Assistant: done in this entry (includes/ai/ai-money.php, ai-actions.php, ai-tools.php, ai-desk.php, ai-vault.php,
  ai-insights.php, ai-decisions.php). Live check at Liwonde on gemini-3.5-flash-lite: 10/10 after fixes, US$0.021 total.
- LATER (not pulled): Assistant Settings saves write no settings-change rows; still-owed list can stop at 300 rows
  without saying so; vault stop words (staff, admin, manager, test, user ...) also apply to guest name parts (full
  names still hidden); staff-count test repeats its rule in SQL; History Summary check misses figures written without
  commas; settings-lookup refusal names the wrong missing permission; 10 money cases in ai_assistant_test.php have no
  refuse-at-Rosalyn's guard; 7 data-dependent fails at Liwonde (briefing left-owing + refunds, 4 alert checks, digest
  recipients) - they also fail on the previous code; idea: cancellations decision could offer the policy as an option.

## Synced 2026-11-14 (guides: step 6, 844f21f / 4a02c11 · Assistant: step 7) — entries kept for reference

**Both parts of every entry below are DONE. Stashes "PARKED 2026-11-14 guides + Assistant sync" (Rosalyn's) and
"PARKED 2026-11-14 Assistant sync port" (Liwonde) are superseded by the step 7 ship; drop them only when the owner says.**

### Group documents / no-show / kept fees / group charge (2026-11-11 → 2026-11-14)
- Commits (Rosalyn's): 0d2ef59, e4867fc, 28a5f0d, a3e5a95, 1e9c2a7, e291682, ec2e069, 58ea5ea, 2472594, f06fac1.
- Guides: 07, 13, 14, 19, faq — group invoices/receipts per room, no-show charges, kept cancellation
  fees owed (Still owed, EOD outstanding), kept fees + no-show charge taxed like a room night, Add
  Charge "This room / Whole group".
- Assistant: `rh_ai_noshow_money` group charge + credit (built, QA PASS 2026-11-13); align the
  `balances_due` tool (`includes/ai/ai-tools.php` ~1850) to the shared Still owed rule (owing no-shows
  + cancelled kept fees).
- PARTIAL WORK in git stash "PARKED 2026-11-14 guides + Assistant sync" (Rosalyn's) and
  "PARKED 2026-11-14 Assistant sync port" (Liwonde) — `git stash list`; re-check against HEAD before use.
- AI suite baseline: 1119 pass / 3 known fails / 10 skip.

### Hotel Settings change log (2026-11-14) — Rosalyn's 7a4cadc / Liwonde 0144ee6
- Guides: System Logs page — new "Hotel Settings changes" section (When / Who / Setting / Before /
  After, secrets shown as hidden, only real changes) and the new Staff activity type "Hotel Settings
  changes"; Hotel Settings guide: "every change you save is recorded in System Logs".
- Assistant: `includes/ai/ai-desk.php` (lines ~80, ~145) reads `admin_activity_log` — new action
  `setting_change` now raises per-person action counts and shows as "Setting change" in history (no
  values). Decide: count it as a save, or exclude; optionally answer "who changed setting X / when".

### Hotel Settings order + search/filter (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: Hotel Settings guide — 5 groups (Website & booking; Rooms & rates rules; Money & documents;
  Email & notifications; Modules & app), search box, group filter, "No settings match" + Clear, Esc clears.
- Assistant: any Assistant links to `booking-settings.php#<anchor>` keep working (anchors unchanged);
  check only.

### Settings change log on the other settings pages (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: System Logs section renamed "Settings changes" with a Page column; covers Automated
  Emails, VAT (Accounting), Modules, Station hours, WhatsApp, Facebook, Gym slots/reminders, Footer.
- Assistant: `ai-settings.php` (Assistant Settings) is AI-lead only — decide whether its saves
  (provider, models, keys masked, switches) should also write `setting_change` rows
  (`includes/settings-change-log.php`: `rh_settings_log_begin/_end/_record`).

### Guest booking lookup + "Booking Received" heading (2026-11-14, NEXT_BUILD_ORDER step 1) — SHIPPED (hashes: see TASKS.md line)
- Guides: guest-facing only. Check Booking Status now lists every room of a multi-room party (same or different
  room types) with each room's guests and reference; cancelled rooms show a "Cancelled" badge and are left out of
  the guests count and the Total Amount. Bookings staff deleted can no longer be looked up or cancelled online;
  expired bookings can't be cancelled online. The confirmation page heading says "Booking Received" for pending
  bookings (was "Booking Confirmed"). Front-desk guide/FAQ lines that quote the old heading: update if any.
- Assistant: no sync needed (grep includes/ai/ for "Booking Confirmed" and booking-lookup: no hits).

### History price note on online bookings (2026-11-14, NEXT_BUILD_ORDER step 2) — SHIPPED (hashes: see TASKS.md line)
- Guides: booking History > "How the price was worked out" now shows the booked room rate × nights for bookings
  made online (website, mixed-room parties, API) from the day this shipped, the same as bookings staff create; the
  rate no longer includes packages. Online bookings made before this still say "worked out from the saved total".
- Assistant: check only. Booking History answers that read the price snapshot (audit `new_values.price` /
  timeline `metadata.price`) now find one on new online bookings; API bookings have no rate plan fields.
- Logs lead: online 'created' audit rows now carry `new_values.price`; timeline 'create' rows carry
  `metadata.price`. Audit screens list changed fields only, so nothing new shows there.
- LATER (not pulled): no "Packages: +" line in the price explanation; the history test builds rows by hand and
  never calls `rh_booking_price_snapshot_clean()`.

### Joined-room live quote (2026-11-14, NEXT_BUILD_ORDER step 3) — SHIPPED (hashes: see TASKS.md line)
- Guides: no sync needed. Guest website only: a party that needs two or more joined rooms now sees a quote that
  adds up each joined pair at its own price, the same figure the booking is saved with.
- Assistant: no overlap (check-availability.php and the new includes/joined-quote.php are not read by includes/ai/).
- LATER (not pulled): the quote can still say a joined type is free when fewer separate pairs are free than the
  party needs (pairs sharing a room are counted twice); booking.php refuses at submit.

### Cancellation & no-show policies + guest whole-party cancel (2026-11-14, NEXT_BUILD_ORDER step 4) — SHIPPED, switch OFF (hashes: see TASKS.md line)
- Guides (settings): Hotel Settings > Refunds & cancellations has a new card "Cancellation & no-show policy":
  On/Off switch, up to 5 rows "Cancelled fewer than [X] days before arrival -> [N] % of the stay" (cancelling
  exactly X days before is still free; arrival day = 0), No-show %, optional separate policy for groups of N rooms
  or more, optional extra note, and "What guests will read" preview. The No-show refund rule is now visible and
  editable there. Only people with Finance settings can change it. At Rosalyn's the card says it needs the
  database update until migration 085 has been run.
- Guides (bookings): each booking made while the switch is On keeps the policy it was made under (Booking Details
  panel "Cancellation policy (locked at booking, vN)"). Cancelling a CONFIRMED booking inside a fee window shows
  the fee before you cancel ("50% of the stay MWK X (booked rate Y x 3 nights)"), what was paid and the refund;
  pending, tentative and expired bookings pay no fee. People with "Lower or waive cancellation fees"
  (administrators + managers by default, grantable to anyone) can lower the fee with a required reason, at cancel
  time or afterwards on Booking Details; the difference becomes credit owed back. History shows it. Worked example
  for the guide: MWK 100,000 x 3 nights.
- Guides (no-show): under a policy, 100% = the booked stay keeps billing as today; below 100% one "No-show
  charge - N% of the stay" line is posted and money paid above it is owed back.
- Guides (guest site, LIVE NOW at both hotels, not behind the switch): "Cancel My Booking" on booking lookup
  cancels every room in the party under the guest's email (rooms listed first; checked-in rooms left alone); one
  cancellation email per room. Parties billed to a company see "contact the hotel" instead of the button. Under a
  policy the guest sees the fee and refund and must tick "I accept the cancellation fee".
- Guides (documents): invoice, quotation, receipt, booking emails, booking lookup, the public Cancellation policy
  window, the booking summary and the create-booking review step print the policy wording (booking's own locked
  wording on its documents). Nothing prints for bookings without a policy.
- Assistant: includes/ai/ai-money.php (~:74-80) still reads only `no_show_refund_policy`; it must read
  `bookings.cancellation_policy_snapshot` and `rh_cancellation_fee_quote()` (includes/cancellation-policy.php);
  new permission key `waive_cancellation_fee`; new settings `cancellation_policy_enabled/_individual/_group/
  _group_min_rooms/_note/_version`. Step 7 covers this.
- Logs lead: new History events 'Cancellation settled' (policy kind/version/tier/%), 'Cancellation fee lowered',
  'No-show charge posted'; `rh_log_event` 'Cancellation policy changed'.
- EOD lead: lowering a fee after cancellation changes a past day's kept-fee figure (counted on the check-in
  date); a below-100% no-show charge is a tagged 'other' folio row ("guest-bill extras").
- LATER (not pulled): fee of exactly 0.01 shows "No fee" but is kept (cancellation-policy.php :489, `<` vs `<=`);
  group dialog totals `rh_booking_group_ids` while the cancel runs on `$cancelGroupIds`; lower/waive for
  legacy-kept first nights not built (ASSUMPTION, keeps the ship inert); sent reminder emails carry no policy
  text (templates/emails/checkin-reminder.php has no caller); cancellation_policy + golden tests not in
  run_tests.php; unused `cancelBooking()` JS in admin/bookings.php posts no fee (no caller; server would refuse).

### Guest booking emails: rate, rate plan, room references (2026-11-14, NEXT_BUILD_ORDER step 5) — SHIPPED (hashes: see TASKS.md line)
- Guides: guest-facing emails only (Booking received, Booking confirmed, Tentative booking created / converted,
  Quotation). Each now ends with a short block: the booked room rate x nights (never today's price), the rate plan
  the booking was made on, and for a multi-room party every room with its own booking reference (cancelled rooms
  left out). The "Rate Plan" row in the email body is no longer blank for website bookings. Nothing is added to
  cancellation, reminder, refund or invoice emails. Stored email templates were not changed; a template can place
  the block itself with `{{booked_rate_block}}` (Email templates guide: add the tag to the list if tags are listed).
- Assistant: check only. New email tag `{{booked_rate_block}}` (config/email.php); no table, setting, status or
  permission changed.
- LATER (not pulled): when the rate lines are left out (figures do not match the total), the plan's per-night
  figure still prints; a template that places the tag in the HTML only gets no block in its text part; the
  quotation's own "Rate Per Night" is on the line basis while the block shows the all-in rate (labelled).

### Failing test suites fixed (2026-11-14) — SHIPPED (hashes: see TASKS.md line)
- Guides: one small wording change. Early checkout on the day the guest arrived: the dialog, History and the
  refund reason now say the right number of unused nights (one night is still charged, so a 2-night stay shows
  1 unused night, not 2). The refund amount did not change. Everything else was test files.
- Assistant: no sync needed (includes/ai/ does not read `unused_nights`; check with a grep at sync time).
- MSP panel: a failed staff step now reports only the error type ("details in this site's error log"), never the
  error text. Liwonde has a permanent test staff account `test.staff` (receptionist, id 5351) - never delete it.
- LATER (not pulled): pin `staff_error` in licence_client.php to the known message starts; an older saved
  licence_staff_error goes out until the next staff run; late_stay_options leaves STMT-ZLS*.pdf files in invoices/;
  error_log_clear, stock_count_approval*, documents_flow, rehearsal_fixes have no refuse-at-Rosalyn's guard; past
  same-day early checkouts at Rosalyn's keep the old night count in their History text (no money effect).

## Known leftovers from earlier syncs (fix during a sync, never on their own)
- `ai_assistant_test` at Liwonde: 7 data-dependent fails (left-owing + refunds briefing, 4 alert checks, digest
  recipients); ai_review_engine "nothing to add for this person" fails on HEAD too. (Tomorrow / 4 of 3 / K1,068,000 now pass.)
- `ai-decisions` ~1620/1777 dept-takings/VAT still on payment_date (matches Reports); "Open refunds"
  link filters Payments by payment_date.
- Guide 14 says "AI Assistant" vs "the Assistant"; `rh_ai_t_guest_history` drops no-email bookings
  when one email is found; `balances_due` per-row fallback (speed).

## Synced
- Through 943ca16: Rosalyn's 55827b0 / Liwonde 717b36e (and earlier 859473d / 8f7301d, 5085f61 / 2c82b0e).
  Full history: `.claude/TASKS.md` Shipped + `.claude/archive/`.
