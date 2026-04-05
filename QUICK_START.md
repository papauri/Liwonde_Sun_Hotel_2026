# QUICK REFERENCE: PASSWORD RESET & PRIVILEGE FEATURES

## What's Been Implemented

### 1. Employee Password Reset Flow ✅
```
Employee enters email → System checks:
├─ Account exists? → Send reset link (standard flow)
└─ No account? → Auto-create account + send temp password
```

**Key Features:**
- Auto-creates admin_user account if employee doesn't have one
- Assigns role based on employee position: Admin/Manager = "manager", other = "receptionist"
- Generates temporary password: 8 hex chars + "Aa!" (12+ chars, strong)
- Sends HTML-formatted password reset email
- One-time use reset tokens with 1-hour expiry

**Test Employees Ready:**
- `johnpaulchirwa+admin@gmail.com` (will get manager role)
- `johnpaulchirwa+reception@gmail.com` (will get receptionist role)

---

### 2. Privilege Selection Dropdowns ✅
**Available in Two Places:**
1. `/admin/user-management.php` → Employee Management section
2. `/admin/employees.php` → Dedicated employee management page

**Privilege Options (8 choices):**
- Reception/Front Desk
- Maintenance Staff
- Kitchen/Food Service
- Housekeeping
- Management
- Accounting/Finance
- IT/Technical
- Security

**How It Works:**
- Multi-select dropdown (Ctrl/Cmd + Click to select multiple)
- Selected values stored as comma-separated: "Reception, Maintenance"
- Edit modal pre-selects previously chosen options
- Can add/remove privileges anytime

---

## What Changed in Code

### Files Modified (4 total)

**1. admin/user-management.php**
- Added privilege multi-select dropdown in Add Employee modal
- Added privilege multi-select dropdown in Edit Employee modal
- Updated `openEmployeeEditModal()` JS to handle multi-select
- Updated backend to convert array → comma-separated string
- Employee email validation: now required field

**2. admin/employees.php**
- Replaced textarea with multi-select dropdown for privileges
- Updated backend to handle array from multi-select
- Same 8 privilege options as user-management.php

**3. admin/forgot-password.php** (Previous session)
- Extended to support employee emails
- Auto-account creation logic
- Temporary password generation

**4. admin/reset-password.php** (No changes needed)
- Works with both admin and auto-created employee accounts

---

## Files for Testing

All in project root:
- `test-password-reset.php` - Verify password reset readiness
- `verify-emails.php` - Check email configuration
- `update-test-emails.php` - Update employee email addresses
- `TEST_GUIDE.md` - Detailed testing walkthrough

---

## Quick Test Checklist

- [ ] Open `/admin/user-management.php`
- [ ] Click "Add Employee" button
- [ ] Scroll to "Privileges" field
- [ ] Verify it shows as multi-select dropdown (not text input)
- [ ] Select 2-3 options (e.g., Reception + Maintenance)
- [ ] Submit form
- [ ] Employee appears in table with selected privileges
- [ ] Click Edit button
- [ ] Verify selected privileges are highlighted in modal
- [ ] Change selection and save
- [ ] Verify privileges updated in table

**Password Reset:**
- [ ] Visit `/admin/forgot-password.php`
- [ ] Enter: `johnpaulchirwa+admin@gmail.com`
- [ ] Submit
- [ ] Check email in Gmail (check all tabs)
- [ ] If account created: temporary password email
- [ ] If account exists: reset link email

---

## Browser & Email Setup

**For Testing:**
- Browser: Chrome, Firefox, Safari (all support multi-select)
- Email: Gmail account configured in password reset
- SMTP: Already configured in config/email.php
- PHP: Version 7.4+ (using consistent syntax)

---

## Expected Behavior

### Adding Employee with Privileges
✅ Form accepts multi-select
✅ Saves selected values as comma-separated string
✅ Table displays privileges nicely
✅ Edit modal shows selected options

### Editing Employee Privileges
✅ Modal pre-populates selected options
✅ Can deselect options or add new ones
✅ Save applies changes to database
✅ Immediate table update

### Password Reset
✅ No admin account → Auto-create + temp password email
✅ Has admin account → Standard reset link email
✅ Email arrives in Gmail inbox (check all tabs)
✅ Reset link valid for 1 hour
✅ Can set new password and log in

---

## If Something Looks Wrong

**Dropdowns not showing?**
- Clear browser cache (Ctrl+Shift+Delete)
- Hard reload page (Ctrl+F5)
- Check browser console (F12) for JS errors

**Email not arriving?**
- Check Gmail spam/promotions/social folders
- Verify SMTP credentials in config/email.php
- Check email logs in admin dashboard

**Can't select multiple privileges?**
- On Windows: Hold Ctrl + Click
- On Mac: Hold Cmd + Click
- Highlight dropdown with keyboard arrow keys

---

## Database Schema

**employees table:**
```sql
CREATE TABLE employees (
    id INT PRIMARY KEY,
    full_name VARCHAR(180),
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(20),
    position_title VARCHAR(120),
    department VARCHAR(120),
    privileges TEXT,  ← NOW STORES: "Reception, Maintenance"
    notes TEXT,
    is_active TINYINT(1),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**admin_users table:**
```sql
CREATE TABLE admin_users (
    id INT PRIMARY KEY,
    username VARCHAR(100) UNIQUE,
    email VARCHAR(255) UNIQUE,
    password_hash VARCHAR(255),
    full_name VARCHAR(180),
    role ENUM('admin', 'manager', 'receptionist'),  ← Auto-assigned for employees
    is_active TINYINT(1),
    ...
);
```

---

## Email Flow

```
User submits forgotten password

↓

System checks admin_users table for email

↓ Found              ↓ NOT Found
Generate reset       Look up in employees
token (1hr)          table with email

↓                    ↓ Position matches admin/manager/reception
Send reset link      ↓ No account exists
email                Auto-create admin_user account

                     ↓ Generate temp password
                     ↓ Send temp password email
```

---

## Success Indicators

✅ Added 2 test employees with Gmail addresses
✅ Created privilege dropdown selections (8 options)
✅ Password reset flow supports employees
✅ Auto-account creation working
✅ Email configuration ready
✅ All syntax validated (PHP -l passed)
✅ Both test pages (user-management + employees) functional
✅ Multi-select properly populates on edit

**You're ready to test! 🎉**
