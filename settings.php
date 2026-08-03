<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settingspage = new admin_settingpage(
        'local_itmabulletin_settings',
        'Parametres bulletins ITMA'
    );

    $settingspage->add(new admin_setting_configtext(
        'local_itmabulletin/verificationbaseurl',
        'URL publique de verification',
        'Domaine public utilise dans le QR code. Exemple : https://moodle.itma.edu.ml. Si ce champ reste vide, Moodle utilise wwwroot.',
        'https://moodle.itma.edu.ml',
        PARAM_URL
    ));

    $settingspage->add(new admin_setting_configpasswordunmask(
        'local_itmabulletin/signingsecret',
        'Cle de signature des bulletins',
        'Secret HMAC utilise pour signer les QR codes officiels. Laissez vide pour une generation automatique au premier bulletin emis.',
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settingspage);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_itmabulletin',
        'Generation des bulletins',
        new moodle_url('/local/itmabulletin/index.php'),
        'moodle/site:config'
    ));
}
