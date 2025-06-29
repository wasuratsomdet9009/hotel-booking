// FILEX: hotel_booking/assets/js/main.js
// VERSION: 2.4 - Final Cleanup by Gemini
// FIX: Ensured moveRoomModal is declared correctly to prevent ReferenceError.
// FIX: Removed a redundant and conflicting calendar event listener at the end of the file.

// =========================================================================
// == GLOBAL FUNCTIONS (Available to all pages)
// =========================================================================

/**
 * Opens the main details modal to show a summary of a booking group.
 * This is a global function attached to the window object.
 * @param {string|number|null} bookingGroupId The ID of the booking group.
 * @param {string|null} bookingIds A comma-separated string of booking IDs if no group ID is available.
 */
window.openBookingGroupSummaryModal = async function(bookingGroupId, bookingIds) {
    const mainDetailsModal = document.getElementById('modal');
    const mainDetailsModalBody = document.getElementById('modal-body');

    if (!mainDetailsModal || !mainDetailsModalBody) {
        console.error("CRITICAL ERROR: Could not find main details modal (#modal) or its body (#modal-body).");
        console.error("เกิดข้อผิดพลาดร้ายแรง: ไม่พบหน้าต่างสำหรับแสดงผล (Modal)");
        return;
    }

    let ajaxUrl = '/hotel_booking/pages/ajax_get_booking_group_summary.php?';
    if (bookingGroupId) {
        ajaxUrl += `booking_group_id=${bookingGroupId}`;
    } else if (bookingIds) {
        ajaxUrl += `booking_ids=${bookingIds}`;
    } else {
        mainDetailsModalBody.innerHTML = '<p class="text-danger" style="padding:20px;">ไม่พบ ID สำหรับโหลดข้อมูล</p>';
        showModal(mainDetailsModal);
        return;
    }

    mainDetailsModalBody.innerHTML = '<p style="text-align:center; padding:20px;">กำลังโหลดข้อมูลสรุปการจองกลุ่ม...</p>';
    showModal(mainDetailsModal);

    try {
        const response = await fetch(ajaxUrl);
        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`HTTP error! status: ${response.status}, message: ${errorText.substring(0,300)}`);
        }
        const html = await response.text();
        mainDetailsModalBody.innerHTML = html;

        const scriptTags = mainDetailsModalBody.querySelectorAll("script");
        scriptTags.forEach(originalScript => {
            const newScript = document.createElement("script");
            if (originalScript.src) {
                newScript.src = originalScript.src;
                newScript.onload = () => newScript.remove(); // Clean up dynamic script
                newScript.onerror = () => {
                    console.error('Failed to load script:', newScript.src);
                    newScript.remove();
                };
            } else {
                newScript.textContent = originalScript.textContent;
            }
            document.body.appendChild(newScript);
            if (!originalScript.src) newScript.remove(); // Remove inline script immediately after execution
        });
    } catch (err) {
        console.error('[openBookingGroupSummaryModal] Failed to load booking group summary:', err);
        mainDetailsModalBody.innerHTML = `<p class="text-danger" style="padding:20px;">เกิดข้อผิดพลาดในการโหลดข้อมูลสรุปกลุ่ม: ${err.message}</p>`;
    }
}

/**
 * Shows a modal.
 * @param {HTMLElement} modalElement The modal element to show.
 */
function showModal(modalElement) {
    if (modalElement) {
        modalElement.classList.add('show');
    }
}

/**
 * Hides a modal.
 * @param {HTMLElement} modalElement The modal element to hide.
 */
function hideModal(modalElement) {
    if (modalElement) {
        modalElement.classList.remove('show');
    }
}

