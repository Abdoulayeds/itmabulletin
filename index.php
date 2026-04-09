<?php
require_once(__DIR__ . '/../../config.php');

use local_itmabulletin\bulletin_manager;
use local_itmabulletin\form\selection_form;

require_login();
$context = context_system::instance();

// Capabilities (ancien + nouveau)
$cap1 = 'local/itmabulZulabulletin:generate';
$cap2 = 'local/itmabulletin:generate';
if (!has_capability($cap1, $context) && !has_capability($cap2, $context)) {
    require_capability($cap2, $context);
}

$PAGE->set_url(new moodle_url('/local/itmabulletin/index.php'));
$PAGE->set_context($context);
$PAGE->set_title('Génération des bulletins ITMA');
$PAGE->set_heading('Génération des bulletins ITMA');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function local_itmabulletin_get_profile_field(int $userid, string $shortname): ?string {
    global $DB;

    $sql = "SELECT d.data
              FROM {user_info_data} d
              JOIN {user_info_field} f ON f.id = d.fieldid
             WHERE d.userid = :userid
               AND f.shortname = :shortname";
    $val = $DB->get_field_sql($sql, ['userid' => $userid, 'shortname' => $shortname]);
    if ($val === false || $val === null) {
        return null;
    }
    $val = trim(strip_tags((string)$val));
    return ($val === '') ? null : $val;
}

function local_itmabulletin_format_note($value): string {
    if ($value === null || $value === '' || $value === false) {
        return '-';
    }
    return format_float((float)$value, 2);
}

function local_itmabulletin_format_date_session(?string $value): string {
    $v = trim((string)$value);
    return ($v === '') ? '-' : s($v);
}

function local_itmabulletin_credit_from_idnumber(string $idnumber): ?float {
    $parts = explode('-', $idnumber);
    if (count($parts) < 2) {
        return null;
    }
    $last = end($parts);
    return is_numeric($last) ? (float)$last : null;
}

function local_itmabulletin_code_from_ue_idnumber(string $idnumber): ?string {
    $parts = explode('-', $idnumber);
    return $parts[1] ?? null;
}

function local_itmabulletin_clean_ue_name(string $name): string {
    $name = preg_replace('/^UE\s+[A-ZÉÈÊÂÎÔÙÇa-zéèêàïûç]+\s+[–-]\s*/u', '', $name);
    $name = preg_replace('/\([A-Z0-9]+\)\s*$/u', '', $name);
    return trim($name);
}

function local_itmabulletin_type_from_ue_idnumber(string $idnumber): string {
    $parts = explode('-', $idnumber);
    $flag = $parts[2] ?? '';
    switch ($flag) {
        case 'MAJ': return 'UE Majeure';
        case 'MIN': return 'UE Mineure';
        case 'LIB': return 'UE Libre';
        default:    return 'UE';
    }
}

function local_itmabulletin_compute_class_avg($dev1, $dev2): ?float {
    $values = [];
    if ($dev1 !== null && $dev1 !== '') {
        $values[] = (float)$dev1;
    }
    if ($dev2 !== null && $dev2 !== '') {
        $values[] = (float)$dev2;
    }
    if (empty($values)) {
        return null;
    }
    return array_sum($values) / count($values);
}

/**
 * Calcul strict demandé par l'administration :
 * moyenne EC = (2 * note examen + note classe) / 3
 * - si note examen absente => 0
 * - si note classe absente => 0
 * - si rattrapage existe et est meilleur => on prend le rattrapage
 */
function local_itmabulletin_compute_ec_avg(?float $classavg, $exam, $rattrapage): ?float {
    $classvalue = ($classavg === null) ? 0.0 : (float)$classavg;
    $examvalue  = ($exam !== null && $exam !== '') ? (float)$exam : 0.0;
    $rat        = ($rattrapage !== null && $rattrapage !== '') ? (float)$rattrapage : null;

    $normalavg = (2 * $examvalue + $classvalue) / 3.0;

    if ($rat !== null && $rat > $normalavg) {
        return $rat;
    }

    return $normalavg;
}

