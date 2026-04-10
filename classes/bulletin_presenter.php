<?php
namespace local_itmabulletin;

defined('MOODLE_INTERNAL') || die();

use html_writer;

/**
 * Outils de présentation/rendu du bulletin étudiant.
 */
class bulletin_presenter {

    public static function get_profile_field(int $userid, string $shortname): ?string {
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

    public static function format_note($value): string {
        if ($value === null || $value === '' || $value === false) {
            return '-';
        }
        return format_float((float)$value, 2);
    }

    public static function format_date_session(?string $value): string {
        $v = trim((string)$value);
        return ($v === '') ? '-' : s($v);
    }

    public static function credit_from_idnumber(string $idnumber): ?float {
        $parts = explode('-', $idnumber);
        if (count($parts) < 2) {
            return null;
        }
        $last = end($parts);
        return is_numeric($last) ? (float)$last : null;
    }

    public static function code_from_ue_idnumber(string $idnumber): ?string {
        $parts = explode('-', $idnumber);
        return $parts[1] ?? null;
    }

    public static function clean_ue_name(string $name): string {
        $name = preg_replace('/^UE\s+[A-ZÉÈÊÂÎÔÙÇa-zéèêàïûç]+\s+[–-]\s*/u', '', $name);
        $name = preg_replace('/\([A-Z0-9]+\)\s*$/u', '', $name);
        return trim($name);
    }

    public static function type_from_ue_idnumber(string $idnumber): string {
        $parts = explode('-', $idnumber);
        $flag = $parts[2] ?? '';
        switch ($flag) {
            case 'MAJ':
                return 'UE Majeure';
            case 'MIN':
                return 'UE Mineure';
            case 'LIB':
                return 'UE Libre';
            default:
                return 'UE';
        }
    }

    public static function compute_class_avg($dev1, $dev2): ?float {
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

    public static function compute_ec_avg(?float $classavg, $exam, $rattrapage): float {
        $classvalue = ($classavg === null) ? 0.0 : (float)$classavg;
        $examvalue = ($exam !== null && $exam !== '') ? (float)$exam : 0.0;
        $rat = ($rattrapage !== null && $rattrapage !== '') ? (float)$rattrapage : null;

        $normalavg = (2 * $examvalue + $classvalue) / 3.0;

        if ($rat !== null && $rat > $normalavg) {
            return $rat;
        }

        return $normalavg;
    }

    public static function semester_display_label(string $semester): string {
        if (preg_match('/S([1-6])(?:[^0-9]|$)/i', $semester, $m)) {
            return 'Semestre ' . $m[1];
        }
        return 'Semestre';
    }

    /**
     * Libellé lisible pour les étudiants.
     */
    public static function semester_label_for_student(string $code): string {
        $semester = self::semester_display_label($code);

        $track = 'Informatique Réseaux & Télécommunications';
        if (strpos($code, 'D-') === 0) {
            $track = 'Droit';
        } else if (strpos($code, 'SGE-FIN-') === 0) {
            $track = 'Science de Finance';
        } else if (strpos($code, 'SGE-GES-') === 0) {
            $track = 'Science de Gestion';
        }

        $option = '';
        if (preg_match('/S[1-6]-(.+)$/', $code, $m)) {
            $option = trim(str_replace('-', ' ', $m[1]));
        }

        $label = $semester . ' — ' . $track;
        if ($option !== '') {
            $label .= ' (' . s($option) . ')';
        }

        return $label;
    }

    /**
     * Liste code => libellé pour le select étudiant.
     */
    public static function get_student_semester_options(): array {
        $options = [];
        foreach (bulletin_manager::get_allowed_semester_codes() as $code) {
            $options[$code] = self::semester_label_for_student($code);
        }
        return $options;
    }

    public static function ue_status(?float $ueavg): string {
        if ($ueavg === null) {
            return '-';
        }
        return ($ueavg >= 12.0) ? 'Validée' : 'En session de rattrapage';
    }

    /**
     * Rendu HTML bulletin étudiant (écran + PDF).
     */
    public static function render_student_bulletin_html(array $bulletin, string $semester): string {
        global $CFG;

        $student = $bulletin['student'];
        $ues = $bulletin['ues'];

        $matricule = !empty($student->username) ? trim((string)$student->username) : '-';

        $rawdate = self::get_profile_field((int)$student->id, 'date_naissance');
        $datenaissance = '-';
        if (!empty($rawdate)) {
            $datenaissance = is_numeric($rawdate) ? date('d/m/Y', (int)$rawdate) : $rawdate;
        }

        $datebulletin = date('d/m/Y');
        $semestretitre = self::semester_display_label($semester);
        $logourl = $CFG->wwwroot . '/local/itmabulletin/pix/logo.png';

        $wue = '37%';
        $wcredit = '7%';
        $wclasse = '12%';
        $wexam = '12%';
        $wmoy = '10%';
        $wdate = '10%';
        $wstatut = '12%';

        $out = '';
        $out .= html_writer::start_div('itmabulletin-wrapper itmabulletin-student');

        $out .= '<table class="itmabulletin-header-table" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
        $out .= '<tr>';
        $out .= '<td class="itmabulletin-header-logo" width="12%" style="width:12%; vertical-align:top;">';
        $out .= '<img src="' . $logourl . '" alt="ITMA" class="itmabulletin-logo" />';
        $out .= '</td>';
        $out .= '<td class="itmabulletin-header-text" width="88%" style="width:88%; vertical-align:top; text-align:center;">';
        $out .= '<div class="itmabulletin-etab-nom"><strong>INSTITUT PRIVE AFRICAIN DE TECHNOLOGIES ET DE MANAGEMENT</strong></div>';
        $out .= '<div class="itmabulletin-consult-subtitle">CONSULTATION ÉTUDIANTE — ' . s($semestretitre) . '</div>';
        $out .= '</td>';
        $out .= '</tr>';
        $out .= '</table>';

        $out .= '<div class="itmabulletin-student-meta">';
        $out .= '<div><strong>Nom :</strong> ' . s($student->lastname) . '</div>';
        $out .= '<div><strong>Prénom :</strong> ' . s($student->firstname) . '</div>';
        $out .= '<div><strong>Numéro matricule :</strong> ' . s($matricule) . '</div>';
        $out .= '<div><strong>Date de naissance :</strong> ' . s($datenaissance) . '</div>';
        $out .= '</div>';

        $out .= '<table class="generaltable itmabulletin-table itmabulletin-table-student" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse; table-layout:fixed;">';
        $out .= '<thead><tr>';
        $out .= '<th width="' . $wue . '"><strong>UE/Matière</strong></th>';
        $out .= '<th width="' . $wcredit . '"><strong>Crédit</strong></th>';
        $out .= '<th width="' . $wclasse . '"><strong>Note Classe</strong></th>';
        $out .= '<th width="' . $wexam . '"><strong>Note Examen</strong></th>';
        $out .= '<th width="' . $wmoy . '"><strong>Moyenne</strong></th>';
        $out .= '<th width="' . $wdate . '"><strong>Date de Session</strong></th>';
        $out .= '<th width="' . $wstatut . '"><strong>Statut</strong></th>';
        $out .= '</tr></thead>';

        $out .= '<tbody>';

        $semweightedsum = 0.0;
        $semcreditssum = 0.0;

        foreach ($ues as $ue) {
            $ecs = $ue->ecs ?? [];

            $ueecinfos = [];
            $ueweightedsum = 0.0;
            $uecreditssum = 0.0;

            foreach ($ecs as $ec) {
                $notes = $ec->notes ?? [];

                $dev1 = $notes['NOTE_DEV1'] ?? null;
                $dev2 = $notes['NOTE_DEV2'] ?? null;
                $exam = $notes['NOTE_EXAM'] ?? null;
                $rat = $notes['NOTE_RATTRAPAGE'] ?? null;
                $datesession = $notes['DATE_SESSION'] ?? null;

                $classavg = self::compute_class_avg($dev1, $dev2);
                $normalavg = (2 * (($exam !== null && $exam !== '') ? (float)$exam : 0.0)
                    + (($classavg !== null) ? (float)$classavg : 0.0)) / 3.0;

                $ecavg = self::compute_ec_avg($classavg, $exam, $rat);

                if ($rat !== null && $rat !== '' && (float)$rat > $normalavg) {
                    $classavg = (float)$rat;
                    $exam = (float)$rat;
                    $ecavg = (float)$rat;
                }

                $eccredit = $ec->credit ?? null;
                if ($eccredit === null) {
                    $eccredit = self::credit_from_idnumber($ec->idnumber ?? '');
                }
                $eccredit = $eccredit ? (float)$eccredit : 0.0;

                if ($eccredit > 0) {
                    $ueweightedsum += $ecavg * $eccredit;
                    $uecreditssum += $eccredit;
                }

                $ueecinfos[] = (object)[
                    'ec' => $ec,
                    'credit' => $eccredit,
                    'classavg' => $classavg,
                    'exam' => $exam,
                    'avg' => $ecavg,
                    'datesession' => $datesession,
                ];
            }

            $ueavg = ($uecreditssum > 0) ? ($ueweightedsum / $uecreditssum) : null;
            if ($ueavg !== null) {
                $ueavg = bulletin_manager::apply_academic_rounding((float)$ueavg);
            }

            $uecredit = $ue->credit ?? null;
            if ($uecredit === null) {
                $uecredit = self::credit_from_idnumber($ue->idnumber ?? '');
            }
            $uecredit = $uecredit ? (float)$uecredit : 0.0;

            if ($ueavg !== null && $uecredit > 0) {
                $semweightedsum += $ueavg * $uecredit;
                $semcreditssum += $uecredit;
            }

            $uecode = self::code_from_ue_idnumber($ue->idnumber ?? '');
            $uename = self::clean_ue_name($ue->name ?? '');
            $uelabel = trim(($uecode ? $uecode . ' ' : '') . $uename);
            $uetypelabel = self::type_from_ue_idnumber($ue->idnumber ?? '');
            $statut = self::ue_status($ueavg);
            $statusclass = ($statut === 'Validée') ? 'itmabulletin-status-ok' : 'itmabulletin-status-retake';

            $out .= '<tr class="itmabulletin-ue">';
            $out .= '<td class="itmabulletin-col-ue" width="' . $wue . '"><strong>' . s($uelabel) . '</strong></td>';
            $out .= '<td width="' . $wcredit . '" style="text-align:center;"><strong>' . self::format_note($uecredit) . '</strong></td>';
            $out .= '<td width="' . $wclasse . '" style="text-align:center;"><strong>' . s($uetypelabel) . '</strong></td>';
            $out .= '<td width="' . $wexam . '" style="text-align:center;"><strong>Moyenne U.E</strong></td>';
            $out .= '<td width="' . $wmoy . '" style="text-align:center;"><strong>' . self::format_note($ueavg) . '</strong></td>';
            $out .= '<td width="' . $wdate . '" style="text-align:center;">-</td>';
            $out .= '<td width="' . $wstatut . '" style="text-align:center;"><span class="itmabulletin-status-badge ' . $statusclass . '">' . s($statut) . '</span></td>';
            $out .= '</tr>';

            foreach ($ueecinfos as $info) {
                $ec = $info->ec;
                $out .= '<tr class="itmabulletin-ec">';
                $out .= '<td class="itmabulletin-col-ue" width="' . $wue . '">' . s($ec->fullname ?? '') . '</td>';
                $out .= '<td width="' . $wcredit . '" style="text-align:center;">' . self::format_note($info->credit) . '</td>';
                $out .= '<td width="' . $wclasse . '" style="text-align:center;">' . self::format_note($info->classavg) . '</td>';
                $out .= '<td width="' . $wexam . '" style="text-align:center;">' . self::format_note($info->exam) . '</td>';
                $out .= '<td width="' . $wmoy . '" style="text-align:center;">' . self::format_note($info->avg) . '</td>';
                $out .= '<td width="' . $wdate . '" style="text-align:center;">' . self::format_date_session($info->datesession) . '</td>';
                $out .= '<td width="' . $wstatut . '" style="text-align:center;">-</td>';
                $out .= '</tr>';
            }
        }

        $out .= '</tbody></table>';

        if ($semcreditssum > 0) {
            $semavg = $semweightedsum / $semcreditssum;
            $semavg = bulletin_manager::apply_academic_rounding((float)$semavg);
            $out .= '<div class="itmabulletin-moyenne-generale"><strong>Moyenne Générale : ' . self::format_note($semavg) . '</strong></div>';
        }

        $out .= '<div class="itmabulletin-note-basdepage"><strong>NB : La moyenne de validation de chaque UE doit être supérieure ou égale à 12</strong></div>';

        $out .= '<table class="itmabulletin-signatures" width="100%" cellspacing="0" cellpadding="0" style="width:100%; border-collapse:collapse;">';
        $out .= '<tr><td width="50%"></td><td width="50%" style="text-align:right;">Bamako le : ' . $datebulletin . '</td></tr>';
        $out .= '<tr><td width="50%"><strong>Le Directeur Général</strong></td><td width="50%" style="text-align:right;"><strong>Le Directeur Académique Adjoint</strong></td></tr>';
        $out .= '</table>';

        $out .= html_writer::end_div();

        return $out;
    }
}
