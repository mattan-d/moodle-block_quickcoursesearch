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
 * Course search used by the quick course search block.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quickcoursesearch;

use context_course;
use context_coursecat;
use context_system;
use core_course_category;
use core_text;
use moodle_url;
use stdClass;

/**
 * Searches courses the current user is allowed to see.
 *
 * The block is available to users with block/quickcoursesearch:search or
 * moodle/category:viewcourselist. Site administrators can search every course.
 *
 * Anyone who holds block/quickcoursesearch:search is limited to the contexts
 * where that capability applies. A manager role assigned on one category
 * therefore sees only that category, even though moodle/category:viewcourselist
 * is allowed for authenticated users at system level. Users who do not hold the
 * plugin capability fall back to moodle/category:viewcourselist and Moodle's
 * normal course visibility rules.
 *
 * @package    block_quickcoursesearch
 */
class search {

    /** @var int Maximum number of courses returned for one query. */
    public const RESULT_LIMIT = 20;

    /** @var int Minimum length of a non-numeric query. */
    public const MIN_QUERY_LENGTH = 2;

    /** @var int Matching rows examined before the search stops. */
    private const MAX_ROWS = 2000;

    /** @var int Rows loaded per query while filtering by permission. */
    private const BATCH_SIZE = 100;

    /** @var int Maximum accepted length of a search query. */
    public const MAX_QUERY_LENGTH = 100;

    /**
     * Whether the current user may use the block.
     *
     * @return bool
     */
    public static function user_can_search(): bool {
        if (!isloggedin()) {
            return false;
        }
        if (is_siteadmin()) {
            return true;
        }
        if (self::has_search_capability_anywhere()) {
            return true;
        }
        $systemcontext = context_system::instance();
        if (has_capability('moodle/category:viewcourselist', $systemcontext)) {
            return true;
        }
        return core_course_category::has_capability_on_any('moodle/category:viewcourselist');
    }

    /**
     * Search courses and return data ready for the results template.
     *
     * @param string $query Raw search query.
     * @return array
     */
    public static function search(string $query): array {
        if (!self::user_can_search()) {
            throw new \moodle_exception('cannotsearch', 'block_quickcoursesearch');
        }

        $query = trim($query);
        if (core_text::strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = core_text::substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        $tooshort = $query !== '' && !self::query_is_long_enough($query);
        $courses = [];
        $more = false;
        if ($query !== '' && !$tooshort) {
            ['courses' => $courses, 'more' => $more] = self::find_courses($query);
        }

        if ($tooshort) {
            $message = get_string('tooshort', 'block_quickcoursesearch', self::MIN_QUERY_LENGTH);
        } else if ($query !== '' && !$courses) {
            $message = get_string('noresults', 'block_quickcoursesearch');
        } else {
            $message = '';
        }

        return self::present([
            'courses' => $courses,
            'more' => $more,
            'tooshort' => $tooshort,
            'message' => $message,
            'moremessage' => $more ? get_string('moreresults', 'block_quickcoursesearch', self::RESULT_LIMIT) : '',
        ]);
    }

    /**
     * Template data for one block instance.
     *
     * @param int $instanceid Block instance id.
     * @param string $query Query submitted for this instance, or an empty string.
     * @param moodle_url $pageurl URL of the page that hosts the block.
     * @return array
     */
    public static function export_block(int $instanceid, string $query, moodle_url $pageurl): array {
        $url = new moodle_url($pageurl);
        $url->remove_params(['qcsq', 'qcsi']);
        $query = trim($query);

        $data = [
            'instanceid' => $instanceid,
            'actionurl' => $url->out(false),
            'query' => $query,
            'minlength' => self::MIN_QUERY_LENGTH,
            'searchlabel' => get_string('searchlabel', 'block_quickcoursesearch'),
            'placeholder' => get_string('searchplaceholder', 'block_quickcoursesearch'),
            'search' => get_string('search'),
            'searched' => false,
        ];

        if ($query !== '') {
            $data = $data + self::search($query);
            $data['searched'] = true;
            $data['query'] = $query;
        }

        return $data;
    }

    /**
     * Whether this user holds the plugin capability in any context.
     *
     * @return bool
     */
    public static function has_search_capability_anywhere(): bool {
        global $USER;

        if (!isloggedin()) {
            return false;
        }
        if (is_siteadmin()) {
            return true;
        }
        if (has_capability('block/quickcoursesearch:search', context_system::instance())) {
            return true;
        }
        [$limitsql] = \core\access\get_user_capability_course_helper::get_sql(
            (int)$USER->id,
            'block/quickcoursesearch:search'
        );
        return !empty($limitsql);
    }

    /**
     * Whether the current user may see this course in search results.
     *
     * @param stdClass $course Course record with id, category and visible.
     * @param bool|null $usesearchcap Precomputed has_search_capability_anywhere() result.
     * @return bool
     */
    public static function user_can_view_course(stdClass $course, ?bool $usesearchcap = null): bool {
        if ((int)$course->id === SITEID || !isloggedin()) {
            return false;
        }
        if (is_siteadmin()) {
            return true;
        }

        if ($usesearchcap === null) {
            $usesearchcap = self::has_search_capability_anywhere();
        }

        $coursecontext = context_course::instance($course->id);
        if ($usesearchcap) {
            if (!has_capability('block/quickcoursesearch:search', $coursecontext)) {
                return false;
            }
        } else if (!core_course_category::can_view_course_info($course)) {
            return false;
        }

        if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
            return false;
        }

        return self::category_is_visible((int)$course->category);
    }

