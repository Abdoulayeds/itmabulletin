<?php
namespace local_itmabulletin;

defined('MOODLE_INTERNAL') || die();

class bulletin_manager {

    public const SEMESTER_IDNUMBERS = [
        'S1'     => 'IRT1-S1',
        'S2'     => 'IRT1-S2',
        'S3'     => 'IRT2-S3',
        'S4-RIT' => 'IRT2-S4-RIT',
        'S4-GL'  => 'IRT2-S4-GL',
        'S5-RIT' => 'IRT3-S5-RIT',
        'S5-GL'  => 'IRT3-S5-GL',
        'S6-RIT' => 'IRT3-S6-RIT',
        'S6-GL'  => 'IRT3-S6-GL',

        'D-S1' => 'DROIT1-S1',
        'D-S2' => 'DROIT1-S2',
        'D-S3' => 'DROIT2-S3',
        'D-S4' => 'DROIT2-S4',

        'D-S5-RID' => 'DROIT3-S5-RID',
        'D-S6-RID' => 'DROIT3-S6-RID',
        'D-S5-DP'  => 'DROIT3-S5-DP',
        'D-S6-DP'  => 'DROIT3-S6-DP',

        'SGE-FIN-S1' => 'SGEFIN1-S1',
        'SGE-FIN-S2' => 'SGEFIN1-S2',
        'SGE-FIN-S3' => 'SGEFIN2-S3',
        'SGE-FIN-S4' => 'SGEFIN2-S4',

        'SGE-FIN-S5-FC'  => 'SGEFIN3-S5-FC',
        'SGE-FIN-S6-FC'  => 'SGEFIN3-S6-FC',
        'SGE-FIN-S5-MBA' => 'SGEFIN3-S5-MBA',
        'SGE-FIN-S6-MBA' => 'SGEFIN3-S6-MBA',

        'SGE-GES-S1' => 'SGEGES1-S1',
        'SGE-GES-S2' => 'SGEGES1-S2',
        'SGE-GES-S3' => 'SGEGES2-S3',
        'SGE-GES-S4' => 'SGEGES2-S4',

        'SGE-GES-S5-GRH' => 'SGEGES3-S5-GRH',
        'SGE-GES-S6-GRH' => 'SGEGES3-S6-GRH',
        'SGE-GES-S5-LT'  => 'SGEGES3-S5-LT',
        'SGE-GES-S6-LT'  => 'SGEGES3-S6-LT',
        'SGE-GES-S5-MV'  => 'SGEGES3-S5-MV',
        'SGE-GES-S6-MV'  => 'SGEGES3-S6-MV',
    ];

    public const GRADE_IDNUMBERS = [
        'NOTE_DEV1',
        'NOTE_DEV2',
        'NOTE_EXAM',
        'NOTE_RATTRAPAGE',
        'DATE_SESSION', // ✅ item texte saisi (feedback)
    ];

    public static function get_semester_category(string $semestercode): ?\stdClass {
        global $DB;

        if (!array_key_exists($semestercode, self::SEMESTER_IDNUMBERS)) {
            return null;
        }

        $idnumber = self::SEMESTER_IDNUMBERS[$semestercode];

        return $DB->get_record('course_categories', [
            'idnumber' => $idnumber,
        ]);
    }

    public static function get_ues_for_semester(string $semestercode): array {
        global $DB;

        $semcat = self::get_semester_category($semestercode);
        if (!$semcat) {
            return [];
        }

        return $DB->get_records('course_categories', [
            'parent' => $semcat->id,
        ], 'sortorder ASC');
    }

    public static function get_ecs_for_ue(int $uecategoryid): array {
        global $DB;

        return $DB->get_records('course', [
            'category' => $uecategoryid,
        ], 'sortorder ASC');
    }

