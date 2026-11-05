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
 * Site administrators and system-level managers can search every course.
 * A role assigned on a category can search every course in that category and
 * its subcategories. Everyone else can search the courses they are allowed to
 * browse or the courses they are enrolled in.
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
        if (is_siteadmin() || self::has_system_course_access()) {
            return true;
        }
        if (self::managed_category_ids()) {
            return true;
        }
        if (has_capability('moodle/category:viewcourselist', context_system::instance())) {
            return true;
        }
        if (core_course_category::has_capability_on_any('moodle/category:viewcourselist')) {
            return true;
        }
        // A participant can search the courses they are enrolled in.
        return (bool)enrol_get_my_courses(null, null, 1);
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
     * Whether a system-level role lets this user manage courses across the site.
     *
     * moodle/category:viewcourselist is intentionally absent. It is the normal
     * catalogue permission of an authenticated user and must not turn a category
     * manager into a site-wide search.
     *
     * @return bool
     */
    private static function has_system_course_access(): bool {
        $systemcontext = context_system::instance();
        foreach ([
            'moodle/category:manage',
            'moodle/course:create',
            'moodle/course:update',
            'block/quickcoursesearch:search',
        ] as $capability) {
            if (has_capability($capability, $systemcontext)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Categories where a role assignment grants access to the courses inside them.
     *
     * The returned ids include subcategories. Any course or category capability
     * on the assigned role qualifies, so a custom category-manager role is covered
     * even when it does not have block/quickcoursesearch:search.
     *
     * @return int[]
     */
    private static function managed_category_ids(): array {
        global $DB, $USER;

        $coursecap = $DB->sql_like('rc.capability', '?', false);
        $categorycap = $DB->sql_like('rc.capability', '?', false);
        $assigned = $DB->get_fieldset_sql(
            "SELECT DISTINCT assignctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} assignctx ON assignctx.id = ra.contextid
               JOIN {role_capabilities} rc ON rc.roleid = ra.roleid AND rc.permission = ?
              WHERE ra.userid = ?
                AND assignctx.contextlevel = ?
                AND (rc.contextid = ? OR rc.contextid = assignctx.id)
                AND ({$coursecap} OR {$categorycap} OR rc.capability = ?)",
            [
                CAP_ALLOW,
                (int)$USER->id,
                CONTEXT_COURSECAT,
                context_system::instance()->id,
                'moodle/course:%',
                'moodle/category:%',
                'block/quickcoursesearch:search',
            ]
        );
        if (!$assigned) {
            return [];
        }

        $ids = [];
        foreach ($assigned as $categoryid) {
            $categoryid = (int)$categoryid;
            $ids[$categoryid] = $categoryid;
            $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
            if ($path === false || $path === null || $path === '') {
                continue;
            }
            $children = $DB->get_fieldset_sql(
                "SELECT id
                   FROM {course_categories}
                  WHERE " . $DB->sql_like('path', '?', false),
                [$DB->sql_like_escape($path) . '/%']
            );
            foreach ($children as $childid) {
                $childid = (int)$childid;
                $ids[$childid] = $childid;
            }
        }
        return array_values($ids);
    }

    /**
     * Whether the current user may see this course in search results.
     *
     * @param stdClass $course Course record with id, category and visible.
     * @param int[]|null $managedcategoryids Categories already resolved for this search.
     * @return bool
     */
    public static function user_can_view_course(stdClass $course, ?array $managedcategoryids = null): bool {
        if ((int)$course->id === SITEID || !isloggedin()) {
            return false;
        }
        if (is_siteadmin() || self::has_system_course_access()) {
            return true;
        }

        $coursecontext = context_course::instance($course->id);
        // Enrolment grants access, including to a hidden course the user is a participant in.
        if (is_enrolled($coursecontext, null, '', true)) {
            return true;
        }

        if ($managedcategoryids === null) {
            $managedcategoryids = self::managed_category_ids();
        }
        $categoryid = (int)$course->category;
        if (in_array($categoryid, $managedcategoryids, true)) {
            return self::can_view_managed_course($course, $coursecontext);
        }
        // A category role is a narrower scope than the site catalogue.
        if ($managedcategoryids) {
            return false;
        }

        $categorycontext = $categoryid ? context_coursecat::instance($categoryid) : null;
        if (!$categorycontext || !has_capability('moodle/category:viewcourselist', $categorycontext)) {
            return false;
        }
        if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
            return false;
        }
        return self::category_is_visible($categoryid);
    }

    /**
     * A course inside a category the user manages or can browse from a category role.
     *
     * Managers see hidden courses and courses in hidden subcategories. A role that
     * can only search or browse the category still follows the normal visibility rules.
     *
     * @param stdClass $course
     * @param context_course $coursecontext
     * @return bool
     */
    private static function can_view_managed_course(stdClass $course, context_course $coursecontext): bool {
        $categorycontext = context_coursecat::instance($course->category);
        $canseehidden = has_capability('moodle/course:viewhiddencourses', $coursecontext)
            || has_capability('moodle/category:manage', $categorycontext)
            || has_capability('moodle/course:update', $coursecontext)
            || has_capability('moodle/course:view', $coursecontext)
            || has_capability('moodle/course:visibility', $coursecontext);
        if ($canseehidden) {
            return true;
        }
        if (empty($course->visible)) {
            return false;
        }
        return self::category_is_visible((int)$course->category);
    }

    /**
     * SQL that keeps the candidate courses inside categories the user can view.
     *
     * An empty string means every matching course is a candidate. Null means the
     * user cannot see any course, so the query should not run.
     *
     * @param int[] $categoryids Categories the user can search, including subcategories.
     * @return array{0: string|null, 1: array}
     */
    private static function view_limit_sql(array $categoryids): array {
        global $DB;

        if (is_siteadmin() || self::has_system_course_access()) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        if ($categoryids) {
            [$insql, $inparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_QM);
            $parts[] = 'c.category ' . $insql;
            $params = array_merge($params, $inparams);
        } else if (has_capability('moodle/category:viewcourselist', context_system::instance())
                || core_course_category::has_capability_on_any('moodle/category:viewcourselist')) {
            // Catalogue browsing is not limited to one category. Hidden courses are filtered per course.
            return ['', []];
        }

        $enrolled = enrol_get_my_courses(null, null, 0);
        if ($enrolled) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($enrolled), SQL_PARAMS_QM);
            $parts[] = 'c.id ' . $insql;
            $params = array_merge($params, $inparams);
        }

        if (!$parts) {
            return [null, []];
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * Find matching courses the current user may see.
     *
     * @param string $query Trimmed query that is already long enough.
     * @return array{courses: array, more: bool}
     */
    private static function find_courses(string $query): array {
        global $DB;

        $categoryids = (is_siteadmin() || self::has_system_course_access()) ? [] : self::managed_category_ids();
        [$limitsql, $limitparams] = self::view_limit_sql($categoryids);
        if ($limitsql === null) {
            return ['courses' => [], 'more' => false];
        }

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
            [$lecturersql, $lecturerparams] = self::lecturer_match_sql($like);
            if ($lecturersql !== '') {
                $clauses[] = $lecturersql;
                $params = array_merge($params, $lecturerparams);
            }
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

        if ($limitsql !== '') {
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
                if (!self::user_can_view_course($record, $categoryids)) {
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

        return ['courses' => self::attach_lecturers($courses), 'more' => $more];
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
            'teachers' => '',
        ];
    }

    /**
     * Add the lecturer names shown under each course.
     *
     * @param array $courses Formatted courses.
     * @return array
     */
    private static function attach_lecturers(array $courses): array {
        if (!$courses) {
            return $courses;
        }
        $names = self::lecturer_names(array_column($courses, 'id'));
        foreach ($courses as $index => $course) {
            $courses[$index]['teachers'] = $names[$course['id']] ?? '';
        }
        return $courses;
    }

    /**
     * Roles whose enrolled users are shown as the course lecturers.
     *
     * The site's course-contact roles are included, together with the teacher
     * and editing-teacher roles and any custom role based on those archetypes.
     *
     * @return int[]
     */
    private static function lecturer_role_ids(): array {
        global $CFG, $DB;

        $ids = [];
        if (!empty($CFG->coursecontact)) {
            foreach (explode(',', $CFG->coursecontact) as $roleid) {
                $roleid = (int)$roleid;
                if ($roleid > 0) {
                    $ids[$roleid] = $roleid;
                }
            }
        }
        $roles = $DB->get_records_select(
            'role',
            "shortname IN ('teacher', 'editingteacher') OR archetype IN ('teacher', 'editingteacher')",
            null,
            '',
            'id'
        );
        foreach ($roles as $role) {
            $ids[(int)$role->id] = (int)$role->id;
        }
        return array_values($ids);
    }

    /**
     * SQL matching courses taught by a lecturer whose name contains the query.
     *
     * The returned parameters use positional placeholders and follow the SQL order.
     *
     * @param string $like Bound LIKE value, including wildcards.
     * @return array{0: string, 1: array}
     */
    private static function lecturer_match_sql(string $like): array {
        global $DB;

        $roleids = self::lecturer_role_ids();
        if (!$roleids) {
            return ['', []];
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_QM);
        $ancestor = $DB->sql_concat('assignctx.path', "'/%'");
        $nameclauses = [
            $DB->sql_like('u.firstname', '?', false, true),
            $DB->sql_like('u.lastname', '?', false, true),
            $DB->sql_like('u.alternatename', '?', false, true),
            $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), '?', false, true),
            $DB->sql_like($DB->sql_concat('u.lastname', "' '", 'u.firstname'), '?', false, true),
        ];
        $sql = "c.id IN (
                    SELECT e.courseid
                      FROM {user} u
                      JOIN {user_enrolments} ue ON ue.userid = u.id
                      JOIN {enrol} e ON e.id = ue.enrolid
                      JOIN {role_assignments} ra ON ra.userid = u.id
                      JOIN {context} assignctx ON assignctx.id = ra.contextid
                      JOIN {context} coursecx ON coursecx.instanceid = e.courseid
                           AND coursecx.contextlevel = ?
                           AND (coursecx.id = assignctx.id OR coursecx.path LIKE {$ancestor})
                     WHERE u.deleted = 0
                       AND e.status = ?
                       AND ue.status = ?
                       AND ue.timestart <= ?
                       AND (ue.timeend = 0 OR ue.timeend > ?)
                       AND ra.roleid {$rolesql}
                       AND (" . implode(' OR ', $nameclauses) . ')
                )';
        $now = time();
        $params = array_merge(
            [CONTEXT_COURSE, ENROL_INSTANCE_ENABLED, ENROL_USER_ACTIVE, $now, $now],
            $roleparams,
            array_fill(0, count($nameclauses), $like)
        );
        return [$sql, $params];
    }

    /**
     * Lecturer names for each course, in the viewer's name format.
     *
     * @param int[] $courseids
     * @return array<int, string>
     */
    private static function lecturer_names(array $courseids): array {
        global $DB;

        $roleids = self::lecturer_role_ids();
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (!$roleids || !$courseids) {
            return [];
        }

        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $contexts = $DB->get_records_sql(
            "SELECT id, instanceid, path
               FROM {context}
              WHERE contextlevel = :courselevel
                AND instanceid {$coursesql}",
            ['courselevel' => CONTEXT_COURSE] + $courseparams
        );
        if (!$contexts) {
            return [];
        }

        // A lecturer role may be assigned on the course or on a parent category.
        $coursesbycontext = [];
        foreach ($contexts as $context) {
            $parts = preg_split('|/|', $context->path, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $contextid) {
                $coursesbycontext[(int)$contextid][] = (int)$context->instanceid;
            }
        }

        [$ctxsql, $ctxparams] = $DB->get_in_or_equal(array_keys($coursesbycontext), SQL_PARAMS_NAMED, 'ctx');
        [$enrolsql, $enrolparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'eid');
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT ra.contextid, e.courseid, u.id AS userid, {$namefields}
                  FROM {role_assignments} ra
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
                  JOIN {enrol} e ON e.courseid {$enrolsql} AND e.status = :enabled
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id
                       AND ue.status = :active
                       AND ue.timestart <= :now1
                       AND (ue.timeend = 0 OR ue.timeend > :now2)
                 WHERE ra.contextid {$ctxsql}
                   AND ra.roleid {$rolesql}";
        $params = [
            'enabled' => ENROL_INSTANCE_ENABLED,
            'active' => ENROL_USER_ACTIVE,
            'now1' => time(),
            'now2' => time(),
        ] + $ctxparams + $enrolparams + $roleparams;

        $bycourse = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $courseid = (int)$record->courseid;
            $covered = $coursesbycontext[(int)$record->contextid] ?? [];
            if (!in_array($courseid, $covered, true)) {
                continue;
            }
            $bycourse[$courseid][(int)$record->userid] = $record;
        }
        $records->close();

        $names = [];
        foreach ($bycourse as $courseid => $users) {
            $context = context_course::instance($courseid);
            $fullnames = [];
            foreach ($users as $user) {
                $fullnames[] = \core_user::get_fullname($user, $context);
            }
            \core_collator::asort($fullnames);
            $names[$courseid] = implode(', ', $fullnames);
        }
        return $names;
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
        $result['teacherslabel'] = get_string('teacherslabel', 'block_quickcoursesearch');
        return $result;
    }
}
