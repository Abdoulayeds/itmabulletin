<?php
require_once(__DIR__ . '/../../config.php');

use local_itmabulletin\bulletin_manager;
use local_itmabulletin\form\selection_form;

require_login();
$context = context_system::instance();

require_capability('local/itmabulletin:generate', $context);

$PAGE->set_url(new moodle_url('/local/itmabulletin/index.php'));
$PAGE->set_context($context);
$PAGE->requires->css('/local/itmabulletin/styles.css');
$PAGE->set_title('GÃ©nÃ©ration des bulletins ITMA');
$PAGE->set_heading('GÃ©nÃ©ration des bulletins ITMA');

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

function local_itmabulletin_format_ue_code(?string $code): ?string {
    $code = trim((string)$code);
    if ($code === '') {
        return null;
    }

    if (preg_match('/^([A-Za-z]+)([0-9]+)$/', $code, $m)) {
        return mb_strtoupper($m[1], 'UTF-8') . '-' . $m[2];
    }

    return mb_strtoupper($code, 'UTF-8');
}

function local_itmabulletin_clean_ue_name(string $name): string {
    $name = preg_replace('/^UE\s+[A-ZÃƒâ€°ÃƒË†ÃƒÅ Ãƒâ€šÃƒÅ½Ãƒâ€Ãƒâ„¢Ãƒâ€¡a-zÃƒÂ©ÃƒÂ¨ÃƒÂªÃƒÂ ÃƒÂ¯ÃƒÂ»ÃƒÂ§]+\s+[Ã¢â‚¬â€œ-]\s*/u', '', $name);
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
 * Calcul strict demandÃƒÂ© par l'administration :
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
 * Convertit le code interne du semestre en libellÃƒÂ© d'affichage.
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
 * HTML bulletin (compatible ÃƒÂ©cran + PDF)
 */
function local_itmabulletin_render_bulletin_html(array $bulletin, string $semester, array $displaydata = [], bool $officialissue = false): string {
    global $CFG;

    $student = $bulletin['student'];
    $ues     = $bulletin['ues'];

    // Ã¢Å“â€¦ Matricule = nom d'utilisateur Moodle.
    $matricule = !empty($student->username) ? mb_strtoupper(trim((string)$student->username), 'UTF-8') : '-';

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
    $juryvalidatedueids = bulletin_manager::get_jury_validated_ueids((int)$student->id, $semester);

    $logourl = $CFG->wwwroot . '/local/itmabulletin/pix/logo.png';

    // ESPACEMENTS (px)
    $SPACE_AFTER_HEADER   = 8;
    $SPACE_BEFORE_ORANGE  = 12;
    $SPACE_AFTER_ORANGE   = 18;
    $SPACE_AFTER_IDENTITE = 8;
    $SPACE_BEFORE_TABLE   = 6;

    // LARGEURS UNIFORMES (doivent matcher bulletin_manager.php)
    $W_UE     = '46%';
    $W_CREDIT = '10%';
    $W_CLASSE = '15%';
    $W_EXAM   = '14%';
    $W_MOY    = '15%';

    $out = '';
    $out .= html_writer::start_div('itmabulletin-wrapper');

    // HEADER
    $out .= '<div class="itmabulletin-official-header">';
    $out .= '<table class="itmabulletin-official-header-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; table-layout:fixed;">';
    $out .= '<tr>';
    $out .= '<td class="itmabulletin-official-logo-cell" colspan="3" width="100%" style="width:100%; vertical-align:top; text-align:left;">';
    $out .= '<img src="'.$logourl.'" alt="ITMA" class="itmabulletin-logo itmabulletin-logo-official" />';
    $out .= '</td>';
    $out .= '</tr>';
    $out .= '<tr>';
    $out .= '<td class="itmabulletin-official-name-cell" colspan="3" width="100%" style="width:100%; vertical-align:middle; text-align:center;">';
    $out .= '<div class="itmabulletin-etab-nom"><strong>INSTITUT PRIVE AFRICAIN DE TECHNOLOGIES ET DE MANAGEMENT</strong></div>';
    $out .= '</td>';
    $out .= '</tr>';
    $out .= '</table>';
    $out .= '</div>';

    $out .= '<div style="height:'.$SPACE_AFTER_HEADER.'px;"></div>';
    $out .= '<div class="itmabulletin-bulletin-title-center"><strong>BULLETIN DU '.s(mb_strtoupper($semestretitre, 'UTF-8')).'</strong></div>';
    $out .= '<div style="height:'.$SPACE_BEFORE_ORANGE.'px;"></div>';
    $out .= '<div class="itmabulletin-yellow-line" style="margin:0; height:0; border-top:3px solid #ffc000;"></div>';
    $out .= '<div style="height:'.$SPACE_AFTER_ORANGE.'px;"></div>';

    // TOP INFO
    $out .= '<table class="itmabulletin-topinfo-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
    $out .= '<tr>';
    $out .= '<td class="itmabulletin-topinfo-left" width="50%" style="width:50%; vertical-align:top;">';
    $out .= '<div class="itmabulletin-info-line">ANNEE UNIVERSITAIRE '.s($anneeacademique).'</div>';
    $out .= '<div class="itmabulletin-info-line">'.s($filiere).'</div>';
    $out .= '<div class="itmabulletin-info-line">'.s(mb_strtoupper($niveau, 'UTF-8')).'</div>';
    $out .= '</td>';
    $out .= '<td class="itmabulletin-topinfo-right" width="50%" style="width:50%; vertical-align:top; text-align:right;">';
    $out .= '<div class="itmabulletin-info-line">Baco-Djicoroni ACI</div>';
    $out .= '<div class="itmabulletin-info-line">TEL : 20281668 / 44215510</div>';
    $out .= '</td>';
    $out .= '</tr>';
    $out .= '</table>';

    // IDENTITE
    $out .= '<table class="itmabulletin-identite-table" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border-collapse:collapse; table-layout:fixed;">';
    $out .= '<tr>';
    $out .= '<td width="55%" style="width:55%; border:0; padding:3px 4px; white-space:normal;"><strong>Nom :</strong> <strong>'.s($student->lastname).'</strong></td>';
    $out .= '<td width="45%" style="width:45%; border:0; padding:3px 4px; text-align:right; white-space:normal;"><strong>Matricule :</strong> <strong>'.s($matricule).'</strong></td>';
    $out .= '</tr>';
    $out .= '<tr>';
    $out .= '<td width="55%" style="width:55%; border:0; padding:3px 4px; white-space:normal;"><strong>PrÃ©nom :</strong> <strong>'.s($student->firstname).'</strong></td>';
    $out .= '<td width="45%" style="width:45%; border:0; padding:3px 4px; text-align:right; white-space:normal;"><strong>Date naissance :</strong> <strong>'.s($datenaissance).'</strong></td>';
    $out .= '</tr>';
    $out .= '</table>';

    $out .= '<div style="height:'.$SPACE_AFTER_IDENTITE.'px;"></div>';
    $out .= '<div style="height:'.$SPACE_BEFORE_TABLE.'px;"></div>';

    // TABLE NOTES
    $out .= '<table class="generaltable itmabulletin-table" width="96%" cellspacing="0" cellpadding="0" style="width:96%; margin-left:auto; margin-right:auto; border-collapse:collapse; table-layout:fixed;">';

    $out .= '<thead><tr>';
    $out .= '<th width="'.$W_UE.'"     style="width:'.$W_UE.';"><strong>UE/EC</strong></th>';
    $out .= '<th width="'.$W_CREDIT.'" style="width:'.$W_CREDIT.';"><strong>CrÃ©dit</strong></th>';
    $out .= '<th width="'.$W_CLASSE.'" style="width:'.$W_CLASSE.';"><strong>Moy. classe</strong></th>';
    $out .= '<th width="'.$W_EXAM.'"   style="width:'.$W_EXAM.';"><strong>Note examen</strong></th>';
    $out .= '<th width="'.$W_MOY.'"    style="width:'.$W_MOY.';"><strong>Moyenne</strong></th>';
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
            ];
        }

        $ueavg = ($ue_credits_sum > 0) ? ($ue_weighted_sum / $ue_credits_sum) : null;

        // Ã¢Å“â€¦ Arrondi acadÃƒÂ©mique UE.
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
        $displaycode = local_itmabulletin_format_ue_code($uecode);
        $uename      = local_itmabulletin_clean_ue_name($ue->name ?? '');
        $uelabel     = trim(($displaycode ? $displaycode . ' ' : '') . $uename);
        $uetypelabel = local_itmabulletin_type_from_ue_idnumber($ue->idnumber ?? '');
        $juryvalidated = ($ueavg !== null && $ueavg < 12.0 && !empty($juryvalidatedueids[(int)$ue->id]));
        $ueavglabel = local_itmabulletin_format_note($ueavg) . ($juryvalidated ? ' *' : '');

        // Ligne UE
        $out .= '<tr class="itmabulletin-ue">';
        $out .= '<td class="itmabulletin-col-ue" width="'.$W_UE.'" style="width:'.$W_UE.';"><strong>'.s($uelabel).'</strong></td>';
        $out .= '<td width="'.$W_CREDIT.'" style="width:'.$W_CREDIT.'; text-align:center;"><strong>'.local_itmabulletin_format_note($uecredit).'</strong></td>';
        $out .= '<td colspan="2" class="itmabulletin-ue-type" style="text-align:center;"><strong>'.s($uetypelabel).'</strong></td>';
        $out .= '<td width="'.$W_MOY.'" style="width:'.$W_MOY.'; text-align:center;"><strong>'.s($ueavglabel).'</strong></td>';
        $out .= '</tr>';

        foreach ($ue_ec_infos as $info) {
            $ec          = $info->ec;
            $eccredit    = $info->credit;
            $classavg    = $info->classavg;
            $examused    = $info->exam;
            $ecavg       = $info->avg;

            $out .= '<tr class="itmabulletin-ec">';
            $out .= '<td class="itmabulletin-col-ue" width="'.$W_UE.'" style="width:'.$W_UE.';">'.s($ec->fullname ?? '').'</td>';
   Ûmv¶‰žËkºwµç@€€t¤ì4(4(€€€€‘¡¥‘‘•¸€ôl4(€€€€€€€€ÕÍ•É¥œ€ôø€‘ÕÍ•É¥°4(€€€€€€€€Í•µ•ÍÑ•Èœ€ôø€‘Í•µ•ÍÑ•È°4(€€€€€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”t€üü€œœ°4(€€€€€€€€™¥±¥•É•…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l™¥±¥•É•…™™¥¡…”t€üü€œœ°4(€€€€€€€€¹¥Ù•…Õ…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l¹¥Ù•…Õ…™™¥¡…”t€üü€œœ°4(€€€€€€€€Í…Ù•©ÕÉäœ€ôø€Ä°4(€€€€€€€€Í•ÍÍ­•äœ€ôøÍ•ÍÍ­•ä ¤°4(€€€tì4(4(€€€™½É•… € ‘¡¥‘‘•¸…Ì€‘¹…µ”€ôø€‘Ù…±Õ”¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€€€€€ÑåÁ”œ€ôø€¡¥‘‘•¸œ°4(€€€€€€€€€€€€¹…µ”œ€ôø€‘¹…µ”°4(€€€€€€€€€€€€Ù…±Õ”œ€ôø€‘Ù…±Õ”°4(€€€€€€€t¤ì4(€€€ô4(4(€€€™½É•… € ‘…¹‘¥‘…Ñ•Ì…Ì€‘Õ•¥€ôø€‘¥Ñ•´¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€€€€€ÑåÁ”œ€ôø€¡¥‘‘•¸œ°4(€€€€€€€€€€€€¹…µ”œ€ôø€©ÕÉå…¹‘¥‘…Ñ•Ímtœ°4(€€€€€€€€€€€€Ù…±Õ”œ€ôø€‘Õ•¥°4(€€€€€€€t¤ì4(4(€€€€€€€€‘¡•­‰½à€ô¡Ñµ±}ÝÉ¥Ñ•Èèé¡•­‰½à 4(€€€€€€€€€€€€©ÕÉå…ÁÁÉ½Ù•‘mtœ°4(€€€€€€€€€€€€‘Õ•¥°4(€€€€€€€€€€€€…•µÁÑä ‘Ù…±¥‘…Ñ•‘Õ•¥‘Íl‘Õ•¥‘t¤°4(€€€€€€€€€€€Ì ‘¥Ñ•µl±…‰•°t¤€¸€œ€´µ½å•¹¹”€œ€¸±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}™½Éµ…Ñ}¹½Ñ” ‘¥Ñ•µl…Ùœt¤4(€€€€€€€€¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø ‘¡•­‰½à°€™½É´µ¡•¬œ¤ì4(€€€ô4(4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€ÑåÁ”œ€ôø€ÍÕ‰µ¥Ðœ°4(€€€€€€€€±…ÍÌœ€ôø€‰Ñ¸‰Ñ¸µÁÉ¥µ…Éäœ°4(€€€€€€€€Ù…±Õ”œ€ôø€¹É•¥ÍÑÉ•È±•ÌÙ…±¥‘…Ñ¥½¹ÌÁ…È©ÕÉäœ°4(€€€t¤ì4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}Ñ…œ ™½É´œ¤ì4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(4(€€€É•ÑÕÉ¸€‘½ÕÐì4)ô4(4)™Õ¹Ñ¥½¸±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}½¡½ÉÑ}©ÕÉå}Á…” 4(€€€¥¹Ð€‘½¡½ÉÑ¥°4(€€€ÍÑÉ¥¹œ€‘Í•µ•ÍÑ•È°4(€€€…ÉÉ…ä€‘‘¥ÍÁ±…å‘…Ñ„4(¤èÍÑÉ¥¹œì4(€€€€‘ÕÍ•ÉÌ€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé•Ñ}½¡½ÉÑ}ÕÍ•ÉÌ ‘½¡½ÉÑ¥¤ì4(€€€¥˜€¡•µÁÑä ‘ÕÍ•ÉÌ¤¤ì4(€€€€€€€É•ÑÕÉ¸¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø ÕÕ¸•ÑÕ‘¥…¹ÐÑÉ½ÕÙ”‘…¹Ì•ÑÑ”½¡½ÉÑ”¸œ°€…±•ÉÐ…±•ÉÐµÝ…É¹¥¹œœ¤ì4(€€€ô4(4(€€€€‘½ÕÐ€ô¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ™½É´µ…Éœ¤ì4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ  Ìœ°€Y…±¥‘…Ñ¥½¸Á…È©ÕÉä€´½¡½ÉÑ”œ¤ì4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ Àœ°€½¡•è±•ÌUÙ…±¥‘••ÌÁ…È‘•¥Í¥½¸‘Ô©ÕÉä…Ù…¹Ð‘”Ñ•±•¡…É•È±”i%@‘•Ì‰Õ±±•Ñ¥¹Ì¸œ¤ì4(4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}Ñ…œ ™½É´œ°l4(€€€€€€€€µ•Ñ¡½œ€ôø€Á½ÍÐœ°4(€€€€€€€€…Ñ¥½¸œ€ôø¹•Üµ½½‘±•}ÕÉ° œ½±½…°½¥Ñµ…‰Õ±±•Ñ¥¸½¥¹‘•à¹Á¡Àœ¤°4(€€€t¤ì4(4(€€€€‘¡¥‘‘•¸€ôl4(€€€€€€€€µ½‘”œ€ôø€½¡½ÉÐœ°4(€€€€€€€€½¡½ÉÑ¥œ€ôø€‘½¡½ÉÑ¥°4(€€€€€€€€Í•µ•ÍÑ•Èœ€ôø€‘Í•µ•ÍÑ•È°4(€€€€€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”t€üü€œœ°4(€€€€€€€€™¥±¥•É•…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l™¥±¥•É•…™™¥¡…”t€üü€œœ°4(€€€€€€€€¹¥Ù•…Õ…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l¹¥Ù•…Õ…™™¥¡…”t€üü€œœ°4(€€€€€€€€Í…Ù•½¡½ÉÑ©ÕÉäœ€ôø€Ä°4(€€€€€€€€Í•ÍÍ­•äœ€ôøÍ•ÍÍ­•ä ¤°4(€€€tì4(4(€€€™½É•… € ‘¡¥‘‘•¸…Ì€‘¹…µ”€ôø€‘Ù…±Õ”¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€€€€€ÑåÁ”œ€ôø€¡¥‘‘•¸œ°4(€€€€€€€€€€€€¹…µ”œ€ôø€‘¹…µ”°4(€€€€€€€€€€€€Ù…±Õ”œ€ôø€‘Ù…±Õ”°4(€€€€€€€t¤ì4(€€€ô4(4(€€€€‘¡…Í…¹‘¥‘…Ñ•Ì€ô™…±Í”ì4(€€€™½É•… € ‘ÕÍ•ÉÌ…Ì€‘ÕÍ•È¤ì4(€€€€€€€€‘‰Õ±±•Ñ¥¸€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé‰Õ¥±‘}Í•µ•ÍÑ•É}‰Õ±±•Ñ¥¸ ¡¥¹Ð¤‘ÕÍ•È´ù¥°€‘Í•µ•ÍÑ•È¤ì4(€€€€€€€¥˜€¡•µÁÑä ‘‰Õ±±•Ñ¥¹lÍÑÕ‘•¹Ðt¤ñð•µÁÑä ‘‰Õ±±•Ñ¥¹lÕ•Ìt¤¤ì4(€€€€€€€€€€€½¹Ñ¥¹Õ”ì4(€€€€€€€ô4(4(€€€€€€€€‘…¹‘¥‘…Ñ•Ì€ô±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}•Ñ}©ÕÉå}…¹‘¥‘…Ñ•}Õ•Ì ‘‰Õ±±•Ñ¥¸¤ì4(€€€€€€€¥˜€¡•µÁÑä ‘…¹‘¥‘…Ñ•Ì¤¤ì4(€€€€€€€€€€€½¹Ñ¥¹Õ”ì4(€€€€€€€ô4(4(€€€€€€€€‘¡…Í…¹‘¥‘…Ñ•Ì€ôÑÉÕ”ì4(€€€€€€€€‘Ù…±¥‘…Ñ•€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé•Ñ}©ÕÉå}Ù…±¥‘…Ñ•‘}Õ•¥‘Ì ¡¥¹Ð¤‘ÕÍ•È´ù¥°€‘Í•µ•ÍÑ•È¤ì4(4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€€€€€ÑåÁ”œ€ôø€¡¥‘‘•¸œ°4(€€€€€€€€€€€€¹…µ”œ€ôø€½¡½ÉÑÕÍ•ÉÍmtœ°4(€€€€€€€€€€€€Ù…±Õ”œ€ôø€¡¥¹Ð¤‘ÕÍ•È´ù¥°4(€€€€€€€t¤ì4(4(€€€€€€€€‘ÍÑÕ‘•¹Ñ±…‰•°€ô™Õ±±¹…µ” ‘ÕÍ•È¤€¸€ …•µÁÑä ‘ÕÍ•È´ùÕÍ•É¹…µ”¤€ü€œ€ œ€¸Ì ‘ÕÍ•È´ùÕÍ•É¹…µ”¤€¸€œ¤œ€è€œœ¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ  Ðœ°€‘ÍÑÕ‘•¹Ñ±…‰•°¤ì4(4(€€€€€€€™½É•… € ‘…¹‘¥‘…Ñ•Ì…Ì€‘Õ•¥€ôø€‘¥Ñ•´¤ì4(€€€€€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€€€€€€€€€ÑåÁ”œ€ôø€¡¥‘‘•¸œ°4(€€€€€€€€€€€€€€€€¹…µ”œ€ôø€©ÕÉå…¹‘¥‘…Ñ•Í|œ€¸€¡¥¹Ð¤‘ÕÍ•È´ù¥€¸€mtœ°4(€€€€€€€€€€€€€€€€Ù…±Õ”œ€ôø€‘Õ•¥°4(€€€€€€€€€€€t¤ì4(4(€€€€€€€€€€€€‘¡•­‰½à€ô¡Ñµ±}ÝÉ¥Ñ•Èèé¡•­‰½à 4(€€€€€€€€€€€€€€€€©ÕÉå…ÁÁÉ½Ù•‘|œ€¸€¡¥¹Ð¤‘ÕÍ•È´ù¥€¸€mtœ°4(€€€€€€€€€€€€€€€€‘Õ•¥°4(€€€€€€€€€€€€€€€€…•µÁÑä ‘Ù…±¥‘…Ñ•‘l‘Õ•¥‘t¤°4(€€€€€€€€€€€€€€€Ì ‘¥Ñ•µl±…‰•°t¤€¸€œ€´µ½å•¹¹”€œ€¸±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}™½Éµ…Ñ}¹½Ñ” ‘¥Ñ•µl…Ùœt¤4(€€€€€€€€€€€€¤ì4(€€€€€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø ‘¡•­‰½à°€™½É´µ¡•¬œ¤ì4(€€€€€€€ô4(€€€ô4(4(€€€¥˜€ „‘¡…Í…¹‘¥‘…Ñ•Ì¤ì4(€€€€€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø ÕÕ¹”U¥¹™•É¥•ÕÉ”„€ÄÈÑÉ½ÕÙ•”Á½ÕÈ±•Ì•ÑÕ‘¥…¹ÑÌ‘”•ÑÑ”½¡½ÉÑ”¸œ°€…±•ÉÐ…±•ÉÐµ¥¹™¼œ¤ì4(€€€ô4(4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¥¹ÁÕÐœ°l4(€€€€€€€€ÑåÁ”œ€ôø€ÍÕ‰µ¥Ðœ°4(€€€€€€€€±…ÍÌœ€ôø€‰Ñ¸‰Ñ¸µÁÉ¥µ…Éäœ°4(€€€€€€€€Ù…±Õ”œ€ôø€¹É•¥ÍÑÉ•È±•ÌÙ…±¥‘…Ñ¥½¹ÌÁ…È©ÕÉäœ°4(€€€t¤ì4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}Ñ…œ ™½É´œ¤ì4(4(€€€€‘é¥ÁÕÉ°€ô¹•Üµ½½‘±•}ÕÉ° œ½±½…°½¥Ñµ…‰Õ±±•Ñ¥¸½¥¹‘•à¹Á¡Àœ°l4(€€€€€€€€µ½‘”œ€ôø€½¡½ÉÐœ°4(€€€€€€€€½¡½ÉÑ¥œ€ôø€‘½¡½ÉÑ¥°4(€€€€€€€€Í•µ•ÍÑ•Èœ€ôø€‘Í•µ•ÍÑ•È°4(€€€€€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”t€üü€œœ°4(€€€€€€€€™¥±¥•É•…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l™¥±¥•É•…™™¥¡…”t€üü€œœ°4(€€€€€€€€¹¥Ù•…Õ…™™¥¡…”œ€ôø€‘‘¥ÍÁ±…å‘…Ñ…l¹¥Ù•…Õ…™™¥¡…”t€üü€œœ°4(€€€€€€€€‘½Ý¹±½…‘é¥Àœ€ôø€Ä°4(€€€€€€€€Í•ÍÍ­•äœ€ôøÍ•ÍÍ­•ä ¤°4(€€€t¤ì4(4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø 4(€€€€€€€¡Ñµ±}ÝÉ¥Ñ•Èèé±¥¹¬ ‘é¥ÁÕÉ°°€Q•±•¡…É•È±”i%@‘•Ì‰Õ±±•Ñ¥¹Ìœ°l±…ÍÌœ€ôø€‰Ñ¸‰Ñ¸µÍ•½¹‘…Éät¤°4(€€€€€€€€¥Ñµ…‰Õ±±•Ñ¥¸µ…Ñ¥½¹Ìœ4(€€€€¤ì4(4(€€€€‘½ÕÐ€¸ô¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(4(€€€É•ÑÕÉ¸€‘½ÕÐì4)ô4(4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(¼¼½É´¡…¹‘±¥¹œ4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(‘ÕÍ•É¥€€€€€€ô½ÁÑ¥½¹…±}Á…É…´ ÕÍ•É¥œ°€À°AI5}%9P¤ì4(‘Í•µ•ÍÑ•È€€€€ô½ÁÑ¥½¹…±}Á…É…´ Í•µ•ÍÑ•Èœ°€œœ°AI5}I]}QI%55¤ì4(‘½¡½ÉÑ¥€€€€ô½ÁÑ¥½¹…±}Á…É…´ ½¡½ÉÑ¥œ°€À°AI5}%9P¤ì4(‘‘½Ý¹±½…€€€€ô½ÁÑ¥½¹…±}Á…É…´ ‘½Ý¹±½…œ°€À°AI5}	==0¤ì4(‘‘½Ý¹±½…‘é¥À€ô½ÁÑ¥½¹…±}Á…É…´ ‘½Ý¹±½…‘é¥Àœ°€À°AI5}	==0¤ì4(‘Í…Ù•©ÕÉä€€€€ô½ÁÑ¥½¹…±}Á…É…´ Í…Ù•©ÕÉäœ°€À°AI5}	==0¤ì4(‘Í…Ù•½¡½ÉÑ©ÕÉä€ô½ÁÑ¥½¹…±}Á…É…´ Í…Ù•½¡½ÉÑ©ÕÉäœ°€À°AI5}	==0¤ì4(‘©ÕÉå…ÁÁÉ½Ù•€ô½ÁÑ¥½¹…±}Á…É…µ}…ÉÉ…ä ©ÕÉå…ÁÁÉ½Ù•œ°mt°AI5}%9P¤ì4(‘©ÕÉå…¹‘¥‘…Ñ•Ì€ô½ÁÑ¥½¹…±}Á…É…µ}…ÉÉ…ä ©ÕÉå…¹‘¥‘…Ñ•Ìœ°mt°AI5}%9P¤ì4(‘½¡½ÉÑÕÍ•ÉÌ€ô½ÁÑ¥½¹…±}Á…É…µ}…ÉÉ…ä ½¡½ÉÑÕÍ•ÉÌœ°mt°AI5}%9P¤ì4(‘µ½‘”€€€€€€€€ô½ÁÑ¥½¹…±}Á…É…´ µ½‘”œ°€ÍÑÕ‘•¹Ðœ°AI5}1A!¤ì4(4(¼¼¡…µÁÌÁ¥±½Ó
¥ÌÁ…È±”™½ÉµÕ±…¥É”¸4(‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”€ô½ÁÑ¥½¹…±}Á…É…´ …¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ°€œÈÀÈÐ´ÈÀÈÔœ°AI5}QaP¤ì4(‘™¥±¥•É•…™™¥¡…”€€€ô½ÁÑ¥½¹…±}Á…É…´ ™¥±¥•É•…™™¥¡…”œ°€IMU`%9=I5Q%EULPQ1=55U9%Q%=9Lœ°AI5}QaP¤ì4(‘¹¥Ù•…Õ…™™¥¡…”€€€€ô½ÁÑ¥½¹…±}Á…É…´ ¹¥Ù•…Õ…™™¥¡…”œ°€1¥•¹”€Äœ°AI5}QaP¤ì4(4(‘…±±½Ý•€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé•Ñ}…±±½Ý•‘}Í•µ•ÍÑ•É}½‘•Ì ¤ì4)¥˜€ …•µÁÑä ‘Í•µ•ÍÑ•È¤€˜˜€…¥¹}…ÉÉ…ä ‘Í•µ•ÍÑ•È°€‘…±±½Ý•°ÑÉÕ”¤¤ì4(€€€€‘Í•µ•ÍÑ•È€ô€œœì4)ô4(4(¼¼½É´4(‘µ™½É´€ô¹•ÜÍ•±•Ñ¥½¹}™½É´¡¹Õ±°°l‘•™…Õ±ÑÍ•µ•ÍÑ•Èœ€ôø€LÄt¤ì4(4)¥˜€ ‘µ™½É´´ù¥Í}…¹•±±• ¤¤ì4(€€€É•‘¥É•Ð¡¹•Üµ½½‘±•}ÕÉ° œ¼œ¤¤ì4)ô4(4(‘±¥­•€ô¹Õ±°ì€¼¼€ÍÑÕ‘•¹Ðœð€½¡½ÉÐœð¹Õ±°4)¥˜€ ‘‘…Ñ„€ô€‘µ™½É´´ù•Ñ}‘…Ñ„ ¤¤ì4(€€€€‘Í•µ•ÍÑ•È€ô€¡ÍÑÉ¥¹œ¤‘‘…Ñ„´ùÍ•µ•ÍÑ•Èì4(4(€€€¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€µ½‘”œ¤€˜˜€…•µÁÑä ‘‘…Ñ„´ùµ½‘”¤¤ì4(€€€€€€€€‘µ½‘”€ô€¡ÍÑÉ¥¹œ¤‘‘…Ñ„´ùµ½‘”ì4(€€€ô4(4(€€€¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ¤¤ì4(€€€€€€€€‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”€ô€¡ÍÑÉ¥¹œ¤‘‘…Ñ„´ù…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”ì4(€€€ô4(€€€¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€™¥±¥•É•…™™¥¡…”œ¤¤ì4(€€€€€€€€‘™¥±¥•É•…™™¥¡…”€ô€¡ÍÑÉ¥¹œ¤‘‘…Ñ„´ù™¥±¥•É•…™™¥¡…”ì4(€€€ô4(€€€¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€¹¥Ù•…Õ…™™¥¡…”œ¤¤ì4(€€€€€€€€‘¹¥Ù•…Õ…™™¥¡…”€ô€¡ÍÑÉ¥¹œ¤‘‘…Ñ„´ù¹¥Ù•…Õ…™™¥¡…”ì4(€€€ô4(4(€€€¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€ÍÕ‰µ¥Ñ}½¡½ÉÐœ¤€˜˜€…•µÁÑä ‘‘…Ñ„´ùÍÕ‰µ¥Ñ}½¡½ÉÐ¤¤ì4(€€€€€€€€‘±¥­•€ô€½¡½ÉÐœì4(€€€ô•±Í”¥˜€¡ÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€ÍÕ‰µ¥Ñ}ÍÑÕ‘•¹Ðœ¤€˜˜€…•µÁÑä ‘‘…Ñ„´ùÍÕ‰µ¥Ñ}ÍÑÕ‘•¹Ð¤¤ì4(€€€€€€€€‘±¥­•€ô€ÍÑÕ‘•¹Ðœì4(€€€ô•±Í”ì4(€€€€€€€€‘±¥­•€ô€‘µ½‘”ì4(€€€ô4(4(€€€¥˜€ ‘±¥­•€ôôô€½¡½ÉÐœ¤ì4(€€€€€€€€‘½¡½ÉÑ¥€ôÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€½¡½ÉÑ¥œ¤€ü€¡¥¹Ð¤‘‘…Ñ„´ù½¡½ÉÑ¥€è€Àì4(€€€€€€€€‘ÕÍ•É¥€€€ô€Àì4(€€€ô•±Í”ì4(€€€€€€€€‘ÕÍ•É¥€€€ôÁÉ½Á•ÉÑå}•á¥ÍÑÌ ‘‘…Ñ„°€ÕÍ•É¥œ¤€ü€¡¥¹Ð¤‘‘…Ñ„´ùÕÍ•É¥€è€Àì4(€€€€€€€€‘½¡½ÉÑ¥€ô€Àì4(€€€ô4)ô4(4(‘‘¥ÍÁ±…å‘…Ñ„€ôl4(€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€ôø€‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”°4(€€€€™¥±¥•É•…™™¥¡…”œ€€€ôø€‘™¥±¥•É•…™™¥¡…”°4(€€€€¹¥Ù•…Õ…™™¥¡…”œ€€€€ôø€‘¹¥Ù•…Õ…™™¥¡…”°4)tì4(4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(¼¼=!=IQi%@€¡ÁÉ¥½É¥Ñ…¥É”¤4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4)¥˜€ ‘‘½Ý¹±½…‘é¥À¤ì4(€€€É•ÅÕ¥É•}Í•ÍÍ­•ä ¤ì4(4(€€€¥˜€ „‘½¡½ÉÑ¥ñð•µÁÑä ‘Í•µ•ÍÑ•È¤¤ì4(€€€€€€€Ñ¡É½Ü¹•Üµ½½‘±•}•á•ÁÑ¥½¸ ½¡½ÉÑ”½ÔÍ•µ•ÍÑÉ”µ…¹ÅÕ…¹Ð¸œ¤ì4(€€€ô4(4(€€€‰Õ±±•Ñ¥¹}µ…¹…•Èèé•áÁ½ÉÑ}½¡½ÉÑ}‰Õ±±•Ñ¥¹Í}é¥À 4(€€€€€€€€¡¥¹Ð¤‘½¡½ÉÑ¥°4(€€€€€€€€¡ÍÑÉ¥¹œ¤‘Í•µ•ÍÑ•È°4(€€€€€€€™Õ¹Ñ¥½¸¡¥¹Ð€‘Õ¥°ÍÑÉ¥¹œ€‘Í•´¤ÕÍ”€ ‘‘¥ÍÁ±…å‘…Ñ„¤ì4(€€€€€€€€€€€€‘ˆ€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé‰Õ¥±‘}Í•µ•ÍÑ•É}‰Õ±±•Ñ¥¸ ‘Õ¥°€‘Í•´¤ì4(€€€€€€€€€€€¥˜€¡•µÁÑä ‘‰lÍÑÕ‘•¹Ðt¤ñð•µÁÑä ‘‰lÕ•Ìt¤¤ì4(€€€€€€€€€€€€€€€É•ÑÕÉ¸€œœì4(€€€€€€€€€€€ô4(€€€€€€€€€€€É•ÑÕÉ¸±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}‰Õ±±•Ñ¥¹}¡Ñµ° ‘ˆ°€‘Í•´°€‘‘¥ÍÁ±…å‘…Ñ„°ÑÉÕ”¤ì4(€€€€€€€ô4(€€€€¤ì4(€€€•á¥Ðì4)ô4(4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(¼¼M¤Á…ÌŸ
¥ÑÕ‘¥…¹Ð½Í•µ•ÍÑÉ”€´ø…™™¥¡•È™½É´4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4)¥˜€ ‘Í…Ù•½¡½ÉÑ©ÕÉä¤ì4(€€€É•ÅÕ¥É•}Í•ÍÍ­•ä ¤ì4(4(€€€™½É•… € ‘½¡½ÉÑÕÍ•ÉÌ…Ì€‘Õ¥¤ì4(€€€€€€€€‘Õ¥€ô€¡¥¹Ð¤‘Õ¥ì4(€€€€€€€¥˜€ ‘Õ¥€ðô€À¤ì4(€€€€€€€€€€€½¹Ñ¥¹Õ”ì4(€€€€€€€ô4(4(€€€€€€€€‘…¹‘¥‘…Ñ•Õ•¥‘Ì€ô½ÁÑ¥½¹…±}Á…É…µ}…ÉÉ…ä ©ÕÉå…¹‘¥‘…Ñ•Í|œ€¸€‘Õ¥°mt°AI5}%9P¤ì4(€€€€€€€€‘…ÁÁÉ½Ù•‘Õ•¥‘Ì€ô½ÁÑ¥½¹…±}Á…É…µ}…ÉÉ…ä ©ÕÉå…ÁÁÉ½Ù•‘|œ€¸€‘Õ¥°mt°AI5}%9P¤ì4(€€€€€€€‰Õ±±•Ñ¥¹}µ…¹…•ÈèéÍ…Ù•}©ÕÉå}Ù…±¥‘…Ñ¥½¹Ì ‘Õ¥°€‘Í•µ•ÍÑ•È°€‘…¹‘¥‘…Ñ•Õ•¥‘Ì°€‘…ÁÁÉ½Ù•‘Õ•¥‘Ì¤ì4(€€€ô4(4(€€€É•‘¥É•Ð¡¹•Üµ½½‘±•}ÕÉ° œ½±½…°½¥Ñµ…‰Õ±±•Ñ¥¸½¥¹‘•à¹Á¡Àœ°l4(€€€€€€€€µ½‘”œ€ôø€½¡½ÉÐœ°4(€€€€€€€€½¡½ÉÑ¥œ€ôø€‘½¡½ÉÑ¥°4(€€€€€€€€Í•µ•ÍÑ•Èœ€ôø€‘Í•µ•ÍÑ•È°4(€€€€€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€ôø€‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”°4(€€€€€€€€™¥±¥•É•…™™¥¡…”œ€ôø€‘™¥±¥•É•…™™¥¡…”°4(€€€€€€€€¹¥Ù•…Õ…™™¥¡…”œ€ôø€‘¹¥Ù•…Õ…™™¥¡…”°4(€€€t¤°€Y…±¥‘…Ñ¥½¹Ì‘”½¡½ÉÑ”•¹É•¥ÍÑÉ••Ì¸œ°¹Õ±°°q½É•q½ÕÑÁÕÑq¹½Ñ¥™¥…Ñ¥½¸èé9=Q%e}MUML¤ì4)ô4(4)¥˜€ ‘½¡½ÉÑ¥€˜˜€…•µÁÑä ‘Í•µ•ÍÑ•È¤¤ì4(€€€•¡¼€‘=UQAUP´ù¡•…‘•È ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÁ…”œ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¡•É¼œ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ  Èœ°€•¹•É…Ñ¥½¸‘•Ì‰Õ±±•Ñ¥¹Ì%Q5œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÑ¥Ñ±”t¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ Àœ°€Y…±¥‘•è±•ÌU‘•¥‘••ÌÁ…È±”©ÕÉä°ÁÕ¥ÌÑ•±•¡…É•è±”i%@‘”±„½¡½ÉÑ”¸œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¥¹ÑÉ¼t¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}½¡½ÉÑ}©ÕÉå}Á…” ‘½¡½ÉÑ¥°€‘Í•µ•ÍÑ•È°€‘‘¥ÍÁ±…å‘…Ñ„¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¡Èœ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ™½É´µ…Éœ¤ì4(€€€€‘µ™½É´´ù‘¥ÍÁ±…ä ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼€‘=UQAUP´ù™½½Ñ•È ¤ì4(€€€•á¥Ðì4)ô4(4)¥˜€ „‘ÕÍ•É¥ñð•µÁÑä ‘Í•µ•ÍÑ•È¤¤ì4(€€€•¡¼€‘=UQAUP´ù¡•…‘•È ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÁ…”œ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¡•É¼œ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ  Èœ°€¥»¥É…Ñ¥½¸‘•Ì‰Õ±±•Ñ¥¹Ì%Q5œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÑ¥Ñ±”t¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ Àœ°€O¥±•Ñ¥½¹¹•èÕ¸ƒ¥ÑÕ‘¥…¹Ð½ÔÕ¹”½¡½ÉÑ”°ÁÕ¥ÌÁ•ÉÍ½¹¹…±¥Í•è±•Ì¥¹™½Éµ…Ñ¥½¹Ì‘p…™™¥¡…”…Ù…¹Ð‘”Ÿ¥»¥É•È±”‰Õ±±•Ñ¥¸¸œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¥¹ÑÉ¼t¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ™½É´µ…Éœ¤ì4(€€€€‘µ™½É´´ù‘¥ÍÁ±…ä ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4(€€€•¡¼€‘=UQAUP´ù™½½Ñ•È ¤ì4(€€€•á¥Ðì4)ô4(4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(¼¼€Äƒ
¥ÑÕ‘¥…¹Ð4(¼¼€´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´´4(‘‰Õ±±•Ñ¥¸€ô‰Õ±±•Ñ¥¹}µ…¹…•Èèé‰Õ¥±‘}Í•µ•ÍÑ•É}‰Õ±±•Ñ¥¸ ‘ÕÍ•É¥°€‘Í•µ•ÍÑ•È¤ì4(4)¥˜€ „‘‰Õ±±•Ñ¥¹lÍÑÕ‘•¹Ðt¤ì4(€€€•¡¼€‘=UQAUP´ù¡•…‘•È ¤ì4(€€€•¡¼€‘=UQAUP´ù¹½Ñ¥™¥…Ñ¥½¸ ‰UÑ¥±¥Í…Ñ•ÕÈ¥¹ÑÉ½ÕÙ…‰±”€¡%€‘ÕÍ•É¥¤¸ˆ°€¹½Ñ¥™åÁÉ½‰±•´œ¤ì4(€€€€‘µ™½É´´ù‘¥ÍÁ±…ä ¤ì4(€€€•¡¼€‘=UQAUP´ù™½½Ñ•È ¤ì4(€€€•á¥Ðì4)ô4(4)¥˜€¡•µÁÑä ‘‰Õ±±•Ñ¥¹lÕ•Ìt¤¤ì4(€€€•¡¼€‘=UQAUP´ù¡•…‘•È ¤ì4(€€€•¡¼€‘=UQAUP´ù¹½Ñ¥™¥…Ñ¥½¸ 4(€€€€€€€€‰ÕÕ¹”UÑÉ½ÕÛ¥”Á½ÕÈ±”Í•µ•ÍÑÉ”ƒ
¬€‘Í•µ•ÍÑ•Èƒ
ì¸[¥É¥™¥”°¥‘¹Õµ‰•È‘”±„…Ó¥½É¥”Í•µ•ÍÑÉ”€¡Á…È•à¸%IPÄµLÄ¤¸ˆ°4(€€€€€€€€¹½Ñ¥™åÁÉ½‰±•´œ4(€€€€¤ì4(€€€€‘µ™½É´´ù‘¥ÍÁ±…ä ¤ì4(€€€•¡¼€‘=UQAUP´ù™½½Ñ•È ¤ì4(€€€•á¥Ðì4)ô4(4(‘©ÕÉå…¹‘¥‘…Ñ•±¥ÍÐ€ô±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}•Ñ}©ÕÉå}…¹‘¥‘…Ñ•}Õ•Ì ‘‰Õ±±•Ñ¥¸¤ì4(4)¥˜€ ‘Í…Ù•©ÕÉä¤ì4(€€€É•ÅÕ¥É•}Í•ÍÍ­•ä ¤ì4(€€€‰Õ±±•Ñ¥¹}µ…¹…•ÈèéÍ…Ù•}©ÕÉå}Ù…±¥‘…Ñ¥½¹Ì ‘ÕÍ•É¥°€‘Í•µ•ÍÑ•È°€‘©ÕÉå…¹‘¥‘…Ñ•Ì°€‘©ÕÉå…ÁÁÉ½Ù•¤ì4(4(€€€É•‘¥É•Ð¡¹•Üµ½½‘±•}ÕÉ° œ½±½…°½¥Ñµ…‰Õ±±•Ñ¥¸½¥¹‘•à¹Á¡Àœ°l4(€€€€€€€€ÕÍ•É¥œ€€€€€€€€€€€€€€ôø€‘ÕÍ•É¥°4(€€€€€€€€Í•µ•ÍÑ•Èœ€€€€€€€€€€€€ôø€‘Í•µ•ÍÑ•È°4(€€€€€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€€ôø€‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”°4(€€€€€€€€™¥±¥•É•…™™¥¡…”œ€€€€ôø€‘™¥±¥•É•…™™¥¡…”°4(€€€€€€€€¹¥Ù•…Õ…™™¥¡…”œ€€€€€ôø€‘¹¥Ù•…Õ…™™¥¡…”°4(€€€t¤°€Y…±¥‘…Ñ¥½¹ÌÁ…È©ÕÉä•¹É•¥ÍÑÉ••Ì¸œ°¹Õ±°°q½É•q½ÕÑÁÕÑq¹½Ñ¥™¥…Ñ¥½¸èé9=Q%e}MUML¤ì4)ô4(4)¥˜€ ‘‘½Ý¹±½…¤ì4(€€€É•ÅÕ¥É•}Í•ÍÍ­•ä ¤ì4(€€€€‘‰Õ±±•Ñ¥¹¡Ñµ°€ô±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}‰Õ±±•Ñ¥¹}¡Ñµ° ‘‰Õ±±•Ñ¥¸°€‘Í•µ•ÍÑ•È°€‘‘¥ÍÁ±…å‘…Ñ„°ÑÉÕ”¤ì4(€€€‰Õ±±•Ñ¥¹}µ…¹…•Èèé•áÁ½ÉÑ}‰Õ±±•Ñ¥¹}Á‘˜ ‘ÕÍ•É¥°€‘Í•µ•ÍÑ•È°€‘‰Õ±±•Ñ¥¹¡Ñµ°¤ì4(€€€•á¥Ðì4)ô4(4(‘‰Õ±±•Ñ¥¹¡Ñµ°€ô±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}‰Õ±±•Ñ¥¹}¡Ñµ° ‘‰Õ±±•Ñ¥¸°€‘Í•µ•ÍÑ•È°€‘‘¥ÍÁ±…å‘…Ñ„¤ì4(4(¼¼™™¥¡…”!Q504)•¡¼€‘=UQAUP´ù¡•…‘•È ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÁ…”œ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¡•É¼œ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ  Èœ°€¥»¥É…Ñ¥½¸‘•Ì‰Õ±±•Ñ¥¹Ì%Q5œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µÑ¥Ñ±”t¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÑ…œ Àœ°€Á•ËÔ‘Ô‰Õ±±•Ñ¥¸Ÿ¥»¥Ë¤¸Y½ÕÌÁ½ÕÙ•èÓ¥³¥¡…É•È±”A½ÔÉ•±…¹•ÈÕ¹”Ÿ¥»¥É…Ñ¥½¸…Ù•Œ‘p…ÕÑÉ•ÌÁ…É…·¡ÑÉ•Ì¸œ°l±…ÍÌœ€ôø€¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ¥¹ÑÉ¼t¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4)•¡¼€‘‰Õ±±•Ñ¥¹¡Ñµ°ì4)•¡¼±½…±}¥Ñµ…‰Õ±±•Ñ¥¹}É•¹‘•É}©ÕÉå}™½É´ 4(€€€€‘©ÕÉå…¹‘¥‘…Ñ•±¥ÍÐ°4(€€€‰Õ±±•Ñ¥¹}µ…¹…•Èèé•Ñ}©ÕÉå}Ù…±¥‘…Ñ•‘}Õ•¥‘Ì ‘ÕÍ•É¥°€‘Í•µ•ÍÑ•È¤°4(€€€€‘ÕÍ•É¥°4(€€€€‘Í•µ•ÍÑ•È°4(€€€€‘‘¥ÍÁ±…å‘…Ñ„4(¤ì4(4(¼¼	½ÕÑ½¸A4(‘Á‘™ÕÉ°€ô¹•Üµ½½‘±•}ÕÉ° œ½±½…°½¥Ñµ…‰Õ±±•Ñ¥¸½¥¹‘•à¹Á¡Àœ°l4(€€€€ÕÍ•É¥œ€€€€€€€€€€€€€€ôø€‘ÕÍ•É¥°4(€€€€Í•µ•ÍÑ•Èœ€€€€€€€€€€€€ôø€‘Í•µ•ÍÑ•È°4(€€€€…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”œ€€ôø€‘…¹¹••Õ¹¥Ù•ÉÍ¥Ñ…¥É”°4(€€€€™¥±¥•É•…™™¥¡…”œ€€€€ôø€‘™¥±¥•É•…™™¥¡…”°4(€€€€¹¥Ù•…Õ…™™¥¡…”œ€€€€€ôø€‘¹¥Ù•…Õ…™™¥¡…”°4(€€€€‘½Ý¹±½…œ€€€€€€€€€€€€ôø€Ä°4(€€€€Í•ÍÍ­•äœ€€€€€€€€€€€€€ôøÍ•ÍÍ­•ä ¤°4)t¤ì4(4)•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé‘¥Ø 4(€€€¡Ñµ±}ÝÉ¥Ñ•Èèé±¥¹¬ ‘Á‘™ÕÉ°°€S¥³¥¡…É•È±”‰Õ±±•Ñ¥¸•¸Aœ°l4(€€€€€€€€±…ÍÌœ€ôø€‰Ñ¸‰Ñ¸µÍ•½¹‘…Éä¥Ñµ…‰Õ±±•Ñ¥¸µÁ‘™‰Ñ¸œ4(€€€t¤°4(€€€€¥Ñµ…‰Õ±±•Ñ¥¸µ…Ñ¥½¹Ìœ4(¤ì4(4)•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•µÁÑå}Ñ…œ ¡Èœ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•ÈèéÍÑ…ÉÑ}‘¥Ø ¥Ñµ…‰Õ±±•Ñ¥¸µ…‘µ¥¸µ™½É´µ…Éœ¤ì4(‘µ™½É´´ù‘¥ÍÁ±…ä ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4)•¡¼¡Ñµ±}ÝÉ¥Ñ•Èèé•¹‘}‘¥Ø ¤ì4)•¡¼€‘=UQAUP´ù™½½Ñ•È ¤ì4(