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
 * Session Booking Plugin cron task
 *
 * @package    local_booking
 * @author     Mustafa Hajjar (mustafahajjar@gmail.com)
 * @copyright  BAVirtual.co.uk © 2021
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_booking\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/booking/lib.php');

use local_booking\local\message\notification;
use local_booking\local\participant\entities\instructor;
use local_booking\local\subscriber\entities\subscriber;

/**
 * A schedule task to send notifications to instructors
 * of student availability postings and recommendations
 *
 * @package    local_booking
 * @author     Mustafa Hajjar (mustafahajjar@gmail.com)
 * @copyright  BAVirtual.co.uk © 2021
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifications_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('tasknotifications', 'local_booking');
    }

    /**
     * Run session booking cron.
     */
    public function execute() {

        // get course list
        $sitecourses = get_courses();

        foreach ($sitecourses as $sitecourse) {
            if ($sitecourse->id != SITEID) {

                // check if the course is using Session Booking
                $course = new subscriber($sitecourse->id);

                if (!empty($course->subscribed) && $course->subscribed) {

                    mtrace('');
                    mtrace('    Notifications for course: ' . $sitecourse->shortname . ' (id: ' . $sitecourse->id . ')');

                    // get active students
                    $students = $course->get_students('active', true);

                    // find students that have an availability posting not emailed
                    foreach ($students as $student) {

                        // notify instructors of student recommendation
                        if (get_user_preferences('local_booking_' . $course->get_id() . '_endorsenotify', false, $student->get_id())) {

                            // get message data
                            $data = array(
                                'coursename'    => $course->get_shortname(),
                                'studentname'   => $student->get_name(),
                                'firstname'     => $student->get_profile_field('firstname', true),
                                'skilltest'     => $course->get_graduation_exercise(true),
                                'instructorname'=> instructor::get_fullname(get_user_preferences('local_booking_' . $course->get_id() . '_endorser', 0, $student->get_id())),
                                'bookingurl'    => (new \moodle_url('/local/booking/view.php', array('courseid'=>$course->get_id())))->out(false),
                                'courseurl'     => (new \moodle_url('/course/view.php', array('id'=> $course->get_id())))->out(false),
                                'assignurl'     => (new \moodle_url('/mod/assign/index.php', array('id'=> $course->get_id())))->out(false),
                                'exerciseurl'   => (new \moodle_url('/mod/assign/view.php', array('id'=> $student->get_current_exercise())))->out(false),
                                'exercise'      => $course->get_exercise_name($student->get_current_exercise()),
                            );

                            // send recommendation message
                            $message = new notification();
                            $message->send_recommendation_notification($course->get_instructors(), $data);

                            mtrace('                recommendation notifications sent...');

                            // reset notification setting
                            set_user_preference('local_booking_' . $course->get_id() . '_endorsenotify', false, $student->get_id());
                        }

                        // notify instructors of student availability posting
                        $slotstonotify = get_user_preferences('local_booking_' . $course->get_id() . '_postingnotify', false, $student->get_id());
                        if (!empty($slotstonotify)) {

                            // get student availability slots new postings
                            $slotids = explode(',', $slotstonotify);
                            $postingstext = '';
                            $postingshtml = '<table style="border-collapse: collapse; width: 400px"><tbody>';
                            $previousday = '';

                            foreach ($slotids as $slotid) {

                                if (!empty($slotid)) {

                                    // get each slot posted
                                    $slot = $student->get_slot($slotid);

                                    // format the availability slots postings
                                    if (!empty($slot)) {

                                        $startdate = new \DateTime('@'.$slot->starttime);
                                        $sameday = $startdate->format('l') == $previousday;
                                        $postingstext .= !$sameday ? PHP_EOL . $startdate->format('l M d\: ') : ', ';
                                        $postingstext .= $startdate->format(' H:i\z') . ' - ' . (new \DateTime('@'.$slot->endtime))->format('H:i\z');
                                        $postingshtml .= '<tr' . (!$sameday ? ' style="border-top: 1pt solid black"' : '') . '><td style="width: 100px">';
                                        $postingshtml .= (!$sameday ? $startdate->format('l ') . '</td><td style="width: 100px;">' . $startdate->format('M d') : '&nbsp;</td><td>&nbsp;') . '</td>';
                                        $postingshtml .= '<td style="width: 100px">' . $startdate->format('H:i\z') . '</td><td style="width: 100px">';
                                        $postingshtml .= (new \DateTime('@'.$slot->endtime))->format('H:i\z') . '</td></tr>';
                                        $previousday = $startdate->format('l');

                                    }

                                }
                            }
                            $postingshtml .= '<tr style="border-top: 1pt solid black"><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table>';

                            // get message data
                            $data = array(
                                'courseurl'     => (new \moodle_url('/course/view.php', array('id'=>$course->get_id())))->out(false),
                                'coursename'    => $course->get_shortname(),
                                'assignurl'     => (new \moodle_url('/mod/assign/index.php', array('id'=>$course->get_id())))->out(false),
                                'studentname'   => $student->get_name(),
                                'firstname'     => $student->get_profile_field('firstname', true),
                                'postingstext'  => $postingstext,
                                'postingshtml'  => $postingshtml,
                                'bookingurl'    => (new \moodle_url('/local/booking/availability.php', array(
                                    'courseid'      => $course->get_id(),
                                    'userid'        => $student->get_id(),
                                    'exid'          => $student->get_next_exercise(),
                                    'action'        => 'book'
                                    )))->out(false),
                                'courseurl'     => (new \moodle_url('/course/view.php', array('id'=> $course->get_id())))->out(false),
                                'assignurl'     => (new \moodle_url('/mod/assign/index.php', array('id'=> $course->get_id())))->out(false),
                                'exerciseurl'   => (new \moodle_url('/mod/assign/view.php', array('id'=> $student->get_next_exercise())))->out(false),
                                'exercise'      => $course->get_exercise_name($student->get_next_exercise()),
                            );

                            $message = new notification();
                            $message->send_availability_posting_notification($course->get_instructors(), $data);

                            mtrace('                availability posting notifications sent...');

                            // reset notification setting
                            set_user_preference('local_booking_' . $course->get_id() . '_postingnotify', '', $student->get_id());
                        }
                    }
                }
            }
        }

        return true;
    }
}