// game.js
let isAutoMode = false;
let isAutoExploreMode = false;
let isAutoRestMode = false;
let combatTimer = null;
let autoActionTimer = null;
let isProcessingTurn = false;
let isProcessingAction = false;
let autoExploreTimer = null;
const MONSTER_HP_STEP_DELAY = 150;
const AUTO_ACTION_DELAY = 450;
const AUTO_REST_HP_THRESHOLD = 0.45;
const AUTO_REST_MP_THRESHOLD = 0.35;

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
    if (data.deck_synergy_html !== undefined) {
        const panel = document.getElementById('deck-synergy-panel');
        if (panel) panel.innerHTML = data.deck_synergy_html;
    }
    if (data.inv_html !== undefined) document.getElementById('hero-list').innerHTML = data.inv_html;
    if (data.deck_count !== undefined) document.getElementById('deck-count-display').innerText = data.deck_count;
    if (data.new_gold !== undefined) document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
    applyButtonTooltips();
}

function applyButtonTooltips(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') return;

    const buttons = root.querySelectorAll('.btn, button');
    buttons.forEach((btn) => {
        const customTooltip = btn.getAttribute('data-tooltip');
        if (customTooltip && customTooltip.trim() !== '') {
            btn.setAttribute('title', customTooltip.trim());
            return;
        }

        const existingTitle = btn.getAttribute('title');
        if (existingTitle && existingTitle.trim() !== '') return;

        const label = (btn.innerText || btn.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label) return;
        btn.setAttribute('title', `${label} 실행`);
    });
}

function updateStatUI(points) {
    document.getElementById('stat-points').innerText = points;
    const buttons = document.querySelectorAll('.btn-stat-up');
    buttons.forEach(btn => btn.style.display = (points > 0) ? 'inline-block' : 'none');
    refreshCommanderStatHighlights();
}

function refreshCommanderStatHighlights() {
    const statEls = Array.from(document.querySelectorAll('.stat-value[data-commander-stat]'));
    if (statEls.length === 0) return;

    const values = statEls
        .map((el) => Number(el.innerText || 0))
        .filter((value) => Number.isFinite(value));
    if (values.length === 0) return;

    const maxValue = Math.max(...values);
    const minValue = Math.min(...values);
    statEls.forEach((el) => el.classList.remove('stat-highest', 'stat-lowest'));
    if (maxValue === minValue) return;

    statEls.forEach((el) => {
        const value = Number(el.innerText || 0);
        if (value === maxValue) el.classList.add('stat-highest');
        if (value === minValue) el.classList.add('stat-lowest');
    });
}

function updateMonsterBars(hp, maxHp = window.currentMobMaxHp || 1) {
    const safeMaxHp = Math.max(1, Number(maxHp) || 1);
    const safeHp = Math.max(0, Math.min(Number(hp) || 0, safeMaxHp));

    window.currentMobHp = safeHp;
    window.currentMobMaxHp = safeMaxHp;

    const hpText = document.getElementById('mob-hp-text');
    const hpBar = document.getElementById('mob-hp-bar');
    if (hpText) hpText.innerText = `${safeHp}/${safeMaxHp}`;
    if (hpBar) hpBar.style.width = (safeHp / safeMaxHp * 100) + '%';
}

function updatePlayerBars(hp, max_hp, mp, max_mp) {
    // 서버 응답에 값이 누락되면 기존 값으로 보정하여 undefined 표기를 방지
    hp = (hp !== undefined && hp !== null) ? hp : 0;
    max_hp = (max_hp !== undefined && max_hp !== null) ? max_hp : (window.playerMaxHp || 1);
    mp = (mp !== undefined && mp !== null) ? mp : 0;
    max_mp = (max_mp !== undefined && max_mp !== null) ? max_mp : (window.playerMaxMp || 1);

    window.playerMaxHp = max_hp; // 전역 변수 갱신
    window.playerMaxMp = max_mp;
    window.playerCurrentHp = hp;
    window.playerCurrentMp = mp;
    document.getElementById('player-hp-text').innerText = `HP (${hp} / ${max_hp})`;
    document.getElementById('player-hp-bar').style.width = (hp / max_hp * 100) + '%';
    document.getElementById('player-mp-text').innerText = `MP (${mp} / ${max_mp})`;
    document.getElementById('player-mp-bar').style.width = (mp / max_mp * 100) + '%';
}

function getExpToNextByLevel(level) {
    const lv = Math.max(1, Number(level) || 1);
    if (lv >= 1000) return 0;
    let mult = 2120;
    if (lv <= 10) mult = 100;
    else if (lv <= 20) mult = 140;
    else if (lv <= 30) mult = 190;
    else if (lv <= 40) mult = 250;
    else if (lv <= 50) mult = 320;
    else if (lv <= 100) mult = 420;
    else if (lv <= 200) mult = 520;
    else if (lv <= 300) mult = 680;
    else if (lv <= 400) mult = 860;
    else if (lv <= 500) mult = 1060;
    else if (lv <= 650) mult = 1280;
    else if (lv <= 800) mult = 1530;
    else if (lv <= 900) mult = 1810;
    return Math.floor(mult * lv);
}

function updateExpBar(level, exp, expToNext) {
    const maxExp = (expToNext !== undefined && expToNext !== null) ? Number(expToNext) : getExpToNextByLevel(level);
    const safeMax = Math.max(1, Number(maxExp) || 1);
    const safeExp = Math.max(0, Number(exp) || 0);
    document.getElementById('level-display').innerText = level;
    document.getElementById('exp-text').innerText = `${safeExp} / ${safeMax}`;
    document.getElementById('exp-bar').style.width = (safeExp / safeMax * 100) + '%';
}

function pulseUiElement(element, glowColor = 'rgba(255, 255, 255, 0.45)') {
    if (!element) return;

    const prev = {
        transition: element.style.transition,
        transform: element.style.transform,
        textShadow: element.style.textShadow,
        boxShadow: element.style.boxShadow,
    };

    element.style.transition = 'transform 0.22s ease, text-shadow 0.22s ease, box-shadow 0.22s ease';
    element.style.transform = 'scale(1.06)';
    element.style.textShadow = `0 0 12px ${glowColor}`;
    element.style.boxShadow = `0 0 16px ${glowColor}`;

    setTimeout(() => {
        element.style.transform = prev.transform;
        element.style.textShadow = prev.textShadow;
        element.style.boxShadow = prev.boxShadow;
        element.style.transition = prev.transition;
    }, 240);
}

