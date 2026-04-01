# Form Validation System - Documentation

## Overview
A modern, instant form validation system has been implemented across all hotel forms to prevent invalid submissions and provide real-time user feedback.

## Features

### ✅ Real-Time Validation
- Validates as users type (on `input`, `blur`, `change` events)
- Immediate visual feedback with error/success states
- Prevents form submission if validation fails
- Auto-scrolls to first error on submission attempt

### 🛡️ Comprehensive Validation Rules
- **Text fields**: Required, min/max length, pattern matching
- **Email**: Pattern validation and format checking
- **Phone**: 7-20 characters, valid phone symbols only
- **Numbers**: Min/max value validation
- **Dates**: Required field + min/max date constraints
- **Times**: Required field + availability checks
- **Select/Dropdown**: Required option selection

### 🎨 Visual Feedback
- **Error State**: Red border, red background tint, error icon (⚠)
- **Success State**: Green border, subtle green tint, success icon (✓)
- **Error Messages**: Clear, friendly error text below fields
- **Form Banner**: Red banner appears at top if form has errors

### ⌚ Auto-Clear Errors
- Errors auto-clear when user corrects input
- Success state shows green checkmark when field is valid
- Error banner auto-hides after 5 seconds on submission

## Forms Protected

### 1. **Booking System** (`booking.php`)
Protected fields:
- Full Name (text)
- Email (email)
- Phone (tel)
- Check-in Date (date)
- Check-out Date (date)
- Number of Guests (number: 1-100)
- Room selection (radio)

### 2. **Restaurant Reservations** (`restaurant.php`)
Protected fields:
- Full Name (text: 2-100 chars)
- Email (email)
- Phone (tel: 7-20 chars)
- Preferred Date (date: min advance days)
- Preferred Time (time: breakfast/lunch/dinner windows)
- Guests (number: 1-20)
- Occasion (text, optional)
- Special Requests (textarea: max 1000 chars)

### 3. **Gym Bookings** (`gym.php`)
Protected fields:
- Full Name (text)
- Email (email)
- Phone (tel)
- Number of Guests (number: 1-10)
- Preferred Date (date)
- Preferred Time (time)
- Package Selection (dropdown)

### 4. **Conference/Events** (`conference.php`)
Protected fields:
- Contact information
- Event details
- Date/Time selection

## How It Works

### Client-Side Validation
```javascript
new FormValidator(formElement);
```

The validator automatically:
1. Finds all inputs in the form
2. Attaches event listeners to each field
3. Validates on blur, input, and change events
4. Prevents form submission if invalid
5. Displays clear error messages

### Validation Rules Structure
```javascript
const ValidationRules = {
    full_name: {
        required: true,
        minLength: 2,
        maxLength: 100,
        pattern: /^[a-zA-Z\s'-]+$/,
        messages: {
            required: 'Name is required',
            minLength: 'Name must be at least 2 characters',
            // ... more messages
        }
    },
    // ... more rules
};
```

### Server-Side Validation
**Important**: Always validate again on the server (PHP) before processing!

The client-side validation prevents:
- Empty/incomplete submissions
- Invalid data formats
- Out-of-range values

But server-side validation must still verify:
- CSRF tokens
- Business logic constraints
- Database integrity
- Security checks

## Custom Validation

To add custom validation to a field, use `data-rules` attribute:

```html
<input type="text" id="username" name="username" data-rules='{"minLength":3,"maxLength":50,"pattern":"/^[a-zA-Z0-9_]+$/"}'>
```

Or generate validation dynamically in JavaScript:
```javascript
if (field.hasAttribute('data-rules')) {
    const customRules = JSON.parse(field.getAttribute('data-rules'));
    // Apply custom rules
}
```

## Visual States

### Error State
```css
border-color: #dc3545 !important;      /* Red border */
background-color: rgba(220, 53, 69, 0.05) !important;  /* Light red background */
box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.1) !important;  /* Red glow */
```

