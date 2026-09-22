/**
 * Shared condition slide-out (E1 host, JSON fallback) — admin-ui's first JS
 * asset. Opens a Magento modal (the standard admin slide-out) editing a
 * workflow's serialized condition tree, then writes the validated tree back
 * into the classic form's hidden `conditions_serialized` field.
 *
 * E1 spike verdict (see docs/11-admin-ui.md): the stock rule-widget rendering
 * layer (Magento\Rule\Block\Conditions + VarienRulesForm) is not a dependency
 * of this module and could not be evidenced in this repo/CI, so the shipped
 * editor is the JSON tree editor with an "edit as JSON" affordance retained by
 * design. The slide-out posts to the shared Conditions controller, which
 * shape-validates through the same F2 pipeline a save runs; when a full install
 * adds the rule widget it renders inside this same modal and posts to the same
 * endpoint — the contract does not move.
 *
 * CSP-safe: no inline script (loaded as a module), no eval, static config from
 * data-* attributes on the trigger element; server strings set via textContent.
 * The trigger context posted alongside the tree is read from the live form
 * fields instead (see contextValue) because it is editable in the same form.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'mage/translate'
], function ($, modal, $t) {
    'use strict';

    /**
     * Reads a form field by name, falling back to a static data-* attribute on
     * the trigger container for hosts that are not inside the workflow form.
     *
     * The trigger context (trigger_type / trigger_ref / entity_type) decides
     * which condition checks the server runs, and it is EDITABLE in the same
     * form as the conditions — so it is read from the live fields at apply
     * time, never cached at init: changing the trigger and then editing
     * conditions must validate against the new trigger. Peer convention:
     * definition-preview.js reads the unsaved definition the same way.
     *
     * @param {jQuery} $root the trigger container carrying data-* config
     * @param {String} name form field name
     * @param {String} dataKey jQuery data key of the static fallback
     * @return {String}
     */
    function contextValue($root, name, dataKey) {
        var $el = $('[name="' + name + '"]');

        if ($el.length) {
            return $el.val() || '';
        }

        return $root.data(dataKey) || '';
    }

    /**
     * @param {HTMLElement} root the trigger container carrying data-* config
     */
    function init(root) {
        var $root = $(root),
            endpoint = $root.data('applyUrl'),
            formKey = $root.data('formKey'),
            fieldSelector = $root.data('fieldSelector');

        var $field = $(fieldSelector);
        var $dialog = $('<div></div>', {'class': 'mageos-conditions-slideout'});
        var $textarea = $('<textarea></textarea>', {
            'class': 'mageos-conditions-json',
            'rows': 16,
            'spellcheck': 'false',
            'aria-label': $t('Condition tree (JSON)')
        });
        var $messages = $('<div></div>', {'class': 'mageos-conditions-messages', 'role': 'alert'});
        $dialog.append(
            $('<p></p>').text($t('Edit the serialized condition tree. Leave blank for "always run". The server re-validates the shape.')),
            $textarea,
            $messages
        );

        var dlg = modal({
            type: 'slide',
            title: $t('Conditions'),
            modalClass: 'mageos-conditions-modal',
            buttons: [
                {
                    text: $t('Cancel'),
                    click: function () {
                        this.closeModal();
                    }
                },
                {
                    text: $t('Apply'),
                    'class': 'action-primary',
                    click: function () {
                        apply(dlg, $textarea, $messages, $field, {
                            endpoint: endpoint,
                            formKey: formKey,
                            triggerType: contextValue($root, 'trigger_type', 'triggerType'),
                            triggerRef: contextValue($root, 'trigger_ref', 'triggerRef'),
                            entityType: contextValue($root, 'entity_type', 'entityType')
                        });
                    }
                }
            ]
        }, $dialog);

        $root.on('click', '[data-role="open-conditions"]', function (e) {
            e.preventDefault();
            $messages.empty();
            $textarea.val($field.val() || '');
            $dialog.modal('openModal');
        });
    }

    function apply(dlg, $textarea, $messages, $field, opts) {
        var value = $.trim($textarea.val());
        $messages.empty();
        $.ajax({
            url: opts.endpoint,
            method: 'POST',
            dataType: 'json',
            data: {
                form_key: opts.formKey,
                conditions_serialized: value,
                trigger_type: opts.triggerType,
                trigger_ref: opts.triggerRef,
                entity_type: opts.entityType
            }
        }).done(function (res) {
            if (!res || res.success === false) {
                showMessage($messages, (res && res.error) || $t('Could not validate the conditions.'), 'error');
                return;
            }
            // Round-trip the normalized tree back into the form field.
            $field.val(res.conditions_serialized || '').trigger('change');
            renderMessages($messages, res.messages || []);
            if (!res.messages || !hasError(res.messages)) {
                $textarea.closest('.mageos-conditions-slideout').trigger('mageos:conditions-applied');
                closeModal($textarea);
            }
        }).fail(function () {
            showMessage($messages, $t('The conditions could not be validated.'), 'error');
        });
    }

    function hasError(messages) {
        return messages.some(function (m) {
            return m.severity === 'error';
        });
    }

    function renderMessages($messages, messages) {
        $messages.empty();
        messages.forEach(function (m) {
            showMessage($messages, m.message, m.severity);
        });
    }

    function showMessage($messages, text, severity) {
        var $line = $('<div></div>', {'class': 'mageos-conditions-message mageos-conditions-message--' + (severity || 'notice')});
        $line.text(text);
        $messages.append($line);
    }

    function closeModal($textarea) {
        $textarea.closest('.mageos-conditions-slideout').modal('closeModal');
    }

    return function (config, element) {
        init(element);
    };
});
