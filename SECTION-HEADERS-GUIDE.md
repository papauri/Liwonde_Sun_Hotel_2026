# Section Headers System Guide

## Overview

The section headers system allows you to manage all page section headers (titles, subtitles, descriptions) from the database without editing code. This makes it easy to update content sitewide.

**Admin Interface Available:** Use the **Section Headers Management** tool in the admin panel (`admin/section-headers-management.php`) for a visual interface to manage all section headers without writing SQL.

## Database Structure

Each section header has the following fields:

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `section_label` | varchar(100) | Small label above title | "Accommodations" |
| `section_subtitle` | varchar(255) | Subtitle text between label and title | "Where Comfort Meets Luxury" |
| `section_title` | varchar(200) | Main heading (H2) | "Luxurious Rooms & Suites" |
| `section_description` | text | Description below title | "Experience unmatched comfort..." |

## HTML Output Structure

Each section header renders as:

```html
<div class="section-header">
    <span class="section-label">Accommodations</span>
    <p class="section-subtitle">Where Comfort Meets Luxury</p>
    <h2 class="section-title">Luxurious Rooms & Suites</h2>
    <p class="section-description">Experience unmatched comfort in our meticulously designed rooms and suites</p>
</div>
```

## Installation

### 1. Create Database Table

Run the migration SQL file:

```bash
mysql -u username -p database_name < Database/migrations/create_section_headers_table.sql
```

Or import via phpMyAdmin.

### 2. Verify Installation

Check that the table was created:

```sql
SHOW TABLES LIKE 'section_headers';
SELECT COUNT(*) FROM section_headers;
```

You should see 15 default section headers.

### View All Section Headers

Use the admin interface or run this SQL:

```sql
SELECT section_key, page, section_label, section_subtitle, section_title 
FROM section_headers 
ORDER BY page, display_order;
```

### Update a Section Header

```sql
UPDATE section_headers
SET 
    section_label = 'Your Label',
    section_subtitle = 'Your Subtitle',
    section_title = 'Your Title',
    section_description = 'Your description text here'
WHERE section_key = 'home_rooms' AND page = 'index';
```

### Add New Section Header

```sql
INSERT INTO section_headers 
(section_key, page, section_label, section_subtitle, section_title, section_description, display_order)
VALUES 
('spa_overview', 'spa', 'Wellness', 'Rejuvenate & Relax', 'Luxury Spa Services', 'Experience ultimate relaxation', 1);
```

## Current Sections by Page

### Homepage (index)
- `home_rooms` - Rooms & Suites section
- `home_facilities` - Facilities section
- `home_testimonials` - Testimonials section
- `hotel_gallery` - Gallery section

### Restaurant Page (restaurant)
- `restaurant_gallery` - Dining spaces gallery
- `restaurant_menu` - Menu section

### Gym Page (gym)
- `gym_wellness` - Wellness overview
- `gym_facilities` - Fitness facilities
- `gym_classes` - Group classes
- `gym_training` - Personal training
- `gym_packages` - Wellness packages

### Rooms Showcase (rooms-showcase)
- `rooms_collection` - All rooms display

### Conference Page (conference)
- `conference_overview` - Meeting facilities

### Events Page (events)
- `events_overview` - Upcoming events

### Global (available on all pages)
- `hotel_reviews` - Guest reviews section

## PHP Helper Functions

### Fetch Section Header

```php
$header = getSectionHeader('home_rooms', 'index', [
    'label' => 'Fallback Label',
    'subtitle' => 'Fallback Subtitle',
    'title' => 'Fallback Title',
    'description' => 'Fallback description'
]);
```

### Render Section Header

```php
// Basic usage
renderSectionHeader('home_rooms', 'index');

// With fallback values
renderSectionHeader('home_rooms', 'index', [
    'label' => 'Accommodations',
    'subtitle' => 'Where Comfort Meets Luxury',
    'title' => 'Luxurious Rooms & Suites',
    'description' => 'Experience unmatched comfort'
]);

// With additional CSS classes
renderSectionHeader('restaurant_menu', 'restaurant', $fallback, 'text-center');
```

### Update from Admin Panel

```php
updateSectionHeader('home_rooms', 'index', [
    'label' => 'New Label',
    'subtitle' => 'New Subtitle',
    'title' => 'New Title',
    'description' => 'New description'
]);
```

## Fallback System

The system uses a three-tier fallback:

1. **Database (specific page)** - First checks for section on specific page
2. **Database (global)** - Falls back to global page if not found
3. **Hardcoded fallback** - Uses fallback array passed in code

This ensures pages always display properly even if database is unavailable.

## Best Practices

