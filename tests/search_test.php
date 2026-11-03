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

namespace block_quickcoursesearch;

use advanced_testcase;
use context_coursecat;
use context_system;

/**
 * Tests for quick course search permissions and matching.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \block_quickcoursesearch\search
 */
final class search_test extends advanced_testcase {

    /**
     * Load the block class.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/../../moodleblock.class.php');
        require_once(__DIR__ . '/../block_quickcoursesearch.php');
        parent::setUpBeforeClass();
    }

    /**
     * Admin search matches name, short name, id and id number, including hidden courses.
     *
     * @covers ::search
     */
    public function test_admin_searches_all_fields(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $this->setAdminUser();

        $byname = $this->ids(search::search('alpha biology'));
        $this->assertContains($scenario->visible->id, $byname);
        $this->assertNotContains($scenario->other->id, $byname);

        $byshort = $this->ids(search::search('CHEM202'));
        $this->assertSame([$scenario->other->id], $byshort);

        $byidnumber = $this->ids(search::search('ID-BIO-1'));
        $this->assertSame([$scenario->visible->id], $byidnumber);

        $byid = $this->ids(search::search((string)$scenario->hidden->id));
        $this->assertContains($scenario->hidden->id, $byid);

        $hidden = $this->course_by_id(search::search('Secret Lab'), $scenario->hidden->id);
        $this->assertTrue($hidden['hidden']);
        $this->assertStringContainsString('/course/view.php', $hidden['viewurl']);
        $this->assertStringContainsString('Science', $hidden['category']);
    }

    /**
     * A manager assigned on one category sees only courses in that category.
     *
     * @covers ::search
     * @covers ::user_can_view_course
     */
    public function test_category_manager_is_limited_to_assigned_categories(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $this->setUser($scenario->manager);

        $own = $this->ids(search::search('Alpha'));
        $this->assertContains($scenario->visible->id, $own);
        $this->assertNotContains($scenario->other->id, $own);

        $hidden = $this->ids(search::search('Secret'));
        $this->assertContains($scenario->hidden->id, $hidden);
        $this->assertNotContains($scenario->otherhidden->id, $hidden);

        $hiddencategory = $this->ids(search::search('Concealed'));
        $this->assertContains($scenario->hiddencatcourse->id, $hiddencategory);

        $other = search::search('Chemistry');
        $this->assertSame([], $this->ids($other));
        $this->assertSame(get_string('noresults', 'block_quickcoursesearch'), $other['message']);
    }

    /**
     * A user with only the default course-list capability sees visible courses and not hidden ones.
     *
     * @covers ::search
     */
    public function test_viewcourselist_user_sees_visible_courses_only(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $this->setUser($scenario->user);

        $this->assertContains($scenario->visible->id, $this->ids(search::search('Alpha')));
        $this->assertNotContains($scenario->hidden->id, $this->ids(search::search('Secret')));
        $this->assertNotContains($scenario->hiddencatcourse->id, $this->ids(search::search('Concealed')));
        $this->assertContains($scenario->other->id, $this->ids(search::search('Chemistry')));
    }

    /**
     * The plugin capability is enough on its own, and still respects its context.
     *
     * @covers ::user_can_search
     * @covers ::search
     */
    public function test_plugin_capability_without_viewcourselist(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $user = $this->getDataGenerator()->create_user();
        $systemcontext = context_system::instance();
        $categorycontext = context_coursecat::instance($scenario->category->id);

        $prohibit = create_role('Prohibit course list', 'prohibitqcs', '');
        assign_capability('moodle/category:viewcourselist', CAP_PROHIBIT, $prohibit, $systemcontext->id);
        role_assign($prohibit, $user->id, $systemcontext->id);

        $allow = create_role('Allow course search', 'allowqcs', '');
        assign_capability('block/quickcoursesearch:search', CAP_ALLOW, $allow, $systemcontext->id);
        role_assign($allow, $user->id, $categorycontext->id);

        $this->setUser($user);
        $this->assertTrue(search::user_can_search());
        $this->assertContains($scenario->visible->id, $this->ids(search::search('Alpha')));
        $this->assertNotContains($scenario->hidden->id, $this->ids(search::search('Secret')));
        $this->assertNotContains($scenario->other->id, $this->ids(search::search('Chemistry')));
    }

    /**
     * Users with neither capability cannot search.
     *
     * @covers ::user_can_search
     */
    public function test_user_without_permission_cannot_search(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('No course search', 'noqcs', '');
        $systemcontext = context_system::instance();
        assign_capability('moodle/category:viewcourselist', CAP_PROHIBIT, $roleid, $systemcontext->id);
        assign_capability('block/quickcoursesearch:search', CAP_PROHIBIT, $roleid, $systemcontext->id);
        role_assign($roleid, $user->id, $systemcontext->id);

        $this->setUser($user);
        $this->assertFalse(search::user_can_search());
        $this->expectException(\moodle_exception::class);
        search::search('Alpha');
    }

    /**
     * Short text is rejected. A numeric course id is accepted.
     *
     * @covers ::search
     */
    public function test_short_query(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $this->setAdminUser();

        $short = search::search('a');
        $this->assertTrue($short['tooshort']);
        $this->assertSame([], $short['courses']);

        $byid = $this->ids(search::search((string)$scenario->visible->id));
        $this->assertContains($scenario->visible->id, $byid);
    }

