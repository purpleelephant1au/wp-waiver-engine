/**
 * WP Waiver Engine – Admin Editor JS
 *
 * Features:
 *  1. Media library PDF picker (settings box)
 *  2. Output-mode toggle
 *  3. PDF Visual Mapper (full-screen modal)
 *       - Renders PDF pages onto a canvas
 *       - Admin drag-draws a rectangle → auto-adds a mapping table row
 *       - Table row changes reflected back as coordinate overlays
 *       - Serialise table → hidden JSON input before form save
 */
/* global wpweAdmin, pdfjsLib, wp */

( function ( $ ) {
    'use strict';

    // =========================================================================
    // 1. PDF Media Picker
    // =========================================================================
    var mediaFrame;

    $( '#wpwe_select_pdf' ).on( 'click', function ( e ) {
        e.preventDefault();
        if ( mediaFrame ) { mediaFrame.open(); return; }
        mediaFrame = wp.media( {
            title:   wpweAdmin.mediaTitle,
            button:  { text: wpweAdmin.mediaButton },
            library: { type: 'application/pdf' },
            multiple: false,
        } );
        mediaFrame.on( 'select', function () {
            var att = mediaFrame.state().get( 'selection' ).first().toJSON();
            $( '#wpwe_pdf_attachment_id' ).val( att.id );
            $( '#wpwe_pdf_name' ).html( '<a href="' + att.url + '" target="_blank">' + att.filename + '</a>' );
            loadPdfIntoMapper( att.url );
        } );
        mediaFrame.open();
    } );

    // =========================================================================
    // 2. Output-mode toggle
    // =========================================================================
    $( '#wpwe_output_mode' ).on( 'change', function () {
        $( '#wpwe_group_key_wrap' ).toggle( $( this ).val() === 'per_row' );
    } );

    // =========================================================================
    // 3. Full-screen Mapper Modal
    // =========================================================================

    var modalEl = document.getElementById( 'wpwe-mapper-modal' );

    function openMapper() {
        if ( ! modalEl ) return;
        modalEl.style.display = 'flex';
        document.body.style.overflow = 'hidden';  // prevent page scroll behind modal
        // Re-render the current page now that the modal pane is visible and sized
        if ( pdfDoc ) {
            renderPage( currentPage );
        } else if ( wpweAdmin.currentPdfUrl ) {
            loadPdfIntoMapper( wpweAdmin.currentPdfUrl );
        }
    }

    function closeMapper() {
        if ( ! modalEl ) return;
        modalEl.style.display = 'none';
        document.body.style.overflow = '';
        serializeTableToHidden();
        updateSummaryText();
    }

    $( '#wpwe-open-mapper' ).on( 'click', openMapper );
    $( '#wpwe-close-mapper, #wpwe-close-mapper-done' ).on( 'click', closeMapper );

    // Click backdrop (outside the modal content) also closes
    $( modalEl ).on( 'click', function ( e ) {
        if ( e.target === modalEl ) closeMapper();
    } );

    // Escape key closes
    $( document ).on( 'keydown.wpweMapper', function ( e ) {
        if ( e.key === 'Escape' && modalEl && modalEl.style.display !== 'none' ) closeMapper();
    } );

    // Update the compact summary text in the meta box
    function updateSummaryText() {
        var el = document.getElementById( 'wpwe-mapper-summary-text' );
        if ( ! el ) return;
        var count = ( document.getElementById( 'wpwe-mapping-tbody' ) || {} ).querySelectorAll( 'tr.wpwe-mapping-row' ).length || 0;
        var pdf   = ( document.getElementById( 'wpwe_builtin_pdf' ) || {} ).value || '';
        if ( pdf && count ) {
            el.textContent = pdf + ' — ' + count + ( count === 1 ? ' field' : ' fields' );
        } else if ( count ) {
            el.textContent = count + ( count === 1 ? ' field mapped' : ' fields mapped' );
        } else {
            el.textContent = 'No fields mapped yet';
        }
    }

    // =========================================================================
    // 4. PDF Visual Mapper
    // =========================================================================

    // --- State ---
    var pdfDoc       = null;
    var currentPage  = 1;
    var totalPages   = 1;
    var pdfUrl       = '';
    var pdfScale     = 1.0;      // canvas pixels per CSS pt
    var PT_PER_PX    = 1.0;      // pt-per-pixel ratio (set after first render)
    var isDrawing    = false;
    var dragStart    = { x: 0, y: 0 };
    var currentRect  = null;     // { x, y, w, h } in canvas pixels

    // Drag/resize state for existing overlays
    var dragMode     = 'draw';   // 'draw' | 'move' | 'resize'
    var dragRow      = null;     // DOM <tr> being operated on
    var dragOverlay  = null;     // .wpwe-field-overlay div being moved/resized
    var dragOffX     = 0;        // mouse offset within overlay on mousedown (move mode)
    var dragOffY     = 0;
    // Cached overlay hit-test data, rebuilt by renderOverlays()
    // Each entry: { div, row, cx, cy, cw, ch }
    var activeOverlays = [];

    var pdfCanvas    = document.getElementById( 'wpwe-pdf-canvas' );
    var drawCanvas   = document.getElementById( 'wpwe-draw-canvas' );
    var overlaysEl   = document.getElementById( 'wpwe-overlays' );
    var viewerEl     = document.getElementById( 'wpwe-pdf-viewer' );
    var hintEl       = document.getElementById( 'wpwe-mapper-hint' );
    var pageNumEl    = document.getElementById( 'wpwe-page-num' );
    var pageTotalEl  = document.getElementById( 'wpwe-page-total' );
    var tbody        = document.getElementById( 'wpwe-mapping-tbody' );
    var rowTpl       = document.getElementById( 'wpwe-mapping-row-tpl' );
    var hiddenInput  = document.getElementById( 'wpwe_pdf_mapping' );

    // Configure PDF.js worker
    if ( typeof pdfjsLib !== 'undefined' ) {
        pdfjsLib.GlobalWorkerOptions.workerSrc = wpweAdmin.workerSrc;
    }

    // --- Load PDF on startup if one is already selected ---
    if ( wpweAdmin.currentPdfUrl ) {
        loadPdfIntoMapper( wpweAdmin.currentPdfUrl );
    }

    // --- Built-in PDF selector ---
    $( '#wpwe_builtin_pdf' ).on( 'change', function () {
        var val = $( this ).val();
        if ( ! val ) return;
        var match = ( wpweAdmin.builtinForms || [] ).find( function ( f ) { return f.value === val; } );
        if ( match ) loadPdfIntoMapper( match.url );
    } );

    // --- Page navigation ---
    $( '#wpwe-prev-page' ).on( 'click', function () { if ( currentPage > 1 ) { currentPage--; renderPage( currentPage ); } } );
    $( '#wpwe-next-page' ).on( 'click', function () { if ( currentPage < totalPages ) { currentPage++; renderPage( currentPage ); } } );

    // -------------------------------------------------------------------------
    // loadPdfIntoMapper
    // -------------------------------------------------------------------------
    function loadPdfIntoMapper( url ) {
        if ( ! url || typeof pdfjsLib === 'undefined' ) return;
        pdfUrl = url;
        pdfjsLib.getDocument( { url: url, withCredentials: true } ).promise.then( function ( doc ) {
            pdfDoc      = doc;
            totalPages  = doc.numPages;
            currentPage = 1;
            $( viewerEl ).show();
            $( hintEl ).hide();
            if ( pageTotalEl ) pageTotalEl.textContent = totalPages;
            // Auto-sync the page-count input to the actual PDF page count
            var pcInput = document.getElementById( 'wpwe_map_page_count' );
            if ( pcInput ) pcInput.value = totalPages;
            renderPage( currentPage );
        } ).catch( function ( err ) {
            console.warn( 'WPWE PDF.js load error:', err );
        } );
    }

    // -------------------------------------------------------------------------
    // renderPage
    // -------------------------------------------------------------------------
    function renderPage( pageNum ) {
        if ( ! pdfDoc ) return;
        pdfDoc.getPage( pageNum ).then( function ( page ) {
            var viewport = page.getViewport( { scale: 1 } );

            // Scale to fit the modal PDF pane (90% of its width, capped at 1200px)
            var paneEl  = document.querySelector( '.wpwe-modal-pdf-pane' );
            var maxWidth = paneEl ? Math.floor( paneEl.clientWidth * 0.98 ) : 860;
            if ( maxWidth < 200 ) maxWidth = 860; // fallback before modal is visible
            pdfScale     = maxWidth / viewport.width;
            var scaledVP = page.getViewport( { scale: pdfScale } );

            // PT_PER_PX: how many PDF points each CSS pixel represents
            PT_PER_PX = viewport.width / scaledVP.width;

            pdfCanvas.width  = scaledVP.width;
            pdfCanvas.height = scaledVP.height;
            drawCanvas.width  = scaledVP.width;
            drawCanvas.height = scaledVP.height;
            $( '#wpwe-canvas-wrap' ).css( { width: scaledVP.width, height: scaledVP.height } );

            page.render( { canvasContext: pdfCanvas.getContext( '2d' ), viewport: scaledVP } ).promise.then( function () {
                if ( pageNumEl ) pageNumEl.textContent = pageNum;
                renderOverlays();
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Hit-test: returns { mode:'move'|'resize', row, div, cx, cy, cw, ch }
    // or null if no overlay is under (px, py).
    // -------------------------------------------------------------------------
    function findHitOverlay( px, py ) {
        // Iterate in reverse so topmost (last-rendered) wins
        for ( var i = activeOverlays.length - 1; i >= 0; i-- ) {
            var o  = activeOverlays[ i ];
            var cx = o.cx, cy = o.cy, cw = o.cw, ch = o.ch;
            if ( px >= cx && px <= cx + cw && py >= cy && py <= cy + ch ) {
                // Near bottom-right corner → resize
                var HANDLE = 10;
                if ( px >= cx + cw - HANDLE && py >= cy + ch - HANDLE ) {
                    return { mode: 'resize', row: o.row, div: o.div, cx: cx, cy: cy, cw: cw, ch: ch };
                }
                return { mode: 'move', row: o.row, div: o.div, cx: cx, cy: cy, cw: cw, ch: ch };
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Drag-to-draw on the overlay canvas (also handles move/resize of existing)
    // -------------------------------------------------------------------------
    if ( drawCanvas ) {
        drawCanvas.addEventListener( 'mousedown', function ( e ) {
            var pos = canvasPos( e );
            var hit = findHitOverlay( pos.x, pos.y );

            if ( hit ) {
                // Interact with existing overlay
                dragMode    = hit.mode;
                dragRow     = hit.row;
                dragOverlay = hit.div;
                dragOverlay.classList.add( 'wpwe-overlay-active' );
                if ( hit.mode === 'move' ) {
                    dragOffX = pos.x - hit.cx;
                    dragOffY = pos.y - hit.cy;
                }
                isDrawing = false;
            } else {
                // draw new field
                dragMode   = 'draw';
                dragRow    = null;
                dragOverlay= null;
                dragStart  = pos;
                isDrawing  = true;
                currentRect= null;
            }
        } );

        drawCanvas.addEventListener( 'mousemove', function ( e ) {
            var pos = canvasPos( e );

            if ( dragMode === 'move' && dragOverlay ) {
                var newCx = pos.x - dragOffX;
                var newCy = pos.y - dragOffY;
                dragOverlay.style.left = newCx + 'px';
                dragOverlay.style.top  = newCy + 'px';
            } else if ( dragMode === 'resize' && dragOverlay ) {
                var ox  = parseFloat( dragOverlay.style.left ) || 0;
                var oy  = parseFloat( dragOverlay.style.top  ) || 0;
                var nCw = Math.max( 10, pos.x - ox );
                var nCh = Math.max( 8,  pos.y - oy );
                dragOverlay.style.width  = nCw + 'px';
                dragOverlay.style.height = nCh + 'px';
            } else if ( dragMode === 'draw' && isDrawing ) {
                currentRect = {
                    x: Math.min( pos.x, dragStart.x ),
                    y: Math.min( pos.y, dragStart.y ),
                    w: Math.abs( pos.x - dragStart.x ),
                    h: Math.abs( pos.y - dragStart.y ),
                };
                var ctx = drawCanvas.getContext( '2d' );
                ctx.clearRect( 0, 0, drawCanvas.width, drawCanvas.height );
                ctx.strokeStyle = '#2271b1';
                ctx.lineWidth   = 2;
                ctx.fillStyle   = 'rgba(34,113,177,0.12)';
                ctx.fillRect( currentRect.x, currentRect.y, currentRect.w, currentRect.h );
                ctx.strokeRect( currentRect.x, currentRect.y, currentRect.w, currentRect.h );
            } else {
                // Update cursor hint based on hover over overlay
                var hint = findHitOverlay( pos.x, pos.y );
                if ( hint ) {
                    drawCanvas.style.cursor = hint.mode === 'resize' ? 'se-resize' : 'move';
                } else {
                    drawCanvas.style.cursor = 'crosshair';
                }
            }
        } );

        drawCanvas.addEventListener( 'mouseup', function () {
            if ( dragMode === 'move' || dragMode === 'resize' ) {
                // Write updated position back to the linked table row
                if ( dragOverlay && dragRow ) {
                    var ptX = roundPt( parseFloat( dragOverlay.style.left   ) * PT_PER_PX );
                    var ptY = roundPt( parseFloat( dragOverlay.style.top    ) * PT_PER_PX );
                    var ptW = roundPt( parseFloat( dragOverlay.style.width  ) * PT_PER_PX );
                    var ptH = roundPt( parseFloat( dragOverlay.style.height ) * PT_PER_PX );
                    setVal( dragRow, '.wpwe-map-x', ptX );
                    setVal( dragRow, '.wpwe-map-y', ptY );
                    setVal( dragRow, '.wpwe-map-w', ptW );
                    setVal( dragRow, '.wpwe-map-h', ptH );
                    dragOverlay.classList.remove( 'wpwe-overlay-active' );
                }
                dragMode    = 'draw';
                dragRow     = null;
                dragOverlay = null;
                renderOverlays();
                serializeTableToHidden();

            } else if ( isDrawing ) {
                isDrawing = false;
                drawCanvas.getContext( '2d' ).clearRect( 0, 0, drawCanvas.width, drawCanvas.height );

                if ( currentRect && currentRect.w > 4 && currentRect.h > 4 ) {
                    var ptX2 = roundPt( currentRect.x * PT_PER_PX );
                    var ptY2 = roundPt( currentRect.y * PT_PER_PX );
                    var ptW2 = roundPt( currentRect.w * PT_PER_PX );
                    var ptH2 = roundPt( currentRect.h * PT_PER_PX );
                    addMappingRow( '', '', '', 'text', false, false, currentPage, ptX2, ptY2, ptW2, ptH2, 11 );
                    serializeTableToHidden();
                }
                currentRect = null;
                dragMode = 'draw';
            }
        } );

        // Cancel on mouseout
        drawCanvas.addEventListener( 'mouseleave', function () {
            if ( isDrawing ) {
                isDrawing = false;
                drawCanvas.getContext( '2d' ).clearRect( 0, 0, drawCanvas.width, drawCanvas.height );
            }
            // If a move/resize was in progress, commit it
            if ( dragMode === 'move' || dragMode === 'resize' ) {
                if ( dragOverlay && dragRow ) {
                    var ptX = roundPt( parseFloat( dragOverlay.style.left   ) * PT_PER_PX );
                    var ptY = roundPt( parseFloat( dragOverlay.style.top    ) * PT_PER_PX );
                    var ptW = roundPt( parseFloat( dragOverlay.style.width  ) * PT_PER_PX );
                    var ptH = roundPt( parseFloat( dragOverlay.style.height ) * PT_PER_PX );
                    setVal( dragRow, '.wpwe-map-x', ptX );
                    setVal( dragRow, '.wpwe-map-y', ptY );
                    setVal( dragRow, '.wpwe-map-w', ptW );
                    setVal( dragRow, '.wpwe-map-h', ptH );
                    dragOverlay.classList.remove( 'wpwe-overlay-active' );
                }
                dragMode    = 'draw';
                dragRow     = null;
                dragOverlay = null;
                renderOverlays();
                serializeTableToHidden();
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Add a row to the mapping table
    // -------------------------------------------------------------------------
    function addMappingRow( groupKey, fieldKey, label, type, required, repeatable, page, x, y, w, h, font, charSpacing, dateFormat, focusField ) {
        if ( ! rowTpl || ! tbody ) return;
        var clone = rowTpl.content.cloneNode( true );
        var row   = clone.querySelector( 'tr' );
        if ( ! row ) return;

        setVal( row, '.wpwe-map-group-key',    groupKey    || '' );
        setVal( row, '.wpwe-map-field-key',    fieldKey    || '' );
        setVal( row, '.wpwe-map-label',        label       || '' );
        setVal( row, '.wpwe-map-type',         type        || 'text' );
        setChk( row, '.wpwe-map-required',   '.wpwe-req-hidden', !! required );
        setChk( row, '.wpwe-map-repeatable', '.wpwe-rep-hidden', !! repeatable );
        setVal( row, '.wpwe-map-page',         page        || 1 );
        setVal( row, '.wpwe-map-x',            x           || '' );
        setVal( row, '.wpwe-map-y',            y           || '' );
        setVal( row, '.wpwe-map-w',            w           || '' );
        setVal( row, '.wpwe-map-h',            h           || '' );
        setVal( row, '.wpwe-map-font',         font        || '' );
        setVal( row, '.wpwe-map-char-spacing', charSpacing || '' );
        setVal( row, '.wpwe-map-date-format',  dateFormat  || '' );

        tbody.appendChild( clone );

        var newRow = tbody.lastElementChild;
        newRow.addEventListener( 'change', function () { renderOverlays(); serializeTableToHidden(); updateSummaryText(); } );
        newRow.addEventListener( 'input',  function () { renderOverlays(); serializeTableToHidden(); updateSummaryText(); } );

        if ( focusField !== false ) {
            var el = newRow.querySelector( '.wpwe-map-group-key' );
            if ( el ) el.focus();
        }

        renderOverlays();
        serializeTableToHidden();
    }

    // Manual add row button
    $( '#wpwe-add-mapping-row' ).on( 'click', function () {
        addMappingRow( '', '', '', 'text', false, false, currentPage, '', '', '', '', 11 );
    } );

    // Sync checkbox state to companion hidden input (unchecked checkboxes don't POST)
    $( '#wpwe-mapping-tbody' ).on( 'change', '.wpwe-map-required', function () {
        $( this ).siblings( '.wpwe-req-hidden' ).val( this.checked ? '1' : '0' );
    } );
    $( '#wpwe-mapping-tbody' ).on( 'change', '.wpwe-map-repeatable', function () {
        $( this ).siblings( '.wpwe-rep-hidden' ).val( this.checked ? '1' : '0' );
    } );

    // Delete row (delegated)
    $( '#wpwe-mapping-tbody' ).on( 'click', '.wpwe-delete-map-row', function () {
        $( this ).closest( 'tr' ).remove();
        renderOverlays();
        serializeTableToHidden();
        updateSummaryText();
    } );

    // Bind change on existing rows (loaded from saved data) and set initial badges
    $( '#wpwe-mapping-tbody tr' ).each( function () {
        var row = this;
        row.addEventListener( 'change', function () { renderOverlays(); serializeTableToHidden(); } );
        row.addEventListener( 'input',  function () { renderOverlays(); serializeTableToHidden(); } );
        updateLocationBadge( row );
    } );

    // -------------------------------------------------------------------------
    // Render overlays on the canvas wrap for current page
    // -------------------------------------------------------------------------
    function renderOverlays() {
        if ( ! overlaysEl ) return;
        overlaysEl.innerHTML = '';
        activeOverlays = [];

        // Always update location badges, even when no PDF is loaded
        eachRow( function ( row ) { updateLocationBadge( row ); } );

        if ( ! pdfCanvas.width ) return;

        eachRow( function ( row ) {
            var page  = intVal( row, '.wpwe-map-page' );
            if ( page !== currentPage ) return;

            var x     = floatVal( row, '.wpwe-map-x' );
            var y     = floatVal( row, '.wpwe-map-y' );
            var w     = floatVal( row, '.wpwe-map-w' );
            var h     = floatVal( row, '.wpwe-map-h' );
            var label     = strVal(  row, '.wpwe-map-label' ) || strVal( row, '.wpwe-map-field-key' ) || '?';
            var type      = strVal(  row, '.wpwe-map-type' ) || 'text';
            var font      = floatVal( row, '.wpwe-map-font' ) || 11;
            var charSpace = floatVal( row, '.wpwe-map-char-spacing' );
            var fmt       = strVal(  row, '.wpwe-map-date-format' ) || 'd/m/Y';
            if ( ! x && ! y ) return;

            // Convert pt → canvas px
            var scale = 1 / PT_PER_PX;
            var cx = x * scale;
            var cy = y * scale;
            var cw = ( w || 60 ) * scale;
            var ch = ( h || 16 ) * scale;

            var div = document.createElement( 'div' );
            div.className = 'wpwe-field-overlay';
            div.style.cssText = 'left:' + cx + 'px;top:' + cy + 'px;width:' + cw + 'px;height:' + ch + 'px;';
            div.title = label;

            // Label badge
            var labelSpan = document.createElement( 'span' );
            labelSpan.className = 'wpwe-overlay-label';
            labelSpan.textContent = label;
            div.appendChild( labelSpan );

            // Placeholder text, scaled to approximate the field's font size
            var sampleText = overlayPlaceholder( type, label, fmt );
            if ( sampleText ) {
                var pxFontSize    = font * scale;
                var pxLetterSpace = charSpace * scale;
                var pSpan = document.createElement( 'span' );
                pSpan.className = 'wpwe-overlay-placeholder';
                pSpan.style.fontSize    = pxFontSize + 'px';
                pSpan.style.letterSpacing = pxLetterSpace + 'px';
                if ( type === 'signature' ) pSpan.style.fontStyle = 'italic';
                pSpan.textContent = sampleText;
                div.appendChild( pSpan );
            }

            // Resize handle
            var handle = document.createElement( 'div' );
            handle.className = 'wpwe-overlay-resize-handle';
            div.appendChild( handle );

            overlaysEl.appendChild( div );
            activeOverlays.push( { div: div, row: row, cx: cx, cy: cy, cw: cw, ch: ch } );
        } );
    }

    /**
     * Return a human-readable placeholder based on field type.
     */
    function overlayPlaceholder( type, label, fmt ) {
        switch ( type ) {
            case 'date':
                // Show today formatted in the chosen PHP-like format, converted to JS
                return formatSampleDate( fmt );
            case 'signature':
                return '~ ' + ( label || 'Signature' ) + ' ~';
            case 'checkbox':
                return '\u2713';
            case 'number':
                return '42';
            case 'image':
                return '';
            default:
                return label || '';
        }
    }

    /**
     * Convert a PHP date() format string to a JS-formatted sample date (today).
     * Supports the most common tokens: d, j, m, n, Y, y, H, i, s, /, -, space.
     */
    function formatSampleDate( phpFmt ) {
        var now = new Date();
        var map = {
            d: pad( now.getDate() ),
            j: String( now.getDate() ),
            m: pad( now.getMonth() + 1 ),
            n: String( now.getMonth() + 1 ),
            Y: String( now.getFullYear() ),
            y: String( now.getFullYear() ).slice( -2 ),
            H: pad( now.getHours() ),
            i: pad( now.getMinutes() ),
            s: pad( now.getSeconds() ),
        };
        var result = '';
        for ( var ci = 0; ci < phpFmt.length; ci++ ) {
            var ch = phpFmt[ ci ];
            result += ( map[ ch ] !== undefined ) ? map[ ch ] : ch;
        }
        return result;
    }

    function pad( n ) { return n < 10 ? '0' + n : String( n ); }

    // -------------------------------------------------------------------------
    // Serialise table to hidden JSON input (also POSTed as fallback)
    // -------------------------------------------------------------------------
    function serializeTableToHidden() {
        if ( ! hiddenInput ) return;
        var fields     = {};
        var groupsMeta = {};
        var rowIndex   = 0;
        eachRow( function ( row ) {
            var gk    = strVal( row, '.wpwe-map-group-key' );
            var fk    = strVal( row, '.wpwe-map-field-key' );
            if ( ! gk || ! fk ) { rowIndex++; return; }
            var path  = gk + '.' + fk;
            // Allow multiple PDF locations for the same form field:
            // append __2, __3, … suffix when the path already exists.
            var finalPath = path;
            var dupCount  = 2;
            while ( fields.hasOwnProperty( finalPath ) ) {
                finalPath = path + '__' + dupCount;
                dupCount++;
            }
            var label = strVal( row, '.wpwe-map-label' );
            var type  = strVal( row, '.wpwe-map-type' )  || 'text';
            var req   = !! row.querySelector( '.wpwe-map-required:checked' );
            var rep   = !! row.querySelector( '.wpwe-map-repeatable:checked' );
            var page  = intVal( row, '.wpwe-map-page' )  || 1;
            var x     = floatVal( row, '.wpwe-map-x' );
            var y     = floatVal( row, '.wpwe-map-y' );
            var w     = floatVal( row, '.wpwe-map-w' );
            var h     = floatVal( row, '.wpwe-map-h' );
            var font      = floatVal( row, '.wpwe-map-font' );
            var charSpace = floatVal( row, '.wpwe-map-char-spacing' );
            var dateFmt   = strVal(   row, '.wpwe-map-date-format' );
            var entry = { page: page, x: x, y: y, type: type, label: label, required: req };
            if ( w > 0 ) entry.width  = w;
            if ( h > 0 ) entry.height = h;
            if ( type !== 'image' && type !== 'signature' && font > 0 ) entry.font_size = font;
            if ( charSpace > 0 ) entry.char_spacing = charSpace;
            if ( type === 'date' && dateFmt ) entry.date_format = dateFmt;
            fields[ finalPath ] = entry;

            if ( ! groupsMeta[ gk ] ) groupsMeta[ gk ] = { repeatable: rep };
            else if ( rep ) groupsMeta[ gk ].repeatable = true;

            rowIndex++;
        } );

        var builtinVal = ( document.getElementById( 'wpwe_builtin_pdf' ) || {} ).value || '';
        var pageCount  = parseInt( ( document.getElementById( 'wpwe_map_page_count' ) || {} ).value, 10 ) || totalPages || 1;

        var mapping = { page_count: pageCount, groups: groupsMeta, fields: fields };
        if ( builtinVal ) mapping.pdf_file = builtinVal;
        hiddenInput.value = JSON.stringify( mapping );
    }

    // Serialize before the template form submits (covers the Update/Save button)
    var templateForm = ( hiddenInput && hiddenInput.form ) ? hiddenInput.form : document.getElementById( 'post' );
    if ( templateForm ) {
        templateForm.addEventListener( 'submit', function () { serializeTableToHidden(); } );
    }
    // Also serialize on legacy WP publish/update button clicks
    $( '#publish, #save-post' ).on( 'click', function () { serializeTableToHidden(); } );

    // =========================================================================
    // Utilities
    // =========================================================================

    function canvasPos( e ) {
        var r = drawCanvas.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    function eachRow( fn ) {
        if ( ! tbody ) return;
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr.wpwe-mapping-row' ), fn );
    }

    function setVal( row, sel, val ) {
        var el = row.querySelector( sel );
        if ( ! el ) return;
        if ( el.tagName === 'SELECT' ) {
            // Try to select matching option
            for ( var i = 0; i < el.options.length; i++ ) {
                if ( el.options[ i ].value == val ) { el.selectedIndex = i; return; }
            }
        } else {
            el.value = ( val !== null && val !== undefined ) ? val : '';
        }
    }

    function strVal( row, sel ) {
        var el = row.querySelector( sel );
        return el ? ( el.value || '' ).trim() : '';
    }

    function intVal( row, sel ) {
        var el = row.querySelector( sel );
        return el ? ( parseInt( el.value, 10 ) || 0 ) : 0;
    }

    function floatVal( row, sel ) {
        var el = row.querySelector( sel );
        return el ? ( parseFloat( el.value ) || 0 ) : 0;
    }

    function roundPt( n ) { return Math.round( n * 10 ) / 10; }

    function escHtml( s ) {
        return s.replace( /&/g,'&amp;').replace( /</g,'&lt;' ).replace( />/g,'&gt;' );
    }

    /**
     * Update the read-only location badge cell for a row.
     * Shows e.g. "pg.1 · 120, 85 · 80×20"
     */
    function updateLocationBadge( row ) {
        var badge = row.querySelector( '.wpwe-map-location' );
        if ( ! badge ) return;
        var page = intVal( row, '.wpwe-map-page' ) || 1;
        var x    = floatVal( row, '.wpwe-map-x' );
        var y    = floatVal( row, '.wpwe-map-y' );
        var w    = floatVal( row, '.wpwe-map-w' );
        var h    = floatVal( row, '.wpwe-map-h' );
        if ( x || y ) {
            badge.textContent = 'pg.' + page + ' \u00b7 ' + x + ', ' + y + ' \u00b7 ' + ( w || '?' ) + '\u00d7' + ( h || '?' );
            badge.style.color = '';
        } else {
            badge.innerHTML = '<em>draw a rectangle above</em>';
        }
    }

    /**
     * Set a checkbox state in a freshly-cloned template row.
     */
    function setChk( row, checkSel, hiddenSel, checked ) {
        var cb  = row.querySelector( checkSel );
        if ( cb ) cb.checked = !! checked;
        var hid = row.querySelector( hiddenSel );
        if ( hid ) hid.value = checked ? '1' : '0';
    }

} )( jQuery );
