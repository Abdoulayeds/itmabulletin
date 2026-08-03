<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_itmabulletin_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026053002) {
        $table = new xmldb_table('local_itmabulletin_jury');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('semestercode', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('uecategoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('approved', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('user_semester_ue_uix', XMLDB_INDEX_UNIQUE, ['userid', 'semestercode', 'uecategoryid']);
            $table->add_index('uecategory_idx', XMLDB_INDEX_NOTUNIQUE, ['uecategoryid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026053002, 'local', 'itmabulletin');
    }

    if ($oldversion < 2026060301) {
        $table = new xmldb_table('local_itmabulletin_issued');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('serialnumber', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('semestercode', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('datahash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('snapshotjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('signature', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('issuedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('issuedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('revokedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokereason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('serialnumber_uix', XMLDB_INDEX_UNIQUE, ['serialnumber']);
            $table->add_index('user_semester_hash_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'semestercode', 'datahash']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060301, 'local', 'itmabulletin');
    }

    if ($oldversion < 2026072801) {
        $currentbaseurl = trim((string)get_config('local_itmabulletin', 'verificationbaseurl'));
        if ($currentbaseurl === '' || rtrim($currentbaseurl, '/') === 'https://itma.edu.ml') {
            set_config('verificationbaseurl', 'https://moodle.itma.edu.ml', 'local_itmabulletin');
        }

        upgrade_plugin_savepoint(true, 2026072801, 'local', 'itmabulletin');
    }

    return true;
}
