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