### Label (section-label)
- Keep short: 1-3 words
- Use title case
- Examples: "Accommodations", "What We Offer", "Stay Active"

### Subtitle (section-subtitle)
- Optional decorative text
- Keep concise: 3-6 words
- More descriptive than label
- Examples: "Where Comfort Meets Luxury", "Your Wellness Journey"

### Title (section-title)
- Main heading (H2)
- Clear and descriptive: 3-5 words
- Examples: "Luxurious Rooms & Suites", "Group Fitness Classes"

### Description (section-description)
- Supporting text: 1-2 sentences
- Explains what the section contains
- Can be longer than title
- Examples: "Experience unmatched comfort in our meticulously designed rooms and suites"

## Managing Content

### Bulk Updates

```sql
-- Update all gym section labels
UPDATE section_headers 
SET section_label = 'Fitness & Wellness'
WHERE page = 'gym';
```

### Disable Sections Temporarily

```sql
UPDATE section_headers 
SET is_active = 0 
WHERE section_key = 'home_facilities';
```

### Reorder Sections

```sql
UPDATE section_headers SET display_order = 1 WHERE section_key = 'gym_wellness';
UPDATE section_headers SET display_order = 2 WHERE section_key = 'gym_facilities';
```

## CSS Styling

The section headers use these CSS classes:

- `.section-header` - Container div
- `.section-label` - Small label (span) - Gold, uppercase, bold, 14px
- `.section-subtitle` - Subtitle paragraph - Gray, italic, serif, 18px  
- `.section-title` - Main title (h2) - Navy, bold, serif, 36px
- `.section-description` - Description paragraph - Gray, 16px

Additional classes can be passed: `text-center`, `text-left`, etc.

**Theme Integration:** Section header styles are part of the site's CSS theme system. Colors automatically adapt to the current theme colors (navy, gold, etc.) selected in Theme Management. The `.section-subtitle` uses italic serif font to distinguish it from the uppercase `.section-label`.

### Dark Background Variants

Sections on dark backgrounds (`.bg-dark`) have special styling:
- `.bg-dark .section-label` - Muted gold
- `.bg-dark .section-subtitle` - Light gray with transparency
- `.bg-dark .section-title` - White text

## Managing Section Headers

### Option 1: Admin Interface (Recommended)

1. Log in to admin panel
2. Navigate to **Section Headers Management** (or visit `/admin/section-headers-management.php`)  
3. Filter by page if needed
4. Click **Edit** on any section header
5. Update label, subtitle, title, and description
6. Click **Save Changes**

The admin interface includes:
- **Live preview** of how headers will appear
- **Page filtering** to view specific pages
- **Toggle active/inactive** status for each section
- **Individual reset** - Reset any single section to factory default
- **Revert all to defaults** - Restore all 15 sections to original values (with confirmation)
- **Style guide reference** - Visual examples of how each field appears
- **Visual editing** without SQL knowledge required

#### Revert to Defaults

If you make mistakes or want to start fresh:

**Reset Individual Section:**
- Click the **Reset** button next to any section
- Confirms before restoring that section to factory default

**Revert All Sections:**
- Click **Revert All to Defaults** button in page header
- Requires confirmation (deletes all custom changes!)
- Restores all 15 original section headers from initial migration
- Clears cache automatically

⚠️ **Warning:** Revert operations cannot be undone. All custom content will be lost.

### Option 2: Database SQL

View all section headers via SQL:

```sql
SELECT section_key, page, section_label, section_subtitle, section_title
FROM section_headers
ORDER BY page, display_order;
```

## Future Enhancements

✅ **Completed:**
- ✓ Admin UI for managing headers (no SQL needed)
- ✓ Live preview before publishing  
- ✓ Page filtering
- ✓ Individual section reset to defaults
- ✓ Revert all sections to factory defaults

**Potential additions:**
- Multi-language support
- Version history for content changes
- WYSIWYG editor for descriptions

## Troubleshooting

### Section not displaying?

1. Check if section is active:
   ```sql
   SELECT is_active FROM section_headers WHERE section_key = 'your_key';
   ```

2. Verify section exists:
   ```sql
   SELECT * FROM section_headers WHERE section_key = 'your_key' AND page = 'your_page';
   ```

3. Check error logs:
   ```bash
   tail -f logs/error.log
   ```

### Content not updating?

1. Clear page cache if enabled
2. Check for typos in section_key or page name
3. Verify database connection

## Support

For questions or issues, refer to:
- `includes/section-headers.php` - Helper functions
- `admin/section-headers-management.php` - Admin UI
- `Database/migrations/create_section_headers_table.sql` - Table structure
