/**
 * "Refresh preview" for the workflow edit form's plain-language panel. Re-runs
 * the F2 validation pipeline over the UNSAVED definition/conditions in the form
 * and repaints the summary + findings, without saving. No live-keystroke JS.
 *
 * MUST stay a RequireJS module booted via data-mage-init, never an inline
 * <script> in the template. This panel is embedded in the form through a
 * ui_component <htmlContent> node, which means the block's rendered HTML is
 * serialized as a JSON string inside the form's
 * <script type="text/x-magento-init"> element. An inline </script> anywhere in
 * that HTML terminates the OUTER script tag at parse time: the remainder of the
 * component config spills into the document as text, Magento_Ui/js/core/app
 * never receives a valid config, and the entire form silently fails to
 * initialize — full-page loading mask, no fields, and typically no console
 * error at all, because the truncated remnant is inert text rather than broken
 * JS. Peer convention: conditions-slideout.js.
 *
 * CSP-safe: no inline script, no eval, all config from data-* attributes;
 * server strings are set via text()/createTextNode, never innerHTML.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    /**
     * @param {HTMLElement} root the panel container carrying data-* config
     */
    function init(root) {
        var $root = $(root),
            refreshUrl = $root.data('refreshUrl'),
            formKey = $root.data('formKey'),
            labels = {
                error: $t('Error'),
                warning: $t('Warning'),
                step: $t('step'),
                failed: $t('The preview could not be generated for this definition.')
            };

        /**
         * Reads a form field by name. The fields belong to the uiComponent form,
         * so they are only queried at click time, never cached.
         *
         * @param {String} name
         * @return {String}
         */
        function fieldValue(name) {
            var $el = $('[name="' + name + '"]');

            return $el.length ? ($el.val() || '') : '';
        }

        /**
         * @param {Array} messages
         */
        function renderMessages(messages) {
            var $container = $root.find('.mageos-workflows-preview-messages').empty();

            (messages || []).forEach(function (msg) {
                var isError = msg.severity === 'error',
                    cls = isError ? 'message-error error' : 'message-warning warning',
                    label = isError ? labels.error : labels.warning,
                    anchor = msg.step_key ? ' — ' + labels.step + ' "' + msg.step_key + '"' : '',
                    $msg = $('<div></div>', {'class': 'message ' + cls}),
                    $span = $('<span></span>');

                $span.append($('<strong></strong>').text(label + anchor + ':'));
                $span.append(document.createTextNode(' ' + (msg.message || '')));
                $msg.append($span);
                $container.append($msg);
            });
        }

        /**
         * @param {String} text
         */
        function setPreviewText(text) {
            $root.find('.mageos-workflows-preview-text').text(text);
        }

        $root.on('click', '.mageos-workflows-preview-refresh', function () {
            $.ajax({
                url: refreshUrl,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                data: {
                    'form_key': formKey,
                    definition: fieldValue('definition'),
                    'conditions_serialized': fieldValue('conditions_serialized'),
                    'trigger_type': fieldValue('trigger_type'),
                    'trigger_ref': fieldValue('trigger_ref'),
                    'entity_type': fieldValue('entity_type')
                }
            }).done(function (response) {
                if (!response || !response.success) {
                    setPreviewText((response && response.error) || labels.failed);
                    renderMessages([]);

                    return;
                }
                setPreviewText(response.plain_language || '');
                renderMessages(response.messages);
            }).fail(function () {
                setPreviewText(labels.failed);
                renderMessages([]);
            });
        });
    }

    return function (config, element) {
        init(element);
    };
});
