# SYSTEM MAP — Liwonde Sun Hotel 2026

Built 2026-09-02 by extracting table references with PHP's **tokenizer** (string literals only,
so prose in comments cannot be mistaken for a table name) and validating every name against the
**live schema**. Only tables that actually exist are listed. Method matters here: three earlier
findings in `BUILD_PLAN.md` were withdrawn because a raw grep was treated as evidence.

Agents cite this file instead of re-scanning. Counts are "distinct real tables touched".

**Live schema:** 132 base tables + 2 views. **119 referenced by code · 15 never referenced.**

---

## 1. Booking engine — 18 tables

| File | Tables |
|---|---|
| `booking.php` | booking_packages, bookings, individual_rooms, policies, room_combinations, rooms |
| `check-availability.php` | individual_rooms, room_combinations, rooms |
| `includes/booking-functions.php` | blocked_dates, bookings, payments, rooms |
| `includes/pricing.php` | rate_plans, room_packages |
| `includes/booking-widget.php` | rooms |
| `admin/create-booking.php` | booking_notes, booking_rooms, bookings, credit_notes, individual_rooms, payments, room_combinations, +1 |
| `admin/edit-booking.php` | booking_notes, bookings, individual_rooms, rooms |
| `admin/bookings.php` | admin_users, booking_charges, booking_status_log, bookings, conference_inquiries, individual_rooms, payments, +2 |
| `admin/rate-plans.php` · `admin/packages.php` | rate_plans / room_packages, rooms |

Full set: admin_users, blocked_dates, booking_charges, booking_notes, booking_packages,
booking_rooms, booking_status_log, bookings, conference_inquiries, credit_notes,
individual_rooms, payments, policies, rate_plans, room_combinations, room_packages, rooms,
tentative_booking_log.

**Note:** `bookings.room_id → rooms.id` (room *type*). The physical unit FK is
`bookings.individual_room_id`. Confusing these two caused the EOD revenue defect fixed 2026-08-15.

## 2. Reservations & front desk — 16 tables

`admin/calendar.php` · `process-checkin.php` · `housekeeping.php` · `individual-rooms.php` ·
`tentative-bookings.php` · `blocked-dates.php` · `includes/booking-timeline.php` ·
`admin/includes/booking-lifecycle.php`

Tables: admin_users, booking_audit_log, booking_date_adjustments, booking_payments,
booking_rooms, booking_timeline_logs, bookings, cancellation_log, housekeeping_assignments,
individual_room_amenities, individual_room_photos, individual_rooms, room_combinations,
room_maintenance_log, rooms, tentative_booking_log.

**Gap:** `admin/room-dashboard.php` issues **no SQL of its own** — all data comes via includes.

## 3. Rooms & inventory — 13 tables

`admin/room-management.php` (gallery, rooms) · `admin/room-maintenance.php` ·
`includes/room-management.php` · `room.php` · `rooms-gallery.php` · `rooms-showcase.php`

Tables: admin_users, booking_rooms, bookings, footer_links, gallery, housekeeping_assignments,
individual_rooms, policies, reviews, room_inspections, room_maintenance_log,
room_maintenance_schedules, rooms.

`room_inspections` provisioned by `admin/migrations/001` (2026-09-02); previously lazy app DDL.

## 4. POS & F&B / KDS — 28 tables

`admin/pos.php` · `kds.php` · `menu-management.php` · `restaurant-tables.php` ·
`order-lifecycle.php` · `room-service-dashboard.php` · `restaurant.php` · `menu-pdf.php`

Tables include: drink_menu, food_menu, menu_categories, menu_items, pos_deals,
restaurant_tables, station_messages, stock_adjustments, stock_kds_events, stock_order_audit,
stock_order_items, stock_order_splits, stock_orders, stock_recipe_ingredients, stock_recipes,
stock_shift_closes, stock_shift_opens, plus booking_charges/bookings/individual_rooms (room
service posts to the folio).

**Gap:** `admin/cds.php`, `admin/bds.php`, `admin/station-settings.php` and
`includes/station-hours.php` contain **no SQL** — they render from shared libs/session state.

## 5. Stock & procurement — 27 tables

`admin/stock-*.php` (11 pages) · `purchase-orders.php` · `admin/includes/procurement-schema.php`
· `scripts/stock-audit.php`