/**
 * Convertit le code interne du semestre en libellé d'affichage.
 * Ex:
 *  - S1 -> SEMESTRE 1
 *  - D-S1 -> SEMESTRE 1
 *  - S4-GL -> SEMESTRE 4
 *  - SGE-GES-S6-MV -> SEMESTRE 6
 */
function local_itmabulletin_semester_display_label(string $semester): string {
    if (preg_match('/S([1-6])(?:[^0-9]|$)/i', $semester, $m)) {
        return 'SEMESTRE ' . $m[1];
    }
    return 'SEMESTRE';
}

/**
 * HTML bulletin (compatible écran + PDF)
 */
function local_itmabulletin_render_bulletin_html(array $bulletin, string $semester, array $displaydata = []): string {
    global $CFG;

    $student = $bulletin['student'];
    $ues     = $bulletin['ues'];

    // ✅ Matricule = nom d'utilisateur Moodle.
    $matricule = !empty($student->username) ? trim((string)$student->username) : '-';

    $rawdate = local_itmabulletin_get_profile_field((int)$student->id, 'date_naissance');
    $datenaissance = '-';
    if (!empty($rawdate)) {
        $datenaissance = is_numeric($rawdate) ? date('d/m/Y', (int)$rawdate) : $rawdate;
    }

    // Ces infos viennent du formulaire.
    $anneeacademique = $displaydata['anneeuniversitaire'] ?? '2024-2025';
    $filiere         = $displaydata['filiereaffichage'] ?? 'RESEAUX INFORMATIQUES ET TELECOMMUNICATIONS';
    $niveau          = $displaydata['niveauaffichage'] ?? 'Licence 1';
    $semestretitre   = local_itmabulletin_semester_display_label($semester);
    $datebulletin    = date('d/m/Y');

    $logourl = $CFG->wwwroot . '/local/itmabulletin/pix/logo.png';

    // ESPACEMENTS (px)
    $SPACE_AFTER_HEADER   = 10;
    $SPACE_BEFORE_ORANGE  = 14;
    $SPACE_AFTER_ORANGE   = 14;
    $SPACE_AFTER_IDENTITE = 12;
    $SPACE_BEFORE_TABLE   = 10;

    // LARGEURS UNIFORMES (doivent matcher bulletin_manager.php)
    $W_UE     = '44%';
    $W_CREDIT = '7%';
    $W_CLASSE = '15%';
    $W_EXAM   = '15%';
    $W_MOY    = '10%';
    $W_DATE   = '9%';

    $out = '';
    $out .= html_writer::start_div('itmabulletin-wrapper');

    // HEADER
    $out .= '<table class="itmabulletin-header-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
    $out .= '<tr>';

    $out .= '<td class="itmabulletin-header-logo" width="12%" style="width:12%; vertical-align:top;">';
    $out .= '<img src="'.$logourl.'" alt="ITMA" class="itmabulletin-logo" />';
    $out .= '</td>';

    $out .= '<td class="itmabulletin-header-text" width="88%" style="width:88%; vertical-align:top; text-align:center;">';
    $out .= '<div class="itmabulletin-etab-nom"><strong>INSTITUT PRIVE AFRICAIN DE TECHNOLOGIES ET DE MANAGEMENT</strong></div>';
    $out .= '</td>';

    $out .= '</tr>';
    $out .= '</table>';

    $out .= '<div style="height:'.$SPACE_AFTER_HEADER.'px;"></div>';

    // TOP INFO
    $out .= '<table class="itmabulletin-topinfo-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
    $out .= '<tr>';
    $out .= '<td class="itmabulletin-topinfo-left" width="50%" style="width:50%; vertical-align:top;">';
    $out .= '<div>ANNEE UNIVERSITAIRE '.$anneeacademique.'</div>';
    $out .= '<div>'.$filiere.'</div>';
    $out .= '<div>'.$niveau.'</div>';
    $out .= '</td>';
    $out .= '<td class="itmabulletin-topinfo-right" width="50%" style="width:50%; vertical-align:top; text-align:right;">';
    $out .= '<div>Baco-Djicoroni ACI</div>';
    $out .= '<div>TEL : 20281668 / 44215510</div>';
    $out .= '<div class="itmabulletin-bulletin-de"><strong>BULLETIN DE : '.$semestretitre.'</strong></div>';
    $out .= '</td>';
    $out .= '</tr>';
    $out .= '</table>';

    $out .= '<div style="height:'.$SPACE_BEFORE_ORANGE.'px;"></div>';

    // BARRE ORANGE
    $out .= '<div class="itmabulletin-yellow-line" style="margin:0; height:0; border-top:3px solid #ffc000;"></div>';

    $out .= '<div style="height:'.$SPACE_AFTER_ORANGE.'px;"></div>';

    // IDENTITE
    $out .= '<table class="itmabulletin-identite-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; table-layout:fixed;">';

    $out .= '<tr>';
    $out .= '<td width="12%" style="width:12%; white-space:nowrap;"><strong>Nom :</strong></td>';
    $out .= '<td width="43%" style="width:43%;"><strong>'.s($student->lastname).'</strong></td>';
    $out .= '<td width="45%" style="width:45%; text-align:right; white-space:nowrap;"><strong>Matricule :</strong> <strong>'.s($matricule).'</strong></td>';
    $out .= '</tr>';

    $out .= '<tr>';
    $out .= '<td width="12%" style="width:12%; white-space:nowrap;"><strong>Prénom :</strong></td>';
    $out .= '<td width="43%" style="width:43%;"><strong>'.s($student->firstname).'</strong></td>';
    $out .= '<td width="45%" style="width:45%; text-align:right; white-space:nowrap;"><strong>Date naissance :</strong> <strong>'.s($datenaissance).'</strong></td>';
    $out .= '</tr>';

    $out .= '</table>';

    $out .= '<div style="height:'.$SPACE_AFTER_IDENTITE.'px;"></div>';
    $out .= '<div style="height:'.$SPACE_BEFORE_TABLE.'px;"></div>';

    // TABLE NOTES
    $out .= '<table class="generaltable itmabulletin-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; table-layout:fixed;">';

    $out .= '<thead><tr>';
    $out .= '<th width="'.$W_UE.'"     style="width:'.$W_UE.';"><strong>UE/Matière</strong></th>';
    $out .= '<th width="'.$W_CREDIT.'" style="width:'.$W_CREDIT.';"><strong>Crédit</strong></th>';
    $out .= '<th width="'.$W_CLASSE.'" style="width:'.$W_CLASSE.';"><strong>Note Classe</strong></th>';
    $out .= '<th width="'.$W_EXAM.'"   style="width:'.$W_EXAM.';"><strong>Note Examen</strong></th>';
    $out .= '<th width="'.$W_MOY.'"    style="width:'.$W_MOY.';"><strong>Moyenne</strong></th>';
    $out .= '<th width="'.$W_DATE.'"   style="width:'.$W_DATE.';"><strong>Date de Session</strong></th>';
    $out .= '</tr></thead>';

    $out .= '<tbody>';

    $sem_weighted_sum = 0.0;
    $sem_credits_sum  = 0.0;

    foreach ($ues as $ue) {
        $ecs = $ue->ecs ?? [];

        $ue_ec_infos     = [];
        $ue_weighted_sum = 0.0;
        $ue_credits_sum  = 0.0;

        foreach ($ecs as $ec) {
            $notes = $ec->notes ?? [];

            $dev1 = $notes['NOTE_DEV1'] ?? null;
            $dev2 = $notes['NOTE_DEV2'] ?? null;
            $exam = $notes['NOTE_EXAM'] ?? null;
            $rat  = $notes['NOTE_RATTRAPAGE'] ?? null;

            // Date session (texte dans le carnet)
            $datesession = $notes['DATE_SESSION'] ?? null;

            $classavg = local_itmabulletin_compute_class_avg($dev1, $dev2);

            $exam_norm  = ($exam !== null && $exam !== '') ? (float)$exam : 0.0;
            $class_norm = ($classavg !== null) ? (float)$classavg : 0.0;
            $rat_norm   = ($rat !== null && $rat !== '') ? (float)$rat : null;

            $normalavg = (2 * $exam_norm + $class_norm) / 3.0;
            $ecavg     = local_itmabulletin_compute_ec_avg($classavg, $exam, $rat);

            if ($rat_norm !== null) {
                $rat_is_better = ($rat_norm > $normalavg);
                if ($rat_is_better) {
                    $classavg = $rat_norm;
                    $exam     = $rat_norm;
                    $ecavg    = $rat_norm;
                }
            }

            $eccredit = $ec->credit ?? null;
            if ($eccredit === null) {
                $eccredit = local_itmabulletin_credit_from_idnumber($ec->idnumber ?? '');
            }
            $eccredit = $eccredit ? (float)$eccredit : 0.0;

            if ($ecavg !== null && $eccredit > 0) {
                $ue_weighted_sum += $ecavg * $eccredit;
                $ue_credits_sum  += $eccredit;
            }

            $ue_ec_infos[] = (object)[
                'ec'          => $ec,
                'credit'      => $eccredit,
                'classavg'    => $classavg,
                'exam'        => $exam,
                'avg'         => $ecavg,
                'datesession' => $datesession,
            ];
        }

        $ueavg = ($ue_credits_sum > 0) ? ($ue_weighted_sum / $ue_credits_sum) : null;

        // ✅ Arrondi académique UE.
        if ($ueavg !== null) {
            $ueavg = bulletin_manager::apply_academic_rounding((float)$ueavg);
        }

        $uecredit = $ue->credit ?? null;
        if ($uecredit === null) {
            $uecredit = local_itmabulletin_credit_from_idnumber($ue->idnumber ?? '');
        }
        $uecredit = $uecredit ? (float)$uecredit : 0.0;

        if ($ueavg !== null && $uecredit > 0) {
            $sem_weighted_sum += $ueavg * $uecredit;
            $sem_credits_sum  += $uecredit;
        }

        $uecode      = local_itmabulletin_code_from_ue_idnumber($ue->idnumber ?? '');
        $uename      = local_itmabulletin_clean_ue_name($ue->name ?? '');
        $uelabel     = trim(($uecode ? $uecode . ' ' : '') . $uename);
        $uetypelabel = local_itmabulletin_type_from_ue_idnumber($ue->idnumber ?? '');

        // Ligne UE
        $out .= '<tr class="itmabulletin-ue">';
        $out .= '<td class="itmabulletin-col-ue" width="'.$W_UE.'" style="width:'.$W_UE.';"><strong>'.s($uelabel).'</strong></td>';
        $out .= '<td width="'.$W_CREDIT.'" style="width:'.$W_CREDIT.'; text-align:center;"><strong>'.local_itmabulletin_format_note($uecredit).'</strong></td>';
        $out .= '<td width="'.$W_CLASSE.'" style="width:'.$W_CLASSE.'; text-align:center;"><strong>'.s($uetypelabel).'</strong></td>';
        $out .= '<td width="'.$W_EXAM.'" style="width:'.$W_EXAM.'; text-align:center;"><strong>Moyenne U.E</strong></td>';
        $out .= '<td width="'.$W_MOY.'" style="width:'.$W_MOY.'; text-align:center;"><strong>'.local_itmabulletin_format_note($ueavg).'</strong></td>';
        $out .= '<td width="'.$W_DATE.'" style="width:'.$W_DATE.'; text-align:center;">-</td>';
        $out .= '</tr>';

        foreach ($ue_ec_infos as $info) {
            $ec          = $info->ec;
            $eccredit    = $info->credit;
            $classavg    = $info->classavg;
            $examused    = $info->exam;
            $ecavg       = $info->avg;
            $datesession = $info->datesession;

            $out .= '<tr class="itmabulletin-ec">';
            $out .= '<td class="itmabulletin-col-ue" width="'.$W_UE.'" style="width:'.$W_UE.';">'.s($ec->fullname ?? '').'</td>';
            $out .= '<td width="'.$W_CREDIT.'" style="width:'.$W_CREDIT.'; text-align:center;">'.local_itmabulletin_format_note($eccredit).'</td>';
            $out .= '<td width="'.$W_CLASSE.'" style="width:'.$W_CLASSE.'; text-align:center;">'.local_itmabulletin_format_note($classavg).'</td>';
            $out .= '<td width="'.$W_EXAM.'" style="width:'.$W_EXAM.'; text-align:center;">'.local_itmabulletin_format_note($examused).'</td>';
            $out .= '<td width="'.$W_MOY.'" style="width:'.$W_MOY.'; text-align:center;">'.local_itmabulletin_format_note($ecavg).'</td>';
            $out .= '<td width="'.$W_DATE.'" style="width:'.$W_DATE.'; text-align:center;">'.local_itmabulletin_format_date_session($datesession).'</td>';
            $out .= '</tr>';
        }
    }

    $out .= '</tbody></table>';

    if ($sem_credits_sum > 0) {
        $semavg = $sem_weighted_sum / $sem_credits_sum;

        // ✅ Arrondi académique moyenne générale.
        $semavg = bulletin_manager::apply_academic_rounding((float)$semavg);

        $out .= '<div class="itmabulletin-moyenne-generale"><strong>Moyenne Générale : '.local_itmabulletin_format_note($semavg).'</strong></div>';
    }

    $out .= '<div class="itmabulletin-note-basdepage"><strong>NB : La moyenne de validation de chaque UE doit être supérieure ou égale à 12</strong></div>';

    $out .= '<table class="itmabulletin-signatures" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
    $out .= '<tr><td width="50%" style="width:50%;"></td>';
    $out .= '<td width="50%" style="width:50%; text-align:right;">Bamako le : '.$datebulletin.'</td></tr>';
    $out .= '<tr><td width="50%" style="width:50%;"><strong>Le Directeur Général</strong></td>';
    $out .= '<td width="50%" style="width:50%; text-align:right;"><strong>Le Directeur Académique Adjoint</strong></td></tr>';
    $out .= '</table>';

    $out .= html_writer::end_div();
    return $out;
}

