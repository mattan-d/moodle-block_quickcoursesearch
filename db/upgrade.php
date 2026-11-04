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
 * Upgrade steps for block_quickcoursesearch.
 *
 * @package    block_quickcoursesearch
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Grant dashboard placement to roles that can already edit a Dashboard.
 *
 * Archetype changes in access.php apply only on first install.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_quickcoursesearch_upgrade($oldversion) {
    if ($oldversion < 2026110400) {
        $systemcontext = context_system::instance();
        $roleids = [];
        foreach (['moodle/my:manageblocks', 'moodle/my:configsyspages'] as $capability) {
            $roles = get_roles_with_capability($capability, CAP_ALLOW);
            foreach ($roles as $role) {
                $roleids[$role->id] = $role->id;
            }
        }
        foreach ($roleids as $roleid) {
            assign_capability(
                'block/quickcoursesearch:myaddinstance',
                CAP_ALLOW,
                $roleid,
                $systemcontext->id,
                true
            );
        }

        upgrade_plugin_savepoint(true, 2026110400, 'block', 'quickcoursesearch');
    }

    return true;
}
