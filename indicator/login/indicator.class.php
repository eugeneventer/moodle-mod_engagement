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
 * This file defines a class with login indicator logic
 *
 * @package    engagementindicator_login
 * @copyright  2012 NetSpot Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__).'/../indicator.class.php');

class indicator_login extends indicator {

    /**
     * get_rawdata
     *
     * @param int $startdate
     * @param int $enddate
     * @access protected
     * @return array            array of risk values, keyed on userid
     */
    protected function get_rawdata($startdate, $enddate) {
        global $DB;

        $sessions = array();

        $params = array();
        $params['courseid'] = $this->courseid;
        $params['startdate'] = $startdate;
        $params['enddate'] = $enddate;
        $sql = "SELECT id, userid, time
                FROM {log}
                WHERE course = :courseid AND time >= :startdate AND time <= :enddate
                ORDER BY time ASC";
        if ($logs = $DB->get_records_sql($sql, $params)) {
            // Need to calculate sessions, sessions are defined by time between consequtive logs not exceeding setting.
            foreach ($logs as $log) {
                $increment = false;
                $week = date('W', $log->time);
                if (!isset($sessions[$log->userid])) {
                    $sessions[$log->userid] = array('total' => 0, 'weeks' => array(), 'pastweek' => 0, 'lengths' => array(),
                                                    'start' => 0);
                }
                if (!isset($sessions[$log->userid]['lastlogin'])) {
                    $increment = true;
                } else {
                    if (($log->time - $this->config['session_length']) > $sessions[$log->userid]['lastlogin']) {
                        $increment = true;
                    }
                }

                if ($increment) {
                    if ($sessions[$log->userid]['start'] > 0) {
                        $sessions[$log->userid]['lengths'][] =
                            $sessions[$log->userid]['lastlogin'] - $sessions[$log->userid]['start'];
                    }
                    $sessions[$log->userid]['total']++;
                    $sessions[$log->userid]['start'] = $log->time;
                    if (!isset($sessions[$log->userid]['weeks'][$week])) {
                        $sessions[$log->userid]['weeks'][$week] = 0;
                    }
                    $sessions[$log->userid]['weeks'][$week]++;

                    if ($log->time > ($enddate - 7*24*60*60)) { // Session in past week.
                        $sessions[$log->userid]['pastweek']++;
                    }
                }
                $sessions[$log->userid]['lastlogin'] = $log->time;
            }
        }

        return $sessions;
    }

    private static function calculate_risk($actual, $expected) {
        $risk = 0;
        if ($actual < $expected) {
            $risk += ($expected - $actual) / $expected;
        }
        return $risk;
    }

