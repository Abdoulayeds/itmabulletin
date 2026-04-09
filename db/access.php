<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Capacité pour simplement voir / accéder à la page du plugin.
    'local/itmabulletin:view' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'           => CAP_ALLOW,   // Utilisateur authentifié (contexte système)
            'manager'        => CAP_ALLOW,   // Seul l'admin
            'coursecreator'  => CAP_PREVENT,
            'teacher'        => CAP_PREVENT,
            'editingteacher' => CAP_PREVENT,
            'student'        => CAP_ALLOW,
        ],
    ],

    // Capacité pour générer les bulletins (utilisée dans index.php).
    'local/itmabulletin:generate' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,   // Seul l'admin
            'coursecreator'  => CAP_PREVENT,
            'teacher'        => CAP_PREVENT,
            'editingteacher' => CAP_PREVENT,
            'student'        => CAP_PREVENT,
        ],
    ],
];
