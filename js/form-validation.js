/**
 * Form Validation Module
 * Modern instant validation for all hotel forms
 * Prevents invalid submissions and provides real-time user feedback
 */

(function () {
    'use strict';

    // Name rule reused across forms
    const nameRule = {
        required: true,
        minLength: 3,
        maxLength: 100,
        pattern: /^[a-zA-Z\s'\-\.]+$/,
        messages: {
            required: 'Full name is required',
            minLength: 'Name must be at least 3 characters',
            maxLength: 'Name must not exceed 100 characters',
            pattern: 'Name can only contain letters, spaces, hyphens, and apostrophes'
        }
    };

    // Email rule reused across forms
    const emailRule = {
        required: true,
        pattern: /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
        messages: {
            required: 'Email address is required',
            pattern: 'Please enter a valid email address (e.g. name@example.com)'
        }
    };

    // Phone number (local part) rule — used for the split phone-number input
    const phoneNumberRule = {
        required: true,
        minLength: 4,
        maxLength: 15,
        pattern: /^[\d\s\-]+$/,
        messages: {
            required: 'Phone number is required',
            minLength: 'Phone number must be at least 4 digits',
            maxLength: 'Phone number must not exceed 15 digits',
            pattern: 'Phone number may only contain digits, spaces, and hyphens'
        }
    };

    // Phone code (country dial code select) rule
    const phoneCodeRule = {
        required: true,
        messages: { required: 'Please select a country dialling code' }
    };

    // Validation rules keyed by field name or id
    // Covers restaurant.php, gym.php (full_name/email/phone_number)
    // and booking.php (guest_name/guest_email/guest_phone_number)
    const ValidationRules = {
        // --- Shared name variants ---
        full_name:          nameRule,
        guest_name:         nameRule,
        name:               nameRule,
        contact_person:     nameRule,   // conference form

        // --- Company name (conference form) ---
        company_name: {
            required: true,
            minLength: 2,
            maxLength: 200,
            messages: {
                required: 'Company name is required',
                minLength: 'Company name must be at least 2 characters',
                maxLength: 'Company name must not exceed 200 characters'
            }
        },

        // --- Shared email variants ---
        email:              emailRule,
        guest_email:        emailRule,

        // --- Phone split fields: dial code select + local number input ---
        phone_code:         phoneCodeRule,
        guest_phone_code:   phoneCodeRule,
        phone_number:       phoneNumberRule,
        guest_phone_number: phoneNumberRule,

        // --- Country (required dropdown) ---
        guest_country: {
            required: true,
            messages: { required: 'Please select your country' }
        },

        // --- Date/time ---
        preferred_date: {
            required: true,
            messages: { required: 'Please select a date' }
        },
        preferred_time: {
            required: true,
            messages: { required: 'Please select a time' }
        },

        // --- Guest counts ---
        guests: {
            required: true,
            min: 1,
            max: 10,
            messages: {
                required: 'Number of guests is required',
                min: 'At least 1 guest is required',
                max: 'Maximum 10 guests allowed'
            }
        },

        // --- Generic fallbacks for tag types ---
        textarea: {
            maxLength: 1000,
            messages: { maxLength: 'Message must not exceed 1000 characters' }
        },
        select: {
            required: true,
            messages: { required: 'Please select an option' }
        }
    };

    class FormValidator {
        constructor(formElement) {
            this.form = formElement;
            this.fields = {};
            this.isValid = true;
            this.init();
        }

        init() {
            // Find all form inputs
            const inputs = this.form.querySelectorAll('input, select, textarea');
            
            inputs.forEach(input => {
                const fieldName = input.name || input.id;
                if (!fieldName) return;
                // Skip radio/checkbox — they don't need text/length validation
                if (input.type === 'radio' || input.type === 'checkbox') return;

                this.fields[fieldName] = input;

                // Add event listeners for real-time validation
                input.addEventListener('blur', () => this.validateField(input));
                input.addEventListener('input', () => this.validateField(input));
                input.addEventListener('change', () => this.validateField(input));
            });

            // Prevent form submission if validation fails
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));
        }

        validateField(field) {
            const fieldName = field.name || field.id;
            const value = field.value.trim();
            const rules = this.getRulesForField(field);
            const errors = [];

            // Check required
            if (rules.required && !value) {
                errors.push(rules.messages?.required || `${fieldName} is required`);
            } else if (value) {
                // Check min length
                if (rules.minLength && value.length < rules.minLength) {
                    errors.push(rules.messages?.minLength || `Minimum ${rules.minLength} characters required`);
                }

                // Check max length
                if (rules.maxLength && value.length > rules.maxLength) {
                    errors.push(rules.messages?.maxLength || `Maximum ${rules.maxLength} characters allowed`);
                }

                // Check pattern
                if (rules.pattern && !rules.pattern.test(value)) {
                    errors.push(rules.messages?.pattern || 'Invalid format');
                }

                // Check min value (for numbers)
                if (rules.min && parseFloat(value) < rules.min) {
                    errors.push(rules.messages?.min || `Minimum value is ${rules.min}`);
                }

                // Check max value (for numbers)
                if (rules.max && parseFloat(value) > rules.max) {
                    errors.push(rules.messages?.max || `Maximum value is ${rules.max}`);
                }

                // Check email format (either type=email or a field with email rules has a pattern)
                if ((field.type === 'email' || /email/i.test(field.name || field.id)) && value) {
                    if (!this.isValidEmail(value)) {
                        errors.push(rules.messages?.pattern || 'Please enter a valid email address (e.g. name@example.com)');
                    }
                }

                // Check date constraints
                if (field.type === 'date' && value) {
                    const dateError = this.validateDateConstraints(field, value);
                    if (dateError) errors.push(dateError);
                }

                // Check time constraints
                if (field.type === 'time' && value) {
                    const timeError = this.validateTimeConstraints(field, value);
                    if (timeError) errors.push(timeError);
                }
            }

            this.displayFieldError(field, errors);
            return errors.length === 0;
        }

        getRulesForField(field) {
            const fieldName = field.name || field.id;
            const fieldType = field.type || field.tagName.toLowerCase();

            // Get custom rules from data attributes
            let rules = {};

            if (field.hasAttribute('data-rules')) {
                try {
                    rules = JSON.parse(field.getAttribute('data-rules'));
                } catch (e) {
                    console.error('Invalid data-rules JSON:', field.getAttribute('data-rules'));
                }
            }

            // Apply predefined rules based on field type
            if (fieldName in ValidationRules) {
                rules = { ...ValidationRules[fieldName], ...rules };
            } else if (fieldType === 'select') {
                rules = { ...ValidationRules.select, ...rules };
            } else if (fieldType === 'textarea') {
                rules = { ...ValidationRules.textarea, ...rules };
            }

            // Check HTML5 attributes — only apply if values are positive/valid numbers
            if (field.required) rules.required = true;
            if (field.minLength > 0) rules.minLength = field.minLength;
            if (field.maxLength > 0) rules.maxLength = field.maxLength;
            if (field.min !== '' && field.min !== undefined && !isNaN(Number(field.min))) rules.min = Number(field.min);
            if (field.max !== '' && field.max !== undefined && !isNaN(Number(field.max))) rules.max = Number(field.max);
            if (field.type === 'email') rules.type = 'email';

            return rules;
        }

        isValidEmail(email) {
            // Requires local@domain.tld — TLD must be at least 2 chars, no consecutive dots
            const emailRegex = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;
            return emailRegex.test(email) && !email.includes('..');
        }

        validateDateConstraints(field, value) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const selectedDate = new Date(value);
            selectedDate.setHours(0, 0, 0, 0);

            // Check if min attribute exists
            if (field.min) {
                const minDate = new Date(field.min);
                minDate.setHours(0, 0, 0, 0);
                if (selectedDate < minDate) {
                    return 'The selected date is in the past or too soon';
                }
            }

            // Check if max attribute exists
            if (field.max) {
                const maxDate = new Date(field.max);
                maxDate.setHours(0, 0, 0, 0);
                if (selectedDate > maxDate) {
                    return 'The selected date is too far in the future';
                }
            }

            return null;
        }

        validateTimeConstraints(field, value) {
            const timeErrorId = field.id + '_error';
            const errorElement = document.getElementById(timeErrorId);

            // Check data-disallowed-times attribute
            if (field.hasAttribute('data-disallowed-times')) {
                const disallowedTimes = JSON.parse(field.getAttribute('data-disallowed-times'));
                if (disallowedTimes.includes(value)) {
                    return 'This time is not available';
                }
            }

            return null;
        }

        displayFieldError(field, errors) {
            const fieldName = field.name || field.id;
            const errorContainerId = (field.id || fieldName) + '_error';
            let errorContainer = document.getElementById(errorContainerId);

            // Check if this field is inside a phone-input-group
            const phoneGroup = field.closest('.phone-input-group');
            // The error message anchor — for phone groups, attach after the group wrapper
            const errorAnchorParent = phoneGroup ? phoneGroup.parentNode : field.parentNode;
            const errorAnchor       = phoneGroup || field;

            // Remove existing error styling from the field
            field.classList.remove('field-error', 'field-success');

            // Create error container if it doesn't exist
            if (!errorContainer && errors.length > 0) {
                errorContainer = document.createElement('small');
                errorContainer.id = errorContainerId;
                errorContainer.className = 'field-error-message';
                // Insert after the phone group or the field itself
                if (phoneGroup) {
                    phoneGroup.insertAdjacentElement('afterend', errorContainer);
                } else {
                    field.parentNode.appendChild(errorContainer);
                }
            }

            if (errors.length > 0) {
                field.classList.add('field-error');
                if (errorContainer) {
                    errorContainer.textContent = errors[0];
                    errorContainer.style.display = 'block';
                }
            } else if (field.value.trim()) {
                field.classList.add('field-success');
                if (errorContainer) {
                    errorContainer.style.display = 'none';
                }
            } else {
                if (errorContainer) {
                    errorContainer.style.display = 'none';
                }
            }
        }

        validateAllFields() {
            let allValid = true;
            Object.keys(this.fields).forEach(fieldName => {
                const field = this.fields[fieldName];
                if (!this.validateField(field)) {
                    allValid = false;
                }
            });
            return allValid;
        }

        handleSubmit(e) {
            if (!this.validateAllFields()) {
                e.preventDefault();

                // Scroll to first error
                const firstError = this.form.querySelector('.field-error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }

                // Show banner message
                this.showErrorBanner('Please fix the errors below before submitting');
                return false;
            }
        }

        showErrorBanner(message) {
            let banner = this.form.querySelector('.form-error-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'form-error-banner';
                this.form.insertBefore(banner, this.form.firstChild);
            }
            banner.textContent = message;
            banner.style.display = 'block';
            setTimeout(() => {
                banner.style.display = 'none';
            }, 5000);
        }
    }

    // Initialize validation on all forms with class "validate-form"
    document.addEventListener('DOMContentLoaded', function () {
        const forms = document.querySelectorAll('form.validate-form, form#bookingForm, form#restaurantReservationForm, form.booking-form');
        forms.forEach(form => {
            new FormValidator(form);
        });
    });

    // Export for use in other scripts
    window.FormValidator = FormValidator;
})();
