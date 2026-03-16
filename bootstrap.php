<?php
// ==========================================
// 1. 환경 변수 로드 (.env 파일)
// ==========================================
function load_env($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
load_env(__DIR__ . '/.env');

// ==========================================
// 2. 글로벌 설정
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 로그 디렉토리가 없을 수 있으므로 생성
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
ini_set('error_log', $logDir . '/error.log');

// 세션 시작
session_start();

// ==========================================
// 3. MySQL 데이터베이스 연결 설정
// ==========================================
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_name = getenv('DB_NAME') ?: 'lucky_dungeon_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';

try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (\PDOException $e) {
    die("<h1>던전 입구(DB)가 무너졌습니다: " . $e->getMessage() . "</h1>");
}

try {
    if (table_exists($pdo, 'tb_explore_events')) {
        $expEventCount = (int)$pdo->query("SELECT COUNT(*) FROM tb_explore_events WHERE event_type = 'exp'")->fetchColumn();
        if ($expEventCount === 0) {
            $pdo->beginTransaction();
            $expSeeds = array(
                'Ancient fragments sharpen your battle senses.',
                'A dead tactician leaves behind a surviving lesson.',
                'Broken manuals whisper a path to survive.',
                'A bloodstained altar grants grim insight.',
                'Forgotten memories awaken in the dark.'
            );
            $insertExp = $pdo->prepare("INSERT INTO tb_explore_events (event_code, event_type, event_title, ai_seed, weight, min_floor, max_floor, is_enabled) VALUES (?, 'exp', ?, ?, ?, ?, ?, 1)");
            for ($i = 1; $i <= 16; $i++) {
                $title = 'Memory Fragment #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
                $seed = $expSeeds[$i % count($expSeeds)];
                $insertExp->execute(array('EXP_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), $title, $seed, 8, 1, 9999));
            }
            $pdo->commit();
        }
    }
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("EXP event migration failed: " . $e->getMessage());
}

// ==========================================
// 마이그레이션 작업: 자동 탐험 및 토벌대 컬럼 추가
// ==========================================
function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function parse_sql_row_tokens(string $row): array {
    $tokens = [];
    $buf = '';
    $inString = false;
    $len = strlen($row);

    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];

        if ($ch === "'") {
            if ($inString) {
                // SQL escaped quote: ''
                if ($i + 1 < $len && $row[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }

                // 문자열 종료로 볼지 판단: 다음 유의미 문자가 ',' 또는 ')'인 경우에만 종료
                $j = $i + 1;
                while ($j < $len && ctype_space($row[$j])) {
                    $j++;
                }
                if ($j >= $len || $row[$j] === ',' || $row[$j] === ')') {
                    $inString = false;
                    $buf .= $ch;
                    continue;
                }

                // 문자열 내부 인용부호
                $buf .= $ch;
                continue;
            }

            $inString = true;
            $buf .= $ch;
            continue;
        }

        if ($ch === ',' && !$inString) {
            $tokens[] = trim($buf);
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }

    if (trim($buf) !== '') {
        $tokens[] = trim($buf);
    }

    return $tokens;
}

function sql_literal_to_php_value(string $token) {
    $t = trim($token);
    if ($t === '') return '';
    if (strcasecmp($t, 'NULL') === 0) return null;

    if (strlen($t) >= 2 && $t[0] === "'" && substr($t, -1) === "'") {
        $inner = substr($t, 1, -1);
        $inner = str_replace("''", "'", $inner);
        return $inner;
    }

    if (is_numeric($t)) {
        return (strpos($t, '.') !== false) ? (float)$t : (int)$t;
    }

    return $t;
}

try {
    // 자동 탐험 컬럼 보강 (MySQL 5.6 호환)
    if (!column_exists($pdo, 'tb_commanders', 'narrative_tone')) {
        $pdo->exec("ALTER TABLE `tb_commanders` ADD COLUMN `narrative_tone` VARCHAR(30) NOT NULL DEFAULT '다크 판타지 톤' COMMENT '내레이션 톤'");
    }
    if (!column_exists($pdo, 'tb_commanders', 'auto_explore_start_time')) {
        $pdo->exec("ALTER TABLE `tb_commanders` ADD COLUMN `auto_explore_start_time` DATETIME NULL COMMENT '자동 탐험 시작 시각'");
    }
    if (!column_exists($pdo, 'tb_commanders', 'auto_explore_rewards')) {
        $pdo->exec("ALTER TABLE `tb_commanders` ADD COLUMN `auto_explore_rewards` TEXT NULL COMMENT '자동 탐험 보상'");
    }
    if (!column_exists($pdo, 'tb_commanders', 'intro_story_seen')) {
        $pdo->exec("ALTER TABLE `tb_commanders` ADD COLUMN `intro_story_seen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '시작 서사 연출 확인 여부'");
    }

    // 토벌대 메인 테이블
    if (!table_exists($pdo, 'tb_expeditions')) {
        $pdo->exec("CREATE TABLE `tb_expeditions` (
            `expedition_id` INT AUTO_INCREMENT PRIMARY KEY,
            `uid` INT NOT NULL,
            `start_time` DATETIME NOT NULL,
            `duration` INT NOT NULL COMMENT '파견 시간 (시간 단위)',
            `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
            INDEX `uid_idx` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '영웅 토벌대 파견 정보';");
    } elseif (!column_exists($pdo, 'tb_expeditions', 'is_completed')) {
        if (column_exists($pdo, 'tb_expeditions', 'claimed')) {
            $pdo->exec("ALTER TABLE `tb_expeditions` CHANGE COLUMN `claimed` `is_completed` TINYINT(1) NOT NULL DEFAULT 0");
        } else {
            $pdo->exec("ALTER TABLE `tb_expeditions` ADD COLUMN `is_completed` TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    // 토벌대 영웅 테이블
    if (!table_exists($pdo, 'tb_expedition_heroes')) {
        $pdo->exec("CREATE TABLE `tb_expedition_heroes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `expedition_id` INT NOT NULL,
            `inv_id` INT NOT NULL,
            `quantity` INT NOT NULL DEFAULT 1,
            INDEX `expedition_id_idx` (`expedition_id`),
            INDEX `inv_id_idx` (`inv_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '토벌대에 파견된 영웅 목록';");
    } else {
        if (!column_exists($pdo, 'tb_expedition_heroes', 'inv_id') && column_exists($pdo, 'tb_expedition_heroes', 'hero_inv_id')) {
            $pdo->exec("ALTER TABLE `tb_expedition_heroes` CHANGE COLUMN `hero_inv_id` `inv_id` INT NOT NULL");
        } elseif (!column_exists($pdo, 'tb_expedition_heroes', 'inv_id')) {
            $pdo->exec("ALTER TABLE `tb_expedition_heroes` ADD COLUMN `inv_id` INT NOT NULL AFTER `expedition_id`");
        }
        if (!column_exists($pdo, 'tb_expedition_heroes', 'quantity')) {
            $pdo->exec("ALTER TABLE `tb_expedition_heroes` ADD COLUMN `quantity` INT NOT NULL DEFAULT 1 AFTER `inv_id`");
        }
        if (!index_exists($pdo, 'tb_expedition_heroes', 'expedition_id_idx')) {
            $pdo->exec("ALTER TABLE `tb_expedition_heroes` ADD INDEX `expedition_id_idx` (`expedition_id`)");
        }
        if (!index_exists($pdo, 'tb_expedition_heroes', 'inv_id_idx')) {
            $pdo->exec("ALTER TABLE `tb_expedition_heroes` ADD INDEX `inv_id_idx` (`inv_id`)");
        }
    }

} catch (\PDOException $e) {
    error_log("스키마 마이그레이션 실패: " . $e->getMessage());
}

// ==========================================
// 4. 하이브리드 AI 라우팅 설정 (Ollama & Gemini)
// ==========================================
define('OLLAMA_URL', getenv('OLLAMA_URL'));
define('OLLAMA_MODEL', getenv('OLLAMA_MODEL'));
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY'));

// Gemini API URL
$gemini_key = getenv('GEMINI_API_KEY');
$gemini_model = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash-lite';
define('GEMINI_API_URL', "https://generativelanguage.googleapis.com/v1beta/models/{$gemini_model}:generateContent?key={$gemini_key}");

// ==========================================
// 5. 게임 데이터 로드
// ==========================================
$hero_data = require_once __DIR__ . '/config/hero_data.php';

// 파서 오작동으로 스킬 문장이 영웅 키로 들어간 오염 데이터 제거
if (is_array($hero_data)) {
    foreach (array_keys($hero_data) as $hero_name_key) {
        if (strpos($hero_name_key, '[') === 0 || !isset($hero_data[$hero_name_key]['rank']) || !isset($hero_data[$hero_name_key]['skills'])) {
            unset($hero_data[$hero_name_key]);
        }
    }
}

// ==========================================
// 추가 마이그레이션 (영웅 스킬 시스템)
// ==========================================
try {
    if (!column_exists($pdo, 'tb_heroes', 'is_on_expedition')) {
        $pdo->exec("ALTER TABLE `tb_heroes` ADD COLUMN `is_on_expedition` BOOLEAN NOT NULL DEFAULT 0 COMMENT '파견 참여 여부'");
    }
    if (!column_exists($pdo, 'tb_heroes', 'level')) {
        $pdo->exec("ALTER TABLE `tb_heroes` ADD COLUMN `level` INT NOT NULL DEFAULT 1 COMMENT '영웅 레벨'");
    }
    if (!column_exists($pdo, 'tb_heroes', 'skills_json')) {
        $pdo->exec("ALTER TABLE `tb_heroes` ADD COLUMN `skills_json` TEXT NULL COMMENT '영웅 스킬 정보 (레벨 등)'");
    }
} catch (\PDOException $e) {
    error_log("영웅 스킬 시스템 마이그레이션 실패: " . $e->getMessage());
}

// ==========================================
// 추가 마이그레이션 (유물/룬 시스템 MVP)
// ==========================================
try {
    if (!table_exists($pdo, 'tb_relics')) {
        $pdo->exec("CREATE TABLE `tb_relics` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `uid` INT NOT NULL,
            `relic_level` INT NOT NULL DEFAULT 1,
            `atk_bonus_percent` INT NOT NULL DEFAULT 0,
            `drop_bonus_percent` INT NOT NULL DEFAULT 0,
            UNIQUE KEY `uid_unique` (`uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '사령관 유물 강화 정보';");
    }
} catch (\PDOException $e) {
    error_log("유물 시스템 마이그레이션 실패: " . $e->getMessage());
}

// ==========================================
// 추가 마이그레이션 (탐색 이벤트 카탈로그)
// ==========================================
try {
    if (!table_exists($pdo, 'tb_explore_events')) {
        $pdo->exec("CREATE TABLE `tb_explore_events` (
            `event_id` INT AUTO_INCREMENT PRIMARY KEY,
            `event_code` VARCHAR(60) NOT NULL,
            `event_type` VARCHAR(30) NOT NULL,
            `event_title` VARCHAR(120) NOT NULL,
            `ai_seed` TEXT NULL,
            `weight` INT NOT NULL DEFAULT 10,
            `min_floor` INT NOT NULL DEFAULT 1,
            `max_floor` INT NOT NULL DEFAULT 9999,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_event_code` (`event_code`),
            KEY `idx_event_lookup` (`is_enabled`, `min_floor`, `max_floor`, `event_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '탐색 이벤트 카탈로그';");
    }

    $eventCount = (int)$pdo->query("SELECT COUNT(*) FROM tb_explore_events")->fetchColumn();
    if ($eventCount < 200) {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM tb_explore_events");

        $encounterSeeds = array(
            '칼날 같은 살기가 통로를 가르며 짙게 번진다.',
            '어둠 속 발소리가 겹쳐 울리며 접근한다.',
            '금속성 숨소리가 벽을 타고 스며든다.',
            '핏빛 기운이 천천히 시야를 잠식한다.',
            '잔해 사이에서 적의 눈빛이 번쩍인다.',
            '정적이 깨지며 포효가 복도를 울린다.',
            '발밑의 그림자가 갑자기 길게 늘어진다.',
            '낮은 울음과 함께 괴이한 형체가 드러난다.'
        );
        $goldSeeds = array(
            '깨진 석판 틈에서 금빛이 번쩍인다.',
            '낡은 주머니가 바닥에서 굴러 나온다.',
            '무너진 벽돌 뒤편에 숨은 전리품을 찾았다.',
            '먼지 속에서 반짝이는 동전 더미가 드러난다.',
            '누군가 놓고 간 보급 상자가 발견되었다.'
        );
        $chestSeeds = array(
            '봉인 문양이 새겨진 상자가 덜컥 열린다.',
            '녹슨 자물쇠를 부수자 묵직한 상자가 모습을 드러낸다.',
            '함정 해제 후 보물 상자 내부가 드러난다.',
            '짙은 먼지를 털어내니 고대 상자가 열렸다.',
            '쇳조각 더미 밑에서 잠긴 상자를 찾아냈다.'
        );
        $trapSeeds = array(
            '바닥 문양이 붉게 빛나며 함정이 작동한다.',
            '천장 톱니가 내려오며 경고음을 낸다.',
            '독침 장치가 벽면에서 튀어나온다.',
            '붕괴 경보와 함께 발밑 지반이 흔들린다.',
            '숨겨진 마법진이 발목을 붙잡는다.'
        );
        $manaSeeds = array(
            '푸른 샘물이 은은한 빛을 내며 흐른다.',
            '마나 입자가 공기 중에서 서서히 응집된다.',
            '고요한 샘에서 신비한 파동이 번진다.',
            '균열 틈에서 맑은 마력이 솟아오른다.',
            '짙은 안개가 걷히며 마나의 샘이 모습을 드러낸다.'
        );

        $insert = $pdo->prepare("INSERT INTO tb_explore_events (event_code, event_type, event_title, ai_seed, weight, min_floor, max_floor, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");

        // 총 200개: 전투 120(60%), 골드 28, 상자 22, 함정 18, 마나의샘 12
        for ($i = 1; $i <= 120; $i++) {
            $title = '적 조우 #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $seed = $encounterSeeds[$i % count($encounterSeeds)] . ' 전투 태세를 갖춰라.';
            $insert->execute(array('ENCOUNTER_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'encounter', $title, $seed, 10, 1, 9999));
        }
        for ($i = 1; $i <= 28; $i++) {
            $title = '골드 발견 #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $seed = $goldSeeds[$i % count($goldSeeds)];
            $insert->execute(array('GOLD_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'gold', $title, $seed, 10, 1, 9999));
        }
        for ($i = 1; $i <= 22; $i++) {
            $title = '상자 발견 #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $seed = $chestSeeds[$i % count($chestSeeds)];
            $insert->execute(array('CHEST_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'chest', $title, $seed, 10, 1, 9999));
        }
        for ($i = 1; $i <= 18; $i++) {
            $title = '함정 작동 #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $seed = $trapSeeds[$i % count($trapSeeds)];
            $insert->execute(array('TRAP_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'trap', $title, $seed, 10, 1, 9999));
        }
        for ($i = 1; $i <= 12; $i++) {
            $title = '마나의 샘 #' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            $seed = $manaSeeds[$i % count($manaSeeds)];
            $insert->execute(array('MANA_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT), 'mana_spring', $title, $seed, 10, 1, 9999));
        }

        $pdo->commit();
    }
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("탐색 이벤트 카탈로그 마이그레이션 실패: " . $e->getMessage());
}

// ==========================================
// 추가 마이그레이션 (tb_explore_events 파일 데이터 반영)
// ==========================================
try {
    if (table_exists($pdo, 'tb_explore_events')) {
        $seedFile = __DIR__ . '/tb_explore_events';
        $hasSeedCode = (int)$pdo->query("SELECT COUNT(*) FROM tb_explore_events WHERE event_code IN ('ENCOUNTER_001', 'ENC_B1_001')")->fetchColumn();

        if (is_file($seedFile) && $hasSeedCode === 0) {
            $lines = file($seedFile, FILE_IGNORE_NEW_LINES);
            if ($lines !== false) {
                $activeColumns = array();
                $insertedRows = 0;

                $ins = $pdo->prepare("INSERT IGNORE INTO tb_explore_events (event_code, event_type, event_title, ai_seed, weight, min_floor, max_floor, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

                $pdo->beginTransaction();

                foreach ($lines as $line) {
                    $trim = trim((string)$line);
                    if ($trim === '' || strpos($trim, '--') === 0) {
                        continue;
                    }

                    if (preg_match('/^INSERT\\s+(?:IGNORE\\s+)?INTO\\s+`tb_explore_events`\\s*\\(([^)]+)\\)\\s*VALUES\\s*$/iu', $trim, $m)) {
                        $activeColumns = array();
                        $cols = explode(',', $m[1]);
                        foreach ($cols as $c) {
                            $activeColumns[] = trim(str_replace('`', '', $c));
                        }
                        continue;
                    }

                    if (empty($activeColumns) || $trim[0] !== '(') {
                        continue;
                    }

                    $rowExpr = rtrim($trim, ",;");
                    if (strlen($rowExpr) < 2 || $rowExpr[0] !== '(' || substr($rowExpr, -1) !== ')') {
                        continue;
                    }

                    $inner = substr($rowExpr, 1, -1);
                    $tokens = parse_sql_row_tokens($inner);
                    if (count($tokens) !== count($activeColumns)) {
                        continue;
                    }

                    $row = array();
                    for ($i = 0; $i < count($activeColumns); $i++) {
                        $row[$activeColumns[$i]] = sql_literal_to_php_value($tokens[$i]);
                    }

                    $eventCode = trim((string)($row['event_code'] ?? ''));
                    if ($eventCode === '') {
                        continue;
                    }

                    $eventType = trim((string)($row['event_type'] ?? 'encounter'));
                    $eventTitle = trim((string)($row['event_title'] ?? '알 수 없는 사건'));
                    $aiSeed = isset($row['ai_seed']) ? (string)$row['ai_seed'] : '';
                    $weight = max(1, (int)($row['weight'] ?? 10));
                    $minFloor = max(1, (int)($row['min_floor'] ?? 1));
                    $maxFloor = max($minFloor, (int)($row['max_floor'] ?? 9999));
                    $isEnabled = ((int)($row['is_enabled'] ?? 1) === 1) ? 1 : 0;

                    $ins->execute(array($eventCode, $eventType, $eventTitle, $aiSeed, $weight, $minFloor, $maxFloor, $isEnabled));
                    $insertedRows += (int)$ins->rowCount();
                }

                $pdo->commit();
                if ($insertedRows > 0) {
                    error_log("tb_explore_events import: {$insertedRows} rows inserted from seed file.");
                }
            }
        }
    }
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("tb_explore_events 파일 반영 실패: " . $e->getMessage());
}

// ==========================================
// 추가 마이그레이션 (탐색 이벤트 2000개 확장)
// ==========================================
try {
    if (table_exists($pdo, 'tb_explore_events')) {
        $targetEventTotal = 2000;
        $currentTotal = (int)$pdo->query("SELECT COUNT(*) FROM tb_explore_events")->fetchColumn();

        if ($currentTotal < $targetEventTotal) {
            $need = $targetEventTotal - $currentTotal;
            $pdo->beginTransaction();

            $insert = $pdo->prepare("INSERT INTO tb_explore_events (event_code, event_type, event_title, ai_seed, weight, min_floor, max_floor, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");

            // 1500개의 고유 상황을 동적 생성하기 위한 다크 판타지 조합 템플릿
            $templates = array(
                'encounter' => array(
                    'weight' => 10, 'titles' => array('적 조우', '위험한 조우', '기습 공격', '마수 출현', '불길한 그림자'),
                    'parts' => array(
                        array("어둠이 짙게 깔린 복도에서", "무너진 고대 제단 너머로", "피비린내가 진동하는 방 안에서", "축축한 이끼가 낀 통로에서", "거미줄이 빽빽하게 얽힌 곳에서", "시야가 닿지 않는 어둠 속에서"),
                        array("이성을 잃고 광기에 찬", "피에 굶주려 눈이 붉어진", "마력에 오염되어 변이된", "기괴한 악취를 풍기는", "차갑고 서늘한 기운을 내뿜는", "독기를 품고 쉿쉿거리는"),
                        array("오크 전사가", "고블린 무리가", "스켈레톤 병사들이", "다이어 울프가", "거대 독거미가", "타락한 수도사가"),
                        array("포효하며 무기를 휘두르고 달려든다.", "소리 없이 다가와 목숨을 노린다.", "독가스를 뿜어내며 숨통을 조여온다.", "천장에서 쏟아져 내리며 기습을 가한다.", "마법진을 그리며 파괴적인 주문을 외운다.", "순식간에 거리를 좁히며 연격을 퍼붓는다.")
                    )
                ),
                'gold' => array(
                    'weight' => 4, 'titles' => array('숨겨진 재화', '잊혀진 보물', '뜻밖의 수확', '전리품', '반짝이는 금화'),
                    'parts' => array(
                        array("바닥에 굴러다니는 해골의 품에서", "부서진 나무 궤짝의 잔해 속에서", "벽면의 이질적인 틈새를 파헤치자", "죽은 몬스터의 끈적한 위장을 가르자", "오래된 제단의 잿더미 아래에서", "물이 마른 분수대의 바닥에서"),
                        array("영롱하게 빛나는", "세월의 흔적이 느껴지는", "피 묻은 자국이 선명한", "순도가 매우 높은", "마력이 은은하게 깃든", "정교하게 세공된"),
                        array("금화 꾸러미를", "희귀한 보석을", "황금 목걸이를", "값비싼 은괴를", "루비 조각을", "고대의 주화를"),
                        array("발견하고 기쁜 마음으로 챙겼다.", "조심스럽게 꺼내어 가방에 넣었다.", "단검으로 간신히 파내어 획득했다.", "운 좋게 손에 넣어 전리품으로 삼았다.", "먼지를 툭툭 털어내고 품에 갈무리했다.", "미소 지으며 가치를 가늠해 보았다.")
                    )
                ),
                'chest' => array(
                    'weight' => 4, 'titles' => array('오래된 상자', '비밀의 궤짝', '굳게 잠긴 함', '마법의 상자', '전리품 궤짝'),
                    'parts' => array(
                        array("비밀스러운 기믹을 해제한 공간에서", "맹독 구덩이 한가운데 놓인 작은 섬에서", "수백 년은 닫혀 있던 듯한 돌문 너머에서", "천장에 아슬아슬하게 매달린 쇠사슬 끝에서", "환영 마법이 걷힌 진짜 벽 뒤에서", "가시 덩굴이 빽빽하게 얽힌 곳에서"),
                        array("마법의 룬으로 겹겹이 봉인된", "강철 띠로 단단하게 덧대어진", "불길한 붉은빛을 뿜어내는", "투명한 얼음 수정으로 만들어진", "해골 장식이 끔찍하게 박혀 있는", "복잡한 톱니바퀴 자물쇠가 달린"),
                        array("보물 상자를", "육중한 궤짝을", "성물함을", "고대의 금고를", "비밀 보관함을", "장비 상자를"),
                        array("발견하고 자물쇠를 부숴 안의 내용물을 챙겼다.", "조심스레 함정을 해제한 뒤 뚜껑을 열어젖혔다.", "마력을 주입해 봉인을 풀고 찬란한 보상을 얻었다.", "강제로 뜯어내자 진귀한 유물들이 모습을 드러냈다.", "어렵게 기믹을 풀어내고 두근거리며 상자를 열었다.", "숨을 죽이고 뚜껑을 열어 숨겨진 마력을 취했다.")
                    )
                ),
                'trap' => array(
                    'weight' => 3, 'titles' => array('치명적인 함정', '숨겨진 덫', '저주의 마법진', '기계 장치 함정', '죽음의 위기'),
                    'parts' => array(
                        array("안전해 보이는 대리석 바닥을 밟는 순간", "화려한 문고리를 잡아당기는 찰나", "무심코 벽면의 조각상을 건드렸을 때", "좁은 통로의 중앙을 지나는 순간", "보물 상자의 자물쇠에 열쇠를 꽂자마자", "천장에서 미세한 모래가 떨어지는 순간"),
                        array("맹독을 머금고 시퍼렇게 벼려진", "수십 톤의 무게를 가진 육중한", "눈에 보이지 않는 마법의", "피할 틈도 없이 날아오는 날카로운", "모든 것을 녹여버릴 듯한 산성의", "정신을 갉아먹는 저주의"),
                        array("독화살 뭉치가", "거대한 가시 철퇴가", "벼락의 룬이", "바닥의 쇠창살이", "독가스 구름이", "낙석 더미가"),
                        array("사방에서 쏟아져 치명적인 상처를 입었다.", "덮쳐와 뼈가 부러지는 듯한 충격을 받았다.", "작동하여 전신이 타들어가는 화상을 입었다.", "살갗을 찢고 지나가 심한 출혈을 일으켰다.", "호흡기를 마비시키며 쓰러질 뻔한 위기를 겪었다.", "퇴로를 막고 짓눌러 엄청난 내상을 입혔다.")
                    )
                ),
                'mana_spring' => array(
                    'weight' => 3, 'titles' => array('마력의 샘', '에테르의 흐름', '명상의 시간', '마나 폭주', '마법사의 성소'),
                    'parts' => array(
                        array("허공에 별가루 같은 마나 입자가 떠다니는 방에서", "심연의 바다처럼 짙은 에테르가 고인 웅덩이에서", "독기와 악취가 완전히 차단된 신성한 구역에서", "기적처럼 한 줄기 빛이 내려오는 부서진 틈새에서", "잔잔한 물소리가 심신을 안정시키는 옹달샘에서", "시간과 공간이 비틀린 듯한 고요한 차원의 틈에서"),
                        array("불순물이 하나도 섞이지 않은 순수한", "정신을 아득하게 만들 정도로 폭발적인", "수정처럼 맑고 깨끗한", "신비로운 빛을 은은하게 발산하는", "성스러운 축복이 깊게 깃든", "부드럽고 따뜻하게 영혼을 감싸는"),
                        array("마나의 샘물을 두 손으로 퍼 마시자", "에테르 결정체에 손바닥을 밀착시키자", "치유의 샘물을 마시자", "약초의 잎사귀를 으깨어 바르자", "정화의 마법석을 가슴에 품자", "부유하는 마력 덩어리를 숨 깊이 들이켜자"),
                        array("텅 비어 메말랐던 기해에 마력이 가득 찼다.", "흐트러졌던 정신력이 수정처럼 맑게 정돈되었다.", "타는 듯한 상처의 통증이 거짓말처럼 사라졌다.", "잃었던 생기와 활력이 벼락처럼 다시 차올랐다.", "찢어진 근육과 피부가 눈에 띄게 재생되었다.", "피로로 끊어질 듯했던 마법 회로가 완벽히 복구되었다.")
                    )
                ),
                'flavor' => array(
                    'weight' => 3, 'titles' => array('불길한 예감', '기괴한 현상', '스산한 분위기', '환청과 환각', '공포의 메아리'),
                    'parts' => array(
                        array("내 심장 박동 소리만이 유일한 소음인 텅 빈 복도에서", "횃불이 기분 나쁜 푸른색으로 일렁이는 구석에서", "방금 지나온 방의 문이 쾅 하고 스스로 닫힌 직후", "바람이 불지 않는데도 낡은 샹들리에가 흔들리는 곳에서", "등 뒤의 어둠이 살아있는 생물처럼 끈적하게 느껴질 때"),
                        array("이유를 알 수 없는 서늘한", "고막을 기분 나쁘게 긁어대는", "어린아이의 것인지 노인의 것인지 모를", "내 발소리와 정확히 1초 늦게 겹치는", "보이지 않는 무언가가 벽을 긁는 듯한"),
                        array("한기가 등골을 타고 훑어 내려갔다.", "기괴한 웃음소리가 사방에서 메아리쳤다.", "정체불명의 발자국이 허공에 찍히며 다가왔다.", "소름 돋는 시선이 느껴져 황급히 뒤를 돌아보았다.", "환청이 머릿속을 파고들어 이성을 흔들었다."),
                        array("", " 그리고 식은땀이 흘렀다.", " 반사적으로 무기를 꽉 쥐었다.", " 두려움에 걸음을 재촉했다.", " 나도 모르게 뒷걸음질을 쳤다.")
                    )
                )
            );

            $usedSeeds = array();

            for ($i = 1; $i <= $need; $i++) {
                $seq = $currentTotal + $i;
                
                // 가중치에 따른 타입 선택
                $totalWeight = 0;
                foreach ($templates as $data) { $totalWeight += $data['weight']; }
                $randWeight = mt_rand(1, $totalWeight);
                $currentWeight = 0;
                $chosenType = 'encounter';
                foreach ($templates as $type => $data) {
                    $currentWeight += $data['weight'];
                    if ($randWeight <= $currentWeight) {
                        $chosenType = $type;
                        break;
                    }
                }

                $tData = $templates[$chosenType];
                $title = $tData['titles'][array_rand($tData['titles'])] . ' #' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                
                // 겹치지 않는 시드 생성
                $seed = '';
                $attempts = 0;
                do {
                    $p1 = $tData['parts'][0][array_rand($tData['parts'][0])];
                    $p2 = $tData['parts'][1][array_rand($tData['parts'][1])];
                    $p3 = $tData['parts'][2][array_rand($tData['parts'][2])];
                    $p4 = $tData['parts'][3][array_rand($tData['parts'][3])];
                    $seed = trim("$p1 $p2 $p3 $p4");
                    $attempts++;
                } while (isset($usedSeeds[$seed]) && $attempts < 50);
                
                $usedSeeds[$seed] = true;
                $eventCode = strtoupper($chosenType) . '_GEN_' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);

                $insert->execute(array($eventCode, $chosenType, $title, $seed, $tData['weight'], 1, 9999));
            }

            $pdo->commit();
        }
    }
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("탐색 이벤트 2000개 확장 마이그레이션 실패: " . $e->getMessage());
}

// ==========================================
// 추가 마이그레이션 (스토리 테이블/시드 반영)
// ==========================================
try {
    if (!table_exists($pdo, 'tb_stories')) {
        $pdo->exec("CREATE TABLE `tb_stories` (
            `story_id` INT AUTO_INCREMENT PRIMARY KEY,
            `floor_number` INT NOT NULL COMMENT '스토리가 등장하는 층',
            `story_title` VARCHAR(255) NOT NULL COMMENT '스토리 제목',
            `story_content` TEXT NOT NULL COMMENT '스토리 내용',
            `reward_gold` INT NOT NULL DEFAULT 0 COMMENT '보상 골드',
            `reward_exp` INT NOT NULL DEFAULT 0 COMMENT '보상 경험치',
            UNIQUE KEY `floor_number_unique` (`floor_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '층별 스토리 데이터';");
    } elseif (!index_exists($pdo, 'tb_stories', 'floor_number_unique')) {
        $pdo->exec("ALTER TABLE `tb_stories` ADD UNIQUE KEY `floor_number_unique` (`floor_number`)");
    }

    if (!table_exists($pdo, 'tb_user_stories')) {
        $pdo->exec("CREATE TABLE `tb_user_stories` (
            `uid` VARCHAR(255) NOT NULL COMMENT '유저 ID',
            `story_id` INT NOT NULL COMMENT '본 스토리 ID',
            `seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`uid`(191), `story_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '유저별 스토리 확인 기록';");
    }

    $stories = array(
        array(
            'floor' => 3,
            'title' => '피로 물든 표식',
            'content' => '벽면 가득 새겨진 오래된 경고문은 단 하나의 문장만을 남기고 있다. \"돌아갈 길은 이미 닫혔다.\" 당신은 검 손잡이를 더욱 강하게 움켜쥔 채, 한 층 더 아래로 발을 내딛는다.',
            'gold' => 120,
            'exp' => 180
        ),
        array(
            'floor' => 7,
            'title' => '사라진 토벌대의 기록',
            'content' => '무너진 야영지 잔해에서 발견한 일지에는 마지막 문장이 떨리는 필체로 남아 있다. \"놈들은 어둠이 아니라 침묵 속에서 온다.\" 당신은 남겨진 지도를 접어 품고, 더 깊은 미궁으로 향한다.',
            'gold' => 220,
            'exp' => 320
        ),
        array(
            'floor' => 12,
            'title' => '균열 아래의 제단',
            'content' => '금이 간 제단 중심부에서 미약한 마력이 맥박처럼 뛰고 있다. 손을 얹는 순간, 오래된 전투의 환영이 스쳐 지나가며 살아남는 법을 각인시킨다.',
            'gold' => 340,
            'exp' => 520
        ),
        array(
            'floor' => 18,
            'title' => '쇠락한 왕의 유언',
            'content' => '왕좌에 앉은 채 부서진 해골은 마지막 순간까지 검을 놓지 않았다. 그가 남긴 유언은 단순하다. \"탐욕을 버린 자만이 이 문을 연다.\"',
            'gold' => 500,
            'exp' => 760
        ),
        array(
            'floor' => 25,
            'title' => '심연의 문턱',
            'content' => '이곳부터는 미궁이 아니라 의지를 시험하는 심연이다. 멀리서 들려오는 저주 섞인 속삭임 속에서도, 당신의 발걸음은 멈추지 않는다.',
            'gold' => 780,
            'exp' => 1100
        )
    );

    $upsertStory = $pdo->prepare("INSERT INTO tb_stories (floor_number, story_title, story_content, reward_gold, reward_exp) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE story_title = VALUES(story_title), story_content = VALUES(story_content), reward_gold = VALUES(reward_gold), reward_exp = VALUES(reward_exp)");
    foreach ($stories as $s) {
        $upsertStory->execute(array((int)$s['floor'], (string)$s['title'], (string)$s['content'], (int)$s['gold'], (int)$s['exp']));
    }
} catch (\PDOException $e) {
    error_log("스토리 시드 반영 실패: " . $e->getMessage());
}