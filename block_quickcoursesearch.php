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
 * Quick course search block.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block that searches courses across the site from a single field.
 *
 * @package    block_quickcoursesearch
 */
class block_quickcoursesearch extends block_base {

    /**
     * Set the default title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_quickcoursesearch');
    }

    /**
     * Use the instance title when one has been configured.
     */
    public function specialization() {
        if (!empty($this->config->title) && trim($this->config->title) !== '') {
            $this->title = format_string($this->config->title, true, ['context' => $this->context]);
        }
    }

    /**
     * One search block is enough on a page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * The block can be placed on any page, including the Dashboard (/my/index.php).
     *
     * my and my-index are set explicitly because the Dashboard checks those formats
     * before offering the block in the add-a-block menu.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'all' => true,
            'my' => true,
            'my-index' => true,
        ];
    }

    /**
     * Expose the block as a search landmark.
     *
     * @return string
     */
    public function get_aria_role() {
        return 'search';
    }

    /**
     * Render the search field and, after a normal form submit, its results.
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (empty($this->instance) || !\block_quickcoursesearch\search::user_can_search()) {
            return $this->content;
        }

        $instanceid = (int)$this->instance->id;
        $query = '';
        if (optional_param('qcsi', 0, PARAM_INT) === $instanceid) {
            $query = optional_param('qcsq', '', PARAM_TEXT);
        }

        $data = \block_quickcoursesearch\search::export_block($instanceid, $query, $this->page->url);
        $this->content->text = $this->page->get_renderer('core')->render_from_template(
            'block_quickcoursesearch/search',
            $data
        );

        return $this->content;
    }
}