function ensureExploreToastLayer() {
    let layer = document.getElementById('explore-toast-layer');
    if (layer) return layer;

    layer = document.createElement('div');
    layer.id = 'explore-toast-layer';
    Object.assign(layer.style, {
        position: 'fixed',
        top: '14px',
        right: '14px',
        zIndex: '2300',
        display: 'flex',
        flexDirection: 'column',
        gap: '10px',
        width: 'min(360px, calc(100vw - 28px))',
        pointerEvents: 'none'
    });
    document.body.appendChild(layer);
    return layer;
}

function showExploreToast({ icon = '✨', title = '탐색 결과', value = '', detail = '', borderColor = '#90caf9', gradient = 'linear-gradient(120deg, rgba(15,22,34,0.95), rgba(18,27,44,0.92))' }) {
    const layer = ensureExploreToastLayer();
    const card = document.createElement('div');
    Object.assign(card.style, {
        border: `1px solid ${borderColor}`,
        borderLeft: `6px solid ${borderColor}`,
        borderRadius: '10px',
        padding: '10px 12px',
        background: gradient,
        color: '#eceff1',
        boxShadow: '0 10px 20px rgba(0,0,0,0.38)',
        opacity: '0',
        transform: 'translateY(-10px) scale(0.98)',
        transition: 'opacity 0.2s ease, transform 0.2s ease'
    });

    card.innerHTML = `
        <div style="font-size:0.8rem; color:#b0bec5; font-weight:bold; letter-spacing:0.4px;">${icon} ${title}</div>
        <div style="font-size:1.15rem; color:#ffffff; font-weight:bold; margin-top:3px;">${value}</div>
        ${detail ? `<div style="font-size:0.82rem; color:#cfd8dc; margin-top:2px;">${detail}</div>` : ''}
    `;

    layer.appendChild(card);
    requestAnimationFrame(() => {
        card.style.opacity = '1';
        card.style.transform = 'translateY(0) scale(1)';
    });

    setTimeout(() => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(-8px) scale(0.98)';
    }, 2100);
    setTimeout(() => {
        if (card.parentNode) card.parentNode.removeChild(card);
    }, 2400);
}

function renderExploreOutcomeHighlights(data, hpBeforeAction) {
    if (!data || data.status !== 'safe') return;

    const eventType = String(data.event_type || '').toLowerCase();
    const goldGain = Math.max(0, Number(data.reward_gold || 0));
    const expGain = Math.max(0, Number(data.reward_exp || 0));
    const trapDamageFromApi = Math.max(0, Number(data.trap_damage || 0));
    const hpLossByDiff = Math.max(0, Number(hpBeforeAction || 0) - Number(data.new_hp || hpBeforeAction || 0));
    const trapDamage = Math.max(trapDamageFromApi, hpLossByDiff);

    if ((eventType === 'gold' || eventType === 'chest') && goldGain > 0) {
        showExploreToast({
            icon: '💰',
            title: '골드 발견',
            value: `+${goldGain.toLocaleString()} G`,
            detail: '획득 골드가 즉시 반영되었습니다.',
            borderColor: '#ffd54f',
            gradient: 'linear-gradient(120deg, rgba(70,50,12,0.96), rgba(32,24,10,0.94))'
        });
    }

    if (eventType === 'exp' && expGain > 0) {
        showExploreToast({
            icon: '📘',
            title: '경험치 획득',
            value: `+${expGain.toLocaleString()} EXP`,
            detail: 'EXP 바와 레벨업 연출이 즉시 갱신됩니다.',
            borderColor: '#b388ff',
            gradient: 'linear-gradient(120deg, rgba(36,18,68,0.95), rgba(18,10,32,0.94))'
        });
    }

    if (eventType === 'trap') {
        if (trapDamage > 0) {
            showExploreToast({
                icon: '🩸',
                title: '함정 발동',
                value: `-${trapDamage.toLocaleString()} HP`,
                detail: '함정 피해가 즉시 적용되었습니다.',
                borderColor: '#ef9a9a',
                gradient: 'linear-gradient(120deg, rgba(84,18,18,0.96), rgba(34,9,9,0.94))'
            });
        } else if (goldGain > 0) {
            showExploreToast({
                icon: '🍀',
                title: '함정 회피 성공',
                value: `+${goldGain.toLocaleString()} G`,
                detail: '함정을 피하고 보상을 획득했습니다.',
                borderColor: '#aed581',
                gradient: 'linear-gradient(120deg, rgba(26,62,34,0.96), rgba(12,30,17,0.94))'
            });
        } else {
            showExploreToast({
                icon: '🛡️',
                title: '함정 무효',
                value: '피해 없음',
                detail: '함정 피해를 완전히 막아냈습니다.',
                borderColor: '#80cbc4',
                gradient: 'linear-gradient(120deg, rgba(20,56,58,0.96), rgba(10,28,32,0.94))'
            });
        }
    }
}

function parseFloorNumber(value) {
    const numeric = Number(value);
    if (Number.isFinite(numeric)) return Math.max(0, Math.floor(numeric));
    const match = String(value || '').match(/\d+/);
    return match ? Number(match[0]) : 0;
}

function getCurrentFloorNumber() {
    const floorEl = document.getElementById('floor-display');
    return parseFloorNumber(floorEl ? floorEl.innerText : 0);
}

function isBossPreFloor(floor = getCurrentFloorNumber()) {
    const safeFloor = Math.max(0, Number(floor) || 0);
    return safeFloor > 0 && (safeFloor % 10) === 9;
}

function updateFloorDisplay(newFloor, newMaxFloor) {
    const floorEl = document.getElementById('floor-display');
    const maxFloorEl = document.getElementById('max-floor-display');
    const parsedNewFloor = parseFloorNumber(newFloor);

    if (floorEl && parsedNewFloor > 0) {
        floorEl.innerText = String(parsedNewFloor);
        if (isAutoExploreMode && isBossPreFloor(parsedNewFloor)) {
            disableAutoExploreMode(`⛔ ${parsedNewFloor}층은 보스 전층입니다. 자동 탐색이 중지되었습니다. 보스전에 앞서 수동으로 진행해 주세요.`);
        }
    }

    if (!maxFloorEl) return;

    const currentMax = parseFloorNumber(maxFloorEl.innerText);
    const candidateMax = (newMaxFloor !== undefined && newMaxFloor !== null)
        ? parseFloorNumber(newMaxFloor)
        : parsedNewFloor;
    const nextMax = Math.max(currentMax, candidateMax);

    if (nextMax > 0) {
        maxFloorEl.innerText = `${nextMax}층`;
    }
}

