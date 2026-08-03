<?php
namespace local_itmabulletin\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

use local_itmabulletin\bulletin_manager;

class selection_form extends \moodleform {

    public function definition() {
        global $DB;

        $mform = $this->_form;

        // -------------------------------------------------------------
        // 0) Mode de génération: étudiant / cohorte
        // -------------------------------------------------------------
        $mform->addElement('select', 'mode', 'Mode', [
            'student' => 'Un étudiant',
            'cohort'  => 'Une cohorte (ZIP)',
        ]);
        $mform->setType('mode', PARAM_ALPHA);
        $mform->setDefault('mode', 'student');

        // -------------------------------------------------------------
        // 1) Sélecteur étudiant (autocomplete avec recherche)
        // -------------------------------------------------------------
        $studentoptions = [];
        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND confirmed = 1',
            [],
            'lastname ASC, firstname ASC',
            'id, firstname, lastname, username',
            0,
            1000
        );

        foreach ($users as $u) {
            $studentoptions[$u->id] = fullname($u) . " ({$u->username})";
        }

        $mform->addElement('autocomplete', 'userid', 'Étudiant', $studentoptions, [
            'multiple' => false,
            'noselectionstring' => 'Rechercher un étudiant...',
        ]);
        $mform->setType('userid', PARAM_INT);

        // -------------------------------------------------------------
        // 2) Cohortes (autocomplete aussi)
        // -------------------------------------------------------------
        $cohortoptions = [];
        $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber');

        foreach ($cohorts as $c) {
            $label = $c->name;
            if (!empty($c->idnumber)) {
                $label .= " ({$c->idnumber})";
            }
            $cohortoptions[$c->id] = $label;
        }

        $mform->addElement('autocomplete', 'cohortid', 'Cohorte', $cohortoptions, [
            'multiple' => false,
            'noselectionstring' => 'Rechercher une cohorte...',
        ]);
        $mform->setType('cohortid', PARAM_INT);

        // -------------------------------------------------------------
        // 3) Année universitaire
        // -------------------------------------------------------------
        $annees = [];
        for ($year = 2020; $year <= 2050; $year++) {
            $label = $year . '-' . ($year + 1);
            $annees[$label] = $label;
        }

        $mform->addElement('select', 'anneeuniversitaire', 'Année universitaire', $annees);
        $mform->setType('anneeuniversitaire', PARAM_TEXT);
        $mform->setDefault('anneeuniversitaire', '2024-2025');
        $mform->addRule('anneeuniversitaire', 'Champ obligatoire', 'required', null, 'client');

        // -------------------------------------------------------------
        // 4) Filière (affichage bulletin)
        // -------------------------------------------------------------
        $filieres = [
            'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS' => 'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS',
            'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS:Génie logiciel' => 'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS:Génie logiciel',
            'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS : RIT' => 'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS : RIT',
            'DROIT' => 'DROIT',
            'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE' => 'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE',
            'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE (DROIT PRIVE)' => 'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE (DROIT PRIVE)',
            'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE (RID)' => 'DROIT RELATIONS INTERNATIONALES ET DIPLOMATIE (RID)',
            'DROIT PRIVE' => 'DROIT PRIVE',
            'RELATION INTERNATIONALE ET DIPLOMATIE' => 'RELATION INTERNATIONALE ET DIPLOMATIE',
            'SCIENCES DE GESTION' => 'SCIENCES DE GESTION',
            'SCIENCES DE GESTION (GRH, MARKETING, LOGISTIQUE TRANSPORT, COMMUNICATION)' => 'SCIENCES DE GESTION (GRH, MARKETING, LOGISTIQUE TRANSPORT, COMMUNICATION)',
            'SCIENCES DE GESTION : Gestion des ressources humaines' => 'SCIENCES DE GESTION : Gestion des ressources humaines',
            'SCIENCES DE GESTION : Marketing & Vente' => 'SCIENCES DE GESTION : Marketing & Vente',
            'SCIENCES DE GESTION : Logistique & Transport' => 'SCIENCES DE GESTION : Logistique & Transport',
            'SCIENCES DE FINANCE' => 'SCIENCES DE FINANCE',
            'SCIENCES DE FINANCE (FC, MBA)' => 'SCIENCES DE FINANCE (FC, MBA)',
            'SCIENCES DE FINANCE : Finance Comptabilité' => 'SCIENCES DE FINANCE : Finance Comptabilité',
            'SCIENCES DE FINANCE : Monnaie Banque Assurance' => 'SCIENCES DE FINANCE : Monnaie Banque Assurance',
        ];

        $mform->addElement('select', 'filiereaffichage', 'Filière', $filieres);
        $mform->setType('filiereaffichage', PARAM_TEXT);
        $mform->setDefault('filiereaffichage', 'INFORMATIQUE RÉSEAUX ET TÉLÉCOMMUNICATIONS');
        $mform->addRule('filiereaffichage', 'Champ obligatoire', 'required', null, 'client');

        // -------------------------------------------------------------
        // 5) Niveau (affichage bulletin)
        // -------------------------------------------------------------
        $niveaux = [
            'Licence 1' => 'Licence 1',
            'Licence 2' => 'Licence 2',
            'Licence 3' => 'Licence 3',
            'Master 1'  => 'Master 1',
            'Master 2'  => 'Master 2',
        ];

