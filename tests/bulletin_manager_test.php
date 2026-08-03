<?php
namespace local_itmabulletin;

defined('MOODLE_INTERNAL') || die();

/**
 * Security-focused tests for official bulletin issue verification.
 *
 * @covers \local_itmabulletin\bulletin_manager
 */
final class bulletin_manager_test extends \advanced_testcase {

    private function build_sample_bulletin(float $exam = 14.0): array {
        $student = (object)[
            'id' => 3,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'username' => 'ADA001',
            'idnumber' => '',
        ];

        $ec = (object)[
            'id' => 10,
            'fullname' => 'Algorithmique',
            'shortname' => 'ALGO',
            'idnumber' => 'EC-ALGO-3',
            'credit' => null,
            'notes' => [
                'NOTE_DEV1' => 12.0,
                'NOTE_DEV2' => 15.0,
                'NOTE_EXAM' => $exam,
                'NOTE_RATTRAPAGE' => null,
                'DATE_SESSION' => 'Juin 2026',
            ],
        ];

        $ue = (object)[
            'id' => 20,
            'name' => 'UE INFO - Programmation (INFO1)',
            'idnumber' => 'UE-INFO1-MAJ-3',
            'credit' => null,
            'ecs' => [$ec],
        ];

        return [
            'userid' => 3,
            'semester' => 'S1',
            'student' => $student,
            'ues' => [$ue],
        ];
    }

    public function test_bulletin_datahash_is_stable_for_same_data(): void {
        $this->resetAfterTest();

        $first = bulletin_manager::build_official_bulletin_data($this->build_sample_bulletin(), 'S1');
        $second = bulletin_manager::build_official_bulletin_data($this->build_sample_bulletin(), 'S1');

        $this->assertSame(
            bulletin_manager::calculate_bulletin_datahash($first),
            bulletin_manager::calculate_bulletin_datahash($second)
        );
    }

    public function test_bulletin_datahash_changes_when_grade_changes(): void {
        $this->resetAfterTest();

        $first = bulletin_manager::build_official_bulletin_data($this->build_sample_bulletin(14.0), 'S1');
        $second = bulletin_manager::build_official_bulletin_data($this->build_sample_bulletin(18.0), 'S1');

        $this->assertNotSame(
            bulletin_manager::calculate_bulletin_datahash($first),
            bulletin_manager::calculate_bulletin_datahash($second)
        );
    }

    public function test_issued_bulletin_signature_can_be_verified(): void {
        $this->resetAfterTest();
        set_config('signingsecret', 'unit-test-secret', 'local_itmabulletin');

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'username' => 'ADA001',
        ]);
        $bulletin = $this->build_sample_bulletin();
        $bulletin['userid'] = (int)$user->id;
        $bulletin['student']->id = (int)$user->id;

        $issue = bulletin_manager::issue_bulletin($bulletin, 'S1');

        $this->assertNotNull($issue);
        $this->assertSame($issue->id, bulletin_manager::verify_issued_bulletin($issue->serialnumber, $issue->signature)->id);
        $this->assertNull(bulletin_manager::verify_issued_bulletin($issue->serialnumber, str_repeat('0', 64)));
    }
}