// -----------------------------------------------------------------------------
// Form handling
// -----------------------------------------------------------------------------
$userid      = optional_param('userid', 0, PARAM_INT);
$semester    = optional_param('semester', '', PARAM_RAW_TRIMMED);
$cohortid    = optional_param('cohortid', 0, PARAM_INT);
$download    = optional_param('download', 0, PARAM_BOOL);
$downloadzip = optional_param('downloadzip', 0, PARAM_BOOL);
$mode        = optional_param('mode', 'student', PARAM_ALPHA);

// Champs pilotés par le formulaire.
$anneeuniversitaire = optional_param('anneeuniversitaire', '2024-2025', PARAM_TEXT);
$filiereaffichage   = optional_param('filiereaffichage', 'RESEAUX INFORMATIQUES ET TELECOMMUNICATIONS', PARAM_TEXT);
$niveauaffichage    = optional_param('niveauaffichage', 'Licence 1', PARAM_TEXT);

$allowed = bulletin_manager::get_allowed_semester_codes();
if (!empty($semester) && !in_array($semester, $allowed, true)) {
    $semester = '';
}

// Form
$mform = new selection_form(null, ['defaultsemester' => 'S1']);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/'));
}

$clicked = null; // 'student' | 'cohort' | null
if ($data = $mform->get_data()) {
    $semester = (string)$data->semester;

    if (property_exists($data, 'mode') && !empty($data->mode)) {
        $mode = (string)$data->mode;
    }

    if (property_exists($data, 'anneeuniversitaire')) {
        $anneeuniversitaire = (string)$data->anneeuniversitaire;
    }
    if (property_exists($data, 'filiereaffichage')) {
        $filiereaffichage = (string)$data->filiereaffichage;
    }
    if (property_exists($data, 'niveauaffichage')) {
        $niveauaffichage = (string)$data->niveauaffichage;
    }

    if (property_exists($data, 'submit_cohort') && !empty($data->submit_cohort)) {
        $clicked = 'cohort';
    } else if (property_exists($data, 'submit_student') && !empty($data->submit_student)) {
        $clicked = 'student';
    } else {
        $clicked = $mode;
    }

    if ($clicked === 'cohort') {
        $cohortid = property_exists($data, 'cohortid') ? (int)$data->cohortid : 0;
        $userid   = 0;
        $downloadzip = 1;
    } else {
        $userid   = property_exists($data, 'userid') ? (int)$data->userid : 0;
        $cohortid = 0;
    }
}