function applyRewardUi(data) {
    if (!data) return;

    const prevLevel = Number(document.getElementById('level-display').innerText || 1);

    if (data.new_gold !== undefined) {
        const goldEl = document.getElementById('gold-display');
        goldEl.innerText = Number(data.new_gold).toLocaleString();
        if (Number(data.reward_gold || 0) > 0) {
            pulseUiElement(goldEl, 'rgba(255, 213, 79, 0.72)');
        }
    }

    if (data.new_level !== undefined && data.new_exp !== undefined) {
        updateExpBar(data.new_level, data.new_exp, data.exp_to_next);
        if (Number(data.reward_exp || 0) > 0) {
            pulseUiElement(document.getElementById('exp-text'), 'rgba(179, 136, 255, 0.72)');
            pulseUiElement(document.getElementById('exp-bar'), 'rgba(179, 136, 255, 0.62)');
        }
        const levelupCount = Number(data.levelup_count || (Array.isArray(data.levelup_logs) ? data.levelup_logs.length : 0));
        if (Number(data.new_level) > prevLevel) {
            showLevelUpEffect(prevLevel, Number(data.new_level), levelupCount || 1);
        }
    }

    if (data.stat_points !== undefined) {
        updateStatUI(data.stat_points);
    }
}

function addLog(message, isSystem = false) {
    const logBox = document.getElementById('game-log');
    const newLog = document.createElement('div');
    newLog.className = 'log-entry' + (isSystem ? ' system' : '');
    newLog.innerHTML = message;
    logBox.appendChild(newLog);
    logBox.scrollTop = logBox.scrollHeight;
}

function getTurnDamageSteps(data) {
    const steps = [];
    const details = (data && Array.isArray(data.turn_damage_details)) ? data.turn_damage_details : [];
    if (details.length > 0) {
        for (const item of details) {
            const name = String((item && item.name) || '영웅');
            const dmg = Number((item && item.damage) || 0);
            steps.push({ text: `${name}의 공격이 ${dmg}의 데미지를 입혔습니다.`, damage: dmg });
        }
        return steps;
    }

    const p = Number((data && data.player_dmg) || 0);
    const h = Number((data && data.hero_dmg) || 0);
    steps.push({ text: `사령관의 공격이 ${p}의 데미지를 입혔습니다.`, damage: p });
    if (h > 0) steps.push({ text: `영웅들의 공격이 ${h}의 데미지를 입혔습니다.`, damage: h });
    return steps;
}

function getTurnDamageLines(data) {
    return getTurnDamageSteps(data).map((step) => step.text);
}

function getTurnScriptSteps(data) {
    const steps = getTurnDamageSteps(data).map((step) => ({
        text: step.text,
        damage: step.damage,
        type: 'outgoing-damage'
    }));

    const incomingDamage = Number((data && data.incoming_damage) || 0);
    const incomingSource = String((data && data.incoming_damage_source) || '적');
    if (incomingDamage > 0) {
        steps.push({
            text: `❗ 피격 경고: ${incomingSource}의 반격으로 사령관이 ${incomingDamage} 데미지를 받았습니다.`,
            damage: incomingDamage,
            type: 'incoming-damage'
        });
    }

    return steps;
}

function toPlainLogText(message) {
    const tmp = document.createElement('div');
    tmp.innerHTML = String(message || '');
    return (tmp.textContent || tmp.innerText || '').trim();
}

function getCurrentPlayerHp() {
    if (window.playerCurrentHp !== undefined && window.playerCurrentHp !== null) {
        return Number(window.playerCurrentHp) || 0;
    }
    const hpText = document.getElementById('player-hp-text');
    const match = hpText ? hpText.innerText.match(/\((\d+)\s*\/\s*\d+\)/) : null;
    return match ? Number(match[1]) : 0;
}

function getCurrentPlayerMp() {
    if (window.playerCurrentMp !== undefined && window.playerCurrentMp !== null) {
        return Number(window.playerCurrentMp) || 0;
    }
    const mpText = document.getElementById('player-mp-text');
    const match = mpText ? mpText.innerText.match(/\((\d+)\s*\/\s*\d+\)/) : null;
    return match ? Number(match[1]) : 0;
}

function triggerIncomingDamageEffect() {
    const centerPanel = document.querySelector('.center-panel');
    const hpBar = document.getElementById('player-hp-bar');
    const hpText = document.getElementById('player-hp-text');

    if (centerPanel) {
        centerPanel.classList.remove('damage-flash');
        void centerPanel.offsetWidth;
        centerPanel.classList.add('damage-flash');
    }

    [hpBar, hpText].forEach((el) => {
        if (!el) return;
        el.classList.remove('hp-hit');
        void el.offsetWidth;
        el.classList.add('hp-hit');
    });
}

function hasAutomationEnabled() {
    return isAutoExploreMode || isAutoRestMode;
}

function isBackgroundAutoExploreRunning() {
    const statusDisplay = document.getElementById('auto-explore-status-display');
    const claimBtn = document.getElementById('btn-claim-auto-explore');
    return Boolean(statusDisplay && claimBtn && statusDisplay.style.display !== 'none' && claimBtn.style.display !== 'none');
}

function updateAutoExploreModeUI() {
    const statusEl = document.getElementById('auto-explore-status-text');
    if (!statusEl) return;
    statusEl.innerText = isAutoExploreMode ? '[ON]' : '[OFF]';
    statusEl.style.color = isAutoExploreMode ? '#81c784' : '#aaa';
}

function updateAutoRestModeUI() {
    const statusEl = document.getElementById('auto-rest-status-text');
    if (!statusEl) return;
    statusEl.innerText = isAutoRestMode ? '[ON]' : '[OFF]';
    statusEl.style.color = isAutoRestMode ? '#ffb74d' : '#aaa';
}

function clearAutoActionTimer() {
    if (autoActionTimer) {
        clearTimeout(autoActionTimer);
        autoActionTimer = null;
    }
}

