/**
 * Composite amount + unit control for a `duration` template parameter on the
 * install form. It wraps — never replaces — the real `param[<key>]` input,
 * which always carries the ISO-8601 value the form posts and the server
 * validates; the composite only writes into it.
 *
 * The control renders ONLY when the block could split the current value into
 * an exact amount/unit pair (data-value + data-unit). No pair means the field
 * is in raw ISO mode — an empty value, or a compound interval such as P1DT12H
 * that the composite cannot represent — and this module does nothing at all.
 * Raw mode is a necessity fallback (no JavaScript, or an unrepresentable
 * value), not a user-selectable alternative: when the composite renders, it
 * is the only control.
 *
 * User-facing strings arrive already translated in data-* attributes (the phtml
 * wraps them in __()), so this module needs no mage/translate dependency — one
 * translation pass, server-side, for both the enhanced and the plain rendering.
 *
 * CSP-safe: no inline script (loaded as a module), no eval, all config from
 * data-* attributes on the container element; server strings set via
 * textContent/.text(), never innerHTML.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /** unit => ISO-8601 pattern; %d is the amount. Also the allowed unit set. */
    var PATTERNS = {
        'minutes': 'PT%dM',
        'hours': 'PT%dH',
        'days': 'P%dD'
    };

    /**
     * @param {HTMLElement} root the container carrying data-* config
     */
    function init(root) {
        var $root = $(root),
            $real = $root.find('input.admin__control-text').first(),
            value = $root.data('value'),
            unit = String($root.data('unit') || ''),
            labels = {
                amount: $root.data('labelAmount') || '',
                unit: $root.data('labelUnit') || '',
                minutes: $root.data('labelMinutes') || '',
                hours: $root.data('labelHours') || '',
                days: $root.data('labelDays') || ''
            };

        if (!$real.length || value === undefined || value === null || !PATTERNS.hasOwnProperty(unit)) {
            // Raw ISO mode: the plain input is the control.
            return;
        }

        var $amount = $('<input/>', {
                'type': 'number',
                'min': 1,
                'step': 1,
                'class': 'admin__control-text mageos-param-duration__amount',
                'value': value,
                'aria-label': labels.amount
            }),
            $unit = $('<select></select>', {
                'class': 'admin__control-select mageos-param-duration__unit',
                'aria-label': labels.unit
            });

        /**
         * Writes the composed interval into the real input. A non-positive or
         * non-integer amount leaves it untouched: a half-typed number must
         * never blank a value the operator already has.
         */
        function compose() {
            var raw = $.trim(String($amount.val() || '')),
                selected = String($unit.val() || '');

            if (!/^\d+$/.test(raw) || parseInt(raw, 10) < 1 || !PATTERNS.hasOwnProperty(selected)) {
                return;
            }
            $real.val(PATTERNS[selected].replace('%d', String(parseInt(raw, 10)))).trigger('change');
        }

        Object.keys(PATTERNS).forEach(function (name) {
            var option = document.createElement('option');

            option.value = name;
            option.textContent = labels[name];
            $unit.get(0).appendChild(option);
        });
        $unit.val(unit);

        $real.hide();
        $root.append($amount, $unit);

        $amount.on('input change', compose);
        $unit.on('change', compose);
    }

    return function (config, element) {
        init(element);
    };
});