$displaydata = [
    'anneeuniversitaire' => $anneeuniversitaire,
    'filiereaffichage'   => $filiereaffichage,
    'niveauaffichage'    => $niveauaffichage,
];

// -----------------------------------------------------------------------------
// COHORTE ZIP (prioritaire)
// -----------------------------------------------------------------------------
if ($downloadzip) {
    require_sesskey();

    if (!$cohortid || empty($semester)) {
        throw new moodle_exception('Cohorte ou semestre manquant.');
    }

    bulletin_manager::export_cohort_bulletins_zip(
        (int)$cohortid,
        (string)$semester,
        function(int $uid, string $sem) use ($displaydata) {
            $b = bulletin_manager::build_semester_bulletin($uid, $sem);
            if (empty($b['student']) || empty($b['ues'])) {
                return '';
            }
            return local_itmabulletin_render_bulletin_html($b, $sem, $displaydata);
        }
    );
    exit;
}

// -----------------------------------------------------------------------------
// Si pas d'étudiant/semestre -> afficher form
// -----------------------------------------------------------------------------
if (!$userid || empty($semester)) {
    echo $OUTPUT->header();
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// -----------------------------------------------------------------------------
// 1 étudiant
// -----------------------------------------------------------------------------
$bulletin = bulletin_manager::build_semester_bulletin($userid, $semester);

if (!$bulletin['student']) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification("Utilisateur introuvable (ID $userid).", 'notifyproblem');
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

if (empty($bulletin['ues'])) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        "Aucune UE trouvée pour le semestre « $semester ». Vérifie l'idnumber de la catégorie semestre (par ex. IRT1-S1).",
        'notifyproblem'
    );
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

$bulletinhtml = local_itmabulletin_render_bulletin_html($bulletin, $semester, $displaydata);

if ($download) {
    require_sesskey();
    bulletin_manager::export_bulletin_pdf($userid, $semester, $bulletinhtml);
    exit;
}

// Affichage HTML
echo $OUTPUT->header();
echo $bulletinhtml;

// Bouton PDF
$pdfurl = new moodle_url('/local/itmabulletin/index.php', [
    'userid'              => $userid,
    'semester'            => $semester,
    'anneeuniversitaire'  => $anneeuniversitaire,
    'filiereaffichage'    => $filiereaffichage,
    'niveauaffichage'     => $niveauaffichage,
    'download'            => 1,
    'sesskey'             => sesskey(),
]);

echo html_writer::div(
    html_writer::link($pdfurl, 'Télécharger le bulletin en PDF', [
        'class' => 'btn btn-secondary itmabulletin-pdfbtn'
    ]),
    'itmabulletin-actions'
);

echo html_writer::empty_tag('hr');
$mform->display();
echo $OUTPUT->footer();