        $mform->addElement('select', 'niveauaffichage', 'Niveau', $niveaux);
        $mform->setType('niveauaffichage', PARAM_TEXT);
        $mform->setDefault('niveauaffichage', 'Licence 1');
        $mform->addRule('niveauaffichage', 'Champ obligatoire', 'required', null, 'client');

        // -------------------------------------------------------------
        // 6) Semestre interne plugin
        // -------------------------------------------------------------
        $labels = [
            'S1'     => 'IRT — Semestre 1',
            'S2'     => 'IRT — Semestre 2',
            'S3'     => 'IRT — Semestre 3',
            'S4-RIT' => 'IRT — Semestre 4 (RIT)',
            'S4-GL'  => 'IRT — Semestre 4 (Génie logiciel)',
            'S5-RIT' => 'IRT — Semestre 5 (RIT)',
            'S5-GL'  => 'IRT — Semestre 5 (Génie logiciel)',
            'S6-RIT' => 'IRT — Semestre 6 (RIT)',
            'S6-GL'  => 'IRT — Semestre 6 (Génie logiciel)',

            'D-S1' => 'DROIT — Semestre 1 (Tronc commun)',
            'D-S2' => 'DROIT — Semestre 2 (Tronc commun)',
            'D-S3' => 'DROIT — Semestre 3 (Tronc commun)',
            'D-S4' => 'DROIT — Semestre 4 (Tronc commun)',

            'D-S5-RID' => 'DROIT — Semestre 5 (RID)',
            'D-S6-RID' => 'DROIT — Semestre 6 (RID)',
            'D-S5-DP'  => 'DROIT — Semestre 5 (DP)',
            'D-S6-DP'  => 'DROIT — Semestre 6 (DP)',

            'SGE-FIN-S1' => 'SGE — Finance — Semestre 1 (Tronc commun)',
            'SGE-FIN-S2' => 'SGE — Finance — Semestre 2 (Tronc commun)',
            'SGE-FIN-S3' => 'SGE — Finance — Semestre 3 (Tronc commun)',
            'SGE-FIN-S4' => 'SGE — Finance — Semestre 4 (Tronc commun)',
            'SGE-FIN-S5-FC'  => 'SGE — Finance — Semestre 5 (FC)',
            'SGE-FIN-S6-FC'  => 'SGE — Finance — Semestre 6 (FC)',
            'SGE-FIN-S5-MBA' => 'SGE — Finance — Semestre 5 (MBA)',
            'SGE-FIN-S6-MBA' => 'SGE — Finance — Semestre 6 (MBA)',

            'SGE-GES-S1' => 'SGE — Gestion — Semestre 1 (Tronc commun)',
            'SGE-GES-S2' => 'SGE — Gestion — Semestre 2 (Tronc commun)',
            'SGE-GES-S3' => 'SGE — Gestion — Semestre 3 (Tronc commun)',
            'SGE-GES-S4' => 'SGE — Gestion — Semestre 4 (Tronc commun)',
            'SGE-GES-S5-GRH' => 'SGE — Gestion — Semestre 5 (GRH)',
            'SGE-GES-S6-GRH' => 'SGE — Gestion — Semestre 6 (GRH)',
            'SGE-GES-S5-LT'  => 'SGE — Gestion — Semestre 5 (LT)',
            'SGE-GES-S6-LT'  => 'SGE — Gestion — Semestre 6 (LT)',
            'SGE-GES-S5-MV'  => 'SGE — Gestion — Semestre 5 (MV)',
            'SGE-GES-S6-MV'  => 'SGE — Gestion — Semestre 6 (MV)',
        ];

        $codes = bulletin_manager::get_allowed_semester_codes();
        $semesters = [];
        foreach ($codes as $code) {
            $semesters[$code] = $labels[$code] ?? $code;
        }

        $mform->addElement('select', 'semester', 'Semestre', $semesters);
        $mform->setType('semester', PARAM_ALPHANUMEXT);
        $mform->addRule('semester', 'Champ obligatoire', 'required', null, 'client');

        // -------------------------------------------------------------
        // 7) Bouton
        // -------------------------------------------------------------
        $this->add_action_buttons(false, 'Continuer');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $mode = $data['mode'] ?? 'student';

        if ($mode === 'student') {
            if (empty($data['userid'])) {
                $errors['userid'] = 'Veuillez sélectionner un étudiant.';
            }
        } else if ($mode === 'cohort') {
            if (empty($data['cohortid'])) {
                $errors['cohortid'] = 'Veuillez sélectionner une cohorte.';
            }
        }

        if (empty($data['anneeuniversitaire'])) {
            $errors['anneeuniversitaire'] = 'Veuillez sélectionner une année universitaire.';
        }

        if (empty($data['filiereaffichage'])) {
            $errors['filiereaffichage'] = 'Veuillez sélectionner une filière.';
        }

        if (empty($data['niveauaffichage'])) {
            $errors['niveauaffichage'] = 'Veuillez sélectionner un niveau.';
        }

        if (empty($data['semester'])) {
            $errors['semester'] = 'Veuillez sélectionner un semestre.';
        }

        return $errors;
    }
}