function shouldAutoRest() {
    if (!isAutoRestMode) return false;
    const hpRatio = getCurrentPlayerHp() / Math.max(1, Number(window.playerMaxHp || 1));
    const mpRatio = getCurrentPlayerMp() / Math.max(1, Number(window.playerMaxMp || 1));
    return hpRatio <= AUTO_REST_HP_THRESHOLD || mpRatio <= AUTO_REST_MP_THRESHOLD;
}

function scheduleAutoAction(delay = AUTO_ACTION_DELAY) {
    clearAutoActionTimer();
    if (!hasAutomationEnabled()) return;
    if (window.isDead || window.isCombat || isProcessingTurn || isProcessingAction) return;
    if (isBackgroundAutoExploreRunning()) return;
    if (isAutoExploreMode && isBossPreFloor()) {
        disableAutoExploreMode(`⛔ 보스 전층(${getCurrentFloorNumber()}층)에서는 자동 탐색을 사용할 수 없습니다.`);
    }
    if (!isAutoExploreMode && !shouldAutoRest()) return;

    autoActionTimer = setTimeout(runAutoActionLoop, Math.max(0, delay));
}

async function runAutoActionLoop() {
    clearAutoActionTimer();
    if (!hasAutomationEnabled()) return;
    if (window.isDead || window.isCombat || isProcessingTurn || isProcessingAction) return;
    if (isBackgroundAutoExploreRunning()) return;
    if (isAutoExploreMode && isBossPreFloor()) {
        disableAutoExploreMode(`⛔ 보스 전층(${getCurrentFloorNumber()}층)에서는 자동 탐색이 허용되지 않습니다.`);
        if (!shouldAutoRest()) return;
    }

    if (shouldAutoRest()) {
        await sendAction('rest');
        return;
    }

    if (isAutoExploreMode) {
        await sendAction('action');
    }
}

function toggleAutoExploreMode() {
    const toggle = document.getElementById('auto-explore-toggle');
    if (!toggle) return;
    isAutoExploreMode = toggle.checked;
    if (isAutoExploreMode && isBossPreFloor()) {
        disableAutoExploreMode(`⛔ 보스 전층(${getCurrentFloorNumber()}층)에서는 자동 탐색을 켤 수 없습니다.`);
        return;
    }
    updateAutoExploreModeUI();

    if (hasAutomationEnabled()) scheduleAutoAction(250);
    else clearAutoActionTimer();
}

function toggleAutoRestMode() {
    const toggle = document.getElementById('auto-rest-toggle');
    if (!toggle) return;
    isAutoRestMode = toggle.checked;
    updateAutoRestModeUI();

    if (hasAutomationEnabled()) scheduleAutoAction(250);
    else clearAutoActionTimer();
}

function disableAutoExploreMode(logMessage = '') {
    const wasEnabled = isAutoExploreMode;
    const toggle = document.getElementById('auto-explore-toggle');
    if (toggle) toggle.checked = false;
    isAutoExploreMode = false;
    updateAutoExploreModeUI();
    if (wasEnabled && logMessage) addLog(logMessage, true);
    if (!hasAutomationEnabled()) clearAutoActionTimer();
}

function disableAutoRestMode(logMessage = '') {
    const toggle = document.getElementById('auto-rest-toggle');
    if (toggle) toggle.checked = false;
    isAutoRestMode = false;
    updateAutoRestModeUI();
    if (logMessage) addLog(logMessage, true);
}

async function typeText(targetEl, text, delayMs = 8) {
    const chars = Array.from(String(text || ''));
    for (const ch of chars) {
        targetEl.textContent += ch;
        const logBox = document.getElementById('game-log');
        if (logBox) logBox.scrollTop = logBox.scrollHeight;
        await wait(delayMs);
    }
}

function getStatusEffectLines(data) {
    const lines = (data && Array.isArray(data.status_effect_logs)) ? data.status_effect_logs : [];
    return lines
        .map((line) => `🧪 [상태이상] ${toPlainLogText(line)}`)
        .filter((line) => line.trim() !== '');
}

async function renderTurnScriptBlock(targetEl, data, options = {}) {
    if (!targetEl) return;
    const scriptSteps = getTurnScriptSteps(data);
    const statusLines = getStatusEffectLines(data);
    if (scriptSteps.length === 0 && statusLines.length === 0) return;

    const textEl = targetEl.querySelector('.turn-script-lines') || targetEl.querySelector('.stream-text');
    if (!textEl) return;
    textEl.innerHTML = '';
    textEl.style.whiteSpace = 'normal';

    let currentMobHp = Number((options.initialMobHp !== undefined) ? options.initialMobHp : (window.currentMobHp || 0));
    const finalMobHp = Math.max(0, Number((options.finalMobHp !== undefined) ? options.finalMobHp : ((data && data.mob_hp) || currentMobHp)));
    const mobMaxHp = Math.max(1, Number((options.mobMaxHp !== undefined) ? options.mobMaxHp : ((data && data.mob_max_hp) || window.currentMobMaxHp || 1)));
    let playerHpApplied = false;
    const initialPlayerHp = Number((options.initialPlayerHp !== undefined) ? options.initialPlayerHp : getCurrentPlayerHp());
    const finalPlayerHp = Math.max(0, Number((options.finalPlayerHp !== undefined) ? options.finalPlayerHp : initialPlayerHp));
    const playerMaxHp = Math.max(1, Number((options.playerMaxHp !== undefined) ? options.playerMaxHp : (window.playerMaxHp || 1)));
    const finalPlayerMp = Math.max(0, Number((options.finalPlayerMp !== undefined) ? options.finalPlayerMp : 0));
    const playerMaxMp = Math.max(1, Number((options.playerMaxMp !== undefined) ? options.playerMaxMp : (window.playerMaxMp || 1)));

    if (options.syncMonsterHp) updateMonsterBars(currentMobHp, mobMaxHp);
    if (options.syncPlayerHp) updatePlayerBars(initialPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);

    for (const step of scriptSteps) {
        const lineEl = document.createElement('div');
        lineEl.className = 'turn-script-line' + (step.type ? ` ${step.type}` : '');
        textEl.appendChild(lineEl);
        await typeText(lineEl, toPlainLogText(step.text));
        const logBox = document.getElementById('game-log');
        if (logBox) logBox.scrollTop = logBox.scrollHeight;
        if (step.type === 'outgoing-damage' && options.syncMonsterHp) {
            currentMobHp = Math.max(finalMobHp, currentMobHp - Math.max(0, Number(step.damage) || 0));
            updateMonsterBars(currentMobHp, mobMaxHp);
            await wait(MONSTER_HP_STEP_DELAY);
        }
        if (step.type === 'incoming-damage' && options.syncPlayerHp) {
            updatePlayerBars(finalPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);
            triggerIncomingDamageEffect();
            playerHpApplied = true;
            await wait(120);
        }
        await wait(options.syncMonsterHp ? 16 : 23);
    }

    for (const line of statusLines) {
        const lineEl = document.createElement('div');
        lineEl.className = 'turn-script-line status-effect';
        textEl.appendChild(lineEl);
        await typeText(lineEl, toPlainLogText(line));
        const logBox = document.getElementById('game-log');
        if (logBox) logBox.scrollTop = logBox.scrollHeight;
        await wait(23);
    }

    if (options.syncMonsterHp) updateMonsterBars(finalMobHp, mobMaxHp);
    if (options.syncPlayerHp && !playerHpApplied) {
        updatePlayerBars(finalPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);
    }
}