    /**
     * The AJAX function returns a valid structure and rejects users who cannot search.
     *
     * @covers \block_quickcoursesearch\external::search_courses
     */
    public function test_external_search(): void {
        $this->resetAfterTest();
        $scenario = $this->create_scenario();
        $this->setAdminUser();

        $result = external::search_courses('BIO101');
        $clean = external::clean_returnvalue(external::search_courses_returns(), $result);
        $this->assertContains($scenario->visible->id, $this->ids($clean));

        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('No external search', 'noqcsajax', '');
        $systemcontext = context_system::instance();
        assign_capability('moodle/category:viewcourselist', CAP_PROHIBIT, $roleid, $systemcontext->id);
        assign_capability('block/quickcoursesearch:search', CAP_PROHIBIT, $roleid, $systemcontext->id);
        role_assign($roleid, $user->id, $systemcontext->id);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        external::search_courses('Alpha');
    }

    /**
     * The block is rendered for an allowed user and omitted for a user without permission.
     *
     * @covers \block_quickcoursesearch::get_content
     */
    public function test_block_visibility(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->add_block($course);

        $adminpage = $this->load_page($course);
        $content = $this->block_on_page($adminpage)->get_content();
        $this->assertStringContainsString('data-region="quickcoursesearch"', $content->text);
        $this->assertStringContainsString('quickcoursesearch-q-', $content->text);

        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Hide quick search', 'hideqcs', '');
        $systemcontext = context_system::instance();
        assign_capability('moodle/category:viewcourselist', CAP_PROHIBIT, $roleid, $systemcontext->id);
        assign_capability('block/quickcoursesearch:search', CAP_PROHIBIT, $roleid, $systemcontext->id);
        role_assign($roleid, $user->id, $systemcontext->id);
        $this->setUser($user);

        $userpage = $this->load_page($course);
        $block = $this->block_on_page($userpage);
        $this->assertSame('', $block->get_content()->text);
        $this->assertTrue($block->is_empty());
    }

    /**
     * Courses and users shared by the search tests.
     *
     * @return \stdClass
     */
    private function create_scenario(): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => 'Science']);
        $othercategory = $generator->create_category(['name' => 'Arts']);
        $hiddencategory = $generator->create_category([
            'name' => 'Restricted science',
            'parent' => $category->id,
            'visible' => 0,
        ]);

        $visible = $generator->create_course([
            'category' => $category->id,
            'fullname' => 'Alpha Biology',
            'shortname' => 'BIO101',
            'idnumber' => 'ID-BIO-1',
        ]);
        $hidden = $generator->create_course([
            'category' => $category->id,
            'fullname' => 'Secret Lab',
            'shortname' => 'LAB900',
            'idnumber' => 'ID-LAB-9',
            'visible' => 0,
        ]);
        $other = $generator->create_course([
            'category' => $othercategory->id,
            'fullname' => 'Beta Chemistry',
            'shortname' => 'CHEM202',
            'idnumber' => 'ID-CHEM-2',
        ]);
        $otherhidden = $generator->create_course([
            'category' => $othercategory->id,
            'fullname' => 'Secret Studio',
            'shortname' => 'STU1',
            'idnumber' => 'ID-STU-1',
            'visible' => 0,
        ]);
        $hiddencatcourse = $generator->create_course([
            'category' => $hiddencategory->id,
            'fullname' => 'Concealed Seminar',
            'shortname' => 'SEM1',
            'idnumber' => 'ID-SEM-1',
        ]);

        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        assign_capability(
            'block/quickcoursesearch:search',
            CAP_ALLOW,
            $managerroleid,
            context_system::instance()->id,
            true
        );
        $manager = $generator->create_user();
        role_assign($managerroleid, $manager->id, context_coursecat::instance($category->id)->id);

        return (object)[
            'category' => $category,
            'visible' => $visible,
            'hidden' => $hidden,
            'other' => $other,
            'otherhidden' => $otherhidden,
            'hiddencatcourse' => $hiddencatcourse,
            'manager' => $manager,
            'user' => $generator->create_user(),
        ];
    }

    /**
     * Course ids returned by a search.
     *
     * @param array $result
     * @return int[]
     */
    private function ids(array $result): array {
        return array_map(static function(array $course): int {
            return (int)$course['id'];
        }, $result['courses']);
    }

    /**
     * One returned course.
     *
     * @param array $result
     * @param int $courseid
     * @return array
     */
    private function course_by_id(array $result, int $courseid): array {
        foreach ($result['courses'] as $course) {
            if ((int)$course['id'] === $courseid) {
                return $course;
            }
        }
        $this->fail('Course ' . $courseid . ' was not in the search results');
    }

    /**
     * Add the block to a course page.
     *
     * @param \stdClass $course
     */
    private function add_block(\stdClass $course): void {
        $page = $this->load_page($course);
        $page->blocks->add_block_at_end_of_default_region('quickcoursesearch');
    }

    /**
     * Build a course page that can load blocks.
     *
     * @param \stdClass $course
     * @return \moodle_page
     */
    private function load_page(\stdClass $course): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_course($course);
        $page->set_pagelayout('standard');
        $page->set_pagetype('course-view');
        $page->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $page->blocks->load_blocks();
        return $page;
    }

    /**
     * The quick course search block loaded on a page.
     *
     * @param \moodle_page $page
     * @return \block_quickcoursesearch
     */
    private function block_on_page(\moodle_page $page): \block_quickcoursesearch {
        $region = $page->blocks->get_default_region();
        foreach ($page->blocks->get_blocks_for_region($region) as $block) {
            if ($block instanceof \block_quickcoursesearch) {
                return $block;
            }
        }
        $this->fail('Quick course search block was not loaded');
    }
}
