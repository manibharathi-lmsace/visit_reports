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
 * Version information
 *
 * @package   block_visitsreport
 * @copyright Moodle Dev
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_visitsreport;

use DateTime;
use html_writer;
use moodle_exception;
use grade_item;

require_once($CFG->dirroot. '/lib/tablelib.php');
/**
 * Reports proccess.
 */
class report {

    public $method;

    public $is_timetracking_available = false;

    public $filter = [];

    public $_customdata = ['startdate' => [], 'enddate' => []];

    public $userid = '';

    public $department = '';

    public $download = '';

    /**
     * Constructor.
     *
     * @param string $method
     * @param int|null $courseid
     */
    function __construct($method='site', $courseid=null, $download = false) {
        global $DB;
        $dbman = $DB->get_manager();
        $this->method = $method;
        $this->set_log_table();
        $this->is_timetracking_available = $dbman->table_exists('local_visits_track');
        $this->download = $download;
        $this->courseid = $courseid;
        $this->_customdata['courseid'] = $courseid;
        $this->_customdata['report'] = $method;
    }

    function set_filter($filter) {
        $this->_customdata['startdate'] = $filter['startdate'] ?: [];
        $this->_customdata['enddate'] = $filter['enddate'] ?: [];


        if (!empty($filter) && isset($filter['startdate']) ) {
            array_walk($filter, function(&$value, $key) {
                if ( !isset($value['day']) ) {
                    return;
                }
                $date = $value['day'].'-'.$value['month'].'-'.$value['year'];
                $date = new DateTime($date);
                $value = $date;
            });
            $filter['start_timestamp'] = $filter['startdate']->getTimeStamp();
            $filter['end_timestamp'] = strtotime("tomorrow", $filter['enddate']->getTimeStamp()) - 1;

        } else {
            $filter = [];
        }
        $this->filter = $filter;
    }

    function set_user($userid) {
        $this->userid = $userid;
        $this->_customdata['userid'] = $userid ?: '';
    }

    function set_departmentfilter($department) {
        $this->department = $department;
        $this->_customdata['department'] = $department ?: '';
    }
    /**
     * Fetch the report content based on the report method.
     *
     * @return void
     */
    public function display() {
        global $PAGE;
        if ($this->method == 'users') {
            $output = $this->top_visiters();
            $output .= $this->top_departmentusers();
            if ($this->is_timetracking_available) {
                $output .= $this->users_timespent();
            }
        } else if ($this->method == 'course') {
            $output = $this->course_visits($this->courseid);
        } else if ($this->method == 'centralised_reports') {
            $output = $this->centralised_reports();
        } else if ($this->method == 'userdepartment') {
            $output = $this->userdepartment_reports();
        } else if ($this->method == 'accessactivities') {
            $output = $this->activities_timespent();
        } else if ($this->method == 'accesscourses') {
            if ($this->is_timetracking_available) {
                $output = $this->courses_timespent();
            }
        } else {
            $output = $this->site_reports();
        }
        $PAGE->requires->data_for_js('visitspagedata', ['courseid' => $this->courseid]);
        $PAGE->requires->js_call_amd('block_visitsreport/visits', 'init', []);
        return $output;
    }

    /**
     * Get userdepartments reports.
     */
    public function userdepartment_reports() {
        $output = $this->user_courseactivityreports();
        $output .= $this->departmentuser_timespentreports();
        return $output;
    }

    public function user_courseactivityreports() {
        global $DB, $CFG;
        list($insql, $inparams) = $this->find_adminuser();
        $params = ['userid' => $this->userid];
        $filtersql = '';
        $data = [];
        if (!empty($this->filter)) {
            $filtersql = ' AND lvt.timecreated BETWEEN :timestart and :timeend ';
            $params += ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }
        $title = get_string('user_courseactivity', 'block_visitsreport');
        $heading = get_string('user_courseactivity_heading', 'block_visitsreport');
        $reporthtml = '';
        $tablereport = '';
        if ($this->userid) {
            $sql = "SELECT lvt.cmid AS cm, cm.instance, m.name AS module, c.fullname AS coursename, c.id AS courseid, lvt.timecreated, SUM(lvt.timespent) as timespent
            FROM {local_visits_track} lvt
            JOIN {course} c ON c.id = lvt.courseid
            JOIN {course_modules} cm ON cm.id = lvt.cmid
            JOIN {modules} m ON m.id = cm.module
            WHERE lvt.userid = :userid
            AND lvt.userid ".$insql;
            $sql .= $filtersql;
            $sql .= "GROUP BY lvt.cmid ORDER BY timespent DESC";
            $reports = $DB->get_records_sql($sql, $params + $inparams, 0, 20);
            if ($reports) {
                foreach ($reports as $report) {
                    $report->name = $DB->get_field($report->module, "name", array('id' => $report->instance));
                    $data[$report->courseid]['coursename'] = $report->coursename;
                    $data[$report->courseid]['activities'][] = $report;
                }
                $tabledata = [];
                foreach ($data as $courseid => $courseinfo) {
                    foreach ($courseinfo['activities'] as $modinfo) {
                        $list = $this->activity_user_states($modinfo, 'centralised_reports', $courseid, true);
                        $tabledata = array_merge($tabledata, $list);
                    }
                }

                if ($this->download && $modcourse = optional_param('modcourse', 0, PARAM_INT)) {
                    $data = array($modcourse => $data[$modcourse]);
                    $reporthtml .= $this->course_activity_accordion($data, 'activity_user_states', 'user_courseactivityreports');
                } else if (!$this->download) {
                    $reporthtml .= $this->course_activity_accordion($data, 'activity_user_states', 'user_courseactivityreports');
                }
            } else {
                $reporthtml .= html_writer::start_div('alert alert-info alert-block fade in report-not-available');
                $reporthtml .= html_writer::tag('p', get_string('neveraccesseduser', 'block_visitsreport'), ['class' => 'report-data-not']);
                $reporthtml .= html_writer::end_div();
            }
            $alabel = get_string('course');
            $blabel = get_string('fullname');
            $clabel = get_string('activity');
            $dlabel = get_string('numofclicks', 'block_visitsreport');
            $elabel = get_string('timespent', 'block_visitsreport');
            $heads = [$alabel, $blabel, $clabel, $dlabel, $elabel];
            $col = ['coursefullname', 'userfullname', 'modulename', 'user_activityclicks', 'user_timespent'];
            $tablereport = $this->visitsreport_table('user_courseactivityreports' , $tabledata, '', $col, $heads);

        } else {
            $reporthtml .= html_writer::start_div('alert alert-info alert-block fade in report-not-available');
            $reporthtml .= html_writer::tag('p', get_string('selectusertoshowreport', 'block_visitsreport'), ['class' => 'report-data-not']);
            $reporthtml .= html_writer::end_div();
        }
        $content = $this->generate_header('user_courseactivityreports', $title, $heading, $reporthtml, $tablereport);
        return $content;
    }

