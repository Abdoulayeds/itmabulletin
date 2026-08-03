<?php
namespace local_itmabulletin;

defined('MOODLE_INTERNAL') || die();

class bulletin_manager {

    private const OFFICIAL_TABLE_WIDTHS = ['46%', '10%', '15%', '14%', '15%'];
    private const STUDENT_TABLE_WIDTHS = ['38%', '8%', '14%', '13%', '13%', '14%'];
    private const PUBLIC_VERIFICATION_BASEURL = 'https://moodle.itma.edu.ml';
    private const ISSUE_STATUS_ACTIVE = 'active';
    private const ISSUE_STATUS_REVOKED = 'revoked';

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
        'DATE_SESSION', // âœ… item texte saisi (feedback)
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
     * RÃ¨gle acadÃ©mique ITMA d'arrondi des moyennes UE.
     *
     * Si la moyenne UE est entre 11.67 et 11.99,
     * elle est automatiquement arrondie Ã  12.00.
     */
    public static function apply_academic_rounding(float $value): float {

        if ($value >= 11.665 && $value < 12) {
            return 12.0;
        }

        return $value;
    }

    private static function credit_from_idnumber(string $idnumber): ?float {
        $parts = explode('-', $idnumber);
        if (count($parts) < 2) {
            return null;
        }

        $last = end($parts);
        return is_numeric($last) ? (float)$last : null;
    }

    private static function code_from_ue_idnumber(string $idnumber): ?string {
        $parts = explode('-', $idnumber);
        return $parts[1] ?? null;
    }