// =========================================================================
// == MAIN DOMContentLoaded EVENT LISTENER
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {

    const originalButtonContents = {};
    const API_BASE_URL = '/hotel_booking/pages/api.php';

    /**
     * Sets the loading state of a button.
     * @param {HTMLElement} buttonElement The button element.
     * @param {boolean} isLoading True to show loading state, false to restore.
     * @param {string} buttonIdForTextStore A unique key for the button.
     */
    function setButtonLoading(buttonElement, isLoading, buttonIdForTextStore) {
        if (!buttonElement) return;
        const key = buttonIdForTextStore || buttonElement.id || `btn-${Date.now()}`;
        if (isLoading) {
            if (!buttonElement.classList.contains('loading')) {
                if (originalButtonContents[key] === undefined) {
                    originalButtonContents[key] = buttonElement.innerHTML;
                }
                buttonElement.innerHTML = '';
                buttonElement.classList.add('loading');
                buttonElement.disabled = true;
            }
        } else {
            if (buttonElement.classList.contains('loading')) {
                if (originalButtonContents[key] !== undefined) {
                    buttonElement.innerHTML = originalButtonContents[key];
                }
                buttonElement.classList.remove('loading');
                buttonElement.disabled = false;
            }
        }
    }

    // =========================================================================
    // == CALENDAR VIEW LOGIC (Consolidated and Fixed)
    // =========================================================================
    const calendarPageContainer = document.querySelector('.calendar-table');
    if (calendarPageContainer) {
        const calendarDayBookingsModal = document.getElementById('calendar-day-bookings-modal');
        const calendarDayModalBody = document.getElementById('calendar-day-modal-body');

        // Main consolidated click handler for the entire calendar table
        calendarPageContainer.addEventListener('click', function(event) {
            const fabOptionLink = event.target.closest('.fab-option-btn');
            const mainFabButton = event.target.closest('.fab-main-btn');
            const bookingGroupTrigger = event.target.closest('.booking-group.calendar-customer-name-action');
            const dailyModalTrigger = event.target.closest('.calendar-day-action-trigger');

            // Allow normal link behavior for FAB options (e.g., to open booking.php)
            if (fabOptionLink) {
                return;
            }

            // Handle main FAB (+) button toggle
            if (mainFabButton) {
                event.preventDefault();
                const fabContainer = mainFabButton.closest('.calendar-fab-container');
                if (fabContainer) {
                    fabContainer.classList.toggle('active');
                }
                return;
            }

            // Handle click on a booking entry to open the summary modal
            if (bookingGroupTrigger) {
                event.preventDefault();
                const bookingIds = bookingGroupTrigger.dataset.bookingIds;
                const bookingGroupId = bookingGroupTrigger.dataset.bookingGroupId;

                if (typeof window.openBookingGroupSummaryModal === 'function' && (bookingIds || bookingGroupId)) {
                    window.openBookingGroupSummaryModal(bookingGroupId, bookingIds);
                } else {
                    console.warn('Booking group clicked, but no booking IDs or group ID found.');
                }
                return;
            }

            // Handle click on the mobile trigger to open the daily list modal
            if (dailyModalTrigger) {
                event.preventDefault();
                const dateStr = dailyModalTrigger.dataset.date;
                if (!dateStr || !calendarDayBookingsModal) return;

                const dayCell = dailyModalTrigger.closest('td');
                const bookingCount = parseInt(dayCell ? dayCell.dataset.bookingCount : '0', 10);
                if (dailyModalTrigger.classList.contains('booking-summary-mobile') && bookingCount === 0) return;

                let modalHtml = '';
                let bookingsFoundForDate = 0;
                if (typeof bookingsByDateAndGroupJS === 'object' && bookingsByDateAndGroupJS !== null) {
                    for (const groupKey in bookingsByDateAndGroupJS) {
                        if (bookingsByDateAndGroupJS[groupKey].date === dateStr) {
                            bookingsFoundForDate++;
                            const groupData = bookingsByDateAndGroupJS[groupKey];
                            const roomsDisplay = groupData.rooms.map(room => room.display).join(', ');
                            modalHtml += `<div class="modal-booking-entry ${groupData.is_highlighted_group ? 'highlighted' : 'regular'}">
                                                <p class="modal-customer-name">${groupData.customer_name}</p>
                                                <p class="modal-room-names">ห้อง: ${roomsDisplay}</p>
                                                ${groupData.customer_phone ? `<p style="font-size:0.85rem; color:var(--color-text-muted);">โทร: ${groupData.customer_phone}</p>` : ''}
                                                <button type="button" class="button-small outline-primary modal-view-details-btn" data-booking-ids="${groupData.booking_ids.join(',')}" data-booking-group-id="${groupData.booking_group_id || ''}">
                                                    <i class="fas fa-info-circle" style="margin-right:4px;"></i>ดูรายละเอียดกลุ่มนี้
                                                </button>
                                            </div>`;
                        }
                    }
                }

                if (bookingsFoundForDate === 0) {
                    modalHtml = '<p style="text-align:center; padding:1rem; color:var(--color-text-muted);"><em>ไม่มีรายการจองสำหรับวันนี้</em></p>';
                }
                if (calendarDayModalBody) calendarDayModalBody.innerHTML = modalHtml;

                const calendarDayModalTitleDate = document.getElementById('modal-selected-date-display');
                if (calendarDayModalTitleDate) {
                    try {
                        calendarDayModalTitleDate.textContent = new Date(dateStr + 'T00:00:00').toLocaleDateString('th-TH', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric'
                        });
                    } catch (e) {
                        calendarDayModalTitleDate.textContent = dateStr;
                    }
                }
                showModal(calendarDayBookingsModal);
            }
        });

        // Click handler for the buttons inside the daily modal
        if (calendarDayModalBody) {
            calendarDayModalBody.addEventListener('click', function(event) {
                const viewDetailsButton = event.target.closest('.modal-view-details-btn');
                if (viewDetailsButton) {
                    if (calendarDayBookingsModal) hideModal(calendarDayBookingsModal);
                    const bookingIds = viewDetailsButton.dataset.bookingIds;
                    const bookingGroupId = viewDetailsButton.dataset.bookingGroupId;
                    if (typeof window.openBookingGroupSummaryModal === 'function') {
                        window.openBookingGroupSummaryModal(bookingGroupId, bookingIds);
                    }
                }
            });
        }
    }
    
    // Close FAB menus when clicking outside
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.calendar-fab-container')) {
            document.querySelectorAll('.calendar-fab-container.active').forEach(fab => fab.classList.remove('active'));
        }
    });

    // =========================================================================
    // == OTHER PAGE LOGIC (From original main.js - Rest of the provided code)
    // =========================================================================

    const detailsModal = document.getElementById('modal');
    const detailsModalBody = document.getElementById('modal-body');
    const detailsModalCloseBtn = detailsModal ? detailsModal.querySelector('.modal-close') : null;
    const detailsModalContent = detailsModal ? detailsModal.querySelector('.modal-content') : null;
    const imageModal = document.getElementById('image-modal');
    const imageModalImage = imageModal ? imageModal.querySelector('#modal-image') : null;
    const depositModal = document.getElementById('deposit-modal');
    const editAddonModal = document.getElementById('edit-addon-modal');
    
    // ***** START: โค้ดที่แก้ไข *****
    const moveRoomModal = document.getElementById('move-room-modal'); 
    // ***** END: โค้ดที่แก้ไข *****

    let HOURLY_RATE_JS = 100.00;
    
    async function fetchHourlyRateAndUpdateConst() {
        try {
            const response = await fetch(`${API_BASE_URL}?action=get_system_setting&setting_key=hourly_extension_rate`);
            const data = await response.json();
            if (data.success && data.value !== null && !isNaN(parseFloat(data.value))) {
                HOURLY_RATE_JS = Math.round(parseFloat(data.value));
                console.log('[MainJS] Fetched and updated HOURLY_RATE_JS:', HOURLY_RATE_JS);
                const hourlyRateDisplaySettings = document.getElementById('current_hourly_extension_rate_display');
                if (hourlyRateDisplaySettings) {
                    hourlyRateDisplaySettings.textContent = String(HOURLY_RATE_JS);
                }
                const hourlyRateInputSettings = document.querySelector('input[name="setting_value"][data-key="hourly_extension_rate"]');
                 if (hourlyRateInputSettings) {
                    hourlyRateInputSettings.value = String(HOURLY_RATE_JS);
                }
            } else {
                 console.warn('[MainJS] Could not fetch or parse hourly_extension_rate, using default:', HOURLY_RATE_JS);
            }
        } catch (err) {
            console.error('[MainJS] Error fetching hourly rate:', err);
        }
    }
    fetchHourlyRateAndUpdateConst();

    // {{START_MODIFICATION_VIEW_MODE_SWITCH_LOGIC}}
    const viewModeToggleCheckbox = document.getElementById('view-mode-toggle-checkbox');
    const searchViewModeInput = document.getElementById('search_view_mode_input'); // Hidden input in search form

    if (viewModeToggleCheckbox) {
        // Set initial state of hidden input for search form
        if (searchViewModeInput) {
            searchViewModeInput.value = viewModeToggleCheckbox.checked ? 'table' : 'grid';
        }

        viewModeToggleCheckbox.addEventListener('change', function() {
            const currentView = this.checked ? 'table' : 'grid';
            if (searchViewModeInput) {
                searchViewModeInput.value = currentView; // Update hidden input for search persistence
            }
            // Preserve other GET parameters like customer_search
            const currentSearchParams = new URLSearchParams(window.location.search);
            currentSearchParams.set('view', currentView);
            window.location.href = window.location.pathname + '?' + currentSearchParams.toString();
        });
    }
    // {{END_MODIFICATION_VIEW_MODE_SWITCH_LOGIC}}

    /** 1) AJAX Booking Form Submission & Addon Logic (booking.php) **/
    const bookingForm = document.getElementById('booking-form');
    if (bookingForm) {
        const baseAmountPaidDisplay_BookingForm = document.getElementById('base_amount_paid_display');
        const totalAddonPriceDisplay_BookingForm = document.getElementById('total-addon-price-display');
        const depositAmountDisplay_BookingForm = document.getElementById('deposit-amount-display');
        const grandTotalPriceDisplay_BookingForm = document.getElementById('grand-total-price-display');
        const finalAmountPaidInput_BookingForm = document.getElementById('final_amount_paid'); // อ้างอิง Input นี้
        const depositNoteText_BookingForm = document.getElementById('deposit_note_text');

        const addonChipsContainer_BookingForm = document.getElementById('addon-chips-container');
        const roomSelect_BookingForm = document.getElementById('room_id'); // <<< RETAINED, used later
        const multiRoomSelect_BookingForm = document.getElementById('room_ids'); // Defined here for access in the event listener

        const bookingTypeGroup_BookingForm = document.getElementById('booking-type-group');
        const bookingTypeSelect_BookingForm = document.getElementById('booking_type');
        const shortStayDurationDisplay_BookingForm = document.getElementById('short_stay_duration_display');
        const shortStayDurationInput_BookingForm = document.getElementById('short_stay_duration_hours');

        const nightsGroup_BookingForm = document.getElementById('nights-group');
        const nightsInput_BookingForm = document.getElementById('nights'); // Ensured definition
        const nightsReadonlyNote_BookingForm = document.getElementById('nights-readonly-note');

        const baseAmountNote_BookingForm = document.getElementById('base_amount_note');
        const checkinNowCheckbox_BookingForm = document.getElementById('checkin_now');
        const checkinInput_BookingForm = document.getElementById('checkin_datetime');

        const checkoutDatetimeInput_BookingForm = document.getElementById('checkout_datetime_edit');

        const zoneFDepositGroup = document.getElementById('zone-f-deposit-group');
        const collectDepositZoneFCheckbox = document.getElementById('collect_deposit_zone_f');
        const customerNameInput = document.getElementById('customer_name');
        const customerNameOptionalText = document.getElementById('customer_name_optional_text');
        const receiptInput = document.getElementById('receipt');
        const receiptOptionalText = document.getElementById('receipt_optional_text');
        const receiptRequiredNote = document.getElementById('receipt_required_note');
        const flexibleOvernightGroup = document.getElementById('flexible-overnight-group'); // Added for flexible overnight

        const roomStatusNoteBookingForm = document.getElementById('room_status_note'); 

        if (roomSelect_BookingForm && roomStatusNoteBookingForm) {
            roomSelect_BookingForm.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const currentStatus = selectedOption ? selectedOption.dataset.currentStatus : null;
                let noteText = '';

                if (currentStatus === 'advance_booking') {
                    noteText = 'หมายเหตุ: ห้องนี้มีการจองล่วงหน้าอยู่แล้ว กรุณาตรวจสอบวันเวลาที่ต้องการจองอย่างระมัดระวัง';
                    roomStatusNoteBookingForm.className = 'text-info';
                } else if (currentStatus === 'booked') {
                    noteText = 'หมายเหตุ: ห้องนี้มีผู้รอเช็คอินในวันนี้ กรุณาเลือกเวลาเช็คอินหลังจากการจองปัจจุบัน';
                    roomStatusNoteBookingForm.className = 'text-warning';
                } else if (['occupied', 'f_short_occupied', 'overdue_occupied'].includes(currentStatus)) {
                    noteText = 'คำเตือน: ห้องนี้ไม่ว่างในปัจจุบัน! ท่านสามารถจองสำหรับวันในอนาคตได้เท่านั้น';
                    roomStatusNoteBookingForm.className = 'text-danger';
                } else {
                    noteText = ''; // ห้อง free
                    roomStatusNoteBookingForm.className = '';
                }
                roomStatusNoteBookingForm.textContent = noteText;
                roomStatusNoteBookingForm.style.display = noteText ? 'block' : 'none';
            });
            if (roomSelect_BookingForm.value) {
                roomSelect_BookingForm.dispatchEvent(new Event('change'));
            }
        }
        
        function updateNightsInputVisualState(makeReadOnly) {
            if (nightsInput_BookingForm) {
                nightsInput_BookingForm.readOnly = makeReadOnly;

                const nightsQuantityGroup = nightsInput_BookingForm.closest('.input-group-quantity');
                if (nightsQuantityGroup) {
                    const minusButton = nightsQuantityGroup.querySelector('.quantity-minus[data-field="nights"]');
                    const plusButton = nightsQuantityGroup.querySelector('.quantity-plus[data-field="nights"]');
                    if (minusButton) minusButton.disabled = makeReadOnly;
                    if (plusButton) plusButton.disabled = makeReadOnly;
                }

                if (nightsReadonlyNote_BookingForm) {
                    nightsReadonlyNote_BookingForm.style.display = makeReadOnly ? 'block' : 'none';
                }
            }
        }

        if (nightsGroup_BookingForm && nightsInput_BookingForm) {
            const quantityMinusBtn = nightsGroup_BookingForm.querySelector('.quantity-minus[data-field="nights"]');
            const quantityPlusBtn = nightsGroup_BookingForm.querySelector('.quantity-plus[data-field="nights"]');

            if (quantityMinusBtn) {
                quantityMinusBtn.addEventListener('click', () => {
                    if (nightsInput_BookingForm.readOnly) return;
                    let currentValue = parseInt(nightsInput_BookingForm.value) || 1;
                    if (currentValue > 1) {
                        nightsInput_BookingForm.value = currentValue - 1;
                        nightsInput_BookingForm.dispatchEvent(new Event('input', { bubbles: true }));
                        calculateAndUpdateBookingFormTotals();
                    }
                });
            }

            if (quantityPlusBtn) {
                quantityPlusBtn.addEventListener('click', () => {
                    if (nightsInput_BookingForm.readOnly) return;
                    let currentValue = parseInt(nightsInput_BookingForm.value) || 0;
                    nightsInput_BookingForm.value = currentValue + 1;
                    nightsInput_BookingForm.dispatchEvent(new Event('input', { bubbles: true }));
                    calculateAndUpdateBookingFormTotals();
                });
            }
            nightsInput_BookingForm.addEventListener('input', () => {
                 if (nightsInput_BookingForm.readOnly) return;
                 calculateAndUpdateBookingFormTotals();
            });
        }

        if (checkoutDatetimeInput_BookingForm) {
            checkoutDatetimeInput_BookingForm.addEventListener('input', () => {
                if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS) {
                    if (checkoutDatetimeInput_BookingForm.value) {
                        updateNightsInputVisualState(true);
                    } else {
                        const currentBookingType = bookingTypeSelect_BookingForm ? bookingTypeSelect_BookingForm.value : 'overnight';
                        if (currentBookingType === 'overnight') {
                            updateNightsInputVisualState(false);
                        } else {
                            updateNightsInputVisualState(false);
                        }
                    }
                    calculateAndUpdateBookingFormTotals();
                }
            });
        }

        const groupActionToolbar = document.getElementById('group-action-toolbar');
        const groupButton = document.getElementById('group-selected-bookings-btn');
        const selectedCountSpan = document.getElementById('selected-booking-count');
        const selectAllCheckbox = document.getElementById('select-all-bookings-checkbox');
        const bookingCheckboxes = document.querySelectorAll('.booking-group-checkbox');

        function updateGroupButtonVisibility() {
            const selectedCheckboxes = document.querySelectorAll('.booking-group-checkbox:checked');
            if (selectedCheckboxes.length > 1) { 
                groupActionToolbar.style.display = 'block';
                selectedCountSpan.textContent = selectedCheckboxes.length;
            } else {
                groupActionToolbar.style.display = 'none';
                selectedCountSpan.textContent = 0;
            }
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                bookingCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                updateGroupButtonVisibility();
            });
        }

        bookingCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateGroupButtonVisibility);
        });

        if (groupButton) {
            groupButton.addEventListener('click', async function() {
                const selectedCheckboxes = document.querySelectorAll('.booking-group-checkbox:checked');
                const bookingIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.bookingId);

                if (bookingIds.length < 2) {
                    console.warn('Please select at least 2 bookings to group.');
                    return;
                }

                if (!confirm(`คุณต้องการรวมการจองจำนวน ${bookingIds.length} รายการเข้าเป็นกลุ่มเดียวกันหรือไม่?`)) {
                    return;
                }

                setButtonLoading(this, true, 'group-selected-bookings-btn');

                const formData = new FormData();
                formData.append('action', 'group_bookings');
                bookingIds.forEach(id => {
                    formData.append('booking_ids[]', id);
                });

                try {
                    const response = await fetch('/hotel_booking/pages/api.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        console.log(result.message || 'Bookings grouped successfully!');
                        window.location.reload();
                    } else {
                        console.error('Error grouping bookings:', result.message);
                    }

                } catch (error) {
                    console.error('Error grouping bookings:', error);
                    console.error('Connection error while grouping bookings.');
                } finally {
                    setButtonLoading(this, false, 'group-selected-bookings-btn');
                }
            });
        }

        function updateBookingTypeVisibilityAndDefaults() {
            if (typeof IS_EDIT_MODE_JS === 'undefined' || typeof ROOM_DETAILS_JS === 'undefined' || typeof DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS === 'undefined') {
                console.warn('[BookingForm] Essential global JS variables are not defined.');
            }

            const isMultiRoomPage = multiRoomSelect_BookingForm && multiRoomSelect_BookingForm.selectedOptions && multiRoomSelect_BookingForm.selectedOptions.length > 0;
            let showZoneFDepositForMultiRoom = false;

            if (nightsInput_BookingForm) {
                const isEditModeWithCheckoutDateActive = (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && checkoutDatetimeInput_BookingForm && checkoutDatetimeInput_BookingForm.value);

                let effectiveBookingTypeForNightsControl = 'overnight';
                if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && typeof CURRENT_BOOKING_TYPE_EDIT_JS !== 'undefined') {
                    effectiveBookingTypeForNightsControl = CURRENT_BOOKING_TYPE_EDIT_JS;
                } else if (bookingTypeSelect_BookingForm) {
                    effectiveBookingTypeForNightsControl = bookingTypeSelect_BookingForm.value;
                }


                if (isEditModeWithCheckoutDateActive) {
                    updateNightsInputVisualState(true);
                } else {
                    if (effectiveBookingTypeForNightsControl === 'overnight') {
                        updateNightsInputVisualState(false);
                    } else {
                        updateNightsInputVisualState(false);
                    }
                }
            }


            if (IS_EDIT_MODE_JS) {
                if(bookingTypeGroup_BookingForm) bookingTypeGroup_BookingForm.style.display = 'none';
                if (flexibleOvernightGroup) {
                    flexibleOvernightGroup.style.display = 'none';
                    const flexibleCheckbox = document.getElementById('flexible_overnight_mode');
                    if (flexibleCheckbox) flexibleCheckbox.checked = false;
                }
            } else if (isMultiRoomPage || !roomSelect_BookingForm || !bookingTypeGroup_BookingForm || !bookingTypeSelect_BookingForm) {
                 if(bookingTypeGroup_BookingForm && isMultiRoomPage ) bookingTypeGroup_BookingForm.style.display = 'none';
                if (flexibleOvernightGroup) {
                    flexibleOvernightGroup.style.display = 'none';
                    const flexibleCheckbox = document.getElementById('flexible_overnight_mode');
                    if (flexibleCheckbox) flexibleCheckbox.checked = false;
                }
            }


            const selectedRoomId = roomSelect_BookingForm ? roomSelect_BookingForm.value : null;
            let isZoneFSelected = false;
            let currentRoomDetails = null;

            if (selectedRoomId && ROOM_DETAILS_JS && ROOM_DETAILS_JS[selectedRoomId]) {
                currentRoomDetails = ROOM_DETAILS_JS[selectedRoomId];
                isZoneFSelected = currentRoomDetails.zone === 'F';

                if (!IS_EDIT_MODE_JS) {
                    if (currentRoomDetails.allow_short_stay == '1') {
                        if(bookingTypeGroup_BookingForm) bookingTypeGroup_BookingForm.style.display = 'block';
                        if (shortStayDurationDisplay_BookingForm) shortStayDurationDisplay_BookingForm.textContent = currentRoomDetails.short_stay_duration_hours || DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS;
                        if (shortStayDurationInput_BookingForm) shortStayDurationInput_BookingForm.value = currentRoomDetails.short_stay_duration_hours || DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS;
                    } else {
                        if(bookingTypeGroup_BookingForm) bookingTypeGroup_BookingForm.style.display = 'none';
                        if(bookingTypeSelect_BookingForm) bookingTypeSelect_BookingForm.value = 'overnight';
                    }
                }
            } else if (!isMultiRoomPage && !IS_EDIT_MODE_JS) {
                if(bookingTypeGroup_BookingForm) bookingTypeGroup_BookingForm.style.display = 'none';
                if (bookingTypeSelect_BookingForm) bookingTypeSelect_BookingForm.value = 'overnight';
            }


            if (customerNameInput && customerNameOptionalText && !IS_EDIT_MODE_JS) {
                customerNameInput.required = !isZoneFSelected || isMultiRoomPage;
                customerNameOptionalText.style.display = (isZoneFSelected && !isMultiRoomPage) ? 'inline' : 'none';
            }
            if (receiptInput && receiptOptionalText && receiptRequiredNote && !IS_EDIT_MODE_JS) {
                receiptOptionalText.style.display = (isZoneFSelected && !isMultiRoomPage) ? 'inline' : 'none';
                receiptRequiredNote.textContent = (isZoneFSelected && !isMultiRoomPage) ?
                    '(โซน F ไม่บังคับแนบหลักฐาน หากไม่เก็บมัดจำ หรือยอดชำระเป็น 0)' :
                    'หากยอดชำระมากกว่า 0 กรุณาแนบหลักฐาน (จำเป็นสำหรับสร้างการจองใหม่)';
            }

            if (isMultiRoomPage && !IS_EDIT_MODE_JS && bookingTypeSelect_BookingForm) {
                const selectedRoomOptions = Array.from(multiRoomSelect_BookingForm.selectedOptions);
                const hasZoneFRoomAskingDeposit = selectedRoomOptions.some(option => {
                    const roomDetails = ROOM_DETAILS_JS[option.value];
                    return roomDetails && roomDetails.zone === 'F' && roomDetails.ask_deposit_on_overnight == '1';
                });
                if (hasZoneFRoomAskingDeposit) {
                    showZoneFDepositForMultiRoom = true;
                }
            }

            if (zoneFDepositGroup && collectDepositZoneFCheckbox && !IS_EDIT_MODE_JS) {
                if (showZoneFDepositForMultiRoom) {
                    zoneFDepositGroup.style.display = 'block';
                } else if (isZoneFSelected && bookingTypeSelect_BookingForm && bookingTypeSelect_BookingForm.value === 'overnight' && currentRoomDetails && currentRoomDetails.ask_deposit_on_overnight == '1') {
                    zoneFDepositGroup.style.display = 'block';
                }
                else {
                    zoneFDepositGroup.style.display = 'none';
                    collectDepositZoneFCheckbox.checked = false;
                }
            } else if (zoneFDepositGroup && !IS_EDIT_MODE_JS) {
                 zoneFDepositGroup.style.display = 'none';
                 if(collectDepositZoneFCheckbox) collectDepositZoneFCheckbox.checked = false;
            } else if (zoneFDepositGroup && IS_EDIT_MODE_JS) {
                zoneFDepositGroup.style.display = 'none';
            }

            if (flexibleOvernightGroup && bookingTypeSelect_BookingForm && !IS_EDIT_MODE_JS) {
                const currentBookingTypeVal = bookingTypeSelect_BookingForm.value;
                if (currentBookingTypeVal === 'overnight' && !isMultiRoomPage) {
                    flexibleOvernightGroup.style.display = 'block';
                } else {
                    flexibleOvernightGroup.style.display = 'none';
                    const flexibleCheckbox = document.getElementById('flexible_overnight_mode');
                    if (flexibleCheckbox) flexibleCheckbox.checked = false;
                }
            } else if (flexibleOvernightGroup && IS_EDIT_MODE_JS) {
                 flexibleOvernightGroup.style.display = 'none';
            }
            calculateAndUpdateBookingFormTotals();
        }

        function calculateAndUpdateBookingFormTotals() {
            if (!finalAmountPaidInput_BookingForm || !grandTotalPriceDisplay_BookingForm) {
                console.warn('[BookingFormTotals] Missing essential display/input elements for calculation.');
                return;
            }
            if (typeof FIXED_DEPOSIT_AMOUNT_GLOBAL_JS === 'undefined' || typeof DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS === 'undefined') {
                console.warn('[BookingFormTotals] Essential global JS variables are not defined.');
            }

            let currentRoomCostOnly = 0;
            let depositAmount = 0;
            let currentBookingType = 'overnight';
            let nights = 1;

            const isMultiMode = multiRoomSelect_BookingForm && multiRoomSelect_BookingForm.selectedOptions && multiRoomSelect_BookingForm.selectedOptions.length > 0;
            let currentSelectedRoomDetails = null;
            let isCurrentRoomZoneF = false;

            const originalCheckinTimeForCalc_JS = (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && typeof ORIGINAL_CHECKIN_DATETIME_EDIT_JS !== 'undefined' && ORIGINAL_CHECKIN_DATETIME_EDIT_JS)
                ? new Date(ORIGINAL_CHECKIN_DATETIME_EDIT_JS.replace(' ', 'T'))
                : (checkinInput_BookingForm ? new Date(checkinInput_BookingForm.value.replace(' ', 'T')) : new Date());


            if (!isMultiMode && roomSelect_BookingForm && roomSelect_BookingForm.value && ROOM_DETAILS_JS && ROOM_DETAILS_JS[roomSelect_BookingForm.value]) {
                currentSelectedRoomDetails = ROOM_DETAILS_JS[roomSelect_BookingForm.value];
                isCurrentRoomZoneF = currentSelectedRoomDetails.zone === 'F';
            }

            if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && typeof CURRENT_BOOKING_TYPE_EDIT_JS !== 'undefined') {
                currentBookingType = CURRENT_BOOKING_TYPE_EDIT_JS;
            } else if (!isMultiMode && bookingTypeSelect_BookingForm && bookingTypeGroup_BookingForm && bookingTypeGroup_BookingForm.style.display !== 'none') {
                currentBookingType = bookingTypeSelect_BookingForm.value;
            } else if (isMultiMode) {
                currentBookingType = 'overnight';
            }

            if (nightsGroup_BookingForm && nightsInput_BookingForm) {
                if (currentBookingType === 'short_stay') {
                    nightsGroup_BookingForm.style.display = 'none';
                    nightsInput_BookingForm.required = false;
                    nightsInput_BookingForm.value = '';
                    nights = 0;
                    updateNightsInputVisualState(false);
                } else {
                    nightsGroup_BookingForm.style.display = 'block';
                    nightsInput_BookingForm.required = true;
                    nights = parseInt(nightsInput_BookingForm.value) || 1;
                    if (nights < 1) nights = 1;

                    if (!(typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && checkoutDatetimeInput_BookingForm && checkoutDatetimeInput_BookingForm.value)) {
                        updateNightsInputVisualState(false);
                    }
                }
            }

            if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && checkoutDatetimeInput_BookingForm && checkoutDatetimeInput_BookingForm.value) {
                const newCheckoutDateTime = new Date(checkoutDatetimeInput_BookingForm.value.replace(' ', 'T'));
                if (currentBookingType === 'overnight' && originalCheckinTimeForCalc_JS && !isNaN(originalCheckinTimeForCalc_JS.getTime()) && !isNaN(newCheckoutDateTime.getTime())) {

                    let diffTime = newCheckoutDateTime.getTime() - originalCheckinTimeForCalc_JS.getTime();
                    let calculatedNights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                    const checkinDate = new Date(originalCheckinTimeForCalc_JS);
                    const checkoutDate = new Date(newCheckoutDateTime);
                    const checkinDayStart = new Date(checkinDate.getFullYear(), checkinDate.getMonth(), checkinDate.getDate());
                    const checkoutDayStart = new Date(checkoutDate.getFullYear(), checkoutDate.getMonth(), checkoutDate.getDate());
                    const dayDifference = (checkoutDayStart.getTime() - checkinDayStart.getTime()) / (1000 * 60 * 60 * 24);

                    if (dayDifference < 0) {
                        nights = 1;
                    } else if (dayDifference === 0) {
                        nights = 1;
                    } else {
                        nights = Math.ceil(dayDifference);
                        calculatedNights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                        nights = (calculatedNights > 0) ? calculatedNights : 1;
                    }

                    if (newCheckoutDateTime.getTime() <= originalCheckinTimeForCalc_JS.getTime()) {
                        nights = 1;
                    }

                    if (nightsInput_BookingForm) {
                        nightsInput_BookingForm.value = nights;
                        updateNightsInputVisualState(true);
                    }
                }
            } else if (nightsInput_BookingForm && currentBookingType === 'overnight') {
                 nights = parseInt(nightsInput_BookingForm.value) || 1;
                 if (nights < 1) nights = 1;
                 nightsInput_BookingForm.value = nights;
                 updateNightsInputVisualState(false);
            }


            if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS && CURRENT_BOOKING_TYPE_EDIT_JS === 'overnight' && typeof ORIGINAL_PRICE_PER_NIGHT_EDIT_JS !== 'undefined') {
                currentRoomCostOnly = nights * ORIGINAL_PRICE_PER_NIGHT_EDIT_JS;
                if (baseAmountNote_BookingForm) baseAmountNote_BookingForm.textContent = `ยอดสำหรับค่าห้องพัก (${nights} คืน) ไม่รวมบริการเสริม`;

                const originalDepositForEditStr = depositAmountDisplay_BookingForm ? depositAmountDisplay_BookingForm.textContent : '0';
                depositAmount = parseFloat(originalDepositForEditStr) || 0;

                if (isCurrentRoomZoneF) {
                     if (depositAmount > 0) {
                        if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(โซน F - มัดจำเดิม: ${String(Math.round(depositAmount))} บาท)`;
                     } else {
                        if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(โซน F - ไม่มีการเก็บค่ามัดจำเดิม)`;
                     }
                } else if (depositAmount > 0) {
                    if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(มัดจำเดิม: ${String(Math.round(depositAmount))} บาท)`;
                } else {
                     if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(ไม่มีค่ามัดจำเดิม)`;
                }

            } else if (isMultiMode && multiRoomSelect_BookingForm) {
                const selectedRooms = Array.from(multiRoomSelect_BookingForm.selectedOptions);
                let numOvernightRoomsInMulti = 0;
                let numZoneFRoomsAskingDepositInMulti = 0;
                let numNonZoneFOrNonAskingDepositRooms = 0;
                currentRoomCostOnly = 0;

                selectedRooms.forEach(option => {
                    const roomDetailsMulti = ROOM_DETAILS_JS[option.value];
                    if (roomDetailsMulti) {
                        const roomPrice = parseFloat(roomDetailsMulti.price_per_day) || 0;
                        currentRoomCostOnly += roomPrice * nights;
                        numOvernightRoomsInMulti++;
                        if (roomDetailsMulti.zone === 'F' && roomDetailsMulti.ask_deposit_on_overnight == '1') {
                            numZoneFRoomsAskingDepositInMulti++;
                        } else {
                            numNonZoneFOrNonAskingDepositRooms++;
                        }
                    }
                });

                depositAmount = 0;
                let depositNoteTextParts = [];

                if (numNonZoneFOrNonAskingDepositRooms > 0) {
                    depositAmount += numNonZoneFOrNonAskingDepositRooms * (FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0);
                    depositNoteTextParts.push(`มาตรฐานห้องละ ${String(Math.round(FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0))} บาท สำหรับ ${numNonZoneFOrNonAskingDepositRooms} ห้อง (นอกโซน F หรือโซน F ที่ไม่ถามมัดจำ)`);
                }

                if (numZoneFRoomsAskingDepositInMulti > 0) {
                    if (collectDepositZoneFCheckbox && collectDepositZoneFCheckbox.checked) {
                        depositAmount += numZoneFRoomsAskingDepositInMulti * (FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0);
                        depositNoteTextParts.push(`โซน F: เลือกเก็บมัดจำ ${String(Math.round(FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0))} บาท/ห้อง สำหรับ ${numZoneFRoomsAskingDepositInMulti} ห้อง`);
                    } else {
                        depositNoteTextParts.push(`โซน F: ไม่ได้เลือกเก็บค่ามัดจำสำหรับ ${numZoneFRoomsAskingDepositInMulti} ห้อง`);
                    }
                }

                if (depositNoteText_BookingForm) {
                     depositNoteText_BookingForm.textContent = depositNoteTextParts.length > 0 ? depositNoteTextParts.join(' | ') : (numOvernightRoomsInMulti > 0 ? '(ไม่มีค่ามัดจำสำหรับห้องที่เลือก)' : '(คำนวณค่ามัดจำ...)');
                }

                if (baseAmountNote_BookingForm) baseAmountNote_BookingForm.textContent = selectedRooms.length > 0 ? `ยอดสำหรับ ${selectedRooms.length} ห้อง x ${nights} คืน (ไม่รวมบริการเสริม)` : `คำนวณค่าห้องพัก...`;
                if (checkinNowCheckbox_BookingForm) {
                    checkinNowCheckbox_BookingForm.disabled = true;
                    checkinNowCheckbox_BookingForm.checked = false;
                }
            } else if (currentSelectedRoomDetails) {
                if (currentBookingType === 'short_stay' && currentSelectedRoomDetails.allow_short_stay == '1') {
                    currentRoomCostOnly = parseFloat(currentSelectedRoomDetails.price_short_stay) || 0;
                    depositAmount = 0;
                    const duration = currentSelectedRoomDetails.short_stay_duration_hours || DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS || 3;
                    if (baseAmountNote_BookingForm) baseAmountNote_BookingForm.textContent = `ยอดสำหรับค่าห้องพัก (${duration} ชม.) ไม่รวมบริการเสริม`;
                    if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(พักชั่วคราว ไม่มีค่ามัดจำ)`;
                } else {
                    currentRoomCostOnly = (parseFloat(currentSelectedRoomDetails.price_per_day) || 0) * nights;
                    if (isCurrentRoomZoneF && currentSelectedRoomDetails.ask_deposit_on_overnight == '1') {
                        if (collectDepositZoneFCheckbox && collectDepositZoneFCheckbox.checked) {
                            depositAmount = FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0;
                            if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(โซน F - เลือกเก็บมัดจำ ${String(Math.round(FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0))} บาท)`;
                        } else {
                            depositAmount = 0;
                            if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(โซน F - ไม่เก็บค่ามัดจำ)`;
                        }
                    } else if (!isCurrentRoomZoneF) {
                        depositAmount = FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0;
                        if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(มาตรฐาน ${String(Math.round(FIXED_DEPOSIT_AMOUNT_GLOBAL_JS || 0))} บาท สำหรับค้างคืน)`;
                    } else {
                        depositAmount = 0;
                         if (depositNoteText_BookingForm) depositNoteText_BookingForm.textContent = `(โซน F - ไม่มีการตั้งค่าให้ถามเก็บมัดจำ หรือไม่เลือกเก็บ)`;
                    }
                    if (baseAmountNote_BookingForm) baseAmountNote_BookingForm.textContent = `ยอดสำหรับค่าห้องพัก (${nights} คืน) ไม่รวมบริการเสริม`;
                }
                const isCalendarPrefill = typeof IS_CALENDAR_PREFILL_BOOKING_PAGE !== 'undefined' && IS_CALENDAR_PREFILL_BOOKING_PAGE;
                if (checkinNowCheckbox_BookingForm && !(typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS) && !isCalendarPrefill) {
                     checkinNowCheckbox_BookingForm.disabled = false;
                }
            }

            if (baseAmountPaidDisplay_BookingForm) baseAmountPaidDisplay_BookingForm.value = String(Math.round(currentRoomCostOnly));

            let currentTotalAddonPrice = 0;
            if (addonChipsContainer_BookingForm) {
                addonChipsContainer_BookingForm.querySelectorAll('.addon-checkbox:checked').forEach(checkbox => {
                    const price = parseFloat(checkbox.dataset.price) || 0;
                    const quantityInput = checkbox.closest('.addon-chip-wrapper').querySelector('.addon-quantity');
                    const quantity = quantityInput ? (parseInt(quantityInput.value) || 1) : 1;
                    if (quantity > 0) currentTotalAddonPrice += price * quantity;
                });
            }
            if (totalAddonPriceDisplay_BookingForm) totalAddonPriceDisplay_BookingForm.textContent = String(Math.round(currentTotalAddonPrice));
            if (depositAmountDisplay_BookingForm) depositAmountDisplay_BookingForm.textContent = String(Math.round(depositAmount));

            const grandTotalToPay = currentRoomCostOnly + currentTotalAddonPrice + depositAmount;
            if(grandTotalPriceDisplay_BookingForm) grandTotalPriceDisplay_BookingForm.textContent = String(Math.round(grandTotalToPay));

            if (finalAmountPaidInput_BookingForm) {
                if (typeof IS_EDIT_MODE_JS !== 'undefined' && !IS_EDIT_MODE_JS) {
                    const isManuallySet = finalAmountPaidInput_BookingForm.dataset.amountPaidManuallySet === 'true';

                    if (!isManuallySet) {
                        finalAmountPaidInput_BookingForm.value = String(Math.round(grandTotalToPay));
                        finalAmountPaidInput_BookingForm.style.backgroundColor = '#e9ecef';
                        finalAmountPaidInput_BookingForm.style.borderColor = '#ced4da';
                    } else {
                        finalAmountPaidInput_BookingForm.style.backgroundColor = '';
                        finalAmountPaidInput_BookingForm.style.borderColor = '';
                        console.log('[CalcTotals-NewBooking] final_amount_paid was manually set, preserving user value:', finalAmountPaidInput_BookingForm.value);
                    }
                } else if (typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS) {
                    finalAmountPaidInput_BookingForm.readOnly = false;
                    finalAmountPaidInput_BookingForm.style.backgroundColor = '';
                    finalAmountPaidInput_BookingForm.style.borderColor = '';
                }
            }

            console.log(`[CalcTotals] Mode: ${typeof IS_EDIT_MODE_JS !== 'undefined' && IS_EDIT_MODE_JS ? 'EDIT' : 'NEW'}, Type: ${currentBookingType}, ZoneF (single): ${isCurrentRoomZoneF}, MultiMode: ${isMultiMode}, Nights: ${nights}, RoomCost: ${currentRoomCostOnly}, Addons: ${currentTotalAddonPrice}, Deposit: ${depositAmount}, GrandTotalToPay: ${grandTotalToPay}, FinalPaidInput: ${finalAmountPaidInput_BookingForm ? finalAmountPaidInput_BookingForm.value : 'N/A'}`);
        }

        if (roomSelect_BookingForm && !(multiRoomSelect_BookingForm && multiRoomSelect_BookingForm.offsetParent !== null && multiRoomSelect_BookingForm.selectedOptions.length > 0)) {
            roomSelect_BookingForm.addEventListener('change', () => {
                updateBookingTypeVisibilityAndDefaults();
            });
        }
        if (multiRoomSelect_BookingForm) {
            multiRoomSelect_BookingForm.addEventListener('change', () => {
                 updateBookingTypeVisibilityAndDefaults();
            });
        }
        if (bookingTypeSelect_BookingForm) {
            bookingTypeSelect_BookingForm.addEventListener('change', () => {
                updateBookingTypeVisibilityAndDefaults();
            });
        }

        if(collectDepositZoneFCheckbox) {
            collectDepositZoneFCheckbox.addEventListener('change', calculateAndUpdateBookingFormTotals);
        }

        if (checkinInput_BookingForm && checkinNowCheckbox_BookingForm) {
          const initiallyReadOnlyByPHP = typeof IS_CHECKIN_TIME_READONLY_BOOKING_PAGE !== 'undefined' ? IS_CHECKIN_TIME_READONLY_BOOKING_PAGE : false;

          checkinNowCheckbox_BookingForm.addEventListener('change', () => {
            if (checkinNowCheckbox_BookingForm.checked) {
              const now = new Date();
              const offset = now.getTimezoneOffset() * 60000;
              const localDate = new Date(now.getTime() - offset);
              checkinInput_BookingForm.value = localDate.toISOString().slice(0, 16);
              checkinInput_BookingForm.readOnly = true;
            } else {
              if (!initiallyReadOnlyByPHP) {
                  checkinInput_BookingForm.readOnly = false;
              }
              if (typeof PHP_INITIAL_CHECKIN_DATETIME_BOOKING_PAGE !== 'undefined' && PHP_INITIAL_CHECKIN_DATETIME_BOOKING_PAGE) {
                  checkinInput_BookingForm.value = PHP_INITIAL_CHECKIN_DATETIME_BOOKING_PAGE;
              } else {
                  console.warn('[MainJS CheckinToggle] PHP_INITIAL_CHECKIN_DATETIME_BOOKING_PAGE not found for uncheck, reverting to current time.');
                  const fallbackNow = new Date();
                  const fallbackOffset = fallbackNow.getTimezoneOffset() * 60000;
                  const fallbackLocalDate = new Date(fallbackNow.getTime() - fallbackOffset);
                  checkinInput_BookingForm.value = fallbackLocalDate.toISOString().slice(0,16);
              }
            }
          });

          if (initiallyReadOnlyByPHP) {
              checkinInput_BookingForm.readOnly = true;
          }
          if (checkinNowCheckbox_BookingForm.checked) {
              checkinInput_BookingForm.readOnly = true;
          }
        }


        if (addonChipsContainer_BookingForm) {
            addonChipsContainer_BookingForm.addEventListener('input', (e) => {
                const target = e.target;
                if (target.classList.contains('addon-checkbox') || target.classList.contains('addon-quantity')) {
                    const wrapper = target.closest('.addon-chip-wrapper');
                    const checkbox = wrapper.querySelector('.addon-checkbox');
                    const quantityInput = wrapper.querySelector('.addon-quantity');

                    if (checkbox && quantityInput) {
                        if (checkbox.checked) {
                            quantityInput.style.display = 'inline-block';
                            quantityInput.disabled = false;
                            if (parseInt(quantityInput.value) < 1 || quantityInput.value === '') {
                                quantityInput.value = 1;
                            }
                            wrapper.classList.add('selected');
                        } else {
                            quantityInput.style.display = 'none';
                            quantityInput.disabled = true;
                            wrapper.classList.remove('selected');
                        }
                    }
                    calculateAndUpdateBookingFormTotals();
                }
            });
        }

        if (typeof IS_EDIT_MODE_JS !== 'undefined') {
            updateBookingTypeVisibilityAndDefaults();
        } else if (multiRoomSelect_BookingForm && multiRoomSelect_BookingForm.selectedOptions && multiRoomSelect_BookingForm.selectedOptions.length > 0) {
            updateBookingTypeVisibilityAndDefaults();
        } else if (roomSelect_BookingForm && roomSelect_BookingForm.value ) {
             updateBookingTypeVisibilityAndDefaults();
        } else {
            updateBookingTypeVisibilityAndDefaults();
        }


        if (addonChipsContainer_BookingForm) {
            addonChipsContainer_BookingForm.querySelectorAll('.addon-checkbox:checked').forEach(cb => {
                const wrapper = cb.closest('.addon-chip-wrapper');
                if (wrapper) {
                    wrapper.classList.add('selected');
                    const quantityInput = wrapper.querySelector('.addon-quantity');
                    if(quantityInput){
                        quantityInput.style.display = 'inline-block';
                        quantityInput.disabled = false;
                    }
                }
            });
        }

        const submitBookingBtn = bookingForm.querySelector('#submit-booking-form-btn');
    }

    if (detailsModal && detailsModalBody && detailsModalCloseBtn && detailsModalContent) {
        document.querySelectorAll('.room').forEach(roomElement => {
            roomElement.addEventListener('click', async (event) => {
                if (event.target !== roomElement && event.target.closest('button, a')) {
                    return;
                }

                try {
                    const roomId = roomElement.dataset.id;
                    if (!roomId) return;
                    if(detailsModalBody) detailsModalBody.innerHTML = '<p style="text-align:center; padding:20px;">Loading room details...</p>';
                    showModal(detailsModal);

                    const response = await fetch(`/hotel_booking/pages/details.php?id=${roomId}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}, message: ${await response.text()}`);
                    const html = await response.text();
                    detailsModalBody.innerHTML = html;
                    attachDetailEvents(detailsModalBody);
                } catch (err) {
                    console.error('[RoomClick] Failed to load room details:', err);
                    if(detailsModalBody) detailsModalBody.innerHTML = '<p>เกิดข้อผิดพลาดในการโหลดข้อมูลห้องพัก: ' + err.message + '</p>';
                    showModal(detailsModal);
                }
            });
        });
        detailsModal.addEventListener('click', (e) => {
            if (e.target === detailsModal && !detailsModalContent.contains(e.target)) {
                hideModal(detailsModal);
            }
        });
         if (detailsModalCloseBtn) {
            detailsModalCloseBtn.addEventListener('click', () => hideModal(detailsModal));
        }
    }

    function attachDetailEvents(modalBodyElement) {
        modalBodyElement.querySelectorAll('.receipt-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (imageModal && imageModalImage) {
                    imageModalImage.src = btn.dataset.src;
                    showModal(imageModal);
                } else { window.open(btn.dataset.src, '_blank'); }
            });
        });

        const returnDepositBtn = modalBodyElement.querySelector('#return-deposit-btn');
        const returnDepositFormDiv = modalBodyElement.querySelector('#return-deposit-form');

        if (returnDepositBtn && returnDepositFormDiv) {
            returnDepositBtn.addEventListener('click', () => {
                const shouldShowNow = returnDepositFormDiv.style.display === 'none';
                returnDepositFormDiv.style.display = shouldShowNow ? 'block' : 'none';
                if (shouldShowNow) {
                  setTimeout(() => {
                    returnDepositFormDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  }, 100);
                }
                const extendFormContainer = modalBodyElement.querySelector('#extend-stay-form-container');
                if (extendFormContainer) extendFormContainer.style.display = 'none';
                const editDetailsFormContainer = modalBodyElement.querySelector('#edit-booking-details-form-container');
                if (editDetailsFormContainer) editDetailsFormContainer.style.display = 'none';
            });
        }

        const submitCompleteBookingBtn = modalBodyElement.querySelector('#submit-deposit.complete-booking-btn');
        if (submitCompleteBookingBtn) {
            submitCompleteBookingBtn.addEventListener('click', async () => {
                const bookingId = submitCompleteBookingBtn.dataset.bookingId;
                const bookingActionType = submitCompleteBookingBtn.dataset.bookingType;
                const fileInput = modalBodyElement.querySelector('#deposit-proof');

                let confirmMessage = 'ยืนยันการดำเนินการนี้และย้ายรายการไปประวัติ?';
                let proofNeededForThisAction = false;

                if (bookingActionType === 'overnight_with_deposit_return') {
                    confirmMessage = 'ยืนยันการคืนมัดจำและย้ายรายการนี้ไปประวัติ?';
                    proofNeededForThisAction = true;
                }

                if (proofNeededForThisAction && (!fileInput || !fileInput.files || fileInput.files.length === 0)) {
                  console.warn('Please select a file for deposit proof.'); 
                  return;
                }
                if (!confirm(confirmMessage)) return;

                setButtonLoading(submitCompleteBookingBtn, true, `submitCompleteBtn-${bookingId}`);
                const formData = new FormData();
                formData.append('booking_id', bookingId);
                formData.append('update_action', 'return_and_complete');
                formData.append('booking_completion_type', bookingActionType);

                if (proofNeededForThisAction && fileInput && fileInput.files && fileInput.files.length > 0) {
                  formData.append('deposit_proof', fileInput.files[0]);
                }

                const url = `${API_BASE_URL}?action=update`;
                try {
                  const response = await fetch(url, { method: 'POST', body: formData });
                  const data = await response.json();
                  if (data.success) {
                    console.log(data.message || 'Operation completed successfully!');
                    window.location.reload();
                  } else {
                    console.error('Error:', data.message || 'Unknown error');
                  }
                } catch (err) {
                  console.error('[attachDetailEvents] Submit complete/deposit error:', err);
                  console.error('Failed to connect to server.');
                } finally {
                    setButtonLoading(submitCompleteBookingBtn, false, `submitCompleteBtn-${bookingId}`);
                }
            });
        }

        const completeNoRefundActionBtn = modalBodyElement.querySelector('#complete-no-refund-action-btn');
        if (completeNoRefundActionBtn) {
            completeNoRefundActionBtn.addEventListener('click', async () => {
                const bookingId = completeNoRefundActionBtn.dataset.bookingId;

                if (!confirm(`คุณแน่ใจหรือไม่ว่าต้องการ "ดำเนินการเช็คเอาท์ (ไม่คืนมัดจำ)" สำหรับการจอง ID: ${bookingId}? การดำเนินการนี้จะย้ายการจองไปประวัติโดยไม่มีการคืนเงินมัดจำ`)) {
                    return;
                }
                if (!confirm(`ย้ำ! ยืนยันการ "เช็คเอาท์ (ไม่คืนมัดจำ)" สำหรับ ID: ${bookingId} ใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้`)) {
                    return;
                }

                setButtonLoading(completeNoRefundActionBtn, true, 'completeNoRefundActionBtn-' + bookingId);
                const formData = new FormData();
                formData.append('booking_id', bookingId);
                formData.append('update_action', 'return_and_complete');
                formData.append('booking_completion_type', 'overnight_no_deposit_return');

                const url = `${API_BASE_URL}?action=update`;
                try {
                    const response = await fetch(url, { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        console.log(data.message || 'Checkout (no refund) completed successfully.');
                        window.location.reload();
                    } else {
                        console.error('Error:', data.message || 'Unknown error');
                    }
                } catch (err) {
                    console.error('[attachDetailEvents] Complete No Refund Action error:', err);
                    console.error('Failed to connect to server.');
                } finally {
                    setButtonLoading(completeNoRefundActionBtn, false, 'completeNoRefundActionBtn-' + bookingId);
                }
            });
        }


        const createBookingBtn = modalBodyElement.querySelector('.create-booking-btn');
        if (createBookingBtn) {
            createBookingBtn.addEventListener('click', () => {
                const roomId = createBookingBtn.dataset.roomId;
                window.location.href = `/hotel_booking/pages/booking.php?room_id=${roomId}`;
            });
        }

        const occupyBtnInModal = modalBodyElement.querySelector('.occupy-btn');
        if (occupyBtnInModal) {
            occupyBtnInModal.addEventListener('click', async (e) => {
                e.preventDefault();
                const bookingId = occupyBtnInModal.dataset.bookingId;
                if (!bookingId) { console.warn('Error: Booking ID not found.'); return; }

                if (confirm(`คุณต้องการยืนยันการเช็คอินสำหรับการจอง ID: ${bookingId} หรือไม่?`)) {
                    const buttonId = occupyBtnInModal.id || `occupy-btn-modal-${bookingId}`;
                    setButtonLoading(occupyBtnInModal, true, buttonId);
                    const url = `${API_BASE_URL}?action=update`;
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ booking_id: bookingId, update_action: 'occupy' })
                        });
                        const data = await response.json();
                        if (data.success) {
                            console.log(data.message || 'เช็คอินสำเร็จ!');
                            window.location.reload();
                        } else {
                            console.error('Check-in error:', data.message || 'Unknown error.');
                        }
                    } catch (err) {
                        console.error('[OccupyBtnInModal] API error:', err);
                        console.error('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อเช็คอินได้');
                    } finally {
                        setButtonLoading(occupyBtnInModal, false, buttonId);
                    }
                }
            });
        }

        const extendFormContainer = modalBodyElement.querySelector('#extend-stay-form-container');
        const showExtendFormBtn = modalBodyElement.querySelector('#show-extend-stay-form-btn');
        const extendForm = modalBodyElement.querySelector('#extend-stay-form');

        if (showExtendFormBtn && extendFormContainer && extendForm) {
            showExtendFormBtn.addEventListener('click', () => {
                const shouldShow = extendFormContainer.style.display === 'none';
                extendFormContainer.style.display = shouldShow ? 'block' : 'none';

                if (returnDepositFormDiv) returnDepositFormDiv.style.display = 'none';
                const editDetailsFormContainer = modalBodyElement.querySelector('#edit-booking-details-form-container');
                if (editDetailsFormContainer) editDetailsFormContainer.style.display = 'none';


                if (shouldShow) {
                    let currentBooking_DB_ServiceTotalPrice_Ext = 0;
                    let currentBooking_DB_PricePerNight_Ext = 0;
                    let jsCurrentCheckoutDateTimeObj_Ext = null;
                    let initialShortStayRoomCost_Ext = 0;
                    let jsCurrentBookingType = '';

                    const currentTotalPriceHidden = modalBodyElement.querySelector('#js-current-total-price');
                    const pricePerNightHidden = modalBodyElement.querySelector('#js-current-price-per-night');
                    const currentCheckoutObjHidden = modalBodyElement.querySelector('#js-current-checkout-datetime-obj');
                    const initialShortStayRoomCostHidden = extendForm.dataset.initialShortStayRoomCost;
                    const currentBookingTypeHidden = modalBodyElement.querySelector('#js-edit-initial-booking-type');

                    currentBooking_DB_ServiceTotalPrice_Ext = parseFloat(currentTotalPriceHidden?.value) || 0;
                    currentBooking_DB_PricePerNight_Ext = parseFloat(pricePerNightHidden?.value) || 0;
                    initialShortStayRoomCost_Ext = parseFloat(initialShortStayRoomCostHidden) || 0;
                    jsCurrentBookingType = currentBookingTypeHidden?.value || '';


                    if (currentCheckoutObjHidden && currentCheckoutObjHidden.value) {
                        jsCurrentCheckoutDateTimeObj_Ext = new Date(currentCheckoutObjHidden.value.replace(' ', 'T'));
                    } else {
                        jsCurrentCheckoutDateTimeObj_Ext = new Date();
                        console.error('[ExtendStay] Critical: Could not load current checkout datetime object for extension calculation.');
                    }
                    const pricePerNightDisplayExtendVal = modalBodyElement.querySelector('#price_per_night_display_extend_val');
                    if(pricePerNightDisplayExtendVal) pricePerNightDisplayExtendVal.textContent = String(Math.round(currentBooking_DB_PricePerNight_Ext));


                    const extendTypeSelect = modalBodyElement.querySelector('#extend_type');
                    const extendHoursInput = modalBodyElement.querySelector('#extend_hours');
                    const extendNightsInput = modalBodyElement.querySelector('#extend_nights');
                    const extendHoursGroup = modalBodyElement.querySelector('#extend_hours_group');
                    const extendNightsGroup = modalBodyElement.querySelector('#extend_nights_group');

                    if(extendTypeSelect) extendTypeSelect.value = 'hours';
                    if(extendHoursInput) { extendHoursInput.value = '1'; extendHoursInput.disabled = false; }
                    if(extendNightsInput) { extendNightsInput.value = '1'; extendNightsInput.disabled = true; }
                    if(extendHoursGroup) extendHoursGroup.style.display = 'block';
                    if(extendNightsGroup) extendNightsGroup.style.display = 'none';

                    if (extendTypeSelect && extendForm) {
                        const hoursOption = extendTypeSelect.querySelector('option[value="hours"]');
                        if (hoursOption) {
                            const currentRoomHourlyRateForDisplay = parseFloat(extendForm.dataset.roomHourlyRate) || HOURLY_RATE_JS;
                            const hourlyRateDisplaySpan = modalBodyElement.querySelector('#hourly_rate_display_extend_val');
                            if(hourlyRateDisplaySpan) hourlyRateDisplaySpan.textContent = String(Math.round(currentRoomHourlyRateForDisplay));
                        }
                    }

                    const extendReceiptInput = modalBodyElement.querySelector('#extend_receipt');
                    if(extendReceiptInput) extendReceiptInput.value = '';

                    function updateExtendCostAndPaymentScoped() {
                        const additionalCostDisplay = modalBodyElement.querySelector('#additional_cost_display');
                        const newTotalAmountDisplay = modalBodyElement.querySelector('#new_total_amount_display');
                        const newCheckoutTimeDisplay = modalBodyElement.querySelector('#new_checkout_time_display');
                        const extensionDurationDetailsDisplay = modalBodyElement.querySelector('#extension_duration_details_display');
                        let paymentForExtensionInput = extendForm.querySelector('input[name="payment_for_extension"]');

                        const currentPaidForExtendDisplay = modalBodyElement.querySelector('#current_paid_for_extend_display');
                        const paymentDueForExtensionDisplay = modalBodyElement.querySelector('#payment_due_for_extension_display');

                        const currentAmountPaidOriginal = parseFloat(
                            modalBodyElement.querySelector('#js-current-total-paid')?.value || 
                            extendForm.dataset.currentAmountPaid || 
                            '0' 
                        ) || 0;

                        if (currentPaidForExtendDisplay) {
                            currentPaidForExtendDisplay.textContent = String(Math.round(currentAmountPaidOriginal));
                        }

                        if (!paymentForExtensionInput) {
                            paymentForExtensionInput = document.createElement('input');
                            paymentForExtensionInput.type = 'hidden';
                            paymentForExtensionInput.name = 'payment_for_extension';
                            extendForm.appendChild(paymentForExtensionInput);
                        }

                        if (!extendTypeSelect || !additionalCostDisplay || !newTotalAmountDisplay || !newCheckoutTimeDisplay || !jsCurrentCheckoutDateTimeObj_Ext || isNaN(jsCurrentCheckoutDateTimeObj_Ext.getTime())) {
                            if(newCheckoutTimeDisplay) newCheckoutTimeDisplay.textContent = 'ข้อผิดพลาดในการคำนวณ';
                            if(additionalCostDisplay) additionalCostDisplay.textContent = '0';
                            if(newTotalAmountDisplay) newTotalAmountDisplay.textContent = String(Math.round(currentBooking_DB_ServiceTotalPrice_Ext || 0));
                            if(paymentForExtensionInput) paymentForExtensionInput.value = '0';
                            if (extensionDurationDetailsDisplay) extensionDurationDetailsDisplay.textContent = '-';
                            if (paymentDueForExtensionDisplay) {
                                paymentDueForExtensionDisplay.textContent = '0';
                            }
                            return;
                        }

                        let calculatedExtensionCostOnly = 0;
                        const type = extendTypeSelect.value;
                        let newCheckoutCalc = new Date(jsCurrentCheckoutDateTimeObj_Ext.getTime());
                        let newTotalBookingValue;
                        let extensionDetailsText = '-';

                        const currentRoomZoneForExtend = extendForm.dataset.currentRoomZone;
                        const roomHourlyRateJS = parseFloat(extendForm.dataset.roomHourlyRate) || HOURLY_RATE_JS;

                        if (type === 'hours') {
                            if(extendHoursGroup) extendHoursGroup.style.display = 'block';
                            if(extendHoursInput) extendHoursInput.disabled = false;
                            if(extendNightsGroup) extendNightsGroup.style.display = 'none';
                            if(extendNightsInput) { extendNightsInput.disabled = true; extendNightsInput.value = '1';}

                            const hours = parseInt(extendHoursInput.value) || 0;
                            if (currentRoomZoneForExtend === 'F' && hours === 3 && jsCurrentBookingType === 'short_stay') {
                                calculatedExtensionCostOnly = 220;
                            } else {
                                calculatedExtensionCostOnly = Math.round(hours * roomHourlyRateJS);
                            }
                            if (hours > 0) {
                                newCheckoutCalc.setHours(newCheckoutCalc.getHours() + hours);
                                extensionDetailsText = `เพิ่ม ${hours} ชั่วโมง`;
                            } else {
                                extensionDetailsText = '-';
                            }
                            newTotalBookingValue = currentBooking_DB_ServiceTotalPrice_Ext + calculatedExtensionCostOnly;

                        } else if (type === 'nights') {
                            if(extendNightsGroup) extendNightsGroup.style.display = 'block';
                            if(extendNightsInput) extendNightsInput.disabled = false;
                            if(extendHoursGroup) extendHoursGroup.style.display = 'none';
                            if(extendHoursInput) { extendHoursInput.disabled = true; extendHoursInput.value = '1';}

                            const nightsExt = parseInt(extendNightsInput.value) || 0;
                            let pricePerNightForCalc = currentBooking_DB_PricePerNight_Ext;
                            if (typeof pricePerNightForCalc === 'undefined' || isNaN(pricePerNightForCalc) || pricePerNightForCalc <= 0) {
                                const roomStandardOvernightPrice = parseFloat(extendForm.dataset.roomOvernightPrice) || 600.00;
                                pricePerNightForCalc = roomStandardOvernightPrice;
                                console.warn('[ExtendCost JS] Price per night for N nights extension was invalid or not found for the current booking, using room default overnight price:', pricePerNightForCalc);
                            }
                            calculatedExtensionCostOnly = nightsExt * pricePerNightForCalc;
                            calculatedExtensionCostOnly = Math.round(calculatedExtensionCostOnly);

                            if (nightsExt > 0) {
                                const standardCheckoutTimeStr = document.getElementById('js-standard-checkout-time-str')?.value || '12:00:00';
                                const coTimeParts = standardCheckoutTimeStr.split(':');
                                newCheckoutCalc.setDate(newCheckoutCalc.getDate() + nightsExt);
                                newCheckoutCalc.setHours(parseInt(coTimeParts[0]), parseInt(coTimeParts[1]), parseInt(coTimeParts[2] || 0), 0);
                                extensionDetailsText = `เพิ่ม ${nightsExt} คืน`;
                            } else {
                                extensionDetailsText = '-';
                            }
                            newTotalBookingValue = currentBooking_DB_ServiceTotalPrice_Ext + calculatedExtensionCostOnly;

                        } else if (type === 'upgrade_to_overnight') {
                            if(extendHoursGroup) extendHoursGroup.style.display = 'none';
                            if(extendNightsGroup) extendNightsGroup.style.display = 'none';
                            if(extendHoursInput) { extendHoursInput.disabled = true; extendHoursInput.value = '1';}
                            if(extendNightsInput) { extendNightsInput.disabled = true; extendNightsInput.value = '1';}

                            const targetOvernightRoomPrice = parseFloat(extendForm.dataset.roomOvernightPrice) || 600.00;
                            const originalShortStayPaidForRoom = initialShortStayRoomCost_Ext;
                            const additionalDepositForOvernight = 0;
                            let additionalPaymentForRoomOnly = targetOvernightRoomPrice - originalShortStayPaidForRoom;
                            if (additionalPaymentForRoomOnly < 0) additionalPaymentForRoomOnly = 0;

                            calculatedExtensionCostOnly = additionalPaymentForRoomOnly + additionalDepositForOvernight;
                            calculatedExtensionCostOnly = Math.round(calculatedExtensionCostOnly);

                            const newRoomPriceForThisBooking = targetOvernightRoomPrice;
                            let upgradeTime = new Date();
                            newCheckoutCalc = new Date(upgradeTime.setDate(upgradeTime.getDate() + 1));
                            const standardCheckoutTimeStr = document.getElementById('js-standard-checkout-time-str')?.value || '12:00:00';
                            const coTimeParts = standardCheckoutTimeStr.split(':');
                            newCheckoutCalc.setHours(parseInt(coTimeParts[0]), parseInt(coTimeParts[1]), parseInt(coTimeParts[2] || 0), 0);

                            const originalAddonCost = currentBooking_DB_ServiceTotalPrice_Ext - initialShortStayRoomCost_Ext - (parseFloat(modalBodyElement.querySelector('#js-edit-initial-deposit-amount')?.value || modalBodyElement.querySelector('#js-current-deposit-amount')?.value) || 0);

                            newTotalBookingValue = newRoomPriceForThisBooking + (originalAddonCost > 0 ? originalAddonCost : 0) + additionalDepositForOvernight;
                            extensionDetailsText = 'เปลี่ยนเป็นค้างคืน (กำหนดเช็คเอาท์ใหม่)';
                        } else {
                            extensionDetailsText = '-';
                            if(extendHoursGroup) extendHoursGroup.style.display = 'none';
                            if(extendNightsGroup) extendNightsGroup.style.display = 'none';
                            if(extendHoursInput) { extendHoursInput.disabled = true; extendHoursInput.value = '1';}
                            if(extendNightsInput) { extendNightsInput.disabled = true; extendNightsInput.value = '1';}
                            newTotalBookingValue = currentBooking_DB_ServiceTotalPrice_Ext;
                            calculatedExtensionCostOnly = 0;
                        }


                        const pad = n => String(n).padStart(2, '0');
                        if (newCheckoutTimeDisplay) newCheckoutTimeDisplay.textContent = `${newCheckoutCalc.getFullYear()}-${pad(newCheckoutCalc.getMonth() + 1)}-${pad(newCheckoutCalc.getDate())} ${pad(newCheckoutCalc.getHours())}:${pad(newCheckoutCalc.getMinutes())} น.`;

                        if (extensionDurationDetailsDisplay) {
                            extensionDurationDetailsDisplay.textContent = extensionDetailsText;
                        }

                        if (additionalCostDisplay) additionalCostDisplay.textContent = String(Math.round(calculatedExtensionCostOnly));
                        if (newTotalAmountDisplay && typeof newTotalBookingValue !== 'undefined') {
                            newTotalAmountDisplay.textContent = String(Math.round(newTotalBookingValue));
                        } else if (newTotalAmountDisplay) {
                            newTotalAmountDisplay.textContent = String(Math.round(currentBooking_DB_ServiceTotalPrice_Ext + calculatedExtensionCostOnly));
                             console.warn("[ExtendCost] newTotalBookingValue was undefined, fallback display calculation used. Review logic if this is not expected.");
                        }
                        if(paymentForExtensionInput) paymentForExtensionInput.value = String(Math.round(calculatedExtensionCostOnly));

                        if (paymentDueForExtensionDisplay) {
                            paymentDueForExtensionDisplay.textContent = String(Math.round(calculatedExtensionCostOnly));
                        }
                        console.log(`[ExtendCost] Type: ${type}, Extension Detail: ${extensionDetailsText}, Initial DB Service Total (Room+Addons): ${currentBooking_DB_ServiceTotalPrice_Ext}, Initial Short Stay Room Cost (if applicable): ${initialShortStayRoomCost_Ext}, Calculated Extension Cost (Payment Now): ${calculatedExtensionCostOnly}, New Total Booking Value (New Room+Addons): ${newTotalBookingValue ? String(Math.round(newTotalBookingValue)) : 'N/A'}, Current Paid Original: ${currentAmountPaidOriginal}, Payment Due for this Extension: ${calculatedExtensionCostOnly}`);
                    }


                    if(extendTypeSelect) extendTypeSelect.onchange = () => {
                        if (extendHoursInput) extendHoursInput.value = '1';
                        if (extendNightsInput) extendNightsInput.value = '1';
                        updateExtendCostAndPaymentScoped();
                    };
                    if(extendHoursInput) extendHoursInput.oninput = updateExtendCostAndPaymentScoped;
                    if(extendNightsInput) extendNightsInput.oninput = updateExtendCostAndPaymentScoped;

                    updateExtendCostAndPaymentScoped();

                    setTimeout(() => {
                         extendFormContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            });

        }


        const cancelExtendBtn = modalBodyElement.querySelector('#cancel-extend-stay-btn');
        if (cancelExtendBtn && extendFormContainer) {
            cancelExtendBtn.addEventListener('click', () => {
                if (extendFormContainer) extendFormContainer.style.display = 'none';
            });
        }

        const submitExtendBtn = modalBodyElement.querySelector('#submit-extend-stay-btn');
        if (submitExtendBtn && extendForm) {
            submitExtendBtn.addEventListener('click', async () => {
                const formData = new FormData(extendForm);

                if (!formData.has('booking_id_extend') || !formData.get('booking_id_extend')) {
                    console.warn('Error: Booking ID for extension not found.'); return;
                }
                if (!confirm('ยืนยันการขยายเวลาการเข้าพักตามข้อมูลนี้?')) return;

                setButtonLoading(submitExtendBtn, true, 'submitExtendBtn');
                const url = `${API_BASE_URL}?action=extend_stay`;
                try {
                    const response = await fetch(url, { method: 'POST', body: formData });
                    const responseText = await response.text();
                    console.log("[ExtendStay Submit] Raw response:", responseText);
                    if (!response.ok) {
                        console.error(`Server error: ${response.status}. ${responseText.substring(0,200)}`);
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        console.log(data.message || 'Stay extended successfully.');
                        window.location.reload();
                    } else {
                        console.error('Extension error:', data.message || 'Unknown error.');
                    }
                } catch (err) {
                    console.error('[ExtendStay] Submission/processing error:', err);
                    console.error('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อขยายเวลาได้ หรือการตอบกลับไม่ใช่ JSON ที่ถูกต้อง');
                } finally {
                    setButtonLoading(submitExtendBtn, false, 'submitExtendBtn');
                }
            });
        }

        const showEditDetailsBtn = modalBodyElement.querySelector('#show-edit-booking-details-btn');
        const editDetailsFormContainer = modalBodyElement.querySelector('#edit-booking-details-form-container');
        const editDetailsForm = modalBodyElement.querySelector('#edit-booking-details-form');
        const adjustmentTypeSelect = modalBodyElement.querySelector('#adjustment_type');
        const adjustmentAmountGroup = modalBodyElement.querySelector('#adjustment_amount_group');
        const adjustmentAmountInput = modalBodyElement.querySelector('#adjustment_amount');
        const adjustmentPaymentMethodGroup = modalBodyElement.querySelector('#adjustment_payment_method_group');
        const adjustmentReceiptGroup = modalBodyElement.querySelector('#adjustment_receipt_group');
        const currentPaidForEditDisplay = modalBodyElement.querySelector('#current_paid_for_edit_display');
        const newTotalPriceAfterAdjustmentDisplay = modalBodyElement.querySelector('#new_total_price_after_adjustment_display');
        const netChangeAmountDisplay = modalBodyElement.querySelector('#net_change_amount_display');
        const submitEditDetailsBtn = modalBodyElement.querySelector('#submit-edit-booking-details-btn');
        const cancelEditDetailsBtn = modalBodyElement.querySelector('#cancel-edit-booking-details-btn');
        const editAddonChipsContainerModal = modalBodyElement.querySelector('#edit-addon-chips-container-modal');
        const modalTotalAddonPriceDisplay = modalBodyElement.querySelector('#modal-total-addon-price-display');

        let initialBooking_DB_RoomCost_ForEdit = 0;
        let initialBooking_DB_Deposit_ForEdit = 0;
        let currentTotalActuallyPaidByCustomer_ForEdit = 0;

        function calculateAndUpdateTotalsInEditModal() {
            if (!newTotalPriceAfterAdjustmentDisplay || !netChangeAmountDisplay ||
                !modalTotalAddonPriceDisplay || !adjustmentTypeSelect || !adjustmentAmountInput ||
                !editAddonChipsContainerModal || !currentPaidForEditDisplay ) {
                console.warn("[EditModalTotals] Missing one or more display/input elements for calculation in edit modal.");
                return;
            }

            let newlySelectedAddonCostInModal = 0;
            editAddonChipsContainerModal.querySelectorAll('.addon-checkbox-modal:checked').forEach(checkbox => {
                const price = Math.round(parseFloat(checkbox.dataset.price) || 0);
                const quantityInput = editAddonChipsContainerModal.querySelector(`.addon-quantity-modal[data-addon-id="${checkbox.value}"]`);
                const quantity = quantityInput ? (parseInt(quantityInput.value) || 1) : 1;
                newlySelectedAddonCostInModal += price * quantity;
            });
            modalTotalAddonPriceDisplay.textContent = String(Math.round(newlySelectedAddonCostInModal));

            const newCalculatedServiceValueAndDeposit = initialBooking_DB_RoomCost_ForEdit + newlySelectedAddonCostInModal + initialBooking_DB_Deposit_ForEdit;
            newTotalPriceAfterAdjustmentDisplay.textContent = String(Math.round(newCalculatedServiceValueAndDeposit));

            const adjustmentTypeValue = adjustmentTypeSelect.value;
            const adjustmentAmountValue = parseFloat(adjustmentAmountInput.value) || 0;

            let finalCustomerPaidAfterThisTransaction = currentTotalActuallyPaidByCustomer_ForEdit;
            if (adjustmentTypeValue === 'add' && adjustmentAmountValue > 0) {
                finalCustomerPaidAfterThisTransaction += adjustmentAmountValue;
            } else if (adjustmentTypeValue === 'reduce' && adjustmentAmountValue > 0) {
                finalCustomerPaidAfterThisTransaction -= adjustmentAmountValue;
            }

            const outstandingOrOverpaid = newCalculatedServiceValueAndDeposit - finalCustomerPaidAfterThisTransaction;

            let displayText = '';
            if (Math.abs(outstandingOrOverpaid) < 0.005) displayText = ' (ไม่มียอดเปลี่ยนแปลงสุทธิ จากยอดที่เคยชำระ)';
            else if (outstandingOrOverpaid > 0) displayText = ` (ลูกค้าต้องชำระเพิ่มจากยอดที่เคยชำระแล้ว หรือ ยอดปรับปรุงนี้ยังไม่ครอบคลุม)`;
            else displayText = ` (ต้องคืนเงินให้ลูกค้า หรือ ยอดปรับปรุงนี้เกินกว่าที่ต้องชำระ)`;

            netChangeAmountDisplay.textContent = `${String(Math.round(outstandingOrOverpaid))}${displayText}`;

            console.log(`[EditModalTotals (No Nights Edit)] InitialRoomCost: ${initialBooking_DB_RoomCost_ForEdit}, DB Deposit: ${initialBooking_DB_Deposit_ForEdit}, NewModalAddonCost: ${newlySelectedAddonCostInModal}, NewBookingTotalPrice (Room+NewAddons+Deposit): ${newCalculatedServiceValueAndDeposit}, CurrentTotalPaidByCustomer(DB): ${currentTotalActuallyPaidByCustomer_ForEdit}, AdjType: ${adjustmentTypeValue}, AdjAmtEntered: ${adjustmentAmountValue}, CustomerTotalPaidAfterThisAdj: ${finalCustomerPaidAfterThisTransaction}, Outstanding/Overpaid: ${outstandingOrOverpaid}`);
        }


        if (showEditDetailsBtn && editDetailsFormContainer && editDetailsForm) {
            showEditDetailsBtn.addEventListener('click', () => {
                const shouldShowEditForm = editDetailsFormContainer.style.display === 'none';
                editDetailsFormContainer.style.display = shouldShowEditForm ? 'block' : 'none';

                if (extendFormContainer) extendFormContainer.style.display = 'none';
                if (returnDepositFormDiv) returnDepositFormDiv.style.display = 'none';

                if (shouldShowEditForm) {
                    initialBooking_DB_RoomCost_ForEdit = parseFloat(modalBodyElement.querySelector('#js-edit-initial-room-cost')?.value) || 0;
                    initialBooking_DB_Deposit_ForEdit = parseFloat(modalBodyElement.querySelector('#js-edit-initial-deposit-amount')?.value) || 0;
                    currentTotalActuallyPaidByCustomer_ForEdit = parseFloat(modalBodyElement.querySelector('#js-edit-initial-total-paid')?.value) || 0;

                    if (currentPaidForEditDisplay) currentPaidForEditDisplay.textContent = String(Math.round(currentTotalActuallyPaidByCustomer_ForEdit));

                    if(editAddonChipsContainerModal) {
                         editAddonChipsContainerModal.querySelectorAll('.addon-checkbox-modal').forEach(phpRenderedCb => {
                            const wrapper = phpRenderedCb.closest('.addon-chip-wrapper');
                            const qtyInput = wrapper?.querySelector(`.addon-quantity-modal[data-addon-id="${phpRenderedCb.value}"]`);
                            phpRenderedCb.checked = phpRenderedCb.dataset.initialChecked === 'true';

                            if(qtyInput) {
                                qtyInput.value = phpRenderedCb.dataset.initialQuantity || '1';
                                if (phpRenderedCb.checked) {
                                    if(wrapper) wrapper.classList.add('selected');
                                    qtyInput.style.display = 'inline-block'; qtyInput.disabled = false;
                                } else {
                                    if(wrapper) wrapper.classList.remove('selected');
                                    qtyInput.style.display = 'none'; qtyInput.disabled = true;
                                }
                            }
                         });
                    }
                    const notesTextarea = modalBodyElement.querySelector('#edit_notes');
                    if(notesTextarea && typeof notesTextarea.dataset.initialValue !== 'undefined') {
                        notesTextarea.value = notesTextarea.dataset.initialValue;
                    }

                    if (adjustmentTypeSelect) adjustmentTypeSelect.value = 'none';
                    if (adjustmentAmountInput) adjustmentAmountInput.value = '0';
                    if (adjustmentAmountGroup) adjustmentAmountGroup.style.display = 'none';
                    if (adjustmentPaymentMethodGroup) adjustmentPaymentMethodGroup.style.display = 'none';
                    const adjustmentReceiptInput = modalBodyElement.querySelector('#adjustment_receipt');
                    if(adjustmentReceiptInput) adjustmentReceiptInput.value = '';

                    if (adjustmentReceiptGroup) {
                         const shouldShowReceipt = (adjustmentTypeSelect && adjustmentTypeSelect.value === 'add');
                         adjustmentReceiptGroup.style.display = shouldShowReceipt ? 'block' : 'none';
                    }

                    calculateAndUpdateTotalsInEditModal();
                    if (editDetailsFormContainer.style.display === 'block') {
                        setTimeout(() => {
                            editDetailsFormContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 100);
                    }
                }
            });
        }

        if (editAddonChipsContainerModal) {
            editAddonChipsContainerModal.addEventListener('input', (e) => {
                const target = e.target;
                if (target.classList.contains('addon-checkbox-modal') || target.classList.contains('addon-quantity-modal')) {
                    const wrapper = target.closest('.addon-chip-wrapper');
                    const addonId = target.classList.contains('addon-quantity-modal') ? target.dataset.addonId : target.value;
                    const checkbox = editAddonChipsContainerModal.querySelector(`#modal_addon_${addonId}`);
                    const quantityInput = editAddonChipsContainerModal.querySelector(`.addon-quantity-modal[data-addon-id="${addonId}"]`);

                    if (checkbox && quantityInput && wrapper) {
                        if (checkbox.checked) {
                            quantityInput.style.display = 'inline-block'; quantityInput.disabled = false;
                            if (parseInt(quantityInput.value) < 1 || quantityInput.value === '') quantityInput.value = 1;
                            wrapper.classList.add('selected');
                        } else {
                            quantityInput.style.display = 'none'; quantityInput.disabled = true;
                            wrapper.classList.remove('selected');
                        }
                    }
                    calculateAndUpdateTotalsInEditModal();
                }
            });
        }


        if (adjustmentTypeSelect) {
            adjustmentTypeSelect.addEventListener('change', function() {
                const showAdjustmentFields = this.value !== 'none';
                if(adjustmentAmountGroup) adjustmentAmountGroup.style.display = showAdjustmentFields ? 'block' : 'none';
                if(adjustmentPaymentMethodGroup) adjustmentPaymentMethodGroup.style.display = showAdjustmentFields ? 'block' : 'none';
                if(adjustmentReceiptGroup) adjustmentReceiptGroup.style.display = (showAdjustmentFields && this.value === 'add') ? 'block' : 'none';
                if (!showAdjustmentFields && adjustmentAmountInput) {
                    adjustmentAmountInput.value = '0';
                }
                calculateAndUpdateTotalsInEditModal();
            });
        }
        if (adjustmentAmountInput) {
            adjustmentAmountInput.addEventListener('input', calculateAndUpdateTotalsInEditModal);
        }
        const notesTextareaForEdit = modalBodyElement.querySelector('#edit_notes');
        if (notesTextareaForEdit) {
        }


        if (cancelEditDetailsBtn && editDetailsFormContainer) {
            cancelEditDetailsBtn.addEventListener('click', () => {
                if (editDetailsFormContainer) editDetailsFormContainer.style.display = 'none';
            });
        }

        if (submitEditDetailsBtn && editDetailsForm) {
            submitEditDetailsBtn.addEventListener('click', async () => {
                calculateAndUpdateTotalsInEditModal();
                const formData = new FormData(editDetailsForm);

                if (!formData.has('booking_id_edit_details') || !formData.get('booking_id_edit_details')) {
                    console.warn('Error: Booking ID for editing details not found.'); return;
                }
                const adjType = formData.get('adjustment_type');
                const adjAmount = parseFloat(formData.get('adjustment_amount')) || 0;

                if (adjType === 'add' && adjAmount > 0 && !formData.get('adjustment_payment_method')) {
                    console.warn('Please specify payment method for adding adjustment.'); return;
                }
                 if (adjType === 'reduce' && adjAmount > 0 && !formData.get('adjustment_payment_method')) {
                    console.warn('Please specify refund method for reducing/refunding adjustment.'); return;
                }

                if (!confirm('ยืนยันการแก้ไขหมายเหตุ, บริการเสริม และ/หรือปรับยอดชำระนี้?')) return;

                setButtonLoading(submitEditDetailsBtn, true, 'submitEditDetailsBtn');
                const url = `${API_BASE_URL}?action=edit_booking_details`;
                try {
                    const response = await fetch(url, { method: 'POST', body: formData });
                    const responseText = await response.text();
                    if (!response.ok) {
                        console.error(`Server error: ${response.status}. ${responseText}`);
                        throw new Error(`Server error: ${response.status}`);
                    }
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        console.log(data.message || 'Booking details edited successfully.');
                        window.location.reload();
                    } else {
                        console.error('Error editing details:', data.message || 'Unknown error.');
                    }
                } catch (err) {
                    console.error('[EditDetails] Submission/processing error:', err);
                    console.error('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อแก้ไขรายละเอียดได้ หรือการตอบกลับไม่ใช่ JSON');
                } finally {
                    setButtonLoading(submitEditDetailsBtn, false, 'submitEditDetailsBtn');
                }
            });
        }

        const showMoveModalBtn = modalBodyElement.querySelector('.show-move-room-modal-btn');
        // const moveRoomModal = document.getElementById('move-room-modal'); // <<-- ลบบรรทัดนี้ออก
        
        if (showMoveModalBtn && moveRoomModal) {
            showMoveModalBtn.addEventListener('click', async function() {
                const bookingId = this.dataset.bookingId;
                const currentRoomId = this.dataset.currentRoomId;
                const customerName = this.dataset.customerName;

                const moveInfoText = moveRoomModal.querySelector('#move-room-info-text');
                const newRoomSelect = moveRoomModal.querySelector('#select-new-room');
                const confirmMoveBtn = moveRoomModal.querySelector('#confirm-move-room-btn');

                moveInfoText.textContent = `ย้ายการจองของคุณ "${customerName}"...`;
                newRoomSelect.innerHTML = '<option value="">กำลังโหลดห้องที่ว่าง...</option>';
                newRoomSelect.disabled = true;
                confirmMoveBtn.disabled = true;

                hideModal(detailsModal); 
                showModal(moveRoomModal); 

                try {
                    const response = await fetch(`/hotel_booking/pages/api.php?action=get_available_rooms_for_move&booking_id=${bookingId}`);
                    const data = await response.json();

                    if (data.success && data.rooms.length > 0) {
                        newRoomSelect.innerHTML = '<option value="">-- กรุณาเลือกห้องใหม่ --</option>';
                        data.rooms.forEach(room => {
                            const option = document.createElement('option');
                            option.value = room.id;
                            option.textContent = `ห้อง ${room.zone}${room.room_number} (ปกติ: ${Math.round(room.price_per_day)} บ.)`;
                            newRoomSelect.appendChild(option);
                        });
                        newRoomSelect.disabled = false;
                        confirmMoveBtn.disabled = false;
                    } else if (data.success) {
                        newRoomSelect.innerHTML = '<option value="">ไม่มีห้องว่างสำหรับย้ายในช่วงเวลานี้</option>';
                    } else {
                        throw new Error(data.message || 'ไม่สามารถโหลดรายชื่อห้องได้');
                    }
                } catch (error) {
                    console.error("Fetch available rooms error:", error);
                    newRoomSelect.innerHTML = `<option value="">เกิดข้อผิดพลาด: ${error.message}</option>`;
                }

                confirmMoveBtn.onclick = async function() {
                    const newRoomId = newRoomSelect.value;
                    if (!newRoomId) {
                        console.warn('Please select a new room to move to.');
                        return;
                    }

                    if (!confirm(`คุณแน่ใจหรือไม่ว่าต้องการย้ายการจองนี้ไปยังห้อง ${newRoomSelect.options[newRoomSelect.selectedIndex].text}?`)) {
                        return;
                    }

                    setButtonLoading(this, true, 'confirm-move-room-btn');

                    const formData = new FormData();
                    formData.append('action', 'move_booking');
                    formData.append('booking_id_to_move', bookingId);
                    formData.append('new_room_id', newRoomId);

                    try {
                        const moveResponse = await fetch('/hotel_booking/pages/api.php', { method: 'POST', body: formData });
                        const moveResult = await moveResponse.json();

                        if (moveResult.success) {
                            console.log(moveResult.message || 'ย้ายห้องสำเร็จ!');
                            window.location.reload();
                        } else {
                            console.error('Error moving room:', moveResult.message);
                        }
                    } catch (err) {
                        console.error('Move booking error:', err);
                        console.error('Connection failed while moving booking.');
                    } finally {
                        setButtonLoading(this, false, 'confirm-move-room-btn');
                    }
                };
            });
        }
    }


    document.body.addEventListener('click', async (e) => {
        const clickedDeleteButton = e.target.closest('.delete-booking-btn');

        document.querySelectorAll('.delete-booking-btn[data-cancel-step="confirm"]').forEach(btn => {
            if (!clickedDeleteButton || btn !== clickedDeleteButton) {
                if (btn.dataset.originalText) {
                    btn.textContent = btn.dataset.originalText;
                }
                btn.classList.remove('btn-danger-confirm');
                delete btn.dataset.cancelStep;
            }
        });

        if (clickedDeleteButton) {
            e.preventDefault();
            const bookingId = clickedDeleteButton.dataset.bookingId;
            const buttonHtmlId = clickedDeleteButton.id || `deleteBtn-dynamic-${bookingId}`;

            if (clickedDeleteButton.dataset.cancelStep === 'confirm') {
                const actualOriginalButtonText = clickedDeleteButton.dataset.originalText || 'ลบ';
                const loadingKey = `${buttonHtmlId}-loading-confirm-${Date.now()}`;
                originalButtonContents[loadingKey] = clickedDeleteButton.innerHTML;
                setButtonLoading(clickedDeleteButton, true, loadingKey);

                const url = `${API_BASE_URL}?action=update`;
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ booking_id: bookingId, update_action: 'delete' })
                    });
                    const data = await response.json();
                    if (data.success) {
                        console.log(data.message || 'การจองถูกยกเลิก/ลบเรียบร้อยแล้ว');
                        window.location.reload();
                    } else {
                        console.error('Error cancelling/deleting booking:', data.message || 'Unknown error.');
                        setButtonLoading(clickedDeleteButton, false, loadingKey);
                        clickedDeleteButton.textContent = actualOriginalButtonText;
                        clickedDeleteButton.classList.remove('btn-danger-confirm');
                        delete clickedDeleteButton.dataset.cancelStep;
                    }
                } catch (err) {
                    console.error('Cancel/Delete booking error:', err);
                    console.error('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อยกเลิก/ลบการจองได้');
                    setButtonLoading(clickedDeleteButton, false, loadingKey);
                    clickedDeleteButton.textContent = actualOriginalButtonText;
                    clickedDeleteButton.classList.remove('btn-danger-confirm');
                    delete clickedDeleteButton.dataset.cancelStep;
                }
            } else {
                if (!clickedDeleteButton.dataset.originalTextSet) {
                    clickedDeleteButton.dataset.originalText = clickedDeleteButton.textContent.trim();
                    clickedDeleteButton.dataset.originalTextSet = "true";
                }
                clickedDeleteButton.textContent = `ยืนยันยกเลิก ID ${bookingId}?`;
                clickedDeleteButton.dataset.cancelStep = 'confirm';
                clickedDeleteButton.classList.add('btn-danger-confirm');
            }
        }

        const clickedOccupyBtnTable = e.target.closest('.occupy-btn-table');
        if (clickedOccupyBtnTable) {
            e.preventDefault();
            const bookingId = clickedOccupyBtnTable.dataset.bookingId;
            if (!bookingId) {
                console.warn('Error: Booking ID for check-in not found.');
                return;
            }
            if (confirm(`คุณต้องการยืนยันการเช็คอินสำหรับการจอง ID: ${bookingId} หรือไม่?`)) {
                const buttonIdForLoading = clickedOccupyBtnTable.id || `occupy-tbl-${bookingId}`;
                setButtonLoading(clickedOccupyBtnTable, true, buttonIdForLoading);
                const url = `${API_BASE_URL}?action=update`;
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ booking_id: bookingId, update_action: 'occupy' })
                    });
                    const data = await response.json();
                    if (data.success) {
                        console.log(data.message || 'เช็คอินสำเร็จ!');
                        fetchAndUpdateRoomStatuses(); 
                    } else {
                        console.error('Check-in error:', data.message || 'Unknown error.');
                    }
                } catch (err) {
                    console.error('[OccupyBtnTable] API error:', err);
                    console.error('Failed to connect to server for check-in.');
                } finally {
                    setButtonLoading(clickedOccupyBtnTable, false, buttonIdForLoading);
                }
            }
        }

        const globalReceiptBtn = e.target.closest('.receipt-btn-global, .proof-thumb, .receipt-thumbnail-table');
        if (globalReceiptBtn && imageModal && imageModalImage) {
            const imgSrc = globalReceiptBtn.dataset.src || globalReceiptBtn.src;
            if (imgSrc) {
                imageModalImage.src = imgSrc;
                 if (typeof showModal === 'function') {
                    showModal(imageModal);
                 } else {
                    imageModal.classList.add('show');
                 }
            }
        }

        const saveRoomPriceBtn = e.target.closest('.save-room-price-btn');
        if (saveRoomPriceBtn) {
            e.preventDefault();
            const roomId = saveRoomPriceBtn.dataset.roomId;
            const row = saveRoomPriceBtn.closest('tr');
            if (!roomId || !row) {
                console.error('Could not find room ID or table row for saving price.');
                return;
            }

            const pricePerDayInput = row.querySelector(`input[name="price_per_day_${roomId}"]`);
            const priceShortStayInput = row.querySelector(`input[name="price_short_stay_${roomId}"]`);
            const newPricePerDay = pricePerDayInput ? pricePerDayInput.value : null;
            const newPriceShortStay = priceShortStayInput ? priceShortStayInput.value : null;

            if (newPricePerDay === null && newPriceShortStay === null) {
                console.warn('No price data found to update or input name is incorrect.'); return;
            }
            if ((newPricePerDay !== null && (isNaN(parseFloat(newPricePerDay)) || parseFloat(newPricePerDay) < 0)) ||
                (newPriceShortStay !== null && (isNaN(parseFloat(newPriceShortStay)) || parseFloat(newPriceShortStay) < 0))) {
                console.warn('Price must be a non-negative number.'); return;
            }

            const formData = new FormData();
            formData.append('action', 'update_room_price');
            formData.append('room_id_price_update', roomId);
            if (newPricePerDay !== null) formData.append('new_price_per_day', newPricePerDay);
            if (newPriceShortStay !== null) formData.append('new_price_short_stay', newPriceShortStay);

            const buttonId = saveRoomPriceBtn.id || `savePriceBtn-${roomId}`;
            setButtonLoading(saveRoomPriceBtn, true, buttonId);
            try {
                const response = await fetch(API_BASE_URL, { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    console.log(data.message || 'อัปเดตราคาห้องพักสำเร็จ!');
                } else {
                    console.error('Error updating room price:', data.message || 'Unknown error.');
                }
            } catch (err) {
                console.error('Update room price error:', err);
                console.error('Connection failed or invalid server response.');
            } finally {
                setButtonLoading(saveRoomPriceBtn, false, buttonId);
            }
        }
    });

    const allModals = [detailsModal, imageModal, depositModal, editAddonModal, moveRoomModal]; 

    allModals.forEach(modalInstance => {
        if (modalInstance) {
            const modalContent = modalInstance.querySelector('.modal-content');
            const allCloseButtonsInModal = modalInstance.querySelectorAll('.modal-close, .close-modal-btn');

            allCloseButtonsInModal.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    hideModal(modalInstance);
                });
            });

            modalInstance.addEventListener('click', (e) => {
                if (e.target === modalInstance) {
                    if (modalContent && !modalContent.contains(e.target)) {
                         hideModal(modalInstance);
                    } else if (!modalContent) {
                         hideModal(modalInstance);
                    }
                }
            });
        }
    });

    document.addEventListener('keyup', (e) => {
        if (e.key === 'Escape' || e.key === 'Esc') {
            allModals.forEach(modal => {
                if (modal && modal.classList.contains('show')) {
                    hideModal(modal);
                }
            });
            const confirmBookingModalEsc = document.getElementById('confirmBookingModal');
            if (confirmBookingModalEsc && confirmBookingModalEsc.classList.contains('show')) {
                hideModal(confirmBookingModalEsc);
                const submitBookingBtnEsc = document.getElementById('submit-booking-form-btn');
                if(submitBookingBtnEsc) setButtonLoading(submitBookingBtnEsc, false, submitBookingBtnEsc.id || 'submitBookingFormBtn');
            }
        }
    });


    /** 7) Settings Management Page Logic (Addons, Hourly Rate) **/
    const addAddonForm = document.getElementById('add-addon-form');
    const addonServicesTableBody = document.querySelector('#addon-services-table tbody');
    const updateHourlyRateForm = document.getElementById('update-hourly-rate-form');
    const editAddonModalForm = document.getElementById('edit-addon-modal-form');
    const editAddonIdInput = document.getElementById('edit_addon_id');
    const editAddonNameInput = document.getElementById('edit_addon_name_modal');
    const editAddonPriceInput = document.getElementById('edit_addon_price_modal');

    if (addAddonForm) {
        const submitAddAddonBtn = addAddonForm.querySelector('button[type="submit"]#submitAddAddonBtn');
        addAddonForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(addAddonForm);
            const name = formData.get('name');
            const price = formData.get('price');
            if (!name || !name.trim() || !price || parseFloat(price) < 0) {
                console.warn('Please enter a valid name and non-negative price.'); return;
            }
            if(submitAddAddonBtn) setButtonLoading(submitAddAddonBtn, true, 'submitAddAddonBtn');
            try {
                const response = await fetch(`${API_BASE_URL}?action=add_addon_service`, { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    console.log(data.message || "Addon service added successfully.");
                    addAddonForm.reset(); 
                    location.reload();
                } else { 
                  console.error(`Failed to add addon: ${data.message || 'Unknown error'}`);
                }
            } catch (err) { 
              console.error('Add addon error:', err); 
              console.error('Connection error while adding addon.');
            } finally { if(submitAddAddonBtn) setButtonLoading(submitAddAddonBtn, false, 'submitAddAddonBtn'); }
        });
    }

    if (addonServicesTableBody) {
        addonServicesTableBody.addEventListener('click', async (e) => {
            const target = e.target.closest('button');
            if (!target) return;

            const row = target.closest('tr');
            if (!row) return;
            const addonId = row.dataset.addonId;
            if (!addonId) return;

            if (target.classList.contains('edit-addon-btn')) {
                if (editAddonModal && editAddonIdInput && editAddonNameInput && editAddonPriceInput) {
                    editAddonIdInput.value = addonId;
                    editAddonNameInput.value = target.dataset.name || row.querySelector('.addon-name')?.textContent.trim() || '';
                    const priceText = target.dataset.price || row.querySelector('.addon-price')?.textContent.trim() || '0';
                    editAddonPriceInput.value = parseFloat(priceText.replace(/[^0-9.-]+/g, '')) || 0.00;
                    showModal(editAddonModal);
                } else {
                    console.error("Edit addon modal or its form elements not found. Check IDs: edit-addon-modal, edit_addon_id, etc.");
                }
            }

            if (target.classList.contains('toggle-addon-status-btn')) {
                if (!confirm('คุณต้องการเปลี่ยนสถานะบริการเสริมนี้ใช่หรือไม่?')) return;
                const buttonId = target.id || `toggleAddon-${addonId}`;
                setButtonLoading(target, true, buttonId);
                try {
                    const response = await fetch(`${API_BASE_URL}?action=toggle_addon_service_status`, {
                        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({ id: addonId })
                    });
                    const data = await response.json();
                    if (data.success) { 
                      console.log(data.message || "Addon status changed successfully.");
                      location.reload();
                    } else { 
                      console.error(`Failed to change addon status: ${data.message || 'Unknown error'}`);
                    }
                } catch (err) { 
                  console.error('Toggle addon status error:', err); 
                  console.error('Connection error while toggling addon status.');
                } finally { setButtonLoading(target, false, buttonId); }
            }
        });
    }

    if (editAddonModalForm && editAddonModal) {
        const submitEditAddonBtn = editAddonModalForm.querySelector('button[type="submit"]#submitEditAddonBtn');
        editAddonModalForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(editAddonModalForm);
            const name = formData.get('name'); const price = formData.get('price');
            if (!name || !name.trim() || !price || parseFloat(price) < 0) {
                console.warn('Please enter a valid name and non-negative price.'); return;
            }
            if(submitEditAddonBtn) setButtonLoading(submitEditAddonBtn, true, 'submitEditAddonBtn');
            try {
                const response = await fetch(`${API_BASE_URL}?action=update_addon_service`, { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    console.log(data.message || "Addon service updated successfully.");
                    hideModal(editAddonModal); 
                    location.reload();
                } else { 
                  console.error(`Failed to update addon: ${data.message || 'Unknown error'}`);
                }
            } catch (err) { 
              console.error('Update addon error:', err); 
              console.error('Connection error while updating addon.');
            } finally { if(submitEditAddonBtn) setButtonLoading(submitEditAddonBtn, false, 'submitEditAddonBtn'); }
        });
    }

    if (updateHourlyRateForm) {
        const submitUpdateHourlyRateBtn = updateHourlyRateForm.querySelector('button[type="submit"]#submitUpdateHourlyRateBtn');
        updateHourlyRateForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(updateHourlyRateForm);
            const value = formData.get('setting_value'); const key = formData.get('setting_key');
            if (value === null || value.trim() === '' || isNaN(parseFloat(value)) || parseFloat(value) < 0) {
                console.warn('Please enter a valid non-negative hourly rate.'); return;
            }
            if(submitUpdateHourlyRateBtn) setButtonLoading(submitUpdateHourlyRateBtn, true, 'submitUpdateHourlyRateBtn');
            try {
                const response = await fetch(`${API_BASE_URL}?action=update_system_setting`, { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    console.log(data.message || "Setting updated successfully.");
                    if (key === 'hourly_extension_rate') {
                        HOURLY_RATE_JS = Math.round(parseFloat(value));
                        const displayElement = document.getElementById('current_hourly_extension_rate_display');
                        if(displayElement) displayElement.textContent = String(Math.round(parseFloat(value)));
                    }
                } else { 
                  console.error(`Failed to update price: ${data.message || 'Unknown error'}`);
                }
            } catch (err) { 
              console.error('Update hourly rate error:', err); 
              console.error('Connection error while updating hourly rate.');
            } finally { if(submitUpdateHourlyRateBtn) setButtonLoading(submitUpdateHourlyRateBtn, false, 'submitUpdateHourlyRateBtn'); }
        });
    }

    async function fetchAndUpdateRoomStatuses() {
        if (!document.querySelector('.rooms-grid') && !document.querySelector('#room-status-table-view')) {
            return;
        }

        try {
            const response = await fetch(`${API_BASE_URL}?action=get_room_statuses`);
            if (!response.ok) {
                console.error('[RoomStatusUpdate] API request failed:', response.status, await response.text());
                return;
            }
            const data = await response.json();

            if (data.success && data.rooms) {
                const allStatusClasses = ['free', 'booked', 'occupied', 'advance_booking', 'f_short_occupied', 'overdue_occupied', 'has-overdue-indicator'];
                let domChanged = false;

                data.rooms.forEach(roomData => {
                    const roomId = roomData.id;
                    const newDisplayStatusFromServer = roomData.display_status;
                    const isOverdueFromServer = roomData.is_overdue == 1 || roomData.is_overdue === true;
                    const newRelevantBookingId = roomData.relevant_booking_id;
                    const currentBookingIdFromServer = roomData.current_booking_id;

                    const isNearingCheckout = roomData.is_nearing_checkout == 1 || roomData.is_nearing_checkout === true;
                    const hasPendingPayment = roomData.has_pending_payment == 1 || roomData.has_pending_payment === true;

                    const roomElementGrid = document.querySelector(`.room-svg-house[data-id='${roomId}']`);
                    if (roomElementGrid) {
                        const currentDisplayStatusOnElement = roomElementGrid.dataset.status;
                        const currentIsOverdueOnElement = roomElementGrid.dataset.isOverdue === 'true';
                        if (currentDisplayStatusOnElement !== newDisplayStatusFromServer || 
                            currentIsOverdueOnElement !== isOverdueFromServer ||
                            (roomElementGrid.dataset.pendingPayment === 'true') !== hasPendingPayment ||
                            (roomElementGrid.dataset.nearingCheckout === 'true') !== isNearingCheckout) {
                            
                            domChanged = true;
                            roomElementGrid.dataset.status = newDisplayStatusFromServer;
                            roomElementGrid.dataset.isOverdue = isOverdueFromServer ? 'true' : 'false';
                            roomElementGrid.dataset.pendingPayment = hasPendingPayment ? 'true' : 'false';
                            roomElementGrid.dataset.nearingCheckout = isNearingCheckout ? 'true' : 'false';

                            allStatusClasses.forEach(cls => roomElementGrid.classList.remove(cls));
                            if (newDisplayStatusFromServer) {
                                roomElementGrid.classList.add(newDisplayStatusFromServer);
                            }

                            let overdueIndicatorSVG = roomElementGrid.querySelector('.overdue-indicator-svg');
                            if (isOverdueFromServer) {
                                roomElementGrid.classList.add('has-overdue-indicator');
                                if (!overdueIndicatorSVG) {
                                    overdueIndicatorSVG = document.createElementNS("http://www.w3.org/2000/svg", "text");
                                    overdueIndicatorSVG.classList.add('overdue-indicator-svg');
                                    overdueIndicatorSVG.setAttribute('x', '85'); overdueIndicatorSVG.setAttribute('y', '25');
                                    overdueIndicatorSVG.setAttribute('font-size', '24'); overdueIndicatorSVG.setAttribute('fill', 'red');
                                    overdueIndicatorSVG.textContent = '⚠️';
                                    roomElementGrid.appendChild(overdueIndicatorSVG);
                                }
                            } else {
                                if (overdueIndicatorSVG) overdueIndicatorSVG.remove();
                            }

                            let pendingPaymentIconSVG = roomElementGrid.querySelector('.pending-payment-indicator-svg');
                            if (hasPendingPayment && (newDisplayStatusFromServer === 'booked' || newDisplayStatusFromServer === 'advance_booking' || newDisplayStatusFromServer === 'occupied')) {
                                if (!pendingPaymentIconSVG) {
                                    pendingPaymentIconSVG = document.createElementNS("http://www.w3.org/2000/svg", "image");
                                    pendingPaymentIconSVG.classList.add('pending-payment-indicator-svg');
                                    pendingPaymentIconSVG.setAttributeNS(null, 'href', '/hotel_booking/assets/image/money_alert.png');
                                    pendingPaymentIconSVG.setAttributeNS(null, 'width', '20'); pendingPaymentIconSVG.setAttributeNS(null, 'height', '20');
                                    pendingPaymentIconSVG.innerHTML = '<title>มียอดค้างชำระ!</title>';
                                    roomElementGrid.appendChild(pendingPaymentIconSVG);
                                }
                                pendingPaymentIconSVG.setAttributeNS(null, 'x', isOverdueFromServer ? '55' : (isNearingCheckout && !isOverdueFromServer ? '25' : '75'));
                                pendingPaymentIconSVG.setAttributeNS(null, 'y', '5');
                            } else {
                                if (pendingPaymentIconSVG) pendingPaymentIconSVG.remove();
                            }

                            let nearingCheckoutIconSVG = roomElementGrid.querySelector('.nearing-checkout-indicator-svg');
                            if (isNearingCheckout && !isOverdueFromServer && newDisplayStatusFromServer === 'occupied') { 
                                if (!nearingCheckoutIconSVG) {
                                    nearingCheckoutIconSVG = document.createElementNS("http://www.w3.org/2000/svg", "image");
                                    nearingCheckoutIconSVG.classList.add('nearing-checkout-indicator-svg');
                                    nearingCheckoutIconSVG.setAttributeNS(null, 'href', '/hotel_booking/assets/image/clock_alert.png');
                                    nearingCheckoutIconSVG.setAttributeNS(null, 'width', '20'); nearingCheckoutIconSVG.setAttributeNS(null, 'height', '20');
                                    nearingCheckoutIconSVG.innerHTML = '<title>ใกล้หมดเวลาเช็คเอาท์!</title>';
                                    roomElementGrid.appendChild(nearingCheckoutIconSVG);
                                }
                                nearingCheckoutIconSVG.setAttributeNS(null, 'x', '5'); 
                                nearingCheckoutIconSVG.setAttributeNS(null, 'y', '5');
                            } else {
                                if (nearingCheckoutIconSVG) nearingCheckoutIconSVG.remove();
                            }
                        }
                        const bookingIdToSetForGrid = currentBookingIdFromServer || newRelevantBookingId;
                        if (bookingIdToSetForGrid && roomElementGrid.dataset.bookingId !== String(bookingIdToSetForGrid)) {
                            roomElementGrid.dataset.bookingId = bookingIdToSetForGrid; domChanged = true;
                        } else if (!bookingIdToSetForGrid && roomElementGrid.dataset.bookingId) {
                            delete roomElementGrid.dataset.bookingId; domChanged = true;
                        }
                    }

                    const roomNameCellTable = document.querySelector(`#room-status-table-view td[data-room-id-cell='${roomId}']`);
                    if (roomNameCellTable) {
                        const row = roomNameCellTable.closest('tr');
                        if (row) {
                            const currentDisplayStatusOnRow = row.dataset.displayStatus;
                            const currentIsOverdueOnRow = row.classList.contains('has-overdue-indicator-row');
                             if (currentDisplayStatusOnRow !== newDisplayStatusFromServer || 
                                 currentIsOverdueOnRow !== isOverdueFromServer ||
                                 (row.dataset.pendingPayment === 'true') !== hasPendingPayment ||
                                 (row.dataset.nearingCheckout === 'true') !== isNearingCheckout) {
                                
                                domChanged = true;
                                row.dataset.displayStatus = newDisplayStatusFromServer;
                                row.dataset.pendingPayment = hasPendingPayment ? 'true' : 'false';
                                row.dataset.nearingCheckout = isNearingCheckout ? 'true' : 'false';
                                
                                const statusIndicatorSpan = row.querySelector('.status-indicator');
                                if (statusIndicatorSpan) {
                                    allStatusClasses.forEach(cls => statusIndicatorSpan.classList.remove(`status-${cls.replace(/_/g, '-')}`));
                                    if (newDisplayStatusFromServer) {
                                        statusIndicatorSpan.classList.add(`status-${newDisplayStatusFromServer.replace(/_/g, '-')}`);
                                        let statusText = newDisplayStatusFromServer.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                                        statusText = statusText.replace('F Short ', 'F ชั่วคราว ');
                                        statusIndicatorSpan.textContent = statusText;
                                    }
                                }

                                let overdueIndicatorTable = roomNameCellTable.querySelector('.overdue-indicator-table');
                                if (isOverdueFromServer) {
                                    row.classList.add('has-overdue-indicator-row');
                                    if (!overdueIndicatorTable) {
                                        overdueIndicatorTable = document.createElement('span');
                                        overdueIndicatorTable.classList.add('overdue-indicator-table');
                                        overdueIndicatorTable.textContent = '⚠️';
                                        roomNameCellTable.querySelector('strong').insertAdjacentElement('afterend', overdueIndicatorTable);
                                    }
                                } else {
                                    row.classList.remove('has-overdue-indicator-row');
                                    if (overdueIndicatorTable) overdueIndicatorTable.remove();
                                }

                                let pendingPaymentIndicatorTable = row.querySelector('.pending-payment-indicator-table');
                                if (hasPendingPayment && (newDisplayStatusFromServer === 'booked' || newDisplayStatusFromServer === 'advance_booking' || newDisplayStatusFromServer === 'occupied')) {
                                    if (!pendingPaymentIndicatorTable) {
                                        pendingPaymentIndicatorTable = document.createElement('span');
                                        pendingPaymentIndicatorTable.classList.add('pending-payment-indicator-table');
                                        pendingPaymentIndicatorTable.title = 'มียอดค้างชำระ!';
                                        pendingPaymentIndicatorTable.innerHTML = '<img src="/hotel_booking/assets/image/money_alert.png" alt="Pending Payment">';
                                        const existingOverdue = roomNameCellTable.querySelector('.overdue-indicator-table');
                                        if (existingOverdue) existingOverdue.insertAdjacentElement('afterend', pendingPaymentIndicatorTable);
                                        else roomNameCellTable.querySelector('strong').insertAdjacentElement('afterend', pendingPaymentIndicatorTable);
                                    }
                                    pendingPaymentIndicatorTable.style.display = 'inline';
                                } else {
                                    if (pendingPaymentIndicatorTable) pendingPaymentIndicatorTable.style.display = 'none';
                                }
                                
                                let nearingCheckoutIndicatorTable = row.querySelector('.nearing-checkout-indicator-table');
                                if (isNearingCheckout && !isOverdueFromServer && newDisplayStatusFromServer === 'occupied') {
                                    if (!nearingCheckoutIndicatorTable) {
                                        nearingCheckoutIndicatorTable = document.createElement('span');
                                        nearingCheckoutIndicatorTable.classList.add('nearing-checkout-indicator-table');
                                        nearingCheckoutIndicatorTable.title = 'ใกล้หมดเวลาเช็คเอาท์!';
                                        nearingCheckoutIndicatorTable.innerHTML = '<img src="/hotel_booking/assets/image/clock_alert.png" alt="Nearing Checkout">';
                                        const existingPending = row.querySelector('.pending-payment-indicator-table');
                                        const existingOverdue = roomNameCellTable.querySelector('.overdue-indicator-table');
                                        if (existingPending && existingPending.style.display !== 'none') existingPending.insertAdjacentElement('afterend', nearingCheckoutIndicatorTable);
                                        else if (existingOverdue) existingOverdue.insertAdjacentElement('afterend', nearingCheckoutIndicatorTable);
                                        else roomNameCellTable.querySelector('strong').insertAdjacentElement('afterend', nearingCheckoutIndicatorTable);
                                    }
                                    nearingCheckoutIndicatorTable.style.display = 'inline';
                                } else {
                                    if (nearingCheckoutIndicatorTable) nearingCheckoutIndicatorTable.style.display = 'none';
                                }
                            }
                            
                            const actionsCell = row.querySelector('.actions-cell');
                            if (actionsCell) {
                                let occupyBtnTable = actionsCell.querySelector(`.occupy-btn-table`);
                                if (newDisplayStatusFromServer === 'booked' && currentBookingIdFromServer) {
                                    if (!occupyBtnTable) {
                                        occupyBtnTable = document.createElement('button');
                                        occupyBtnTable.classList.add('button-small', 'occupy-btn-table', 'success');
                                        occupyBtnTable.textContent = 'เช็คอิน';
                                        const viewRoomBtn = actionsCell.querySelector('.room');
                                        if (viewRoomBtn) viewRoomBtn.insertAdjacentElement('afterend', occupyBtnTable);
                                        else actionsCell.insertBefore(occupyBtnTable, actionsCell.firstChild);
                                        domChanged = true;
                                    }
                                    occupyBtnTable.dataset.bookingId = currentBookingIdFromServer;
                                    occupyBtnTable.id = `occupy-tbl-${currentBookingIdFromServer}`;
                                } else {
                                    if (occupyBtnTable) { occupyBtnTable.remove(); domChanged = true; }
                                }
                            }
                        }
                    }
                });
                if (domChanged && typeof AOS !== 'undefined') {
                    AOS.refresh();
                }
            } else {
                console.error('[RoomStatusUpdate] API response error or no rooms data:', data.message || 'No rooms data');
            }
        } catch (error) {
            console.error('[RoomStatusUpdate] Error fetching or processing room statuses:', error);
        }
    }

    const pathForPolling = window.location.pathname;
    if (pathForPolling.includes('/index.php') || pathForPolling === '/hotel_booking/' || pathForPolling === '/hotel_booking/pages/' || pathForPolling.endsWith('/hotel_booking/pages')) {
        fetchAndUpdateRoomStatuses();
        const ROOM_STATUS_POLL_INTERVAL = 30 * 1000;
        setInterval(fetchAndUpdateRoomStatuses, ROOM_STATUS_POLL_INTERVAL);
        console.log(`[MainJS] Polling for room status updates every ${ROOM_STATUS_POLL_INTERVAL / 1000} seconds on ${pathForPolling}.`);
    }

    function isRelevantPageForAutoRefresh() {
        const path = window.location.pathname;
        return path.includes('/index.php') ||
               path === '/hotel_booking/' ||
               path === '/hotel_booking/pages/' ||
               path.endsWith('/') ||
               path.includes('/report.php');
    }

    if (isRelevantPageForAutoRefresh()) {
        const AUTO_REFRESH_INTERVAL = 5 * 60 * 1000;
        console.log(`[MainJS AutoRefresh] Page will attempt full refresh in ${AUTO_REFRESH_INTERVAL / 60000} minutes if applicable on ${window.location.pathname}.`);

        setTimeout(() => {
            console.log('[MainJS AutoRefresh] Triggering full page refresh...');
            window.location.reload();
        }, AUTO_REFRESH_INTERVAL);
    }
    
    // This is the redundant block that was causing errors. It has been removed in VERSION 2.4
    /*
    const calendarTable = document.querySelector('.calendar-table');
    if (calendarTable && detailsModal && detailsModalBody) {
        // ... (conflicting logic was here) ...
    }
    */

    if (typeof window.viewReceiptImage !== 'function') {
        window.viewReceiptImage = function(src) {
            if (imageModal && imageModalImage) {
                imageModalImage.src = src;
                showModal(imageModal);
            } else {
                window.open(src, '_blank');
            }
        }
    }

    const shareDashboardBtn = document.getElementById('share-dashboard-btn');
    if (shareDashboardBtn) {
        shareDashboardBtn.addEventListener('click', async function() {
            const elementToCapture = document.querySelector('.site-content.container') || document.body;
            const buttonId = this.id;
            setButtonLoading(this, true, buttonId);

            if (elementToCapture && typeof html2canvas === 'function') {
                const originalBodyBg = document.body.style.backgroundColor;
                document.body.style.backgroundColor = 'var(--color-bg, #f8f9fa)';

                try {
                    const canvas = await html2canvas(elementToCapture, {
                        scale: 1.5,
                        useCORS: true,
                        logging: true,
                        windowWidth: elementToCapture.scrollWidth,
                        windowHeight: elementToCapture.scrollHeight
                    });

                    document.body.style.backgroundColor = originalBodyBg;
                    const image = canvas.toDataURL('image/png');
                    const fileName = `dashboard_overview_${new Date().toISOString().slice(0,10)}.png`;

                    if (navigator.share && navigator.canShare && navigator.canShare({ files: [new File([""],fileName, {type: "image/png"})] })) {
                        const response = await fetch(image);
                        const blob = await response.blob();
                        const file = new File([blob], fileName, { type: 'image/png' });

                        await navigator.share({
                            title: 'ภาพรวม Dashboard โรงแรม',
                            text: 'ภาพรวมสถานะห้องพักและสถิติ ณ วันที่ ' + new Date().toLocaleDateString('th-TH'),
                            files: [file]
                        });
                        console.log('Dashboard image shared successfully');
                    } else {
                        const link = document.createElement('a');
                        link.download = fileName;
                        link.href = image;
                        link.click();
                        console.log('Browser does not support direct file sharing. Dashboard overview image downloaded. You can share it manually.');
                    }
                } catch (err) {
                    document.body.style.backgroundColor = originalBodyBg;
                    console.error('Error generating or sharing dashboard image:', err);
                    console.error('Error generating or sharing image: ' + err.message);
                } finally {
                    setButtonLoading(shareDashboardBtn, false, buttonId);
                }
            } else if (typeof html2canvas !== 'function') {
                  console.error('Library for image export (html2canvas) is not loaded. Cannot share dashboard image.');
                  setButtonLoading(shareDashboardBtn, false, buttonId);
            } else {
                  console.error('Could not find content to export for dashboard image.');
                  setButtonLoading(shareDashboardBtn, false, buttonId);
            }
        });
    }

    const receiptInputMain = document.getElementById('receipt');
    const receiptFilenameDisplay = document.getElementById('file-upload-filename');
    const receiptPreviewImage = document.getElementById('receipt-preview-image');

    if (receiptInputMain && receiptFilenameDisplay && receiptPreviewImage) {
        receiptInputMain.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                receiptFilenameDisplay.textContent = "ไฟล์ที่เลือก: " + file.name;

                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        receiptPreviewImage.src = e.target.result;
                        receiptPreviewImage.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                } else {
                    receiptPreviewImage.src = '#';
                    receiptPreviewImage.style.display = 'none';
                    receiptFilenameDisplay.textContent += " (ไม่ใช่รูปภาพ, ไม่แสดงตัวอย่าง)";
                }
            } else {
                receiptFilenameDisplay.textContent = '';
                receiptPreviewImage.src = '#';
                receiptPreviewImage.style.display = 'none';
            }
        });
    }

}); // End of DOMContentLoaded
