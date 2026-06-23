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
 * View the reports of visits.
 *
 * @package   block_visitsreport
 * @copyright Moodle Dev
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

$heading = get_string('visitsreport', 'block_visitsreport');
$url = new \moodle_url('/blocks/visitsreport/view.php');

require_login();
require_once($CFG->dirroot.'/lib/formslib.php');
require_once($CFG->dirroot.'/blocks/visitsreport/lib.php');

$courseid = optional_param('id', 0, PARAM_INT);
$report = optional_param('report', 'site', PARAM_TEXT);
$download = optional_param('download', '', PARAM_TEXT);

$url->params(['report' => $report]);
$context = \context_system::instance();

if ($report == 'course') {
    $courseid = required_param('id', PARAM_INT);
    $url->params(['id' => $courseid]);
    if (!$course = $DB->get_record('course', ['id' => $courseid])) {
        throw new moodle_exception('coursenotfound', 'block_visitsreport');
    }
    $context = \context_course::instance($courseid);
    require_capability('block/visitsreport:viewcoursereport', $context, $USER->id);
    $PAGE->set_course($course);
}

// Page setup.
$PAGE->set_context($context);
$PAGE->set_pagelayout('base');
$PAGE->set_url($url);
$PAGE->add_body_classes(['visits-report', 'visits-report-'.$report]);
$PAGE->set_heading($heading);
$reportnode = $PAGE->navigation->add(get_string('reportnav', 'block_visitsreport', ['n' => ucwords($report)]), $PAGE->url->out(false) );
$reportnode->make_active();

$capability = block_visitsreport_get_capability_for_reports($report);

$returnurl = optional_param('returnurl', '', PARAM_URL);

require_capability($capability, $context, $USER->id);

if ($download) {
    $reportname = required_param('reportname', PARAM_TEXT);
    $startdate = optional_param_array('startdate', [], PARAM_INT);
    $enddate = optional_param_array('enddate', [], PARAM_INT);
    $userid = optional_param('userid', 0, PARAM_INT);
    $department = optional_param('department', '', PARAM_TEXT);
    if ($reportname == 'course_visits') {
        $courseid = required_param('courseid', PARAM_INT);
        if (!$course = $DB->get_record('course', ['id' => $courseid])) {
            throw new moodle_exception('coursenotfound', 'block_visitsreport');
        }
        $context = \context_course::instance($courseid);
        require_capability('block/visitsreport:viewcoursereport', $context, $USER->id);
        $PAGE->set_course($course);
    }
}

if (!$download) {
    // PAGE output starts here.
    echo $OUTPUT->header();
    if ($returnurl && isset($SESSION)) {
        $SESSION->wantsurl = $returnurl;
    }
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'id' => 'contextid', 'value' => $context->id]);
}

$visitreport = new \block_visitsreport\report($report, $courseid, $download);
if ($download) {
    if (!empty($startdate)) {
        $filter = ['startdate' => $startdate, 'enddate' => $enddate, 'userid' => $userid];
        $visitreport->set_filter($filter);
    }
    if (!empty($userid)) {
        $visitreport->set_user($userid);
    }
    if (!empty($department)) {
        $visitreport->set_departmentfilter($department);
    }
    if (method_exists($visitreport, $reportname)) {
        $visitreport->$reportname();
    }
} else {
    // $visitreport->set_user(3);
    echo $visitreport->display();

    echo $OUTPUT->footer();
}

