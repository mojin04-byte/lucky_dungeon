<?php
// character_create.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // 프로덕션 환경에서는 반드시 0으로 설정

require_once 'bootstrap.php';

function get_random_base_rolls() {
    return array(
        'str' => rand(1, 20),
        'mag' => rand(1, 20),
        'agi' => rand(1, 20),
        'luk' => rand(1, 20),
        'men' => rand(1, 20),
        'vit' => rand(1, 20),
    );
}

function distribute_random_bonus_stats_local(&$stats, $bonus_points) {
    $pool = max(0, (int)$bonus_points);
    if ($pool <= 0 || !is_array($stats)) return;
    $keys = array('str', 'mag', 'agi', 'luk', 'men', 'vit');
    for ($i = 0; $i < $pool; $i++) {
        $target = $keys[array_rand($keys)];
        $stats[$target] = (int)(isset($stats[$target]) ? $stats[$target] : 0) + 1;
    }
}

function get_starting_hp_from_vit($vit) {
    return 100 + (max(0, (int)$vit) * 10);
}

function get_starting_mp_from_mag($mag) {
    return 50 + (max(0, (int)$mag) * 5);
}

$reincarnation_mode = false;
$reincarnation_pending = null;
$nickname = '';
$error_msg = '';

if (isset($_SESSION['reincarnation_pending']) && is_array($_SESSION['reincarnation_pending'])) {
    $pending = $_SESSION['reincarnation_pending'];
    $pending_uid = (int)($pending['uid'] ?? 0);
    $session_uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    if ($pending_uid > 0 && $session_uid > 0 && $pending_uid === $session_uid) {
        $reincarnation_mode = true;
        $reincarnation_pending = $pending;
        $nickname = (string)($pending['nickname'] ?? ($_SESSION['nickname'] ?? '사령관'));
    } else {
        unset($_SESSION['reincarnation_pending']);
    }
}

if (!$reincarnation_mode) {
    if (!isset($_SESSION['temp_nickname'])) {
        header("Location: login.php");
        exit;
    }
    $nickname = $_SESSION['temp_nickname'];
}

