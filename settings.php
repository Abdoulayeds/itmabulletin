<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_itmabulletin',
        'Génération des bulletins',
        new moodle_url('/local/itmabulletin/index.php'),
        'moodle/site:config'
    ));
}