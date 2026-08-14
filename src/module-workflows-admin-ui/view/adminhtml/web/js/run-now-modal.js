/**
 * "Run Now" entity picker for the workflow edit form — the replacement for the
 * toolbar button's window.prompt. The button's on_click stays dumb: it triggers
 * one custom event on this container ('mageos:open-run-now') and this module
 * owns everything else.
 *
 * Running a workflow is a STATE CHANGE — real refunds, emails and webhooks fire
 * against the chosen entity — so it is submitted as a POST, never navigated to.
 * The id used to be appended to the run URL as a path segment and the browser
 * sent there; that made the whole dispatch a GET whose only protection was the
 * adminhtml secret key, which merchants often disable.
 *
 * mage/utils/misc's submit() is the canonical admin helper for this: it builds a
 * detached form, stamps window.FORM_KEY into it (the CSRF token
 * Magento\Backend\App\AbstractAction::_processUrlKeys() validates on every admin
 * POST), appends our fields and submits. The run URL therefore stays exactly as
 * the block renders it — no id in the path — and entity_id travels in the body.
 * The controller re-checks the ACL and re-validates the id, so the client-side
 * numeric check is only there to keep an obvious typo from costing a round trip.
 *
 * User-facing strings arrive already translated in data-* attributes (the phtml
 * wraps them in __()), so this module needs no mage/translate dependency — one
 * translation pass, server-side, for both the modal chrome and the markup.
 *
 * CSP-safe: no inline script (loaded as a module), no eval, all config from
 * data-* attributes on the container element; messages written with .text().
 */
define([
    'jquery',
    'mage/utils/misc',
    'Magento_Ui/js/modal/modal'
], function ($, miscUtils, modal) {
    'use strict';

    /** Digits only — the same shape the Run controller accepts (ctype_digit). */
    var NUMERIC = /^\d+$/;

    /**
     * @param {HTMLElement} root the container carrying data-* config
     */
    function init(root) {
        var $root = $(root),
            runUrl = $root.data('runUrl'),
            $input = $root.find('[data-role="run-now-entity-id"]'),
            $recent = $root.find('[data-role="run-now-recent"]'),
            $error = $root.find('[data-role="run-now-error"]');

        if (!runUrl || !$input.length) {
            // Nothing to run against: leave the container hidden.
            return;
        }

        /**
         * @param {String} message
         */
        function fail(message) {
            $error.text(message);
        }

        function run() {
            var entityId = $.trim($input.val() || '');

            if (entityId === '' || !NUMERIC.test(entityId)) {
                fail($root.data('labelInvalid') || '');

                return;
            }

            // POST, not navigation: this dispatches real side effects. submit()
            // adds form_key itself, so the admin CSRF check passes without the
            // template having to render the token.
            miscUtils.submit({
                url: runUrl,
                data: {
                    'entity_id': entityId
                }
            });
        }

        modal({
            type: 'popup',
            modalClass: 'mageos-run-now-modal-wrap',
            title: $root.data('title') || '',
            buttons: [
                {
                    text: $root.data('labelCancel') || '',
                    click: function () {
                        this.closeModal();
                    }
                },
                {
                    text: $root.data('labelRun') || '',
                    'class': 'action-primary',
                    click: run
                }
            ]
        }, $root);

        $recent.on('change', function () {
            if ($recent.val()) {
                $input.val($recent.val());
                fail('');
            }
        });

        $input.on('keydown', function (event) {
            if (event.keyCode === 13) {
                event.preventDefault();
                run();
            }
        });

        // The toolbar button raises this; it knows nothing else about the modal.
        $root.on('mageos:open-run-now', function () {
            fail('');
            $root.modal('openModal');
            $input.focus();
        });
    }

    return function (config, element) {
        init(element);
    };
});
