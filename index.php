<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'bootstrap.php';

if (!isset($_SESSION['uid'])) { header("Location: login.php"); exit; }
$uid = $_SESSION['uid'];

try {
    $stmt = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ?");
    $stmt->execute([$uid]);
    $commander = $stmt->fetch();
    if (!$commander) { session_destroy(); header("Location: login.php"); exit; }
} catch (\PDOException $e) { die("DB 오류"); }

$intro_story_payload = null;
$background_story = trim((string)($commander['background_story'] ?? ''));
$intro_story_seen = (int)($commander['intro_story_seen'] ?? 0);
if ($background_story !== '' && $intro_story_seen === 0) {
    $tone_map = [
        'dark_fantasy' => '다크 판타지',
        'high_tension' => '하이텐션',
        'concise_log' => '간결 로그'
    ];
    $tone_key = (string)($commander['narrative_tone'] ?? 'dark_fantasy');
    $tone_label = $tone_map[$tone_key] ?? '다크 판타지';

    $_SESSION['story_stream_text'] = trim(
        "다음 배경 설정을 바탕으로, 게임 시작 직후 메인 팝업에서 보여줄 오프닝 서사를 작성하라. " .
        "반드시 한국어로 작성하고, 5~8문장 분량의 스크립트형 연출로 구성하라. " .
        "주인공 이름은 '{$commander['nickname']}', 직업은 '{$commander['class_type']}', 톤은 '{$tone_label}'이다. " .
        "플레이어가 곧 1층 탐험을 시작한다는 긴장감을 담고, 마지막 문장은 한 걸음을 내딛는 장면으로 마무리하라. " .
        "배경 서사: {$background_story}"
    );

    $pdo->prepare("UPDATE tb_commanders SET intro_story_seen = 1 WHERE uid = ?")->execute([$uid]);
    $intro_story_payload = [
        'story_title' => '운명의 서막'
    ];
}

function get_exp_to_next_for_level($level) {
    $lv = max(1, (int)$level);
    if ($lv >= 1000) return 0;
    if ($lv <= 10) return 100 * $lv;
    if ($lv <= 20) return 140 * $lv;
    if ($lv <= 30) return 190 * $lv;
    if ($lv <= 40) return 250 * $lv;
    if ($lv <= 50) return 320 * $lv;
    if ($lv <= 100) return 420 * $lv;
    if ($lv <= 200) return 520 * $lv;
    if ($lv <= 300) return 680 * $lv;
    if ($lv <= 400) return 860 * $lv;
    if ($lv <= 500) return 1060 * $lv;
    if ($lv <= 650) return 1280 * $lv;
    if ($lv <= 800) return 1530 * $lv;
    if ($lv <= 900) return 1810 * $lv;
    return 2120 * $lv;
}