$pending_start_gold = $reincarnation_mode ? max(1000, (int)($reincarnation_pending['start_gold'] ?? 1000)) : 1000;
$pending_bonus_total = $reincarnation_mode ? max(0, (int)($reincarnation_pending['new_stat_bonus_total'] ?? 0)) : 0;
$pending_new_bonus_gain = $reincarnation_mode ? max(0, (int)($reincarnation_pending['new_bonus_gain'] ?? 0)) : 0;
$pending_life_levels = $reincarnation_mode ? max(0, (int)($reincarnation_pending['life_levels'] ?? 0)) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_type = $_POST['class_type'] ?? '';
    $background_story = $_POST['background_story'] ?? '';
    $narrative_tone = '다크 판타지 톤';

    if (in_array($class_type, array('돌격', '신비', '전술'), true)) {
        $roll_keys = array('str', 'mag', 'agi', 'luk', 'men', 'vit');
        $base_rolls = array();
        $roll_is_valid = true;
        foreach ($roll_keys as $rk) {
            $posted = isset($_POST['roll_' . $rk]) ? (int)$_POST['roll_' . $rk] : 0;
            if ($posted < 1 || $posted > 20) {
                $roll_is_valid = false;
                break;
            }
            $base_rolls[$rk] = $posted;
        }
        $disp = isset($_POST['roll_disposition']) ? (int)$_POST['roll_disposition'] : 0;
        if ($disp < 1 || $disp > 100) {
            $roll_is_valid = false;
        }

        if (!$roll_is_valid) {
            $base_rolls = get_random_base_rolls();
            $disp = rand(1, 100);
        }

        $str = (int)$base_rolls['str'];
        $mag = (int)$base_rolls['mag'];
        $agi = (int)$base_rolls['agi'];
        $luk = (int)$base_rolls['luk'];
        $men = (int)$base_rolls['men'];
        $vit = (int)$base_rolls['vit'];

        if ($class_type === '돌격') {
            $str += 10;
            $vit += 10;
        } elseif ($class_type === '신비') {
            $mag += 10;
            $men += 10;
        } elseif ($class_type === '전술') {
            $agi += 10;
            $luk += 10;
        }

        try {
            if ($reincarnation_mode) {
                $bonus_pack = array(
                    'str' => $str,
                    'mag' => $mag,
                    'agi' => $agi,
                    'luk' => $luk,
                    'men' => $men,
                    'vit' => $vit,
                );
                distribute_random_bonus_stats_local($bonus_pack, $pending_bonus_total);
                $str = (int)$bonus_pack['str'];
                $mag = (int)$bonus_pack['mag'];
                $agi = (int)$bonus_pack['agi'];
                $luk = (int)$bonus_pack['luk'];
                $men = (int)$bonus_pack['men'];
                $vit = (int)$bonus_pack['vit'];
                $start_max_hp = get_starting_hp_from_vit($vit);
                $start_max_mp = get_starting_mp_from_mag($mag);

                $pending_uid = (int)($reincarnation_pending['uid'] ?? 0);
                if ($pending_uid <= 0) {
                    throw new \Exception('환생 세션이 유효하지 않습니다.');
                }

                $new_reincarnation_count = max(0, (int)($reincarnation_pending['new_reincarnation_count'] ?? 0));
                $new_level_total = max(0, (int)($reincarnation_pending['new_level_total'] ?? 0));
                $gold_bonus = max(0, (int)($reincarnation_pending['gold_bonus'] ?? ($pending_start_gold - 1000)));

                $pdo->beginTransaction();
                $lock_stmt = $pdo->prepare("SELECT uid, is_combat FROM tb_commanders WHERE uid = ? FOR UPDATE");
                $lock_stmt->execute([$pending_uid]);
                $current_cmd = $lock_stmt->fetch();
                if (!$current_cmd) throw new \Exception('유저 정보 없음');
                if ((int)$current_cmd['is_combat'] === 1) throw new \Exception('전투 중에는 환생을 완료할 수 없습니다.');

                $pdo->prepare("DELETE eh FROM tb_expedition_heroes eh INNER JOIN tb_expeditions e ON eh.expedition_id = e.expedition_id WHERE e.uid = ?")
                    ->execute([$pending_uid]);
                $pdo->prepare("DELETE FROM tb_expeditions WHERE uid = ?")->execute([$pending_uid]);
                $pdo->prepare("DELETE FROM tb_heroes WHERE uid = ?")->execute([$pending_uid]);

                $update_stmt = $pdo->prepare("UPDATE tb_commanders SET
                    class_type = ?,
                    hp = ?, max_hp = ?,
                    mp = ?, max_mp = ?,
                    stat_str = ?, stat_mag = ?, stat_agi = ?, stat_luk = ?, stat_men = ?, stat_vit = ?,
                    disposition = ?,
                    gold = ?,
                    current_floor = 1,
                    stat_points = 5,
                    level = 1,
                    exp = 0,
                    is_combat = 0,
                    mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0,
                    reincarnation_count = ?,
                    reincarnation_level_total = ?,
                    reincarnation_stat_bonus = ?,
                    background_story = ?,
                    intro_story_seen = 0
                    WHERE uid = ?");
                $update_stmt->execute([
                    $class_type,
                    $start_max_hp, $start_max_hp,
                    $start_max_mp, $start_max_mp,
                    $str, $mag, $agi, $luk, $men, $vit,
                    $disp,
                    $pending_start_gold,
                    $new_reincarnation_count,
                    $new_level_total,
                    $pending_bonus_total,
                    $background_story,
                    $pending_uid
                ]);

                $log = "♻️ <b>[환생 의식 완료]</b> 새로운 육신이 완성되었습니다. 누적 레벨 {$pending_life_levels} 반영(+{$pending_new_bonus_gain} 보너스), 시작 골드 <b>{$pending_start_gold}G</b> (누적 골드 보너스 {$gold_bonus}G), 보유 영웅과 출전 덱은 모두 초기화되었습니다.";
                $pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute([$pending_uid, $log]);
                $pdo->commit();

                unset($_SESSION['reincarnation_pending']);
                unset($_SESSION['combat_state']);
                $_SESSION['orc_frenzy_stacks'] = 0;
                $_SESSION['orc_no_kill_turns'] = 0;
                $_SESSION['class_type'] = $class_type;
                $_SESSION['nickname'] = $nickname;

                header("Location: index.php");
                exit;
            }

            $start_max_hp = get_starting_hp_from_vit($vit);
            $start_max_mp = get_starting_mp_from_mag($mag);

            $stmt = $pdo->prepare("
                INSERT INTO tb_commanders
                (nickname, class_type, narrative_tone, hp, max_hp, mp, max_mp, stat_str, stat_mag, stat_agi, stat_luk, stat_men, stat_vit, disposition, gold, current_floor, stat_points, level, exp, background_story)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1000, 1, 5, 1, 0, ?)
            ");
            $stmt->execute([$nickname, $class_type, $narrative_tone, $start_max_hp, $start_max_hp, $start_max_mp, $start_max_mp, $str, $mag, $agi, $luk, $men, $vit, $disp, $background_story]);

            $new_uid = $pdo->lastInsertId();

            unset($_SESSION['temp_nickname']);
            $_SESSION['uid'] = $new_uid;
            $_SESSION['nickname'] = $nickname;
            $_SESSION['class_type'] = $class_type;
            $_SESSION['narrative_tone'] = $narrative_tone;

            header("Location: index.php");
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() == 23000) {
                $error_msg = "앗! 길드 내에 이미 같은 닉네임이 존재합니다.";
                if (!$reincarnation_mode) {
                    unset($_SESSION['temp_nickname']);
                }
            } else {
                $error_msg = "DB 저장 중 오류가 발생했습니다: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "올바른 사령관 특성을 선택해주세요.";
    }
}

$page_title = $reincarnation_mode ? '환생 의식 - 혼돈의 미궁' : '사령관 임명 - 혼돈의 미궁';
$heading_title = $reincarnation_mode ? '환생 육신 재구성' : '차원 사령관 임명';
$welcome_text = $reincarnation_mode
    ? '[ ' . $nickname . ' ] 님, 새로운 육신의 주특기와 주사위를 확정하십시오.'
    : '[ ' . $nickname . ' ] 님, 주특기를 선택하십시오.';
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Malgun Gothic', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .create-box { background-color: #1e1e1e; padding: 40px; border-radius: 8px; box-shadow: 0 0 15px rgba(255, 165, 0, 0.2); width: 450px; max-height: 90vh; overflow-y: auto; }
        h2 { color: #ffa500; margin-bottom: 10px; text-align: center; }
        .welcome { text-align: center; color: #aaa; margin-bottom: 30px; }
        .class-card { background-color: #2c2c2c; border: 1px solid #444; padding: 15px; margin-bottom: 15px; border-radius: 6px; cursor: pointer; transition: 0.3s; }
        .class-card:hover { border-color: #ffa500; }
        .class-card input[type="radio"] { display: none; }
        .class-card input[type="radio"]:checked + div h3 { color: #ffa500; }
        .class-card input[type="radio"]:checked + div { font-weight: bold; }
        .class-title { margin: 0 0 5px 0; font-size: 18px; color: #fff; }
        .class-desc { margin: 0; font-size: 13px; color: #888; font-weight: normal; }
        textarea { width: 100%; background-color: #2c2c2c; color: #e0e0e0; border: 1px solid #444; border-radius: 4px; padding: 10px; box-sizing: border-box; resize: vertical; font-family: 'Malgun Gothic', sans-serif;}
        button { width: 100%; padding: 15px; background-color: #ffa500; color: #121212; border: none; border-radius: 4px; font-weight: bold; font-size: 16px; cursor: pointer; margin-top: 10px; }
        button:hover { background-color: #e69500; }
        .error { color: #ff5252; text-align: center; margin-bottom: 15px; }
        .roll-panel { margin: 14px 0 18px; padding: 12px; background: #242424; border: 1px solid #3d3d3d; border-radius: 6px; }
        .roll-title { color: #ffcc80; font-weight: bold; margin-bottom: 8px; font-size: 14px; }
        .roll-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .roll-row { display: flex; align-items: center; justify-content: space-between; background: #1a1a1a; border: 1px solid #333; border-radius: 4px; padding: 6px 8px; }
        .roll-name { color: #bdbdbd; font-size: 13px; }
        .roll-value { color: #fff; font-weight: bold; min-width: 38px; text-align: center; }
        .btn-reroll { width: auto; margin: 0; padding: 5px 8px; font-size: 12px; background: #546e7a; color: #fff; border-radius: 4px; }
        .btn-reroll:hover { background: #607d8b; }
        .roll-note { color: #9e9e9e; font-size: 12px; margin-top: 8px; line-height: 1.4; }
    </style>
</head>
<body>

<div class="create-box">
    <h2><?= htmlspecialchars($heading_title) ?></h2>
    <div class="welcome"><?= htmlspecialchars($welcome_text) ?></div>

    <?php if ($reincarnation_mode): ?>
        <div style="margin-bottom:14px; padding:10px; border:1px solid #5d3f76; border-radius:6px; background:#241a2d; color:#d1c4e9; font-size:13px; line-height:1.5;">
            환생 누적 보너스 <b>+<?= (int)$pending_bonus_total ?></b>가 최종 스탯에 랜덤 반영됩니다.<br>
            시작 골드는 <b><?= number_format((int)$pending_start_gold) ?>G</b>이며, 이번 생 레벨 반영치는 <b><?= number_format((int)$pending_life_levels) ?></b>입니다.
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="돌격" required>
            <div>
                <h3 class="class-title">🗡️ 돌격 사령관</h3>
                <p class="class-desc">물리 전투의 대가입니다. 힘(STR)과 체력(VIT) 다이스에 강력한 보너스를 받습니다.</p>
            </div>
        </label>

        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="신비" required>
            <div>
                <h3 class="class-title">✨ 신비 사령관</h3>
                <p class="class-desc">마법과 퍼즐에 능합니다. 지능(INT)과 정신력(MEN) 다이스에 보너스를 받습니다.</p>
            </div>
        </label>

        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="전술" required>
            <div>
                <h3 class="class-title">🎲 전술 사령관</h3>
                <p class="class-desc">함정 회피와 운에 기대는 변수 창출의 대가입니다. 민첩(AGI)과 행운(LUK)에 보너스를 받습니다.</p>
            </div>
        </label>

        <div class="roll-panel">
            <div class="roll-title">🎲 초기 능력치 랜덤 배분 (원하는 만큼 재굴림 가능)</div>
            <div class="roll-grid">
                <div class="roll-row"><span class="roll-name">힘 (STR)</span><span class="roll-value" id="roll-view-str">-</span><button type="button" class="btn-reroll" data-roll-key="str">다시</button></div>
                <div class="roll-row"><span class="roll-name">마력 (MAG)</span><span class="roll-value" id="roll-view-mag">-</span><button type="button" class="btn-reroll" data-roll-key="mag">다시</button></div>
                <div class="roll-row"><span class="roll-name">민첩 (AGI)</span><span class="roll-value" id="roll-view-agi">-</span><button type="button" class="btn-reroll" data-roll-key="agi">다시</button></div>
                <div class="roll-row"><span class="roll-name">행운 (LUK)</span><span class="roll-value" id="roll-view-luk">-</span><button type="button" class="btn-reroll" data-roll-key="luk">다시</button></div>
                <div class="roll-row"><span class="roll-name">정신력 (MEN)</span><span class="roll-value" id="roll-view-men">-</span><button type="button" class="btn-reroll" data-roll-key="men">다시</button></div>
                <div class="roll-row"><span class="roll-name">체력 (VIT)</span><span class="roll-value" id="roll-view-vit">-</span><button type="button" class="btn-reroll" data-roll-key="vit">다시</button></div>
                <div class="roll-row" style="grid-column: 1 / span 2;"><span class="roll-name">성향 (1~100)</span><span class="roll-value" id="roll-view-disposition">-</span><button type="button" class="btn-reroll" data-roll-key="disposition">다시</button></div>
            </div>
            <div class="roll-note">
                선택한 클래스의 +10 보너스는 최종 생성 시 적용됩니다.
                시작 HP는 VIT, 시작 MP는 MAG 수치에 비례해 계산됩니다.
                <?php if ($reincarnation_mode): ?>
                    환생 누적 보너스 +<?= (int)$pending_bonus_total ?>는 확정 시 랜덤 분배됩니다.
                <?php endif; ?>
                만족할 때까지 개별 재굴림 후 입장하세요.
            </div>
        </div>

        <input type="hidden" name="roll_str" id="roll-input-str" value="">
        <input type="hidden" name="roll_mag" id="roll-input-mag" value="">
        <input type="hidden" name="roll_agi" id="roll-input-agi" value="">
        <input type="hidden" name="roll_luk" id="roll-input-luk" value="">
        <input type="hidden" name="roll_men" id="roll-input-men" value="">
        <input type="hidden" name="roll_vit" id="roll-input-vit" value="">
        <input type="hidden" name="roll_disposition" id="roll-input-disposition" value="">

		<div style="margin-bottom: 15px;">
            <label for="background_story" style="display:block; margin-bottom:5px; color: #ccc;"><?= $reincarnation_mode ? '재탄생 설화 (AI 생성 등)' : '캐릭터 탄생 설화 (AI 생성 등)' ?></label>
            <textarea id="background_story" name="background_story" rows="4" placeholder="<?= $reincarnation_mode ? '새로운 생의 배경 이야기를 자유롭게 작성하거나 붙여넣어 주세요.' : '캐릭터의 배경 이야기를 자유롭게 작성하거나 붙여넣어 주세요.' ?>"></textarea>
		</div>

        <button type="submit"><?= $reincarnation_mode ? '주사위 확정 및 환생 완료 ♻️' : '주사위 굴리기 및 던전 입장 🎲' ?></button>
    </form>
</div>

<script>
(function () {
    const rolls = {
        str: 1,
        mag: 1,
        agi: 1,
        luk: 1,
        men: 1,
        vit: 1,
        disposition: 50,
    };

    function randomInt(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    function rerollKey(key) {
        if (key === 'disposition') {
            rolls.disposition = randomInt(1, 100);
            return;
        }
        rolls[key] = randomInt(1, 20);
    }

    function syncRollToDom(key) {
        const view = document.getElementById('roll-view-' + key);
        if (view) view.textContent = String(rolls[key]);
        const input = document.getElementById('roll-input-' + key);
        if (input) input.value = String(rolls[key]);
    }

    function syncAll() {
        Object.keys(rolls).forEach(syncRollToDom);
    }

    ['str', 'mag', 'agi', 'luk', 'men', 'vit', 'disposition'].forEach((key) => {
        rerollKey(key);
    });
    syncAll();

    const rerollButtons = document.querySelectorAll('.btn-reroll[data-roll-key]');
    rerollButtons.forEach((btn) => {
        btn.addEventListener('click', function () {
            const key = btn.getAttribute('data-roll-key');
            if (!key || !(key in rolls)) return;
            rerollKey(key);
            syncRollToDom(key);
        });
    });
})();
</script>

</body>
</html>