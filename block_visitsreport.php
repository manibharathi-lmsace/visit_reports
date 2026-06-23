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
 * Block visits reports base.
 *
 * @package   block_visitsreport
 * @copyright Moodle Dev
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die('No Direct Access');

class block_visitsreport extends block_base {

    /**
     * Initialize block instance.
     *
     * @throws coding_exception
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_visitsreport');
    }

    /**
     * This block supports configuration fields.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * List of links to access the reports displayed on the blocks.
     *
     * @return object $content
     */
    public function get_content() {
        global $PAGE, $USER, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        if (empty($this->instance)) {
            return '';
        }

        $this->content = new \stdClass();

        $viewurl = new moodle_url('/blocks/visitsreport/view.php');

        $context = context_system::instance();
        $data = ['returnurl' => $PAGE->url->out(false)];
        // Site reports.
        $data['sitereport'] = (has_capability('block/visitsreport:viewsitereport', $context, $USER->id)) ? 1 : 0;
        $viewurl->params(['report' => 'site']);
        $data['sitereporturl'] = $viewurl->out(false);
        // Users Report.
        $params = ['report' => 'users'];
        if ($PAGE->pagelayout == 'course' || $PAGE->pagelayout == 'incourse')  {
            $params['id'] = $PAGE->course->id;
        }
        $viewurl->params($params);
        $data['usersreport'] = (has_capability('block/visitsreport:viewusersreport', $context, $USER->id)) ? 1 : 0;
        $data['usersreporturl'] = $viewurl->out(false);
        // Course reports.
        if ($PAGE->pagelayout == 'course' || $PAGE->pagelayout == 'incourse') {
            $courseid = $PAGE->course->id;
            $coursecontext = \context_course::instance($courseid);
            $data['coursereport'] = (has_capability('block/visitsreport:viewcoursereport', $coursecontext, $USER->id)) ? 1 : 0;
            $data['iscoursepage'] = true;
            $viewurl->params(['report' => 'course', 'id' => $courseid]);
            $data['coursereporturl'] = $viewurl->out(false);
        }

        // Centralized Reports.
        $params = ['report' => 'centralised_reports'];
        $viewurl->params($params);
        $data['centralizereport'] = (has_capability('block/visitsreport:viewcentralizereport', $context, $USER->id)) ? 1 : 0;
        $data['centralizereporturl'] = $viewurl->out(false);

        // User&Department Reports.
        $params = ['report' => 'userdepartment'];
        $viewurl->params($params);
        $data['userdepartment'] = (has_capability('block/visitsreport:viewuserdepartmentreport', $context, $USER->id)) ? 1 : 0;
        $data['userdepartmenturl'] = $viewurl->out(false);

        // Most accessed activities Reports.
        $params = ['report' => 'accessactivities'];
        $viewurl->params($params);
        $data['accessactivities'] = (has_capability('block/visitsreport:viewaccessactivitiesreport', $context, $USER->id)) ? 1 : 0;
        $data['accessactivitiesurl'] = $viewurl->out(false);

        // Most accessed courses Reports.
        $params = ['report' => 'accesscourses'];
        $viewurl->params($params);
        $data['accesscourses'] = (has_capability('block/visitsreport:viewaccesscoursesreport', $context, $USER->id)) ? 1 : 0;
        $data['accesscoursesurl'] = $viewurl->out(false);

        $this->content->text = $OUTPUT->render_from_template('block_visitsreport/content', $data);
        return $this->content;
    }

    /**
     * Dashes are suitable on all page types.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['all' => true];
    }
}