### Success State
```css
border-color: #28a745 !important;      /* Green border */
background-color: rgba(40, 167, 69, 0.03) !important;  /* Light green background */
box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.08) !important;  /* Green glow */
```

## CSS Classes

| Class | Purpose |
|-------|---------|
| `validate-form` | Applied to form to enable validation |
| `field-error` | Applied to invalid input fields |
| `field-success` | Applied to valid input fields |
| `field-error-message` | Error message display element |
| `form-error-banner` | Top-level error message banner |

## JavaScript API

### FormValidator Class

```javascript
// Create validator for a form
const validator = new FormValidator(formElement);

// Validate all fields
const isValid = validator.validateAllFields();

// Validate single field
validator.validateField(inputElement);

// Get validation rules for field
const rules = validator.getRulesForField(inputElement);

// Validate date constraints
const dateError = validator.validateDateConstraints(dateField, value);

// Validate time constraints
const timeError = validator.validateTimeConstraints(timeField, value);
```

## Error Prevention

### What's Considered Invalid:
✗ Empty required fields
✗ Text too short or too long
✗ Invalid email format
✗ Phone numbers with invalid characters
✗ Dates in the past (when min date is set)
✗ Unselected dropdown/radio options
✗ Numbers outside min/max range
✗ Special characters in name fields

### What's Accepted:
✓ Full names with spaces, hyphens, apostrophes
✓ Valid email addresses
✓ Phone numbers with +, -, (), spaces
✓ Future dates within constraints
✓ Any text in optional fields
✓ Unicode characters (for international names)

## Browser Support

- Chrome/Edge: ✅ Full support
- Firefox: ✅ Full support
- Safari: ✅ Full support (iOS 12+)
- IE11: ⚠️ Partial support (no date/time inputs)

## Performance

- **Initial load**: ~25KB (form-validation.js)
- **CSS overhead**: ~5KB (form-validation.css)
- **Validation speed**: <1ms per field
- **No jQuery dependency**: Pure vanilla JavaScript
- **No external libraries**: Standalone module

## Accessibility Features

- HTML5 validation attributes honored
- Screen reader friendly error messages
- Keyboard navigation support
- Focus management on errors
- Color contrast meets WCAG standards
- Form labels properly associated with inputs

## Security Considerations

⚠️ **Client-side validation alone is NOT secure!**

Always implement server-side validation:
```php
// Example: restaurant.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $validated = validateReservationRequest($_POST);
    
    if (!$validated['success']) {
        // Return error to user
        return $validated['error'];
    }
    
    // Process booking
    // ...
}
```

## Troubleshooting

### Form validation not working?
1. Ensure `<form class="validate-form">` is in HTML
2. Check form-validation.js is loaded in page footer
3. Verify form-validation.css is in `<head>`
4. Check browser console for errors
5. Ensure form fields have `name` or `id` attributes

### Validation triggering too early?
- Validation runs on `blur` (field loses focus)
- Use `data-no-blur` attribute to skip blur validation
- Validation also runs on `input` and `change` events

### Custom error messages not showing?
- Ensure `data-rules` JSON is valid
- Check field name matches validation rules
- Verify error container is created (auto-generated if needed)

### Fields not validating specific rules?
- Add HTML5 attributes: `required`, `min`, `max`, `pattern`, `minlength`, `maxlength`
- Or use `data-rules` attribute with custom JSON
- Rules from both sources are merged

## Future Enhancements

Potential additions:
- Custom async validation (server-side checks)
- Password strength meter
- Conditional field validation
- Field dependency validation
- Multi-step form progress tracking
- Internationalization (i18n) for error messages
- Real-time availability checking for dates
- Integration with captcha verification

## Support

For issues or enhancements, refer to:
- [Form Validation Configuration](./CONFIGURATION.md)
- [Advanced Customization](./ADVANCED.md)
- Server-side validation handlers in PHP files
