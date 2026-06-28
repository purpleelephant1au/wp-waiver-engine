/* global pewaveSettings */
( function () {
    'use strict';

    function initCaptchaProviderToggle() {
        var sel  = document.getElementById( 'pewave_captcha_provider' );
        var wrap = document.getElementById( 'pewave-captcha-keys' );
        if ( ! sel || ! wrap ) {
            return;
        }

        sel.addEventListener( 'change', function () {
            wrap.style.display = this.value === 'none' ? 'none' : '';
        } );
    }

    function initCleanupTrigger() {
        var btn = document.getElementById( 'pewave-cleanup-trigger' );
        if ( ! btn || typeof pewaveSettings === 'undefined' ) {
            return;
        }

        btn.addEventListener( 'click', function () {
            var daysInput = document.getElementById( 'pewave_pdf_retention_days' );
            var days  = daysInput ? parseInt( daysInput.value, 10 ) : parseInt( btn.dataset.days, 10 );
            var count = parseInt( btn.dataset.count, 10 );
            var msg   = count > 0 ? pewaveSettings.cleanupConfirm : pewaveSettings.cleanupEmpty;
            msg = msg.replace( '{count}', count ).replace( '{days}', days );

            if ( window.confirm( msg ) ) {
                document.getElementById( 'pewave-cleanup-form' ).submit();
            }
        } );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        initCaptchaProviderToggle();
        initCleanupTrigger();
    } );
}() );