async function addTypedLogLine(message, isSystem = true, extraClass = '') {
    const plain = toPlainLogText(message);
    if (!plain) return;
    const logBox = document.getElementById('game-log');
    const newLog = document.createElement('div');
    newLog.className = 'log-entry' + (isSystem ? ' system' : '') + (extraClass ? ` ${extraClass}` : '');
    newLog.style.whiteSpace = 'pre-wrap';
    newLog.textContent = '';
    logBox.appendChild(newLog);
    logBox.scrollTop = logBox.scrollHeight;
    await typeText(newLog, plain);
}

async function addTurnDamageBreakdown(data, options = {}) {
    const scriptSteps = getTurnScriptSteps(data);
    const statusLines = getStatusEffectLines(data);

    let currentMobHp = Number((options.initialMobHp !== undefined) ? options.initialMobHp : (window.currentMobHp || 0));
    const finalMobHp = Math.max(0, Number((options.finalMobHp !== undefined) ? options.finalMobHp : ((data && data.mob_hp) || currentMobHp)));
    const mobMaxHp = Math.max(1, Number((options.mobMaxHp !== undefined) ? options.mobMaxHp : ((data && data.mob_max_hp) || window.currentMobMaxHp || 1)));
    let playerHpApplied = false;
    const initialPlayerHp = Number((options.initialPlayerHp !== undefined) ? options.initialPlayerHp : getCurrentPlayerHp());
    const finalPlayerHp = Math.max(0, Number((options.finalPlayerHp !== undefined) ? options.finalPlayerHp : initialPlayerHp));
    const playerMaxHp = Math.max(1, Number((options.playerMaxHp !== undefined) ? options.playerMaxHp : (window.playerMaxHp || 1)));
    const finalPlayerMp = Math.max(0, Number((options.finalPlayerMp !== undefined) ? options.finalPlayerMp : 0));
    const playerMaxMp = Math.max(1, Number((options.playerMaxMp !== undefined) ? options.playerMaxMp : (window.playerMaxMp || 1)));

    if (options.syncMonsterHp) updateMonsterBars(currentMobHp, mobMaxHp);
    if (options.syncPlayerHp) updatePlayerBars(initialPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);

    for (const step of scriptSteps) {
        await addTypedLogLine(step.text, true, step.type === 'incoming-damage' ? 'incoming-damage' : '');
        if (step.type === 'outgoing-damage' && options.syncMonsterHp) {
            currentMobHp = Math.max(finalMobHp, currentMobHp - Math.max(0, Number(step.damage) || 0));
            updateMonsterBars(currentMobHp, mobMaxHp);
            await wait(MONSTER_HP_STEP_DELAY);
        }
        if (step.type === 'incoming-damage' && options.syncPlayerHp) {
            updatePlayerBars(finalPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);
            triggerIncomingDamageEffect();
            playerHpApplied = true;
            await wait(120);
        }
        await wait(options.syncMonsterHp ? 16 : 20);
    }

    for (const line of statusLines) {
        await addTypedLogLine(line, true);
        await wait(20);
    }

    if (options.syncMonsterHp) updateMonsterBars(finalMobHp, mobMaxHp);
    if (options.syncPlayerHp && !playerHpApplied) {
        updatePlayerBars(finalPlayerHp, playerMaxHp, finalPlayerMp, playerMaxMp);
    }
}

function formatAiBadge(meta) {
    if (!meta || !meta.provider) return '[AI ✨]';
    const provider = String(meta.provider).toLowerCase();
    const model = meta.model ? ` ${meta.model}` : '';
    if (provider === 'ollama') return `[Ollama${model} ✨]`;
    if (provider === 'gemini') return `[Gemini${model} ✨]`;
    return `[AI${model} ✨]`;
}

function formatEventTypeLabel(eventType) {
    const t = String(eventType || '').toLowerCase();
    if (t === 'encounter') return "<span style='color:#ff8a80;'>전투</span>";
    if (t === 'gold') return "<span style='color:#ffd54f;'>골드</span>";
    if (t === 'chest') return "<span style='color:#ffcc80;'>보물</span>";
    if (t === 'trap') return "<span style='color:#ef9a9a;'>함정</span>";
    if (t === 'mana_spring') return "<span style='color:#80deea;'>마나</span>";
    return "<span style='color:#cfd8dc;'>이벤트</span>";
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
    if (window.currentMobHp === undefined || window.currentMobHp === null) {
        window.currentMobHp = window.currentMobMaxHp || 0;
    }

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
        show('explore-actions', true);
        return;
    }

    if (stage === BATTLE_STAGE.ENCOUNTER) {
        show('dead-actions', false);
        show('explore-actions', false);
        show('encounter-actions', true);
        show('combat-actions', false);
        show('monster-ui', true);
        document.getElementById('mob-name-display').innerText = window.currentMobName || '몬스터';
        const nowHp = Math.max(0, Math.min(Number(window.currentMobHp || 0), Number(window.currentMobMaxHp || 0)));
        const maxHp = Math.max(1, Number(window.currentMobMaxHp || 1));
        document.getElementById('mob-hp-text').innerText = `${nowHp}/${maxHp}`;
        document.getElementById('mob-hp-bar').style.width = (nowHp / maxHp * 100) + '%';
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
            const nowHp = Math.max(0, Math.min(Number(window.currentMobHp || 0), Number(window.currentMobMaxHp || 0)));
            const maxHp = Math.max(1, Number(window.currentMobMaxHp || 1));
            document.getElementById('mob-hp-text').innerText = `${nowHp}/${maxHp}`;
            document.getElementById('mob-hp-bar').style.width = (nowHp / maxHp * 100) + '%';
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
    clearAutoActionTimer();
    setBattleStage(BATTLE_STAGE.DEAD);
    addLog('<h2 style="color:red;">☠️ 사망하였습니다.</h2>모든 행동이 불가능합니다. 여신의 축복을 받으세요.', true);
}

