// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Live search for the quick course search block.
 *
 * @module     block_quickcoursesearch/search
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification', 'core/templates'], function(Ajax, Notification, Templates) {
    /**
     * Attach live search to one block.
     *
     * @param {String} selector Root element selector.
     */
    var init = function(selector) {
        var root = document.querySelector(selector);
        if (!root || root.dataset.initialised) {
            return;
        }
        root.dataset.initialised = '1';

        var input = root.querySelector('[data-region="search-input"]');
        var results = root.querySelector('[data-region="search-results"]');
        var form = root.querySelector('form');
        if (!input || !results || !form) {
            return;
        }

        var timer = null;
        var requestid = 0;

        /**
         * Replace the results region.
         *
         * @param {Object} data Template context.
         * @param {Number} current Request id that produced the data.
         * @return {Promise}
         */
        var render = function(data, current) {
            return Templates.renderForPromise('block_quickcoursesearch/results', data)
                .then(function(result) {
                    if (current !== requestid) {
                        return result;
                    }
                    Templates.replaceNodeContents(results, result.html, result.js);
                    results.removeAttribute('aria-busy');
                    return result;
                })
                .catch(Notification.exception);
        };

        /**
         * Run a search for the current field value.
         */
        var search = function() {
            window.clearTimeout(timer);
            var query = input.value.trim();
            var current = ++requestid;

            if (query === '') {
                results.removeAttribute('aria-busy');
                results.innerHTML = '';
                return;
            }

            results.setAttribute('aria-busy', 'true');
            Ajax.call([{
                methodname: 'block_quickcoursesearch_search_courses',
                args: {query: query},
            }])[0].then(function(response) {
                if (current !== requestid) {
                    return response;
                }
                return render(response, current);
            }).catch(function(error) {
                if (current !== requestid) {
                    return;
                }
                results.removeAttribute('aria-busy');
                Notification.exception(error);
            });
        };

        input.addEventListener('input', function() {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, 300);
        });

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            search();
        });
    };

    return {
        init: init,
    };
});