    private static function normalize_grade_value($value): ?float {
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function normalize_text_value($value): ?string {
        if ($value === null || $value === false) {
            return null;
        }
        $v = trim(strip_tags((string)$value));
        return ($v === '') ? null : $v;
    }

    public static function get_allowed_semester_codes(): array {
        return array_keys(self::SEMESTER_IDNUMBERS);
    }

    /**
     * Règle académique ITMA d'arrondi des moyennes UE.
     *
     * Si la moyenne UE est entre 11.67 et 11.99,
     * elle est automatiquement arrondie à 12.00.
     */
    public static function apply_academic_rounding(float $value): float {

        if ($value >= 11.67 && $value < 12) {
            return 12.0;
        }

        return $value;
    }

    /**
     * ✅ Récupère notes + DATE_SESSION (texte) depuis le carnet de notes.
     * - Notes : finalgrade
     * - Date : feedback => texte saisi
     */
    public static function get_notes_for_course(int $courseid, int $userid): array {
        global $DB;

        $result = [
            'NOTE_DEV1'       => null,
            'NOTE_DEV2'       => null,
            'NOTE_EXAM'       => null,
            'NOTE_RATTRAPAGE' => null,
            'DATE_SESSION'    => null,
        ];

        if (!$courseid || !$userid) {
            return $result;
        }

        $idnumbers = array_keys($result);

        list($insql, $params) = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;

        $items = $DB->get_records_select(
            'grade_items',
            "courseid = :courseid
               AND itemtype = 'manual'
               AND " . $DB->sql_compare_text('idnumber') . " $insql",
            $params
        );

        if (!$items) {
            return $result;
        }

        foreach ($items as $item) {
            $idnum = strtoupper(trim((string)$item->idnumber));
            if (!array_key_exists($idnum, $result)) {
                continue;
            }

            $grade = $DB->get_record('grade_grades', [
                'itemid' => $item->id,
                'userid' => $userid,
            ], 'id, finalgrade, rawgrade, feedback, feedbackformat', IGNORE_MISSING);

            if (!$grade) {
                $result[$idnum] = null;
                continue;
            }

            if ($idnum === 'DATE_SESSION') {
                $result[$idnum] = self::normalize_text_value($grade->feedback);
                continue;
            }

            $final = self::normalize_grade_value($grade->finalgrade);
            $raw   = self::normalize_grade_value($grade->rawgrade);

            if ($final === null) {
                $result[$idnum] = null;
                continue;
            }

            if ((float)$final === 0.0 && $raw === null) {
                $result[$idnum] = null;
                continue;
            }

            $result[$idnum] = (float)$final;
        }

        return $result;
    }

    public static function build_semester_bulletin(int $userid, string $semestercode): array {
        global $DB;

        $data = [
            'userid'   => $userid,
            'semester' => $semestercode,
            'student'  => $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, idnumber, username'),
            'ues'      => [],
        ];

        $ues = self::get_ues_for_semester($semestercode);
        foreach ($ues as $ue) {
            $ueobj = new \stdClass();
            $ueobj->id        = $ue->id;
            $ueobj->name      = $ue->name;
            $ueobj->idnumber  = $ue->idnumber;
            $ueobj->credit    = null;
            $ueobj->ecs       = [];

            $courses = self::get_ecs_for_ue($ue->id);
            foreach ($courses as $course) {
                $ec = new \stdClass();
                $ec->id        = $course->id;
                $ec->fullname  = $course->fullname;
                $ec->shortname = $course->shortname;
                $ec->idnumber  = $course->idnumber;
                $ec->credit    = null;
                $ec->notes     = self::get_notes_for_course((int)$course->id, $userid);

                $ueobj->ecs[] = $ec;
            }

            $data['ues'][] = $ueobj;
        }

        return $data;
    }

    private static function patch_html_for_tcpdf(string $html): string {
        $widths = ['44%', '7%', '15%', '15%', '10%', '9%'];

        if (!preg_match('/<table[^>]*class="[^"]*itmabulletin-table[^"]*"[^>]*>.*?<\/table>/is', $html, $m)) {
            return $html;
        }
        $tablehtml = $m[0];

        $tablehtml = preg_replace('/<colgroup\b[^>]*>.*?<\/colgroup>/is', '', $tablehtml);

        $tablehtml = preg_replace_callback(
            '/<table([^>]*)>/i',
            function ($mm) {
                $attrs = $mm[1];
                if (preg_match('/\sstyle="([^"]*)"/i', $attrs, $sm)) {
                    $style = $sm[1];
                    $newstyle = rtrim($style, ';') . '; width:100%; table-layout:fixed; border-collapse:collapse;';
                    $attrs = preg_replace('/\sstyle="[^"]*"/i', ' style="' . $newstyle . '"', $attrs);
                } else {
                    $attrs .= ' style="width:100%; table-layout:fixed; border-collapse:collapse;"';
                }
                if (!preg_match('/\swidth="/i', $attrs)) {
                    $attrs .= ' width="100%"';
                }
                return '<table' . $attrs . '>';
            },
            $tablehtml,
            1
        );

        $tablehtml = preg_replace_callback('/<thead\b[^>]*>.*?<\/thead>/is', function($theadm) use ($widths) {
            $thead = $theadm[0];
            $i = 0;
            $thead = preg_replace_callback('/<th([^>]*)>(.*?)<\/th>/is', function($thm) use (&$i, $widths) {
                $attrs = $thm[1];
                $content = $thm[2];
                $w = $widths[$i] ?? null;
                $i++;
                if ($w) {
                    if (preg_match('/\swidth="/i', $attrs)) {
                        $attrs = preg_replace('/\swidth="[^"]*"/i', ' width="'.$w.'"', $attrs);
                    } else {
                        $attrs .= ' width="'.$w.'"';
                    }
                }
                return '<th'.$attrs.'>'.$content.'</th>';
            }, $thead);
            return $thead;
        }, $tablehtml, 1);

        $tablehtml = preg_replace_callback('/<tr\b[^>]*>.*?<\/tr>/is', function($trm) use ($widths) {
            $tr = $trm[0];
            if (!preg_match('/<td\b/i', $tr)) {
                return $tr;
            }
            $i = 0;
            $tr = preg_replace_callback('/<td([^>]*)>(.*?)<\/td>/is', function($tdm) use (&$i, $widths) {
                $attrs = $tdm[1];
                $content = $tdm[2];
                $w = $widths[$i] ?? null;
                $i++;

                if ($w) {
                    if (preg_match('/\swidth="/i', $attrs)) {
                        $attrs = preg_replace('/\swidth="[^"]*"/i', ' width="'.$w.'"', $attrs);
                    } else {
                        $attrs .= ' width="'.$w.'"';
                    }

                    if ($i === 1) {
                        if (!preg_match('/\sstyle="/i', $attrs)) {
                            $attrs .= ' style="white-space:normal;"';
                        }
                    } else {
                        if (!preg_match('/\sstyle="/i', $attrs)) {
                            $attrs .= ' style="text-align:center; white-space:nowrap;"';
                        } else {
                            $attrs = preg_replace('/\sstyle="([^"]*)"/i', ' style="$1; text-align:center; white-space:nowrap;"', $attrs);
                        }
                    }
                }

                return '<td'.$attrs.'>'.$content.'</td>';
            }, $tr);
            return $tr;
        }, $tablehtml);

        $html = str_replace($m[0], $tablehtml, $html);
        $html = preg_replace('/<td([^>]*)>\s*<p>(.*?)<\/p>\s*<\/td>/is', '<td$1>$2</td>', $html);

        return $html;
    }

    /**
     * ✅ Export PDF (téléchargement direct)
     */
    public static function export_bulletin_pdf(int $userid, string $semester, string $html) {
        global $CFG;

        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        while (ob_get_level()) {
            ob_end_clean();
        }

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Moodle');
        $pdf->SetAuthor('ITMA');
        $pdf->SetTitle('Bulletin de notes – ITMA');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $logojpg = $CFG->dirroot . '/local/itmabulletin/pix/logo.jpg';
        $logopng = $CFG->dirroot . '/local/itmabulletin/pix/logo.png';

        $chosen = null;
        $imagetype = null;

        if (file_exists($logojpg) && is_readable($logojpg)) {
            $chosen = realpath($logojpg);
            $imagetype = 'JPG';
        } elseif (file_exists($logopng) && is_readable($logopng)) {
            $chosen = realpath($logopng);
            $imagetype = 'PNG';
        }

        $html = preg_replace(
            '#<img[^>]*class="[^"]*itmabulletin-logo[^"]*"[^>]*>#i',
            '<table class="pdf-logo-space" cellspacing="0" cellpadding="0" border="0"><tr><td>&nbsp;</td></tr></table>',
            $html
        );

        $html = preg_replace_callback(
            '#<div\s+style="height:\s*([0-9]+)\s*px;?\s*"\s*>\s*</div>#i',
            function($m) {
                $h = (int)$m[1];
                if ($h <= 0) {
                    return '';
                }
                return '<table class="pdf-spacer" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%; border-collapse:collapse;">'
                    . '<tr><td style="height:' . $h . 'pt; line-height:' . $h . 'pt; font-size:1pt;">&nbsp;</td></tr>'
                    . '</table>';
            },
            $html
        );

        $html = self::patch_html_for_tcpdf($html);

        $css = <<<CSS
body { font-family: helvetica, sans-serif; font-size: 10.5pt; color: #000; }
.itmabulletin-wrapper { width:100%; }

.itmabulletin-etab-nom{
  font-weight: 900;
  font-size: 13pt;
  text-align:center;
  white-space: nowrap;
  text-transform: uppercase;
}

.pdf-logo-space td{
  width: 34mm;
  height: 28mm;
  font-size: 1pt;
  line-height: 1;
}

.pdf-spacer td{ font-size:1pt; line-height:1; }

.itmabulletin-topinfo-table { width:100%; border-collapse:collapse; }
.itmabulletin-topinfo-right{ text-align:right; }
.itmabulletin-bulletin-de{ font-weight:900; text-transform: uppercase; }

.itmabulletin-identite-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.itmabulletin-identite-table td { padding: 5px 4px; font-size: 10.7pt; }

.itmabulletin-table { width:100%; border-collapse:collapse; table-layout:fixed; margin-top: 8pt; }
.itmabulletin-table th{
  background-color:#bfbfbf;
  border:0.7pt solid #000;
  padding:7px 5px;
  font-size: 10.2pt;
  font-weight: 900;
  text-align:center;
  vertical-align:middle;
  white-space: nowrap;
}
.itmabulletin-table td{
  border:0.7pt solid #000;
  padding:6px 5px;
  font-size: 9.8pt;
  vertical-align: middle;
  line-height: 1.25;
}

.itmabulletin-col-ue{
  font-size: 11.0pt !important;
  line-height: 1.25;
  white-space: normal;
}

.itmabulletin-ue td{ background-color:#e6e6e6; font-weight:900; }

.itmabulletin-moyenne-generale { margin-top: 12pt; font-size: 12pt; font-weight: 900; color:#c00000; }
.itmabulletin-note-basdepage   { margin-top: 10pt; font-size: 11pt; font-weight: 900; color:#c00000; }
.itmabulletin-signatures{ width:100%; border-collapse:collapse; margin-top: 18pt; font-size: 10.5pt; }
CSS;

        $fullhtml = '<style>' . $css . '</style>' . $html;
        $pdf->writeHTML($fullhtml, true, false, true, false, '');

        if ($chosen && $imagetype) {
            $imgdata = @file_get_contents($chosen);
            if ($imgdata !== false && strlen($imgdata) > 0) {
                try {
                    $pdf->Image('@' . $imgdata, 4, 2, 32, 0, $imagetype);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        $filename = 'bulletin_itma_' . $userid . '_' . $semester . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    }

    // -------------------------------------------------------------------------
    // ✅ COHORTE -> ZIP
    // -------------------------------------------------------------------------

    /**
     * Retourne la liste des users d'une cohorte.
     */
    public static function get_cohort_users(int $cohortid): array {
        global $DB;

        if ($cohortid <= 0) {
            return [];
        }

        $sql = "SELECT u.id, u.firstname, u.lastname, u.username, u.idnumber
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid
                 WHERE cm.cohortid = :cid
              ORDER BY u.lastname ASC, u.firstname ASC";
        return $DB->get_records_sql($sql, ['cid' => $cohortid]);
    }

    /**
     * Nettoyage nom de fichier.
     */
    private static function sanitize_filename(string $s): string {
        $s = trim($s);
        $s = preg_replace('/[^\pL\pN\-_\. ]+/u', '', $s);
        $s = preg_replace('/\s+/u', '_', $s);
        $s = trim($s, '._-');
        return ($s === '') ? 'etudiant' : $s;
    }

    /**
     * Génère le PDF dans un fichier (NE TELECHARGE PAS).
     * IMPORTANT: pas de exit; ici.
     */
    public static function generate_pdf_file(int $userid, string $semester, string $html, string $filepath): bool {
        global $CFG;

        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        // Nettoyer buffers (évite corruption PDF)
        while (ob_get_level()) {
            ob_end_clean();
        }

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Moodle');
        $pdf->SetAuthor('ITMA');
        $pdf->SetTitle('Bulletin de notes – ITMA');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $logojpg = $CFG->dirroot . '/local/itmabulletin/pix/logo.jpg';
        $logopng = $CFG->dirroot . '/local/itmabulletin/pix/logo.png';

        $chosen = null;
        $imagetype = null;

        if (file_exists($logojpg) && is_readable($logojpg)) {
            $chosen = realpath($logojpg);
            $imagetype = 'JPG';
        } elseif (file_exists($logopng) && is_readable($logopng)) {
            $chosen = realpath($logopng);
            $imagetype = 'PNG';
        }

        $html = preg_replace(
            '#<img[^>]*class="[^"]*itmabulletin-logo[^"]*"[^>]*>#i',
            '<table class="pdf-logo-space" cellspacing="0" cellpadding="0" border="0"><tr><td>&nbsp;</td></tr></table>',
            $html
        );

        $html = preg_replace_callback(
            '#<div\s+style="height:\s*([0-9]+)\s*px;?\s*"\s*>\s*</div>#i',
            function($m) {
                $h = (int)$m[1];
                if ($h <= 0) {
                    return '';
                }
                return '<table class="pdf-spacer" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%; border-collapse:collapse;">'
                    . '<tr><td style="height:' . $h . 'pt; line-height:' . $h . 'pt; font-size:1pt;">&nbsp;</td></tr>'
                    . '</table>';
            },
            $html
        );

        $html = self::patch_html_for_tcpdf($html);

        $css = <<<CSS
body { font-family: helvetica, sans-serif; font-size: 10.5pt; color: #000; }
.itmabulletin-wrapper { width:100%; }

.itmabulletin-etab-nom{
  font-weight: 900;
  font-size: 13pt;
  text-align:center;
  white-space: nowrap;
  text-transform: uppercase;
}

.pdf-logo-space td{
  width: 34mm;
  height: 28mm;
  font-size: 1pt;
  line-height: 1;
}

.pdf-spacer td{ font-size:1pt; line-height:1; }

.itmabulletin-topinfo-table { width:100%; border-collapse:collapse; }
.itmabulletin-topinfo-right{ text-align:right; }
.itmabulletin-bulletin-de{ font-weight:900; text-transform: uppercase; }

.itmabulletin-identite-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.itmabulletin-identite-table td { padding: 5px 4px; font-size: 10.7pt; }

.itmabulletin-table { width:100%; border-collapse:collapse; table-layout:fixed; margin-top: 8pt; }
.itmabulletin-table th{
  background-color:#bfbfbf;
  border:0.7pt solid #000;
  padding:7px 5px;
  font-size: 10.2pt;
  font-weight: 900;
  text-align:center;
  vertical-align:middle;
  white-space: nowrap;
}
.itmabulletin-table td{
  border:0.7pt solid #000;
  padding:6px 5px;
  font-size: 9.8pt;
  vertical-align: middle;
  line-height: 1.25;
}

.itmabulletin-col-ue{
  font-size: 11.0pt !important;
  line-height: 1.25;
  white-space: normal;
}

.itmabulletin-ue td{ background-color:#e6e6e6; font-weight:900; }

.itmabulletin-moyenne-generale { margin-top: 12pt; font-size: 12pt; font-weight: 900; color:#c00000; }
.itmabulletin-note-basdepage   { margin-top: 10pt; font-size: 11pt; font-weight: 900; color:#c00000; }
.itmabulletin-signatures{ width:100%; border-collapse:collapse; margin-top: 18pt; font-size: 10.5pt; }
CSS;

        $fullhtml = '<style>' . $css . '</style>' . $html;
        $pdf->writeHTML($fullhtml, true, false, true, false, '');

        if ($chosen && $imagetype) {
            $imgdata = @file_get_contents($chosen);
            if ($imgdata !== false && strlen($imgdata) > 0) {
                try {
                    $pdf->Image('@' . $imgdata, 4, 2, 32, 0, $imagetype);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        try {
            $pdf->Output($filepath, 'F');
        } catch (\Throwable $e) {
            return false;
        }

        return file_exists($filepath) && filesize($filepath) > 0;
    }

    /**
     * Génère tous les bulletins d'une cohorte et télécharge un ZIP.
     * $htmlbuilder: fn(int $userid, string $semester): string bulletin html
     */
    public static function export_cohort_bulletins_zip(int $cohortid, string $semester, callable $htmlbuilder): void {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $users = self::get_cohort_users($cohortid);
        if (empty($users)) {
            throw new \moodle_exception('Aucun étudiant trouvé dans cette cohorte.');
        }

        $basedir = make_temp_directory('local_itmabulletin');
        $batchid = 'cohort_' . $cohortid . '_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $semester) . '_' . time();
        $workdir = $basedir . DIRECTORY_SEPARATOR . $batchid;

        if (!is_dir($workdir)) {
            @mkdir($workdir, 0770, true);
        }

        $okcount = 0;
        foreach ($users as $u) {
            $userid = (int)$u->id;

            $html = $htmlbuilder($userid, $semester);
            if (!is_string($html) || trim($html) === '') {
                continue;
            }

            $name = self::sanitize_filename(($u->lastname ?? '') . '_' . ($u->firstname ?? ''));
            $filename = 'bulletin_' . $name . '_' . $semester . '_' . $userid . '.pdf';
            $filepath = $workdir . DIRECTORY_SEPARATOR . $filename;

            if (self::generate_pdf_file($userid, $semester, $html, $filepath)) {
                $okcount++;
            }
        }

        if ($okcount === 0) {
            throw new \moodle_exception('Aucun PDF généré (vérifie données / notes / accès).');
        }

        $zipname = 'bulletins_cohorte_' . $cohortid . '_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $semester) . '.zip';
        $zippath = $basedir . DIRECTORY_SEPARATOR . $zipname;

        $zipper = new \zip_archive();
        if ($zipper->open($zippath, \file_archive::CREATE) !== true) {
            throw new \moodle_exception('Impossible de créer le ZIP.');
        }

        $files = glob($workdir . DIRECTORY_SEPARATOR . '*.pdf');
        foreach ($files as $f) {
            $zipper->add_file_from_pathname(basename($f), $f);
        }
        $zipper->close();

        send_temp_file($zippath, $zipname);

        @remove_dir($workdir, true);
        @unlink($zippath);
        exit;
    }

}