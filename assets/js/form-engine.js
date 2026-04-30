/**
 * WP Waiver Engine – Frontend Form Engine
 *
 * Handles:
 *  - Repeatable group row add/remove
 *  - Signature pad (using signature_pad library)
 *  - Client-side validation
 *  - AJAX submission
 */
/* global wpweForm, SignaturePad */

( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Init: called for every .wpwe-form on the page
    // -------------------------------------------------------------------------
    function initForm( form ) {
        initRepeatableGroups( form );
        initSignaturePads( form );
        initPreview( form );
        initEmailCopy( form );
        initBookingSearch( form );
        form.addEventListener( 'submit', onSubmit );
    }

    // -------------------------------------------------------------------------
    // Booking search
    // -------------------------------------------------------------------------
    function initBookingSearch( form ) {
        // The booking-search widget is a sibling element ABOVE the form,
        // inside the same .wpwe-form-wrap.
        var wrap = form.closest( '.wpwe-form-wrap' );
        if ( ! wrap ) return;

        var widget = wrap.querySelector( '.wpwe-booking-search' );
        if ( ! widget ) return;  // template has no linked services

        var bookingIdInput = widget.querySelector( '.wpwe-booking-id-lookup' );
        var bookingEmailInput = widget.querySelector( '.wpwe-booking-email-lookup' );
        var verifyBtn    = widget.querySelector( '.wpwe-booking-verify-btn' );
        var searchingEl   = widget.querySelector( '.wpwe-booking-searching' );
        var errorEl       = widget.querySelector( '.wpwe-booking-search-error' );
        var selectedEl    = widget.querySelector( '.wpwe-booking-selected' );
        var selectedBadge = widget.querySelector( '.wpwe-booking-selected__badge' );
        var clearBtn      = widget.querySelector( '.wpwe-booking-clear-btn' );
        var bookingInput  = form.querySelector( '.wpwe-booking-id-input' );

        if ( ! bookingIdInput || ! bookingEmailInput || ! verifyBtn || ! bookingInput ) return;

        verifyBtn.addEventListener( 'click', function () {
            verifyBooking();
        } );

        bookingEmailInput.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' ) {
                e.preventDefault();
                verifyBooking();
            }
        } );

        clearBtn && clearBtn.addEventListener( 'click', function () {
            clearSelection();
            bookingIdInput.focus();
        } );

        function verifyBooking() {
            var bookingId = parseInt( bookingIdInput.value, 10 );
            var customerEmail = bookingEmailInput.value.trim();

            if ( ! bookingId || bookingId <= 0 || ! customerEmail ) {
                showError( ( wpweForm.i18n && wpweForm.i18n.bookingNoResults ) || 'Booking not found or details do not match.' );
                return;
            }

            if ( searchingEl ) searchingEl.hidden = false;
            clearError();

            var templateId = widget.dataset.templateId || form.dataset.templateId || '';
            var body = new FormData();
            body.append( 'action',      'wpwe_get_booking' );
            body.append( 'nonce',       wpweForm.bookingNonce );
            body.append( 'template_id', templateId );
            body.append( 'booking_id',  bookingId );
            body.append( 'customer_email', customerEmail );

            fetch( wpweForm.ajaxUrl, {
                method:      'POST',
                credentials: 'same-origin',
                body:        body,
            } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( resp ) {
                if ( searchingEl ) searchingEl.hidden = true;
                if ( resp.success && resp.data ) {
                    selectBooking( resp.data );
                } else {
                    showError( ( wpweForm.i18n && wpweForm.i18n.bookingNoResults ) || 'Booking not found or details do not match.' );
                }
            } )
            .catch( function () {
                if ( searchingEl ) searchingEl.hidden = true;
                showError( ( wpweForm.i18n && wpweForm.i18n.bookingNoResults ) || 'Booking not found or details do not match.' );
            } );
        }

        function showError( msg ) {
            if ( ! errorEl ) return;
            errorEl.textContent = msg;
            errorEl.hidden = false;
        }

        function clearError() {
            if ( ! errorEl ) return;
            errorEl.textContent = '';
            errorEl.hidden = true;
        }

        function selectBooking( b ) {
            bookingInput.value = b.id;
            clearError();
            if ( selectedBadge ) {
                selectedBadge.textContent =
                    b.service + ' — ' + b.customer + ' — ' + b.bookingDate;
            }
            if ( selectedEl ) selectedEl.hidden = false;
            widget.classList.add( 'wpwe-booking-search--has-selection' );
        }

        function clearSelection() {
            bookingInput.value = '';
            bookingIdInput.value = '';
            bookingEmailInput.value = '';
            if ( selectedEl ) selectedEl.hidden = true;
            clearError();
            widget.classList.remove( 'wpwe-booking-search--has-selection' );
        }

        // Auto-select a booking when the page URL contains ?booking_id=N.
        // This allows booking confirmation emails to link directly to the waiver
        // form with the customer's appointment already pre-selected.
        var urlParams    = new URLSearchParams( window.location.search );
        var urlBookingId = parseInt( urlParams.get( 'booking_id' ), 10 );
        var templateId   = widget.dataset.templateId || form.dataset.templateId || '';

        if ( urlBookingId > 0 && templateId ) {
            bookingIdInput.value = String( urlBookingId );

            // Optional convenience: support ?booking_email=user@example.com
            var urlBookingEmail = urlParams.get( 'booking_email' );
            if ( urlBookingEmail ) {
                bookingEmailInput.value = urlBookingEmail;
                verifyBooking();
            }

            // If email is not present, keep booking ID pre-filled and wait for user email entry.
        }

        // Privacy-safe flow requires customer email verification before selecting a booking.
    }

    // -------------------------------------------------------------------------
    // Email-copy opt-in toggle
    // -------------------------------------------------------------------------
    function initEmailCopy( form ) {
        var check    = form.querySelector( '.wpwe-email-copy-check' );
        var inputWrap = form.querySelector( '.wpwe-email-copy-input' );
        if ( ! check || ! inputWrap ) return;

        check.addEventListener( 'change', function () {
            if ( this.checked ) {
                inputWrap.hidden = false;
                inputWrap.querySelector( '.wpwe-copy-email-input' ).focus();
            } else {
                inputWrap.hidden = true;
                // clear any previous error
                var errEl = inputWrap.querySelector( '.wpwe-copy-email-error' );
                if ( errEl ) errEl.textContent = '';
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Repeatable groups
    // -------------------------------------------------------------------------
    function initRepeatableGroups( form ) {
        form.querySelectorAll( '.wpwe-group--repeatable' ).forEach( function ( group ) {
            var addBtn = group.querySelector( '.wpwe-add-row' );
            var maxRows = parseInt( group.dataset.maxRows, 10 ) || 20;
            var minRows = parseInt( group.dataset.minRows, 10 ) || 1;

            if ( addBtn ) {
                addBtn.addEventListener( 'click', function () {
                    var rows = group.querySelectorAll( '.wpwe-row' );
                    if ( rows.length >= maxRows ) {
                        return;
                    }
                    addRow( group );
                } );
            }

            // Delegate remove-row clicks
            group.addEventListener( 'click', function ( e ) {
                if ( e.target.classList.contains( 'wpwe-remove-row' ) ) {
                    var row = e.target.closest( '.wpwe-row' );
                    var currentRows = group.querySelectorAll( '.wpwe-row' );
                    if ( currentRows.length > minRows ) {
                        row.remove();
                        reindexRows( group );
                    }
                }
            } );

            // Delegate per-row PDF preview button clicks
            group.addEventListener( 'click', function ( e ) {
                var btn = e.target.closest( '.wpwe-row-preview-btn' );
                if ( btn ) {
                    var row = btn.closest( '.wpwe-row' );
                    if ( row ) {
                        requestPdfPreview(
                            form,
                            group.dataset.groupKey || '',
                            parseInt( row.dataset.rowIndex, 10 ) || 0
                        );
                    }
                }
            } );

            updateAddButtonVisibility( group, maxRows );

            // Mode toggle (for table-layout repeatable groups)
            var modeSelect = group.querySelector( '.wpwe-mode-select' );
            if ( modeSelect ) {
                modeSelect.addEventListener( 'change', function () {
                    handleModeChange( group, this.value );
                } );
            }
        } );
    }

    function addRow( group ) {
        var template = group.querySelector( '.wpwe-row-template' );
        if ( ! template ) return;

        var rows    = group.querySelectorAll( '.wpwe-row' );
        var newIdx  = rows.length;
        var maxRows = parseInt( group.dataset.maxRows, 10 ) || 20;
        var groupLabel = group.querySelector( '.wpwe-group-legend' )
            ? group.querySelector( '.wpwe-group-legend' ).textContent.trim()
            : '';

        var clone = template.content.cloneNode( true );
        var rowEl = clone.querySelector( '.wpwe-row' );

        // Replace __IDX__ placeholder in names/ids with actual index
        rowEl.querySelectorAll( '[name]' ).forEach( function ( el ) {
            el.name = el.name.replace( /__IDX__/g, newIdx );
        } );
        rowEl.querySelectorAll( '[id]' ).forEach( function ( el ) {
            el.id = el.id.replace( /__IDX__/g, newIdx );
        } );
        rowEl.querySelectorAll( '[for]' ).forEach( function ( el ) {
            el.htmlFor = el.htmlFor.replace( /__IDX__/g, newIdx );
        } );
        rowEl.dataset.rowIndex = newIdx;

        var rowLabel = rowEl.querySelector( '.wpwe-row-label' );
        if ( rowLabel ) {
            rowLabel.textContent = groupLabel + ' ' + ( newIdx + 1 );
        }
        var rowNum = rowEl.querySelector( '.wpwe-row-num' );
        if ( rowNum ) {
            rowNum.textContent = newIdx + 1;
        }

        group.querySelector( '.wpwe-rows' ).appendChild( clone );

        reindexRows( group ); // update remove-button visibility for all rows

        // Init any signature pads in the new row
        var newRow = group.querySelector( '.wpwe-rows .wpwe-row:last-child' );
        if ( newRow ) {
            initSignaturePads( newRow );
        }

        updateAddButtonVisibility( group, maxRows );
    }

    function reindexRows( group ) {
        var rows       = group.querySelectorAll( '.wpwe-row' );
        var maxRows    = parseInt( group.dataset.maxRows, 10 ) || 20;
        var groupLabel = group.querySelector( '.wpwe-group-legend' )
            ? group.querySelector( '.wpwe-group-legend' ).textContent.trim()
            : '';
        var groupKey   = group.dataset.groupKey || '';

        rows.forEach( function ( row, i ) {
            row.dataset.rowIndex = i;
            var rowLabel = row.querySelector( '.wpwe-row-label' );
            if ( rowLabel ) rowLabel.textContent = groupLabel + ' ' + ( i + 1 );
            var rowNum = row.querySelector( '.wpwe-row-num' );
            if ( rowNum ) rowNum.textContent = i + 1;

            // Re-index names: wpwe_data[group][OLD][field] -> wpwe_data[group][NEW][field]
            row.querySelectorAll( '[name]' ).forEach( function ( el ) {
                el.name = el.name.replace(
                    new RegExp( '\\[' + groupKey + '\\]\\[\\d+\\]' ),
                    '[' + groupKey + '][' + i + ']'
                );
            } );
            row.querySelectorAll( '[id]' ).forEach( function ( el ) {
                el.id = el.id.replace(
                    new RegExp( '_' + groupKey + '_\\d+_' ),
                    '_' + groupKey + '_' + i + '_'
                );
            } );

            // Show/hide remove button based on min_rows
            var removeBtn = row.querySelector( '.wpwe-remove-row' );
            if ( removeBtn ) {
                var minRows = parseInt( group.dataset.minRows, 10 ) || 1;
                removeBtn.style.display = ( rows.length <= minRows ) ? 'none' : '';
            }
        } );

        updateAddButtonVisibility( group, maxRows );
    }

    function updateAddButtonVisibility( group, maxRows ) {
        var addBtn  = group.querySelector( '.wpwe-add-row' );
        var rowCount = group.querySelectorAll( '.wpwe-row' ).length;
        if ( addBtn ) {
            addBtn.style.display = rowCount >= maxRows ? 'none' : '';
        }
    }

    function handleModeChange( group, mode ) {
        group.dataset.mode = mode;
        var allRows = group.querySelectorAll( '.wpwe-rows .wpwe-row' );
        var addBtn  = group.querySelector( '.wpwe-add-row' );
        var maxRows = parseInt( group.dataset.maxRows, 10 ) || 20;

        if ( mode === 'single' ) {
            allRows.forEach( function ( row, i ) {
                if ( i === 0 ) {
                    row.style.display = '';
                    row.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
                        el.disabled = false;
                    } );
                    var removeBtn = row.querySelector( '.wpwe-remove-row' );
                    if ( removeBtn ) removeBtn.style.display = 'none';
                } else {
                    row.style.display = 'none';
                    row.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
                        el.disabled = true;
                    } );
                }
            } );
            if ( addBtn ) addBtn.style.display = 'none';
        } else {
            // repeating mode
            allRows.forEach( function ( row ) {
                row.style.display = '';
                row.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
                    el.disabled = false;
                } );
            } );
            reindexRows( group );
            updateAddButtonVisibility( group, maxRows );
        }
    }

    // -------------------------------------------------------------------------
    // Signature pads
    // -------------------------------------------------------------------------
    function initSignaturePads( container ) {
        container.querySelectorAll( '.wpwe-signature-wrap' ).forEach( function ( wrap ) {
            var canvas    = wrap.querySelector( '.wpwe-signature-canvas' );
            var input     = wrap.querySelector( '.wpwe-signature-data' );
            var clearBtn  = wrap.querySelector( '.wpwe-clear-signature' );

            if ( ! canvas || ! input ) return;

            // Avoid double-init
            if ( canvas._sigPad ) return;

            var sigPad = new SignaturePad( canvas, {
                backgroundColor: 'rgba(255,255,255,0)',
                penColor: 'rgb(0,0,0)',
            } );
            canvas._sigPad = sigPad;

            // Sync to hidden input on each stroke end
            canvas.addEventListener( 'pointerup', function () {
                input.value = sigPad.isEmpty() ? '' : sigPad.toDataURL( 'image/png' );
            } );

            // Clear button
            if ( clearBtn ) {
                clearBtn.addEventListener( 'click', function () {
                    sigPad.clear();
                    input.value = '';
                } );
            }

            // Resize canvas correctly
            resizeCanvas( canvas, sigPad );
            window.addEventListener( 'resize', debounce( function () {
                resizeCanvas( canvas, sigPad );
            }, 200 ) );
        } );
    }

    function resizeCanvas( canvas, sigPad ) {
        var ratio  = Math.max( window.devicePixelRatio || 1, 1 );
        var data   = sigPad.toData();
        canvas.width  = canvas.offsetWidth  * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext( '2d' ).scale( ratio, ratio );
        sigPad.clear();
        sigPad.fromData( data );
    }

    // -------------------------------------------------------------------------
    // Client-side validation
    // -------------------------------------------------------------------------
    function validateForm( form ) {
        var valid = true;

        // Clear previous errors
        form.querySelectorAll( '.wpwe-field-error' ).forEach( function ( el ) {
            el.textContent = '';
        } );
        form.querySelectorAll( '.wpwe-field--invalid, .wpwe-cell--invalid' ).forEach( function ( el ) {
            el.classList.remove( 'wpwe-field--invalid', 'wpwe-cell--invalid' );
        } );

        // Standard HTML5 required inputs
        form.querySelectorAll( '[required]' ).forEach( function ( input ) {
            if ( input.disabled ) return;
            if ( ! input.value.trim() ) {
                markFieldError( input, wpweForm.i18n.required || 'This field is required.' );
                valid = false;
            }
        } );

        // Signature fields (data-required="1", value stored in hidden input)
        form.querySelectorAll( '.wpwe-signature-data[data-required]' ).forEach( function ( input ) {
            if ( input.disabled ) return;
            if ( ! input.value ) {
                var wrap = input.closest( '.wpwe-field' ) || input.closest( '.wpwe-cell' );
                if ( wrap ) {
                    wrap.classList.add( wrap.classList.contains( 'wpwe-cell' ) ? 'wpwe-cell--invalid' : 'wpwe-field--invalid' );
                    var errEl = wrap.querySelector( '.wpwe-field-error' );
                    if ( errEl ) errEl.textContent = wpweForm.i18n.required || 'Signature is required.';
                }
                valid = false;
            }
        } );

        // Validate the optional copy-email field if checkbox is ticked
        var copyCheck = form.querySelector( '.wpwe-email-copy-check' );
        var copyInput = form.querySelector( '.wpwe-copy-email-input' );
        var copyErrEl = form.querySelector( '.wpwe-copy-email-error' );
        if ( copyCheck && copyCheck.checked && copyInput ) {
            var emailVal = copyInput.value.trim();
            var emailOk  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( emailVal );
            if ( ! emailOk ) {
                if ( copyErrEl ) copyErrEl.textContent = 'Please enter a valid email address.';
                copyInput.closest( '.wpwe-email-copy-input' ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
                valid = false;
            } else {
                if ( copyErrEl ) copyErrEl.textContent = '';
            }
        }

        return valid;
    }

    function markFieldError( input, msg ) {
        var wrap = input.closest( '.wpwe-field' ) || input.closest( '.wpwe-cell' );
        if ( ! wrap ) return;
        wrap.classList.add( wrap.classList.contains( 'wpwe-cell' ) ? 'wpwe-cell--invalid' : 'wpwe-field--invalid' );
        var errEl = wrap.querySelector( '.wpwe-field-error' );
        if ( errEl ) errEl.textContent = msg;
    }

    // -------------------------------------------------------------------------
    // AJAX submission
    // -------------------------------------------------------------------------
    function onSubmit( e ) {
        e.preventDefault();
        var form = e.currentTarget;

        if ( ! validateForm( form ) ) {
            var firstErr = form.querySelector( '.wpwe-field--invalid, .wpwe-cell--invalid' );
            if ( firstErr ) firstErr.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            return;
        }

        var tokenInput = form.querySelector( '.wpwe-captcha-token' );
        var provider   = ( wpweForm.captchaProvider ) || 'none';

        if ( ! tokenInput || provider === 'none' ) {
            submitForm( form );
            return;
        }

        // Obtain CAPTCHA token before submitting
        var siteKey = wpweForm.captchaSiteKey || '';

        if ( provider === 'recaptcha_v3' ) {
            if ( typeof grecaptcha === 'undefined' ) {
                submitForm( form ); // SDK not loaded – fail open
                return;
            }
            grecaptcha.ready( function () {
                grecaptcha.execute( siteKey, { action: 'waiver' } )
                    .then( function ( token ) {
                        tokenInput.value = token;
                        submitForm( form );
                    } )
                    .catch( function () {
                        submitForm( form ); // fail open on execute error
                    } );
            } );
        } else if ( provider === 'hcaptcha' ) {
            if ( typeof hcaptcha === 'undefined' ) {
                submitForm( form ); // SDK not loaded – fail open
                return;
            }
            hcaptcha.execute( { sitekey: siteKey } )
                .then( function ( result ) {
                    tokenInput.value = result.response || result;
                    submitForm( form );
                } )
                .catch( function () {
                    submitForm( form ); // fail open on execute error
                } );
        } else {
            submitForm( form );
        }
    }

    function submitForm( form ) {
        var submitBtn = form.querySelector( '.wpwe-submit-btn' );
        var spinner   = form.querySelector( '.wpwe-spinner' );
        var messages  = form.querySelector( '.wpwe-messages' );

        submitBtn.disabled = true;
        submitBtn.textContent = wpweForm.i18n.submitting;
        if ( spinner ) spinner.style.display = '';
        if ( messages ) {
            messages.textContent = '';
            messages.className   = 'wpwe-messages';
        }

        var formData = new FormData( form );
        formData.append( 'action', 'wpwe_submit_waiver' );
        formData.append( 'nonce',  wpweForm.nonce );
        formData.append( 'template_id', form.dataset.templateId || '' );

        fetch( wpweForm.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body:        formData,
        } )
        .then( function ( res ) { return res.json(); } )
        .then( function ( response ) {
            if ( response.success ) {
                if ( messages ) {
                    messages.textContent = response.data.message || wpweForm.i18n.success;
                    messages.classList.add( 'wpwe-messages--success' );
                }
                form.reset();
                // Clear signature pads
                form.querySelectorAll( '.wpwe-signature-canvas' ).forEach( function ( canvas ) {
                    if ( canvas._sigPad ) canvas._sigPad.clear();
                } );
                form.querySelectorAll( '.wpwe-signature-data' ).forEach( function ( inp ) {
                    inp.value = '';
                } );
                // Scroll to message
                if ( messages ) messages.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            } else {
                var errMsg = ( response.data && response.data.message ) || wpweForm.i18n.error;
                if ( messages ) {
                    messages.textContent = errMsg;
                    messages.classList.add( 'wpwe-messages--error' );
                }
                // Mark server-returned field errors
                if ( response.data && response.data.errors ) {
                    Object.keys( response.data.errors ).forEach( function ( fieldKey ) {
                        // fieldKey format: "group[idx][field]"
                        var input = form.querySelector( '[name="wpwe_data[' + fieldKey.replace( /[\[\]]/g, function ( m ) { return '\\' + m; } ) + ']"]' );
                        if ( input ) markFieldError( input, response.data.errors[ fieldKey ] );
                    } );
                }
            }
        } )
        .catch( function () {
            if ( messages ) {
                messages.textContent = wpweForm.i18n.error;
                messages.classList.add( 'wpwe-messages--error' );
            }
        } )
        .finally( function () {
            submitBtn.disabled    = false;
            submitBtn.textContent = 'Submit Waiver';
            if ( spinner ) spinner.style.display = 'none';
        } );
    }

    // -------------------------------------------------------------------------
    // Preview – PDF via AJAX
    // -------------------------------------------------------------------------
    function initPreview( form ) {
        var previewBtn = form.querySelector( '.wpwe-preview-btn' );
        if ( ! previewBtn ) return;
        previewBtn.addEventListener( 'click', function () {
            requestPdfPreview( form );
        } );
    }

    /**
     * Request a PDF preview from the server and show it in a modal.
     *
     * @param {HTMLFormElement} form
     * @param {string}          [groupKey]  – if set, generates a per-row preview
     * @param {number}          [rowIndex]  – row index within groupKey
     */
    function requestPdfPreview( form, groupKey, rowIndex ) {
        var isRowPreview = ( groupKey !== undefined );

        // For full preview, validate first
        if ( ! isRowPreview ) {
            if ( ! validateForm( form ) ) {
                var firstErr = form.querySelector( '.wpwe-field--invalid, .wpwe-cell--invalid' );
                if ( firstErr ) firstErr.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                return;
            }
        }

        var modal = buildPdfPreviewModal( form, isRowPreview );
        document.body.appendChild( modal );
        document.body.classList.add( 'wpwe-preview-open' );
        modal.querySelector( '.wpwe-preview-close' ).focus();

        var formData = new FormData( form );
        formData.append( 'action',      'wpwe_preview_pdf' );
        formData.append( 'nonce',       wpweForm.previewNonce );
        formData.append( 'template_id', form.dataset.templateId || '' );

        if ( isRowPreview ) {
            formData.append( 'preview_mode', 'row' );
            formData.append( 'group_key',    groupKey );
            formData.append( 'row_index',    String( rowIndex ) );
        }

        fetch( wpweForm.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData } )
            .then( function ( res ) { return res.json(); } )
            .then( function ( resp ) {
                if ( resp.success && resp.data && resp.data.pdf ) {
                    showPdfInModal( modal, resp.data.pdf );
                } else {
                    showPdfModalError( modal, ( resp.data && resp.data.message ) || wpweForm.i18n.error );
                }
            } )
            .catch( function () {
                showPdfModalError( modal, wpweForm.i18n.error );
            } );
    }

    function buildPdfPreviewModal( form, isRowPreview ) {
        var overlay = document.createElement( 'div' );
        overlay.className = 'wpwe-preview-overlay';
        overlay.setAttribute( 'role', 'dialog' );
        overlay.setAttribute( 'aria-modal', 'true' );
        overlay.setAttribute( 'aria-label', wpweForm.i18n.previewHeading );

        var footerBtns =
            '<button type="button" class="wpwe-preview-edit-btn">' + escHtml( wpweForm.i18n.editForm ) + '</button>';
        if ( ! isRowPreview ) {
            footerBtns +=
                '<button type="button" class="wpwe-preview-confirm-btn">' + escHtml( wpweForm.i18n.confirmSubmit ) + '</button>';
        }

        overlay.innerHTML =
            '<div class="wpwe-preview-modal wpwe-pdf-preview-modal">' +
                '<div class="wpwe-preview-header">' +
                    '<h2 class="wpwe-preview-title">' + escHtml( wpweForm.i18n.previewHeading ) + '</h2>' +
                    '<button type="button" class="wpwe-preview-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="wpwe-preview-body wpwe-pdf-preview-body">' +
                    '<div class="wpwe-pdf-loading">' +
                        escHtml( wpweForm.i18n.pdfLoading || 'Generating PDF preview\u2026' ) +
                    '</div>' +
                    '<iframe class="wpwe-pdf-iframe" title="PDF Preview" style="display:none;"></iframe>' +
                '</div>' +
                '<div class="wpwe-preview-footer">' +
                    footerBtns +
                '</div>' +
            '</div>';

        // Close handlers
        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) closePdfPreviewModal( overlay );
        } );
        overlay.querySelector( '.wpwe-preview-close' ).addEventListener( 'click', function () {
            closePdfPreviewModal( overlay );
        } );
        overlay.querySelector( '.wpwe-preview-edit-btn' ).addEventListener( 'click', function () {
            closePdfPreviewModal( overlay );
        } );
        var confirmBtn = overlay.querySelector( '.wpwe-preview-confirm-btn' );
        if ( confirmBtn ) {
            confirmBtn.addEventListener( 'click', function () {
                closePdfPreviewModal( overlay );
                submitForm( form );
            } );
        }
        // Escape key
        function escKeyHandler( e ) {
            if ( e.key === 'Escape' ) {
                closePdfPreviewModal( overlay );
                document.removeEventListener( 'keydown', escKeyHandler );
            }
        }
        document.addEventListener( 'keydown', escKeyHandler );

        return overlay;
    }

    function showPdfInModal( modal, base64 ) {
        var loading = modal.querySelector( '.wpwe-pdf-loading' );
        var iframe  = modal.querySelector( '.wpwe-pdf-iframe' );
        if ( ! iframe ) return;

        try {
            var binary = window.atob( base64 );
            var bytes  = new Uint8Array( binary.length );
            for ( var i = 0; i < binary.length; i++ ) {
                bytes[ i ] = binary.charCodeAt( i );
            }
            var blob    = new Blob( [ bytes ], { type: 'application/pdf' } );
            var blobUrl = URL.createObjectURL( blob );
            iframe.src  = blobUrl;
            iframe.addEventListener( 'load', function () {
                if ( loading ) loading.style.display = 'none';
                iframe.style.display = '';
            }, { once: true } );
            // Fallback: reveal after 3 s even if load event doesn't fire (some browsers)
            setTimeout( function () {
                if ( loading && loading.style.display !== 'none' ) {
                    loading.style.display = 'none';
                    iframe.style.display  = '';
                }
            }, 3000 );
        } catch ( err ) {
            showPdfModalError( modal, 'Failed to render PDF.' );
        }
    }

    function showPdfModalError( modal, msg ) {
        var loading = modal.querySelector( '.wpwe-pdf-loading' );
        if ( loading ) {
            loading.textContent  = msg;
            loading.style.color  = '#d63638';
        }
    }

    function closePdfPreviewModal( modal ) {
        if ( modal && modal.parentNode ) {
            var iframe = modal.querySelector( '.wpwe-pdf-iframe' );
            if ( iframe && iframe.src && iframe.src.startsWith( 'blob:' ) ) {
                URL.revokeObjectURL( iframe.src );
            }
            modal.parentNode.removeChild( modal );
        }
        document.body.classList.remove( 'wpwe-preview-open' );
    }

    function escHtml( str ) {
        var d = document.createElement( 'div' );
        d.appendChild( document.createTextNode( String( str || '' ) ) );
        return d.innerHTML;
    }

    // -------------------------------------------------------------------------
    // Utils
    // -------------------------------------------------------------------------
    function debounce( fn, delay ) {
        var timer;
        return function () {
            clearTimeout( timer );
            timer = setTimeout( fn, delay );
        };
    }

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------
    document.addEventListener( 'DOMContentLoaded', function () {
        document.querySelectorAll( '.wpwe-form' ).forEach( initForm );
    } );

} )();
