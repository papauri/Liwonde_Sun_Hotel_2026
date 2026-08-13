# Liwonde Sun Hotel — Migration to the Rosalyn Platform

**Goal:** Liwonde Sun runs the complete Rosalyn codebase and admin, with Liwonde's own
branding, content, images and guest-facing design.

**Approach:** Fork Rosalyn as the new base and transplant Liwonde's front end onto it —
*not* port 57 admin pages into Liwonde.

**Schema rule (locked):** the rebuilt database is **byte-identical to Rosalyn's** —
117 objects (115 tables + 2 views). **Zero additions, zero retained Liwonde-only tables.**
Any Liwonde feature with no Rosalyn equivalent is removed, not ported.

**Verified against:** live DB `<db-name>` @ `<db-host>` (MySQL 8.0.46, read-only
inspection, 2026-08-13) and Rosalyn dump `Database/db-20260622-154415.sql.gz`.

---

## 0. Governing facts and decisions

| Fact / decision | Consequence |
|---|---|
| All Liwonde data is test data (3 bookings, 1 payment) | **No data migration.** Content-only port. |
| **Rebuild `<db-name>` in place** | No parallel DB. Full backup first (Phase 0). |
| **Schema identical to Rosalyn** | 115 tables + 2 views. No custom columns. |
| **Restaurant reservations: removed** | Rosalyn has no equivalent — see §4.1. |
| **Secondary hero CTA: removed** | Would require 2 non-Rosalyn columns — see §4.2. |
| **No live ad campaigns depend on UTM** | Campaign attribution dropped outright. |
| `gallery`, `hotel_gallery`, `site_settings` column-identical | Copy verbatim. Zero transform. |
| Rosalyn `room.php` uses the identical room-photo query | Room galleries need no rework. |
| Images on disk (`images/`, 38 MB), DB holds paths | Pictures survive untouched. |

---

## 1. Table disposition — all 58 live tables accounted for

### 1.1 Copy verbatim — identical or compatible columns (28)

**Content (25):** `site_settings` (89) · `drink_menu` (116) · `food_menu` (71) ·
`gallery` (26) · `section_headers` (16) · `menu_categories` (13) · `footer_links` (12) ·
`site_pages` (11) · `about_us` (9) · `page_loaders` (8) · `page_heroes` (6) ·
`facilities` (6) · `restaurant_gallery` (6) · `hotel_gallery` (4) · `rooms` (3) ·
`reviews` (3) · `review_responses` (1) · `testimonials` (3) · `conference_rooms` (3) ·
`policies` (4) · `gym_content` (1) · `gym_facilities` (6) · `gym_features` (4) ·
`gym_classes` (4) · `gym_packages` (3)

**Accounts and config (3):** `admin_users` (2) · `user_permissions` (20) ·
`email_settings` (20)

`rooms` gains 6 nullable Rosalyn columns — leave at defaults, set later in admin:
`image_path`, `single/double/triple_occupancy_enabled`, `children_allowed`,
`price_triple_occupancy`, `child_price_multiplier`.

### 1.2 Transform — renamed concepts (2)

| Liwonde | → Rosalyn | Mapping |
|---|---|---|
| `room_units` (15) | `individual_rooms` | `room_id`→`room_type_id`, `unit_code`→`room_number`, `unit_label`→`room_name`, `is_active`, `notes`. New columns `floor`, `view_type`, `status`, `housekeeping_status` → defaults. |
| `hero_slides` (4) | `page_heroes` | `title`→`hero_title`, `subtitle`→`hero_subtitle`, `description`→`hero_description`, `image_path`→`hero_image_path`, `video_path`→`hero_video_path`, `video_type`→`hero_video_type`, `primary_cta_text`, `primary_cta_link`, `display_order`, `is_active`. Set `page_slug='home'`. **`secondary_cta_text`/`secondary_cta_link` discarded** (§4.2). |

### 1.3 Drop structure and data — Liwonde-only, no Rosalyn equivalent (10)

`marketing_campaigns` (0) · `marketing_campaign_clicks` (0) · `room_promotions` (0) ·
`room_maintenance_tasks` (0) · `deleted_records_backup` (0) · `employees` (2) ·
`employee_titles` (10) · `employee_activity_log` (1) · `booking_additional_charges` (0) ·
`restaurant_inquiries` (1)

Rosalyn replacements: `system_event_log` + `*_audit_log` for activity logs;
`room_maintenance_blocks/_log/_schedules` for maintenance; `admin_users` +
`permissions`/`role_permissions` for employees; `booking_charges` for additional charges.
`restaurant_inquiries` and the campaign tables have **no replacement** — features removed.

