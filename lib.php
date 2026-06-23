<?php

if (!function_exists('block_visitsreport_time_track')) {

    function block_visitsreport_time_track() {
        global $PAGE, $USER;
        // echo $PAGE->bodyid;
        // $PAGE->requires->js_call_amd('block_visitsreport/timetracking', 'init', ['userid' => $USER->id, 'pageid' => $PAGE->id]);
    }
    block_visitsreport_time_track();
}

function block_visitsreport_output_fragment_getreport($args) {
    global $PAGE;

    $context = $args['context'];
    $url = new \moodle_url('/blocks/visitsreport/view.php');
    $PAGE->set_url($url);
    $filterdata = $args['filterdata'];

    $report = $args['report'];
    $courseid = $args['courseid'];
    $userid = ($args['userid']) ? ($args['userid']) : '';
    $department = ($args['department']) ? ($args['department']) : '';
    if ($department == get_string('alldepart', "block_visitsreport")) {
        $department = 0;
    }
    if (!empty($report) && $context != '') {
        $visitreport = new \block_visitsreport\report($report, $courseid);
        if (!empty($filterdata)) {
            parse_str($filterdata, $filter);
            $filter = ['startdate' => $filter['startdate'], 'enddate' => $filter['enddate'], 'userid' => $userid];
            $visitreport->set_filter($filter);
        }
        if (!empty($userid)) {
            $visitreport->set_user($userid);
        }
        if (!empty($department)) {
            $visitreport->set_departmentfilter($department);
        }
        if (method_exists($visitreport, $report)) {
            return $visitreport->$report();
        }
    }
}

function block_visitsreport_output_fragment_getmoduleinfo($args) {
    $cm = $args['cm'];
    $courseid = $args['course'];
    $modinstance = $args['modinstance'];
    $showmore = ($args['showmore'] == true) ? false : true;
    $modname = $args['modname'];
    $modinfo = new stdClass();
    $modinfo->id = $modinstance;
    $modinfo->modname = $modname;
    $modinfo->cm = $cm;
    $visitreport = new \block_visitsreport\report('centralised_reports');
    return $visitreport->course_activity_user($modinfo, 'centralised_reports', $courseid, false, $showmore);
}

function block_visitsreport_get_capability_for_reports($report) {
    if ($report == 'users') {
        $capability = "block/visitsreport:viewusersreport";
    } else if ($report == 'course') {
        $capability = "block/visitsreport:viewcoursereport";
    } else if ($report == 'centralised_reports') {
        $capability = "block/visitsreport:viewcentralizereport";
    } else if ($report == 'userdepartment') {
        $capability = "block/visitsreport:viewuserdepartmentreport";
    } else if ($report == 'accessactivities') {
        $capability = "block/visitsreport:viewaccessactivitiesreport";
    } else if ($report == 'accesscourses') {
        $capability = "block/visitsreport:viewaccesscoursesreport";
    } else {
        $capability = "block/visitsreport:viewsitereport";
    }
    return $capability;
}

function block_visitsreport_user_activity_timespent($cmid, $userid) {
    global $DB;
    $sql = "SELECT lvt.cmid, SUM(lvt.timespent) as timespent
            FROM {local_visits_track} lvt WHERE lvt.cmid = :cmid AND lvt.userid = :userid
            GROUP BY lvt.cmid";
    $params = ['userid' => $userid, 'cmid' => $cmid];
    $record = $DB->get_record_sql($sql, $params);
    return !empty($record) ? $record->timespent : 0;
}

function block_visitsreport_user_activity_clicks($cmid, $userid) {
    global $DB;
    $sql = "SELECT count(*) FROM {logstore_standard_log} ls
        WHERE ls.contextinstanceid = :cmid AND ls.userid = :userid AND ls.target = 'course_module'
        AND ls.action = 'viewed'";
    $params = ['userid' => $userid, 'cmid' => $cmid];
    return $DB->count_records_sql($sql, $params);
}


class filterform extends \moodleform {

    public function definition() {
        global $CFG, $PAGE;

        $mform = $this->_form;
        $mform->updateAttributes(['class' => 'visits-report-filter']);
        $selectusersreports = ['users_timespent', 'user_courseactivityreports'];
        if (isset($this->_customdata['reportname']) && in_array($this->_customdata['reportname'], $selectusersreports)) {
            $users = $options = [];
            if (isset($this->_customdata['courseid']) && !empty($this->_customdata['courseid'])) {
                $users = ['' => get_string('user')];
                $context = context_course::instance($this->_customdata['courseid']);
                $users += get_enrolled_users($context);
                array_walk($users, function(&$user) {
                    $user = fullname($user);
                });

            } else {
                $options = [
                    'ajax' => 'core_search/form-search-user-selector',
                    'noselectionstring' => get_string('user'),
                    'valuehtmlcallback' => function($userid) {
                        $user = core_user::get_user($userid);
                        return fullname($user, has_capability('moodle/site:viewfullnames', context_system::instance()));
                    }
                ];
            }
            $mform->addElement('autocomplete', 'users', '', $users, $options);
            if (isset($this->_customdata['userid'])) {
                $mform->setDefault('users', $this->_customdata['userid']);
            }
        }
        $selectdepartreports = ['top_departmentusers', 'departmentuser_timespentreports'];
        if (isset($this->_customdata['reportname']) && in_array($this->_customdata['reportname'], $selectdepartreports)) {
            $users = $options = [];
            $departmentusers = block_visitsreport\report::available_departments();
            foreach ($departmentusers as $userid => $user) {
                $department = $user->department;
                $departments[$department] = $department;
            }
            $mform->addElement('autocomplete', 'department', '', $departments);
            if (isset($this->_customdata['department'])) {
                $mform->setDefault('department', $this->_customdata['department']);
            }
        }

        $mform->addElement('date_selector', 'startdate', get_string('startdate', 'block_visitsreport'), array('startyear' => 2015, 'optional' => true));
        $mform->setDefault('startdate', $this->_customdata['startdate']);

        $mform->addElement('date_selector', 'enddate', get_string('enddate', 'block_visitsreport'), array('startyear' => 2015, 'optional' => true));
        $mform->setDefault('enddate', $this->_customdata['enddate']);

        $mform->addElement('hidden', 'report');
        $mform->setDefault('report', $this->_customdata['reportname']);
        $mform->setType('report', PARAM_TEXT);

        $this->add_action_buttons(false, get_string('getreports', 'block_visitsreport'));
    }

    public function validation($data, $files) {
        // If both start and end dates are set end date should be later than the start date.
        if (!empty($coursedata['startdate']) && !empty($coursedata['enddate']) &&
            ($coursedata['enddate'] < $coursedata['startdate'])) {
                $errors['enddate'] = 'enddatebeforestartdate';
        }
        return $errors;
    }


}