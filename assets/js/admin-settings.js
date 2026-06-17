/* global wpweSettings */
( function () {
    'use strict';

    function initCaptchaProviderToggle() {
        var sel  = document.getElementById( 'wpwe_captcha_provider' );
        var wrap = document.getElementById( 'wpwe-captcha-keys' );
        if ( ! sel || ! wrap ) {
            return;
        }

        sel.addEventListener( 'change', function () {
            wrap.style.display = this.value === 'none' ? 'none' : '';
        } );
    }

    function initCleanupTrigger() {
        var btn = document.getElementById( 'wpwe-cleanup-trigger' );
        if ( ! btn || typeof wpweSettings === 'undefined' ) {
            return;
        }

        btn.addEventListener( 'click', function () {
            var daysInput = document.getElementById( 'wpwe_pdf_retention_days' );
            var days  = daysInput ? parseInt( daysInput.value, 10 ) : parseInt( btn.dataset.days, 10 );
            var count = parseInt( btn.dataset.count, 10 );
            var msg   = count > 0 ? wpweSettings.cleanupConfirm : wpweSettings.cleanupEmpty;
            msg = msg.replace( '{count}', count ).replace( '{days}', days );

            if ( window.confirm( msg ) ) {
                document.getElementById( 'wpwe-cleanup-form' ).submit();
            }
        } );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        initCaptchaProviderToggle();
        initCleanupTrigger();
    } );
}() );
