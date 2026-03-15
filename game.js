// game.js
let isAutoMode = false;
let combatTimer = null;
let isProcessingTurn = false;
let autoExploreTimer = null;

const BATTLE_STAGE = {
    EXPLORE: 'explore',
    ENCOUNTER: 'encounter',
    COMBAT: 'combat',
    DEAD: 'dead'
};

// ==================================
// API 호출 래퍼
// ==================================
async function callApi(action, options = {}) {
    const url = `api.php?action=${action}`;
    try {
        const response = await fetch(url, options);
        if (!response.ok) {
            const errorBody = await response.text();
            console.error(`API '${action}' HTTP ${response.status}:`, errorBody.substring(0, 500));
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (parseErr) {
            console.error(`API '${action}' JSON parse failed. Raw response (first 500 chars):`, text.substring(0, 500));
            console.error('Hex of first 20 bytes:', Array.from(text.substring(0, 20)).map(c => c.charCodeAt(0).toString(16).padStart(2, '0')).join(' '));
            throw parseErr;
        }
    } catch (error) {
        console.error(`API call to action '${action}' failed:`, error);
        addLog(`❌ 통신 오류: ${action} 액션 실패.`, true);
        return null;
    }
}

// ==================================
// UI 갱신 함수들
// ==================================
function updateInventoryUI(data) {
    if (!data) return;
    if (data.deck_html !== undefined) document.getElementById('deck-list').innerHTML = data.deck_html;
    if (data.inv_html !== undefined) document.getElementById('hero-list').innerHTML = data.inv_html;
    if (data.deck_count !== undefined) document.getElementById('deck-count-display').innerText = data.deck_count;
    if (data.new_gold !== undefined) document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
}

function updateStatUI(points) {
    document.getElementById('stat-points').innerText = points;
    const buttons = document.querySelectorAll('.btn-stat-up');
    buttons.forEach(btn => btn.style.display = (points > 0) ? 'inline-block' : 'none');
}

function updatePlayerBars(hp, max_hp, mp, max_mp) {
    // 서버 응답에 값이 누락되면 기존 값으로 보정하여 undefined 표기를 방지
    hp = (hp !== undefined && hp !== null) ? hp : 0;
    max_hp = (max_hp !== undefined && max_hp !== null) ? max_hp : (window.playerMaxHp || 1);
    mp = (mp !== undefined && mp !== null) ? mp : 0;
    max_mp = (max_mp !== undefined && max_mp !== null) ? max_mp : (window.playerMaxMp || 1);

    window.playerMaxHp = max_hp; // 전역 변수 갱신
    window.playerMaxMp = max_mp;
    document.getElementById('player-hp-text').innerText = `HP (${hp} / ${max_hp})`;
    document.getElementById('player-hp-bar').style.width = (hp / max_hp * 100) + '%';
    document.getElementById('player-mp-text').innerText = `MP (${mp} / ${max_mp})`;
    document.getElementById('player-mp-bar').style.width = (mp / max_mp * 100) + '%';
}

function updateExpBar(level, exp) {
    const maxExp = level * 100;
    document.getElementById('level-display').innerText = level;
    document.getElementById('exp-text').innerText = `${exp} / ${maxExp}`;
    document.getElementById('exp-bar').style.width = (exp / maxExp * 100) + '%';
}

function addLog(message, isSystem = false) {
    const logBox = document.getElementById('game-log');
    const newLog = document.createElement('div');
    newLog.className = 'log-entry' + (isSystem ? ' system' : '');
    newLog.innerHTML = message;
    logBox.appendChild(newLog);
    logBox.scrollTop = logBox.scrollHeight;
}

function setBattleStage(stage, mobName = '', mobMaxHp = 0) {
    window.battleStage = stage;
    localStorage.setItem('ld_battle_stage', stage);

    const show = (id, visible) => {
        const el = document.getElementById(id);
        if (el) el.style.display = visible ? (id.includes('actions') ? 'grid' : 'block') : 'none';
    };

    if (mobName) window.currentMobName = mobName;
    if (mobMaxHp) window.currentMobMaxHp = mobMaxHp;

    if (stage === BATTLE_STAGE.DEAD) {
        show('dead-actions', true);
        show('explore-actions', false);
        show('encounter-actions', false);
        show('combat-actions', false);
        show('monster-ui', false);
        return;
    }

    if (stage === BATTLE_STAGE.EXPLORE) {
        show('dead-actions', false);
        show('encounter-actions', false);
        show('combat-actions', false);
        show('monster-ui', false);
        if (document.getElementById('btn-start-auto-explore').style.display !== 'none') {
            show('explore-actions', true);
        }
        return;
    }

    if (stage === BATTLE_STAGE.ENCOUNTER) {
        show('dead-actions', false);
        show('explore-actions', false);
        show('encounter-actions', true);
        show('combat-actions', false);
        show('monster-ui', true);
        document.getElementById('mob-name-display').innerText = window.currentMobName || '몬스터';
        document.getElementById('mob-hp-text').innerText = `${window.currentMobMaxHp}/${window.currentMobMaxHp}`;
        document.getElementById('mob-hp-bar').style.width = '100%';
        return;
    }

    if (stage === BATTLE_STAGE.COMBAT) {
        show('dead-actions', false);
        show('explore-actions', false);
        show('encounter-actions', false);
        show('combat-actions', true);
        show('monster-ui', true);
        document.getElementById('mob-name-display').innerText = window.currentMobName || '몬스터';
        if (window.currentMobMaxHp > 0) {
            document.getElementById('mob-hp-text').innerText = `${window.currentMobMaxHp}/${window.currentMobMaxHp}`;
            document.getElementById('mob-hp-bar').style.width = '100%';
        }
    }
}

// ==================================
// 게임 상태 관리
// ==================================
const wait = (ms) => new Promise(resolve => setTimeout(resolve, ms));

function enterDeadState() {
    window.isDead = true;
    window.isCombat = false;
    clearCombatTimer();
    setBattleStage(BATTLE_STAGE.DEAD);
    addLog('<h2 style="color:red;">☠️ 사망하였습니다.</h2>모든 행동이 불가능합니다. 여신의 축복을 받으세요.', true);
}

function exitToExploreState() {
    window.isCombat = false;
    clearCombatTimer();
    document.body.classList.remove('boss-bg');
    setBattleStage(BATTLE_STAGE.EXPLORE);
}

function enterEncounterState(mobName, mobMaxHp) {
    window.isCombat = true;
    setBattleStage(BATTLE_STAGE.ENCOUNTER, mobName, mobMaxHp);
    if (mobName.includes('[보스]')) {
        document.body.classList.add('boss-bg');
        addLog(`<span style="color:red; font-size:1.1rem; font-weight:bold; text-shadow:0 0 5px red;">⚠️ 경고: 강력한 보스의 살기가 느껴집니다!</span>`);
    }
}

function enterCombatState(mobName, mobMaxHp) {
    window.isCombat = true;
    setBattleStage(BATTLE_STAGE.COMBAT, mobName, mobMaxHp);
    if (isAutoMode) doCombatTurn();
}

function clearCombatTimer() {
    if (combatTimer) { clearTimeout(combatTimer); combatTimer = null; }
    isProcessingTurn = false;
}

function toggleAutoMode() {
    isAutoMode = document.getElementById('auto-combat-toggle').checked;
    document.getElementById('auto-status-text').innerText = isAutoMode ? '[자동]' : '[수동]';
    if(isAutoMode && window.isCombat && !isProcessingTurn) doCombatTurn();
}

// ==================================
// 액션 핸들러
// ==================================
async function upStat(type, amount = 1) {
    const formData = new URLSearchParams();
    formData.append('stat_type', type);
    formData.append('amount', amount);
    const data = await callApi('stat_up', { method: 'POST', body: formData });
    
    if (data && data.status === 'success') {
        // 스탯 수치 갱신
        document.getElementById(`val-${data.stat_type}`).innerText = data.new_val;
        updateStatUI(data.new_points);
        
        // 🚨 새로 추가: VIT, MEN 상승으로 최대 HP/MP가 변했을 때 UI 즉시 반영
        if (data.new_max_hp !== undefined) {
            updatePlayerBars(data.new_hp, data.new_max_hp, data.new_mp, data.new_max_mp);
            
            // 시각적 이펙트 (피가 차오르는 느낌)
            const centerPanel = document.querySelector('.center-panel');
            centerPanel.classList.remove('heal-flash');
            void centerPanel.offsetWidth;
            centerPanel.classList.add('heal-flash');
        }
        
        if (data.msg) addLog(data.msg, true);
    } else if (data) {
        alert(data.msg);
    }
}

async function doCombatTurn() {
    if (isProcessingTurn || !window.isCombat || window.battleStage !== BATTLE_STAGE.COMBAT) return;
    isProcessingTurn = true;
    const data = await callApi('combat');
    if (data) {
        if (data.status === 'error') {
            if (data.msg && data.msg.includes('대치')) {
                enterEncounterState(window.currentMobName || '정체불명의 적', window.currentMobMaxHp || 1);
                addLog(data.msg, true);
            } else {
                exitToExploreState();
            }
            isProcessingTurn = false;
        } else {
            updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
            document.getElementById('mob-hp-text').innerText = `${data.mob_hp}/${data.mob_max_hp}`;
            document.getElementById('mob-hp-bar').style.width = (data.mob_hp / data.mob_max_hp * 100) + '%';
            if (data.new_level) updateExpBar(data.new_level, data.new_exp);
            if (data.stat_points !== undefined) updateStatUI(data.stat_points);

                if (data.stream) {
                    const logBox = document.getElementById('game-log');
                    const streamLog = document.createElement('div');
                    streamLog.className = 'log-entry system';
                    streamLog.innerHTML = "<span style='color:#ff5252; font-weight:bold;'>[전투 상황 ✨]</span><br><span class='stream-text'></span>";
                    logBox.appendChild(streamLog);
                    logBox.scrollTop = logBox.scrollHeight;
                    const textSpan = streamLog.querySelector('.stream-text');

                    const source = new EventSource('api.php?action=stream_ai');
                    source.onmessage = function(event) {
                        if (event.data === '[DONE]') {
                            source.close();
                            isProcessingTurn = false;
                            if (data.status === 'victory') { exitToExploreState(); toggleEquip(0, -1); } 
                            else if (data.status === 'defeat') { enterDeadState(); } 
                            else if (isAutoMode) { combatTimer = setTimeout(doCombatTurn, 1000); }
                            return;
                        }
                        const chunk = JSON.parse(event.data);
                        if (chunk.text) textSpan.innerHTML += chunk.text;
                        if (chunk.log_append) streamLog.innerHTML += "<br>" + chunk.log_append;
                        logBox.scrollTop = logBox.scrollHeight;
                    };
                    source.onerror = function() {
                        source.close();
                        isProcessingTurn = false;
                    };
                    return; // SSE가 끝날 때까지 대기
                } else {
                    if (Array.isArray(data.logs)) {
                        for (const log of data.logs) addLog(log);
                    }
                    if (data.status === 'victory') { exitToExploreState(); toggleEquip(0, -1); } 
                    else if (data.status === 'defeat') { enterDeadState(); } 
                    else if (isAutoMode) { combatTimer = setTimeout(doCombatTurn, 1000); }
                }
        }
    }
        if (!data || !data.stream) isProcessingTurn = false;
}

async function attemptFlee() {
    const data = await callApi('flee');
    if (data) {
        addLog(data.log);
        if (data.status === 'success') exitToExploreState();
        else if (data.new_hp <= 0) enterDeadState();
        else enterCombatState(window.currentMobName, window.currentMobMaxHp);
    }
}

function startCombat() {
    addLog(`⚔️ <b>[${window.currentMobName}]</b>와 전투 시작!`);
    enterCombatState(window.currentMobName, window.currentMobMaxHp);
}

// game.js 내부 sendAction 함수 교체
async function sendAction(actionType) {
    // 🚨 중복 클릭 방지: 통신 중에는 모든 버튼 잠금
    const btns = document.querySelectorAll('.action-container .btn');
    btns.forEach(b => b.style.pointerEvents = 'none');

    let isStreamInProgress = false;

    try {
        // 🚨 즉각적인 피드백 로그 (서버 응답을 기다리는 동안 유저가 지루하지 않게 함)
        if (actionType === 'action') {
            addLog('🐾 어두운 미궁 속으로 발걸음을 옮깁니다... (AI 묘사 대기 중)', true);
        } else if (actionType === 'rest') {
            addLog('🔥 모닥불을 피울 안전한 곳을 찾습니다... (AI 묘사 대기 중)', true);
        } else if (actionType === 'summon') {
            addLog('✨ 차원의 틈새를 엽니다...', true);
        }

        const data = await callApi(actionType, {method: 'POST'});
        
        if(data) {
            if (actionType === 'action') {
                if (data.story_event) showStoryModal(data.story_event);
                if (data.status === 'encounter' || data.status === 'safe') {
                    document.getElementById('floor-display').innerText = data.new_floor;
                    if (data.status === 'safe') {
                        updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
                    }
                    if (data.stream) {
                        isStreamInProgress = true;
                        const logBox = document.getElementById('game-log');
                        const lastLog = logBox.lastElementChild;
                        if (lastLog && lastLog.innerText.includes('AI 묘사 대기 중')) logBox.removeChild(lastLog);

                        const streamLog = document.createElement('div');
                        streamLog.className = 'log-entry system';
                        streamLog.innerHTML = "<span style='color:#00d8ff; font-weight:bold;'>[Gemini 1.5 Flash ✨]</span><br><span class='stream-text'></span>";
                        logBox.appendChild(streamLog);
                        logBox.scrollTop = logBox.scrollHeight;
                        const textSpan = streamLog.querySelector('.stream-text');

                        const source = new EventSource('api.php?action=stream_ai');
                        source.onmessage = function(event) {
                            if (event.data === '[DONE]') {
                                source.close();
                                if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                                btns.forEach(b => b.style.pointerEvents = 'auto');
                                return;
                            }
                            const chunk = JSON.parse(event.data);
                            if (chunk.text) textSpan.innerHTML += chunk.text;
                            if (chunk.log_append) streamLog.innerHTML += "<br>" + chunk.log_append;
                            logBox.scrollTop = logBox.scrollHeight;
                        };
                        source.onerror = function() {
                            source.close();
                            if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                            btns.forEach(b => b.style.pointerEvents = 'auto');
                        };
                    } else {
                        if (data.log) addLog(data.log);
                        if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                    }
                } else if (data.status === 'error') {
                    addLog(data.msg, true);
                }
            } else if (actionType === 'rest' || actionType === 'summon') {
                 if(data.status === 'success') {
                    if (data.logs) data.logs.forEach(l => addLog(l));
                    else if(data.log) addLog(data.log); // 휴식 로그 출력
                    else if(data.msg) addLog(data.msg);
                    
                    if (actionType === 'summon') updateInventoryUI(data);
                    else { // 휴식 성공 시 바 갱신
                        updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
                        document.getElementById('gold-display').innerText = data.new_gold.toLocaleString();
                    }
                } else {
                    addLog(data.msg, true);
                 }
            }
        }
    } catch (e) {
        console.error("sendAction Error:", e);
        addLog("❌ 클라이언트 오류가 발생했습니다.", true);
    } finally {
        if (!isStreamInProgress) {
            setTimeout(() => {
                btns.forEach(b => b.style.pointerEvents = 'auto');
            }, 300);
        }
    }
}

async function revive() {
    const data = await callApi('restart', {method: 'POST'});
    if (data && data.status === 'success') {
        window.isDead = false;
        addLog(data.log, true);
        document.getElementById('floor-display').innerText = '1';
        updatePlayerBars(data.max_hp, data.max_hp, data.max_mp, data.max_mp);
        exitToExploreState();
        toggleEquip(0, -1);
    }
}

async function useSkill(skillId) {
    if (isProcessingTurn) { addLog('⏳ 턴을 기다리는 중입니다.'); return; }
    isProcessingTurn = true;
    const formData = new URLSearchParams();
    formData.append('skill_id', skillId);
    const data = await callApi('skill', { method: 'POST', body: formData });
    if(data) {
        if (data.status === 'error') { addLog('❌ ' + data.msg, true); }
        else {
            if (Array.isArray(data.logs)) {
                for (const log of data.logs) {
                    addLog(log); await wait(400);
                }
            }
            updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
            if (data.mob_hp !== undefined) {
                document.getElementById('mob-hp-text').innerText = `${data.mob_hp}/${window.currentMobMaxHp}`;
                document.getElementById('mob-hp-bar').style.width = (data.mob_hp / window.currentMobMaxHp * 100) + '%';
            }
            if (data.mob_hp <= 0) {
                addLog('💫 승리했습니다!'); await wait(1000); exitToExploreState(); toggleEquip(0, -1);
            } else if (isAutoMode) { doCombatTurn(); }
        }
    }
    isProcessingTurn = false;
}

async function toggleEquip(invId, action) {
    const formData = new URLSearchParams();
    formData.append('inv_id', invId);
    formData.append('action', action);
    const data = await callApi('equip', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        updateInventoryUI(data);
        if(data.msg) addLog(data.msg);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function synthesizeHero(heroName) {
    if (!confirm(`${heroName}을(를) 3마리 합성하시겠습니까?`)) return;
    const formData = new URLSearchParams();
    formData.append('hero_name', heroName);
    const data = await callApi('synthesize', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        addLog(data.msg);
        updateInventoryUI(data);
        showObtainEffect(data.new_rank, data.new_hero_name);
    } else if (data) {
        addLog('❌ 합성 실패: ' + data.msg, true);
    }
}

async function combineHero(mode, targetName) {
    const formData = new URLSearchParams(); 
    formData.append('mode', mode); 
    formData.append('target_name', targetName);
    const data = await callApi('combine', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        document.getElementById('combine-list-area').innerHTML = data.html;
        if(data.msg) {
            addLog(data.msg);
            toggleEquip(0, -1);
            if (data.new_rank && data.new_name) {
                const finalName = (mode === 'evolve' && data.new_name !== targetName) ? data.new_name : targetName;
                showObtainEffect(data.new_rank, finalName);
            }
        }
    } else if (data) {
        alert(data.msg);
    }
}

function showObtainEffect(rank, name) {
    const overlay = document.getElementById('obtain-overlay');
    const rankText = document.getElementById('eff-rank');
    const nameText = document.getElementById('eff-name');
    overlay.style.display = 'flex';
    rankText.innerText = `[ ${rank} ]`;
    nameText.innerText = name;
    setTimeout(() => { overlay.style.display = 'none'; }, 4000);
}

async function openModal(modalId, listAreaId, action) {
    document.getElementById(modalId).style.display = 'flex';
    document.getElementById(listAreaId).innerHTML = '불러오는 중...';
    const data = await callApi(action);
    if(data && data.status === 'success') {
        document.getElementById(listAreaId).innerHTML = data.html;
    }
}
const openRanking = () => openModal('ranking-modal', 'ranking-list-area', 'ranking');
const openBookModal = () => openModal('book-modal', 'book-list-area', 'book');
const openHeroLevelupModal = () => openModal('hero-levelup-modal', 'hero-levelup-list-area', 'hero_levelup_view');
const openRelicModal = () => openModal('relic-modal', 'relic-list-area', 'relic_info');
const openCombineModal = () => {
    document.getElementById('combine-modal').style.display = 'flex';
    document.getElementById('combine-list-area').innerHTML = '불러오는 중...';
    combineHero('view', '');
};

async function levelUpHero(invId) {
    const formData = new URLSearchParams();
    formData.append('inv_id', invId);
    const data = await callApi('hero_levelup', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        addLog(data.msg, true);
        document.getElementById('hero-levelup-list-area').innerHTML = data.html;
        document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        toggleEquip(0, -1);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function upgradeRelic() {
    const data = await callApi('relic_upgrade', { method: 'POST' });
    if (data && data.status === 'success') {
        addLog(data.msg, true);
        document.getElementById('relic-list-area').innerHTML = data.html;
        document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
    } else if (data) {
        addLog(data.msg, true);
    }
}

function showStoryModal(storyData) {
    document.getElementById('story-title').innerText = storyData.story_title;
    const contentDiv = document.getElementById('story-content');
    contentDiv.innerHTML = '<span style="color:#aaa;">고대의 기록을 해독하는 중...</span>';
    document.getElementById('story-modal').style.display = 'flex';

    const source = new EventSource('api.php?action=stream_story_ai');
    let isFirstChunk = true;
    source.onmessage = function(event) {
        if (event.data === '[DONE]') {
            source.close();
            return;
        }
        if (isFirstChunk) { contentDiv.innerHTML = ''; isFirstChunk = false; }
        const chunk = JSON.parse(event.data);
        if (chunk.text) contentDiv.innerHTML += chunk.text;
    };
    source.onerror = function() {
        source.close();
        if (isFirstChunk) contentDiv.innerHTML = '스토리를 불러오지 못했습니다.';
    };
}

// ==================================
// 자동 탐험
// ==================================
async function startAutoExplore() {
    const data = await callApi('auto_explore_start', { method: 'POST' });
    if (data && data.status === 'success') {
        addLog(data.msg, true);
        updateAutoExploreUI(true);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function claimAutoExplore() {
    const data = await callApi('auto_explore_claim', { method: 'POST' });
    if (data && data.status === 'success') {
        addLog(data.log, true);
        updateAutoExploreUI(false);
        toggleEquip(0,-1); 
    } else if(data) {
        addLog(data.msg, true);
    }
}

function updateAutoExploreUI(isExploring, data) {
    const statusDisplay = document.getElementById('auto-explore-status-display');
    const startBtn = document.getElementById('btn-start-auto-explore');
    const claimBtn = document.getElementById('btn-claim-auto-explore');
    const exploreActions = document.getElementById('explore-actions');

    if (isExploring) {
        statusDisplay.style.display = 'block';
        startBtn.style.display = 'none';
        claimBtn.style.display = 'block';
        exploreActions.style.display = 'none';
        if (data) {
            document.getElementById('auto-explore-timer').innerText = `${data.elapsed_minutes}분`;
            document.getElementById('auto-explore-gold').innerText = data.rewards.gold.toLocaleString();
            document.getElementById('auto-explore-exp').innerText = data.rewards.exp.toLocaleString();
        }
    } else {
        statusDisplay.style.display = 'none';
        startBtn.style.display = 'block';
        claimBtn.style.display = 'none';
        if(!window.isDead && !window.isCombat) {
            exploreActions.style.display = 'grid';
        }
    }
}

async function checkAutoExploreStatus() {
    const data = await callApi('auto_explore_status');
    if(data) {
        updateAutoExploreUI(data.status === 'exploring', data);
    }
}

function setupStatButtons() {
    const buttons = document.querySelectorAll('.btn-stat-up');
    buttons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const statType = e.target.getAttribute('data-stat');
            upStat(statType, 1); // 클릭 시 1포인트씩 API로 전송!
        });
    });
}

// ==================================
// 초기화
// ==================================
window.addEventListener('DOMContentLoaded', () => { 
    window.playerMaxHp = window.playerMaxHp || 0; // index.php 초기값 사용
    window.playerMaxMp = window.playerMaxMp || 0;

    // 전투 모드 기본값: 수동
    const autoToggle = document.getElementById('auto-combat-toggle');
    if (autoToggle) {
        autoToggle.checked = false;
    }
    toggleAutoMode();

    toggleEquip(0, -1); 
    setupStatButtons();
    if (window.initialStatPoints !== undefined) updateStatUI(window.initialStatPoints);
    if (window.isDead) enterDeadState(); 
    else if (window.isCombat) {
        // 새로고침 시 마지막 전투 단계를 복원(기본은 대치)
        const savedStage = localStorage.getItem('ld_battle_stage');
        if (savedStage === BATTLE_STAGE.COMBAT) enterCombatState(window.currentMobName || '정체불명의 적', window.currentMobMaxHp || 1);
        else enterEncounterState(window.currentMobName || '정체불명의 적', window.currentMobMaxHp || 1);
    } else {
        setBattleStage(BATTLE_STAGE.EXPLORE);
    }
    
    checkAutoExploreStatus();
    autoExploreTimer = setInterval(checkAutoExploreStatus, 5000);
});