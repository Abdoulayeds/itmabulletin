<?php
defined('MOODLE_INTERNAL') || die();

class local_itmabulletin_renderer extends plugin_renderer_base {

    /**
     * Affiche le formulaire de sélection.
     */
    public function render_selection_form(array $students, array $semesters, ?int $userid, ?int $semcatid): string {
        $out = html_writer::start_div('itmabulletin-selection');

        $out .= html_writer::tag('h2', get_string('page_title', 'local_itmabulletin'));

        $out .= html_writer::start_tag('form', [
            'method' => 'get',
            'action' => new moodle_url('/local/itmabulletin/index.php'),
        ]);

        // Sélection étudiant.
        $out .= html_writer::start_div('form-group');
        $out .= html_writer::tag('label', get_string('choose_student', 'local_itmabulletin'));
        $options = [];
        foreach ($students as $s) {
            $options[$s->id] = fullname($s) . ' ('.$s->username.')';
        }
        $out .= html_writer::select($options, 'userid', $userid, ['' => '---']);
        $out .= html_writer::end_div();

        // Sélection semestre.
        $out .= html_writer::start_div('form-group');
        $out .= html_writer::tag('label', get_string('choose_semester', 'local_itmabulletin'));
        $semopts = [];
        foreach ($semesters as $cat) {
            $semopts[$cat->id] = $cat->get_formatted_name() . ' ['.$cat->idnumber.']';
        }
        $out .= html_writer::select($semopts, 'semcatid', $semcatid, ['' => '---']);
        $out .= html_writer::end_div();

        $out .= html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('generate', 'local_itmabulletin')
        ]);

        $out .= html_writer::end_tag('form');
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Rendu HTML du bulletin.
     *
     * $student : objet renvoyé par bulletin_manager::get_student_profile()
     * $semcat  : catégorie semestre
     * $bulletin: objet renvoyé par bulletin_manager::build_bulletin()
     */
    public function render_bulletin($student, $semcat, $bulletin, bool $showpdfbutton = true): string {
        $out = '';

        // Header avec logo.
        $logourl = $this->image_url('logo', 'local_itmabulletin');
        $out .= html_writer::start_div('itmabulletin-wrapper');
        $out .= html_writer::start_div('itmabulletin-header');

        $out .= html_writer::empty_tag('img', [
            'src' => $logourl,
            'alt' => 'Logo ITMA',
            'class' => 'itmabulletin-logo'
        ]);

        $out .= html_writer::start_div('itmabulletin-header-text');
        $out .= html_writer::tag('h3', 'INSTITUT AFRICAIN DE TECHNOLOGIE ET DE MANAGEMENT');
        if (!empty($student->annee)) {
            $out .= html_writer::tag('div', 'Année universitaire : '.$student->annee);
        }
        $out .= html_writer::tag('div', get_string('semester_label', 'local_itmabulletin', $semcat->get_formatted_name()));
        $out .= html_writer::end_div(); // header-text

        $out .= html_writer::end_div(); // header

        // Bloc identité étudiant.
        $out .= html_writer::start_div('itmabulletin-identite');
        $out .= html_writer::tag('h4', get_string('bulletin_for', 'local_itmabulletin').' : '.s($student->fullname));

        $table = new html_table();
        $table->attributes['class'] = 'generaltable identitetable';

        $table->data[] = [
            'Nom et prénom',
            s($student->lastname . ' ' . $student->firstname),
            get_string('student_id', 'local_itmabulletin'),
            s($student->matricule)
        ];
        $table->data[] = [
            get_string('date_of_birth', 'local_itmabulletin'),
            s($student->dob),
            get_string('place_of_birth', 'local_itmabulletin'),
            s($student->pob)
        ];
        $table->data[] = [
            get_string('nationality', 'local_itmabulletin'),
            s($student->nationality),
            get_string('major', 'local_itmabulletin'),
            s(trim($student->filiere . ' ' . $student->specialite))
        ];

        $out .= html_writer::table($table);
        $out .= html_writer::end_div();

        // Tableau UE / EC.
        if (empty($bulletin->ues)) {
            $out .= $this->notification(get_string('nostructure', 'local_itmabulletin'), 'notifyproblem');
        } else {
            foreach ($bulletin->ues as $ue) {
                $out .= html_writer::start_div('itmabulletin-ue');

                $libue = trim(($ue->code ? $ue->code.' - ' : '') . $ue->name);
                if ($ue->moyenneue !== null) {
                    $libue .= ' (Moyenne UE : '.$ue->moyenneue.'/20, Crédits : '.$ue->creditsue.')';
                } else {
                    $libue .= ' (Crédits : '.$ue->creditsue.')';
                }

                $out .= html_writer::tag('h4', s($libue));

                $tbl = new html_table();
                $tbl->attributes['class'] = 'generaltable uetable';

                $tbl->head = [
                    'EC',
                    get_string('credits', 'local_itmabulletin'),
                    get_string('classmark', 'local_itmabulletin'),
                    get_string('exammark', 'local_itmabulletin'),
                    get_string('makeupmark', 'local_itmabulletin'),
                    get_string('average', 'local_itmabulletin'),
                    get_string('sessiondate', 'local_itmabulletin'),
                ];

                foreach ($ue->ecs as $ec) {
                    $tbl->data[] = [
                        s($ec->fullname),
                        $ec->creditsec ?: '',
                        self::format_note($ec->note_dev1, $ec->note_dev2),
                        self::format_simple($ec->note_exam),
                        self::format_simple($ec->note_rattr),
                        self::format_simple($ec->moyenneec),
                        s($ec->sessiondate),
                    ];
                }

                $out .= html_writer::table($tbl);
                $out .= html_writer::end_div();
            }

            // Moyenne générale.
            if ($bulletin->moyennegenerale !== null) {
                $out .= html_writer::start_div('itmabulletin-footer');
                $out .= html_writer::tag('div',
                    get_string('general_average', 'local_itmabulletin').' : <strong>'.
                    $bulletin->moyennegenerale.'/20</strong>',
                    ['class' => 'itmabulletin-moyennegenerale']
                );
                $out .= html_writer::end_div();
            }
        }

        // Bouton PDF.
        if ($showpdfbutton) {
            $url = new moodle_url('/local/itmabulletin/index.php', [
                'userid' => $student->id,
                'semcatid' => $semcat->id,
                'format' => 'pdf'
            ]);
            $out .= html_writer::div(
                html_writer::link($url, get_string('downloadpdf', 'local_itmabulletin'),
                    ['class' => 'btn btn-secondary']),
                'itmabulletin-pdfbtn'
            );
        }

        $out .= html_writer::end_div(); // wrapper
        return $out;
    }

    protected static function format_note($dev1, $dev2): string {
        $vals = [];
        if ($dev1 !== null) {
            $vals[] = 'D1: '.sprintf('%.2f', $dev1);
        }
        if ($dev2 !== null) {
            $vals[] = 'D2: '.sprintf('%.2f', $dev2);
        }
        return implode(' / ', $vals);
    }

    protected static function format_simple($n): string {
        if ($n === null) {
            return '';
        }
        return sprintf('%.2f', $n);
    }
}
