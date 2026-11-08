<?php
// CSV parsing + card-set validation, shared by the admin importer and the CLI.
// Mirrors the data rules: ASCII only, UTF-8 no BOM, CRLF, exact headers,
// known effect codes (unknown codes fail loudly), rebuild_trust formula.

require_once __DIR__ . '/Engine.php';

final class Validate
{
    public const HEADERS = [
        'crises' => ['id','name','type','cost_an','cost_in','cost_gr','cost_rg','cost_co','cost_iv','trust','reward_extra','threat','devote_currency','fallout_code','fallout_text','back_name','rebuild_an','rebuild_in','rebuild_gr','rebuild_rg','rebuild_co','rebuild_iv','rebuild_trust','action_code','action_text','owner_code','owner_text'],
        'mandates' => ['id','name','compromised','condition_code','condition_text','bonus_per'],
        'fallen_heroes' => ['id','name','target','ongoing_code','ongoing_text','redeem_an','redeem_in','redeem_gr','redeem_rg','redeem_co','redeem_iv','trust'],
        'spin' => ['id','name','kind','effect_code','effect_text'],
        'locations' => ['id','name','group','spaces','action_code','action_text'],
    ];
    public const EXPECTED_ROWS = ['crises' => 48, 'mandates' => 9, 'fallen_heroes' => 6, 'spin' => 30, 'locations' => 11];
    public const FILE_TO_KIND = ['crises' => 'crisis', 'mandates' => 'mandate', 'fallen_heroes' => 'fallen', 'spin' => 'spin', 'locations' => 'location'];
    public const TYPES = ['Financial', 'Media', 'Community', 'Political', 'Public Safety', 'Conspiracy'];
    public const WEIGHTS = ['an' => 0.5, 'in' => 1, 'gr' => 1, 'rg' => 2, 'co' => 2, 'iv' => 2];

    private static ?string $opRegex = null;

    private static function opRegex(): string
    {
        if (self::$opRegex !== null) { return self::$opRegex; }
        $cur = 'AN|IN|GR|RG|CO|IV';
        $gi = "(?:(?:$cur)\\d+|SPIN\\d+|TRUST\\d+)";
        $ops = [
            "GAIN:$gi(?:,$gi)*",
            "TAX:(?:$cur):\\d+:(?:TRUST|VILLAIN)",
            "RICH:(?:$cur):\\d+",
            'TRUSTALL:\\d+', 'TRUSTLEAD:\\d+', 'VILLAIN:\\d+', 'FALLEN:\\d+',
            'CLOSE:(?:MEDIA|BUSINESS|NEIGHBORHOODS)',
            'DISRUPT:(?:MEDIA|CITYHALL|OWNER|SPIN|INCOME|COST)',
            'FIRSTPLAYER', 'REBUILD', 'TRADE:\\d+', 'PLAYSPIN', 'REASSIGN',
            'SCOUT:\\d+', 'RECALL:\\d+',
            "STEAL:(?:$cur):\\d+", "OPPLOSE:(?:$cur):\\d+", 'SHUTTER:COMMON',
        ];
        $alt = implode('|', $ops);
        self::$opRegex = "/^(?:$alt)(?:;(?:$alt))*$/";
        return self::$opRegex;
    }