$exp_to_next = get_exp_to_next_for_level((int)($commander['level'] ?? 1));
$exp_progress_width = ($exp_to_next > 0) ? (((int)($commander['exp'] ?? 0) / $exp_to_next) * 100) : 100;
$commander_stat_values = [
    'str' => (int)($commander['stat_str'] ?? 0),
    'mag' => (int)($commander['stat_mag'] ?? 0),
    'agi' => (int)($commander['stat_agi'] ?? 0),
    'luk' => (int)($commander['stat_luk'] ?? 0),
    'men' => (int)($commander['stat_men'] ?? 0),
    'vit' => (int)($commander['stat_vit'] ?? 0),
];
$commander_stat_max = max($commander_stat_values);
$commander_stat_min = min($commander_stat_values);
$commander_stat_classes = array_fill_keys(array_keys($commander_stat_values), 'stat-value');
if ($commander_stat_max > $commander_stat_min) {
    foreach ($commander_stat_values as $key => $value) {
        if ($value === $commander_stat_max) $commander_stat_classes[$key] .= ' stat-highest';
        if ($value === $commander_stat_min) $commander_stat_classes[$key] .= ' stat-lowest';
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>운빨 던전 - <?= htmlspecialchars($commander['nickname']) ?></title>
    <style>
        body { margin: 0; background: #050505; color: #ddd; font-family: 'Malgun Gothic', sans-serif; display: flex; height: 100vh; overflow: hidden; }
        .panel { padding: 20px; box-sizing: border-box; overflow-y: auto; }
        .left-panel { width: 25%; background: #111; border-right: 1px solid #333; }
        .center-panel { width: 50%; background: #0a0a0a; display: flex; flex-direction: column; position: relative; }
        .right-panel { width: 25%; background: #111; border-left: 1px solid #333; }
        h2 { color: #ffa500; font-size: 1.2rem; margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 10px; }
        .stat-box { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; border-bottom: 1px dashed #222; padding-bottom: 5px; }
        .stat-value { font-weight: bold; color: #4caf50; transition: color 0.2s ease, text-shadow 0.2s ease; }
        .stat-value.stat-highest { color: #ffca28; text-shadow: 0 0 10px rgba(255, 202, 40, 0.35); }
        .stat-value.stat-lowest { color: #80deea; text-shadow: 0 0 8px rgba(128, 222, 234, 0.25); }
        .bar-bg { background: #333; border-radius: 4px; width: 100%; height: 16px; margin-bottom: 15px; overflow: hidden; }
        .hp-bar { background: #4caf50; height: 100%; width: <?= ($commander['hp'] / $commander['max_hp']) * 100 ?>%; transition: width 0.3s; }
        .mp-bar { background: #2196f3; height: 100%; width: <?= ($commander['mp'] / $commander['max_mp']) * 100 ?>%; transition: width 0.3s; }
        .mob-hp-bar { background: #ff5252; height: 100%; width: 100%; transition: width 0.12s ease-out; }
        .log-container { flex-grow: 1; padding: 15px; overflow-y: auto; font-size: 1.05rem; line-height: 1.6; }
        .log-entry { margin-bottom: 12px; padding: 12px; background: #1a1a1a; border-left: 4px solid #4caf50; border-radius: 4px; animation: fadeIn 0.3s; }
        .log-entry.system { border-left-color: #ffa500; color: #ffeb3b; }
        .log-entry.incoming-damage { border-left-color: #ff5252; background: linear-gradient(90deg, rgba(120, 10, 10, 0.7) 0%, rgba(26, 26, 26, 0.95) 100%); color: #ffd6d6; box-shadow: 0 0 18px rgba(255, 82, 82, 0.22); }
        .turn-script-line { white-space: pre-wrap; margin: 4px 0; }
        .turn-script-line.incoming-damage { margin: 8px 0 6px; padding: 8px 10px; border-left: 4px solid #ff5252; border-radius: 6px; background: linear-gradient(90deg, rgba(140, 20, 20, 0.78) 0%, rgba(30, 8, 8, 0.95) 100%); color: #ffd6d6; font-weight: bold; text-shadow: 0 0 10px rgba(255, 82, 82, 0.35); box-shadow: 0 0 14px rgba(255, 82, 82, 0.18); }
        .turn-script-line.status-effect { color: #c8f1ff; }
        .action-container { padding: 20px; background: #111; border-top: 1px solid #333; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .btn { padding: 15px; background: #2c2c2c; color: #fff; border: 1px solid #555; border-radius: 4px; cursor: pointer; font-weight: bold; transition: 0.2s; text-align: center; user-select: none; }
        .btn:hover { background: #4caf50; color: #000; }
        .btn-summon { background: #ffa500; color: #000; }
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; vertical-align: middle; margin: 0 5px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #4caf50; }
        input:checked + .slider:before { transform: translateX(20px); }
        .damage-flash { animation: flashRed 0.3s; }
        @keyframes flashRed { 0% { background-color: #500000; } 100% { background-color: #0a0a0a; } }
        .heal-flash { animation: flashGreen 0.5s ease-out; }
        .hp-hit { animation: hpHitPulse 0.45s ease-out; }
        @keyframes hpHitPulse {
            0% { filter: brightness(1); transform: scaleX(1); }
            35% { filter: brightness(1.9); transform: scaleX(1.02); }
            100% { filter: brightness(1); transform: scaleX(1); }
        }
        @keyframes flashGreen { 0% { background-color: #003300; } 100% { background-color: #0a0a0a; } }
        /* 모달 스타일 */
        .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:999; justify-content:center; align-items:center; }
        .modal-content { background:#111; width:90%; max-width:800px; max-height:80%; overflow-y:auto; padding:20px; border:2px solid #ff9800; border-radius:8px; position:relative; }
        
        /* 보스 조우 시 연출 */
        .boss-bg { animation: pulseDark 2s infinite; background: #220000 !important; transition: background 1s; }
        @keyframes pulseDark { 0% { background: #1a0000; } 50% { background: #450505; } 100% { background: #1a0000; } }
        
        /* 스탯 업 버튼 스타일 */
        .btn-stat-up { background: #ff9800; color: #000; border: none; border-radius: 50%; width: 20px; height: 20px; font-weight: bold; cursor: pointer; font-size: 12px; margin-left: 5px; display: none; }
        
        /* 획득 이펙트 오버레이 */
        #obtain-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); z-index:2000; flex-direction:column; justify-content:center; align-items:center; animation: fadeIn 0.5s; }
        .obtain-light { position:absolute; width:100%; height:100%; background: radial-gradient(circle, rgba(255,215,0,0.3) 0%, rgba(0,0,0,0) 70%); animation: pulseLight 2s infinite; }
        .obtain-text { font-size: 3rem; font-weight: bold; color: #fff; text-shadow: 0 0 20px #ffeb3b; z-index:2001; text-align:center; animation: scaleUp 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .obtain-rank { font-size: 1.5rem; color: #ff5252; margin-bottom: 20px; z-index:2001; letter-spacing: 5px; animation: slideDown 0.5s; }
        .obtain-sub { font-size: 1rem; color: #aaa; margin-top: 30px; z-index:2001; opacity:0; animation: fadeIn 1s 1s forwards; }

        /* 레벨업 오버레이 */
        #levelup-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; z-index:2100; background:rgba(8, 12, 24, 0.88); justify-content:center; align-items:center; }
        .levelup-card { min-width:320px; max-width:90%; text-align:center; padding:28px 24px; border-radius:12px; border:2px solid #ffd54f; background:linear-gradient(180deg, #1b2432 0%, #0f1624 100%); box-shadow:0 0 28px rgba(255, 213, 79, 0.35); animation: scaleUp 0.45s ease-out; }
        .levelup-title { font-size:2.1rem; font-weight:bold; color:#ffeb3b; letter-spacing:2px; text-shadow:0 0 16px rgba(255, 235, 59, 0.7); }
        .levelup-level { margin-top:10px; font-size:1.4rem; color:#ffffff; font-weight:bold; }
        .levelup-meta { margin-top:10px; font-size:0.95rem; color:#b0bec5; }
        
        @keyframes scaleUp { 
            0% { transform: scale(0.1); opacity: 0; } 
            60% { transform: scale(1.2); opacity: 1; } 
            100% { transform: scale(1); opacity: 1; } 
        }
        @keyframes slideDown {
            0% { transform: translateY(-50px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }
        @keyframes pulseLight {
            0% { transform: scale(0.8); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
            100% { transform: scale(0.8); opacity: 0.5; }
        }

        /* 랭킹 1~3위 특별 스타일 (금/은/동 효과) */
        #ranking-list-area table tr:nth-child(2) { background: linear-gradient(90deg, rgba(255,215,0,0.15) 0%, transparent 100%); border-left: 3px solid #ffd700; }
        #ranking-list-area table tr:nth-child(3) { background: linear-gradient(90deg, rgba(192,192,192,0.15) 0%, transparent 100%); border-left: 3px solid #c0c0c0; }
        #ranking-list-area table tr:nth-child(4) { background: linear-gradient(90deg, rgba(205,127,50,0.15) 0%, transparent 100%); border-left: 3px solid #cd7f32; }
    </style>
</head>
<body>

    <div class="panel left-panel">
        <h2>🛡️ Lv.<span id="level-display"><?= $commander['level'] ?? 1 ?></span> <?= htmlspecialchars($commander['nickname']) ?></h2>
        <div style="font-size: 0.85rem; color: #4caf50; margin-bottom: 3px; font-weight: bold;" id="player-hp-text">HP (<?= $commander['hp'] ?> / <?= $commander['max_hp'] ?>)</div>
        <div class="bar-bg"><div class="hp-bar" id="player-hp-bar"></div></div>
        <div style="font-size: 0.85rem; color: #2196f3; margin-bottom: 3px; font-weight: bold;" id="player-mp-text">MP (<?= $commander['mp'] ?> / <?= $commander['max_mp'] ?>)</div>
        <div class="bar-bg"><div class="mp-bar" id="player-mp-bar" style="width: <?= ($commander['mp'] / $commander['max_mp']) * 100 ?>%;"></div></div>
        
        <!-- 경험치 바 추가 -->
        <div style="font-size: 0.85rem; color: #b388ff; margin-bottom: 3px; font-weight: bold;">EXP (<span id="exp-text"><?= $commander['exp'] ?? 0 ?> / <?= max(1, $exp_to_next) ?></span>)</div>
        <div class="bar-bg"><div class="exp-bar" id="exp-bar" style="background: #7c4dff; height: 100%; width: <?= $exp_progress_width ?>%; transition: width 0.3s;"></div></div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding:8px; background:#1a1a1a; border-radius:4px; border:1px solid #333;">
            <span style="color:#aaa; font-size:0.9rem;">🚩 최고 도달</span>
            <span style="color:#fff; font-weight:bold;"><?= $commander['max_floor'] ?? 1 ?>층</span>
        </div>

        <div style="margin-bottom: 25px; padding: 10px; background: #222; border-radius: 4px; color: #ffd700; font-weight: bold; text-align: center;">💰 골드: <span id="gold-display"><?= number_format($commander['gold']) ?></span> G</div>
        
        <h3 style="color: #ccc; font-size: 1rem; border-bottom: 1px solid #333; padding-bottom: 5px; display:flex; justify-content:space-between;">
            <span>📊 사령관 스탯</span> <span style="font-size:0.8rem; color:#ff9800;">POINT: <span id="stat-points"><?= $commander['stat_points'] ?? 0 ?></span></span>
        </h3>
        <div class="stat-box" title="STR: 사령관 기본 공격식에 STR*2 적용, 물리 영웅 피해 +2%/10 STR, 함정 파괴 확률 min(35, floor(STR/3))"><span class="stat-name">힘 (STR)</span> <div><span class="<?= $commander_stat_classes['str'] ?>" data-commander-stat="str" id="val-str"><?= $commander['stat_str'] ?></span> <button class="btn-stat-up" data-stat="str" title="STR 1 증가">+</button></div></div>
        <div class="stat-box" title="MAG: 액티브 스킬 피해/회복 증폭, 마법 영웅 피해 +2%/10 MAG, 탐색 시 MP 회복 floor(MAG/10)"><span class="stat-name">마력 (MAG)</span> <div><span class="<?= $commander_stat_classes['mag'] ?>" data-commander-stat="mag" id="val-mag"><?= $commander['stat_mag'] ?></span> <button class="btn-stat-up" data-stat="mag" title="MAG 1 증가">+</button></div></div>
        <div class="stat-box" title="AGI: 도주 확률 40+AGI, 사령관/영웅 연속 공격 확률 floor(AGI/5)%"><span class="stat-name">민첩 (AGI)</span> <div><span class="<?= $commander_stat_classes['agi'] ?>" data-commander-stat="agi" id="val-agi"><?= $commander['stat_agi'] ?></span> <button class="btn-stat-up" data-stat="agi" title="AGI 1 증가">+</button></div></div>
        <div class="stat-box" title="LUK: 치명타 확률 floor(LUK/2)%, 치명 배율 1.5+LUK*0.01, 탐험 골드/행운 이벤트 강화, 소환(전설/영웅/희귀) 가중치 보정"><span class="stat-name">행운 (LUK)</span> <div><span class="<?= $commander_stat_classes['luk'] ?>" data-commander-stat="luk" id="val-luk"><?= $commander['stat_luk'] ?></span> <button class="btn-stat-up" data-stat="luk" title="LUK 1 증가">+</button></div></div>
        <div class="stat-box" title="MEN: 최대 MP +5/포인트, 휴식 추가 회복 +MEN*3, 영웅 피해 배율 1+MEN*0.005, 영웅 스킬 발동 +2%/10 MEN"><span class="stat-name">정신력 (MEN)</span> <div><span class="<?= $commander_stat_classes['men'] ?>" data-commander-stat="men" id="val-men"><?= $commander['stat_men'] ?></span> <button class="btn-stat-up" data-stat="men" title="MEN 1 증가">+</button></div></div>
        <div class="stat-box" title="VIT: 최대 HP +20/포인트, 피해 감소 floor(VIT/2), 반격 방어 floor(VIT/5)%, 영웅 보호막 floor(VIT/10)% (최대 40%)"><span class="stat-name">체력 (VIT)</span> <div><span class="<?= $commander_stat_classes['vit'] ?>" data-commander-stat="vit" id="val-vit"><?= $commander['stat_vit'] ?></span> <button class="btn-stat-up" data-stat="vit" title="VIT 1 증가">+</button></div></div>
        <div class="stat-box"><span class="stat-name">성향</span> <span class="stat-value"><?= $commander['disposition'] ?> (<?php 
            $disp = $commander['disposition'];
            if ($disp <= 20) echo '극도로 조심'; 
            elseif ($disp <= 40) echo '신중함'; 
            elseif ($disp <= 60) echo '균형잡힘'; 
            elseif ($disp <= 80) echo '다소 과감'; 
            else echo '매우 과감';
        ?>)</span></div>
        <div style="margin-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="btn" onclick="openRanking()" style="padding: 10px; font-size: 0.9rem;">명예의 전당 🏆</div>
            <div class="btn" onclick="openRelicModal()" style="padding: 10px; font-size: 0.9rem; background:#6d4c41;">유물 제련 🗿</div>
            <div class="btn" onclick="document.getElementById('stat-help-modal').style.display='flex'" style="padding: 10px; font-size: 0.9rem; background:#455a64; grid-column: span 2;">스탯 공식 도움말 📘</div>
        </div>

        <div id="auto-explore-section" style="margin-top: 20px; background: #222; padding: 15px; border-radius: 5px;">
            <h3 style="color: #ccc; font-size: 1rem; margin:0 0 10px 0;">🏕️ 사령관 자동 탐험</h3>
            <div id="auto-explore-status-display" style="font-size: 0.9rem; color: #aaa; margin-bottom: 10px; display:none;">
                <p>진행 시간: <span id="auto-explore-timer">0분</span></p>
                <p>예상 보상: 💰<span id="auto-explore-gold">0</span>G | 📚<span id="auto-explore-exp">0</span>XP</p>
            </div>
            <button id="btn-start-auto-explore" class="btn" style="width:100%; background-color: #5a5;" onclick="startAutoExplore()">자동 탐험 시작</button>
            <button id="btn-claim-auto-explore" class="btn" style="width:100%; background-color: #a55; display:none;" onclick="claimAutoExplore()">보상 수령</button>
        </div>
    </div>

    <div class="panel center-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #333; background: #111;">
            <h2 style="margin: 0; border: none; padding: 0;">던전 <span id="floor-display"><?= $commander['current_floor'] ?></span> 층</h2>
            <div style="display:flex; align-items:center; justify-content:flex-end; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="color: #aaa; font-size: 0.9rem;">자동 전투</span>
                    <label class="switch"><input type="checkbox" id="auto-combat-toggle" onchange="toggleAutoMode()"><span class="slider"></span></label>
                    <span id="auto-status-text" style="color: #4caf50; font-weight: bold; font-size: 0.9rem;">[수동]</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;" title="전투가 끝나면 자동으로 다시 탐색을 이어갑니다.">
                    <span style="color: #aaa; font-size: 0.9rem;">자동 탐험</span>
                    <label class="switch"><input type="checkbox" id="auto-explore-toggle" onchange="toggleAutoExploreMode()"><span class="slider"></span></label>
                    <span id="auto-explore-status-text" style="color: #aaa; font-weight: bold; font-size: 0.9rem;">[OFF]</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;" title="HP 45% 이하 또는 MP 35% 이하일 때 자동으로 휴식합니다.">
                    <span style="color: #aaa; font-size: 0.9rem;">자동 휴식</span>
                    <label class="switch"><input type="checkbox" id="auto-rest-toggle" onchange="toggleAutoRestMode()"><span class="slider"></span></label>
                    <span id="auto-rest-status-text" style="color: #aaa; font-weight: bold; font-size: 0.9rem;">[OFF]</span>
                </div>
            </div>
        </div>

        <div id="monster-ui" style="display: none; padding: 15px; background: #2a0a0a; border-bottom: 2px solid #ff5252;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span id="mob-name-display" style="color: #ff5252; font-weight: bold;">몬스터</span>
                <span style="color: #ff5252; font-weight: bold;">HP (<span id="mob-hp-text">0/0</span>)</span>
            </div>
            <div class="bar-bg"><div class="mob-hp-bar" id="mob-hp-bar"></div></div>
        </div>
        
        <div class="log-container" id="game-log"></div>

        <div id="explore-actions" class="action-container">
            <div class="btn" onclick="sendAction('action')">탐색하기 👣</div>
            <div class="btn" onclick="sendAction('rest')">휴식하기 🏕️</div>
            <div class="btn" style="background:#1976d2;" onclick="sendAction('next_floor')">다음 층 이동 ⬆️</div>
            <div class="btn" style="background: #2196f3;" onclick="useSkill('heal')">💚 힐<br><span style='font-size:0.7rem;'>(MP 30)</span></div>
            <div class="btn btn-summon" onclick="sendAction('summon')">영웅 소환 ✨</div>
        </div>

        <div id="encounter-actions" class="action-container" style="display: none; background: #310;">
            <div class="btn" style="background: #d32f2f;" onclick="startCombat()">⚔️ 전투 시작</div>
            <div class="btn" style="background: #555;" onclick="attemptFlee()">🏃 도망치기</div>
        </div>

        <div id="combat-actions" class="action-container" style="display: none; background: #200; grid-template-columns: 1fr 1fr 1fr;">
            <div class="btn" style="background: #d32f2f; grid-column: span 3;" onclick="doCombatTurn()">🗡️ 공격</div>
            <div class="btn" style="background: #ff9800;" onclick="useSkill('fireball')">🔥 화염구<br><span style='font-size:0.7rem;'>(MP 25)</span></div>
            <div class="btn" style="background: #2196f3;" onclick="useSkill('heal')">💚 힐<br><span style='font-size:0.7rem;'>(MP 30)</span></div>
            <div class="btn" style="background: #ffeb3b; color: #000;" onclick="useSkill('thunder_bolt')">⚡ 번개<br><span style='font-size:0.7rem;'>(MP 28)</span></div>
            <div class="btn" style="background: #9c27b0;" onclick="useSkill('shield_up')">🛡️ 방어강화<br><span style='font-size:0.7rem;'>(MP 소모 없음)</span></div>
            <div class="btn" style="background: #d32f2f;" onclick="useSkill('berserk')">💥 광폭화<br><span style='font-size:0.7rem;'>(HP 10% · 공격/마법 +30%)</span></div>
            <div class="btn" style="background: #555;" onclick="attemptFlee()">🏃 도망치기</div>
        </div>

        <div id="dead-actions" class="action-container" style="display: none; background: #300; border-top: 3px solid #ff0000;">
            <div class="btn" style="background: #ff5252; color: white; grid-column: span 2; font-size: 1.2rem; padding: 20px;" onclick="revive()">✨ 여신의 축복으로 부활 (1층부터)</div>
        </div>
    </div>

    <!-- 조합 모달 -->
    <div id="combine-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('combine-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">🔮 신화 조합 & 불멸 진화</h2>
            <div id="combine-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 영웅 토벌대 모달 -->
    <div id="expedition-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('expedition-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">⚔️ 영웅 토벌대 파견</h2>
            <div id="expedition-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 랭킹 모달 -->
    <div id="ranking-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('ranking-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">🏆 명예의 전당</h2>
            <div id="ranking-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 영웅 레벨업 모달 -->
    <div id="hero-levelup-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('hero-levelup-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">⛩️ 영웅 제단 (레벨업)</h2>
            <div id="hero-levelup-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 유물 제련 모달 -->
    <div id="relic-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('relic-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">🗿 유물 제련소</h2>
            <div id="relic-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 도감 모달 -->
    <div id="book-modal" class="modal-overlay">
        <div class="modal-content">
            <button onclick="document.getElementById('book-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0;">📜 영웅 도감 & 컬렉션</h2>
            <div id="book-list-area">불러오는 중...</div>
        </div>
    </div>

    <!-- 스탯 공식 도움말 모달 -->
    <div id="stat-help-modal" class="modal-overlay">
        <div class="modal-content" style="border-color:#90a4ae;">
            <button onclick="document.getElementById('stat-help-modal').style.display='none'" style="float:right; background:#d32f2f; color:white; border:none; padding:5px 10px; cursor:pointer;">닫기 ✖</button>
            <h2 style="margin-top:0; color:#90caf9;">📘 스탯 공식 도움말</h2>
            <div style="line-height:1.7; font-size:0.95rem;">
                <div style="margin-bottom:10px;"><b>🛡️ VIT</b><br>최대 HP +20/포인트, 전투/함정 피해 감소 floor(VIT/2), 반격 방어 확률 floor(VIT/5)%, 영웅 보호막 확률 floor(VIT/10)% (최대 40%).</div>
                <div style="margin-bottom:10px;"><b>🧠 MEN</b><br>최대 MP +5/포인트, 휴식 추가 회복 +MEN*3, 영웅 피해 배율 1 + MEN*0.005, 영웅 스킬 발동 확률 +2%/10 MEN.</div>
                <div style="margin-bottom:10px;"><b>🍀 LUK</b><br>치명타 확률 floor(LUK/2)%, 치명 배율 1.5 + LUK*0.01, 탐험 골드 획득량 (1 + LUK*0.01)배, 함정 행운 발동 확률 min(40, floor(LUK/2))%.<br>소환은 신화가 제외되며, LUK는 전설(+0.03%/35), 영웅(+0.10%/20), 희귀(+0.20%/15) 가중치 보정에만 적용됩니다.</div>
                <div style="margin-bottom:10px;"><b>🗡️ STR</b><br>사령관 기본 공격력 증가(기본식에 STR*2), 물리 영웅 피해 +2%/10 STR, 함정 파괴 확률 min(35, floor(STR/3))%.</div>
                <div style="margin-bottom:10px;"><b>🔮 MAG</b><br>액티브 스킬 피해/회복이 MAG 비례 증폭, 마법 영웅 피해 +2%/10 MAG, 탐색 시 MP 자연회복 floor(MAG/10).</div>
                <div><b>💨 AGI</b><br>도주 확률 40 + AGI, 사령관/영웅 연속 공격 확률 floor(AGI/5)%.</div>
            </div>
        </div>
    </div>

    <!-- 스토리 모달 -->
    <div id="story-modal" class="modal-overlay">
        <div class="modal-content" style="border-color: #ffeb3b;">
            <h2 id="story-title" style="margin-top:0; color: #ffeb3b;">스토리 제목</h2>
            <div id="story-content" style="line-height: 1.7; white-space: pre-wrap; font-size: 1.1rem; max-height: 400px; overflow-y: auto; padding-right: 10px;">스토리 내용</div>
            <button onclick="document.getElementById('story-modal').style.display='none'" style="margin-top: 20px; padding: 10px 20px; background: #ffeb3b; color: #000; border: none; cursor: pointer; font-weight: bold; width: 100%;">계속...</button>
        </div>
    </div>

    <!-- 획득 이펙트 컨테이너 -->
    <div id="obtain-overlay" onclick="this.style.display='none'">
        <div class="obtain-light"></div>
        <div class="obtain-rank" id="eff-rank">MYTHIC</div>
        <div class="obtain-text" id="eff-name">영웅 이름</div>
        <div class="obtain-sub">화면을 클릭하여 닫기</div>
    </div>

    <div id="levelup-overlay" onclick="this.style.display='none'">
        <div class="levelup-card">
            <div class="levelup-title">LEVEL UP!</div>
            <div class="levelup-level" id="levelup-level-text">Lv.1 → Lv.2</div>
            <div class="levelup-meta" id="levelup-meta-text">성장이 깨어납니다.</div>
        </div>
    </div>

    <div class="panel right-panel">
        <h2>⚔️ 출전 덱 (<span id="deck-count-display">0</span>/5)</h2>
        <div id="deck-list"></div>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #333;">
            <h2 style="margin:0; padding:0; border:none;">🎒 보유 영웅</h2>
            <div class="btn" style="padding:8px 12px; font-size:0.85rem; background:#673ab7; white-space:nowrap;" onclick="openCombineModal()">조합/진화 🔮</div>
        </div>
        <div id="hero-list"></div>
        <div id="expedition-section" style="margin-top: 20px; background: #222; padding: 15px; border-radius: 5px;">
            <h3 style="color: #ccc; font-size: 1rem; margin:0 0 10px 0;">⚔️ 영웅 토벌대 파견</h3>
            <p style="font-size: 0.9rem; color: #aaa; margin-bottom: 10px;">
                대기 중인 영웅을 파견하여 추가 보상을 획득하세요.
            </p>
            <button id="btn-open-expedition-modal" class="btn" style="width:100%; background-color: #4a6;" onclick="openExpeditionModal()">토벌대 관리</button>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                <div class="btn" onclick="openBookModal()" style="padding: 10px; font-size: 0.9rem;">영웅 도감 📜</div>
                <div class="btn" onclick="openHeroLevelupModal()" style="padding: 10px; font-size: 0.9rem; background:#3f51b5;">영웅 제단 ⛩️</div>
            </div>
        </div>
    </div>

    <script>
        window.playerMaxHp = <?= (int)$commander['max_hp'] ?>;
        window.playerMaxMp = <?= (int)$commander['max_mp'] ?>;
        window.playerCurrentHp = <?= (int)$commander['hp'] ?>;
        window.playerCurrentMp = <?= (int)$commander['mp'] ?>;
        window.isDead = <?= ($commander['hp'] <= 0) ? 'true' : 'false' ?>;
        window.isCombat = <?= ($commander['is_combat'] == 1) ? 'true' : 'false' ?>;
        window.currentMobName = <?= json_encode($commander['mob_name'] ?? '') ?>;
        window.currentMobHp = <?= (int)$commander['mob_hp'] ?>;
        window.currentMobMaxHp = <?= (int)$commander['mob_max_hp'] ?>;
        // 초기 스탯 포인트 버튼 갱신을 위해 DOM 로드 후 실행될 JS에 값 전달
        window.initialStatPoints = <?= (int)($commander['stat_points'] ?? 0) ?>;
        window.initialIntroStory = <?= json_encode($intro_story_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="game.js?v=<?= time() ?>"></script>
    <script>
    function openExpeditionModal() {
    document.getElementById('expedition-modal').style.display = 'flex';
    const listArea = document.getElementById('expedition-list-area');
    listArea.innerHTML = '<p>정보를 불러오는 중...</p>';

    fetch('api.php?action=expedition_info')
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                listArea.innerHTML = `<p style="color:red;">오류: ${data.msg}</p>`;
                return;
            }

            let html = '<div>';

            // 진행 중인 파견
            html += '<h3 style="color:#ffc107;">진행 중인 토벌대</h3>';
            if (data.expeditions && data.expeditions.length > 0) {
                data.expeditions.forEach(exp => {
                    const startTime = new Date(exp.start_time);
                    const endTime = new Date(startTime.getTime() + exp.duration * 60 * 60 * 1000);
                    const now = new Date();
                    const remaining = endTime - now;
                    
                    html += `<div style="background:#333; padding:10px; margin-bottom:10px; border-radius:4px;">`;
                    html += `<p><strong>파견 시간:</strong> ${exp.duration}시간</p>`;
                    html += `<p><strong>소속 영웅:</strong> ${exp.heroes.length}명</p>`;
                    if (remaining > 0) {
                        const hours = Math.floor(remaining / (1000 * 60 * 60));
                        const minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
                        html += `<p><strong>남은 시간:</strong> 약 ${hours}시간 ${minutes}분</p>`;
                        html += `<button class="btn" disabled>복귀 대기 중</button>`;
                    } else {
                        html += `<p style="color:#4caf50;"><strong>복귀 완료!</strong></p>`;
                        html += `<button class="btn" style="background:#4caf50;" onclick="claimExpeditionReward(${exp.expedition_id})">보상 수령</button>`;
                    }
                    html += `</div>`;
                });
            } else {
                html += '<p style="color:#aaa;">현재 파견 보낸 토벌대가 없습니다.</p>';
            }

            // 새 파견 보내기
            html += '<h3 style="color:#81d4fa; margin-top: 30px;">신규 토벌대 파견</h3>';
            html += '<p>대기 중인 영웅을 최대 10명까지 선택하여 파견을 보낼 수 있습니다.</p>';
            html += '<div id="expedition-hero-selection" style="max-height: 200px; overflow-y: auto; border: 1px solid #444; padding: 10px; margin-bottom: 10px;">';
            if (data.available_heroes && data.available_heroes.length > 0) {
                data.available_heroes.forEach(hero => {
                    html += `<div><input type="checkbox" id="hero-${hero.inv_id}" name="expedition_heroes" value="${hero.inv_id}"> <label for="hero-${hero.inv_id}">${hero.hero_name} (수량: ${hero.quantity})</label></div>`;
                });
            } else {
                html += '<p style="color:#aaa;">파견 가능한 영웅이 없습니다.</p>';
            }
            html += '</div>';

            html += '<strong>파견 시간 선택:</strong> ';
            html += '<input type="radio" name="duration" value="1" id="dur1" checked><label for="dur1">1시간</label> ';
            html += '<input type="radio" name="duration" value="4" id="dur4"><label for="dur4">4시간</label> ';
            html += '<input type="radio" name="duration" value="8" id="dur8"><label for="dur8">8시간</label><br><br>';
            
            html += '<button class="btn" style="width:100%; background:#2196f3;" onclick="startExpedition()">파견 시작</button>';
            html += '</div>';
            listArea.innerHTML = html;
        })
        .catch(error => {
            listArea.innerHTML = `<p style="color:red;">정보를 불러오는 중 오류가 발생했습니다.</p>`;
            console.error('Error:', error);
        });
}

function startExpedition() {
    const selectedHeroes = Array.from(document.querySelectorAll('input[name="expedition_heroes"]:checked')).map(cb => cb.value);
    const duration = document.querySelector('input[name="duration"]:checked').value;

    if (selectedHeroes.length === 0) {
        alert('파견할 영웅을 1명 이상 선택하세요.');
        return;
    }
    if (selectedHeroes.length > 10) {
        alert('최대 10명의 영웅만 파견할 수 있습니다.');
        return;
    }

    const formData = new FormData();
    selectedHeroes.forEach(id => formData.append('hero_ids[]', id));
    formData.append('duration', duration);

    fetch('api.php?action=start_expedition', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('토벌대를 성공적으로 파견했습니다!');
            document.getElementById('expedition-modal').style.display='none';
            toggleEquip(0, -1); // 영웅 목록 UI 갱신
        } else {
            alert('오류: ' + data.msg);
        }
    })
    .catch(error => {
        alert('파견 중 오류가 발생했습니다.');
        console.error('Error:', error);
    });
}

function claimExpeditionReward(expeditionId) {
    const formData = new FormData();
    formData.append('expedition_id', expeditionId);

    fetch('api.php?action=claim_expedition', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert(`보상으로 ${data.reward}G를 획득했습니다!`);
            addLog(data.log);
            const goldDisplay = document.getElementById('gold-display');
            const currentGold = parseInt(goldDisplay.innerText.replace(/,/g, '')) || 0;
            goldDisplay.innerText = (currentGold + data.reward).toLocaleString();
            
            document.getElementById('expedition-modal').style.display='none';
            toggleEquip(0, -1); // 영웅 목록 UI 갱신
        } else {
            alert('오류: ' + data.msg);
        }
    })
    .catch(error => {
        alert('보상 수령 중 오류가 발생했습니다.');
        console.error('Error:', error);
    });
}
    </script>
</body>
</html>