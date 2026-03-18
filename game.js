// game.js
let isAutoMode = false;
let isAutoExploreMode = false;
let isAutoRestMode = false;
let combatTimer = null;
let autoActionTimer = null;
let isProcessingTurn = false;
let isProcessingAction = false;
const MONSTER_HP_STEP_DELAY = 150;
const AUTO_ACTION_DELAY = 450;
const DEFAULT_AUTO_REST_MP_THRESHOLD = 0.35;
const CAUTIOUS_AUTO_REST_MP_THRESHOLD = 0.45;
const BOLD_AUTO_REST_MP_THRESHOLD = 0.00;
const AUTO_SKILL_BUFF_DURATION = 3;
const COLLAPSE_STORAGE_PREFIX = 'ld_panel_collapsed_';
let centerLogTypingQueue = Promise.resolve();
let prefetchedCombatTurnData = null;
let prefetchedCombatTurnPromise = null;
let combatPrefetchToken = 0;

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
    if (data.hero_owned !== undefined || data.hero_limit !== undefined) {
        updateHeroCapacityDisplay(data.hero_owned, data.hero_limit);
    }
    applyButtonTooltips();
}

function updateHeroCapacityDisplay(heroOwned, heroLimit) {
    const ownedEl = document.getElementById('hero-owned-display');
    const limitEl = document.getElementById('hero-limit-display');
    if (!ownedEl || !limitEl) return;

    if (heroOwned !== undefined && heroOwned !== null) {
        ownedEl.innerText = String(Number(heroOwned) || 0);
    }
    if (heroLimit !== undefined && heroLimit !== null) {
        limitEl.innerText = String(Number(heroLimit) || 0);
    }
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

function updateCollapseToggleLabel(button, isCollapsed) {
    if (!button) return;
    button.textContent = isCollapsed ? '▼' : '▲';
    button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
}

function setCollapsedState(targetId, isCollapsed) {
    if (!targetId) return;
    const body = document.getElementById(targetId);
    if (!body) return;

    body.classList.toggle('is-collapsed', !!isCollapsed);
    const relatedButtons = document.querySelectorAll(`[data-collapse-target="${targetId}"]`);
    relatedButtons.forEach((button) => {
        updateCollapseToggleLabel(button, !!isCollapsed);
        const header = button.closest('.section-header');
        if (header) header.classList.toggle('is-collapsed', !!isCollapsed);
    });

    try {
        localStorage.setItem(`${COLLAPSE_STORAGE_PREFIX}${targetId}`, isCollapsed ? '1' : '0');
    } catch (err) {
        console.warn('collapse state save failed', err);
    }
}

function toggleCollapsedState(targetId) {
    const body = document.getElementById(targetId);
    if (!body) return;
    const nextCollapsed = !body.classList.contains('is-collapsed');
    setCollapsedState(targetId, nextCollapsed);
}

function initCollapsiblePanels() {
    const toggleButtons = Array.from(document.querySelectorAll('[data-collapse-target]'));
    if (toggleButtons.length === 0) return;

    toggleButtons.forEach((button) => {
        const targetId = button.getAttribute('data-collapse-target');
        if (!targetId) return;
        button.setAttribute('aria-controls', targetId);
        button.addEventListener('click', () => toggleCollapsedState(targetId));
        button.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleCollapsedState(targetId);
            }
        });
    });

    const initializedTargets = new Set();
    toggleButtons.forEach((button) => {
        const targetId = button.getAttribute('data-collapse-target');
        if (!targetId || initializedTargets.has(targetId)) return;
        initializedTargets.add(targetId);

        let shouldCollapse = false;
        try {
            shouldCollapse = localStorage.getItem(`${COLLAPSE_STORAGE_PREFIX}${targetId}`) === '1';
        } catch (err) {
            shouldCollapse = false;
        }
        setCollapsedState(targetId, shouldCollapse);
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

function isBossFloor(floor = getCurrentFloorNumber()) {
    const safeFloor = Math.max(1, Number(floor) || 1);
    return (safeFloor % 10) === 0;
}

function isBossMob(mobName = '') {
    return String(mobName || '').includes('[보스]');
}

function isBossAutoCombatRestricted(mobName = window.currentMobName || '', floor = getCurrentFloorNumber()) {
    return isBossFloor(floor) && isBossMob(mobName);
}

function getCurrentCommanderLevel() {
    const levelEl = document.getElementById('level-display');
    return parseFloorNumber(levelEl ? levelEl.innerText : 1);
}

function isAutoExploreBlockedByLevel(level = getCurrentCommanderLevel(), floor = getCurrentFloorNumber()) {
    const safeLevel = Math.max(1, Number(level) || 1);
    const safeFloor = Math.max(1, Number(floor) || 1);
    return safeLevel >= (safeFloor + 6);
}

function getAutoExploreBlockedMessage(actionText = '중지되었습니다.') {
    const level = getCurrentCommanderLevel();
    const floor = getCurrentFloorNumber();
    return `⛔ 사령관 레벨(${level})이 현재 층(${floor})보다 6 이상 높아 자동 탐색이 ${actionText}`;
}

function updateFloorDisplay(newFloor, newMaxFloor) {
    const floorEl = document.getElementById('floor-display');
    const maxFloorEl = document.getElementById('max-floor-display');
    const parsedNewFloor = parseFloorNumber(newFloor);

    if (floorEl && parsedNewFloor > 0) {
        floorEl.innerText = String(parsedNewFloor);
        if (isAutoExploreMode && isAutoExploreBlockedByLevel(getCurrentCommanderLevel(), parsedNewFloor)) {
            disableAutoExploreMode(getAutoExploreBlockedMessage('중지되었습니다.'));
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

function getDispositionLabel(value) {
    const disp = clampDispositionValue(value);
    if (disp <= 20) return '극도로 조심';
    if (disp <= 40) return '신중함';
    if (disp <= 60) return '균형잡힘';
    if (disp <= 80) return '다소 과감';
    return '매우 과감';
}

function updateCommanderStatsDisplay(stats = {}) {
    const map = {
        str: 'val-str',
        mag: 'val-mag',
        agi: 'val-agi',
        luk: 'val-luk',
        men: 'val-men',
        vit: 'val-vit',
    };

    Object.entries(map).forEach(([key, elementId]) => {
        if (stats[key] === undefined) return;
        const el = document.getElementById(elementId);
        if (el) el.innerText = String(stats[key]);
    });

    if (stats.disposition !== undefined) {
        window.commanderDisposition = clampDispositionValue(stats.disposition);
        const dispEl = document.getElementById('val-disposition');
        if (dispEl) {
            dispEl.innerText = `${window.commanderDisposition} (${getDispositionLabel(window.commanderDisposition)})`;
        }
        updateAutoRestModeUI();
    }

    refreshCommanderStatHighlights();
}

function addLog(message, isSystem = false) {
    return renderCenterScriptLine(message, { isSystem });
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
    const html = String(message || '')
        .replace(/<\s*br\s*\/?>/giu, '\n')
        .replace(/<\s*\/\s*(div|p|li|h1|h2|h3|h4|h5|h6)\s*>/giu, '\n');
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
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

function clampDispositionValue(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 50;
    return Math.max(0, Math.min(100, Math.floor(numeric)));
}

function getCommanderDisposition() {
    return clampDispositionValue(window.commanderDisposition !== undefined ? window.commanderDisposition : 50);
}

function isBoldDisposition() {
    return getCommanderDisposition() >= 80;
}

function isCautiousDisposition() {
    return getCommanderDisposition() <= 20;
}

function getAutoRestHpThresholdRatio() {
    const disposition = getCommanderDisposition();
    return Math.max(0.20, Math.min(0.70, (70 - (disposition * 0.5)) / 100));
}

function getAutoRestMpThresholdRatio() {
    if (isBoldDisposition()) return BOLD_AUTO_REST_MP_THRESHOLD;
    if (isCautiousDisposition()) return CAUTIOUS_AUTO_REST_MP_THRESHOLD;
    return DEFAULT_AUTO_REST_MP_THRESHOLD;
}

function ensureAutoCombatState() {
    if (!window.autoCombatState || typeof window.autoCombatState !== 'object') {
        window.autoCombatState = { shieldUpTurns: 0, berserkTurns: 0 };
    }
    return window.autoCombatState;
}

function resetAutoCombatState() {
    window.autoCombatState = { shieldUpTurns: 0, berserkTurns: 0 };
}

function projectAutoCombatStateAfterAction(state, skillId = '') {
    const base = (state && typeof state === 'object') ? state : { shieldUpTurns: 0, berserkTurns: 0 };
    return {
        shieldUpTurns: (skillId === 'shield_up')
            ? AUTO_SKILL_BUFF_DURATION
            : Math.max(0, Number(base.shieldUpTurns || 0) - 1),
        berserkTurns: (skillId === 'berserk')
            ? AUTO_SKILL_BUFF_DURATION
            : Math.max(0, Number(base.berserkTurns || 0) - 1),
    };
}

function chooseAutoCombatActionFromSnapshot(snapshot = {}) {
    // 자동전투에서는 일반 공격만 수행
    return 'attack';
}

function advanceAutoCombatState(skillId = '') {
    const state = ensureAutoCombatState();
    const projected = projectAutoCombatStateAfterAction(state, skillId);
    window.autoCombatState = projected;
    return projected;
}

function chooseAutoCombatAction() {
    return chooseAutoCombatActionFromSnapshot({
        hp: getCurrentPlayerHp(),
        maxHp: Math.max(1, Number(window.playerMaxHp || 1)),
        mp: getCurrentPlayerMp(),
        state: ensureAutoCombatState(),
        disposition: getCommanderDisposition(),
    });
}

function clearCombatTurnPrefetch() {
    combatPrefetchToken += 1;
    prefetchedCombatTurnData = null;
    prefetchedCombatTurnPromise = null;
}

function hasCombatTurnPrefetch() {
    return prefetchedCombatTurnData !== null || !!prefetchedCombatTurnPromise;
}

async function getCombatTurnData() {
    if (prefetchedCombatTurnData !== null) {
        const cached = prefetchedCombatTurnData;
        prefetchedCombatTurnData = null;
        return cached;
    }

    if (prefetchedCombatTurnPromise) {
        await prefetchedCombatTurnPromise;
        if (prefetchedCombatTurnData !== null) {
            const cached = prefetchedCombatTurnData;
            prefetchedCombatTurnData = null;
            return cached;
        }
    }

    return await callApi('combat');
}

function startCombatTurnPrefetch() {
    if (!window.isCombat || !isAutoMode || window.battleStage !== BATTLE_STAGE.COMBAT) return false;
    if (hasCombatTurnPrefetch()) return false;

    const token = ++combatPrefetchToken;
    prefetchedCombatTurnPromise = callApi('combat')
        .then((data) => {
            if (token !== combatPrefetchToken) return null;
            prefetchedCombatTurnData = data;
            return data;
        })
        .catch(() => null)
        .finally(() => {
            if (token === combatPrefetchToken) {
                prefetchedCombatTurnPromise = null;
            }
        });
    return true;
}

function queueNextAutoCombatPrefetch(currentTurnData, snapshot = {}) {
    if (!isAutoMode || !window.isCombat || window.battleStage !== BATTLE_STAGE.COMBAT) {
        clearCombatTurnPrefetch();
        return false;
    }
    if (!currentTurnData || currentTurnData.status !== 'ongoing') {
        clearCombatTurnPrefetch();
        return false;
    }

    const projectedState = projectAutoCombatStateAfterAction(ensureAutoCombatState(), 'attack');
    const predictedNextAction = chooseAutoCombatActionFromSnapshot({
        hp: Math.max(0, Number(snapshot.hp || 0)),
        maxHp: Math.max(1, Number(snapshot.maxHp || 1)),
        mp: Math.max(0, Number(snapshot.mp || 0)),
        state: projectedState,
        disposition: snapshot.disposition !== undefined ? snapshot.disposition : getCommanderDisposition(),
    });

    if (predictedNextAction !== 'attack') {
        clearCombatTurnPrefetch();
        return false;
    }

    return startCombatTurnPrefetch();
}

function getAutoCombatPostTurnDelay() {
    return hasCombatTurnPrefetch() ? 24 : 180;
}

function scheduleAutoCombatAction(delay = 420) {
    clearCombatTimer();
    if (!isAutoMode || !window.isCombat || isProcessingTurn || window.battleStage !== BATTLE_STAGE.COMBAT) return;
    combatTimer = setTimeout(runAutoCombatAction, Math.max(0, delay));
}

async function runAutoCombatAction() {
    if (!isAutoMode || !window.isCombat || isProcessingTurn || window.battleStage !== BATTLE_STAGE.COMBAT) return;
    if (hasCombatTurnPrefetch()) {
        await doCombatTurn();
        return;
    }
    const nextAction = chooseAutoCombatAction();
    if (nextAction === 'attack') {
        await doCombatTurn();
        return;
    }
    await useSkill(nextAction);
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

function updateAutoExploreModeUI() {
    const statusEl = document.getElementById('auto-explore-status-text');
    if (!statusEl) return;
    statusEl.innerText = isAutoExploreMode ? '[ON]' : '[OFF]';
    statusEl.style.color = isAutoExploreMode ? '#81c784' : '#aaa';
}

function updateAutoRestModeUI() {
    const statusEl = document.getElementById('auto-rest-status-text');
    if (!statusEl) return;
    const thresholdPercent = Math.round(getAutoRestHpThresholdRatio() * 100);
    statusEl.innerText = isAutoRestMode ? `[ON ${thresholdPercent}%]` : '[OFF]';
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
    const hpThreshold = getAutoRestHpThresholdRatio();
    const mpThreshold = getAutoRestMpThresholdRatio();
    return hpRatio <= hpThreshold || (mpThreshold > 0 && mpRatio <= mpThreshold);
}

function scheduleAutoAction(delay = AUTO_ACTION_DELAY) {
    clearAutoActionTimer();
    if (!hasAutomationEnabled()) return;
    if (window.isDead || window.isCombat || isProcessingTurn || isProcessingAction) return;
    if (isAutoExploreMode && isAutoExploreBlockedByLevel()) {
        disableAutoExploreMode(getAutoExploreBlockedMessage('사용할 수 없습니다.'));
    }
    if (!isAutoExploreMode && !shouldAutoRest()) return;

    autoActionTimer = setTimeout(runAutoActionLoop, Math.max(0, delay));
}

async function runAutoActionLoop() {
    clearAutoActionTimer();
    if (!hasAutomationEnabled()) return;
    if (window.isDead || window.isCombat || isProcessingTurn || isProcessingAction) return;
    if (isAutoExploreMode && isAutoExploreBlockedByLevel()) {
        disableAutoExploreMode(getAutoExploreBlockedMessage('허용되지 않습니다.'));
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
    if (isAutoExploreMode && isAutoExploreBlockedByLevel()) {
        disableAutoExploreMode(getAutoExploreBlockedMessage('켜둘 수 없습니다.'));
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
        if (!targetEl || !targetEl.isConnected) return;
        targetEl.textContent += ch;
        const logBox = document.getElementById('game-log');
        if (logBox) logBox.scrollTop = logBox.scrollHeight;
        await wait(delayMs);
    }
}

function enqueueCenterLogTyping(task) {
    const nextTask = centerLogTypingQueue.catch(() => {}).then(task);
    centerLogTypingQueue = nextTask.catch(() => {});
    return nextTask;
}

function createCenterScriptLineElement(plainText, isSystem = false, extraClass = '') {
    const logBox = document.getElementById('game-log');
    if (!logBox) return null;

    const newLog = document.createElement('div');
    newLog.className = 'log-entry' + (isSystem ? ' system' : '') + (extraClass ? ` ${extraClass}` : '');
    newLog.style.whiteSpace = 'pre-wrap';
    newLog.dataset.plainText = plainText;
    newLog.textContent = '';
    logBox.appendChild(newLog);
    logBox.scrollTop = logBox.scrollHeight;
    return newLog;
}

function renderCenterScriptLine(message, options = {}) {
    const plain = toPlainLogText(message);
    if (!plain) return Promise.resolve(null);

    const isSystem = Boolean(options.isSystem);
    const extraClass = options.extraClass || '';
    const delayMs = Math.max(1, Number(options.delayMs || 8));
    const targetEl = createCenterScriptLineElement(plain, isSystem, extraClass);
    if (!targetEl) return Promise.resolve(null);

    return enqueueCenterLogTyping(async () => {
        if (!targetEl.isConnected) return targetEl;
        await typeText(targetEl, plain, delayMs);
        return targetEl;
    });
}

function getStatusEffectLines(data) {
    const lines = (data && Array.isArray(data.status_effect_logs)) ? data.status_effect_logs : [];
    return lines
        .map((line) => `🧪 [상태이상] ${toPlainLogText(line)}`)
        .filter((line) => line.trim() !== '');
}

function getPlainLogLines(logs) {
    const lines = Array.isArray(logs) ? logs : [];
    return lines
        .map((line) => toPlainLogText(line))
        .filter((line) => line.trim() !== '');
}

async function renderPlainLogBlock(targetEl, logs, extraClass = '') {
    if (!targetEl) return;
    const lines = getPlainLogLines(logs);
    if (lines.length === 0) return;

    const textEl = targetEl.querySelector('.turn-script-lines') || targetEl.querySelector('.stream-text') || targetEl;
    if (!textEl) return;
    textEl.innerHTML = '';
    textEl.style.whiteSpace = 'pre-wrap';

    for (const line of lines) {
        const lineEl = document.createElement('div');
        lineEl.className = 'turn-script-line' + (extraClass ? ` ${extraClass}` : '');
        textEl.appendChild(lineEl);
        await typeText(lineEl, line);
        const logBox = document.getElementById('game-log');
        if (logBox) logBox.scrollTop = logBox.scrollHeight;
        await wait(20);
    }
}

function createCenterScriptBlock(title = '[전투 스크립트 ✨]', headerColor = '#ff5252') {
    const logBox = document.getElementById('game-log');
    const block = document.createElement('div');
    block.className = 'log-entry system';
    block.innerHTML = "<div class='turn-script-header'></div><div class='turn-script-lines'></div><div class='stream-text'></div>";

    const headerEl = block.querySelector('.turn-script-header');
    const narrativeEl = block.querySelector('.stream-text');
    if (headerEl) {
        headerEl.style.color = headerColor;
        headerEl.style.fontWeight = 'bold';
        headerEl.textContent = '';
    }
    if (narrativeEl) {
        narrativeEl.style.whiteSpace = 'pre-wrap';
        narrativeEl.style.marginTop = '6px';
        narrativeEl.style.display = 'none';
        narrativeEl.textContent = '';
    }

    if (logBox) {
        logBox.appendChild(block);
        logBox.scrollTop = logBox.scrollHeight;
    }

    return {
        logBox,
        block,
        headerEl,
        narrativeEl,
    };
}

function shouldSkipCenterScriptNarrative(meta) {
    const provider = String((meta && meta.provider) || '').toLowerCase();
    const model = String((meta && meta.model) || '').toLowerCase();
    return provider === 'raw' || model === 'local-fallback';
}

async function streamCenterScriptNarrative(scriptBlock, streamUrl, options = {}) {
    if (!scriptBlock || !scriptBlock.narrativeEl || !streamUrl) {
        return { skipped: true, meta: null };
    }

    const narrativeEl = scriptBlock.narrativeEl;
    const logBox = scriptBlock.logBox;
    const onLogAppend = typeof options.onLogAppend === 'function' ? options.onLogAppend : null;
    const perCharDelay = Math.max(1, Number(options.perCharDelay || 12));
    const skipRawFallback = options.skipRawFallback !== false;

    let sseBuffer = '';
    let sseDone = false;
    let meta = null;
    let skipNarrative = false;

    const source = new EventSource(streamUrl);
    source.onmessage = function(event) {
        if (event.data === '[DONE]') {
            source.close();
            sseDone = true;
            return;
        }

        try {
            const chunk = JSON.parse(event.data);
            if (chunk.meta) {
                meta = chunk.meta;
                skipNarrative = skipRawFallback && shouldSkipCenterScriptNarrative(meta);
                if (skipNarrative) {
                    source.close();
                    sseDone = true;
                    return;
                }
            }
            if (chunk.text) {
                narrativeEl.style.display = 'block';
                sseBuffer += chunk.text;
            }
            if (chunk.log_append && onLogAppend) {
                onLogAppend(chunk.log_append);
            }
        } catch (error) {
            console.error('streamCenterScriptNarrative parse error:', error);
        }
    };
    source.onerror = function() {
        source.close();
        sseDone = true;
    };

    let displayedIdx = 0;
    while (!sseDone || displayedIdx < sseBuffer.length) {
        if (displayedIdx < sseBuffer.length) {
            narrativeEl.textContent += sseBuffer.charAt(displayedIdx);
            displayedIdx += 1;
            if (logBox) logBox.scrollTop = logBox.scrollHeight;
            await wait(perCharDelay);
            continue;
        }
        await wait(7);
    }

    if (skipNarrative || narrativeEl.textContent.trim() === '') {
        narrativeEl.style.display = 'none';
        narrativeEl.textContent = '';
    }

    return { skipped: skipNarrative, meta };
}

async function renderCenterScriptMessage(options = {}) {
    const title = options.title || '[전투 스크립트 ✨]';
    const headerColor = options.headerColor || '#ff5252';
    const renderBody = typeof options.renderBody === 'function' ? options.renderBody : null;
    const streamUrl = options.streamUrl || '';
    const scriptBlock = createCenterScriptBlock(title, headerColor);

    if (scriptBlock.headerEl) {
        await typeText(scriptBlock.headerEl, title, 7);
    }

    if (renderBody) {
        await renderBody(scriptBlock.block);
    }

    if (scriptBlock.logBox) {
        scriptBlock.logBox.scrollTop = scriptBlock.logBox.scrollHeight;
    }

    if (streamUrl) {
        await streamCenterScriptNarrative(scriptBlock, streamUrl, {
            skipRawFallback: options.skipRawFallback,
            perCharDelay: options.perCharDelay,
            onLogAppend: options.onLogAppend,
        });
    }

    return scriptBlock;
}

async function renderTurnScriptBlock(targetEl, data, options = {}) {
    if (!targetEl) return;
    const scriptSteps = getTurnScriptSteps(data);
    const statusLines = getStatusEffectLines(data);
    if (scriptSteps.length === 0 && statusLines.length === 0) return;

    const textEl = targetEl.querySelector('.turn-script-lines') || targetEl.querySelector('.stream-text');
    if (!textEl) return;
    textEl.innerHTML = '';
    textEl.style.whiteSpace = 'pre-wrap';

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
    await renderCenterScriptLine(message, { isSystem, extraClass });
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
    clearCombatTurnPrefetch();
    clearCombatTimer();
    clearAutoActionTimer();
    resetAutoCombatState();
    setBattleStage(BATTLE_STAGE.DEAD);
    addLog('<h2 style="color:red;">☠️ 사망하였습니다.</h2>모든 행동이 불가능합니다. 여신의 축복을 받으세요.', true);
}

function exitToExploreState() {
    window.isCombat = false;
    clearCombatTurnPrefetch();
    clearCombatTimer();
    resetAutoCombatState();
    document.body.classList.remove('boss-bg');
    setBattleStage(BATTLE_STAGE.EXPLORE);
    scheduleAutoAction(AUTO_ACTION_DELAY);
}

function enterEncounterState(mobName, mobMaxHp) {
    if (isBossMob(mobName)) {
        document.body.classList.add('boss-bg');
        addLog(`<span style="color:red; font-size:1.1rem; font-weight:bold; text-shadow:0 0 5px red;">⚠️ 경고: 강력한 보스의 살기가 느껴집니다!</span>`);
        if (isBossAutoCombatRestricted(mobName)) {
            addLog('⛔ 10층 보스전에서는 자동 전투를 사용할 수 없습니다. 수동 전투로 진행해 주세요.', true);
        }
    }
    enterCombatState(mobName, mobMaxHp);
}

function enterCombatState(mobName, mobMaxHp) {
    window.isCombat = true;
    clearCombatTurnPrefetch();
    clearAutoActionTimer();
    resetAutoCombatState();
    if (!window.currentMobHp || window.currentMobHp <= 0) window.currentMobHp = mobMaxHp;
    setBattleStage(BATTLE_STAGE.COMBAT, mobName, mobMaxHp);
    if (isAutoMode && isBossAutoCombatRestricted(mobName)) {
        disableAutoCombatMode();
        return;
    }
    if (isAutoMode) runAutoCombatAction();
}

function clearCombatTimer() {
    if (combatTimer) { clearTimeout(combatTimer); combatTimer = null; }
    isProcessingTurn = false;
}

function updateAutoCombatModeUI() {
    const statusEl = document.getElementById('auto-status-text');
    if (!statusEl) return;
    statusEl.innerText = isAutoMode ? '[자동]' : '[수동]';
    statusEl.style.color = isAutoMode ? '#4caf50' : '#aaa';
}

function disableAutoCombatMode(logMessage = '') {
    const toggle = document.getElementById('auto-combat-toggle');
    const wasEnabled = isAutoMode;
    if (toggle) toggle.checked = false;
    isAutoMode = false;
    clearCombatTurnPrefetch();
    updateAutoCombatModeUI();
    if (wasEnabled && logMessage) addLog(logMessage, true);
}

function toggleAutoMode() {
    const toggle = document.getElementById('auto-combat-toggle');
    if (!toggle) return;

    if (isProcessingTurn) {
        toggle.checked = isAutoMode;
        addLog('⏳ 턴 진행 중에는 자동 전투를 변경할 수 없습니다.', true);
        return;
    }

    isAutoMode = toggle.checked;
    if (!isAutoMode) {
        clearCombatTurnPrefetch();
    }
    if (isAutoMode && window.isCombat && isBossAutoCombatRestricted(window.currentMobName || '')) {
        disableAutoCombatMode('⛔ 10층 보스전에서는 자동 전투를 사용할 수 없습니다. 수동 전투로 진행해 주세요.');
        return;
    }

    updateAutoCombatModeUI();
    if (isAutoMode && window.isCombat && !isProcessingTurn) runAutoCombatAction();
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
    let continueAutoCombat = false;
    const data = await getCombatTurnData();
    if (data) {
        if (data.status === 'error') {
            clearCombatTurnPrefetch();
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

            queueNextAutoCombatPrefetch(data, {
                hp: nextPlayerHp,
                maxHp: nextPlayerMaxHp,
                mp: nextPlayerMp,
                disposition: getCommanderDisposition(),
            });

                if (data.stream) {
                    await renderCenterScriptMessage({
                        title: '[전투 스크립트 ✨]',
                        renderBody: async (targetEl) => {
                            await renderTurnScriptBlock(targetEl, data, {
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
                        },
                        streamUrl: 'api.php?action=stream_combat_ai',
                        perCharDelay: 12,
                        onLogAppend: (logAppend) => {
                            if (!logAppend) return;
                            const logBox = document.getElementById('game-log');
                            if (!logBox) return;
                            const extraLine = document.createElement('div');
                            extraLine.className = 'turn-script-line';
                            extraLine.textContent = toPlainLogText(logAppend);
                            const currentBlock = logBox.lastElementChild;
                            if (currentBlock) {
                                const linesEl = currentBlock.querySelector('.turn-script-lines');
                                if (linesEl) linesEl.appendChild(extraLine);
                            }
                        },
                    });

                    // 10턴 지표, 전투 보상 처리
                    if (Array.isArray(data.logs)) {
                        for (const log of data.logs) {
                            if (typeof log === 'string' && log.includes('턴 지표')) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = log;
                                const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                const headerMatch = textContent.match(/\[.*?턴 지표\]/);
                                const header = headerMatch ? headerMatch[0] : "전투 요약";
                                const dataPart = textContent.replace(/📊\s*\[.*?턴 지표\]\s*/, '').trim();
                                const metrics = dataPart.split('|').map(m => m.trim());
                                const metricsHtml = metrics.map(metric => {
                                    const parts = metric.split(' ');
                                    if (parts.length < 2) return `<span>${metric}</span>`;
                                    const value = parts.pop();
                                    const label = parts.join(' ');
                                    return `<span style="margin-right: 16px; white-space: nowrap;"><span style="color: #bdbdbd;">${label}</span> <strong style="color: #fff; font-weight:bold;">${value}</strong></span>`;
                                }).join('');
                                const reportHtml = `
                                    <div style="border: 1px solid #7e57c2; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(40, 30, 55, 0.5), rgba(30, 35, 45, 0.5)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                        <div style="font-weight: bold; color: #d1c4e9; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #555;">📊 ${header}</div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 8px 0px; font-size: 0.9em; line-height: 1.6;">
                                            ${metricsHtml}
                                        </div>
                                    </div>`;
                                const logBox = document.getElementById('game-log');
                                if (logBox) {
                                    const newLog = document.createElement('div');
                                    newLog.className = 'log-entry system';
                                    newLog.innerHTML = reportHtml;
                                    logBox.appendChild(newLog);
                                    logBox.scrollTop = logBox.scrollHeight;
                                }
                            } else if (typeof log === 'string' && (log.includes('전투 보상') || log.includes('🎖️'))) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = log;
                                const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                const parts = textContent.replace('🎖️', '').trim().split(':');
                                const title = parts[0] || "전투 보상";
                                const details = (parts.length > 1 ? parts[1].trim() : '').replace(/, /g, '<span style="margin:0 8px; color:#777;">|</span>');
                                const rewardHtml = `
                                <div style="border: 1px solid #ffd54f; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(60, 50, 26, 0.6), rgba(45, 40, 30, 0.6)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                    <div style="font-weight: bold; color: #ffecb3; margin-bottom: 8px; font-size: 1.05em;">🎖️ ${title}</div>
                                    <div style="font-size: 1em; color: #fff;"><strong>${details}</strong></div>
                                </div>`;
                                const logBox = document.getElementById('game-log');
                                if (logBox) {
                                    const newLog = document.createElement('div');
                                    newLog.className = 'log-entry system';
                                    newLog.innerHTML = rewardHtml;
                                    logBox.appendChild(newLog);
                                    logBox.scrollTop = logBox.scrollHeight;
                                }
                            }
                        }
                    }

                    advanceAutoCombatState('attack');
                    isProcessingTurn = false;
                    if (data.status === 'victory') { clearCombatTurnPrefetch(); exitToExploreState(); toggleEquip(0, -1); }
                    else if (data.status === 'defeat') { clearCombatTurnPrefetch(); enterDeadState(); }
                    else if (isAutoMode) { scheduleAutoCombatAction(getAutoCombatPostTurnDelay()); }
                    return;
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
                        for (const log of data.logs) {
                            if (typeof log === 'string' && log.includes('턴 지표')) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = log;
                                const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                const headerMatch = textContent.match(/\[.*?턴 지표\]/);
                                const header = headerMatch ? headerMatch[0] : "전투 요약";
                                const dataPart = textContent.replace(/📊\s*\[.*?턴 지표\]\s*/, '').trim();
                                const metrics = dataPart.split('|').map(m => m.trim());
                                const metricsHtml = metrics.map(metric => {
                                    const parts = metric.split(' ');
                                    if (parts.length < 2) return `<span>${metric}</span>`;
                                    const value = parts.pop();
                                    const label = parts.join(' ');
                                    return `<span style="margin-right: 16px; white-space: nowrap;"><span style="color: #bdbdbd;">${label}</span> <strong style="color: #fff; font-weight:bold;">${value}</strong></span>`;
                                }).join('');
                                const reportHtml = `
                                    <div style="border: 1px solid #7e57c2; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(40, 30, 55, 0.5), rgba(30, 35, 45, 0.5)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                        <div style="font-weight: bold; color: #d1c4e9; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #555;">📊 ${header}</div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 8px 0px; font-size: 0.9em; line-height: 1.6;">
                                            ${metricsHtml}
                                        </div>
                                    </div>`;
                                const logBox = document.getElementById('game-log');
                                if (logBox) {
                                    const newLog = document.createElement('div');
                                    newLog.className = 'log-entry system';
                                    newLog.innerHTML = reportHtml;
                                    logBox.appendChild(newLog);
                                    logBox.scrollTop = logBox.scrollHeight;
                                }
                            } else if (typeof log === 'string' && (log.includes('전투 보상') || log.includes('🎖️'))) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = log;
                                const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                const parts = textContent.replace('🎖️', '').trim().split(':');
                                const title = parts[0] || "전투 보상";
                                const details = (parts.length > 1 ? parts[1].trim() : '').replace(/, /g, '<span style="margin:0 8px; color:#777;">|</span>');
                                const rewardHtml = `
                                <div style="border: 1px solid #ffd54f; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(60, 50, 26, 0.6), rgba(45, 40, 30, 0.6)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                    <div style="font-weight: bold; color: #ffecb3; margin-bottom: 8px; font-size: 1.05em;">🎖️ ${title}</div>
                                    <div style="font-size: 1em; color: #fff;"><strong>${details}</strong></div>
                                </div>`;
                                const logBox = document.getElementById('game-log');
                                if (logBox) {
                                    const newLog = document.createElement('div');
                                    newLog.className = 'log-entry system';
                                    newLog.innerHTML = rewardHtml;
                                    logBox.appendChild(newLog);
                                    logBox.scrollTop = logBox.scrollHeight;
                                }
                            } else {
                                addLog(log);
                            }
                        }
                    }
                    advanceAutoCombatState('attack');
                    if (data.status === 'victory') { clearCombatTurnPrefetch(); exitToExploreState(); toggleEquip(0, -1); } 
                    else if (data.status === 'defeat') { clearCombatTurnPrefetch(); enterDeadState(); } 
                    else if (isAutoMode) { continueAutoCombat = true; }
                }
        }
    }
        if (!data || !data.stream) isProcessingTurn = false;
        if (!data) clearCombatTurnPrefetch();
        if (continueAutoCombat) scheduleAutoCombatAction(getAutoCombatPostTurnDelay());
}

async function attemptFlee() {
    clearCombatTurnPrefetch();
    const data = await callApi('flee');
    if (data) {
        addLog(data.log);
        if (data.new_gold !== undefined) {
            const goldEl = document.getElementById('gold-display');
            if (goldEl) goldEl.innerText = Number(data.new_gold).toLocaleString();
        }
        if (data.status === 'success') {
            if (data.new_floor !== undefined) {
                updateFloorDisplay(data.new_floor, data.new_max_floor);
            }
            exitToExploreState();
        }
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
            if (!data.stream) {
                const logBox = document.getElementById('game-log');
                const pendingLog = logBox ? logBox.lastElementChild : null;
                const pendingText = pendingLog ? String(pendingLog.dataset.plainText || pendingLog.textContent || '') : '';
                if (pendingLog && pendingText.includes('(결과 계산 중)')) {
                    logBox.removeChild(pendingLog);
                }
            }
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
                    if (data.log) addLog(data.log);
                    if (data.status === 'encounter') enterEncounterState(data.mob_name, data.mob_max_hp);
                    shouldRescheduleAutomation = true;
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
        updateFloorDisplay(data.new_floor !== undefined ? data.new_floor : 1);
        updatePlayerBars(data.max_hp, data.max_hp, data.max_mp, data.max_mp);
        exitToExploreState();
        toggleEquip(0, -1);
    }
}

async function useSkill(skillId) {
    if (isProcessingTurn) { addLog('⏳ 턴을 기다리는 중입니다.'); return; }
    const wasCombat = Boolean(window.isCombat && window.battleStage === BATTLE_STAGE.COMBAT);
    let continueAutoCombat = false;
    clearCombatTurnPrefetch();
    isProcessingTurn = true;
    const formData = new URLSearchParams();
    formData.append('skill_id', skillId);
    const data = await callApi('skill', { method: 'POST', body: formData });
    if(data) {
        if (data.status === 'error') {
            addLog('❌ ' + data.msg, true);
            if (wasCombat && isAutoMode) continueAutoCombat = true;
        }
        else {
                    if (wasCombat && data.stream) {
                        // SSE 요청 전 세션 저장 시간 확보
                        await wait(50);

                        await renderCenterScriptMessage({
                            title: '[전투 스크립트 ✨]',
                            renderBody: async (targetEl) => {
                                await renderPlainLogBlock(targetEl, data.logs, 'skill-log');
                            },
                            streamUrl: 'api.php?action=stream_combat_ai',
                            perCharDelay: 12,
                        });

                        // 10턴 지표, 전투 보상 처리
                        if (Array.isArray(data.logs)) {
                            for (const log of data.logs) {
                                if (typeof log === 'string' && log.includes('턴 지표')) {
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = log;
                                    const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                    const headerMatch = textContent.match(/\[.*?턴 지표\]/);
                                    const header = headerMatch ? headerMatch[0] : "전투 요약";
                                    const dataPart = textContent.replace(/📊\s*\[.*?턴 지표\]\s*/, '').trim();
                                    const metrics = dataPart.split('|').map(m => m.trim());
                                    const metricsHtml = metrics.map(metric => {
                                        const parts = metric.split(' ');
                                        if (parts.length < 2) return `<span>${metric}</span>`;
                                        const value = parts.pop();
                                        const label = parts.join(' ');
                                        return `<span style="margin-right: 16px; white-space: nowrap;"><span style="color: #bdbdbd;">${label}</span> <strong style="color: #fff; font-weight:bold;">${value}</strong></span>`;
                                    }).join('');
                                    const reportHtml = `
                                        <div style="border: 1px solid #7e57c2; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(40, 30, 55, 0.5), rgba(30, 35, 45, 0.5)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                            <div style="font-weight: bold; color: #d1c4e9; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #555;">📊 ${header}</div>
                                            <div style="display: flex; flex-wrap: wrap; gap: 8px 0px; font-size: 0.9em; line-height: 1.6;">
                                                ${metricsHtml}
                                            </div>
                                        </div>`;
                                    const logBox = document.getElementById('game-log');
                                    if (logBox) {
                                        const newLog = document.createElement('div');
                                        newLog.className = 'log-entry system';
                                        newLog.innerHTML = reportHtml;
                                        logBox.appendChild(newLog);
                                        logBox.scrollTop = logBox.scrollHeight;
                                    }
                                } else if (typeof log === 'string' && (log.includes('전투 보상') || log.includes('🎖️'))) {
                                    const tempDiv = document.createElement('div');
                                    tempDiv.innerHTML = log;
                                    const textContent = tempDiv.textContent || tempDiv.innerText || "";
                                    const parts = textContent.replace('🎖️', '').trim().split(':');
                                    const title = parts[0] || "전투 보상";
                                    const details = (parts.length > 1 ? parts[1].trim() : '').replace(/, /g, '<span style="margin:0 8px; color:#777;">|</span>');
                                    const rewardHtml = `
                                    <div style="border: 1px solid #ffd54f; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(60, 50, 26, 0.6), rgba(45, 40, 30, 0.6)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
                                        <div style="font-weight: bold; color: #ffecb3; margin-bottom: 8px; font-size: 1.05em;">🎖️ ${title}</div>
                                        <div style="font-size: 1em; color: #fff;"><strong>${details}</strong></div>
                                    </div>`;
                                    const logBox = document.getElementById('game-log');
                                    if (logBox) {
                                        const newLog = document.createElement('div');
                                        newLog.className = 'log-entry system';
                                        newLog.innerHTML = rewardHtml;
                                        logBox.appendChild(newLog);
                                        logBox.scrollTop = logBox.scrollHeight;
                                    }
                                }
                            }
                        }
            } else {
                if (Array.isArray(data.logs)) {
                    for (const log of data.logs) {
						if (typeof log === 'string' && log.includes('턴 지표')) {
							const tempDiv = document.createElement('div');
							tempDiv.innerHTML = log;
							const textContent = tempDiv.textContent || tempDiv.innerText || "";
							const headerMatch = textContent.match(/\[.*?턴 지표\]/);
							const header = headerMatch ? headerMatch[0] : "전투 요약";
							const dataPart = textContent.replace(/📊\s*\[.*?턴 지표\]\s*/, '').trim();
							const metrics = dataPart.split('|').map(m => m.trim());
							const metricsHtml = metrics.map(metric => {
								const parts = metric.split(' ');
								if (parts.length < 2) return `<span>${metric}</span>`;
								const value = parts.pop();
								const label = parts.join(' ');
								return `<span style="margin-right: 16px; white-space: nowrap;"><span style="color: #bdbdbd;">${label}</span> <strong style="color: #fff; font-weight:bold;">${value}</strong></span>`;
							}).join('');
							const reportHtml = `
								<div style="border: 1px solid #7e57c2; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(40, 30, 55, 0.5), rgba(30, 35, 45, 0.5)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
									<div style="font-weight: bold; color: #d1c4e9; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #555;">📊 ${header}</div>
									<div style="display: flex; flex-wrap: wrap; gap: 8px 0px; font-size: 0.9em; line-height: 1.6;">
										${metricsHtml}
									</div>
								</div>`;
							const logBox = document.getElementById('game-log');
							if (logBox) {
								const newLog = document.createElement('div');
								newLog.className = 'log-entry system';
								newLog.innerHTML = reportHtml;
								logBox.appendChild(newLog);
								logBox.scrollTop = logBox.scrollHeight;
							}
						} else if (typeof log === 'string' && (log.includes('전투 보상') || log.includes('🎖️'))) {
							const tempDiv = document.createElement('div');
							tempDiv.innerHTML = log;
							const textContent = tempDiv.textContent || tempDiv.innerText || "";
							const parts = textContent.replace('🎖️', '').trim().split(':');
							const title = parts[0] || "전투 보상";
							const details = (parts.length > 1 ? parts[1].trim() : '').replace(/, /g, '<span style="margin:0 8px; color:#777;">|</span>');
							const rewardHtml = `
							<div style="border: 1px solid #ffd54f; border-radius: 8px; padding: 12px; margin: 8px 4px; background: linear-gradient(145deg, rgba(60, 50, 26, 0.6), rgba(45, 40, 30, 0.6)); box-shadow: 0 3px 10px rgba(0,0,0,0.3);">
								<div style="font-weight: bold; color: #ffecb3; margin-bottom: 8px; font-size: 1.05em;">🎖️ ${title}</div>
								<div style="font-size: 1em; color: #fff;"><strong>${details}</strong></div>
							</div>`;
							const logBox = document.getElementById('game-log');
							if (logBox) {
								const newLog = document.createElement('div');
								newLog.className = 'log-entry system';
								newLog.innerHTML = rewardHtml;
								logBox.appendChild(newLog);
								logBox.scrollTop = logBox.scrollHeight;
							}
						} else {
							await addTypedLogLine(log, true);
						}
                    }
                }
            }
            updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
            applyRewardUi(data);
            if (wasCombat) advanceAutoCombatState(skillId);
            if (wasCombat && data.mob_hp !== undefined && data.mob_hp !== null) {
                updateMonsterBars(Number(data.mob_hp || 0), window.currentMobMaxHp || 1);
            }
            if (wasCombat && Number(data.mob_hp || 0) <= 0) {
                addLog('💫 승리했습니다!'); await wait(1000); exitToExploreState(); toggleEquip(0, -1);
            } else if (wasCombat && isAutoMode) { continueAutoCombat = true; }
        }
    }
    isProcessingTurn = false;
    if (continueAutoCombat) scheduleAutoCombatAction(320);
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
const openItemModal = () => openModal('item-modal', 'item-list-area', 'item_info');
const openCombineModal = () => {
    document.getElementById('combine-modal').style.display = 'flex';
    document.getElementById('combine-list-area').innerHTML = '불러오는 중...';
    combineHero('view', '');
};

async function openReincarnationModal() {
    const modal = document.getElementById('reincarnation-modal');
    const area = document.getElementById('reincarnation-content-area');
    if (!modal || !area) return;

    modal.style.display = 'flex';
    area.innerHTML = '<span style="color:#aaa;">불러오는 중...</span>';

    const data = await callApi('reincarnate_preview');
    if (!data || data.status !== 'success') {
        area.innerHTML = '<span style="color:#f44336;">환생 정보를 불러오지 못했습니다.</span>';
        return;
    }

    area.innerHTML = `
        <div style="margin-bottom:12px; color:#d1c4e9;">현재 레벨 <b>Lv.${Number(data.level).toLocaleString()}</b>, 환생 <b>${Number(data.reincarnation_count).toLocaleString()}회</b></div>
        <div style="background:#1d1d1d; border:1px solid #3d2f5e; border-radius:8px; padding:12px; margin-bottom:10px;">
            <div style="color:#ffcc80; font-weight:bold; margin-bottom:6px;">환생 완료 시 적용</div>
            <div>시작 골드: <b>${Number(data.projected_start_gold).toLocaleString()}G</b> (누적 골드 보너스 ${Number(data.gold_bonus).toLocaleString()}G)</div>
            <div>이번 생 누적 레벨 반영: <b>${Number(data.life_levels).toLocaleString()}</b></div>
            <div>이번 환생 추가 스탯 보너스: <b>+${Number(data.new_stat_bonus_gain).toLocaleString()}</b></div>
            <div>환생 누적 랜덤 보너스 총합: <b>+${Number(data.projected_stat_bonus_total).toLocaleString()}</b></div>
        </div>
        <div style="background:#161616; border:1px solid #333; border-radius:8px; padding:12px; color:#bdbdbd; font-size:0.92rem; margin-bottom:12px;">
            유물과 내실 강화는 유지됩니다. 보유 영웅, 출전 덱, 토벌대 파견 기록은 모두 초기화됩니다. 확인을 누르면 재탄생 생성창으로 이동하며, 그곳에서 직업 선택과 주사위 확정을 마치면 환생이 완료됩니다.
        </div>
        <button onclick="confirmReincarnation()" style="width:100%; padding:12px; border:none; border-radius:6px; background:#7b1fa2; color:#fff; font-weight:bold; cursor:pointer;">환생 시작</button>
    `;
}

async function confirmReincarnation() {
    const first = window.confirm('환생을 진행하면 현재 사령관 레벨/경험치/층수/전투 상태가 초기화됩니다. 계속합니까?');
    if (!first) return;
    const second = window.confirm('정말 환생하시겠습니까? 확인 후 재탄생 생성창으로 이동합니다.');
    if (!second) return;

    const data = await callApi('reincarnate', { method: 'POST', body: new URLSearchParams() });
    if (!data || data.status !== 'success') {
        if (data && data.msg) addLog(data.msg, true);
        return;
    }

    const modal = document.getElementById('reincarnation-modal');
    if (modal) modal.style.display = 'none';

    if (data.redirect_url) {
        addLog(data.log || '♻️ 환생 생성창으로 이동합니다.', true);
        window.location.href = data.redirect_url;
        return;
    }

    window.reincarnationCount = Number(data.reincarnation_count || 0);
    window.reincarnationStatBonus = Number(data.reincarnation_stat_bonus || 0);
    window.reincarnationLevelTotal = Number(data.reincarnation_level_total || 0);
    window.currentMobName = '';
    window.currentMobHp = 0;
    window.currentMobMaxHp = 0;
    window.isDead = false;
    window.isCombat = false;

    updateFloorDisplay(data.new_floor, data.new_floor);
    updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
    updateExpBar(data.new_level, data.new_exp, data.exp_to_next);
    updateStatUI(data.stat_points);
    if (data.new_gold !== undefined) {
        const goldEl = document.getElementById('gold-display');
        if (goldEl) goldEl.innerText = Number(data.new_gold).toLocaleString();
    }
    if (data.stats) {
        updateCommanderStatsDisplay({ ...data.stats, disposition: data.new_disposition });
    } else if (data.new_disposition !== undefined) {
        updateCommanderStatsDisplay({ disposition: data.new_disposition });
    }

    addLog(data.log, true);
    addLog('🎲 환생 후 스탯은 클래스 보정 뒤 랜덤 재분배되었습니다.', true);
    exitToExploreState();
}

// ==================================
// 내실 강화 / 제단 축복 UI
// ==================================
async function openProgressionModal() {
    const modal = document.getElementById('progression-modal');
    const area = document.getElementById('progression-content-area');
    if (!modal || !area) return;
    modal.style.display = 'flex';
    area.innerHTML = '<span style="color:#aaa;">불러오는 중...</span>';

    const data = await callApi('get_progression_state');
    if (!data || data.status !== 'success') {
        area.innerHTML = '<span style="color:#f44336;">불러오기 실패</span>';
        return;
    }

    updateHeroCapacityDisplay(data.hero_owned, data.hero_limit);

    let html = `<div style="margin-bottom:14px; color:#ffcc80; font-size:0.95rem;">💰 보유 골드: <b>${Number(data.gold).toLocaleString()}G</b> &nbsp;|&nbsp; 🧑‍🤝‍🧑 영웅 보유: <b>${data.hero_owned}/${data.hero_limit}</b>명</div>`;

    // 내실 강화 섹션
    html += `<div style="color:#90caf9; font-weight:bold; margin-bottom:8px; border-bottom:1px solid #333; padding-bottom:4px;">⚔️ 내실 강화</div>`;
    for (const [key, upg] of Object.entries(data.upgrades)) {
        const isCapped = upg.current_level >= upg.max_level;
        const costText = isCapped ? '최대' : `${Number(upg.cost).toLocaleString()}G`;
        const btnDisabled = isCapped ? 'disabled' : '';
        const btnColor = isCapped ? '#555' : '#e65100';
        html += `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; padding:6px; background:#1f1f1f; border-radius:4px;">
            <div>
                <span style="color:#ffe082; font-weight:bold;">${upg.label}</span>
                <span style="color:#aaa; font-size:0.8rem; margin-left:6px;">Lv.${upg.current_level}/${upg.max_level}</span>
                <div style="color:#888; font-size:0.78rem;">${upg.desc}</div>
            </div>
            <button onclick="upgradeProgression('${key}')" ${btnDisabled} style="background:${btnColor}; color:#fff; border:none; border-radius:4px; padding:5px 10px; cursor:pointer; min-width:70px;">${costText}</button>
        </div>`;
    }

    // 제단 축복 섹션
    const b = data.blessing;
    html += `<div style="color:#ce93d8; font-weight:bold; margin:14px 0 8px; border-bottom:1px solid #333; padding-bottom:4px;">✨ 제단 축복</div>`;
    html += `<div style="background:#1f1f1f; border-radius:6px; padding:10px; margin-bottom:8px;">
        <div style="color:#ffe082;">현재: <b>${b.label}</b>${b.value > 0 ? ` +${b.value}%` : ''}</div>
        <div style="color:#aaa; font-size:0.8rem;">${b.desc}</div>
        <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center;">
            <span style="color:#888; font-size:0.8rem;">리롤 ${b.reroll_count}회 | 다음 비용: <b>${Number(b.reroll_cost).toLocaleString()}G</b></span>
            <button onclick="rerollBlessing()" style="background:#4a148c; color:#fff; border:none; border-radius:4px; padding:5px 12px; cursor:pointer;">🎲 축복 갱신</button>
        </div>
    </div>`;

    area.innerHTML = html;
    applyButtonTooltips(area);
}

async function upgradeProgression(upgradeKey) {
    const formData = new URLSearchParams();
    formData.append('upgrade_key', upgradeKey);
    const data = await callApi('progression_upgrade', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        addLog(data.msg, true);
        openProgressionModal();
    } else if (data) {
        addLog(data.msg || '강화 실패', true);
    }
}

async function rerollBlessing() {
    const data = await callApi('blessing_reroll', { method: 'POST', body: new URLSearchParams() });
    if (data && data.status === 'success') {
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        addLog(data.msg, true);
        openProgressionModal();
    } else if (data) {
        addLog(data.msg || '리롤 실패', true);
    }
}

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

async function buyItem(itemCode) {
    const formData = new URLSearchParams();
    formData.append('item_code', itemCode);
    const data = await callApi('item_buy', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        const listArea = document.getElementById('item-list-area');
        if (listArea && data.html !== undefined) {
            listArea.innerHTML = data.html;
            applyButtonTooltips(listArea);
        }
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        if (data.msg) addLog(data.msg, true);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function useItem(itemId) {
    const formData = new URLSearchParams();
    formData.append('item_id', itemId);
    const data = await callApi('item_use', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        const listArea = document.getElementById('item-list-area');
        if (listArea && data.html !== undefined) {
            listArea.innerHTML = data.html;
            applyButtonTooltips(listArea);
        }
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        if (
            data.new_hp !== undefined && data.max_hp !== undefined &&
            data.new_mp !== undefined && data.max_mp !== undefined
        ) {
            updatePlayerBars(data.new_hp, data.max_hp, data.new_mp, data.max_mp);
        }
        if (data.new_floor !== undefined) {
            updateFloorDisplay(data.new_floor);
        }
        if (data.left_combat) {
            window.currentMobName = '';
            window.currentMobHp = 0;
            window.currentMobMaxHp = 0;
            exitToExploreState();
        }
        if (data.deck_html !== undefined) {
            updateInventoryUI(data);
        }
        if (data.msg) addLog(data.msg, true);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function toggleEquipItem(itemId, action) {
    const formData = new URLSearchParams();
    formData.append('item_id', itemId);
    formData.append('action', action);
    const data = await callApi('item_toggle_equip', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        const listArea = document.getElementById('item-list-area');
        if (listArea && data.html !== undefined) {
            listArea.innerHTML = data.html;
            applyButtonTooltips(listArea);
        }
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        if (data.msg) addLog(data.msg, true);
    } else if (data) {
        addLog(data.msg, true);
    }
}

async function synthesizeEquipment(baseGrade) {
    const formData = new URLSearchParams();
    formData.append('base_grade', baseGrade);
    const data = await callApi('item_synthesize', { method: 'POST', body: formData });
    if (data && data.status === 'success') {
        const listArea = document.getElementById('item-list-area');
        if (listArea && data.html !== undefined) {
            listArea.innerHTML = data.html;
            applyButtonTooltips(listArea);
        }
        if (data.new_gold !== undefined) {
            document.getElementById('gold-display').innerText = Number(data.new_gold).toLocaleString();
        }
        if (data.msg) addLog(data.msg, true);
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
    window.commanderDisposition = clampDispositionValue(window.commanderDisposition !== undefined ? window.commanderDisposition : 50);
    resetAutoCombatState();

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
    initCollapsiblePanels();
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
    
    if (window.initialIntroStory) {
        setTimeout(() => showStoryModal(window.initialIntroStory), 500);
    }
});