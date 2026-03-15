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
        .stat-value { font-weight: bold; color: #4caf50; }
        .bar-bg { background: #333; border-radius: 4px; width: 100%; height: 16px; margin-bottom: 15px; overflow: hidden; }
        .hp-bar { background: #4caf50; height: 100%; width: <?= ($commander['hp'] / $commander['max_hp']) * 100 ?>%; transition: width 0.3s; }
        .mp-bar { background: #2196f3; height: 100%; width: <?= ($commander['mp'] / $commander['max_mp']) * 100 ?>%; transition: width 0.3s; }
        .mob-hp-bar { background: #ff5252; height: 100%; width: 100%; transition: width 0.3s; }
        .log-container { flex-grow: 1; padding: 15px; overflow-y: auto; font-size: 1.05rem; line-height: 1.6; }
        .log-entry { margin-bottom: 12px; padding: 12px; background: #1a1a1a; border-left: 4px solid #4caf50; border-radius: 4px; animation: fadeIn 0.3s; }
        .log-entry.system { border-left-color: #ffa500; color: #ffeb3b; }
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
        <div style="font-size: 0.85rem; color: #b388ff; margin-bottom: 3px; font-weight: bold;">EXP (<span id="exp-text"><?= $commander['exp'] ?? 0 ?> / <?= ($commander['level'] ?? 1) * 100 ?></span>)</div>
        <div class="bar-bg"><div class="exp-bar" id="exp-bar" style="background: #7c4dff; height: 100%; width: <?= (($commander['exp'] ?? 0) / max(1, ($commander['level'] ?? 1) * 100)) * 100 ?>%; transition: width 0.3s;"></div></div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding:8px; background:#1a1a1a; border-radius:4px; border:1px solid #333;">
            <span style="color:#aaa; font-size:0.9rem;">🚩 최고 도달</span>
            <span style="color:#fff; font-weight:bold;"><?= $commander['max_floor'] ?? 1 ?>층</span>
        </div>

        <div style="margin-bottom: 25px; padding: 10px; background: #222; border-radius: 4px; color: #ffd700; font-weight: bold; text-align: center;">💰 골드: <span id="gold-display"><?= number_format($commander['gold']) ?></span> G</div>
        
        <h3 style="color: #ccc; font-size: 1rem; border-bottom: 1px solid #333; padding-bottom: 5px; display:flex; justify-content:space-between;">
            <span>📊 사령관 스탯</span> <span style="font-size:0.8rem; color:#ff9800;">POINT: <span id="stat-points"><?= $commander['stat_points'] ?? 0 ?></span></span>
        </h3>
        <div class="stat-box"><span class="stat-name">힘 (STR)</span> <div><span class="stat-value" id="val-str"><?= $commander['stat_str'] ?></span> <button class="btn-stat-up" data-stat="str">+</button></div></div>
        <div class="stat-box"><span class="stat-name">마력 (MAG)</span> <div><span class="stat-value" id="val-mag"><?= $commander['stat_mag'] ?></span> <button class="btn-stat-up" data-stat="mag">+</button></div></div>
        <div class="stat-box"><span class="stat-name">민첩 (AGI)</span> <div><span class="stat-value" id="val-agi"><?= $commander['stat_agi'] ?></span> <button class="btn-stat-up" data-stat="agi">+</button></div></div>
        <div class="stat-box"><span class="stat-name">행운 (LUK)</span> <div><span class="stat-value" id="val-luk"><?= $commander['stat_luk'] ?></span> <button class="btn-stat-up" data-stat="luk">+</button></div></div>
        <div class="stat-box"><span class="stat-name">정신력 (MEN)</span> <div><span class="stat-value" id="val-men"><?= $commander['stat_men'] ?></span> <button class="btn-stat-up" data-stat="men">+</button></div></div>
        <div class="stat-box"><span class="stat-name">체력 (VIT)</span> <div><span class="stat-value" id="val-vit"><?= $commander['stat_vit'] ?></span> <button class="btn-stat-up" data-stat="vit">+</button></div></div>
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
            <div class="btn" onclick="openBookModal()" style="padding: 10px; font-size: 0.9rem;">영웅 도감 📜</div>
            <div class="btn" onclick="openHeroLevelupModal()" style="padding: 10px; font-size: 0.9rem; background:#3f51b5;">영웅 제단 ⛩️</div>
            <div class="btn" onclick="openRelicModal()" style="padding: 10px; font-size: 0.9rem; background:#6d4c41;">유물 제련 🗿</div>
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
            <div>
                <span style="color: #aaa; font-size: 0.9rem;">전투 모드:</span>
                <label class="switch"><input type="checkbox" id="auto-combat-toggle" onchange="toggleAutoMode()"><span class="slider"></span></label>
                <span id="auto-status-text" style="color: #4caf50; font-weight: bold; font-size: 0.9rem;">[수동]</span>
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
            <div class="btn btn-summon" onclick="sendAction('summon')">영웅 소환 ✨</div>
            <div class="btn" style="background:#673ab7;" onclick="openCombineModal()">조합/진화 🔮</div>
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
            <div class="btn" style="background: #9c27b0;" onclick="useSkill('shield_up')">🛡️ 방어강화<br><span style='font-size:0.7rem;'>(MP 20)</span></div>
            <div class="btn" style="background: #d32f2f;" onclick="useSkill('berserk')">💥 광폭화<br><span style='font-size:0.7rem;'>(MP 35)</span></div>
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

    <div class="panel right-panel">
        <h2>⚔️ 출전 덱 (<span id="deck-count-display">0</span>/5)</h2>
        <div id="deck-list"></div>
        <h2>🎒 보유 영웅</h2>
        <div id="hero-list"></div>
        <div id="expedition-section" style="margin-top: 20px; background: #222; padding: 15px; border-radius: 5px;">
            <h3 style="color: #ccc; font-size: 1rem; margin:0 0 10px 0;">⚔️ 영웅 토벌대 파견</h3>
            <p style="font-size: 0.9rem; color: #aaa; margin-bottom: 10px;">
                대기 중인 영웅을 파견하여 추가 보상을 획득하세요.
            </p>
            <button id="btn-open-expedition-modal" class="btn" style="width:100%; background-color: #4a6;" onclick="openExpeditionModal()">토벌대 관리</button>
        </div>
    </div>

    <script>
        window.playerMaxHp = <?= (int)$commander['max_hp'] ?>;
        window.playerMaxMp = <?= (int)$commander['max_mp'] ?>;
        window.isDead = <?= ($commander['hp'] <= 0) ? 'true' : 'false' ?>;
        window.isCombat = <?= ($commander['is_combat'] == 1) ? 'true' : 'false' ?>;
        window.currentMobName = <?= json_encode($commander['mob_name'] ?? '') ?>;
        window.currentMobMaxHp = <?= (int)$commander['mob_max_hp'] ?>;
        // 초기 스탯 포인트 버튼 갱신을 위해 DOM 로드 후 실행될 JS에 값 전달
        window.initialStatPoints = <?= (int)($commander['stat_points'] ?? 0) ?>;
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