    /**
     * Find matching courses the current user may see.
     *
     * @param string $query Trimmed query that is already long enough.
     * @return array{courses: array, more: bool}
     */
    private static function find_courses(string $query): array {
        global $DB, $USER;

        $usesearchcap = !is_siteadmin() && self::has_search_capability_anywhere();
        $courseid = self::query_course_id($query);
        $params = [CONTEXT_COURSE];
        $where = ['c.id <> ?'];
        $params[] = SITEID;

        $clauses = [];
        if (core_text::strlen($query) >= self::MIN_QUERY_LENGTH) {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $clauses[] = $DB->sql_like('c.fullname', '?', false, true);
            $clauses[] = $DB->sql_like('c.shortname', '?', false, true);
            $clauses[] = $DB->sql_like('c.idnumber', '?', false, true);
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($courseid !== null) {
            $clauses[] = 'c.id = ?';
            $params[] = $courseid;
            if (core_text::strlen($query) < self::MIN_QUERY_LENGTH) {
                $clauses[] = 'c.idnumber = ?';
                $params[] = $query;
            }
        }
        if (!$clauses) {
            return ['courses' => [], 'more' => false];
        }
        $where[] = '(' . implode(' OR ', $clauses) . ')';

        if ($usesearchcap) {
            [$limitsql, $limitparams] = \core\access\get_user_capability_course_helper::get_sql(
                (int)$USER->id,
                'block/quickcoursesearch:search'
            );
            if (empty($limitsql)) {
                return ['courses' => [], 'more' => false];
            }
            $where[] = $limitsql;
            $params = array_merge($params, $limitparams);
        }

        $fields = \context_helper::get_preload_record_columns_sql('x');
        $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, c.category, c.visible, {$fields}
                  FROM {course} c
                  JOIN {context} x ON x.instanceid = c.id AND x.contextlevel = ?
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY c.fullname ASC, c.id ASC";

        // Permission checks query the database, so each batch is loaded and closed first.
        $courses = [];
        $more = false;
        $scanned = 0;
        for ($offset = 0; $offset < self::MAX_ROWS && !$more; $offset += self::BATCH_SIZE) {
            $records = $DB->get_records_sql($sql, $params, $offset, self::BATCH_SIZE);
            if (!$records) {
                break;
            }
            foreach ($records as $record) {
                $scanned++;
                \context_helper::preload_from_record($record);
                if (!self::user_can_view_course($record, $usesearchcap)) {
                    continue;
                }
                if (count($courses) === self::RESULT_LIMIT) {
                    $more = true;
                    break;
                }
                $courses[] = self::format_course($record);
            }
            if ($more || count($records) < self::BATCH_SIZE || $scanned >= self::MAX_ROWS) {
                break;
            }
        }

        return ['courses' => $courses, 'more' => $more];
    }

    /**
     * Format one course for the template and the AJAX response.
     *
     * @param stdClass $course
     * @return array
     */
    private static function format_course(stdClass $course): array {
        $coursecontext = context_course::instance($course->id);

        return [
            'id' => (int)$course->id,
            'fullname' => format_string($course->fullname, true, [
                'context' => $coursecontext,
                'escape' => false,
            ]),
            'shortname' => format_string($course->shortname, true, [
                'context' => $coursecontext,
                'escape' => false,
            ]),
            'category' => self::category_path((int)$course->category),
            'viewurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'hidden' => empty($course->visible),
        ];
    }

    /**
     * Category path, from the top-level category down to the course category.
     *
     * @param int $categoryid
     * @return string
     */
    private static function category_path(int $categoryid): string {
        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        if (!$category) {
            return '';
        }
        $ids = array_merge($category->get_parents(), [$category->id]);
        $names = [];
        foreach ($ids as $id) {
            $cat = ((int)$id === (int)$category->id) ? $category : core_course_category::get($id, IGNORE_MISSING, true);
            if (!$cat) {
                continue;
            }
            $names[] = format_string($cat->name, true, [
                'context' => $cat->get_context(),
                'escape' => false,
            ]);
        }
        return implode(' / ', $names);
    }

    /**
     * A hidden category, or a category under a hidden parent, is visible only
     * to users who may view hidden categories there.
     *
     * @param int $categoryid
     * @return bool
     */
    private static function category_is_visible(int $categoryid): bool {
        $category = core_course_category::get($categoryid, IGNORE_MISSING, true);
        if (!$category) {
            return false;
        }
        $ids = array_merge($category->get_parents(), [$category->id]);
        foreach ($ids as $id) {
            $cat = ((int)$id === (int)$category->id) ? $category : core_course_category::get($id, IGNORE_MISSING, true);
            if (!$cat) {
                return false;
            }
            if (!empty($cat->visible)) {
                continue;
            }
            if (!has_capability('moodle/category:viewhiddencategories', context_coursecat::instance($cat->id))) {
                return false;
            }
        }
        return true;
    }

    /**
     * A positive course id encoded by the whole query, or null.
     *
     * @param string $query
     * @return int|null
     */
    private static function query_course_id(string $query): ?int {
        if (!preg_match('/^[1-9][0-9]{0,8}$/', $query)) {
            return null;
        }
        return (int)$query;
    }

    /**
     * Whether the query is long enough to search, or is a course id.
     *
     * @param string $query Trimmed query.
     * @return bool
     */
    private static function query_is_long_enough(string $query): bool {
        if (self::query_course_id($query) !== null) {
            return true;
        }
        return core_text::strlen($query) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * Add labels shared by the results template.
     *
     * @param array $result
     * @return array
     */
    private static function present(array $result): array {
        $result['hascourses'] = !empty($result['courses']);
        $result['hiddenlabel'] = get_string('hiddencourse', 'block_quickcoursesearch');
        $result['categorylabel'] = get_string('categorylabel', 'block_quickcoursesearch');
        $result['shortnamelabel'] = get_string('shortnamelabel', 'block_quickcoursesearch');
        return $result;
    }
}