    public function activity_user_states($modinfo, $reportname, $courseid, $returndata = false) {
        $timespent = block_visitsreport_user_activity_timespent($modinfo->cm, $this->userid);
        $activityclick = block_visitsreport_user_activity_clicks($modinfo->cm, $this->userid);
        $alabel = get_string('fullname');
        $blabel = get_string('activity');
        $clabel = get_string('numofclicks', 'block_visitsreport');
        $dlabel = get_string('timespent', 'block_visitsreport');
        $record = new \stdClass();
        $record->timespent = (int) $timespent;
        $record->activityclick = (int) $activityclick;
        $user = \core_user::get_user($this->userid);
        $record->firstname = $user->firstname;
        $record->lastname = $user->lastname;
        $record->cm = $modinfo->cm;
        $record->name = $modinfo->name;
        $record->coursename = $modinfo->coursename;
        $records = [];
        $records[] = $record;
        if ($returndata) {
            return $records;
        }
        $reporttable = $this->visitsreport_table($reportname, $records, '',
            ['userfullname', 'modulename', 'user_activityclicks', 'user_timespent'], [$alabel, $blabel, $clabel, $dlabel]);
        return $reporttable;
    }

    public function course_activity_accordion($data, $activityreportfunction, $reportname) {
        global $OUTPUT;
        $reporthtml = '';
        $reporthtml .= html_writer::start_div('course-info-block');
        foreach ($data as $courseid => $courseinfo) {
            $courseicon = $OUTPUT->image_url('courses', 'block_myoverview')->out();
            $reporthtml .= html_writer::start_div('info-block');
            $reporthtml .= html_writer::start_div('accordion-course-block', ['id' => "accordion-course"]);
            $reporthtml .= html_writer::start_div('card'); // Start course card
            // Course accordion header.
            $reporthtml .= html_writer::start_div('card-header', ['id' => "course-head-$courseid"]); // Start course card-header
                $reporthtml .= html_writer::start_tag('h5', array('class' => 'mb-0'));
                $reporthtml .= html_writer::start_tag('button', array('class' => 'btn btn-link collapsed', 'data-toggle' => 'collapse',
                    'data-target' => "#course-content-$courseid", "aria-expanded" => "false", "aria-controls" => "course-content-$courseid"));
                    $reporthtml .= html_writer::start_div('course-img-block');
                    $reporthtml .= html_writer::empty_tag('img', array('src' => $courseicon, 'height' => 50, 'width' => 35));
                    $reporthtml .= html_writer::end_div();
                    $reporthtml .= $courseinfo['coursename'];
                $reporthtml .= html_writer::end_tag('button');
                $reporthtml .= html_writer::end_tag('h5');
            $reporthtml .= html_writer::end_div(); // End course card-header

            // Course accordion content.
            $reporthtml .= html_writer::start_div('collapse', ['id' => "course-content-$courseid", "aria-labelledby" => "course-head-$courseid",
                'data-parent' => "#accordion-course"]); // Start course card-content
                $reporthtml .= html_writer::start_div('card-body');
                $reporthtml .= html_writer::start_div("course-info-download");
                    $coursedata = [];
                    foreach ($courseinfo['activities'] as $modinfo) {
                        $list = $this->$activityreportfunction($modinfo, $reportname, $courseid, true);
                        $coursedata = array_merge($coursedata, $list);
                    }
                    if (!empty($coursedata)) {
                        if ($reportname == 'centralised_reports') {
                            $alabel = get_string('course');
                            $blabel = get_string('activity');
                            $clabel = get_string('firstname');
                            $dlabel = get_string('lastname', 'block_visitsreport');
                            $elabel = get_string('email');
                            $flabel = get_string('department');
                            $glabel = get_string('timespent', 'block_visitsreport');
                            $hlabel = get_string('numofclicks', 'block_visitsreport');
                            $col = ['coursefullname', 'modulename', 'firstname', 'lastname', 'email', 'department', 'user_timespent', 'numofclicks'];
                            $heads = [$alabel, $blabel, $clabel, $dlabel, $elabel, $flabel, $glabel, $hlabel];
                        } else if ($reportname == 'user_courseactivityreports') {
                            $alabel = get_string('course');
                            $blabel = get_string('fullname');
                            $clabel = get_string('activity');
                            $dlabel = get_string('numofclicks', 'block_visitsreport');
                            $elabel = get_string('timespent', 'block_visitsreport');
                            $heads = [$alabel, $blabel, $clabel, $dlabel, $elabel];
                            $col = ['coursefullname', 'userfullname', 'modulename', 'user_activityclicks', 'user_timespent'];
                        }
                        $baseurl = $this->get_baseurl($reportname, array('modcourse' => $courseid));
                        $reporthtml .= $this->visitsreport_table($reportname , $coursedata, '', $col, $heads, '', $baseurl);
                    }
                $reporthtml .=  html_writer::end_div();
                $reporthtml .= html_writer::start_div('modules-info-block'); // Start modules block
                foreach ($courseinfo['activities'] as $modinfo) {
                    $modicon = $OUTPUT->image_icon('icon', get_string('modulename', $modinfo->mod), $modinfo->mod);
                    $reporthtml .= html_writer::start_div('info-block');
                    $reporthtml .= html_writer::start_div('accordion-mod-block', ['id' => "accordion-mod"]); // start Mod accordion.
                    $reporthtml .= html_writer::start_div('card'); // Start mod card.
                        // Module accordion header.
                        $reporthtml .= html_writer::start_div('card-header', ['id' => "mod-head-$modinfo->cm"]); // Start module card-header
                        $reporthtml .= html_writer::start_tag('h5', array('class' => 'mb-0'));
                        $reporthtml .= html_writer::start_tag('button', array('class' => 'btn btn-link collapsed', 'data-toggle' => 'collapse',
                            'data-target' => "#mod-content-$modinfo->cm", "aria-expanded" => "false", "aria-controls" => "mod-content-$modinfo->cm"));
                            $reporthtml .= $modicon;
                            $reporthtml .= $modinfo->name;
                        $reporthtml .= html_writer::end_tag('button');
                        $reporthtml .= html_writer::end_tag('h5');
                        $reporthtml .= html_writer::end_div(); // End mod card-header

                        // Course accordion content.
                        $reporthtml .= html_writer::start_div('collapse', ['id' => "mod-content-$modinfo->cm", "aria-labelledby" => "mod-head-$modinfo->cm",
                        'data-parent' => "#accordion-mod"]); // Start mod card-content
                        $reporthtml .= html_writer::start_div('card-body mod-toggle');
                            $reporthtml .= $this->$activityreportfunction($modinfo, $reportname, $courseid);
                        $reporthtml .= html_writer::end_div(); // End mod card-content.
                        $reporthtml .= html_writer::end_div();

                    $reporthtml .= html_writer::end_div();
                    $reporthtml .= html_writer::end_div(); // End mod accordion.
                    $reporthtml .= html_writer::end_div();
                }
                $reporthtml .= html_writer::end_div();// End modules block
                $reporthtml .= html_writer::end_div();
            $reporthtml .= html_writer::end_div(); // End course card-content

            $reporthtml .= html_writer::end_div(); // End course card
            $reporthtml .= html_writer::end_div();
            $reporthtml .= html_writer::end_div();
        }
        $reporthtml .= html_writer::end_div();
        return $reporthtml;
    }


