<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/itmabulletin:generate', $context);

$action = required_param('action', PARAM_ALPHANUMEXT);

header('Content-Type: application/json; charset=utf-8');

global $DB;

if ($action === 'searchcohorts') {
    $q = optional_param('q', '', PARAM_RAW_TRIMMED);
    $q = trim($q);

    $params = [];
    $where = '1=1';

    if ($q !== '') {
        $where = $DB->sql_like('name', ':q', false);
        $params['q'] = '%' . $DB->sql_like_escape($q) . '%';
    }

    $cohorts = $DB->get_records_select('cohort', $where, $params, 'name ASC', 'id, name', 0, 50);

    $out = [];
    foreach ($cohorts as $c) {
        $out[] = [
            'id' => (int)$c->id,
            'name' => $c->name . " (ID: {$c->id})",
        ];
    }

    echo json_encode($out);
    die();
}

echo json_encode([]);
die();