    /** RFC-ish CSV parser for our minimal-quoting files. @return string[][] */
    public static function parseCsv(string $text): array
    {
        $rows = [];
        $row = [];
        $field = '';
        $q = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($q) {
                if ($c === '"') {
                    if ($i + 1 < $len && $text[$i + 1] === '"') { $field .= '"'; $i++; }
                    else { $q = false; }
                } else { $field .= $c; }
            } elseif ($c === '"') { $q = true; }
            elseif ($c === ',') { $row[] = $field; $field = ''; }
            elseif ($c === "\n") {
                $row[] = rtrim($field, "\r");
                $rows[] = $row;
                $row = [];
                $field = '';
            } else { $field .= $c; }
        }
        if ($field !== '' || $row !== []) { $row[] = $field; $rows[] = $row; }
        return array_values(array_filter($rows, fn($r) => implode('', $r) !== ''));
    }

    /**
     * Validate one file's raw bytes. Returns [rows(assoc)[], errors[], report[]].
     * @param string $file one of crises|mandates|fallen_heroes|spin|locations
     */
    public static function file(string $file, string $bytes, bool $enforceRowCount = true): array
    {
        $errors = [];
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) { $errors[] = "$file: UTF-8 BOM present"; $bytes = substr($bytes, 3); }
        if (preg_match('/[^\x00-\x7F]/', $bytes)) { $errors[] = "$file: non-ASCII bytes"; }
        if (substr_count($bytes, "\n") !== substr_count($bytes, "\r\n")) { $errors[] = "$file: bare LF line endings (CRLF required)"; }

        $raw = self::parseCsv($bytes);
        $want = self::HEADERS[$file];
        if (!$raw || $raw[0] !== $want) {
            $errors[] = "$file: header mismatch (expected " . implode(',', $want) . ')';
            return [[], $errors, []];
        }
        $rows = [];
        $ids = [];
        foreach (array_slice($raw, 1) as $n => $r) {
            if (count($r) !== count($want)) {
                $errors[] = "$file row " . ($n + 2) . ': ' . count($r) . ' cols, expected ' . count($want);
                continue;
            }
            $row = array_combine($want, $r);
            if (isset($ids[$row['id']])) { $errors[] = "$file: duplicate id {$row['id']}"; }
            $ids[$row['id']] = true;
            $rows[] = $row;
        }
        if ($enforceRowCount && count($rows) !== self::EXPECTED_ROWS[$file]) {
            $errors[] = "$file: " . count($rows) . ' rows, expected ' . self::EXPECTED_ROWS[$file];
        }

        foreach ($rows as $row) {
            foreach ($row as $col => $v) {
                if (str_ends_with($col, '_code') && $col !== 'condition_code' && $v !== '' && !preg_match(self::opRegex(), $v)) {
                    $errors[] = "$file {$row['id']}: unknown effect code in $col: $v";
                }
            }
            if ($file === 'crises') {
                if (!in_array($row['type'], self::TYPES, true)) {
                    $errors[] = "crises {$row['id']}: unknown type {$row['type']}";
                }
                if (!array_key_exists(trim($row['reward_extra']), Engine::REWARD_EXTRA) && trim($row['reward_extra']) !== '') {
                    $errors[] = "crises {$row['id']}: unknown reward_extra '{$row['reward_extra']}' (R13)";
                }
                $w = 0.0;
                foreach (self::WEIGHTS as $k => $wt) { $w += $wt * (int)$row["rebuild_$k"]; }
                $wantTrust = (int)ceil($w / 2);
                if ((int)$row['rebuild_trust'] !== $wantTrust) {
                    $errors[] = "crises {$row['id']}: rebuild_trust={$row['rebuild_trust']}, formula says $wantTrust (R15)";
                }
            }
            if ($file === 'spin' && !in_array($row['kind'], ['Attack', 'Utility', 'Sabotage'], true)) {
                $errors[] = "spin {$row['id']}: unknown kind {$row['kind']}";
            }
        }
        $report = ["$file: " . count($rows) . ' rows x ' . count($want) . ' cols'];
        return [$rows, $errors, $report];
    }

    /**
     * Load + validate every CSV in a directory (the repo data/ layout).
     * @return array{0:?CardSet,1:string[],2:string[]} cardset|null, errors, report
     */
    public static function loadCsvDir(string $dir): array
    {
        $allRows = [];
        $errors = [];
        $report = [];
        foreach (array_keys(self::HEADERS) as $file) {
            $path = "$dir/$file.csv";
            if (!is_file($path)) {
                $errors[] = "missing $file.csv";
                continue;
            }
            [$rows, $errs, $rep] = self::file($file, file_get_contents($path));
            array_push($errors, ...$errs);
            array_push($report, ...$rep);
            foreach ($rows as $row) {
                $allRows[] = ['kind' => self::FILE_TO_KIND[$file], 'card_id' => $row['id'], 'data' => $row];
            }
        }
        return [$errors ? null : CardSet::fromRows($allRows), $errors, $report];
    }
}