    public function activities_timespent() {
        global $DB;
        list($insql, $inparams) = $this->find_adminuser();
        $params = [];
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND lvt.timecreated BETWEEN :timestart and :timeend ';
            $params = ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }

        $sql = "SELECT lvt.cmid AS cm, cm.instance, m.name AS module, c.fullname AS coursename, lvt.timecreated, SUM(lvt.timespent) as timespent
            FROM {local_visits_track} lvt
            JOIN {course} c ON c.id = lvt.courseid
            JOIN {course_modules} cm ON cm.id = lvt.cmid
            JOIN {modules} m ON m.id = cm.module
            WHERE lvt.courseid != 1 AND lvt.userid ".$insql;
        $sql .= $filtersql;
        $sql .= " GROUP BY lvt.cmid ORDER BY timespent DESC";
        $records = $DB->get_records_sql($sql, $params + $inparams, 0, 20 );
        if ($records) {
            array_map(function($record) {
                global $DB;
                $record->name = $DB->get_field($record->module, "name", array('id' => $record->instance));
            }, $records);
        }
        $xlabel = get_string('timespent', 'block_visitsreport');
        $ylabel = get_string('activity');
        $zlabel = get_string('course');
        if ($records) {
            $reporttable = $this->visitsreport_table('activities_timespent', $records, '',
                ['coursefullname', 'modulename', 'user_timespent'], [$zlabel, $ylabel, $xlabel], '', '', TABLE_P_TOP);
        } else {
            $reporttable = html_writer::start_div('alert alert-info alert-block fade in report-not-available');
            $reporttable .= html_writer::tag('p', get_string('nodatafound', 'block_visitsreport'), ['class' => 'report-data-not']);
            $reporttable .= html_writer::end_div();
        }
        $title = get_string('modulespent', 'block_visitsreport');
        $heading = get_string('modulespent_heading', 'block_visitsreport');
        return $this->generate_table('activities_timespent', $title, $heading, $reporttable);
    }

    public function courses_timespent() {
        global $DB, $PAGE;
        list($insql, $inparams) = $this->find_adminuser();
        $params = [];
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND lvt.timecreated BETWEEN :timestart and :timeend ';
            $params = ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }

        $sql = "SELECT lvt.courseid, c.fullname AS coursename, lvt.timecreated, SUM(lvt.timespent) as timespent
            FROM {local_visits_track} lvt
            INNER JOIN {course} c ON c.id = lvt.courseid
            WHERE lvt.courseid != 1 AND lvt.userid ".$insql;
        $sql .= $filtersql;
        $sql .= " GROUP BY lvt.courseid ORDER BY timespent DESC";

        $labelfunction = (function($value) use (&$labels) {
            $labels[] = $value->coursename;
        });
        $xlabel = get_string('timespent', 'block_visitsreport');
        $ylabel = get_string('course');
        $records = $DB->get_records_sql($sql, $params + $inparams, 0, 20 );
        // Convert the seconds to minutes.
        $visits = [];
        $timespent = array_column($records, 'timespent');
        if (!empty($timespent)) {
            array_walk($timespent, function(&$value) {
                if (!empty($value)) {
                    $value = (int) $value;
                    $zero    = new \DateTime("@0");
                    $offset  = new \DateTime("@$value");
                    $diff    = $zero->diff($offset)->format('%H.%I');
                    $value   = $diff;
                }
            });
            $visits  = new \core\chart_series($xlabel, $timespent);
        }
        $labels = [];
        array_walk($records, $labelfunction);
        $title = get_string('coursespent', 'block_visitsreport');
        $heading = get_string('coursespent_heading', 'block_visitsreport');
        $reporttable = $this->visitsreport_table('courses_timespent', $records, '', ['user_timespent', 'coursefullname'], [$xlabel, $ylabel]);
        return $this->generate_chart($visits, $labels, 'courses_timespent', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * Get the user courses reports.
     */
    public function departmentuser_timespentreports() {
        global $DB;
        list($insql, $inparams) = $this->find_adminuser();
        $params = [];
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND lvt.timecreated BETWEEN :timestart and :timeend ';
            $params = ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }
        $sql = "SELECT lvt.userid, u.firstname, u.lastname, u.email, u.department, SUM(lvt.timespent) as timespent
            FROM {local_visits_track} lvt
            INNER JOIN {user} u ON u.id = lvt.userid
            WHERE lvt.userid ".$insql;
        $sql .= $filtersql;
        if (!empty($this->department)) {
            $sql .= ' AND u.department = :department';
            $params['department'] = $this->department;
        } else {
            $sql .= "AND u.department != ''";
        }

        $sql .= " GROUP BY lvt.userid ORDER BY timespent DESC";
        $records = $DB->get_records_sql($sql, $params + $inparams, 0, 20 );
        $alabel = get_string('firstname');
        $blabel = get_string('lastname', 'block_visitsreport');
        $clabel = get_string('email');
        $dlabel = get_string('department');
        $elabel = get_string('timespent', 'block_visitsreport');
        $reporttable = $this->visitsreport_table('departmentuser_timespentreports', $records, '',
            ['firstname', 'lastname', 'email', 'department', 'user_timespent'], [$alabel, $blabel, $clabel, $dlabel, $elabel], '', '', TABLE_P_TOP);
        $title = get_string('departmentuser_timespent', 'block_visitsreport');
        $heading = get_string('departmentuser_timespent_heading', 'block_visitsreport');
        return $this->generate_table('departmentuser_timespentreports', $title, $heading, $reporttable);
    }

    /**
     * Get centralize reports.
     */
    public function centralised_reports() {
        global $OUTPUT, $CFG;
        require_once($CFG->dirroot. "/course/lib.php");
        $reporthtml = "";
        $page = optional_param('page', 0, PARAM_INT);
        $perpage = optional_param('perpage', 10, PARAM_INT);
        $totalcourses = \core_course_category::get(0)->get_courses(array('recursive' => true));
        $reporthtml .= $OUTPUT->paging_bar(count($totalcourses), $page, $perpage, $this->get_baseurl('centralised_reports'));
        $courses = \core_course_category::get(0)->get_courses(
            array(
                'recursive' => true,
                'offset' => $page * $perpage,
                'limit' => $perpage
            )
        );
        $data = [];
        if ($courses) {
            foreach ($courses as $course) {
                $list = [];
                $list['coursename'] = $course->get_formatted_fullname();
                $activities = get_array_of_activities($course->id);
                $list['activities'] = $activities;
                $data[$course->id] = $list;
            }
            $tabledata = [];
            foreach ($data as $courseid => $courseinfo) {
                foreach ($courseinfo['activities'] as $modinfo) {
                    $list = $this->course_activity_user($modinfo, 'centralised_reports', $courseid, true);
                    $tabledata = array_merge($tabledata, $list);
                }
            }
            if ($this->download && $modcourse = optional_param('modcourse', 0, PARAM_INT)) {
                $data = array($modcourse => $data[$modcourse]);
                $reporthtml .= $this->course_activity_accordion($data, 'course_activity_user', 'centralised_reports');
            } else if (!$this->download) {
                $reporthtml .= $this->course_activity_accordion($data, 'course_activity_user', 'centralised_reports');
            }
        }
        $title = get_string('centralised_reports', 'block_visitsreport');
        $heading = get_string('centralised_reports_heading', 'block_visitsreport');
        $alabel = get_string('course');
        $blabel = get_string('activity');
        $clabel = get_string('firstname');
        $dlabel = get_string('lastname', 'block_visitsreport');
        $elabel = get_string('email');
        $flabel = get_string('department');
        $glabel = get_string('timespent', 'block_visitsreport');
        $hlabel = get_string('numofclicks', 'block_visitsreport');
        $col = ['coursefullname', 'modulename', 'firstname', 'lastname', 'email', 'department', 'user_timespent', 'numofclicks'];
        $heads = [$alabel, $blabel, $clabel, $dlabel, $elabel, $flabel, $glabel, $hlabel];
        $tablereport = $this->visitsreport_table('centralised_reports' , $tabledata, '', $col, $heads);
        return $this->generate_header('centralised_reports', $title, $heading, $reporthtml, $tablereport);
    }

    public function course_activity_user($modinfo, $reportname, $courseid, $returndata = false, $showmore = true ) {
        global $DB, $CFG;
        require_once($CFG->dirroot."/lib/grade/grade_item.php");
        require_once($CFG->dirroot."/lib/grade/constants.php");
        $logfiltersql = '';
        $visitstrackfiltersql = '';
        $gradefiltersql = '';
        $inparams = [];
        $coursecontext = \context_course::instance($courseid);
        if (!empty($this->filter)) {
            $logfiltersql = ' AND lg.timecreated BETWEEN :startdate AND :enddate ';
            $visitstrackfiltersql = ' AND lvt.timecreated BETWEEN :starttime AND :endtime ';
            $gradefiltersql = 'AND g.timemodified BETWEEN :timestart AND :timeend';
            $inparams += [
                'startdate' => $this->filter['start_timestamp'],
                'enddate' => $this->filter['end_timestamp'],
                'starttime' => $this->filter['start_timestamp'],
                'endtime' => $this->filter['end_timestamp'],
                'timestart' => $this->filter['start_timestamp'],
                'timeend' => $this->filter['end_timestamp'],
            ];
        }
        $studentparams = [];
        $studentsql = '';
        $options = array('itemtype' => 'mod',
                        'itemmodule' => $modinfo->mod,
                        'iteminstance' => $modinfo->id,
                        'courseid' => $courseid,
                        'itemnumber' => 0);
        $gradeitem = grade_item::fetch_all($options);
        $studentroles = get_archetype_roles('student');
        $studentroles = array_keys($studentroles);
        list($studentsql, $studentparams) = $DB->get_in_or_equal($studentroles, SQL_PARAMS_NAMED);
        // Grade activity.
        //if ($gradeitem) {

            /* $gradeitem = current($gradeitem);
            $sql = "SELECT u.id AS userid, c.fullname AS coursename, u.firstname, u.lastname, u.email, u.department, SUM(lvt.timespent) AS timespent,
            (SELECT count(lg.id)
                FROM {logstore_standard_log} lg WHERE lg.userid = g.userid AND lg.action = 'viewed'
            AND lg.target='course_module' AND lg.objectid = :modinstance $logfiltersql GROUP BY lg.userid) As views
            FROM {grade_grades} g
            JOIN {user} u ON u.id = g.userid
            JOIN {local_visits_track} lvt ON lvt.userid = u.id AND lvt.cmid = :cmid $visitstrackfiltersql
            JOIN {course} c ON c.id = lvt.courseid
            JOIN {role_assignments} r ON r.userid = lvt.userid AND r.contextid = :coursecontext AND r.roleid $studentsql
            WHERE g.itemid = :itemid $gradefiltersql
            GROUP BY g.userid ORDER BY g.finalgrade DESC";
            $params = array('cmid' => $modinfo->cm, 'itemid' => $gradeitem->id,
            'modinstance' => $modinfo->id, 'coursecontext' => $coursecontext->id);
            $params += $inparams;
            $params += $studentparams;
            $records = $DB->get_records_sql($sql, $params); */
        ///} else {
            $sql = "SELECT lg.userid, c.fullname AS coursename, u.firstname, u.lastname, u.email, u.department, count(lg.id) As views,
            (SELECT SUM(lvt.timespent) FROM {local_visits_track} lvt WHERE lvt.userid = lg.userid
            AND lvt.cmid = :cmid $visitstrackfiltersql GROUP BY lvt.userid) AS timespent
            FROM {logstore_standard_log} lg
            JOIN {user} u ON u.id = lg.userid
            JOIN {course} c ON c.id = lg.courseid
            JOIN {role_assignments} r ON r.userid = lg.userid AND r.contextid = :coursecontext AND r.roleid $studentsql
            WHERE lg.action = 'viewed' AND lg.target='course_module' AND lg.objectid = :moduleinstance
            AND lg.contextinstanceid = :cm AND lg.courseid = :courseid $logfiltersql
            GROUP BY lg.userid ORDER BY count(lg.id) DESC";
            $params = [
                'moduleinstance' => $modinfo->id,
                'cmid' => $modinfo->cm,
                'cm' => $modinfo->cm,
                'courseid' => $courseid,
                'coursecontext' => $coursecontext->id
            ];
            $params += $inparams;
            $params += $studentparams;
            $records = $DB->get_records_sql($sql, $params);
        //}
        if ($records) {
            array_map(function($record) use ($modinfo) {
                $record->name = $modinfo->name;
                $record->cm = $modinfo->cm;
            }, $records);
        }
        if ($returndata) {
            return $records;
        }
        $reporttable = html_writer::start_div('mod-toggle-block', array("id" => "mod-toggle-$modinfo->cm"));
        if (!empty($records)) {
            $totalres = count($records);
            $morecontent = '';
            $attr = [
                'class' => 'show-more-action',
                'data-course' => $courseid,
                'data-cm' => $modinfo->cm,
                'data-modinstance' => $modinfo->id,
                'data-modname' => $modinfo->mod,
                'data-showmore' => $showmore
            ];
            if ($showmore && !$this->download) {
                if ($totalres > 20) { // Check more then 20.
                    $morecontent .= html_writer::start_div('show-more-table');
                    $morecontent .= html_writer::link('#', get_string('showmore', 'block_visitsreport'), $attr);
                    $morecontent .= html_writer::end_div();
                }
                $records = array_slice($records, 0, 20);
            } else {
                $morecontent .= html_writer::start_div('show-more-table');
                $morecontent .= html_writer::link('#', get_string('showless', 'block_visitsreport'), $attr);
                $morecontent .= html_writer::end_div();
            }
            $alabel = get_string('firstname');
            $blabel = get_string('lastname', 'block_visitsreport');
            $clabel = get_string('email');
            $dlabel = get_string('department');
            $elabel = get_string('timespent', 'block_visitsreport');
            $flabel = get_string('numofclicks', 'block_visitsreport');
            $baseurl = $this->get_baseurl('centralised_reports', ['modcourse' => $courseid, 'modcm' => $modinfo->cm]);
            $reporttable .= $this->visitsreport_table('centralised_reports', $records, '',
                ['firstname', 'lastname', 'email', 'department', 'user_timespent', 'numofclicks'],
                [$alabel, $blabel, $clabel, $dlabel, $elabel, $flabel], $morecontent, $baseurl);
        } else {
            $reporttable .= html_writer::start_tag('div', array('id' => 'reports-info-block'));
            $reporttable .= html_writer::tag('h2', get_string('notaccessed', 'block_visitsreport'));
            $reporttable .= html_writer::end_tag('div');
        }
        $reporttable .= html_writer::end_div();
        return $reporttable;
    }

    /**
     * Sql queries to find the admin user to remove from the report data fetch.
     *
     * @return array Sql and Parameters.
     */
    public function find_adminuser() {
        global $CFG, $DB;
        $siteadmins = explode(',', $CFG->siteadmins);
        list($insql, $inparams) = $DB->get_in_or_equal($siteadmins, SQL_PARAMS_NAMED, '', false);
        return [ $insql, $inparams];
    }

    /**
     * Find the global log table to fetch data from.
     *
     * @return void
     */
    public function set_log_table() {

		$logmanager = get_log_manager();
	    $readers = $logmanager->get_readers();
	  	$reader = reset($readers);
		$this->logtable = $reader->get_internal_log_table_name();
		$this->maxseconds = 150 * 3600 * 24;
	}

    /**
     * Get list of last 12 months from today. It returns the array of timestamp for each month first date.
     *
     * @return array List of last months.
     */
    public function get_last_months() {
        $endtime = (isset($this->filter['end_timestamp']) && !empty($this->filter['end_timestamp'])) ? $this->filter['end_timestamp'] : time();
        $month = $endtime;
        // Find the month counts between start and end dates filter.
        $howmanymonths = 12;
        if (!empty($this->filter) && $this->filter['startdate'] instanceof DateTime) {
            $diffmonths = $this->filter['enddate']->diff($this->filter['startdate']);
            if (isset($diffmonths->m)) {
                $howmanymonths = (($diffmonths->y) * 12) + ($diffmonths->m);
            }
        }

        $months[0] =  strtotime('tomorrow', strtotime( date('r', $endtime) )) -1;
        for ($i = 1; $i <= $howmanymonths; $i++) {
            $month = strtotime('last month', $month);
            $months[] = strtotime(date("r", $month));
        }
        if (count($months) == 1) {
            $months[] = $this->filter['start_timestamp'];
        }
        return $months;
    }


    /**
     * Site visits reports available report charts are listed here.
     *
     * @return string Report of site visits and most visited courses.
     */
    public function site_reports() {
        $report = $this->visits_record();
        $report .= $this->most_visited_courses();
        if ($this->is_timetracking_available) {
            $report .= $this->timespent_bydepartment();
        }
        return $report;
    }

    /**
     * List of visits in the site per month report data fetching from logs table.
     *
     * @return string Chart content of the report.
     */
    public function visits_record() {
        global $DB, $OUTPUT;

        $months = $this->get_last_months();
        list($insql, $inparams) = $this->find_adminuser();
        $sql = "SELECT MONTH(FROM_UNIXTIME(`timecreated`)) as createdmonth, timecreated, COUNT(*) as visits
            FROM {".$this->logtable."} WHERE
            timecreated BETWEEN :timestart and :timeend
            AND action='viewed'
            AND userid ".$insql."
            GROUP BY MONTH(FROM_UNIXTIME(`timecreated`))
        ";
        $params = [
            'timestart' => end($months),
            'timeend' => reset($months)
        ];
        if (!empty($this->filter)) {
            $params = ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }

        $report = $DB->get_records_sql($sql, $params + $inparams );

        $ylabel = get_string('visits', 'block_visitsreport');
        $visits  = (!empty($report)) ? $this->chart_series($ylabel, array_column($report, 'visits')) : [];
        $labels = array_column($report, 'timecreated');
        array_walk($labels, function(&$value) {
            $value = date('M Y', $value);
        });

        $xlabel = get_string('month');
        $title = get_string('sitevisits', 'block_visitsreport');
        $heading = get_string('sitevisits_heading', 'block_visitsreport');

        // Table count sql.
        $countsql = "SELECT COUNT(*)
        FROM {".$this->logtable."} WHERE
        timecreated BETWEEN :timestart and :timeend
        AND action='viewed'
        AND userid ".$insql."
        GROUP BY MONTH(FROM_UNIXTIME(`timecreated`))";


        $reporttable = $this->visitsreport_table('visits_record', $report, $countsql, ['timecreated', 'visits'], [$xlabel, $ylabel]);
        return $this->generate_chart($visits, $labels, 'visits_record', false, $title, $heading, $xlabel, $ylabel, $reporttable);

    }

    /**
     * Most visited courses reports data fetched and processed to the reports.
     *
     * @return string
     */
    public function most_visited_courses() {
        global $DB;

        list($insql, $inparams) = $this->find_adminuser();

        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND l.timecreated BETWEEN :startdate AND :enddate ';
            $inparams += ['startdate' => $this->filter['start_timestamp'], 'enddate' => $this->filter['end_timestamp']];
        }

        $sql = 'SELECT l.courseid as id, l.courseid, c.fullname, count(*) as visits FROM {'.$this->logtable.'} l
        INNER JOIN {course} c on c.id = l.courseid
        WHERE l.courseid > 1 AND l.userid '.$insql.' '. $filtersql .' GROUP BY l.courseid ORDER BY visits DESC ';
        $records = $DB->get_records_sql($sql, $inparams);

        $xlabel = get_string('visits', 'block_visitsreport');
        $visits = (!empty($records)) ? $this->chart_series($xlabel, array_column($records, 'visits')) : [];
        $labels = array_column($records, 'fullname');

        $ylabel = get_string('course');
        $title = get_string('mostvisitedcourse', 'block_visitsreport');
        $heading = get_string('mostvisitedcourse_heading', 'block_visitsreport');

        $reporttable = $this->visitsreport_table('most_visited_courses', $records, '', ['fullname', 'visits'], [$ylabel, $xlabel]);
        return $this->generate_chart($visits, $labels, 'most_visited_courses', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * List of available user departments.
     */
    public static function available_departments() {
        global $DB;

        $sql = 'SELECT id, department FROM {user} u GROUP BY department';
        $data = $DB->get_records_sql($sql, []);
        if (isset($data[1])) {
            $data[1]->department = get_string('alldepart', 'block_visitsreport');
        }
        return $data;
    }

    /**
     * Chart of top 20 users from selected department.
     *
     * @return string
     */
    public function top_departmentusers() {
        global $DB;
        $params = [];

        list($insql, $inparams) = $this->find_adminuser();
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND l.timecreated BETWEEN :startdate AND :enddate ';
            $inparams += ['startdate' => $this->filter['start_timestamp'], 'enddate' => $this->filter['end_timestamp']];
        }

        $params['department'] = (isset($this->department)) ? $this->department : '';

        $sql = "SELECT u.id, count(*) as visits, u.department, u.firstname, u.lastname FROM {".$this->logtable."} l
        LEFT JOIN {user} u  ON u.id = l.userid
        WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1 AND u.department = :department
        AND l.action='viewed' ";
        if ($this->courseid) {
            $sql .= ' AND l.courseid=:courseid ';
            $params['courseid'] = $this->courseid;
        }
        $sql .= $filtersql;
        $sql .= 'GROUP BY l.userid ORDER BY visits DESC';

        $users = $DB->get_records_sql($sql, $params + $inparams, 0, 20);

        $params;
        $xlabel = get_string('visits', 'block_visitsreport');
        $visits = $this->chart_series($xlabel, array_column($users, 'visits'));
        $labels = [];
        array_walk($users, function($value) use (&$labels) {
            $labels[] = $value->firstname.' '.$value->lastname. ' ('.$value->visits.') ';
        });

        $ylabel = get_string('users');
        $title = get_string('topdepartmentusers', 'block_visitsreport');
        $heading = get_string('topdepartmentusers_heading', 'block_visitsreport');

        $reporttable = $this->visitsreport_table('top_departmentusers', $users, '', ['userfullname', 'visits'], [$ylabel, $xlabel]);
        return $this->generate_chart($visits, $labels, 'top_departmentusers', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * Report of department users timespent in site.
     *
     * @return string
     */
    public function timespent_bydepartment() {
        global $DB, $PAGE;
        // $departments = $this->get_userdepartments();
        $params = $visits = [];
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND vt.timecreated BETWEEN :startdate AND :enddate ';
            $params = ['startdate' => $this->filter['start_timestamp'], 'enddate' => $this->filter['end_timestamp']];
        }

        $sql = "SELECT u.id, u.department, SUM(vt.timespent) AS dpart_timespent, count(*) AS userscount FROM {user} u
        LEFT JOIN {local_visits_track} vt ON vt.userid = u.id
        WHERE u.department <> '' ".$filtersql." GROUP BY department ";

        $dpartusers = $DB->get_records_sql($sql, $params);
        $xlabel = get_string('timespent', 'block_visitsreport');
        $timespent = array_column($dpartusers, 'dpart_timespent');
        if (!empty($timespent)) {
            array_walk($timespent, function(&$value) {
                if (!empty($value)) {
                    $value = (int) $value;
                    $zero    = new \DateTime("@0");
                    $offset  = new \DateTime("@$value");
                    $diff    = $zero->diff($offset)->format('%H.%I');
                    $value = $diff;
                }
            });

            $visits = $this->chart_series($xlabel, $timespent);
        }
        $labels = array_column($dpartusers, 'department');

        $ylabel = get_string('department');
        $title = get_string('departmentsiteusage', 'block_visitsreport');
        $heading = get_string('departmentsiteusage_heading', 'block_visitsreport');

        if (count($labels) > 20) {
            $height = (count($labels)) * 20;
            $maxheight = (count($labels) + 10 ) * 20;
            $PAGE->requires->js_amd_inline("require(['jquery', 'core/chartjs'], function($, ChartJS) {
                var height = ".$height.";
                var maxheight = ".$maxheight.";
                $(window).on('load', function() {
                     Chart.helpers.each(Chart.instances, function(instance) {
                        var id = $(instance.canvas).parents('.visits-report-element').attr('id');
                        if (id == 'timespent_bydepartment') {
                            instance.config.options.responsive = true;
                            instance.config.options.maintainAspectRatio = false;
                            instance.canvas.parentNode.style.height= '".$height."px';
                            instance.update();
                            instance.resize();
                        }
                    })
                })
            })");
            echo '<style>
            .visits-report-element#timespent_bydepartment .chart-block {
                position:relative; display:block;
            }
            .visits-report-element#timespent_bydepartment .chart-block-parent  {
                overflow-y: auto;
            }
            #timespent_bydepartment .chart-block {
                height: auto;
            }
            </style>';
        }

        $reporttable = $this->visitsreport_table('timespent_bydepartment', $dpartusers, '', ['department', 'dpart_timespent'], [$ylabel, $xlabel]);
        return $this->generate_chart($visits, $labels, 'timespent_bydepartment', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    public function get_userdepartments(): array {
        global $DB;
        $departments = $DB->get_fieldset_sql("SELECT department FROM {user} WHERE department <> '' GROUP BY department ORDER BY department ASC", []);
        return $departments;
    }
    /**
     * List all course visits report data process.
     *
     * @param int $courseid Id of the course to generate the report.
     * @return string
     */
    public function course_visits() {
        global $DB;
        if (empty($this->courseid)) {
            throw new moodle_exception('unspecifycourseid');
        }
        list($insql, $inparams) = $this->find_adminuser();

        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND timecreated BETWEEN :startdate AND :enddate ';
            $inparams += ['startdate' => $this->filter['start_timestamp'], 'enddate' => $this->filter['end_timestamp']];
        }


        $sql = 'SELECT * FROM {'.$this->logtable.'} WHERE action="viewed" AND userid '.$insql.' AND courseid=:courseid ';
        $params = ['courseid' => $this->courseid];
        $conditionsql = $this->generate_timestamp($params);
        $sql .= ' AND (' . $conditionsql.')';
        $sql .= $filtersql;

        $records = $DB->get_records_sql($sql, $params + $inparams);
        // Filter unique logins for the day.
        $ylabel = get_string('visits', 'block_visitsreport');
        $visits = $visitseries = [];
        if (!empty($records)) {
            foreach ($records as $key => $record) {
                $month = date('M Y', $record->timecreated);
                $visits[$month] = (array_key_exists($month, $visits)) ? $visits[$month] + 1 : 1;
            }
            $visitseries = $this->chart_series($ylabel, array_values($visits));
        }
        $labels = array_keys($visits);

        array_walk($visits, function(&$visit, $month) {
            $visit = ['month' => $month, 'visits' => $visit];
        });

        $xlabel = get_string('month');
        $title = get_string('coursevisits', 'block_visitsreport');
        $heading = get_string('coursevisits_heading', 'block_visitsreport');

        $reporttable = $this->visitsreport_table('course_visits', $visits, '', ['month', 'visits'], [$xlabel, $ylabel]);
        return  $this->generate_chart($visitseries, $labels, 'course_visits', false, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * Generate the timestamp to get the last 7 days of each month.
     *
     * @param array $params
     * @return string sql condition queries.
     */
    public function generate_timestamp(&$params) {
        $month = time();
        $condition = [];
        for ($i = 1; $i <= 12; $i++) {
            $month = strtotime('last month', $month);
            $prevmonth = strtotime(date('y-m-t', $month));
            $monthend = $prevmonth;
            $startdate = strtotime('-7 days', $prevmonth);
            $startlabel = 'startdate_'.$i;
            $endlabel = 'enddate_'.$i;
            $condition[] = ' timecreated BETWEEN :'.$startlabel.' AND :'.$endlabel;
            $params[$startlabel] = $startdate;
            $params[$endlabel] = $monthend;
        }
        return implode(' OR ', $condition);
    }

    /**
     * Reports based on the most frequently accessed users in the site.
     *
     * @return string
     */
    public function top_visiters() {
        global $DB;

        list($insql, $inparams) = $this->find_adminuser();
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND l.timecreated BETWEEN :startdate AND :enddate ';
            $inparams += ['startdate' => $this->filter['start_timestamp'], 'enddate' => $this->filter['end_timestamp']];
        }

        $params = [];
        $sql = 'SELECT l.userid, u.firstname, u.lastname, count(*) as visits FROM {'.$this->logtable.'} l
        INNER JOIN {user} u ON u.id = l.userid
        WHERE l.userid '.$insql;
        if ($this->courseid) {
            $sql .= ' AND courseid=:courseid ';
            $params['courseid'] = $this->courseid;
        }
        $sql .= $filtersql;

        $sql .= 'GROUP BY l.userid ORDER BY visits DESC';

        $xlabel = get_string('visits', 'block_visitsreport');
        $records = $DB->get_records_sql($sql, $params + $inparams, 0, 20);
        $visits = (!empty($records)) ? $this->chart_series($xlabel, array_column($records, 'visits')) : [];
        $labels = [];
        array_walk($records, function($value) use (&$labels) {
            $labels[] = $value->firstname.' '.$value->lastname. ' ('.$value->visits.') ';
        });

        $ylabel = get_string('users');
        $title = get_string('mostfrequentusers', 'block_visitsreport');
        $heading = get_string('mostfrequentusers_heading', 'block_visitsreport');

        $reporttable = $this->visitsreport_table('top_visiters', $records, '', ['userfullname', 'visits'], [$xlabel, $ylabel]);
        return $this->generate_chart($visits, $labels, 'top_visiters', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * Users timespent in course.
     *
     * @return void
     */
    public function users_timespent() {
        global $DB, $PAGE;
        list($insql, $inparams) = $this->find_adminuser();
        $params = [];
        $filtersql = '';
        if (!empty($this->filter)) {
            $filtersql = ' AND lvt.timecreated BETWEEN :timestart and :timeend ';
            $params = ['timestart' => $this->filter['start_timestamp'], 'timeend' => $this->filter['end_timestamp']];
        }

        if (isset($this->userid) && !empty($this->userid) && !$this->courseid ) {

            $sql = "SELECT lvt.courseid, c.fullname, lvt.timecreated, SUM(lvt.timespent) as timespent, count(*) AS usersessions
            FROM {local_visits_track} lvt
            INNER JOIN {course} c ON c.id = lvt.courseid
            WHERE lvt.userid = :userid
            AND lvt.userid ".$insql;
            $sql .= $filtersql;
            $sql .= " GROUP BY lvt.courseid ORDER BY timespent DESC";

            $params['userid'] = $this->userid;

            $labelfunction = (function($value) use (&$labels) {
                $labels[] = $value->fullname;
            });
            $ylabel = get_string('courses');
            $yfield = 'fullname';
        } else {

            $sql = "SELECT lvt.userid, u.firstname, u.lastname, lvt.timecreated, SUM(lvt.timespent) as timespent, count(*) AS usersessions
                FROM {local_visits_track} lvt
                INNER JOIN {user} u ON u.id = lvt.userid
                WHERE lvt.userid ".$insql;

            $sql .= $filtersql;
            if ($this->courseid) {
                $sql .= ' AND courseid=:courseid ';
                $params['courseid'] = $this->courseid;
            }

            if (!empty($this->userid)) {
                $sql .= ' AND lvt.userid = :userid ';
                $params['userid'] = $this->userid;
            }
            $sql .= " GROUP BY lvt.userid ORDER BY timespent DESC";

            $labelfunction = (function($value) use (&$labels) {
                $labels[] = $value->firstname.' '.$value->lastname;
            });
            $ylabel = get_string('users');
            $yfield = 'userfullname';
        }

        $records = $DB->get_records_sql($sql, $params + $inparams, 0, 20 );

        // Convert the seconds to minutes.
        $visits = [];
        $timespent = array_column($records, 'timespent');
        if (!empty($timespent)) {
            array_walk($timespent, function(&$value) {
                if (!empty($value)) {
                    $value = (int) $value;
                    $zero    = new \DateTime("@0");
                    $offset  = new \DateTime("@$value");
                    $diff    = $zero->diff($offset)->format('%H.%I');
                    $value = $diff;
                }
            });
            $visits  = new \core\chart_series($ylabel, $timespent);
        }

        $labels = [];
        array_walk($records, $labelfunction);
        $xlabel = get_string('timespent', 'block_visitsreport');
        $title = get_string('siteuserspent', 'block_visitsreport');
        $heading = get_string('siteuserspent_heading', 'block_visitsreport');
        $reporttable = $this->visitsreport_table('users_timespent', $records, '', [$yfield, 'user_timespent'], [$ylabel, $xlabel]);
        return $this->generate_chart($visits, $labels, 'users_timespent', true, $title, $heading, $xlabel, $ylabel, $reporttable);
    }

    /**
     * Convert the set of data to chart series.
     *
     * @param string $name
     * @param array $data
     * @return void
     */
    public function chart_series($name, $data) {
        if (empty($data)) {
            return null;
        }
        return new \core\chart_series($name, $data);
    }

    /**
     * Generate the visits chart using moodle chart functions.
     *
     * @param \core\chart_series $series
     * @param array $labels
     * @param boolean $horizontal
     * @param string $title
     * @param string $heading
     * @param string $xlabel
     * @param string $ylabel
     * @return string Content report page.
     */
    public function generate_chart($series, $labels, $name, $horizontal=false, $title="", $heading='', $xlabel='', $ylabel='', $table='') {
        global $OUTPUT, $SESSION;

        // Custom date filter.
        $this->_customdata['reportname'] = $name;
        $this->_customdata['courseid'] = $this->courseid;
        $form = new \filterform(null, $this->_customdata);

        if ($series instanceof \core\chart_series) {
            $chart = new \core\chart_bar();
            if ($horizontal) {
                $chart->set_horizontal(true);
            }
            $chart->add_series($series);
            $chart->set_labels($labels);
            if ($title) {
                $chart->set_title($title);
            }
            if ($xlabel) {
                $chart->get_xaxis(0, true)->set_label($xlabel);
            }
            if ($ylabel) {
                $chart->get_yaxis(0, true)->set_label($ylabel);
            }


            $chart->set_legend_options(
                [
                    'responsive'=> true,
                    'maintainAspectRatio' => false
                ]
            );
        }

        $returnurl = optional_param('returnurl', '', PARAM_URL);
        if (empty($returnurl)) {
            $returnurl = isset($SESSION->wantsurl) ? $SESSION->wantsurl : '';
        }

        $html = html_writer::start_div('visits-report-chart visits-report-element', ['id' => $name]);
        $html .= html_writer::start_div('top-section');

        $html .= html_writer::start_div('heading-block');
        $itag = html_writer::tag('i', '', ['class' => 'fa fa-arrow-left']);
        if ($returnurl) {
            $html .= html_writer::link($returnurl, html_writer::tag('span', $itag . get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary']) );
        } else {
            $html .= html_writer::tag('span', $itag.get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary', 'onclick' =>'window.history.back()']);
        }
        $html .= html_writer::tag('h3', $heading);
        $html .= html_writer::end_div();

        $html .= html_writer::start_div('right-menu');
        $html .= $form->render();
        $html .= html_writer::end_div();

        $html .= html_writer::start_div('download-menu');
        $html .= $table;
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // E.O Top-section.

        $html .= html_writer::start_div('chart-block-parent');
        $html .= html_writer::start_div('chart-block');

        if (isset($chart)) {
            $html .= $OUTPUT->render($chart);
        } else {
            $html .= html_writer::start_div('alert alert-info alert-block fade in report-not-available');
            $html .= html_writer::tag('p', get_string('nodatafound', 'block_visitsreport'), ['class' => 'report-data-not']);
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div();
        $html .= html_writer::end_div();



        $html .= html_writer::end_div();

        return $html;
    }


    /**
     * Generate the table reports.
     *
     * @param \core\chart_series $series
     * @param array $labels
     * @param boolean $horizontal
     * @param string $title
     * @param string $heading
     * @param string $xlabel
     * @param string $ylabel
     * @return string Content report page.
     */
    public function generate_table($name, $title="", $heading='', $table='') {
        global $OUTPUT, $SESSION;

        // Custom date filter.
        $this->_customdata['reportname'] = $name;
        $this->_customdata['courseid'] = $this->courseid;
        $form = new \filterform(null, $this->_customdata);
        $returnurl = optional_param('returnurl', '', PARAM_URL);
        if (empty($returnurl)) {
            $returnurl = isset($SESSION->wantsurl) ? $SESSION->wantsurl : '';
        }

        $html = html_writer::start_div('visits-report-table visits-report-element', ['id' => $name]);
        $html .= html_writer::start_div('top-section');

        $html .= html_writer::start_div('heading-block');
        $itag = html_writer::tag('i', '', ['class' => 'fa fa-arrow-left']);
        if ($returnurl) {
            $html .= html_writer::link($returnurl, html_writer::tag('span', $itag . get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary']) );
        } else {
            $html .= html_writer::tag('span', $itag.get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary', 'onclick' =>'window.history.back()']);
        }
        $html .= html_writer::tag('h3', $heading);
        $html .= html_writer::end_div();

        $html .= html_writer::start_div('right-menu');
        $html .= $form->render();
        $html .= html_writer::end_div();

        $html .= html_writer::start_div('download-menu');
        $html .= $table;
        $html .= html_writer::end_div();

        $html .= html_writer::end_div(); // E.O Top-section.
        $html .= html_writer::end_div();

        return $html;
    }

    public function generate_header($name, $title = '', $heading = '', $reportshtml = '', $table = '') {
        // Custom date filter.
        $this->_customdata['reportname'] = $name;
        $this->_customdata['courseid'] = $this->courseid;
        $form = new \filterform(null, $this->_customdata);
        $returnurl = optional_param('returnurl', '', PARAM_URL);
        if (empty($returnurl)) {
            $returnurl = isset($SESSION->wantsurl) ? $SESSION->wantsurl : '';
        }

        $html = html_writer::start_div('visits-report-block visits-report-element', ['id' => $name]);
        $html .= html_writer::start_div('top-section');

        $html .= html_writer::start_div('heading-block');
        $itag = html_writer::tag('i', '', ['class' => 'fa fa-arrow-left']);
        if ($returnurl) {
            $html .= html_writer::link($returnurl, html_writer::tag('span', $itag . get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary']) );
        } else {
            $html .= html_writer::tag('span', $itag.get_string('back', 'block_visitsreport'), ['class' => 'pull-left btn btn-primary', 'onclick' =>'window.history.back()']);
        }
        $html .= html_writer::tag('h3', $heading);
        $html .= html_writer::end_div();

        $html .= html_writer::start_div('right-menu');
        $html .= $form->render();
        $html .= html_writer::end_div();
        if (!empty($table)) {
            $html .= html_writer::start_div('download-menu');
            $html .= $table;
            $html .= html_writer::end_div();
        }

        $html .= html_writer::end_div(); // E.O Top-section.
        if ($reportshtml) {
            $html .= html_writer::start_tag('div', array('class' => 'reports-block'));
            $html .= $reportshtml;
            $html .= html_writer::end_div('div');
        }
        $html .= html_writer::end_div(); // E.O report block
        return $html;
    }

    public function get_baseurl($reportname, $addon = []) {
        global $PAGE;
        $tableparams = [];// http_build_query($this->_customdata);
        array_walk($this->_customdata, function($val, $key) use (&$tableparams){
            if (is_array($val)) {
                foreach($val as $k => $v) {
                    $param = $key.'['.$k.']';
                    $tableparams[$param] = $v;
                }
            } else {
                $tableparams[$key] = $val;
            }
        });

        $tableparams['reportname'] = $reportname;
        $tableparams += $addon;
        $url = new \moodle_url($PAGE->url, $tableparams);
        return $url;
    }

    public function visitsreport_table($reportname, $data, $countsql, $columns, $headers, $showmore = '', $baseurl = '', $position = '') {
        if (empty($baseurl)) {
            $baseurl = $this->get_baseurl($reportname);
        }
        $reporttable = new \block_visitsreport\report_table($reportname);
        $reporttable->define_baseurl($baseurl);
        $reporttable->set_report($reportname, $data, $countsql, $columns, $headers);
        if ($position) {
            $reporttable->showdownloadbuttonsat = [$position];
        }
        $reportfilename = get_string($reportname.'_file', 'block_visitsreport');
        if ($this->download) {
            $reporttable->is_downloading($this->download, $reportfilename);
            $reporttable->out(0, true, true);
        } else {
            ob_start();
            $reporttable->out(0, true, true);
            $table = ob_get_contents();
            ob_clean();
            if ($showmore) {
                $table .= $showmore;
            }
            return $table;
        }
    }
}
