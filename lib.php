<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Rend un template PHP en string (sans rien afficher).
 *
 * @param string $filepath Chemin absolu du fichier template
 * @param array  $vars     Variables à injecter dans le template
 * @return string
 */
function local_itmabulletin_render_template_to_string(string $filepath, array $vars = []): string {
    if (!file_exists($filepath)) {
        return '';
    }

    extract($vars, EXTR_SKIP);

    ob_start();
    include $filepath;
    return (string)ob_get_clean();
}

/**
 * Génère le PDF ITMA.
 * IMPORTANT: aucune sortie (echo/print) hors de cette fonction.
 */
function local_itmabulletin_generate_pdf($student, $bulletin, $semester) {
    global $CFG;

    require_once($CFG->libdir . '/pdflib.php');

    $pdf = new pdf();
    $pdf->SetMargins(10, 10);
    $pdf->AddPage();

    // Construire HTML du tableau (tu as déjà cette fonction)
    $notes_html = local_itmabulletin_render_notes_html($bulletin);

    // Template en string (pas de echo parasite)
    $templatepath = __DIR__ . '/templates/bulletin_pdf.php';
    $html = local_itmabulletin_render_template_to_string($templatepath, [
        'student'    => $student,
        'bulletin'   => $bulletin,
        'semester'   => $semester,
        'notes_html' => $notes_html,
        'CFG'        => $CFG,
    ]);

    $pdf->writeHTML($html);

    $filename = "Bulletin_ITMA_{$student->lastname}_{$semester}.pdf";
    $pdf->Output($filename, 'D');
    exit;
}

/**
 * Ajoute un lien de navigation vers la génération des bulletins.
 * Visible uniquement pour l'administrateur du site.
 *
 * @param global_navigation $navigation
 */
function local_itmabulletin_extend_navigation(global_navigation $navigation) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $systemcontext = context_system::instance();

    if (!isguestuser()) {
        $studenturl = new moodle_url('/local/itmabulletin/consultation.php');
        $navigation->add(
            get_string('student_nav_label', 'local_itmabulletin'),
            $studenturl,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_itmabulletin_consultation',
            new pix_icon('i/report', '')
        );
    }

    if (has_capability('local/itmabulletin:generate', $systemcontext)) {
        $adminurl = new moodle_url('/local/itmabulletin/index.php');
        $navigation->add(
            get_string('admin_nav_label', 'local_itmabulletin'),
            $adminurl,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_itmabulletin_generate',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Ajoute un accès direct dans la navigation utilisateur (menu avatar / préférences).
 *
 * @param settings_navigation $navigation
 * @param stdClass $user
 * @param context_user $usercontext
 * @param stdClass|null $course
 * @param context_course|null $coursecontext
 */
function local_itmabulletin_extend_navigation_user($navigation, $user, $usercontext, $course, $coursecontext) {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $studenturl = new moodle_url('/local/itmabulletin/consultation.php');
    $navigation->add(
        get_string('student_nav_label', 'local_itmabulletin'),
        $studenturl,
        navigation_node::TYPE_SETTING,
        null,
        'local_itmabulletin_user_consultation',
        new pix_icon('i/report', '')
    );

    $systemcontext = context_system::instance();
    if (has_capability('local/itmabulletin:generate', $systemcontext)) {
        $adminurl = new moodle_url('/local/itmabulletin/index.php');
        $navigation->add(
            get_string('admin_nav_label', 'local_itmabulletin'),
            $adminurl,
            navigation_node::TYPE_SETTING,
            null,
            'local_itmabulletin_user_generation',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Bouton d'accès rapide sur le Dashboard étudiant.
 *
 * @return string
 */
function local_itmabulletin_before_footer(): string {
    global $PAGE;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    // Affiche uniquement sur le dashboard utilisateur.
    if (($PAGE->pagetype ?? '') !== 'my-index') {
        return '';
    }

    $systemcontext = context_system::instance();

    // Le bouton dashboard est destiné aux étudiants/utilisateurs non admins.
    if (has_capability('local/itmabulletin:generate', $systemcontext)) {
        return '';
    }

    $url = new moodle_url('/local/itmabulletin/consultation.php');
    $button = html_writer::link($url, get_string('dashboard_cta', 'local_itmabulletin'), [
        'class' => 'btn btn-primary itmabulletin-dashboard-btn',
    ]);

    return html_writer::div($button, 'itmabulletin-dashboard-cta');
}