### 1.4 Drop data, structure rebuilt from Rosalyn (18)

Transactional: `bookings` (3) · `payments` (1) · `booking_notes` · `cancellation_log` ·
`tentative_booking_log` · `room_blocked_dates` · `events` · `newsletter_subscribers`
Inquiries: `conference_inquiries` (2) · `gym_inquiries` (1)
Logs/session: `admin_activity_log` (51) · `session_logs` (46) · `site_visitors` (127) ·
`cookie_consent_log` (40) · `api_usage_logs` (18) · `migration_log` · `password_resets`
Keys: `api_keys` (1) — **regenerate**, do not carry the old key across

### 1.5 Create from Rosalyn schema (69 new)

Arrive free by loading Rosalyn's structure:
**Booking depth** — `booking_rooms`, `booking_payments`, `booking_charges`,
`booking_audit_log`, `booking_financial_audit`, `booking_timeline_logs`,
`booking_date_adjustments`, `booking_packages`, `booking_email_templates`,
`room_combinations`, `blocked_dates`, `individual_room_*`
**Finance** — `quotations`, `credit_notes`, `credit_note_applications`,
`finance_sequences`, `receipt_events`, `payment_audit_log`, `mra_submission_queue`
**Rates** — `rate_plans`, `room_packages`
**Housekeeping/maintenance** — `housekeeping_assignments`, `housekeeping_audit_log`,
`room_maintenance_blocks/_log/_schedules`, `maintenance_audit_log`
**POS/restaurant** — `restaurant_tables`, `pos_deals`, `pos_ready_notifications`,
`station_messages`, `menu_items`
**Stock (18)** — `stock_*`
**Platform** — `permissions`, `role_permissions`, `admin_user_preferences`,
`enabled_modules`, `idempotency_keys`, `system_event_log`, `offline_replay_log`,
`managed_media_*`, `contact_inquiries`, `guest_services`, `conference_bookings`, `welcome`

---

## 2. Accounts and access

`admin_users` (2) and `user_permissions` (20) copy across — same columns both sides;
existing password hashes stay valid. Rosalyn adds the `permissions` +
`role_permissions` catalogue (seeded from its dump) and a far larger permission set
(`permissions.php` 61 KB vs 15 KB), so **re-run permission assignment per user** in
`admin/user-management.php` after cutover.

---

## 3. Phases

### Phase 0 — Safety net
- [ ] **Full `mysqldump` of `<db-name>` (structure + data) → `Database/`** — mandatory;
      the rebuild is destructive and in place
- [ ] Second copy of that dump off-server
- [ ] Tag repo: `git tag pre-rosalyn-migration`
- [ ] Copy `images/` (38 MB) aside
- [ ] Export the §1.1 content tables to a seed file **before** anything is dropped

**Done when:** the dump restores cleanly into a scratch DB and the content seed file exists.

### Phase 1 — Codebase fork
- [ ] Copy `Rosalyns-hotel-2026/` → new working tree for Liwonde
- [ ] Purge Rosalyn identity: `.env`, `config/database.local.php`,
      `config/base-url-override.php`, `admin/error_log` (685 KB), `backups/`, `cache/`,
      `invoices/`, `logs/`, Rosalyn `images/`, `CLAUDE.md`, `Database/*.sql.gz`
- [ ] Point `git remote` at `papauri/Liwonde_Sun_Hotel_2026`
- [ ] Write Liwonde `config/database.local.php`

**Done when:** `grep -ri "rosalyn" --include=*.php .` returns only harmless matches.

### Phase 2 — Schema rebuild (destructive)
- [ ] Confirm Phase 0 backup verified
- [ ] Drop all 58 tables + 2 views in `<db-name>`
- [ ] Load Rosalyn structure (no data) — all 115 tables + 2 views
- [ ] Confirm `enabled_modules` self-provisions on first admin hit

**Done when:** `information_schema` reports exactly Rosalyn's 117 objects, and a table-name
diff against Rosalyn's dump is empty in both directions.

### Phase 3 — Content import
- [ ] Import §1.1 (28 tables) unchanged
- [ ] Run the two §1.2 transforms
- [ ] Seed `permissions` / `role_permissions` from Rosalyn's dump
- [ ] Regenerate `api_keys`
- [ ] Import nothing from §1.3 or §1.4

**Done when:** §1.1 row counts match, `individual_rooms` = 15, `page_heroes` = 10 (6 + 4
folded slides).

### Phase 4 — Front-end transplant
Copy Liwonde's 18 guest pages + `css/` (272 KB) + `js/` (84 KB) + `images/` onto the
Rosalyn base, then reconcile:

