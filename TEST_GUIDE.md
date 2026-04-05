# PASSWORD RESET & PRIVILEGE DROPDOWN TEST GUIDE

## Overview
This guide walks through testing:
1. ✅ Employee password reset flow with auto-account creation
2. ✅ Privilege dropdown selections (employees and users)
3. ✅ Email delivery verification

---

## PART 1: PASSWORD RESET FLOW TEST

### Setup Status
- ✅ Test Employee #1: Test Admin Employee (johnpaulchirwa+admin@gmail.com)
- ✅ Test Employee #2: Test Reception Employee (johnpaulchirwa+reception@gmail.com)
- ✅ Permission system: Available
- ✅ Email system: Configured (PHPMailer SMTP)

### Testing Steps

#### Test 1a: Employee Without Account - Password Reset
1. **Navigate to:** `http://localhost:8000/admin/forgot-password.php`
2. **Enter email:** `johnpaulchirwa+admin@gmail.com`
3. **Submit form**
4. **Expected results:**
   - ✓ Success message: "Password reset instructions were sent to your email"
   - ✓ Database: Auto-creates `admin_user` account with:
     - Username: auto-generated (e.g., `test_admin_employee1`)
     - Email: johnpaulchirwa+admin@gmail.com
     - Role: manager (auto-assigned based on employee position)
     - Password: temporary random password
   - ✓ Email: Check Gmail inbox for reset/temp password email:
     - Subject: "[Hotel Admin] Temporary Password - Liwonde Sun Hotel"
     - OR: "Password Reset Link - Liwonde Sun Hotel"
   - ✓ Click reset link or use temporary password to log in

#### Test 1b: Employee With Account - Standard Password Reset
1. **Prerequisites:** Let Employee #1 log in and set own password (from Test 1a)
2. **Navigate to:** `http://localhost:8000/admin/forgot-password.php`
3. **Enter email:** `johnpaulchirwa+admin@gmail.com`
4. **Submit form**
5. **Expected results:**
   - ✓ Success message: "Password reset instructions were sent to your email"
   - ✓ Email: Receives reset link email (NOT temp password)
   - ✓ Click link and set new password (1-hour expiry)
   - ✓ Log in with new password

#### Test 1c: Second Employee (Receptionist)
1. **Navigate to:** `http://localhost:8000/admin/forgot-password.php`
2. **Enter email:** `johnpaulchirwa+reception@gmail.com`
3. **Submit form**
4. **Expected results:**
   - ✓ Auto-creates account with role: **receptionist** (not manager)
   - ✓ Proceeds to SMTP email delivery
   - ✓ Check Gmail for reset/temp password email

---

## PART 2: PRIVILEGE DROPDOWN TEST

### Test 2a: Employee Privilege Selection
1. **Navigate to:** `http://localhost:8000/admin/user-management.php`
2. **Click:** "Add Employee" button (near page title)
3. **Fill form:**
   - Full Name: "Test Employee Priv"
   - Position: "Multi-role Staff"
   - Department: "Operations"
   - Email: test.priv@example.com
   - Phone: +265-9-123-456
4. **Select Privileges:**
   - ✓ Multi-select dropdown appears
   - ✓ Available options:
     - Reception/Front Desk
     - Maintenance Staff
     - Kitchen/Food Service
     - Housekeeping
     - Management
     - Accounting/Finance
     - IT/Technical
     - Security
   - ✓ Ctrl+Click to select multiple: "Reception", "Maintenance", "Management"
5. **Submit & Verify:**
   - ✓ Employee appears in table
   - ✓ Privileges column shows: "Reception, Maintenance, Management"
6. **Edit employee:**
   - ✓ Click Edit button
   - ✓ Modal opens with previously selected options highlighted
   - ✓ Modify selection and save
   - ✓ Privileges update correctly

### Test 2b: Dedicated Employees Page
1. **Navigate to:** `http://localhost:8000/admin/employees.php`
2. **Add Employee:**
   - ✓ Privilege field is multi-select dropdown
   - ✓ Same options as Test 2a
3. **Edit existing employee:**
   - ✓ Edit modal shows privilege dropdown
   - ✓ Previously selected values are highlighted
   - ✓ Can add/remove privileges and save
4. **Verify in table:**
   - ✓ Privileges column shows comma-separated list

### Test 2c: User Management (Role-Based)
1. **Navigate to:** `http://localhost:8000/admin/user-management.php`
2. **User privileges are role-based:**
   - ✓ Admin users have **role** field (not privileges field)
   - ✓ Roles: admin, manager, receptionist
   - ✓ Permission system separate (dashboard, bookings, calendar, etc.)
3. **Each role has default permissions:**
   - ✓ Admin: Full access
   - ✓ Manager: Most features except user management
   - ✓ Receptionist: Limited access (bookings, calendar, etc.)

---

## PART 3: VERIFICATION CHECKLIST

### Database Changes
- [ ] `employees` table exists with `privileges` column (TEXT)
- [ ] Test employees have Gmail addresses configured
- [ ] Privileges stored as comma-separated values (e.g., "Reception, Maintenance")
- [ ] `admin_users` table has auto-created accounts for employees

### UI Changes
- [ ] Employee "Add" modal has multi-select privilege dropdown
- [ ] Employee "Edit" modal has multi-select privilege dropdown
- [ ] Dedicated employees.php page shows multi-select for privileges
- [ ] Privilege selection shows 8 standard options
- [ ] "Hold Ctrl/Cmd to select multiple" hint visible

### Functionality
- [ ] Adding employee with privileges works
- [ ] Editing employee to change privileges works
- [ ] Deleting employee works
- [ ] Privileges display correctly in employee tables
- [ ] Password reset email sends successfully

### API Endpoints (if needed)
- [ ] `/api/booking-details.php` works
- [ ] `/api/payments.php` works
- [ ] Employee data available in maintenance task assignment

---

## TESTING SEQUENCE (Recommended)

### Quick Test (15 minutes)
1. Visit employees.php
2. Add new employee with multiple privilege selections
3. Edit employee and verify privileges persist
4. Check forgot-password.php with Gmail addresses

### Full Test (45 minutes)
1. Run all 3 password reset tests (1a, 1b, 1c)
2. Check Gmail for reset emails
3. Complete password reset flow
4. Test privilege dropdowns (2a, 2b, 2c)
5. Verify database changes

---

## TROUBLESHOOTING

### Privilege Dropdown Not Showing
- Clear browser cache (Ctrl+F5)
- Ensure JavaScript is enabled
- Check browser console for errors
- Verify multi-select elements are rendering

### Password Reset Email Not Arriving
- Check Gmail spam/promotions folder
- Verify email config in `config/email.php`
- Check SMTP settings and credentials
- Verify employee email addresses are correct

### Auto-Account Not Created
- Verify employee `position_title` contains: Admin/Manager/Receptionist
- Check database `employees` table exists
- Verify `admin_users` table for insert permissions

---

## Test Files Created
- `update-test-emails.php` - Script to update employee emails
- `verify-emails.php` - Verify email configuration
- `test-password-reset.php` - Check password reset readiness
- **This file** - Complete test guide

All test scripts are in the project root directory.