Tables: stock_adjustments, stock_batch_deductions, stock_batches, stock_count_lines,
stock_counts, stock_in_log, stock_ingredients, stock_kds_events, stock_order_audit,
stock_order_deliveries, stock_order_items, stock_order_splits, stock_orders,
stock_purchase_order_items, stock_purchase_orders, stock_recipe_ingredients, stock_recipes,
stock_suppliers, stock_wastage + shared (admin_users, bookings, menu_*, payments, rooms).

**Note:** `procurement-schema.php` still performs runtime `ALTER TABLE`/`ADD INDEX`. It is not
called from the connection bootstrap, so it no longer fires per-request, but it remains
app-code DDL outside `admin/migrations/`.

## 6. Finance & accounting — 26 tables

`admin/invoices.php` · `payments.php` · `payment-{add,refund}.php` · `receipts.php` ·
`credit-notes.php` · `quotations.php` · `end-of-day-report.php` · `shift-close-report.php` ·
`accounting-dashboard.php` · `pos-accounting.php` · `includes/finance-sequences.php`

Tables: credit_note_applications, credit_notes, finance_sequences, payments, quotations,
receipt_events + cross-domain (bookings, conference_inquiries, event_inquiries, gym_inquiries,
stock_*). **There is no `invoices` table** — invoices are derived from `payments`/`bookings`.

Money rails: `BALANCE_TOLERANCE` (`config/database.php`), `payment_amount` always **net**,
`vat_pricing_mode` drives room pricing while F&B prices are always gross.

**Gaps:** `admin/includes/finance-schema.php` and `includes/quotation-pdf.php` issue no SQL.
`stock_payments` is referenced by `admin/includes/reports-extra-tabs.php` but **does not exist**
— the F&B payment-split panel permanently reads as empty (owner decision pending).

## 7. Gym — 13 tables

`gym.php` · `admin/gym-{management,members,checkin,classes,inquiries,reports}.php` ·
`admin/includes/gym-{classes,schedule}-lib.php`

Tables: gym_attendance, gym_class_enrollments, gym_classes, gym_content, gym_facilities,
gym_features, gym_hours, gym_inquiries, gym_members, gym_packages, gym_slot_reservations,
payments, policies.

**Gaps:** `gym-schedule.php`, `admin/gym-packages.php` and `scripts/gym_membership_reminders.php`
contain no direct SQL (all via the `gym-*-lib.php` helpers). `gym-classes-lib` and
`gym-schedule-lib` still hold lazy `CREATE TABLE IF NOT EXISTS`.

## 8. Conference & events — 7 tables

`conference.php` · `events.php` · `admin/conference-management.php` ·
`admin/events-{management,inquiries}.php` · `includes/upcoming-events.php`

Tables: conference_inquiries, conference_rooms, event_inquiries, events, payments, policies,
site_settings.

**`conference_inquiries` is the live model; `conference_bookings` is legacy and orphaned (0 rows,
zero code references).** `events` and `event_inquiries` are both empty on live.

## 9. Guest communication — 10 tables

`config/email.php` (7,036 lines) · `admin/includes/guest-lifecycle-lib.php` ·
`scripts/guest_lifecycle_emails.php` · `scripts/daily_reports.php` ·
`includes/{whatsapp,facebook}-functions.php`

Tables: booking_packages, bookings, cancellation_log, conference_inquiries, conference_rooms,
email_settings, guest_communication_log, rooms, site_settings, stock_orders.

**Trap (cost two real defects):** the keys `email_from_email` / `email_admin_email` exist in
**both** `site_settings` (empty) and `email_settings` (populated). `getSetting()` reads only
`site_settings` and returns `''` for an existing-but-empty row, so the `$default` is never
reached. Use `getEmailSetting()` for anything in `email_settings`.

## 10. Content & marketing — 20 tables

`admin/{page,gallery,media,footer,section-headers}-management.php` · `deals.php` · `reviews.php`
· `submit-review.php` · `admin/visitor-analytics.php` · `includes/visitor-tracker.php`

Tables: about_us, footer_links, hotel_gallery, managed_media_catalog, managed_media_links,
page_heroes, page_loaders, policies, pos_deals, review_responses, reviews, section_headers,
session_logs, site_pages, site_settings, site_visitors + bookings/menu_*/rooms.

