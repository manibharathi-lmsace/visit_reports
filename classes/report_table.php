<?php

namespace block_visitsreport;

class report_table extends \table_sql {

    public $reportsql;

    public $reportdata;

    public function set_report($reportname, $data, $sql, $columns, $headers) {
        $this->reportsql = $sql;
        $this->reportdata = $data;
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->downloadable = true;
        $this->is_sortable = false;
        $this->is_collapsible = false;
        $this->showdownloadbuttonsat = [TABLE_P_BOTTOM];
    }

    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;
        $this->rawdata = $this->reportdata;
    }

    public function col_timecreated($row) {
        return date('M Y', $row->timecreated);
    }

    public function col_fullname($row) {
        return $row->fullname;
    }

    public function col_userfullname($row) {
        if (isset($row->visits)) {
            return  $row->firstname.' '.$row->lastname. ' ('.$row->visits.') ';
        } else {
            return  $row->firstname.' '.$row->lastname;
        }
    }

    public function col_firstname($row) {
        return isset($row->firstname) ? $row->firstname : '';
    }

    public function col_lastname($row) {
        return isset($row->lastname) ? $row->lastname : '';
    }

    public function col_department($row) {
        return isset($row->department) ? $row->department : '';
    }

    public function col_email($row) {
        return $row->email;
    }

    public function col_coursefullname($row) {
        return isset($row->coursename) ? $row->coursename : '';
    }

    public function col_modulename($row) {
        if (isset($row->cm)) {
            return isset($row->name) ? $row->name : '';
        } else {
            return "";
        }
    }

    public function col_dpart_timespent($row) {
        $value = (int) $row->dpart_timespent;
        $zero    = new \DateTime("@0");
        $offset  = new \DateTime("@$value");
        $diff    = $zero->diff($offset)->format('%H.%I');
        return $diff;
    }

    public function col_user_timespent($row) {
        $value = !empty($row->timespent) ? (int) $row->timespent : 0;
        $zero    = new \DateTime("@0");
        $offset  = new \DateTime("@$value");
        $diff    = $zero->diff($offset)->format('%H.%I');
        return $diff;
    }

    public function col_user_activityclicks($row) {
        return $row->activityclick;
    }

    public function col_numofclicks($row) {
        return !empty($row->views) ? $row->views : 0;
    }

    public function col_coursevisits($record) {
        $month = date('M Y', $record->timecreated);
        $visits = $record->visits;
        return (array_key_exists($month, $visits)) ? $visits[$month] + 1 : 1;
    }

    public function out($pagesize, $useinitialsbar, $downloadhelpbutton='') {
        parent::out($pagesize, $useinitialsbar, $downloadhelpbutton);
    }

}