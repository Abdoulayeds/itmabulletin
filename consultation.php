<?php
require_once(__DIR__ . '/../../config.php');

use local_itmabulletin\bulletin_manager;
use local_itmabulletin\bulletin_presenter;

require_login();

$context = context_system::instance();
if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$PAGE->set_url(new moodle_url('/local/itmabulletin/consultation.php'));
$PAGE->set_context($context);
$PAGE->requires->css('/local/itmabulletin/styles.css');
$PAGE->set_title(get_string('student_page_title', 'local_itmabulletin'));
$PAGE->set_heading(get_string('student_page_heading', 'local_itmabulletin'));

$userid = (int)$USER->id;
$semester = optional_param('semester', '', PARAM_RAW_TRIMMED);
$download = optional_param('download', 0, PARAM_BOOL);

$allowed = bulletin_manager::get_allowed_semester_codes();
if (!empty($semester) && !in_array($semester, $allowed, true)) {
    $semester = '';
}

$semoptions = bulletin_presenter::get_student_semester_options();

$bulletin = null;
$bulletinhtml = '';

if (!empty($semester)) {
    $bulletin = bulletin_manager::build_semester_bulletin($userid, $semester);

    if (!empty($bulletin['student']) && !empty($bulletin['ues'])) {
        if ($download) {
            require_sesskey();
            $bulletinhtml = bulletin_presenter::render_student_bulletin_html($bulletin, $semester, true);
            bulletin_manager::export_student_bulletin_pdf($userid, $semester, $bulletinhtml);
            exit;
        }

        $bulletinhtml = bulletin_presenter::render_student_bulletin_html($bulletin, $semester);
    }
}

echo $OUTPUT->header();

echo html_writer::start_div('itmabulletin-student-page');
echo html_writer::tag('h2', get_string('student_form_title', 'local_itmabulletin'));
echo html_writer::tag('p', get_string('student_form_intro', 'local_itmabulletin'), ['class' => 'itmabulletin-student-intro']);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/itmabulletin/consultation.php'),
    'class' => 'itmabulletin-student-form',
]);

echo html_writer::start_div('itmabulletin-student-form-row');
echo html_writer::tag('label', get_string('student_semester_label', 'local_itmabulletin'), ['for' => 'id_semester']);
echo html_writer::select($semoptions, 'semester', $semester, ['' => get_string('student_semester_placeholder', 'local_itmabulletin')], ['id' => 'id_semester']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('student_consult_button', 'local_itmabulletin'),
    'class' => 'btn btn-primary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (empty($semester)) {
    echo $OUTPUT->notification(get_string('student_choose_semester', 'local_itmabulletin'), 'notifymessage');
} else if (empty($bulletin['student'])) {
    echo $OUTPUT->notification(get_string('student_not_found', 'local_itmabulletin'), 'notifyproblem');
} else if (empty($bulletin['ues'])) {
    echo $OUTPUT->notification(get_string('student_no_ue', 'local_itmabulletin', s($semester)), 'notifyproblem');
} else {
    echo $bulletinhtml;

    $pdfurl = new moodle_url('/local/itmabulletin/consultation.php', [
        'semester' => $semester,
        'download' => 1,
        'sesskey' => sesskey(),
    ]);

    echo html_writer::div(
        html_writer::link($pdfurl, get_string('student_download_pdf', 'local_itmabulletin'), [
            'class' => 'btn btn-secondary itmabulletin-pdfbtn',
        ]),
        'itmabulletin-actions'
    );
}

echo html_writer::end_div();

echo $OUTPUT->footer();