    protected function calculate_risks(array $userids) {
        $risks = array();
        $sessions = $this->rawdata;

        $strloginspastweek = get_string('eloginspastweek', 'engagementindicator_login');
        $strloginsperweek = get_string('eloginsperweek', 'engagementindicator_login');
        $stravgsessionlength = get_string('eavgsessionlength', 'engagementindicator_login');
        $strtimesincelast = get_string('etimesincelast', 'engagementindicator_login');
        $strmaxrisktitle = get_string('maxrisktitle', 'engagementindicator_login');

        foreach ($userids as $userid) {
            $risk = 0;
            $reasons = array();

            if (!isset($sessions[$userid])) {
                $info = new stdClass();
                $info->risk = 1.0 * ($this->config['w_loginspastweek'] +
                                         $this->config['w_avgsessionlength'] +
                                         $this->config['w_loginsperweek'] +
                                         $this->config['w_timesincelast']);
                $reason = new stdClass();
                $reason->weighting = '100%';
                $reason->localrisk = '100%';
                $reason->logic = "This user has never logged into the course and so is at the maximum 100% risk.";
                $reason->riskcontribution = '100%';
                $reason->title = $strmaxrisktitle;
                $info->info = array($reason);
                $risks[$userid] = $info;
                continue;
            }

            // Logins past week.
            $local_risk = self::calculate_risk($sessions[$userid]['pastweek'], $this->config['e_loginspastweek']);
            $risk_contribution = $local_risk * $this->config['w_loginspastweek'];
            $reason = new stdClass();
            $reason->weighting = intval($this->config['w_loginspastweek']*100).'%';
            $reason->localrisk = intval($local_risk*100).'%';
            $reason->logic = "0% risk for more than {$this->config['e_loginspastweek']} logins a week. ".
                             "100% for 0 logins in the past week.";
            $reason->riskcontribution = intval($risk_contribution*100).'%';
            $reason->title = $strloginspastweek;
            $reasons[] = $reason;
            $risk += $risk_contribution;

            // Average session length.
            if (($count = count($sessions[$userid]['lengths'])) > 0) {
                $average = array_sum($sessions[$userid]['lengths']) / $count;
            } else {
                $average = 0;
            }
            $local_risk = self::calculate_risk($average, $this->config['e_avgsessionlength']);
            $risk_contribution = $local_risk * $this->config['w_avgsessionlength'];
            $reason = new stdClass();
            $reason->weighting = intval($this->config['w_avgsessionlength']*100).'%';
            $reason->localrisk = intval($local_risk*100).'%';
            $reason->logic = "0% risk for average session length longer than ".
                             "{$this->config['e_avgsessionlength']} seconds. 100% for session length of 0.";
            $reason->riskcontribution = intval($risk_contribution*100).'%';
            $reason->title = $stravgsessionlength;
            $reasons[] = $reason;
            $risk += $risk_contribution;

            // Logins per week.
            if (($count = count($sessions[$userid]['weeks'])) > 0) {
                $average = array_sum($sessions[$userid]['weeks']) / $count;
            } else {
                $average = 0;
            }
            $local_risk = self::calculate_risk($average, $this->config['e_loginsperweek']);
            $risk_contribution = $local_risk * $this->config['w_loginsperweek'];
            $reason = new stdClass();
            $reason->weighting = intval($this->config['w_loginsperweek']*100).'%';
            $reason->localrisk = intval($local_risk*100).'%';
            $reason->logic = "0% risk for logging in to the course >= {$this->config['e_loginsperweek']} ".
                             "times a week. 100% risk for 0 logins a week.";
            $reason->riskcontribution = intval($risk_contribution*100).'%';
            $reason->title = $strloginsperweek;
            $reasons[] = $reason;
            $risk += $risk_contribution;

            // Time since last login.
            $timediff = time() - $sessions[$userid]['lastlogin'];
            $local_risk = self::calculate_risk($this->config['e_timesincelast'], $timediff);
            $risk_contribution = $local_risk * $this->config['w_timesincelast'];
            $reason = new stdClass();
            $reason->weighting = intval($this->config['w_timesincelast']*100).'%';
            $reason->localrisk = intval($local_risk*100).'%';
            $reason->logic = "0% risk for last login to the course having just happened. ".
                             "Scaling to the max 100% risk after ".($this->config['e_timesincelast']/86400)." days.";
            $reason->riskcontribution = intval($risk_contribution*100).'%';
            $reason->title = $strtimesincelast;
            $reasons[] = $reason;
            $risk += $risk_contribution;

            $info = new stdClass();
            $info->risk = $risk;
            $info->info = $reasons;
            $risks[$userid] = $info;
        }
        return $risks;
    }

    protected function load_config() {
        parent::load_config();
        $defaults = $this->get_defaults();
        foreach ($defaults as $setting => $value) {
            if (!isset($this->config[$setting])) {
                $this->config[$setting] = $value;
            } else if (substr($setting, 0, 2) == 'w_') {
                $this->config[$setting] = $this->config[$setting] / 100;
            }
        }
    }

    public static function get_defaults() {
        $settings = array();
        $settings['e_loginspastweek'] = 2;
        $settings['w_loginspastweek'] = 0.2;

        $settings['e_loginsperweek'] = 2;
        $settings['w_loginsperweek'] = 0.3;

        $settings['e_avgsessionlength'] = 10*60;
        $settings['w_avgsessionlength'] = 0.1;

        $settings['e_timesincelast'] = 7*24*60*60; // 1 week.
        $settings['w_timesincelast'] = 0.4;

        $settings['session_length'] = 60*60; // 1 hour.
        return $settings;
    }
}