function exitToExploreState() {
    window.isCombat = false;
    clearCombatTimer();
    document.body.classList.remove('boss-bg');
    setBattleStage(BATTLE_STAGE.EXPLORE);
    scheduleAutoAction(AUTO_ACTION_DELAY);
}

function enterEncounterState(mobName, mobMaxHp) {
    if (mobName.includes('[보스]')) {
        document.body.classList.add('boss-bg');
        addLog(`<span style="color:red; font-size:1.1rem; font-weight:bold; text-shadow:0 0 5px red;">⚠️ 경고: 강력한 보스의 살기가 느껴집니다!</span>`);
    }
    enterCombatState(mobName, mobMaxHp);
}

function enterCombatState(mobName, mobMaxHp) {
    window.isCombat = true;
    clearAutoActionTimer();
    if (!window.currentMobHp || window.currentMobHp <= 0) window.currentMobHp = mobMaxHp;
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
                enterCombatState(window.currentMobName || '정체불명의 적', window.currentMobMaxHp || 1);
                addLog(data.msg, true);
            } else {
                exitToExploreState();
            }
            isProcessingTurn = false;
        } else {
            const currentPlayerHpBeforeTurn = getCurrentPlayerHp();
            const nextPlayerHp = Math.max(0, Number(data.new_hp || 0));
            const nextPlayerMaxHp = Math.max(1, Number(data.max_hp || window.playerMaxHp || 1));
            const nextPlayerMp = Math.max(0, Number(data.new_mp || 0));
            const nextPlayerMaxMp = Math.max(1, Number(data.max_mp || window.playerMaxMp || 1));
            updatePlayerBars(currentPlayerHpBeforeTurn, nextPlayerMaxHp, nextPlayerMp, nextPlayerMaxMp);
            const currentMobHpBeforeTurn = Math.max(0, Number(window.currentMobHp || 0));
            const nextMobHp = Math.max(0, Number(data.mob_hp || 0));
            const nextMobMaxHp = Math.max(1, Number(data.mob_max_hp || window.currentMobMaxHp || 1));
            window.currentMobMaxHp = nextMobMaxHp;
            applyRewardUi(data);

                if (data.stream) {
                    const logBox = document.getElementById('game-log');
                    const streamLog = document.createElement('div');
                    streamLog.className = 'log-entry system';
                    streamLog.innerHTML = "<span class='turn-script-header' style='color:#ff5252; font-weight:bold;'></span><br><span class='turn-script-lines'></span><span class='stream-text'></span>";
                    logBox.appendChild(streamLog);

                    const textSpan = streamLog.querySelector('.stream-text');
                    if (textSpan) textSpan.style.whiteSpace = 'pre-wrap';

                    // 턴 스크립트 표시와 동시에 SSE 백그라운드 프리페치 시작
                    let sseBuffer = '';
                    let sseLogAppends = [];
                    let sseDone = false;
                    const source = new EventSource('api.php?action=stream_combat_ai');
                    source.onmessage = function(event) {
                        if (event.data === '[DONE]') { source.close(); sseDone = true; return; }
                        try {
                            const chunk = JSON.parse(event.data);
                            if (chunk.text) sseBuffer += chunk.text;
                            if (chunk.log_append) sseLogAppends.push(chunk.log_append);
                        } catch(e) {}
                    };
                    source.onerror = function() { source.close(); sseDone = true; };

                    // 턴 스크립트(데미지) 애니메이션 출력
                    const headerEl = streamLog.querySelector('.turn-script-header');
                    if (headerEl) {
                        headerEl.textContent = '';
                        await typeText(headerEl, '[전투 스크립트 ✨]', 7);
                    }
                    await renderTurnScriptBlock(streamLog, data, {
                        syncMonsterHp: true,
                        syncPlayerHp: true,
                        initialMobHp: currentMobHpBeforeTurn,
                        finalMobHp: nextMobHp,
                        mobMaxHp: nextMobMaxHp,
                        initialPlayerHp: currentPlayerHpBeforeTurn,
                        finalPlayerHp: nextPlayerHp,
                        playerMaxHp: nextPlayerMaxHp,
                        finalPlayerMp: nextPlayerMp,
                        playerMaxMp: nextPlayerMaxMp,
                    });
                    logBox.scrollTop = logBox.scrollHeight;

                    // 턴 스크립트 완료 후 버퍼된 SSE 내용을 즉시 이어붙이고, 미완료면 폴링 대기
                    let displayedIdx = 0;
                    while (!sseDone || displayedIdx < sseBuffer.length) {
                        if (displayedIdx < sseBuffer.length) {
                            if (textSpan) textSpan.textContent += sseBuffer.slice(displayedIdx);
                            displayedIdx = sseBuffer.length;
                            while (sseLogAppends.length > 0) {
                                streamLog.innerHTML += '<br>' + sseLogAppends.shift();
                            }
                            logBox.scrollTop = logBox.scrollHeight;
                        }
                        if (!sseDone) await wait(7);
                    }

                    isProcessingTurn = false;
                    if (data.status === 'victory') { exitToExploreState(); toggleEquip(0, -1); }
                    else if (data.status === 'defeat') { enterDeadState(); }
                    else if (isAutoMode) { combatTimer = setTimeout(doCombatTurn, 500); }
                    return; // 완료
                } else {
                    await addTurnDamageBreakdown(data, {
                        syncMonsterHp: true,
                        syncPlayerHp: true,
                        initialMobHp: currentMobHpBeforeTurn,
                        finalMobHp: nextMobHp,
                        mobMaxHp: nextMobMaxHp,
                        initialPlayerHp: currentPlayerHpBeforeTurn,
                        finalPlayerHp: nextPlayerHp,
                        playerMaxHp: nextPlayerMaxHp,
                        finalPlayerMp: nextPlayerMp,
                        playerMaxMp: nextPlayerMaxMp,
                    });
                    if (Array.isArray(data.logs)) {
                        for (const log of data.logs) addLog(log);
                    }
                    if (data.status === 'victory') { exitToExploreState(); toggleEquip(0, -1); } 
                    else if (data.status === 'defeat') { enterDeadState(); } 
                    else if (isAutoMode) { combatTimer = setTimeout(doCombatTurn, 500); }
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
    if (isProcessingAction) return;
    isProcessingAction = true;
    clearAutoActionTimer();
    const hpBeforeAction = (actionType === 'action') ? getCurrentPlayerHp() : 0;

    // 🚨 중복 클릭 방지: 통신 중에는 모든 버튼 잠금
    const btns = document.querySelectorAll('.action-container .btn');
    btns.forEach(b => b.style.pointerEvents = 'none');

    let isStreamInProgress = false;
    let shouldRescheduleAutomation = false;

    const finalizeActionState = (resumeAutomation) => {
        isProcessingAction = false;
        setTimeout(() => {
            btns.forEach(b => b.style.pointerEvents = 'auto');
        }, 300);
        if (resumeAutomation) scheduleAutoAction(AUTO_ACTION_DELAY);
    };

    try {
        // 🚨 즉각적인 피드백 로그 (서버 응답을 기다리는 동안 유저가 지루하지 않게 함)
        if (actionType === 'action') {
            addLog('🐾 어두운 미궁 속으로 발걸음을 옮깁니다... (결과 계산 중)', true);
        } else if (actionType === 'rest') {
            addLog('🔥 모닥불을 피울 안전한 곳을 찾습니다... (결과 계산 중)', true);
        } else if (actionType === 'summon') {
            addLog('✨ 차원의 틈새를 엽니다...', true);
        } else if (actionType === 'next_floor') {
            addLog('⬆️ 계단을 올라 다음 층으로 이동합니다...', true);
        }

        const data = await callApi(actionType, {method: 'POST'});
        
        if(data) {
            if (actionType === 'action') {
                if (data.story_event) showStoryModal(data.story_event);
                if (data.status === 'encounter' || data.status === 'safe') {
                    updateFloorDisplay(data.new_floor, data.new_max_floor);
                    if (data.status === 'encounter') {
                        addLog(`⚠️ 전투 대상 확인: <b>[${data.mob_name}]</b> (HP ${data.mob_max_hp})`, true);
                    }
                    if (data.status === 'safe') {
                        updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
                        applyRewardUi(data);
                        renderExploreOutcomeHighlights(data, hpBeforeAction);
                        if (Array.isArray(data.levelup_logs)) {
                            for (const line of data.levelup_logs) addLog(line, true);
                        }
                    }
                    if (data.stream) {
                        isStreamInProgress = true;
                        const logBox = document.getElementById('game-log');
                        const lastLog = logBox.lastElementChild;
                        if (lastLog && (lastLog.innerText.includes('AI 묘사 대기 중') || lastLog.innerText.includes('결과 계산 중'))) {
                            logBox.removeChild(lastLog);
                        }

                        if (data.event_title && data.event_type) {
                            const typeLabel = formatEventTypeLabel(data.event_type);
                            addLog(`🧭 <b style='color:#b39ddb;'>[${data.event_title}]</b> (${typeLabel})`, true);
                        }

                        const streamLog = document.createElement('div');
                        streamLog.className = 'log-entry system';
                        streamLog.innerHTML = "<span class='stream-badge' style='color:#00d8ff; font-weight:bold;'>[AI ✨]</span><br><span class='stream-text'></span>";
                        logBox.appendChild(streamLog);
                        logBox.scrollTop = logBox.scrollHeight;
                        const textSpan = streamLog.querySelector('.stream-text');
                        const badgeSpan = streamLog.querySelector('.stream-badge');

                        const source = new EventSource('api.php?action=stream_ai');
                        source.onmessage = function(event) {
                            if (event.data === '[DONE]') {
                                source.close();
                                if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                                finalizeActionState(true);
                                return;
                            }
                            const chunk = JSON.parse(event.data);
                            if (chunk.meta && badgeSpan) {
                                badgeSpan.innerText = formatAiBadge(chunk.meta);
                            }
                            if (chunk.text) textSpan.textContent += chunk.text;
                            if (chunk.log_append) streamLog.innerHTML += "<br>" + chunk.log_append;
                            logBox.scrollTop = logBox.scrollHeight;
                        };
                        source.onerror = function() {
                            source.close();
                            if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                            finalizeActionState(true);
                        };
                    } else {
                        if (data.log) addLog(data.log);
                        if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                        shouldRescheduleAutomation = true;
                    }
                    } else if (data.status === 'auto_advance') {
                        if (data.new_floor !== undefined) updateFloorDisplay(data.new_floor, data.new_max_floor);
                        updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
                        if (data.msg) addLog(data.msg, true);
                        shouldRescheduleAutomation = true;
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
                        shouldRescheduleAutomation = true;
                    }
                } else {
                    if (actionType === 'rest' && data.msg && data.msg.includes('마력석이 부족')) {
                        disableAutoRestMode('⚠️ 자동 휴식을 중지했습니다. 휴식 골드가 부족합니다.');
                        shouldRescheduleAutomation = isAutoExploreMode;
                    } else if (actionType === 'rest' && isAutoExploreMode) {
                        shouldRescheduleAutomation = true;
                    }
                    addLog(data.msg, true);
                 }
            } else if (actionType === 'next_floor') {
                if (data.status === 'success' || data.status === 'encounter') {
                    if (data.new_floor !== undefined) {
                        updateFloorDisplay(data.new_floor, data.new_max_floor);
                    }
                    if (data.new_hp !== undefined && data.max_hp !== undefined && data.new_mp !== undefined && data.max_mp !== undefined) {
                        updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
                    }
                    if (data.msg) addLog(data.msg, true);
                    if (data.status === 'encounter') {
                        const nextMobMaxHp = Math.max(1, Number(data.mob_max_hp || window.currentMobMaxHp || 1));
                        addLog(`⚠️ 전투 대상 확인: <b>[${data.mob_name}]</b> (HP ${nextMobMaxHp})`, true);
                        enterEncounterState(data.mob_name || '정체불명의 적', nextMobMaxHp);
                    }
                    shouldRescheduleAutomation = hasAutomationEnabled();
                } else {
                    addLog(data.msg, true);
                }
            }
        }
    } catch (e) {
        console.error("sendAction Error:", e);
        addLog("❌ 클라이언트 오류가 발생했습니다.", true);
        shouldRescheduleAutomation = false;
    } finally {
        if (!isStreamInProgress) {
            finalizeActionState(shouldRescheduleAutomation);
        }
    }
}