**Note:** `includes/visitor-tracker.php` is included from `includes/footer.php`, so **every guest
page view INSERTs into `site_visitors`** — and localhost is deliberately *tracked*, not skipped
(line 39). Rendering the site locally against the live DB pollutes production analytics.

## 11. Admin platform — 26 tables

`admin/admin-init.php` (session + CSRF + headers + permission + module gate) ·
`admin/includes/permissions.php` (1,700 lines; `getModuleForPage()` map) · `user-management.php`
· `system-logs.php` · `backup-management.php` · `module-settings.php` · `api-keys.php` ·
`admin/includes/audit-functions.php`

Tables: admin_activity_log, admin_users, api_keys, api_usage_logs, booking_audit_log,
gym_member_audit_log, housekeeping_audit_log, maintenance_audit_log, offline_replay_log,
permissions, site_pages, site_settings, system_event_log, user_permissions + operational.

Module gate: `admin-init.php:121` — **skipped entirely for `role === 'admin'`**, so an admin can
reach a disabled module by direct URL (deliberate: that is how a module gets re-enabled).
74 of 92 admin pages are module-mapped; the unmapped 18 are platform pages.

## 12. API — 25 public + 30 admin endpoints

- `api/index.php` — key-auth router (`X-API-Key`), `ApiResponse::`, `$auth->checkPermission()`.
- **12 of 25 `api/*.php` are NOT routed through it** and carry their own session auth instead:
  `cancel-order`, `kds-action`, `void-order`, `pos-tab-detail`, `pos-notifications`,
  `reports-export`, `cookie-consent`, `health`, `page-content`, `reviews`, `site-settings`,
  `spatial-loading`. Each was audited 2026-09-02 and is correctly guarded.
- `admin/api/` (30 files) gated by `admin/api/api-init.php` — **except** `all-room-ratings.php`
  (public review data, no auth — low risk but misplaced) and the review endpoints, which start
  their own sessions.
- `api/spatial-loading.php` references the non-existent `room_features` and is **not routed** —
  likely dead code (owner decision pending).
- `api_keys` is **empty on live** — no key has ever been issued.

## 13. Platform / PWA / performance — 3 tables

`manifest.php` · `offline.php` · `admin/offline-log.php` · `config/{cache,page-cache,base-url}.php`
· `includes/image-proxy.php` · `scripts/scheduled-cache-clear.php`

Tables: admin_users, offline_replay_log, site_settings.

**`includes/image-proxy.php` does NOT resize or convert** — it is a fetch-and-cache proxy for
*remote* URLs only. There is **no image processing anywhere in this codebase**; all six upload
handlers are bare `move_uploaded_file()` behind a shared size cap (`config/security.php`).
`getSetting()` file-caches for 1h — settings changes need a cache clear to appear.

## 14. Safety net — 7 tables

`scripts/smoke_test_{booking,finance}.php` · `backup_database.php` · `restore_database.php` ·
`patch_amount_due_drift.php` · **`admin/migrations/migrate.php`** (added 2026-09-02).

Tables: booking_charges, bookings, finance_sequences, migration_log, payments, rooms,
site_settings.

Migrations record into the pre-existing `migration_log` table. Smoke tests **write** (insert and
delete bookings) — never run them against production.

---

## Orphan tables — 15 exist in the schema, referenced by no code

| Table | Rows | Note |
|---|---|---|
| `role_permissions` | **15** | Only orphan holding data. Permissions are read via `permissions` + `user_permissions`; this looks like a superseded model worth confirming before anyone trusts it. |
| `conference_bookings` | 0 | Legacy — `conference_inquiries` is the live model. |
| `booking_financial_audit`, `payment_audit_log` | 0 | Audit tables never wired up. |
| `individual_room_pictures_archive`, `managed_media_groups_archive`, `managed_media_items_archive` | 0 | Archive tables from the media rework. |
| `mra_submission_queue` | 0 | Malawi Revenue Authority queue — feature never built. |
| `newsletter_subscribers`, `welcome`, `room_blocked_dates` | 0 | Unused (note: `blocked_dates` **is** used; `room_blocked_dates` is not). |
| `v_active_tentative_bookings`, `v_tentative_booking_stats` | 0 | Exist as **BASE TABLES, not views**, despite the naming. Zero code references. |
| `v_media_by_page`, `v_room_media` | — | The only genuine VIEWs. Unreferenced. |

All are inert. Removing any of them is a schema change and therefore an owner decision.
