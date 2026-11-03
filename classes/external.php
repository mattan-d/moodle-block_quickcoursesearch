<?php
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
 * External API for live course search.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quickcoursesearch;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * AJAX entry point for the search field.
 *
 * @package    block_quickcoursesearch
 */
class external extends external_api {

    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function search_courses_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Course name, short name, id or id number'),
        ]);
    }

    /**
     * Search courses visible to the current user.
     *
     * @param string $query
     * @return array
     */
    public static function search_courses(string $query): array {
        $params = self::validate_parameters(self::search_courses_parameters(), [
            'query' => $query,
        ]);
        self::validate_context(context_system::instance());
        return search::search($params['query']);
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function search_courses_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                    'category' => new external_value(PARAM_TEXT, 'Category path'),
                    'viewurl' => new external_value(PARAM_URL, 'Course page URL'),
                    'editurl' => new external_value(PARAM_URL, 'Course settings URL', VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'canedit' => new external_value(PARAM_BOOL, 'Whether the user can edit course settings'),
                    'hidden' => new external_value(PARAM_BOOL, 'Whether the course is hidden'),
                ])
            ),
            'more' => new external_value(PARAM_BOOL, 'Whether more matches exist beyond the returned list'),
            'tooshort' => new external_value(PARAM_BOOL, 'Whether the query is too short to search'),
            'message' => new external_value(PARAM_TEXT, 'Empty-state message'),
            'moremessage' => new external_value(PARAM_TEXT, 'Message shown when the result list is truncated'),
            'hascourses' => new external_value(PARAM_BOOL, 'Whether any course is returned'),
            'hiddenlabel' => new external_value(PARAM_TEXT, 'Label for a hidden course'),
            'editlabel' => new external_value(PARAM_TEXT, 'Label for the settings link'),
            'categorylabel' => new external_value(PARAM_TEXT, 'Label for the category'),
            'shortnamelabel' => new external_value(PARAM_TEXT, 'Label for the short name'),
        ]);
    }
}
