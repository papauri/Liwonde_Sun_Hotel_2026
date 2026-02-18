/**
 * Room Availability Calendar
 * Hotel Website - Booking System
 * 
 * A visual calendar component that shows available dates
 * and blocks unavailable dates (booked or manually blocked)
 */

class RoomAvailabilityCalendar {
    constructor(options = {}) {
        this.options = {
            roomId: null,
            checkInInput: null,
            checkOutInput: null,
            minNights: 1,
            maxNights: 30,
            maxAdvanceDays: 90,
            apiEndpoint: '/api/rooms.php',
            blockedDatesEndpoint: '/api/blocked-dates.php',
            onDateSelect: null,
            onAvailabilityCheck: null,
            ...options
        };
        
        this.availableDates = [];
        this.blockedDates = [];
        this.bookedDates = [];
        this.flatpickrInstance = null;
        
        this.init();
    }
    
    /**
     * Initialize the calendar
     */
    init() {
        if (!this.options.roomId || !this.options.checkInInput || !this.options.checkOutInput) {
            console.error('RoomAvailabilityCalendar: Missing required options');
            return;
        }
        
        this.loadAvailabilityData();
        this.initFlatpickr();
    }
    
    /**
     * Load availability data from API
     */
    async loadAvailabilityData() {
        try {
            // Get available dates
            const today = new Date();
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + this.options.maxAdvanceDays);
            
            const startDate = today.toISOString().split('T')[0];
            const endDate = maxDate.toISOString().split('T')[0];
            
            // Fetch available dates
            const availabilityResponse = await fetch(
                `${this.options.apiEndpoint}?action=check_availability&room_id=${this.options.roomId}&check_in_date=${startDate}&check_out_date=${endDate}`
            );
            const availabilityData = await availabilityResponse.json();
            
            if (availabilityData.success && availabilityData.data) {
                this.availableDates = availabilityData.data.available_dates || [];
            }
            
            // Fetch blocked dates
            const blockedResponse = await fetch(
                `${this.options.blockedDatesEndpoint}?room_id=${this.options.roomId}&start_date=${startDate}&end_date=${endDate}`
            );
            const blockedData = await blockedResponse.json();
            
            if (blockedData.success && blockedData.data) {
                this.blockedDates = blockedData.data.map(bd => bd.block_date);
            }
            
            // Trigger callback if provided
            if (this.options.onAvailabilityCheck) {
                this.options.onAvailabilityCheck({
                    available: this.availableDates,
                    blocked: this.blockedDates
                });
            }
            
        } catch (error) {
            console.error('Error loading availability data:', error);
        }
    }
    
    /**
     * Initialize Flatpickr calendar
     */
    initFlatpickr() {
        const today = new Date();
        const maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + this.options.maxAdvanceDays);
        
        this.flatpickrInstance = flatpickr(this.options.checkInInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            minDate: 'today',
            maxDate: maxDate,
            inline: false,
            showMonths: 2,
            disableMobile: true,
            onDayCreate: (dObj, dStr, fp, dayElem) => {
                this.styleDayElement(dayElem, fp);
            },
            onChange: (selectedDates, dateStr, instance) => {
                this.handleDateChange(selectedDates);
            },
            onReady: (selectedDates, dateStr, instance) => {
                this.styleDayElement(dayElem, instance);
            }
        });
    }
    
    /**
     * Style individual day elements based on availability
     */
    styleDayElement(dayElem, fp) {
        const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        // Check if date is in the past
        if (dayElem.dateObj < today) {
            dayElem.classList.add('flatpickr-disabled');
            return;
        }
        
        // Check if date is blocked
        if (this.blockedDates.includes(dateStr)) {
            dayElem.classList.add('blocked-date');
            dayElem.title = 'This date is blocked';
            return;
        }
        
        // Check if date is available
        const isAvailable = this.availableDates.some(ad => ad.date === dateStr);
        if (!isAvailable) {
            dayElem.classList.add('unavailable-date');
            dayElem.title = 'This date is not available';
        } else {
            const availableDate = this.availableDates.find(ad => ad.date === dateStr);
            if (availableDate) {
                dayElem.classList.add('available-date');
                dayElem.title = `${availableDate.rooms_left} room(s) available`;
            }
        }
    }
    
    /**
     * Handle date selection change
     */
    handleDateChange(selectedDates) {
        if (selectedDates.length === 2) {
            const checkIn = selectedDates[0];
            const checkOut = selectedDates[1];
            
            // Calculate nights
            const nights = Math.round((checkOut - checkIn) / (1000 * 60 * 60 * 24));
            
            // Validate minimum nights
            if (nights < this.options.minNights) {
                this.flatpickrInstance.clear();
                this.showAlert(`Minimum stay is ${this.options.minNights} night(s)`);
                return;
            }
            
            // Validate maximum nights
            if (nights > this.options.maxNights) {
                this.flatpickrInstance.clear();
                this.showAlert(`Maximum stay is ${this.options.maxNights} nights`);
                return;
            }
            
            // Check if all dates in range are available
            const rangeDates = this.getDateRange(checkIn, checkOut);
            const hasUnavailableDates = rangeDates.some(date => 
                this.blockedDates.includes(date) || 
                !this.availableDates.some(ad => ad.date === date)
            );
            
            if (hasUnavailableDates) {
                this.flatpickrInstance.clear();
                this.showAlert('Some dates in your selection are not available');
                return;
            }
            
            // Update check-out input
            if (this.options.checkOutInput) {
                this.options.checkOutInput.value = this.flatpickrInstance.formatDate(checkOut, 'Y-m-d');
            }
            
            // Trigger callback
            if (this.options.onDateSelect) {
                this.options.onDateSelect({
                    checkIn: this.flatpickrInstance.formatDate(checkIn, 'Y-m-d'),
                    checkOut: this.flatpickrInstance.formatDate(checkOut, 'Y-m-d'),
                    nights: nights
                });
            }
        }
    }
    
    /**
     * Get all dates in a range
     */
    getDateRange(startDate, endDate) {
        const dates = [];
        const current = new Date(startDate);
        const end = new Date(endDate);
        
        while (current < end) {
            dates.push(this.flatpickrInstance.formatDate(current, 'Y-m-d'));
            current.setDate(current.getDate() + 1);
        }
        
        return dates;
    }
    
    /**
     * Show alert message
     */
    showAlert(message) {
        // Create alert element if it doesn't exist
        let alertElement = document.getElementById('calendar-alert');
        if (!alertElement) {
            alertElement = document.createElement('div');
            alertElement.id = 'calendar-alert';
            alertElement.className = 'alert alert-warning alert-dismissible fade show';
            alertElement.style.marginTop = '15px';
            
            const closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'btn-close';
            closeButton.setAttribute('data-bs-dismiss', 'alert');
            
            alertElement.appendChild(closeButton);
            
            const messageElement = document.createElement('span');
            messageElement.className = 'alert-message';
            alertElement.appendChild(messageElement);
            
            this.options.checkInInput.parentNode.insertBefore(alertElement, this.options.checkInInput.nextSibling);
        }
        
        alertElement.querySelector('.alert-message').textContent = message;
        alertElement.classList.remove('d-none');
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            alertElement.classList.add('d-none');
        }, 5000);
    }
    
    /**
     * Refresh calendar data
     */
    refresh() {
        this.loadAvailabilityData();
        if (this.flatpickrInstance) {
            this.flatpickrInstance.redraw();
        }
    }
    
    /**
     * Destroy calendar instance
     */
    destroy() {
        if (this.flatpickrInstance) {
            this.flatpickrInstance.destroy();
            this.flatpickrInstance = null;
        }
    }
    
    /**
     * Set room ID and refresh
     */
    setRoomId(roomId) {
        this.options.roomId = roomId;
        this.refresh();
    }
    
    /**
     * Get selected dates
     */
    getSelectedDates() {
        if (!this.flatpickrInstance) {
            return null;
        }
        
        const selectedDates = this.flatpickrInstance.selectedDates;
        if (selectedDates.length === 2) {
            return {
                checkIn: this.flatpickrInstance.formatDate(selectedDates[0], 'Y-m-d'),
                checkOut: this.flatpickrInstance.formatDate(selectedDates[1], 'Y-m-d'),
                nights: Math.round((selectedDates[1] - selectedDates[0]) / (1000 * 60 * 60 * 24))
            };
        }
        
        return null;
    }
    
    /**
     * Clear selected dates
     */
    clear() {
        if (this.flatpickrInstance) {
            this.flatpickrInstance.clear();
        }
    }
}

// Export for use in other files
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RoomAvailabilityCalendar;
}