| File | Change |
|---|---|
| `check-availability.php` | `room_units` → `individual_rooms` (`room_id`→`room_type_id`, `unit_code`→`room_number`) |
| `index.php` | `hero_slides` → `page_heroes WHERE page_slug='home'`; remove secondary CTA button from hero markup |
| `restaurant.php` | **Remove the reservation form entirely** — keep menus, gallery, hours |
| all pages | Delete `includes/campaign-attribution.php` + UTM capture |
| `room.php`, gallery pages | **No change** — query already identical |
| `includes/seo-meta.php` | Keep `theme_color` (driven by `site_settings`) |

Reconcile Liwonde's `includes/` against Rosalyn's — Rosalyn has 38 includes vs Liwonde's 26
and is the superset; keep Rosalyn's, port only Liwonde-specific markup.

**Done when:** every guest page loads with Liwonde imagery, no PHP notices in `error_log`,
and no page references a dropped table.

### Phase 5 — Branding and config
- [ ] `site_settings` (89) already carries hotel name, contacts, colours
- [ ] `config/email.php` — Liwonde SMTP, from-address, reply-to
- [ ] `config/invoice.php` — Liwonde legal entity, TPIN, VAT rate, invoice prefix
- [ ] `config/base-url.php` — Liwonde domain
- [ ] `favicon.svg`, logo under `images/logo/`
- [ ] `finance_sequences` — reset invoice/receipt/quotation counters to 1
- [ ] `.htaccess`, `.user.ini` — take Liwonde's

**Done when:** a test booking email arrives from the Liwonde address with correct branding.

### Phase 6 — Module enablement
In `admin/module-settings.php`, enable what Liwonde Sun operates; leave the rest **disabled
rather than deleted** (`enabled_modules` + `rh_module_key_enabled()`):

Likely on: bookings · conference · events · gym · restaurant/menu · reviews · gallery ·
page management · reports · accounting · invoices · quotations · credit notes ·
housekeeping · maintenance · rate plans · packages
Likely off initially: POS · KDS/CDS/BDS · stock/inventory (18 tables) · procurement ·
gym memberships · Facebook/WhatsApp integrations

**Done when:** the sidebar shows only enabled modules and no disabled page is reachable by
direct URL.

### Phase 7 — Verification and cutover
- [ ] Guest booking → confirmation email → admin visibility
- [ ] Admin: create booking, take payment, generate invoice, check in, check out
- [ ] Every guest page at mobile + desktop widths
- [ ] Log in as each of the 2 admin users, confirm correct access
- [ ] Deploy, tag `post-rosalyn-migration`
- [ ] **Remove `<developer-ip>` from cPanel → Remote MySQL**

---

## 4. Resolved decisions

### 4.1 Restaurant reservations — removed
Rosalyn's `restaurant_tables` is a **POS table-locking registry** (`table_number`,
`capacity`, `notes`), not a guest reservation system. Rosalyn has no guest-facing
restaurant booking feature at all. Under the identical-schema rule, `restaurant_inquiries`
and `admin/restaurant-reservations.php` are dropped and the form is removed from
`restaurant.php`.

*Effect:* guests can no longer request a table online. Restaurant menus, gallery and hours
remain. Reversing this later means re-adding the table, the admin page and the form.

### 4.2 Secondary hero CTA — removed
`hero_slides` carried `secondary_cta_text`/`secondary_cta_link`; `page_heroes` has no
equivalent. Adding them would break schema parity, so the secondary button is dropped from
the homepage hero. The primary CTA is preserved.

*Reversal is cheap* if you'd rather keep the design: two nullable `VARCHAR` columns on
`page_heroes`, one admin field in `page-management.php`, render only when both are set.

### 4.3 Campaign attribution — removed
Confirmed no live ad campaigns depend on UTM tracking. `marketing_campaigns`,
`marketing_campaign_clicks`, the 11 UTM columns on `bookings` and
`includes/campaign-attribution.php` are all dropped.

---

## 5. Effort estimate

| Phase | Weight |
|---|---|
| 0 (backup + seed export) | Low — but **do not skip**, rebuild is destructive |
| 1–2 (fork, schema rebuild) | Low — mechanical |
| 3 (content import) | Low — 28 verbatim + 2 transforms |
| 4 (front-end transplant) | **Highest** — 18 pages, 4 need real edits |
| 5 (branding/config) | Low–medium |
| 6 (modules) | Low — config only |
| 7 (verification) | Medium — must be thorough |

Phase 4 is the only one with genuine uncertainty; everything else is mechanical.