async function revive() {
    const data = await callApi('restart', {method: 'POST'});
    if (data && data.status === 'success') {
        window.isDead = false;
        addLog(data.log, true);
        updateFloorDisplay(1);
        updatePlayerBars(data.max_hp, data.max_hp, data.max_mp, data.max_mp);
        exitToExploreState();
        toggleEquip(0, -1);
    }
}

async function useSkill(skillId) {
    if (isProcessingTurn) { addLog('⏳ 턴을 기다리는 중입니다.'); return; }
    const wasCombat = Boolean(window.isCombat && window.battleStage === BATTLE_STAGE.COMBAT);
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
            applyRewardUi(data);
            if (wasCombat && data.mob_hp !== undefined && data.mob_hp !== null) {
                updateMonsterBars(Number(data.mob_hp || 0), window.currentMobMaxHp || 1);
            }
            if (wasCombat && Number(data.mob_hp || 0) <= 0) {
                addLog('💫 승리했습니다!'); await wait(1000); exitToExploreState(); toggleEquip(0, -1);
            } else if (wasCombat && isAutoMode) { doCombatTurn(); }
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
        applyButtonTooltips(document.getElementById('combine-list-area'));
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

function showLevelUpEffect(fromLevel, toLevel, levelupCount = 1) {
    const overlay = document.getElementById('levelup-overlay');
    const levelText = document.getElementById('levelup-level-text');
    const metaText = document.getElementById('levelup-meta-text');
    if (!overlay || !levelText || !metaText) return;

    levelText.innerText = `Lv.${fromLevel} -> Lv.${toLevel}`;
    if (levelupCount >= 2) {
        metaText.innerText = `연속 레벨업 ${levelupCount}회! 잠재력이 폭발합니다.`;
    } else {
        metaText.innerText = '새로운 힘이 각성합니다.';
    }
    overlay.style.display = 'flex';
    setTimeout(() => { overlay.style.display = 'none'; }, 2600);
}

async function openModal(modalId, listAreaId, action) {
    document.getElementById(modalId).style.display = 'flex';
    document.getElementById(listAreaId).innerHTML = '불러오는 중...';
    const data = await callApi(action);
    if(data && data.status === 'success') {
        document.getElementById(listAreaId).innerHTML = data.html;
        applyButtonTooltips(document.getElementById(listAreaId));
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
        applyButtonTooltips(document.getElementById('hero-levelup-list-area'));
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
        applyButtonTooltips(document.getElementById('relic-list-area'));
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
    let aiProviderBadge = '';
    source.onmessage = function(event) {
        if (event.data === '[DONE]') {
            source.close();
            return;
        }
        if (isFirstChunk) { contentDiv.innerHTML = ''; isFirstChunk = false; }
        const chunk = JSON.parse(event.data);
        if (chunk.meta) {
            aiProviderBadge = `<div style="color:#90caf9; margin-bottom:8px; font-size:0.9rem;">${formatAiBadge(chunk.meta)}</div>`;
            contentDiv.innerHTML = aiProviderBadge;
        }
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
    if (isBossPreFloor()) {
        addLog(`⛔ 보스 전층(${getCurrentFloorNumber()}층)에서는 자동 탐험을 시작할 수 없습니다.`, true);
        return;
    }
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
        const prevLevel = Number(document.getElementById('level-display').innerText || 1);
        addLog(data.log, true);
        if (Array.isArray(data.levelup_logs)) {
            for (const lvlog of data.levelup_logs) addLog(lvlog, true);
        }
        if (data.new_level !== undefined && data.new_exp !== undefined) {
            updateExpBar(data.new_level, data.new_exp, data.exp_to_next);
            const gainCount = Number(data.levelup_count || (Array.isArray(data.levelup_logs) ? data.levelup_logs.length : 0));
            if (Number(data.new_level) > prevLevel) {
                showLevelUpEffect(prevLevel, Number(data.new_level), gainCount || 1);
            }
        }
        if (data.new_stat_points !== undefined) {
            updateStatUI(data.new_stat_points);
        }
        if (data.new_hp !== undefined && data.new_max_hp !== undefined && data.new_mp !== undefined && data.new_max_mp !== undefined) {
            updatePlayerBars(data.new_hp, data.new_max_hp, data.new_mp, data.new_max_mp);
        }
        if (data.rewards && data.rewards.gold !== undefined) {
            const goldEl = document.getElementById('gold-display');
            const currentGold = Number((goldEl && goldEl.innerText || '0').replace(/,/g, '')) || 0;
            goldEl.innerText = (currentGold + Number(data.rewards.gold)).toLocaleString();
        }
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
        clearAutoActionTimer();
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
            scheduleAutoAction(AUTO_ACTION_DELAY);
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
    window.playerCurrentHp = window.playerCurrentHp || 0;
    window.playerCurrentMp = window.playerCurrentMp || 0;

    // 전투 모드 기본값: 수동
    const autoToggle = document.getElementById('auto-combat-toggle');
    if (autoToggle) {
        autoToggle.checked = false;
    }
    const autoExploreToggle = document.getElementById('auto-explore-toggle');
    if (autoExploreToggle) {
        autoExploreToggle.checked = false;
    }
    const autoRestToggle = document.getElementById('auto-rest-toggle');
    if (autoRestToggle) {
        autoRestToggle.checked = false;
    }
    toggleAutoMode();
    toggleAutoExploreMode();
    toggleAutoRestMode();

    toggleEquip(0, -1); 
    setupStatButtons();
    applyButtonTooltips();
    if (window.initialStatPoints !== undefined) updateStatUI(window.initialStatPoints);
    refreshCommanderStatHighlights();
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

    if (window.initialIntroStory) {
        setTimeout(() => showStoryModal(window.initialIntroStory), 500);
    }
});