    private static function format_ue_code(?string $code): ?string {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z]+)([0-9]+)$/', $code, $matches)) {
            return strtoupper($matches[1]) . '-' . $matches[2];
        }

        return strtoupper($code);
    }

    private static function clean_ue_name(string $name): string {
        $name = preg_replace('/^UE\s+.+?\s+[-]\s*/u', '', $name);
        $name = preg_replace('/\([A-Z0-9]+\)\s*$/u', '', $name);
        return trim($name);
    }

    private static function type_from_ue_idnumber(string $idnumber): string {
        $parts = explode('-', $idnumber);
        $flag = $parts[2] ?? '';

        switch ($flag) {
            case 'MAJ':
                return 'UE Majeure';
            case 'MIN':
                return 'UE Mineure';
            case 'LIB':
                return 'UE Libre';
            default:
                return 'UE';
        }
    }

    private static function compute_class_avg($dev1, $dev2): ?float {
        $values = [];
        if ($dev1 !== null && $dev1 !== '') {
            $values[] = (float)$dev1;
        }
        if ($dev2 !== null && $dev2 !== '') {
            $values[] = (float)$dev2;
        }

        return empty($values) ? null : array_sum($values) / count($values);
    }

    private static function compute_ec_avg(?float $classavg, $exam, $rattrapage): float {
        $classvalue = ($classavg === null) ? 0.0 : (float)$classavg;
        $examvalue = ($exam !== null && $exam !== '') ? (float)$exam : 0.0;
        $rat = ($rattrapage !== null && $rattrapage !== '') ? (float)$rattrapage : null;
        $normalavg = (2 * $examvalue + $classvalue) / 3.0;

        return ($rat !== null && $rat > $normalavg) ? $rat : $normalavg;
    }

    private static function canonical_note($value): ?float {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return round((float)$value, 4);
    }

    public static function build_official_bulletin_data(array $bulletin, string $semester): array {
        $student = $bulletin['student'] ?? null;
        $ues = $bulletin['ues'] ?? [];
        $userid = $student ? (int)$student->id : (int)($bulletin['userid'] ?? 0);
        $juryvalidatedueids = self::get_jury_validated_ueids($userid, $semester);

        $official = [
            'schema' => 1,
            'userid' => $userid,
            'semester' => $semester,
            'student' => [
                'id' => $userid,
                'firstname' => $student ? trim((string)$student->firstname) : '',
                'lastname' => $student ? trim((string)$student->lastname) : '',
                'username' => $student ? trim((string)$student->username) : '',
                'idnumber' => $student ? trim((string)($student->idnumber ?? '')) : '',
            ],
            'ues' => [],
            'totalcredits' => 0.0,
            'generalaverage' => null,
        ];

        $semweightedsum = 0.0;
        $semcreditssum = 0.0;

        foreach ($ues as $ue) {
            $ueweightedsum = 0.0;
            $uecreditssum = 0.0;
            $ecs = [];

            foreach (($ue->ecs ?? []) as $ec) {
                $notes = $ec->notes ?? [];
                $dev1 = $notes['NOTE_DEV1'] ?? null;
                $dev2 = $notes['NOTE_DEV2'] ?? null;
                $exam = $notes['NOTE_EXAM'] ?? null;
                $rat = $notes['NOTE_RATTRAPAGE'] ?? null;
                $classavg = self::compute_class_avg($dev1, $dev2);
                $normalavg = (2 * (($exam !== null && $exam !== '') ? (float)$exam : 0.0)
                    + (($classavg !== null) ? (float)$classavg : 0.0)) / 3.0;
                $ecavg = self::compute_ec_avg($classavg, $exam, $rat);

                if ($rat !== null && $rat !== '' && (float)$rat > $normalavg) {
                    $classavg = (float)$rat;
                    $exam = (float)$rat;
                    $ecavg = (float)$rat;
                }

                $eccredit = $ec->credit ?? null;
                if ($eccredit === null) {
                    $eccredit = self::credit_from_idnumber($ec->idnumber ?? '');
                }
                $eccredit = $eccredit ? (float)$eccredit : 0.0;

                if ($eccredit > 0) {
                    $ueweightedsum += $ecavg * $eccredit;
                    $uecreditssum += $eccredit;
                }

                $ecs[] = [
                    'id' => (int)($ec->id ?? 0),
                    'fullname' => trim((string)($ec->fullname ?? '')),
                    'shortname' => trim((string)($ec->shortname ?? '')),
                    'idnumber' => trim((string)($ec->idnumber ?? '')),
                    'credit' => self::canonical_note($eccredit),
                    'classaverage' => self::canonical_note($classavg),
                    'exam' => self::canonical_note($exam),
                    'makeup' => self::canonical_note($rat),
                    'average' => self::canonical_note($ecavg),
                    'sessiondate' => trim((string)($notes['DATE_SESSION'] ?? '')),
                ];
            }

            $ueavg = ($uecreditssum > 0) ? self::apply_academic_rounding($ueweightedsum / $uecreditssum) : null;
            $uecredit = $ue->credit ?? null;
            if ($uecredit === null) {
                $uecredit = self::credit_from_idnumber($ue->idnumber ?? '');
            }
            $uecredit = $uecredit ? (float)$uecredit : 0.0;

            if ($ueavg !== null && $uecredit > 0) {
                $semweightedsum += $ueavg * $uecredit;
                $semcreditssum += $uecredit;
            }

            $uecode = self::code_from_ue_idnumber($ue->idnumber ?? '');
            $displaycode = self::format_ue_code($uecode);
            $uename = self::clean_ue_name($ue->name ?? '');
            $juryvalidated = ($ueavg !== null && $ueavg < 12.0 && !empty($juryvalidatedueids[(int)$ue->id]));

            $official['ues'][] = [
                'id' => (int)($ue->id ?? 0),
                'name' => trim((string)($ue->name ?? '')),
                'label' => trim(($displaycode ? $displaycode . ' ' : '') . $uename),
                'type' => self::type_from_ue_idnumber($ue->idnumber ?? ''),
                'idnumber' => trim((string)($ue->idnumber ?? '')),
                'credit' => self::canonical_note($uecredit),
                'average' => self::canonical_note($ueavg),
                'status' => $juryvalidated ? 'Validee par jury' : (($ueavg !== null && $ueavg >= 12.0) ? 'Validee' : 'En session de rattrapage'),
                'juryvalidated' => $juryvalidated,
                'ecs' => $ecs,
            ];
        }

        if ($semcreditssum > 0) {
            $official['totalcredits'] = self::canonical_note($semcreditssum);
            $official['generalaverage'] = self::canonical_note(self::apply_academic_rounding($semweightedsum / $semcreditssum));
        }

        return $official;
    }

    private static function canonical_json(array $data): string {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function calculate_bulletin_datahash(array $officialdata): string {
        return hash('sha256', self::canonical_json($officialdata));
    }

    private static function get_signing_secret(): string {
        $secret = (string)get_config('local_itmabulletin', 'signingsecret');
        if ($secret !== '') {
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            $secret = hash('sha256', get_site_identifier() . '|' . microtime(true));
        }

        set_config('signingsecret', $secret, 'local_itmabulletin');
        return $secret;
    }

    private static function sign_issue_payload(string $serialnumber, int $userid, string $semester, string $datahash, int $issuedat): string {
        $payload = $serialnumber . '|' . $userid . '|' . $semester . '|' . $datahash . '|' . $issuedat;
        return hash_hmac('sha256', $payload, self::get_signing_secret());
    }

    private static function issued_table_exists(): bool {
        global $DB;

        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_itmabulletin_issued'));
    }

    private static function generate_serial_number(int $userid, string $semester, string $datahash): string {
        $seed = $userid . '|' . $semester . '|' . $datahash . '|' . microtime(true);
        try {
            $seed .= '|' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $seed .= '|' . uniqid('', true);
        }

        return 'ITMA-' . date('Ymd') . '-' . strtoupper(substr(hash('sha256', $seed), 0, 12));
    }

    public static function issue_bulletin(array $bulletin, string $semester): ?\stdClass {
        global $DB, $USER;

        if (!self::issued_table_exists()) {
            return null;
        }

        $officialdata = self::build_official_bulletin_data($bulletin, $semester);
        $userid = (int)($officialdata['userid'] ?? 0);
        if ($userid <= 0) {
            return null;
        }

        $datahash = self::calculate_bulletin_datahash($officialdata);
        $existing = $DB->get_record('local_itmabulletin_issued', [
            'userid' => $userid,
            'semestercode' => $semester,
            'datahash' => $datahash,
            'status' => self::ISSUE_STATUS_ACTIVE,
        ], '*', IGNORE_MISSING);

        if ($existing) {
            return $existing;
        }

        $now = time();
        $record = new \stdClass();
        $record->serialnumber = self::generate_serial_number($userid, $semester, $datahash);
        $record->userid = $userid;
        $record->semestercode = $semester;
        $record->datahash = $datahash;
        $record->snapshotjson = self::canonical_json($officialdata);
        $record->issuedat = $now;
        $record->issuedby = isset($USER->id) ? (int)$USER->id : 0;
        $record->status = self::ISSUE_STATUS_ACTIVE;
        $record->revokedat = 0;
        $record->revokedby = 0;
        $record->revokereason = '';
        $record->signature = self::sign_issue_payload($record->serialnumber, $userid, $semester, $datahash, $now);
        $record->timecreated = $now;
        $record->timemodif×Ž<æÚ$z{-®éÜj×&6¶w&÷VæBÖ6öÆ÷#¢6fffffc°¢&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâÖ6÷—°¢FF–æs£GB°¢6öÆ÷#¢3#3ƒVc°¢föçB×6—¦S£‚ãGC°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâÖ6÷’6ÖÆÂÀ¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâ×"6ÖÆÇ°¢6öÆ÷#¢3S3f#ƒ#°¢föçB×6—¦S£rã'C°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâ×'°¢FF–æs£°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W7°¢Ö&v–â×F÷£WC°¢6öÆ÷#¢3#3ƒVc°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W2FG°¢FF–ær×F÷£GC°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&RÖÆ–æW°¢F—7Æ“¦&Æö6³°¢†V–v‡C£WC°¢&÷&FW"×F÷£ãwB6öÆ–B3#3ƒVc°§Ð¤553°Ð Ð¢FgVÆÆ‡FÖÂÒsÇ7G–ÆSârâF772âsÂ÷7G–ÆSârâF‡FÖÃ°¢GFbÓçw&—FT…DÔÂ‚FgVÆÆ‡FÖÂÂG'VRÂfÇ6RÂG'VRÂfÇ6RÂrr“° Ð¢–b‚F6†÷6VâbbF–ÖvWG—R’°Ð¢F–ÖvFFÒf–ÆUövWEö6öçFVçG2‚F6†÷6Vâ“°Ð¢–b‚F–ÖvFFÓÒfÇ6Rbb7G&ÆVâ‚F–ÖvFF’â’°Ð¢G'’°Ð¢GFbÓä–ÖvR‚trâF–ÖvFFÂÂÂ’ÂÂF–ÖvWG—R“°Ð¢Ò6F6‚…ÅF‡&÷v&ÆRFR’°Ð¢òò–væ÷&PÐ¢ÐÐ¢ÐÐ¢ÐÐ Ð¢Ff–ÆVæÖRÒ6VÆc£¦'V–ÆEö'VÆÆWF–åöf–ÆVæÖR‚GW6W&–BÂG6VÖW7FW"“°Ð¢GFbÓä÷WGWB‚Ff–ÆVæÖRÂtBr“°Ð¢W†—C°Ð¢ÐÐ Ð¢òòÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÐÐ¢òò)ÈR4ô„õ%DRÓâ¤• Ð¢òòÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÒÐÐ Ð¢ò¢ Ð¢¢&WF÷W&æRÆÆ—7FRFW2W6W'2BwVæR6ö†÷'FRàÐ¢¢ðÐ¢V&Æ–27FF–2gVæ7F–öâvWEö6ö†÷'E÷W6W'2†–çBF6ö†÷'F–B“¢'&’°Ð¢vÆö&ÂDD#°Ð Ð¢–b‚F6ö†÷'F–BÃÒ’°Ð¢&WGW&âµÓ°Ð¢ÐÐ Ð¢G7ÂÒ%4TÄT5BRæ–BÂRæf—'7FæÖRÂRæÆ7FæÖRÂRçW6W&æÖRÂRæ–FçVÖ&W Ð¢e$ôÒ¶6ö†÷'EöÖVÖ&W'7Ò6Ð¢¤ô”â·W6W'ÒRôâRæ–BÒ6ÒçW6W&–@¢t„U$R6Òæ6ö†÷'F–BÒ¦6–@¢äBRæFVÆWFVBÒ ¢äBRç7W7VæFVBÒ ¢äBRæ6öæf—&ÖVBÒ¢õ$DU"%’RæÆ7FæÖR42ÂRæf—'7FæÖR42#°¢&WGW&âDD"ÓævWE÷&V6÷&G5÷7Â‚G7ÂÂ²v6–BrÓâF6ö†÷'F–EÒ“°Ð¢ÐÐ Ð¢ò¢ Ð¢¢æWGF÷–vRæöÒFRf–6†–W"àÐ¢¢ðÐ¢&—fFR7FF–2gVæ7F–öâ'V–ÆEö'VÆÆWF–åöf–ÆVæÖR†–çBGW6W&–BÂ7G&–ærG6VÖW7FW"“¢7G&–ær°Ð¢vÆö&ÂDD#°Ð Ð¢G7GVFVçBÒDD"ÓævWE÷&V6÷&B‚wW6W"rÂ²v–BrÓâGW6W&–EÒÂvf—'7FæÖRÂÆ7FæÖRrÂ”täõ$UôÔ•54”är“°Ð Ð¢FÆ7FæÖRÒrs°Ð¢Ff—'7FæÖRÒrs°Ð¢–b‚G7GVFVçB’°Ð¢FÆ7FæÖRÒÖ%÷7G'F÷WW"‡G&–Ò‚‡7G&–ær’‚G7GVFVçBÓæÆ7FæÖRóòrr’’ÂuUDbÓ‚r“°Ð¢Ff—'7FæÖRÒÖ%÷7G'F÷WW"‡G&–Ò‚‡7G&–ær’‚G7GVFVçBÓæf—'7FæÖRóòrr’’ÂuUDbÓ‚r“°Ð¢ÐÐ Ð¢FÆ7FæÖRÒ&Vu÷&WÆ6R‚rõµåÇÅÇåÂÒÒ²÷RrÂrrÂFÆ7FæÖR“°Ð¢Ff—'7FæÖRÒ&Vu÷&WÆ6R‚rõµåÇÅÇåÂÒÒ²÷RrÂrrÂFf—'7FæÖR“°Ð¢FÆ7FæÖRÒ&Vu÷&WÆ6R‚rõÇ2²÷RrÂrrÂFÆ7FæÖR“°Ð¢Ff—'7FæÖRÒ&Vu÷&WÆ6R‚rõÇ2²÷RrÂrrÂFf—'7FæÖR“°Ð Ð¢G'G2Ò'&•öf–ÇFW"…°Ð¢G&–Ò‚‡7G&–ær’FÆ7FæÖR’ÀÐ¢G&–Ò‚‡7G&–ær’Ff—'7FæÖR’ÀÐ¢Ö%÷7G'F÷WW"‡G&–Ò‚‡7G&–ær’G6VÖW7FW"’ÂuUDbÓ‚r’ÀÐ¢ÒÂfâ‚Gb’ÓâGbÓÒrr“°Ð Ð¢Ff–ÆVæÖRÒG&–Ò†–×ÆöFR‚rrÂG'G2’“°Ð¢&WGW&â‚Ff–ÆVæÖRÓÓÒrròt%TÄÄUD”âr¢Ff–ÆVæÖR’ârçFbs°Ð¢ÐÐ Ð¢&—fFR7FF–2gVæ7F–öâ6æ—F—¦Uöf–ÆVæÖR‡7G&–ærG2“¢7G&–ær°Ð¢G2ÒG&–Ò‚G2“°Ð¢G2Ò&Vu÷&WÆ6R‚rõµåÇÅÇåÂÕõÂâÒ²÷RrÂrrÂG2“°Ð¢G2Ò&Vu÷&WÆ6R‚rõÇ2²÷RrÂuòrÂG2“°Ð¢G2ÒG&–Ò‚G2ÂråòÒr“°Ð¢&WGW&â‚G2ÓÓÒrr’òvWGVF–çBr¢G3°Ð¢ÐÐ Ð¢ò¢ Ð¢¢|:–ì:‡&RÆRDbFç2Vâf–6†–W"„äRDTÄT4„$tR2’àÐ¢¢”Õõ%DåC¢2FRW†—C²–6’àÐ¢¢ðÐ¢V&Æ–27FF–2gVæ7F–öâvVæW&FU÷Feöf–ÆR†–çBGW6W&–BÂ7G&–ærG6VÖW7FW"Â7G&–ærF‡FÖÂÂ7G&–ærFf–ÆWF‚“¢&ööÂ°Ð¢vÆö&ÂD4ds°Ð Ð¢&WV—&Uööæ6R‚D4drÓæÆ–&F—"âr÷F7Fb÷F7Fbç‡r“°Ð Ð¢FF—"ÒF—&æÖR‚Ff–ÆWF‚“°Ð¢–b‚—5öF—"‚FF—"’’°Ð¢Ö¶F—"‚FF—"ÂssÂG'VR“°Ð¢ÐÐ Ð¢òòæWGF÷–W"'VffW'2Œ:—f—FR6÷''WF–öâDbÐ¢v†–ÆR†ö%övWEöÆWfVÂ‚’’°Ð¢ö%öVæEö6ÆVâ‚“°Ð¢ÐÐ Ð¢GFbÒæWrÅD5Db…DeõtUôõ$”TåDD”ôâÂDeõTä•BÂDeõtUôdõ$ÔBÂG'VRÂuUDbÓ‚rÂfÇ6R“°Ð¢GFbÓå6WD7&VF÷"‚tÖööFÆRr“°Ð¢GFbÓå6WDWF†÷"‚t•DÔr“°Ð¢GFbÓå6WEF—FÆR‚t'VÆÆWF–âFRæ÷FW2(	2•DÔr“°Ð¢GFbÓç6WE&–çD†VFW"†fÇ6R“°Ð¢GFbÓç6WE&–çDfö÷FW"†fÇ6R“°Ð Ð¢GFbÓå6WDÖ&v–ç2ƒBÂ"ÂB“°¢GFbÓå6WDWFõvT'&V²‡G'VRÂR“°¢GFbÓäFEvR‚“°Ð¢GFbÓå6WDföçB‚v†VÇfWF–6rÂrrÂ“°Ð Ð¢FÆövö§rÒD4drÓæF—'&ö÷BâröÆö6Âö—FÖ'VÆÆWF–â÷—‚öÆövòæ§rs°Ð¢FÆöv÷ærÒD4drÓæF—'&ö÷BâröÆö6Âö—FÖ'VÆÆWF–â÷—‚öÆövòçærs°Ð Ð¢F6†÷6VâÒçVÆÃ°Ð¢F–ÖvWG—RÒçVÆÃ°Ð Ð¢–b†f–ÆUöW†—7G2‚FÆövö§r’bb—5÷&VF&ÆR‚FÆövö§r’’°Ð¢F6†÷6VâÒ&VÇF‚‚FÆövö§r“°Ð¢F–ÖvWG—RÒt¥rs°Ð¢ÒVÇ6V–b†f–ÆUöW†—7G2‚FÆöv÷ær’bb—5÷&VF&ÆR‚FÆöv÷ær’’°Ð¢F6†÷6VâÒ&VÇF‚‚FÆöv÷ær“°Ð¢F–ÖvWG—RÒuärs°Ð¢ÐÐ Ð¢GFfÆövö‡FÖÂÒrs°Ð¢–b‚F6†÷6Vâ’°Ð¢GFf–ÖwF‚Ò7G%÷&WÆ6R‚uÅÂrÂròrÂF6†÷6Vâ“°Ð¢GFfÆövö‡FÖÂÒsÆ–Ör7&3Ò"râ2‚GFf–ÖwF‚’âr"ÇCÒ$•DÔ"6Æ73Ò'FbÖÆövòÖ–æÆ–æR"v–GFƒÒ#S‚"óâs°¢ÐÐ Ð¢F‡FÖÂÒ&Vu÷&WÆ6R€Ð¢r3Æ–ÖuµãåÒ¦6Æ73Ò%µâ%Ò¦—FÖ'VÆÆWF–âÖÆövõµâ%Ò¢%µãåÒ£â6’rÀÐ¢GFfÆövö‡FÖÂÀÐ¢F‡FÖÀÐ¢“°Ð Ð¢F‡FÖÂÒ&Vu÷&WÆ6Uö6ÆÆ&6²€Ð¢r3ÆF—eÇ2·7G–ÆSÒ&†V–v‡C¥Ç2¢…³Ó•Ò²•Ç2§ƒ³õÇ2¢%Ç2£åÇ2£ÂöF—câ6’rÀÐ¢gVæ7F–öâ‚FÒ’°Ð¢F‚Ò†–çB—&÷VæB‚‚†–çB’FÕ³Ò’¢ãSR“°Ð¢–b‚F‚ÃÒ’°Ð¢&WGW&ârs°Ð¢ÐÐ¢&WGW&âsÇF&ÆR6Æ73Ò'Fb×76W""6VÆÇ76–æsÒ#"6VÆÇFF–æsÒ#"&÷&FW#Ò#"v–GFƒÒ#R"7G–ÆSÒ'v–GFƒ£S²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²#âpÐ¢âsÇG#ãÇFB7G–ÆSÒ&†V–v‡C¢râF‚âwC²Æ–æRÖ†V–v‡C¢râF‚âwC²föçB×6—¦S£C²#âfæ'7³Â÷FCãÂ÷G#âpÐ¢âsÂ÷F&ÆSâs°Ð¢ÒÀÐ¢F‡FÖÀÐ¢“°Ð Ð¢F‡FÖÂÒ6VÆc£§F6…ö‡FÖÅöf÷%÷F7Fb‚F‡FÖÂ“°Ð¢F‡FÖÂÒ6VÆc£¦FEööff–6–Å÷F7Feö&÷GFöÕ÷76W'2‚F‡FÖÂ“°Ð¢F–FVçF—G’ÒçVÆÃ°Ð¢F‡FÖÂÒ6VÆc£§&WÆ6Uö–FVçF—G•÷F&ÆUöf÷%÷F7Fb‚F‡FÖÂÂF–FVçF—G’“°Ð¢GfW&–g—W&ÂÒçVÆÃ°¢G&f–ÆRÒ6VÆc£¦W‡G&7E÷&6öFUöf–ÆUöf÷%÷F7Fb‚F‡FÖÂÂGfW&–g—W&Â“° Ð¢F772ÒÃÃÄ550Ð¦&öG’²föçBÖfÖ–Ç“¢†VÇfWF–6Â6ç2×6W&–c²föçB×6—¦S¢’ã'C²6öÆ÷#¢3²Ð¢æ—FÖ'VÆÆWF–â×w&W"²v–GFƒ£S²Ð¢æ—FÖ'VÆÆWF–âÖöff–6–ÂÖ†VFW'²Ö&v–ã£'C²Ð¢æ—FÖ'VÆÆWF–âÖöff–6–ÂÖ†VFW"×F&ÆW²v–GFƒ£S²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²F&ÆRÖÆ–÷WC¦f—†VC²Ð¢æ—FÖ'VÆÆWF–âÖöff–6–ÂÖÆövòÖ6VÆÇ²FW‡BÖÆ–vã¦ÆVgC²fW'F–6ÂÖÆ–vã§F÷²FF–ærÖ&÷GFöÓ£C²Ð¢æ—FÖ'VÆÆWF–âÖöff–6–ÂÖæÖRÖ6VÆÇ²FW‡BÖÆ–vã¦6VçFW#²fW'F–6ÂÖÆ–vã¦Ö–FFÆS²FF–ær×F÷£²Ð¢çFbÖÆövòÖ–æÆ–æW²F—7Æ“¦&Æö6³²Ð¢æ—FÖ'VÆÆWF–âÖWF"Öæö×°¢föçB×vV–v‡C¢“°¢föçB×6—¦S¢ãGC°¢FW‡BÖÆ–vã¦6VçFW#°¢v†—FR×76S¢æ÷w&°¢FW‡B×G&ç6f÷&Ó¢WW&66S°¢Æ–æRÖ†V–v‡C¢ãC°§Ð¢æ—FÖ'VÆÆWF–âÖ'VÆÆWF–â×F—FÆRÖ6VçFW'°¢FW‡BÖÆ–vã¦6VçFW#°¢föçB×6—¦S¢"ã‡C°¢föçB×vV–v‡C¢“°¢FW‡B×G&ç6f÷&Ó¢WW&66S°¢ÆWGFW"×76–æs¢ã'C°§Ð¢çFb×76W"FG²föçB×6—¦S£C²Æ–æRÖ†V–v‡C£²Ð¢çFbÖ–FVçF—FRÖfÆ÷w°¢v–GFƒ£S°¢&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S°¢Ö&v–ã£B'C°§Ð¢çFbÖ–FVçF—FRÖfÆ÷rÖ6VÆÇ°¢FF–æs£'B7C°¢föçB×6—¦S£‚ãgC°¢Æ–æRÖ†V–v‡C£ãƒ°¢föçB×vV–v‡C£s°¢6öÆ÷#¢3°§Ð¢çFbÖ–FVçF—FRÖfÆ÷r×"×76W°¢FF–æs£°¢föçB×6—¦S£C°¢Æ–æRÖ†V–v‡C£°§Ð¢æ—FÖ'VÆÆWF–â×F÷–æfò×F&ÆRÀ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FR×F&ÆRÀ¢æ—FÖ'VÆÆWF–â×6–væGW&W7°¢v–GFƒ£S°Ð¢Ö&v–âÖÆVgC¦WFó°Ð¢Ö&v–â×&–v‡C¦WFó°Ð§ÐÐ¢æ—FÖ'VÆÆWF–â×F÷–æfò×F&ÆR²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²Ö&v–â×F÷£C²Ð¢æ—FÖ'VÆÆWF–â×F÷–æfòÖÆVgBÀ¢æ—FÖ'VÆÆWF–â×F÷–æfò×&–v‡G°¢föçB×6—¦S¢‚ãgC°¢Æ–æRÖ†V–v‡C¢ãƒ°§Ð¢æ—FÖ'VÆÆWF–â×F÷–æfò×&–v‡G²FW‡BÖÆ–vã§&–v‡C²ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FR×F&ÆR²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²F&ÆRÖÆ–÷WC¦f—†VC²&÷&FW#£²ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FR×F&ÆRFB°Ð¢FF–æs¢Bã‡BGC°Ð¢föçB×6—¦S¢’ãwC°Ð¢&÷&FW#£°Ð¢&÷&FW"Ö&÷GFöÓ£°Ð§ÐÐ¢çFbÖ–FVçF—FRÖ6ÆVç°Ð¢v–GFƒ£S°Ð¢Ö&v–ã£—B°Ð¢FF–æs£GC°Ð¢föçB×6—¦S£’ãwC°Ð¢föçB×vV–v‡C£s°Ð¢Æ–æRÖ†V–v‡C£ãCS°Ð§ÐÐ¢çFbÖ–FVçF—FRÖÆ–æW°Ð¢v–GFƒ£S°Ð¢6ÆV#¦&÷Fƒ°Ð¢FF–æs£ãWB°Ð§ÐÐ¢çFbÖ–FVçF—FR×&–v‡G°Ð¢fÆöC§&–v‡C°Ð¢FW‡BÖÆ–vã§&–v‡C°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FRÖ6ÆVç°Ð¢v–GFƒ£S°Ð¢Ö&v–ã£‡B°Ð¢föçB×6—¦S£’ã‡C°Ð¢Æ–æRÖ†V–v‡C£ãCS°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FR×&÷w°Ð¢v–GFƒ£S°Ð¢FF–æs£ãWB°Ð¢6ÆV#¦&÷Fƒ°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FRÖÆVgG°Ð¢fÆöC¦ÆVgC°Ð¢v–GFƒ£SS°Ð¢v†—FR×76S¦æ÷w&°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖ–FVçF—FR×&–v‡G°Ð¢fÆöC§&–v‡C°Ð¢v–GFƒ£SS°Ð¢FW‡BÖÆ–vã§&–v‡C°Ð¢v†—FR×76S¦æ÷w&°Ð§ÐÐ¢æ—FÖ'VÆÆWF–â×F&ÆR²v–GFƒ£“bR–×÷'FçC²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²F&ÆRÖÆ–÷WC¦f—†VC²Ö&v–ã¢'BWFò²Ð¢æ—FÖ'VÆÆWF–â×F&ÆRF‡°Ð¢&6¶w&÷VæBÖ6öÆ÷#¢6C–C–C“°Ð¢&÷&FW#£ãwB6öÆ–B3°Ð¢FF–æs£2ã'B"ã'C°¢föçB×6—¦S¢‚ãC°¢föçB×vV–v‡C¢“°Ð¢FW‡BÖÆ–vã¦6VçFW#°Ð¢fW'F–6ÂÖÆ–vã¦Ö–FFÆS°Ð¢v†—FR×76S¢æ÷&ÖÃ°Ð§ÐÐ¢æ—FÖ'VÆÆWF–â×F&ÆRFG°Ð¢&÷&FW#£ãwB6öÆ–B3°Ð¢FF–æs£2ã'B"ãGC°¢föçB×6—¦S¢‡C°¢fW'F–6ÂÖÆ–vã¢Ö–FFÆS°¢Æ–æRÖ†V–v‡C¢ãƒ°§Ð¢æ—FÖ'VÆÆWF–â×F&ÆRFƒ¦f—'7BÖ6†–ÆBÀ¢æ—FÖ'VÆÆWF–â×F&ÆRFC¦f—'7BÖ6†–ÆG²FF–ærÖÆVgC£WC²Ð¢æ—FÖ'VÆÆWF–âÖ6öÂ×VW°¢föçB×6—¦S¢‚ãWB–×÷'FçC°¢Æ–æRÖ†V–v‡C¢ãƒ°¢v†—FR×76S¢æ÷&ÖÃ°Ð§ÐÐ¢æ—FÖ'VÆÆWF–â×VRFG²&6¶w&÷VæBÖ6öÆ÷#¢6SfSfSc²föçB×vV–v‡C£“²ÐÐ¢æ—FÖ'VÆÆWF–â×VR×G—W²ÆWGFW"×76–æs¢ãWC²ÐÐ¢æ—FÖ'VÆÆWF–â×VRFC¦f—'7BÖ6†–ÆBÀÐ¢æ—FÖ'VÆÆWF–â×VRFC¦Æ7BÖ6†–ÆG°Ð¢fW'F–6ÂÖÆ–vã¦Ö–FFÆS°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖÖ÷–VææRÖvVæW&ÆRÀÐ¢æ—FÖ'VÆÆWF–âÖæ÷FRÖ&6FWvW°Ð¢v–GFƒ£S°Ð¢Ö&v–âÖÆVgC¦WFó°Ð¢Ö&v–â×&–v‡C¦WFó°Ð§ÐÐ¢æ—FÖ'VÆÆWF–âÖÖ÷–VææRÖvVæW&ÆR²Ö&v–â×F÷¢²föçB×6—¦S¢ãGC²föçB×vV–v‡C¢“²6öÆ÷#¢63²Ð¢æ—FÖ'VÆÆWF–âÖæ÷FRÖ&6FWvR²Ö&v–â×F÷¢²föçB×6—¦S¢‚ã'C²föçB×vV–v‡C¢“²6öÆ÷#¢63²Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W7²&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S²Ö&v–â×F÷¢²föçB×6—¦S¢’ã‡C²Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W2FG²FF–ær×F÷¢GC²Ð¢ò¢•DÔöff–6–Âf—7VÂÆ–W"¢ð¢æ—FÖ'VÆÆWF–â×F÷–æfò×F&ÆW°¢v–GFƒ£S°¢Ö&v–â×F÷£C°¢Ö&v–âÖ&÷GFöÓ£7C°¢&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S°§Ð¢æ—FÖ'VÆÆWF–â×F÷–æfòÖÆVgBÀ¢æ—FÖ'VÆÆWF–â×F÷–æfò×&–v‡G°¢&÷&FW#£ãwB6öÆ–B6CvSVc#°¢&6¶w&÷VæBÖ6öÆ÷#¢6c†f&fc°¢FF–æs£"ãGBGC°¢FW‡BÖÆ–vã¦ÆVgC°§Ð¢æ—FÖ'VÆÆWF–â×F÷–æfò×&–v‡G°¢FW‡BÖÆ–vã§&–v‡C°§Ð¢æ—FÖ'VÆÆWF–âÖ–æfòÖÆ–æW°¢Ö&v–ã£C°¢6öÆ÷#¢3#3ƒVc°¢föçB×6—¦S£rã‡C°¢föçB×vV–v‡C£“°¢Æ–æRÖ†V–v‡C£ãc°§Ð¢æ—FÖ'VÆÆWF–â×F&ÆW°¢Ö&v–â×F÷£C°§Ð¢æ—FÖ'VÆÆWF–â×F&ÆRF‡°¢&6¶w&÷VæBÖ6öÆ÷#¢3#3ƒVc°¢6öÆ÷#¢6fffffc°¢&÷&FW#£ãwB6öÆ–B3#3ƒVc°¢FF–æs£"ãBãGC°¢föçB×6—¦S£bã‡C°¢Æ–æRÖ†V–v‡C£ã°¢v†—FR×76S¦æ÷w&°§Ð¢æ—FÖ'VÆÆWF–â×F&ÆRFG°¢&÷&FW#£ãwB6öÆ–B6#v3FCC°¢FF–æs£"ãWBã‡C°¢föçB×6—¦S£bã“WC°¢Æ–æRÖ†V–v‡C£ã#°¢fW'F–6ÂÖÆ–vã¦Ö–FFÆS°§Ð¢æ—FÖ'VÆÆWF–â×F&ÆRFƒ¦f—'7BÖ6†–ÆBÀ¢æ—FÖ'VÆÆWF–â×F&ÆRFC¦f—'7BÖ6†–ÆG°¢FF–ærÖÆVgC£WC°§Ð¢æ—FÖ'VÆÆWF–â×VRFG°¢&6¶w&÷VæBÖ6öÆ÷#¢6Vccƒ°¢6öÆ÷#¢3c&cSS°§Ð¢æ—FÖ'VÆÆWF–âÖ6öÂ×VW°¢föçB×6—¦S£rãWB–×÷'FçC°¢Æ–æRÖ†V–v‡C£ã3°§Ð¢æ—FÖ'VÆÆWF–âÖÖ÷–VææRÖvVæW&ÆW°¢v–GFƒ£S°¢Ö&v–â×F÷£'C°¢&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S°¢&÷&FW#£B6öÆ–B3#3ƒVc°¢&6¶w&÷VæBÖ6öÆ÷#¢6c†f&fc°§Ð¢æ—FÖ'VÆÆWF–âÖÖ÷–VææRÖvVæW&ÆRFG°¢FF–æs£"ã‡BWC°¢6öÆ÷#¢3#3ƒVc°¢föçB×6—¦S£ãgC°¢föçB×vV–v‡C£“°¢Æ–æRÖ†V–v‡C£ã°¢FW‡BÖÆ–vã¦6VçFW#°§Ð¢æ—FÖ'VÆÆWF–âÖæ÷FRÖ&6FWvW°¢Ö&v–â×F÷£'C°¢FF–æs£'BGC°¢&6¶w&÷VæBÖ6öÆ÷#¢6ffc†Sc°¢6öÆ÷#¢3v&SS°¢&÷&FW"ÖÆVgC£'B6öÆ–B6cV#S#°¢föçB×6—¦S£wC°¢föçB×vV–v‡C£ƒ°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öç°¢v–GFƒ£S°¢Ö&v–â×F÷£'C°¢&÷&FW#£°¢&6¶w&÷VæBÖ6öÆ÷#¢6fffffc°¢&÷&FW"Ö6öÆÆ6S¦6öÆÆ6S°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâÖ6÷—°¢FF–æs£B°¢6öÆ÷#¢3#3ƒVc°¢föçB×6—¦S£‚ãGC°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâÖ6÷’6ÖÆÂÀ¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâ×"6ÖÆÇ°¢6öÆ÷#¢3S3f#ƒ#°¢föçB×6—¦S£rã'C°§Ð¢æ—FÖ'VÆÆWF–â×fW&–f–6F–öâ×'°¢FF–æs£°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W7°¢Ö&v–â×F÷£'C°¢6öÆ÷#¢3#3ƒVc°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&W2FG°¢FF–ær×F÷£'C°§Ð¢æ—FÖ'VÆÆWF–â×6–væGW&RÖÆ–æW°¢F—7Æ“¦&Æö6³°¢†V–v‡C£'C°¢&÷&FW"×F÷£ãwB6öÆ–B3#3ƒVc°§Ð¤553°Ð Ð¢FgVÆÆ‡FÖÂÒsÇ7G–ÆSârâF772âsÂ÷7G–ÆSârâF‡FÖÃ°Ð¢GFbÓçw&—FT…DÔÂ‚FgVÆÆ‡FÖÂÂG'VRÂfÇ6RÂG'VRÂfÇ6RÂrr“°Ð¢6VÆc£¦G&uö–FVçF—G•öæE÷&6öFUöf÷%÷F7Fb‚GFbÂGW6W&–BÂG6VÖW7FW"ÂF–FVçF—G’ÂG&f–ÆRÂGfW&–g—W&Â“° Ð¢G'’°Ð¢GFbÓä÷WGWB‚Ff–ÆWF‚Âtbr“°Ð¢Ò6F6‚…ÅF‡&÷v&ÆRFR’°Ð¢&WGW&âfÇ6S°Ð¢ÐÐ Ð¢&WGW&âf–ÆUöW†—7G2‚Ff–ÆWF‚’bbf–ÆW6—¦R‚Ff–ÆWF‚’â°Ð¢ÐÐ Ð¢ò¢ Ð¢¢|:–ì:‡&RF÷W2ÆW2'VÆÆWF–ç2BwVæR6ö†÷'FRWBL:–Ì:–6†&vRVâ¤•àÐ¢¢F‡FÖÆ'V–ÆFW#¢fâ†–çBGW6W&–BÂ7G&–ærG6VÖW7FW"“¢7G&–ær'VÆÆWF–â‡FÖÀÐ¢¢ðÐ¢V&Æ–27FF–2gVæ7F–öâW‡÷'Eö6ö†÷'Eö'VÆÆWF–ç5÷¦—†–çBF6ö†÷'F–BÂ7G&–ærG6VÖW7FW"Â6ÆÆ&ÆRF‡FÖÆ'V–ÆFW"“¢fö–B°Ð¢vÆö&ÂD4ds°Ð Ð¢&WV—&Uööæ6R‚D4drÓæÆ–&F—"âröf–ÆVÆ–"ç‡r“°Ð Ð¢GW6W'2Ò6VÆc£¦vWEö6ö†÷'E÷W6W'2‚F6ö†÷'F–B“°Ð¢–b†V×G’‚GW6W'2’’°Ð¢F‡&÷ræWrÆÖööFÆUöW†6WF–öâ‚tV7Vâ:—GVF–çBG&÷Wl:’Fç26WGFR6ö†÷'FRâr“°Ð¢ÐÐ Ð¢F&6VF—"ÒÖ¶U÷FV×öF—&V7F÷'’‚vÆö6Åö—FÖ'VÆÆWF–âr“°Ð¢F&F6†–BÒv6ö†÷'EòrâF6ö†÷'F–Bâuòrâ&Vu÷&WÆ6R‚rõµäÕ¦×£Ó•ÂÕõÒòrÂuòrÂG6VÖW7FW"’âuòrâF–ÖR‚“°Ð¢Gv÷&¶F—"ÒF&6VF—"âD•$T5Dõ%•õ4U$Dõ"âF&F6†–C°Ð Ð¢–b‚—5öF—"‚Gv÷&¶F—"’’°Ð¢Ö¶F—"‚Gv÷&¶F—"ÂssÂG'VR“°Ð¢ÐÐ Ð¢Fö¶6÷VçBÒ°Ð¢f÷&V6‚‚GW6W'22GR’°Ð¢GW6W&–BÒ†–çB’GRÓæ–C°Ð Ð¢F‡FÖÂÒF‡FÖÆ'V–ÆFW"‚GW6W&–BÂG6VÖW7FW"“°Ð¢–b‚—5÷7G&–ær‚F‡FÖÂ’ÇÂG&–Ò‚F‡FÖÂ’ÓÓÒrr’°Ð¢6öçF–çVS°Ð¢ÐÐ Ð¢Ff–ÆVæÖRÒ6VÆc£¦'V–ÆEö'VÆÆWF–åöf–ÆVæÖR‚GW6W&–BÂG6VÖW7FW"“°Ð¢Ff–ÆWF‚ÒGv÷&¶F—"âD•$T5Dõ%•õ4U$Dõ"âFf–ÆVæÖS°Ð Ð¢–b‡6VÆc£¦vVæW&FU÷Feöf–ÆR‚GW6W&–BÂG6VÖW7FW"ÂF‡FÖÂÂFf–ÆWF‚’’°Ð¢Fö¶6÷VçB²³°Ð¢ÐÐ¢ÐÐ Ð¢–b‚Fö¶6÷VçBÓÓÒ’°Ð¢F‡&÷ræWrÆÖööFÆUöW†6WF–öâ‚tV7VâDb|:–ì:—,:’‡l:—&–f–RFöæì:–W2òæ÷FW2ò6<:‡2’âr“°Ð¢ÐÐ Ð¢G¦—æÖRÒv'VÆÆWF–ç5ö6ö†÷'FUòrâF6ö†÷'F–Bâuòrâ&Vu÷&WÆ6R‚rõµäÕ¦×£Ó•ÂÕõÒòrÂuòrÂG6VÖW7FW"’ârç¦—s°Ð¢G¦—F‚ÒF&6VF—"âD•$T5Dõ%•õ4U$Dõ"âG¦—æÖS°Ð Ð¢G¦—W"ÒæWrÇ¦—ö&6†—fR‚“°Ð¢–b‚G¦—W"Óæ÷Vâ‚G¦—F‚ÂÆf–ÆUö&6†—fS£¤5$TDR’ÓÒG'VR’°Ð¢F‡&÷ræWrÆÖööFÆUöW†6WF–öâ‚t–×÷76–&ÆRFR7,:–W"ÆR¤•âr“°Ð¢ÐÐ Ð¢Ff–ÆW2ÒvÆö"‚Gv÷&¶F—"âD•$T5Dõ%•õ4U$Dõ"âr¢çFbr“°Ð¢f÷&V6‚‚Ff–ÆW22Fb’°Ð¢G¦—W"ÓæFEöf–ÆUög&öÕ÷F†æÖR†&6VæÖR‚Fb’ÂFb“°Ð¢ÐÐ¢G¦—W"Óæ6Æ÷6R‚“°Ð Ð¢6VæE÷FV×öf–ÆR‚G¦—F‚ÂG¦—æÖR“°Ð Ð¢&VÖ÷fUöF—"‚Gv÷&¶F—"ÂG'VR“°Ð¢VæÆ–æ²‚G¦—F‚“°Ð¢W†—C°Ð¢ÐÐ Ð§ÐÐ 