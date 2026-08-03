<?php
require_once(__DIR__ . '/../../config.php');

use local_itmabulletin\bulletin_manager;

$serialnumber = optional_param('b', '', PARAM_ALPHANUMEXT);
$token = optional_param('t', '', PARAM_ALPHANUMEXT);
$legacyuserid = optional_param('u', 0, PARAM_INT);
$legacysemester = optional_param('s', '', PARAM_ALPHANUMEXT);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/itmabulletin/verify.php'));
$PAGE->set_title('Verification bulletin ITMA');
$PAGE->set_heading('Verification bulletin ITMA');

function local_itmabulletin_format_verified_note($value): string {
    if ($value === null || $value === '' || $value === false) {
        return '-';
    }

    return format_float((float)$value, 2);
}

function local_itmabulletin_render_issue_details(stdClass $issue, array $snapshot): string {
    $student = $snapshot['student'] ?? [];
    $generalaverage = $snapshot['generalaverage'] ?? null;
    $totalcredits = $snapshot['totalcredits'] ?? null;
    $status = ((string)$issue->status === 'active') ? 'Bulletin officiel actif' : 'Bulletin revoque';

    $summary = html_writer::alist([
        'Statut : ' . $status,
        'Numero officiel : ' . s($issue->serialnumber),
        'Etudiant : ' . s(trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''))),
        'Matricule : ' . s($student['username'] ?? ''),
        'Semestre : ' . s($issue->semestercode),
        'Moyenne generale : ' . local_itmabulletin_format_verified_note($generalaverage),
        'Total credits : ' . local_itmabulletin_format_verified_note($totalcredits),
        'Date emission : ' . userdate((int)$issue->issuedat),
        'Empreinte officielle : ' . s(substr((string)$issue->datahash, 0, 16)) . '...',
    ]);

    if ((string)$issue->status !== 'active') {
        $summary .= html_writer::div(
            'Ce bulletin a ete revoque. Il ne doit pas etre accepte comme document officiel.',
            'alert alert-danger'
        );
    }

    $rows = [];
    foreach (($snapshot['ues'] ?? []) as $ue) {
        $rows[] = new html_table_row([
            html_writer::tag('strong', s($ue['label'] ?? '')),
            s($ue['type'] ?? ''),
            local_itmabulletin_format_verified_note($ue['credit'] ?? null),
            local_itmabulletin_format_verified_note($ue['average'] ?? null),
            s($ue['status'] ?? ''),
        ]);

        foreach (($ue['ecs'] ?? []) as $ec) {
            $rows[] = new html_table_row([
                '&nbsp;&nbsp;' . s($ec['fullname'] ?? ''),
                'EC',
                local_itmabulletin_format_verified_note($ec['credit'] ?? null),
                local_itmabulletin_format_verified_note($ec['average'] ?? null),
                'Classe ' . local_itmabulletin_format_verified_note($ec['classaverage'] ?? null)
                    . ' / Examen ' . local_itmabulletin_format_verified_note($ec['exam'] ?? null)
                    . ' / Rattrapage ' . local_itmabulletin_format_verified_note($ec['makeup'] ?? null),
            ]);
        }
    }

    $table = new html_table();
    $table->head = ['UE / EC', 'Type', 'Credits', 'Moyenne', 'Statut / Notes'];
    $table->data = $rows;
    $table->attributes['class'] = 'generaltable itmabulletin-verification-table';

    return html_writer::div(
        html_writer::tag('h3', 'Bulletin authentique') .
        html_writer::tag('p', 'Ce QR code confirme que les donnees ci-dessous correspondent a un bulletin officiellement emis par la plateforme ITMA.') .
        $summary .
        html_writer::table($table),
        'box generalbox'
    );
}

echo $OUTPUT->header();

if ($serialnumber !== '' && $token !== '') {
    $issue = bulletin_manager::verify_issued_bulletin($serialnumber, $token);
    if (!$issue) {
        echo $OUTPUT->notification('Document non reconnu ou signature de verification invalide.', 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }

    echo local_itmabulletin_render_issue_details($issue, bulletin_manager::decode_issue_snapshot($issue));
    echo $OUTPUT->footer();
    exit;
}

if ($legacyuserid > 0 && $legacysemester !== '' && $token !== '') {
    if (!bulletin_manager::verify_bulletin_token($legacyuserid, $legacysemester, $token)) {
        echo $OUTPUT->notification('Document non reconnu ou code de verification invalide.', 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }

    $user = $DB->get_record('user', ['id' => $legacyuserid, 'deleted' => 0], 'id, firstname, lastname, username', IGNORE_MISSING);
    if (!$user) {
        echo $OUTPUT->notification('Etudiant introuvable pour ce bulletin.', 'notifyproblem');
        echo $OUTPUT->footer();
        exit;
    }

    echo $OUTPUT->notification(
        'Ancien QR code : cette verification confirme seulement l etudiant et le semestre, pas le contenu complet du PDF.',
        'warning'
    );
    echo html_writer::div(
        html_writer::tag('h3', 'Bulletin reconnu - verification ancienne generation') .
        html_writer::alist([
            'Etudiant : ' . fullname($user),
            'Matricule : ' . s($user->username),
            'Semestre : ' . s($legacysemester),
            'Date de verification : ' . userdate(time()),
        ]),
        'box generalbox'
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->notification('Parametres de verification manquants.', 'notifyproblem');
echo $OUTPUT->footer();
