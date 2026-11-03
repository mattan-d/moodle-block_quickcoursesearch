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
 * Instance configuration for the quick course search block.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Optional title for one block instance.
 *
 * @package    block_quickcoursesearch
 */
class block_quickcoursesearch_edit_form extends block_edit_form {

    /**
     * Extra instance settings.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));
        $mform->addElement('text', 'config_title', get_string('configtitle', 'block_quickcoursesearch'), ['size' => 40]);
        $mform->setType('config_title', PARAM_TEXT);
    }
}
