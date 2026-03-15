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