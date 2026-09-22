/**
 * Type-ahead picker for a template install-form parameter backed by an option
 * source (an `options_search` field, or an unbounded `entity:*` type). It wraps
 * — never replaces — the real `param[<key>]` input the form posts: the input is
 * always rendered by the template, so the field still works with JavaScript
 * off, after an install error re-renders the form, and when the feed is down.
 *
 * Every failure path reveals that raw input again rather than trapping the
 * operator in a picker that cannot resolve anything: a missing endpoint/source,
 * an HTTP or parse error, or the "enter the record ID manually" affordance. The
 * server re-validates the submitted value against the same source either way,
 * so a hand-typed ID is never a way around a check.
 *
 * User-facing strings arrive already translated in data-* attributes (the phtml
 * wraps them in __()), so this module needs no mage/translate dependency — one
 * translation pass, server-side, for both the enhanced and the plain rendering.
 *
 * CSP-safe: no inline script (loaded as a module), no eval, all config from
 * data-* attributes on the container element; option labels and every other
 * server string are written with textContent/.text(), never innerHTML.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
define([
    'jquery'
], function ($) {
    'use strict';

    /** Idle time after the last keystroke before the source is queried. */
    var DEBOUNCE_MS = 250;

    /**
     * @param {HTMLElement} root the container carrying data-* config
     */
    function init(root) {
        var $root = $(root),
            $real = $root.find('input.admin__control-text').first(),
            endpoint = $root.data('endpoint'),
            source = $root.data('source'),
            minChars = parseInt($root.data('minChars'), 10),
            labels = {
                placeholder: $root.data('searchPlaceholder') || '',
                results: $root.data('labelResults') || '',
                noResults: $root.data('labelNoResults') || '',
                nothingSelected: $root.data('labelNothingSelected') || '',
                manual: $root.data('labelManual') || ''
            },
            timer = null;

        if (!$real.length || !endpoint || !source) {
            // Nothing to query: leave the plain input exactly as rendered.
            return;
        }
        if (isNaN(minChars) || minChars < 0) {
            minChars = 2;
        }

        var $chip = $('<div></div>', {'class': 'mageos-param-search__current'}),
            $query = $('<input/>', {
                'type': 'text',
                'class': 'admin__control-text mageos-param-search__query',
                'placeholder': labels.placeholder,
                'autocomplete': 'off',
                'aria-label': labels.placeholder
            }),
            $results = $('<select></select>', {
                'class': 'admin__control-select mageos-param-search__results',
                'size': 6,
                'aria-label': labels.results
            }),
            $manual = $('<button></button>', {
                'type': 'button',
                'class': 'action-secondary mageos-param-search__manual'
            });

        /**
         * @param {String} text
         */
        function setChip(text) {
            $chip.text(text);
        }

        /**
         * The escape hatch: the operator types the record ID straight into the
         * field the form posts. One-way — the picker stays available above it.
         */
        function reveal() {
            $real.show();
            $manual.hide();
        }

        /**
         * @param {Array} options {value, label} rows from the options feed
         */
        function render(options) {
            var list = $results.get(0),
                option;

            $results.empty();

            if (!options.length) {
                option = document.createElement('option');
                option.disabled = true;
                option.textContent = labels.noResults;
                list.appendChild(option);
                $results.show();

                return;
            }

            options.forEach(function (row) {
                var entry = document.createElement('option');

                entry.value = row.value;
                entry.textContent = row.label + ' (' + row.value + ')';
                list.appendChild(entry);
            });
            $results.show();
        }

        /**
         * @param {String} query
         */
        function search(query) {
            $.getJSON(endpoint, {
                'source': source,
                'q': query
            }).done(function (response) {
                render(response && Array.isArray(response.options) ? response.options : []);
            }).fail(function () {
                $results.hide().empty();
                reveal();
            });
        }

        $manual.text(labels.manual);
        $results.hide();
        $real.hide();
        $root.append($chip, $query, $results, $manual);
        setChip($root.data('currentLabel') || $real.val() || labels.nothingSelected);

        $query.on('input', function () {
            var query = $.trim($query.val() || '');

            window.clearTimeout(timer);

            if (query.length < minChars) {
                $results.hide().empty();

                return;
            }
            timer = window.setTimeout(function () {
                search(query);
            }, DEBOUNCE_MS);
        });

        $results.on('change', function () {
            var value = $results.val(),
                label = $results.find('option:selected').text();

            if (value === null || value === '') {
                return;
            }
            $real.val(value).trigger('change');
            setChip(label);
            $results.hide().empty();
            $query.val('');
        });

        $manual.on('click', function (event) {
            event.preventDefault();
            reveal();
        });
    }

    return function (config, element) {
        init(element);
    };
});
