<?php
ob_start();
require_once 'bootstrap.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

function is_debug_enabled() {
	$q = isset($_GET['debug']) ? $_GET['debug'] : '0';
	if ($q === '1') return true;
	$env = getenv('APP_DEBUG');
	return ($env === '1' || strtolower((string)$env) === 'true');
}

function app_log($tag, $ctx = array()) {
	if (!is_debug_enabled()) return;
	$line = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	error_log('[LD-DEBUG] ' . $tag . ' ' . ($line ? $line : '{}'));
}

set_exception_handler(function($e) {
	app_log('exception', array('msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()));
	echo json_encode(array('status' => 'error', 'msg' => '서버 내부 오류: ' . $e->getMessage()));
	exit;
});

set_error_handler(function($severity, $message, $file, $line) {
	app_log('php.error', array('severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line));
	return true;
});

$GLOBALS['colors'] = array('일반'=>'#ffffff', '희귀'=>'#2196f3', '영웅'=>'#9c27b0', '전설'=>'#ffeb3b', '신화'=>'#ff9800', '불멸'=>'#ff5252', '유일'=>'#00e5ff');

function http_post_json($url, $payload, $timeout_sec = 10) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, max(1, (int)$timeout_sec));
	$raw = curl_exec($ch);
	$errno = curl_errno($ch);
	$err = curl_error($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	return array('ok' => ($errno === 0 && $code >= 200 && $code < 300), 'raw' => $raw, 'errno' => $errno, 'error' => $err, 'http' => $code);
}

function build_ai_prompt($source_text, $is_story = false, $mode = 'default') {
	$tone = isset($_SESSION['narrative_tone']) ? (string)$_SESSION['narrative_tone'] : '다크 판타지 톤';
	$tone_guide = "";
	if ($tone === '하이텐션 액션 톤') {
		$tone_guide = "\n톤 지시: 하이텐션 액션 톤으로, 동사 중심의 빠르고 강렬한 리듬으로 작성하세요.";
	} elseif ($tone === '간결 로그 톤') {
		$tone_guide = "\n톤 지시: 간결 로그 톤으로, 짧고 명확한 문장 위주로 작성하세요.";
	} else {
		$tone_guide = "\n톤 지시: 다크 판타지 톤으로, 음울하고 신비로운 분위기를 유지하세요.";
	}

	if ($mode === 'combat') {
		$style = "한국어로 3~5문장의 전투 스크립트를 출력하세요. 공격, 반격, 상태이상, 승패를 시간순으로 간결하게 묘사하세요.";
		$rules = "\n규칙:"
			. "\n- 옵션/대안/후보를 나열하지 마세요."
			. "\n- '옵션 1/2/3', 제목, 마크다운(##, **, >)을 절대 쓰지 마세요."
			. "\n- 첫 문장은 반드시 '[전황:교전] 결과' 형식으로 시작하세요."
			. "\n- 전투가 끝나지 않았다면 '승리/패배/처치 완료'를 단정하지 마세요."
			. "\n- 설명 없이 최종 문장만 출력하세요.";
	} else {
		$style = $is_story
			? "한국어로 2~4문장의 판타지 내레이션만 출력하세요."
			: "한국어로 게임 로그 내레이션 1~3문장만 출력하세요.";
		$rules = "\n규칙:"
			. "\n- 옵션/대안/후보를 나열하지 마세요."
			. "\n- '옵션 1/2/3', 제목, 마크다운(##, **, >)을 절대 쓰지 마세요."
			. "\n- 첫 문장은 반드시 '[이벤트:종류] 결과' 형식으로 직관적으로 시작하세요."
			. "\n- 설명 없이 최종 문장만 출력하세요.";
	}
	return $style . $tone_guide . $rules . "\n원문: " . $source_text;
}

function normalize_ai_output($text) {
	$t = trim((string)$text);
	if ($t === '') return $t;

	// 옵션 나열 출력이 들어오면 첫 옵션만 채택
	if (preg_match_all('/옵션\s*\d+\s*:\s*(.+?)(?=(옵션\s*\d+\s*:)|$)/us', $t, $m) && !empty($m[1])) {
		$t = trim((string)$m[1][0]);
	}

	// 불필요한 마크다운/접두어 제거
	$t = preg_replace('/^\s*#{1,6}\s*/um', '', $t);
	$t = str_replace(array('**', '__'), '', $t);
	$t = preg_replace('/^\s*>\s*/um', '', $t);
	$t = strip_tags($t);
	$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$t = preg_replace('/\s+/u', ' ', $t);
	return trim($t);
}

function request_ai_text_with_fallback($source_text, $is_story = false, $mode = 'default') {
	$prompt = build_ai_prompt($source_text, $is_story, $mode);

	// 1) 기본: Ollama (3초 응답 제한)
	$ollama_url = defined('OLLAMA_URL') ? trim((string)OLLAMA_URL) : '';
	$ollama_model = defined('OLLAMA_MODEL') ? trim((string)OLLAMA_MODEL) : '';
	if ($ollama_url !== '' && $ollama_model !== '') {
		$endpoint = rtrim($ollama_url, '/');
		if (substr($endpoint, -13) !== '/api/generate') {
			$endpoint .= '/api/generate';
		}
		$res = http_post_json($endpoint, array(
			'model' => $ollama_model,
			'prompt' => $prompt,
			'stream' => false,
			'keep_alive' => '30m',
			'options' => array(
				'num_predict' => 96,
				'temperature' => 0.7,
				'top_p' => 0.9,
			),
		), 3);
		if ($res['ok'] && $res['raw']) {
			$decoded = json_decode($res['raw'], true);
			if (isset($decoded['response']) && trim((string)$decoded['response']) !== '') {
				app_log('ai.provider', array('provider' => 'ollama', 'fallback' => false));
				return array('text' => normalize_ai_output((string)$decoded['response']), 'provider' => 'ollama', 'model' => $ollama_model);
			}
		}
		app_log('ai.ollama.failed', array('errno' => $res['errno'], 'http' => $res['http'], 'error' => $res['error']));
	}

	// 2) 폴백: Gemini 2.5 Flash Mini
	$gemini_key = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
	$gemini_url = defined('GEMINI_API_URL') ? trim((string)GEMINI_API_URL) : '';
	if ($gemini_key !== '' && $gemini_url !== '') {
		$res = http_post_json($gemini_url, array(
			'contents' => array(
				array('parts' => array(array('text' => $prompt)))
			),
			'generationConfig' => array('temperature' => 0.8, 'topP' => 0.9)
		), 12);
		if ($res['ok'] && $res['raw']) {
			$decoded = json_decode($res['raw'], true);
			if (isset($decoded['candidates'][0]['content']['parts']) && is_array($decoded['candidates'][0]['content']['parts'])) {
				$text = '';
				foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
					if (isset($part['text'])) $text .= (string)$part['text'];
				}
				if (trim($text) !== '') {
					app_log('ai.provider', array('provider' => 'gemini-2.5-flash-mini', 'fallback' => true));
					$model = 'gemini-2.5-flash-lite';
					if (defined('GEMINI_API_URL') && preg_match('#/models/([^:]+):generateContent#', (string)GEMINI_API_URL, $mm)) {
						$model = $mm[1];
					}
					return array('text' => normalize_ai_output($text), 'provider' => 'gemini', 'model' => $model);
				}
			}
		}
		app_log('ai.gemini.failed', array('errno' => $res['errno'], 'http' => $res['http'], 'error' => $res['error']));
	}

	// 3) 최종 폴백: 원문 반환
	app_log('ai.provider', array('provider' => 'raw', 'fallback' => true));
	return array('text' => normalize_ai_output((string)$source_text), 'provider' => 'raw', 'model' => 'local-fallback');
}

function stream_text_as_sse($text, $sleep_us = 28000, $meta = null, $chunk_min = 2, $chunk_max = 6) {
	if (is_array($meta)) {
		echo 'data: ' . json_encode(array('meta' => $meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
		@ob_flush(); @flush();
	}
	$chunk_min = max(1, (int)$chunk_min);
	$chunk_max = max($chunk_min, (int)$chunk_max);
	$chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
	if (!$chars) $chars = array((string)$text);
	$buffer = '';
	$buffer_len = 0;
	$target_len = rand($chunk_min, $chunk_max);
	foreach ($chars as $ch) {
		$buffer .= $ch;
		$buffer_len++;
		if ($buffer_len >= $target_len) {
			echo 'data: ' . json_encode(array('text' => $buffer), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
			$buffer = '';
			$buffer_len = 0;
			$target_len = rand($chunk_min, $chunk_max);
			@ob_flush(); @flush();
			usleep($sleep_us);
		}
	}
	if ($buffer !== '') {
		echo 'data: ' . json_encode(array('text' => $buffer), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
		@ob_flush(); @flush();
	}
}

function json_error($msg, $extra = array()) {
	echo json_encode(array_merge(array('status' => 'error', 'msg' => $msg), $extra));
}

function get_uid_or_fail() {
	if (!isset($_SESSION['uid'])) {
		json_error('세션 만료');
		exit;
	}
	return (int)$_SESSION['uid'];
}

function cleanup_combat_if_stale(PDO $pdo, $uid, &$cmd) {
	if ((int)$cmd['is_combat'] !== 1) return;
	if ((int)$cmd['mob_hp'] > 0 && (int)$cmd['mob_max_hp'] > 0 && trim((string)$cmd['mob_name']) !== '') return;
	$pdo->prepare("UPDATE tb_commanders SET is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")->execute(array($uid));
	$cmd['is_combat'] = 0;
}

function extract_status_effect_logs($logs) {
	$out = array();
	foreach ($logs as $line) {
		$plain = trim(strip_tags((string)$line));
		if ($plain === '') continue;
		if (preg_match('/상태이상|행동하지 못|보호막|막아냈|기절|스턴|빙결|중독|출혈|약화|침묵|감속/u', $plain)) {
			$out[] = $plain;
		}
	}
	return array_values(array_unique($out));
}

function html_log_to_plain_text($html) {
	$t = (string)$html;
	$t = preg_replace('/<\s*br\s*\/?>/iu', "\n", $t);
	$t = preg_replace('/<\s*\/\s*div\s*>/iu', "\n", $t);
	$t = strip_tags($t);
	$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$t = preg_replace('/[ \t]+\n/u', "\n", $t);
	$t = preg_replace('/\n{3,}/u', "\n\n", $t);
	return trim($t);
}

function get_player_buff_value($key) {
	if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) return 0;
	if (!isset($_SESSION['combat_state']['player_buffs']) || !is_array($_SESSION['combat_state']['player_buffs'])) return 0;
	if (!isset($_SESSION['combat_state']['player_buffs'][$key])) return 0;
	$buff = $_SESSION['combat_state']['player_buffs'][$key];
	$turns = isset($buff['turns_left']) ? (int)$buff['turns_left'] : 0;
	if ($turns <= 0) {
		unset($_SESSION['combat_state']['player_buffs'][$key]);
		return 0;
	}
	return isset($buff['value']) ? (int)$buff['value'] : 0;
}

function tick_player_buffs(&$logs, $skip_keys = array()) {
	if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) return;
	if (!isset($_SESSION['combat_state']['player_buffs']) || !is_array($_SESSION['combat_state']['player_buffs'])) return;
	if (!is_array($skip_keys)) $skip_keys = array();

	foreach (array_keys($_SESSION['combat_state']['player_buffs']) as $key) {
		if (in_array($key, $skip_keys, true)) continue;
		$turns = isset($_SESSION['combat_state']['player_buffs'][$key]['turns_left']) ? (int)$_SESSION['combat_state']['player_buffs'][$key]['turns_left'] : 0;
		if ($turns <= 1) {
			unset($_SESSION['combat_state']['player_buffs'][$key]);
			if (is_array($logs)) {
				if ($key === 'vit') $logs[] = "🛡️ 방어강화 효과가 사라졌습니다.";
				if ($key === 'berserk_power') $logs[] = "🔥 광폭화 효과가 사라졌습니다.";
			}
		} else {
			$_SESSION['combat_state']['player_buffs'][$key]['turns_left'] = $turns - 1;
		}
	}

	if (empty($_SESSION['combat_state']['player_buffs'])) {
		unset($_SESSION['combat_state']['player_buffs']);
	}
}

function estimate_expected_turn_damage(PDO $pdo, $uid, $cmd) {
	$p_str = (int)$cmd['stat_str'];
	$p_luk = (int)$cmd['stat_luk'];
	$p_men = (int)$cmd['stat_men'];
	$p_agi = (int)$cmd['stat_agi'];

	$crit_chance = min(100, max(0, (int)floor($p_luk / 2)));
	$crit_chance_rate = $crit_chance / 100.0;
	$crit_mult = 1.5 + ($p_luk * 0.01);
	$expected_crit_mult = 1 + ($crit_chance_rate * ($crit_mult - 1));
	$men_mult = 1 + ($p_men * 0.005);
	$agi_double_rate = min(100, max(0, (int)floor($p_agi / 5))) / 100.0;
	$agi_mult = 1 + $agi_double_rate;

	$commander_base = max(1, (int)floor(($p_str * 1.8) + 8));
	$commander_expected = (float)$commander_base * $expected_crit_mult;

	$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
	$deck_stmt->execute(array($uid));
	$deck = $deck_stmt->fetchAll();

	$rank_avg_map = array(
		'일반' => 7.5,
		'희귀' => 15.0,
		'영웅' => 24.0,
		'전설' => 36.5,
		'신화' => 49.0,
		'불멸' => 60.0,
		'유일' => 68.0
	);

	$heroes_expected = 0.0;
	foreach ($deck as $hero) {
		$rank = isset($hero['hero_rank']) ? $hero['hero_rank'] : '일반';
		$avg = isset($rank_avg_map[$rank]) ? (float)$rank_avg_map[$rank] : 7.5;
		$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));
		$heroes_expected += $avg * $hero_count * $men_mult * $expected_crit_mult;
	}

	$expected_turn_damage = ($commander_expected + $heroes_expected) * $agi_mult;
	return max(10, (int)floor($expected_turn_damage));
}

function apply_adaptive_hp_diminishing($expected_turn_damage, $floor_reference_turn_damage, $is_boss = false) {
	$expected = max(1.0, (float)$expected_turn_damage);
	$reference = max(1.0, (float)$floor_reference_turn_damage);
	if ($expected <= $reference) return (int)round($expected);

	$excess = $expected - $reference;
	$linear_ratio = $is_boss ? 0.50 : 0.42;
	$sqrt_gain = $is_boss ? 2.1 : 1.8;
	$diminished_excess = ($excess * $linear_ratio) + (sqrt($excess) * $sqrt_gain);

	return max(1, (int)round($reference + $diminished_excess));
}

function get_total_hero_units(PDO $pdo, $uid) {
	$st = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM tb_heroes WHERE uid = ? AND quantity > 0");
	$st->execute(array($uid));
	return (int)$st->fetchColumn();
}

function get_commander_level_band($level) {
	$lv = max(1, (int)$level);
	if ($lv <= 10) return array('exp_mult' => 100, 'sp' => 3, 'hp' => 12, 'mp' => 6);
	if ($lv <= 20) return array('exp_mult' => 140, 'sp' => 3, 'hp' => 14, 'mp' => 7);
	if ($lv <= 30) return array('exp_mult' => 190, 'sp' => 4, 'hp' => 16, 'mp' => 8);
	if ($lv <= 40) return array('exp_mult' => 250, 'sp' => 4, 'hp' => 18, 'mp' => 10);
	if ($lv <= 50) return array('exp_mult' => 320, 'sp' => 5, 'hp' => 20, 'mp' => 12);
	if ($lv <= 100) return array('exp_mult' => 420, 'sp' => 5, 'hp' => 22, 'mp' => 13);
	if ($lv <= 200) return array('exp_mult' => 520, 'sp' => 6, 'hp' => 24, 'mp' => 14);
	if ($lv <= 300) return array('exp_mult' => 680, 'sp' => 6, 'hp' => 26, 'mp' => 15);
	if ($lv <= 400) return array('exp_mult' => 860, 'sp' => 7, 'hp' => 28, 'mp' => 16);
	if ($lv <= 500) return array('exp_mult' => 1060, 'sp' => 7, 'hp' => 30, 'mp' => 18);
	if ($lv <= 650) return array('exp_mult' => 1280, 'sp' => 8, 'hp' => 32, 'mp' => 19);
	if ($lv <= 800) return array('exp_mult' => 1530, 'sp' => 8, 'hp' => 34, 'mp' => 21);
	if ($lv <= 900) return array('exp_mult' => 1810, 'sp' => 9, 'hp' => 36, 'mp' => 22);
	return array('exp_mult' => 2120, 'sp' => 10, 'hp' => 40, 'mp' => 24);
}

function get_required_exp_for_next_level($level) {
	$lv = max(1, (int)$level);
	if ($lv >= 1000) return 0;
	$band = get_commander_level_band($lv);
	return (int)$band['exp_mult'] * $lv;
}

function apply_commander_exp_gain(PDO $pdo, $uid, $cmd, $exp_gain) {
	$level = max(1, (int)$cmd['level']);
	$exp = max(0, (int)$cmd['exp']) + max(0, (int)$exp_gain);
	$stat_points = max(0, (int)$cmd['stat_points']);
	$max_hp = max(1, (int)$cmd['max_hp']);
	$hp = max(0, (int)$cmd['hp']);
	$max_mp = max(1, (int)$cmd['max_mp']);
	$mp = max(0, (int)$cmd['mp']);
	$levelup_logs = array();

	while ($level < 1000) {
		$need = get_required_exp_for_next_level($level);
		if ($need <= 0 || $exp < $need) break;
		$exp -= $need;
		$level++;
		$band = get_commander_level_band($level);
		$stat_points += (int)$band['sp'];
		$max_hp += (int)$band['hp'];
		$hp += (int)$band['hp'];
		$max_mp += (int)$band['mp'];
		$mp += (int)$band['mp'];
		$levelup_logs[] = "🌟 레벨업! Lv.{$level} 달성 (SP +{$band['sp']}, HP +{$band['hp']}, MP +{$band['mp']})";
	}

	if ($level >= 1000) {
		$level = 1000;
		$exp = 0;
	}

	$hp = min($max_hp, $hp);
	$mp = min($max_mp, $mp);

	$pdo->prepare("UPDATE tb_commanders SET level = ?, exp = ?, stat_points = ?, max_hp = ?, hp = ?, max_mp = ?, mp = ? WHERE uid = ?")
		->execute(array($level, $exp, $stat_points, $max_hp, $hp, $max_mp, $mp, $uid));

	return array(
		'level' => $level,
		'exp' => $exp,
		'stat_points' => $stat_points,
		'max_hp' => $max_hp,
		'hp' => $hp,
		'max_mp' => $max_mp,
		'mp' => $mp,
		'exp_to_next' => get_required_exp_for_next_level($level),
		'levelup_logs' => $levelup_logs
	);
}

function get_battle_reward_bundle($floor, $mob_name = '') {
	$safe_floor = max(1, (int)$floor);
	$is_boss = (strpos((string)$mob_name, '[보스]') !== false);
	$gold_min = $is_boss ? 90 : 28;
	$gold_max = $is_boss ? 180 : 52;
	$exp_min = $is_boss ? 140 : 34;
	$exp_max = $is_boss ? 260 : 68;

	return array(
		'gold' => rand($gold_min, $gold_max) * max(1, (int)floor($safe_floor / 2)),
		'exp' => (int)floor(rand($exp_min, $exp_max) * (8 + (int)floor($safe_floor / 4)) / 2),
		'is_boss' => $is_boss
	);
}

function apply_commander_rewards(PDO $pdo, $uid, $cmd_state, $gold_gain, $exp_gain, $floor = 0) {
	$gold_gain = max(0, (int)$gold_gain);
	$exp_gain = max(0, (int)$exp_gain);
	$base_gold = isset($cmd_state['gold']) ? (int)$cmd_state['gold'] : 0;

	if ($gold_gain > 0) {
		$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($gold_gain, $uid));
	}

	if ($exp_gain > 0) {
		if ($floor > 0) {
			$ovr = max(0, (int)(isset($cmd_state['level']) ? $cmd_state['level'] : 1) - (int)$floor);
			$dim = max(0.55, 1.0 - 0.02 * $ovr);
			$exp_gain = (int)floor($exp_gain * $dim);
		}
		$progress = apply_commander_exp_gain($pdo, $uid, $cmd_state, $exp_gain);
	} else {
		$progress = array(
			'level' => isset($cmd_state['level']) ? (int)$cmd_state['level'] : 1,
			'exp' => isset($cmd_state['exp']) ? (int)$cmd_state['exp'] : 0,
			'stat_points' => isset($cmd_state['stat_points']) ? (int)$cmd_state['stat_points'] : 0,
			'max_hp' => isset($cmd_state['max_hp']) ? (int)$cmd_state['max_hp'] : 1,
			'hp' => isset($cmd_state['hp']) ? (int)$cmd_state['hp'] : 1,
			'max_mp' => isset($cmd_state['max_mp']) ? (int)$cmd_state['max_mp'] : 1,
			'mp' => isset($cmd_state['mp']) ? (int)$cmd_state['mp'] : 0,
			'exp_to_next' => get_required_exp_for_next_level(isset($cmd_state['level']) ? (int)$cmd_state['level'] : 1),
			'levelup_logs' => array()
		);
	}

	return array(
		'new_gold' => $base_gold + $gold_gain,
		'reward_gold' => $gold_gain,
		'reward_exp' => $exp_gain,
		'new_level' => (int)$progress['level'],
		'new_exp' => (int)$progress['exp'],
		'exp_to_next' => (int)$progress['exp_to_next'],
		'stat_points' => (int)$progress['stat_points'],
		'new_hp' => (int)$progress['hp'],
		'max_hp' => (int)$progress['max_hp'],
		'new_mp' => (int)$progress['mp'],
		'max_mp' => (int)$progress['max_mp'],
		'levelup_logs' => $progress['levelup_logs'],
		'levelup_count' => count($progress['levelup_logs'])
	);
}

function escape_attr_text($text) {
	$safe = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	return str_replace(array("\r\n", "\r", "\n"), '&#10;', $safe);
}

function build_hero_tooltip($hero) {
	global $hero_data;

	$name = isset($hero['hero_name']) ? (string)$hero['hero_name'] : '알 수 없는 영웅';
	$rank = isset($hero['hero_rank']) ? (string)$hero['hero_rank'] : '미상';
	$level = max(1, (int)(isset($hero['level']) ? $hero['level'] : 1));
	$quantity = max(1, (int)(isset($hero['quantity']) ? $hero['quantity'] : 1));
	$battle_count = max(0, (int)(isset($hero['battle_count']) ? $hero['battle_count'] : 0));

	$lines = array(
		"{$name} [{$rank}]",
		"레벨: {$level}",
		"보유 수량: {$quantity}"
	);

	if ((int)(isset($hero['is_on_expedition']) ? $hero['is_on_expedition'] : 0) === 1) {
		$lines[] = '상태: 파견 중';
	} elseif ((int)(isset($hero['is_equipped']) ? $hero['is_equipped'] : 0) === 1) {
		$lines[] = '상태: 출전 중';
	} else {
		$lines[] = '상태: 대기 중';
	}

	if ($battle_count > 0) {
		$lines[] = "전투 참여: {$battle_count}회";
	}

	$skills = isset($hero_data[$name]['skills']) && is_array($hero_data[$name]['skills']) ? $hero_data[$name]['skills'] : array();
	if (!empty($skills)) {
		$lines[] = '스킬:';
		foreach ($skills as $skill) {
			if (!is_array($skill)) continue;
			$skill_name = isset($skill['name']) ? trim((string)$skill['name']) : '';
			$skill_desc = isset($skill['description']) ? trim((string)$skill['description']) : '';
			if ($skill_name === '' && $skill_desc === '') continue;
			if ($skill_name !== '' && $skill_desc !== '') {
				$lines[] = "- {$skill_name}: {$skill_desc}";
			} elseif ($skill_name !== '') {
				$lines[] = "- {$skill_name}";
			} else {
				$lines[] = "- {$skill_desc}";
			}
		}
	} else {
		$lines[] = '스킬 정보: 없음';
	}

	return implode("\n", $lines);
}

function generate_hero_lists($heroes) {
	$deck_html = '';
	$inv_html = '';
	$deck_count = 0;

	foreach ($heroes as $h) {
		$color = isset($GLOBALS['colors'][$h['hero_rank']]) ? $GLOBALS['colors'][$h['hero_rank']] : '#fff';
		$tooltip = escape_attr_text(build_hero_tooltip($h));
		$card = "<div title='{$tooltip}' style='background:#222; padding:10px; margin-bottom:5px; border-radius:4px; border-left:3px solid {$color}; display:flex; justify-content:space-between; align-items:center; cursor:help;'>";
		$card .= "<div title='{$tooltip}'><span style='color:{$color}; font-size:0.8rem;'>[{$h['hero_rank']}]</span> <span style='font-weight:bold;'>{$h['hero_name']}</span> (x{$h['quantity']})</div>";

		if ((int)$h['is_equipped'] === 1 && (int)$h['is_on_expedition'] === 0) {
			$card .= "<button class='btn' title='출전 덱에서 해제' style='padding:5px 10px; font-size:0.8rem; background:#555;' onclick='toggleEquip({$h['inv_id']}, -1)'>해제</button></div>";
			$deck_html .= $card;
			$deck_count += max(1, (int)$h['quantity']);
		} else {
			if ((int)$h['is_on_expedition'] === 1) {
				$card .= "<span style='color:#ff9800; font-size:0.8rem; font-weight:bold;'>[파견중]</span></div>";
			} else {
				$card .= "<div style='display:flex; gap:6px;'>";
				$card .= "<button class='btn' title='출전 덱에 추가' style='padding:5px 10px; font-size:0.8rem;' onclick='toggleEquip({$h['inv_id']}, 1)'>출전</button>";
				if (in_array($h['hero_rank'], array('일반', '희귀'), true) && (int)$h['quantity'] >= 3) {
					$hero_name_js = json_encode($h['hero_name']);
					$card .= "<button class='btn' title='동일 영웅 3기를 상위 등급으로 합성' style='padding:5px 10px; font-size:0.8rem; background:#2f7d32;' onclick='synthesizeHero({$hero_name_js})'>합성</button>";
				}
				$card .= "</div></div>";
			}
			$inv_html .= $card;
		}
	}

	if ($deck_count === 0) $deck_html = "<div style='color:#777; font-size:0.9rem; text-align:center; padding:10px;'>출전 중인 영웅이 없습니다.</div>";
	if ($inv_html === '') $inv_html = "<div style='color:#777; font-size:0.9rem; text-align:center; padding:10px;'>보유 중인 대기 영웅이 없습니다.</div>";
	return array($deck_html, $inv_html, $deck_count);
}

function get_skill_effects_for_level($skill_def, $hero_level) {
	$effects_map = array();
	if (isset($skill_def['effects']) && is_array($skill_def['effects'])) {
		foreach ($skill_def['effects'] as $eff) {
			if (!isset($eff['type'])) continue;
			$effects_map[$eff['type']] = $eff;
		}
	}
	if (isset($skill_def['level_up']) && is_array($skill_def['level_up'])) {
		$keys = array_keys($skill_def['level_up']);
		sort($keys, SORT_NUMERIC);
		foreach ($keys as $lv_req) {
			if ((int)$hero_level < (int)$lv_req) continue;
			$up = $skill_def['level_up'][$lv_req];
			if (!isset($up['effects']) || !is_array($up['effects'])) continue;
			foreach ($up['effects'] as $eff) {
				if (!isset($eff['type'])) continue;
				$effects_map[$eff['type']] = array_merge(isset($effects_map[$eff['type']]) ? $effects_map[$eff['type']] : array(), $eff);
			}
		}
	}
	return array_values($effects_map);
}

function apply_dynamic_effect($effect, $skill_def, &$hero, $hero_count, $base_damage, $is_critical, &$cmd, &$new_mob_hp, &$logs, &$total_gold_gain) {
	if (!isset($effect['type'])) return;
	$type = $effect['type'];
	$value = isset($effect['value']) ? (float)$effect['value'] : 0.0;
	$duration = isset($effect['duration']) ? (float)$effect['duration'] : 0.0;
	$hero_name = $hero['hero_name'];
	$skill_name = isset($skill_def['name']) ? $skill_def['name'] : $type;
	$is_boss = (strpos((string)$cmd['mob_name'], '[보스]') !== false);

	if (!isset($_SESSION['combat_state'])) $_SESSION['combat_state'] = array();
	if (!isset($_SESSION['combat_state']['enemy_debuffs'])) $_SESSION['combat_state']['enemy_debuffs'] = array();

	switch ($type) {
		case 'add_gold':
			$gain = max(1, (int)round($value)) * max(1, (int)$hero_count);
			$total_gold_gain += $gain;
			$logs[] = "💰 <span style='color:gold;'>[{$hero_name}]</span> {$skill_name} 발동! <b>+{$gain}G</b>";
			break;
		case 'damage_to_boss_increase_percentage':
			if ($is_boss && $base_damage > 0) {
				$bonus = max(1, (int)floor($base_damage * ($value / 100)));
				$new_mob_hp = max(0, $new_mob_hp - $bonus);
				$logs[] = "🌊 <span style='color:cyan;'>[{$hero_name}]</span> {$skill_name}! 보스 추가 피해 <b>{$bonus}</b>";
			}
			break;
		case 'damage_single_magic':
		case 'damage_single_physical':
		case 'damage_aoe_magic':
		case 'damage_aoe_physical':
		case 'damage_all_enemies_per_second_magic':
			if ($base_damage > 0) {
				$extra = max(1, (int)floor($base_damage * ($value / 100)));
				$new_mob_hp = max(0, $new_mob_hp - $extra);
				$logs[] = "✨ <span style='color:#80d8ff;'>[{$hero_name}]</span> {$skill_name} 추가 피해 <b>{$extra}</b>";
			}
			break;
		case 'true_damage_fixed':
			$td = ($base_damage > 0) ? max(1, (int)floor($base_damage * ($value / 100))) : max(1, (int)$value);
			$new_mob_hp = max(0, $new_mob_hp - $td);
			$logs[] = "🩸 <span style='color:#ff8a80;'>[{$hero_name}]</span> 고정 피해 <b>{$td}</b>";
			break;
		case 'critical_damage_increase':
			if ($is_critical && $base_damage > 0) {
				$bonus = max(1, (int)floor($base_damage * ($value / 100)));
				$new_mob_hp = max(0, $new_mob_hp - $bonus);
				$logs[] = "⚡ <span style='color:#ffeb3b;'>[{$hero_name}]</span> 치명 증폭 +{$bonus}";
			}
			break;
		case 'stun':
		case 'freeze':
		case 'freeze_aoe':
		case 'freeze_all_enemies':
			$turns = max(1, (int)ceil($duration));
			$cur = isset($_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left']) ? (int)$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] : 0;
			$_SESSION['combat_state']['enemy_debuffs']['stun'] = array('turns_left' => max($cur, $turns), 'source' => $hero_name);
			$logs[] = "❄️ <span style='color:#b3e5fc;'>[{$hero_name}]</span> {$skill_name} 발동! 적이 {$turns}턴 행동불가";
			break;
		case 'shred_armor_flat_aura':
		case 'shred_armor_flat_single':
		case 'piercing_attack':
			$cur_shred = isset($_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value']) ? (float)$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value'] : 0;
			$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat'] = array('value' => max($cur_shred, $value), 'source' => $hero_name);
			$logs[] = "🛡️ <span style='color:orange;'>[{$hero_name}]</span> 방어 약화 적용 ({$value})";
			break;
		default:
			// 미구현 효과 타입은 무시 (전투 안정성 우선)
			break;
	}
}

function apply_hero_skills(&$hero, &$new_mob_hp, &$logs, &$total_gold_gain, &$cmd, $base_damage = 0, $is_critical = false) {
	global $hero_data;
	if (!isset($hero_data[$hero['hero_name']])) return;

	$hero_skills_def = isset($hero_data[$hero['hero_name']]['skills']) ? $hero_data[$hero['hero_name']]['skills'] : array();
	$skill_level = isset($hero['level']) ? (int)$hero['level'] : 1;
	$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));

	if (!isset($_SESSION['combat_state']['hero_attack_counts'])) $_SESSION['combat_state']['hero_attack_counts'] = array();
	if (!isset($_SESSION['combat_state']['hero_attack_counts'][$hero['hero_name']])) $_SESSION['combat_state']['hero_attack_counts'][$hero['hero_name']] = 0;
	$_SESSION['combat_state']['hero_attack_counts'][$hero['hero_name']] += 1;
	$hero_hit_count = $_SESSION['combat_state']['hero_attack_counts'][$hero['hero_name']];

	foreach ($hero_skills_def as $skill_def) {
		$skill_type = isset($skill_def['type']) ? $skill_def['type'] : 'on_attack';
		$is_passive = ($skill_type === 'passive_aura' || $skill_type === 'passive_buff');

		if ($is_passive) {
			if (!isset($_SESSION['combat_state']['passives_applied'])) $_SESSION['combat_state']['passives_applied'] = array();
			$key = $hero['hero_name'] . '::' . (isset($skill_def['name']) ? $skill_def['name'] : 'passive');
			if (isset($_SESSION['combat_state']['passives_applied'][$key])) continue;
			$_SESSION['combat_state']['passives_applied'][$key] = 1;
		}

		if ($skill_type === 'on_attack_nth') {
			$nth = isset($skill_def['nth']) ? max(1, (int)$skill_def['nth']) : 1;
			if ($hero_hit_count % $nth !== 0) continue;
		}

		$chance = isset($skill_def['trigger_chance']) ? (int)$skill_def['trigger_chance'] : 0;
		if ($chance <= 0 && isset($skill_def['description']) && preg_match('/(\d+)% 확률/', $skill_def['description'], $m)) $chance = (int)$m[1];
		$men_skill_bonus = max(0, (int)floor(((int)$cmd['stat_men']) / 10) * 2);
		if ($chance > 0) $chance = min(95, $chance + $men_skill_bonus);
		if (!$is_passive && $chance > 0 && rand(1, 100) > $chance) continue;

		$effects = get_skill_effects_for_level($skill_def, $skill_level);
		foreach ($effects as $eff) {
			apply_dynamic_effect($eff, $skill_def, $hero, $hero_count, (int)$base_damage, (bool)$is_critical, $cmd, $new_mob_hp, $logs, $total_gold_gain);
		}
	}
}

function sanitize_event_title($title) {
	$t = trim((string)$title);
	$t = preg_replace('/\s*#\d+\s*$/u', '', $t);
	return trim($t);
}

function normalize_event_title_for_context($event_type, $title) {
	$clean = sanitize_event_title($title);
	if ((string)$event_type !== 'encounter') return $clean;
	if (preg_match('/승리|완승|격파|처치|섬멸|토벌\s*완료/u', $clean)) {
		return '교전';
	}
	return $clean;
}

function get_floor_balance_profile($floor) {
	$safe_floor = max(1, (int)$floor);
	$profiles = array(
		array('max_floor' => 20, 'atk_scale' => 1.00, 'hp_scale' => 1.00, 'normal_turns' => 5, 'boss_turns' => 9, 'var_min' => 90, 'var_max' => 110),
		array('max_floor' => 40, 'atk_scale' => 0.95, 'hp_scale' => 1.02, 'normal_turns' => 5, 'boss_turns' => 9, 'var_min' => 90, 'var_max' => 110),
		array('max_floor' => 60, 'atk_scale' => 0.90, 'hp_scale' => 1.05, 'normal_turns' => 6, 'boss_turns' => 10, 'var_min' => 90, 'var_max' => 110),
		array('max_floor' => 80, 'atk_scale' => 0.86, 'hp_scale' => 1.08, 'normal_turns' => 6, 'boss_turns' => 10, 'var_min' => 91, 'var_max' => 109),
		array('max_floor' => 120, 'atk_scale' => 0.82, 'hp_scale' => 1.12, 'normal_turns' => 6, 'boss_turns' => 11, 'var_min' => 92, 'var_max' => 108),
		array('max_floor' => 9999, 'atk_scale' => 0.78, 'hp_scale' => 1.16, 'normal_turns' => 7, 'boss_turns' => 11, 'var_min' => 93, 'var_max' => 107)
	);

	foreach ($profiles as $profile) {
		if ($safe_floor <= (int)$profile['max_floor']) {
			return $profile;
		}
	}

	return $profiles[count($profiles) - 1];
}

function handle_action(PDO $pdo) {
	app_log('handle_action.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		if ((int)$cmd['hp'] <= 0) { $pdo->rollBack(); json_error('사망 상태입니다.'); return; }
		cleanup_combat_if_stale($pdo, $uid, $cmd);
		if ((int)$cmd['is_combat'] === 1) { $pdo->rollBack(); json_error('대치 중인 적이 있습니다. 처치하거나 도망쳐야 합니다.'); return; }

		$current_floor = (int)$cmd['current_floor'];
		$new_floor = $current_floor;
		$max_floor = max((int)$cmd['max_floor'], $current_floor);
		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_vit = (int)$cmd['stat_vit'];
		$mp_regen = max(0, (int)floor($p_mag / 10));
		$new_mp_common = min((int)$cmd['max_mp'], (int)$cmd['mp'] + $mp_regen);

		// 오버레벨 자동 층 이동 (플레이어 레벨이 현재 층 + 5 초과 시)
		if ((int)$cmd['level'] > $current_floor + 5) {
			$adv_floor = $current_floor + 1;
			$adv_max_floor = max((int)$cmd['max_floor'], $adv_floor);
			$auto_log = "⚡ 현재 층의 난이도가 너무 낮습니다. <b>{$adv_floor}층</b>으로 자동 이동합니다.";
			if ($adv_floor % 10 === 0) {
				$diff = pow(2, floor(($adv_floor - 1) / 10));
				$balance = get_floor_balance_profile($adv_floor);
				$atk_scale = (float)$balance['atk_scale'];
				$hp_scale = (float)$balance['hp_scale'];
				$bosses = array('거대 고기 슬라임', '폭주 로봇', '심연의 뱀파이어', '파괴자 나하투', '고대 드래곤', '리치 왕');
				$mob_name = '[보스] ' . $bosses[array_rand($bosses)];
				$base_mob_hp = (40 + rand(0, 20)) * $diff * 10 * $hp_scale;
				$mob_atk = max(1, (int)round(((8 + rand(0, 5)) * $diff) * $atk_scale));
				$expected_turn_damage = estimate_expected_turn_damage($pdo, $uid, $cmd);
				$target_turns = (int)$balance['boss_turns'];
				$var_min = (int)$balance['var_min']; $var_max = (int)$balance['var_max'];
				if ($var_min > $var_max) { $tmp = $var_min; $var_min = $var_max; $var_max = $tmp; }
				$variance = rand($var_min, $var_max) / 100.0;
				$floor_reference_turn_damage = max(10, (int)round($base_mob_hp / max(1, $target_turns)));
				$effective_turn_damage = apply_adaptive_hp_diminishing($expected_turn_damage, $floor_reference_turn_damage, true);
				$adaptive_hp = (int)round((($effective_turn_damage * $target_turns) + ($base_mob_hp * 0.12)) * $variance);
				$min_hp = max(300, (int)floor($base_mob_hp * 0.30));
				$mob_max_hp = max($min_hp, $adaptive_hp);
				$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, mp = ?, is_combat = 1, mob_name = ?, mob_hp = ?, mob_max_hp = ?, mob_atk = ? WHERE uid = ?")
					->execute(array($adv_floor, $adv_max_floor, $new_mp_common, $mob_name, $mob_max_hp, $mob_max_hp, $mob_atk, $uid));
				$boss_log = $auto_log . " 보스 <b>{$mob_name}</b> 출현!";
				$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $boss_log));
				$pdo->commit();
				echo json_encode(array(
					'status' => 'encounter', 'stream' => false,
					'msg' => $boss_log, 'new_floor' => $adv_floor,
					'new_hp' => (int)$cmd['hp'], 'max_hp' => (int)$cmd['max_hp'],
					'new_mp' => $new_mp_common, 'max_mp' => (int)$cmd['max_mp'],
					'mob_name' => $mob_name, 'mob_max_hp' => $mob_max_hp
				));
				return;
			}
			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, mp = ? WHERE uid = ?")
				->execute(array($adv_floor, $adv_max_floor, $new_mp_common, $uid));
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $auto_log));
			$pdo->commit();
			echo json_encode(array(
				'status' => 'auto_advance', 'stream' => false,
				'msg' => $auto_log, 'new_floor' => $adv_floor,
				'new_hp' => (int)$cmd['hp'], 'max_hp' => (int)$cmd['max_hp'],
				'new_mp' => $new_mp_common, 'max_mp' => (int)$cmd['max_mp']
			));
			return;
		}

		$event_stmt = $pdo->prepare("SELECT event_id, event_type, event_title, ai_seed, weight FROM tb_explore_events WHERE is_enabled = 1 AND ? BETWEEN min_floor AND max_floor");
		$event_stmt->execute(array($new_floor));
		$event_rows = $event_stmt->fetchAll();
		$selected_event = null;
		if (!empty($event_rows)) {
			$total_weight = 0;
			foreach ($event_rows as $er) {
				$total_weight += max(1, (int)$er['weight']);
			}
			$roll = rand(1, max(1, $total_weight));
			$acc = 0;
			foreach ($event_rows as $er) {
				$acc += max(1, (int)$er['weight']);
				if ($roll <= $acc) {
					$selected_event = $er;
					break;
				}
			}
		}
		if (!$selected_event) {
			$selected_event = array('event_type' => 'encounter', 'event_title' => '적 조우 (기본)', 'ai_seed' => '어둠 속에서 적의 기척이 급격히 가까워진다.');
		}

		$event_type = isset($selected_event['event_type']) ? (string)$selected_event['event_type'] : 'encounter';
		$event_title_raw = isset($selected_event['event_title']) ? (string)$selected_event['event_title'] : '알 수 없는 사건';
		$event_title = normalize_event_title_for_context($event_type, $event_title_raw);
		$event_seed = isset($selected_event['ai_seed']) ? (string)$selected_event['ai_seed'] : '';

		$resp = array('status' => 'safe');
		$log = '';
		$resp['event_type'] = $event_type;
		$resp['event_title'] = $event_title;

		if ($event_type === 'encounter') {
			// 전투 조우
			$diff = pow(2, floor(($new_floor - 1) / 10));
			$is_boss = ($new_floor % 10 === 0);
			$balance = get_floor_balance_profile($new_floor);
			$atk_scale = (float)$balance['atk_scale'];
			$hp_scale = (float)$balance['hp_scale'];
			if ($is_boss) {
				$bosses = array('거대 고기 슬라임', '폭주 로봇', '심연의 뱀파이어', '파괴자 나하투', '고대 드래곤', '리치 왕');
				$mob_name = '[보스] ' . $bosses[array_rand($bosses)];
				$base_mob_hp = (40 + rand(0, 20)) * $diff * 10 * $hp_scale;
				$mob_atk = max(1, (int)round(((8 + rand(0, 5)) * $diff) * $atk_scale));
			} else {
				$mobs = array('고블린', '스켈레톤', '오크', '슬라임', '늑대인간', '동굴거미', '미믹', '광신도');
				$mob_name = (($new_floor > 50) ? '타락한 ' : '굶주린 ') . $mobs[array_rand($mobs)];
				$base_mob_hp = (40 + rand(0, 20)) * $diff * $hp_scale;
				$mob_atk = max(1, (int)round(((8 + rand(0, 5)) * $diff) * $atk_scale));
			}

			$expected_turn_damage = estimate_expected_turn_damage($pdo, $uid, $cmd);
			$target_turns = $is_boss ? (int)$balance['boss_turns'] : (int)$balance['normal_turns'];
			$var_min = (int)$balance['var_min'];
			$var_max = (int)$balance['var_max'];
			if ($var_min > $var_max) {
				$tmp = $var_min;
				$var_min = $var_max;
				$var_max = $tmp;
			}
			$variance = rand($var_min, $var_max) / 100.0;
			$floor_reference_turn_damage = max(10, (int)round($base_mob_hp / max(1, $target_turns)));
			$effective_turn_damage = apply_adaptive_hp_diminishing($expected_turn_damage, $floor_reference_turn_damage, $is_boss);
			$adaptive_hp = (int)round((($effective_turn_damage * $target_turns) + ($base_mob_hp * ($is_boss ? 0.12 : 0.08))) * $variance);
			$min_hp = $is_boss ? max(300, (int)floor($base_mob_hp * 0.30)) : max(60, (int)floor($base_mob_hp * 0.25));
			$mob_max_hp = max($min_hp, $adaptive_hp);

			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, mp = ?, is_combat = 1, mob_name = ?, mob_hp = ?, mob_max_hp = ?, mob_atk = ? WHERE uid = ?")
				->execute(array($new_floor, $max_floor, $new_mp_common, $mob_name, $mob_max_hp, $mob_max_hp, $mob_atk, $uid));
			$resp['status'] = 'encounter';
			$resp['mob_name'] = $mob_name;
			$resp['mob_max_hp'] = $mob_max_hp;
			$resp['new_floor'] = $new_floor;
			$resp['new_mp'] = $new_mp_common;
			$resp['max_mp'] = (int)$cmd['max_mp'];
			$log = "⚔️ <b>[{$event_title}]</b> {$event_seed} <b>[{$mob_name}]</b> 출현!";
		} else {
			// 안전 이벤트
			$hp = (int)$cmd['hp'];
			$max_hp = (int)$cmd['max_hp'];
			$mp = $new_mp_common;
			$max_mp = (int)$cmd['max_mp'];
			$reward_gold = 0;
			$reward_exp = 0;

			if ($event_type === 'gold') {
				$base_gold = rand(30, 140) * max(1, floor($current_floor / 2));
				$reward_gold = (int)floor($base_gold * (1 + ($p_luk * 0.01)));
				$log = "💰 <b>[{$event_title}]</b> {$event_seed} <b>{$reward_gold}G</b> 획득.";
			} elseif ($event_type === 'chest') {
				$base_gold = rand(80, 260) * max(1, floor($current_floor / 2));
				$reward_gold = (int)floor($base_gold * (1 + ($p_luk * 0.01)));
				$heal = rand(4, 12);
				$hp = min($max_hp, $hp + $heal);
				$log = "🎁 <b>[{$event_title}]</b> {$event_seed} <b>{$reward_gold}G</b> + HP <b>{$heal}</b>.";
			} elseif ($event_type === 'exp') {
				$reward_exp = rand(20, 45) * max(1, $current_floor);
				$log = "📘 <b>[{$event_title}]</b> {$event_seed} 경험치 <b>+{$reward_exp}</b>.";
			} elseif ($event_type === 'mana_spring') {
				$mana_gain = rand(12, 28) + (int)floor($p_mag / 6);
				$mp = min($max_mp, $mp + $mana_gain);
				$log = "🔷 <b>[{$event_title}]</b> {$event_seed} MP <b>+{$mana_gain}</b>.";
			} elseif ($event_type === 'trap') {
				$break_chance = min(35, (int)floor($p_str / 3));
				if (rand(1, 100) <= $break_chance) {
					$log = "🪓 <b>[{$event_title}]</b> {$event_seed} STR 발동으로 피해 무효.";
				} else {
					$dmg = max(1, rand(5, 15) - (int)floor($p_vit / 2));
					if (rand(1, 100) <= min(40, (int)floor($p_luk / 2))) {
						$reward_gold = (int)floor(rand(5, 25) * (1 + ($p_luk * 0.01)));
						$log = "🍀 <b>[{$event_title}]</b> 함정을 회피하고 <b>{$reward_gold}G</b> 획득.";
					} else {
						$hp = max(1, $hp - $dmg);
						$log = "🩸 <b>[{$event_title}]</b> {$event_seed} HP <b>-{$dmg}</b>.";
					}
				}
			} else {
				$hp = min($max_hp, $hp + 6);
				$log = "👣 <b>[{$event_title}]</b> {$event_seed} 전열을 정비했습니다.";
			}

			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, hp = ?, mp = ? WHERE uid = ?")
				->execute(array($new_floor, $max_floor, $hp, $mp, $uid));

			$resp['new_hp'] = $hp;
			$resp['max_hp'] = $max_hp;
			$resp['new_mp'] = $mp;
			$resp['max_mp'] = $max_mp;
			$resp['new_floor'] = $new_floor;

			if ($reward_gold > 0 || $reward_exp > 0) {
				$reward_state = $cmd;
				$reward_state['hp'] = $hp;
				$reward_state['max_hp'] = $max_hp;
				$reward_state['mp'] = $mp;
				$reward_state['max_mp'] = $max_mp;
				$reward_meta = apply_commander_rewards($pdo, $uid, $reward_state, $reward_gold, $reward_exp);
				$resp = array_merge($resp, $reward_meta);
			}
		}

		$resp['log'] = $log;
		$resp['stream'] = false;
		unset($_SESSION['ai_stream_text']);
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();
		echo json_encode($resp);
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error('탐색 중 오류가 발생했습니다.');
	}
}

function handle_next_floor(PDO $pdo) {
	app_log('handle_next_floor.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['hp'] <= 0) throw new Exception('사망 상태에서는 이동할 수 없습니다.');
		if ((int)$cmd['is_combat'] === 1) throw new Exception('전투 중에는 이동할 수 없습니다.');

		$new_floor = (int)$cmd['current_floor'] + 1;
		$new_max_floor = max((int)$cmd['max_floor'], $new_floor);
		$mp_regen = max(0, (int)floor(((int)$cmd['max_mp']) * 0.02));
		$new_mp = min((int)$cmd['max_mp'], (int)$cmd['mp'] + $mp_regen);

		if ($new_floor % 10 === 0) {
			$diff = pow(2, floor(($new_floor - 1) / 10));
			$balance = get_floor_balance_profile($new_floor);
			$atk_scale = (float)$balance['atk_scale'];
			$hp_scale = (float)$balance['hp_scale'];
			$bosses = array('거대 고기 슬라임', '폭주 로봇', '심연의 뱀파이어', '파괴자 나하투', '고대 드래곤', '리치 왕');
			$mob_name = '[보스] ' . $bosses[array_rand($bosses)];
			$base_mob_hp = (40 + rand(0, 20)) * $diff * 10 * $hp_scale;
			$mob_atk = max(1, (int)round(((8 + rand(0, 5)) * $diff) * $atk_scale));

			$expected_turn_damage = estimate_expected_turn_damage($pdo, $uid, $cmd);
			$target_turns = (int)$balance['boss_turns'];
			$var_min = (int)$balance['var_min'];
			$var_max = (int)$balance['var_max'];
			if ($var_min > $var_max) {
				$tmp = $var_min;
				$var_min = $var_max;
				$var_max = $tmp;
			}
			$variance = rand($var_min, $var_max) / 100.0;
			$floor_reference_turn_damage = max(10, (int)round($base_mob_hp / max(1, $target_turns)));
			$effective_turn_damage = apply_adaptive_hp_diminishing($expected_turn_damage, $floor_reference_turn_damage, true);
			$adaptive_hp = (int)round((($effective_turn_damage * $target_turns) + ($base_mob_hp * 0.12)) * $variance);
			$min_hp = max(300, (int)floor($base_mob_hp * 0.30));
			$mob_max_hp = max($min_hp, $adaptive_hp);

			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, mp = ?, is_combat = 1, mob_name = ?, mob_hp = ?, mob_max_hp = ?, mob_atk = ? WHERE uid = ?")
				->execute(array($new_floor, $new_max_floor, $new_mp, $mob_name, $mob_max_hp, $mob_max_hp, $mob_atk, $uid));

			$log = "⬆️ 다음 층으로 이동했습니다. <b>{$new_floor}층</b> 도달과 동시에 <b>{$mob_name}</b> 출현!";
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
			$pdo->commit();

			echo json_encode(array(
				'status' => 'encounter',
				'msg' => $log,
				'new_floor' => $new_floor,
				'new_hp' => (int)$cmd['hp'],
				'max_hp' => (int)$cmd['max_hp'],
				'new_mp' => $new_mp,
				'max_mp' => (int)$cmd['max_mp'],
				'mob_name' => $mob_name,
				'mob_max_hp' => $mob_max_hp
			));
			return;
		}

		$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, mp = ? WHERE uid = ?")
			->execute(array($new_floor, $new_max_floor, $new_mp, $uid));

		$log = "⬆️ 다음 층으로 이동했습니다. <b>{$new_floor}층</b>";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'msg' => $log,
			'new_floor' => $new_floor,
			'new_hp' => (int)$cmd['hp'],
			'max_hp' => (int)$cmd['max_hp'],
			'new_mp' => $new_mp,
			'max_mp' => (int)$cmd['max_mp']
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_combat(PDO $pdo) {
	app_log('handle_combat.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['is_combat'] === 0 || (int)$cmd['hp'] <= 0) { $pdo->rollBack(); json_error('전투 불가'); return; }

		cleanup_combat_if_stale($pdo, $uid, $cmd);
		if ((int)$cmd['is_combat'] === 0) { $pdo->commit(); json_error('전투 정보가 정리되었습니다.'); return; }

		if (!isset($_SESSION['combat_state'])) $_SESSION['combat_state'] = array('hero_attack_counts' => array(), 'enemy_debuffs' => array());

		$logs = array();
		$new_mob_hp = (int)$cmd['mob_hp'];
		$new_hp = (int)$cmd['hp'];
		$incoming_damage = 0;
		$incoming_damage_source = '';
		$total_hero_turn_damage = 0;
		$turn_damage_details = array();
		$hero_damage_map = array();
		$hero_crit_hits = 0;
		$max_hit_hero_name = '';
		$max_hit_hero_dmg = 0;

		$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
		$deck_stmt->execute(array($uid));
		$deck = $deck_stmt->fetchAll();

		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_agi = (int)$cmd['stat_agi'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_men = (int)$cmd['stat_men'];
		$p_vit = (int)$cmd['stat_vit'];
		$buff_vit = get_player_buff_value('vit');
		if ($buff_vit > 0) $p_vit += $buff_vit;
		$berserk_bonus_pct = max(0, get_player_buff_value('berserk_power'));

		$relic_stmt = $pdo->prepare("SELECT atk_bonus_percent FROM tb_relics WHERE uid = ? LIMIT 1");
		$relic_stmt->execute(array($uid));
		$relic = $relic_stmt->fetch();
		$relic_atk_bonus = (int)(isset($relic['atk_bonus_percent']) ? $relic['atk_bonus_percent'] : 0);

		$crit_chance = floor($p_luk / 2);
		$crit_mult = 1.5 + ($p_luk * 0.01);
		$men_mult = 1 + ($p_men * 0.005);
		$agi_double_chance = floor($p_agi / 5);
		$agi_evasion_chance = min(100, max(0, (int)floor($p_agi / 4)));
		$vit_block_chance = floor($p_vit / 5);
		$hero_shield_chance = (count($deck) > 0) ? min(40, (int)floor($p_vit / 10)) : 0;
		$str_party_bonus_pct = (int)floor($p_str / 10) * 2;
		$mag_party_bonus_pct = (int)floor($p_mag / 10) * 2;
		$physical_heroes = array('늑대전사', '배트맨', '블롭', '베인', '닌자', '마스터 쿤', '골라조', '산적', '야만인', '레인저', '보안관', '호랑이사부');
		$magic_heroes = array('냥법사', '콜디', '펄스생성기', '오크주술사', '중력자탄', '전기로봇', '충격로봇', '물의정령', '샌드맨', '마마', '아토', '와트', '타르');

		// 공격 버튼 기본 피해는 STR 중심으로 계산
		$player_base = max(1, (int)floor(($p_str * 1.8) + rand(4, 12)));
		if ($relic_atk_bonus > 0) $player_base = (int)floor($player_base * (1 + ($relic_atk_bonus / 100)));
		if ($berserk_bonus_pct > 0) $player_base = (int)floor($player_base * (1 + ($berserk_bonus_pct / 100)));
		$is_crit = (rand(1, 100) <= $crit_chance);
		$player_dmg = $is_crit ? floor($player_base * $crit_mult) : $player_base;
		$turn_damage_details[] = array('name' => '사령관', 'damage' => (int)$player_dmg);
		$crit_txt = $is_crit ? "💥 <span style='color:#ffeb3b; font-weight:bold;'>[치명타!]</span> " : "";
		$logs[] = "{$crit_txt}🗡️ <b>[사령관]</b>의 공격! <b>{$cmd['mob_name']}</b>에게 <span style='color:#ff9800;'>{$player_dmg}</span> 피해.";
		$new_mob_hp = max(0, $new_mob_hp - $player_dmg);

		$total_gold_gain = 0;
		if ($new_mob_hp > 0 && count($deck) > 0) {
			$logs[] = "<div style='margin:5px 0; padding-left:10px; border-left:2px solid #555; color:#aaa; font-size:0.85rem;'>▼ 영웅들이 합세합니다!</div>";
			foreach ($deck as $hero) {
				if ($new_mob_hp <= 0) break;

				$attack_times = (rand(1, 100) <= $agi_double_chance) ? 2 : 1;
				for ($i = 0; $i < $attack_times; $i++) {
					if ($new_mob_hp <= 0) break;
					if ($i === 1) $logs[] = "💨 <span style='color:#00e5ff; font-weight:bold;'>[AGI 발동]</span> <b>{$hero['hero_name']}</b> 연속 공격!";

					$range_map = array('일반'=>array(5,10,'#aaa'), '희귀'=>array(10,20,'#4caf50'), '영웅'=>array(18,30,'#2196f3'), '전설'=>array(28,45,'#9c27b0'), '신화'=>array(38,60,'#ff5252'), '불멸'=>array(45,75,'#ffeb3b'));
					$r = isset($range_map[$hero['hero_rank']]) ? $range_map[$hero['hero_rank']] : array(5,10,'#8bc34a');
					$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));
					$hero_dmg = rand($r[0], $r[1]) * $hero_count;
					$hero_dmg = (int)floor($hero_dmg * $men_mult);
					if (in_array($hero['hero_name'], $physical_heroes, true) && $str_party_bonus_pct > 0) {
						$hero_dmg = (int)floor($hero_dmg * (1 + ($str_party_bonus_pct / 100)));
					} elseif (in_array($hero['hero_name'], $magic_heroes, true) && $mag_party_bonus_pct > 0) {
						$hero_dmg = (int)floor($hero_dmg * (1 + ($mag_party_bonus_pct / 100)));
					}

					$armor_break_flat = isset($_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value']) ? (float)$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value'] : 0;
					if ($armor_break_flat > 0) $hero_dmg = (int)floor($hero_dmg * (1 + min(2.0, $armor_break_flat / 100.0)));

					$is_h_crit = false;
					if (rand(1, 100) <= floor($p_luk / 2)) { $is_h_crit = true; $hero_dmg = (int)floor($hero_dmg * $crit_mult); }
					if ($is_h_crit) $hero_crit_hits++;

					$mob_hp_before_attack_skills = $new_mob_hp;
					apply_hero_skills($hero, $new_mob_hp, $logs, $total_gold_gain, $cmd, $hero_dmg, $is_h_crit);

					$hcrit = $is_h_crit ? "⚡ <span style='color:yellow; font-weight:bold;'>[치명타]</span> " : "⚔️ ";
					$logs[] = "{$hcrit}<span style='color:{$r[2]}'>[{$hero['hero_name']}]</span>(x{$hero_count})의 공격. {$hero_dmg} 피해.";
					$hero_total_damage = (int)$hero_dmg + max(0, (int)($mob_hp_before_attack_skills - $new_mob_hp));
					$total_hero_turn_damage += $hero_total_damage;
					if (!isset($hero_damage_map[$hero['hero_name']])) $hero_damage_map[$hero['hero_name']] = 0;
					$hero_damage_map[$hero['hero_name']] += $hero_total_damage;
					if ($hero_total_damage > $max_hit_hero_dmg) {
						$max_hit_hero_dmg = $hero_total_damage;
						$max_hit_hero_name = $hero['hero_name'];
					}
					$new_mob_hp = max(0, $new_mob_hp - $hero_dmg);
				}
			}
		}

		foreach ($hero_damage_map as $hero_name => $hero_damage) {
			$turn_damage_details[] = array('name' => (string)$hero_name, 'damage' => (int)$hero_damage);
		}

		$reward_meta = null;
		if ($new_mob_hp <= 0) {
			$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 쓰러졌습니다!";
			$logs[] = "⚡ <span style='color:#ffeb3b; font-weight:bold;'>[선제 제압]</span> 반격 없이 전투를 끝냈습니다!";
			$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
			$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => (int)$cmd['mp'])), (int)$battle_reward['gold'] + (int)$total_gold_gain, (int)$battle_reward['exp'], (int)$cmd['current_floor']);
			$logs[] = "🎖️ 전투 보상: <b>" . ((int)$battle_reward['gold'] + (int)$total_gold_gain) . "G</b>, 경험치 <b>+{$battle_reward['exp']}</b>.";
			foreach ($reward_meta['levelup_logs'] as $levelup_log) {
				$logs[] = $levelup_log;
			}
			$new_hp = (int)$reward_meta['new_hp'];
			unset($_SESSION['combat_state']);
			$status = 'victory';
		} else {
			$stun_turns = isset($_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left']) ? (int)$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] : 0;
			if ($stun_turns > 0) {
				$logs[] = "🧊 <b>{$cmd['mob_name']}</b>은(는) 상태이상으로 행동하지 못했습니다.";
				$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] = max(0, $stun_turns - 1);
				$status = 'ongoing';
			} elseif ($hero_shield_chance > 0 && rand(1, 100) <= $hero_shield_chance) {
				$logs[] = "🛡️ <span style='color:#80cbc4; font-weight:bold;'>[VIT 시너지]</span> 영웅의 보호막이 반격을 상쇄했습니다!";
				$status = 'ongoing';
			} elseif ($agi_evasion_chance > 0 && rand(1, 100) <= $agi_evasion_chance) {
				$logs[] = "💨 <span style='color:#80d8ff; font-weight:bold;'>[AGI 회피]</span> 사령관이 적의 반격을 완전히 회피했습니다!";
				$status = 'ongoing';
			} elseif (rand(1, 100) <= $vit_block_chance) {
				$logs[] = "🛡️ <span style='color:orange; font-weight:bold;'>[VIT 특성 발동]</span> 사령관이 공격을 막아냈습니다!";
				$status = 'ongoing';
			} else {
				$mob_dmg = max(1, (int)$cmd['mob_atk'] - floor($p_vit / 2));
				$incoming_damage = (int)$mob_dmg;
				$incoming_damage_source = (string)$cmd['mob_name'];
				$logs[] = "🩸 <b>{$cmd['mob_name']}</b>의 반격! <span style='color:#ff5252;'>{$mob_dmg}</span> 피해.";
				$new_hp = max(0, $new_hp - $mob_dmg);

				$reflect_dmg = max(0, (int)floor(($p_vit * 0.8) + ((int)$cmd['max_hp'] * 0.03)));
				if ($reflect_dmg > 0) {
					$new_mob_hp = max(0, $new_mob_hp - $reflect_dmg);
					$logs[] = "🛡️ <span style='color:#8bc34a; font-weight:bold;'>[가시 갑옷]</span> 단단한 방어력으로 <b>{$cmd['mob_name']}</b>에게 <span style='color:#8bc34a;'>{$reflect_dmg}</span> 반사 피해!";
				}

				if ($new_hp <= 0) {
					$logs[] = "💀 사령관이 쓰러졌습니다...";
					unset($_SESSION['combat_state']);
					$status = 'defeat';
				} elseif ($new_mob_hp <= 0) {
					$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 반사 피해로 쓰러졌습니다!";
					$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
					$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => (int)$cmd['mp'])), (int)$battle_reward['gold'] + (int)$total_gold_gain, (int)$battle_reward['exp'], (int)$cmd['current_floor']);
					$logs[] = "🎖️ 전투 보상: <b>" . ((int)$battle_reward['gold'] + (int)$total_gold_gain) . "G</b>, 경험치 <b>+{$battle_reward['exp']}</b>.";
					foreach ($reward_meta['levelup_logs'] as $levelup_log) {
						$logs[] = $levelup_log;
					}
					$new_hp = (int)$reward_meta['new_hp'];
					unset($_SESSION['combat_state']);
					$status = 'victory';
				} else {
					$status = 'ongoing';
				}
			}
		}

		if ($reward_meta === null && $total_gold_gain > 0) {
			$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($total_gold_gain, $uid));
		}

		if ($status === 'victory' || $status === 'defeat') {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
				->execute(array($new_hp, $uid));
		} else {
			tick_player_buffs($logs);
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mob_hp, $uid));
		}

		$final_log = implode('<br>', $logs);
		$status_effect_logs = extract_status_effect_logs($logs);
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $final_log));
		$combat_stream_seed = html_log_to_plain_text($final_log);
		$_SESSION['ai_stream_text'] = $combat_stream_seed;
		$_SESSION['combat_stream_text'] = $combat_stream_seed;
		$pdo->commit();

		echo json_encode(array(
			'status' => $status,
			'stream' => true,
			'logs' => $logs,
				'incoming_damage' => $incoming_damage,
				'incoming_damage_source' => $incoming_damage_source,
			'status_effect_logs' => $status_effect_logs,
			'turn_damage_details' => $turn_damage_details,
			'player_dmg' => (int)$player_dmg,
			'player_crit' => (bool)$is_crit,
			'hero_dmg' => (int)$total_hero_turn_damage,
			'hero_crit_hits' => (int)$hero_crit_hits,
			'max_hit_hero_name' => (string)$max_hit_hero_name,
			'max_hit_hero_dmg' => (int)$max_hit_hero_dmg,
			'new_hp' => $new_hp,
			'max_hp' => $reward_meta ? (int)$reward_meta['max_hp'] : (int)$cmd['max_hp'],
			'new_mp' => $reward_meta ? (int)$reward_meta['new_mp'] : (int)$cmd['mp'],
			'max_mp' => $reward_meta ? (int)$reward_meta['max_mp'] : (int)$cmd['max_mp'],
			'mob_hp' => $new_mob_hp,
			'mob_max_hp' => (int)$cmd['mob_max_hp'],
			'new_gold' => $reward_meta ? (int)$reward_meta['new_gold'] : ((int)$cmd['gold'] + (int)$total_gold_gain),
			'reward_gold' => $reward_meta ? (int)$reward_meta['reward_gold'] : (int)$total_gold_gain,
			'reward_exp' => $reward_meta ? (int)$reward_meta['reward_exp'] : 0,
			'new_level' => $reward_meta ? (int)$reward_meta['new_level'] : (int)$cmd['level'],
			'new_exp' => $reward_meta ? (int)$reward_meta['new_exp'] : (int)$cmd['exp'],
			'exp_to_next' => $reward_meta ? (int)$reward_meta['exp_to_next'] : get_required_exp_for_next_level((int)$cmd['level']),
			'stat_points' => $reward_meta ? (int)$reward_meta['stat_points'] : (int)$cmd['stat_points'],
			'levelup_logs' => $reward_meta ? $reward_meta['levelup_logs'] : array(),
			'levelup_count' => $reward_meta ? (int)$reward_meta['levelup_count'] : 0
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error('전투 중 오류');
	}
}

function handle_rest(PDO $pdo) {
	app_log('handle_rest.start');
	$uid = get_uid_or_fail();
	$cost = 30;
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT hp, max_hp, mp, max_mp, gold, stat_men FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['hp'] <= 0) { $pdo->rollBack(); json_error('이미 사망했습니다.'); return; }
		if ((int)$cmd['gold'] < $cost) { $pdo->rollBack(); json_error('마력석이 부족합니다.'); return; }
		if ((int)$cmd['hp'] >= (int)$cmd['max_hp'] && (int)$cmd['mp'] >= (int)$cmd['max_mp']) {
			$pdo->rollBack();
			json_error('체력과 마력이 이미 가득 차 있습니다.');
			return;
		}

		$men_bonus = (int)$cmd['stat_men'] * 3;
		$new_hp = min((int)$cmd['max_hp'], (int)$cmd['hp'] + floor((int)$cmd['max_hp'] * 0.2) + $men_bonus);
		$new_mp = min((int)$cmd['max_mp'], (int)$cmd['mp'] + floor((int)$cmd['max_mp'] * 0.2) + $men_bonus);
		$new_gold = (int)$cmd['gold'] - $cost;

		$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, gold = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $new_gold, $uid));
		$log = "🏕️ 휴식을 취했습니다. <span style='color:#4caf50;'>HP 회복</span> / <span style='color:#2196f3;'>MP 회복</span> / <span style='color:#ffd700;'>-{$cost}G</span>";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();

		echo json_encode(array('status'=>'success','log'=>$log,'new_hp'=>$new_hp,'new_mp'=>$new_mp,'max_hp'=>(int)$cmd['max_hp'],'max_mp'=>(int)$cmd['max_mp'],'new_gold'=>$new_gold));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error('휴식 중 오류');
	}
}

function handle_flee(PDO $pdo) {
	app_log('handle_flee.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['is_combat'] === 0) { $pdo->rollBack(); json_error('도망칠 적이 없습니다.'); return; }
		if (strpos((string)$cmd['mob_name'], '[보스]') !== false) {
			$pdo->rollBack();
			echo json_encode(array('status' => 'fail', 'log' => '보스의 강력한 기운에 짓눌려 도망칠 수 없습니다!', 'new_hp' => (int)$cmd['hp'], 'max_hp' => (int)$cmd['max_hp']));
			return;
		}

		$chance = 40 + (int)$cmd['stat_agi'];
		if (rand(1, 100) <= $chance) {
			$log = "💨 <b>[도주 성공!]</b> {$cmd['mob_name']}에게서 도망쳤습니다.";
			$pdo->prepare("UPDATE tb_commanders SET is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")->execute(array($uid));
			unset($_SESSION['combat_state']);
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
			$pdo->commit();
			echo json_encode(array('status' => 'success', 'log' => $log));
		} else {
			$dmg = rand(10, 20);
			$new_hp = max(0, (int)$cmd['hp'] - $dmg);
			$log = "🩸 <b>[도주 실패!]</b> {$cmd['mob_name']}의 기습! 체력 -{$dmg}";
			$pdo->prepare("UPDATE tb_commanders SET hp = ? WHERE uid = ?")->execute(array($new_hp, $uid));
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
			$pdo->commit();
			echo json_encode(array('status' => 'fail', 'log' => $log, 'new_hp' => $new_hp, 'max_hp' => (int)$cmd['max_hp']));
		}
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error('도주 중 오류');
	}
}

function handle_expedition_info(PDO $pdo) {
	app_log('handle_expedition_info.start');
	$uid = get_uid_or_fail();
	try {
		$stmt = $pdo->prepare("SELECT * FROM tb_expeditions WHERE uid = ? AND is_completed = 0");
		$stmt->execute(array($uid));
		$expeditions = $stmt->fetchAll();
		foreach ($expeditions as &$exp) {
			$h = $pdo->prepare("SELECT h.* FROM tb_expedition_heroes eh JOIN tb_heroes h ON eh.inv_id = h.inv_id WHERE eh.expedition_id = ?");
			$h->execute(array($exp['expedition_id']));
			$exp['heroes'] = $h->fetchAll();
		}
		$av = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND is_equipped = 0 AND is_on_expedition = 0 AND quantity > 0");
		$av->execute(array($uid));
		$available_heroes = $av->fetchAll();
		echo json_encode(array('status' => 'success', 'expeditions' => $expeditions, 'available_heroes' => $available_heroes));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_start_expedition(PDO $pdo) {
	app_log('handle_start_expedition.start');
	$uid = get_uid_or_fail();
	$hero_inv_ids = isset($_POST['hero_ids']) ? $_POST['hero_ids'] : array();
	$duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 1;
	if (empty($hero_inv_ids) || !in_array($duration, array(1, 4, 8)) || count($hero_inv_ids) > 10) {
		json_error('잘못된 요청입니다.');
		return;
	}

	try {
		$pdo->beginTransaction();
		$pdo->prepare("INSERT INTO tb_expeditions (uid, start_time, duration) VALUES (?, NOW(), ?)")->execute(array($uid, $duration));
		$expedition_id = $pdo->lastInsertId();

		$sent = array();
		foreach ($hero_inv_ids as $inv_id) {
			$st = $pdo->prepare("SELECT * FROM tb_heroes WHERE inv_id = ? AND uid = ? AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
			$st->execute(array((int)$inv_id, $uid));
			$hero = $st->fetch();
			if (!$hero) throw new Exception('파견 불가 영웅 포함');

			if ((int)$hero['quantity'] > 1) {
				$pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($hero['inv_id']));
				$lore = isset($hero['hero_lore']) ? $hero['hero_lore'] : '';
				$lvl = isset($hero['level']) ? (int)$hero['level'] : 1;
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, is_equipped, is_on_expedition, level, hero_lore) VALUES (?, ?, ?, 1, 0, 1, ?, ?)")
					->execute(array($uid, $hero['hero_rank'], $hero['hero_name'], $lvl, $lore));
				$sent[] = (int)$pdo->lastInsertId();
			} else {
				$pdo->prepare("UPDATE tb_heroes SET is_on_expedition = 1 WHERE inv_id = ?")->execute(array($hero['inv_id']));
				$sent[] = (int)$hero['inv_id'];
			}
		}

		$ins = $pdo->prepare("INSERT INTO tb_expedition_heroes (expedition_id, inv_id, quantity) VALUES (?, ?, 1)");
		foreach ($sent as $sid) $ins->execute(array($expedition_id, $sid));

		$pdo->commit();
		echo json_encode(array('status' => 'success', 'msg' => count($sent) . '명의 영웅을 파견했습니다.'));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_claim_expedition(PDO $pdo) {
	app_log('handle_claim_expedition.start');
	$uid = get_uid_or_fail();
	$expedition_id = isset($_POST['expedition_id']) ? (int)$_POST['expedition_id'] : 0;
	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_expeditions WHERE expedition_id = ? AND uid = ? AND is_completed = 0 FOR UPDATE");
		$st->execute(array($expedition_id, $uid));
		$exp = $st->fetch();
		if (!$exp) throw new Exception('존재하지 않거나 이미 수령한 파견입니다.');

		$start = new DateTime($exp['start_time']);
		$end = (clone $start)->add(new DateInterval('PT' . (int)$exp['duration'] . 'H'));
		$now = new DateTime();
		if ($now < $end) throw new Exception('아직 파견이 완료되지 않았습니다.');

		$h = $pdo->prepare("SELECT h.* FROM tb_expedition_heroes eh JOIN tb_heroes h ON eh.inv_id = h.inv_id WHERE eh.expedition_id = ?");
		$h->execute(array($expedition_id));
		$heroes = $h->fetchAll();

		$power_map = array('일반'=>1, '희귀'=>2, '영웅'=>4, '전설'=>8, '신화'=>16, '불멸'=>32, '유일'=>64);
		$total_power = 0;
		foreach ($heroes as $hero) $total_power += (isset($power_map[$hero['hero_rank']]) ? $power_map[$hero['hero_rank']] : 1);

		$reward = (int)floor(max(10, $total_power * (int)$exp['duration'] * 5));
		$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($reward, $uid));
		$pdo->prepare("UPDATE tb_expeditions SET is_completed = 1 WHERE expedition_id = ?")->execute(array($expedition_id));

		foreach ($heroes as $rhero) {
			$stack = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 AND inv_id != ? LIMIT 1 FOR UPDATE");
			$stack->execute(array($uid, $rhero['hero_name'], $rhero['inv_id']));
			$existing = $stack->fetch();
			if ($existing) {
				$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($existing['inv_id']));
				$pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($rhero['inv_id']));
			} else {
				$pdo->prepare("UPDATE tb_heroes SET is_on_expedition = 0 WHERE inv_id = ?")->execute(array($rhero['inv_id']));
			}
		}

		$log = "⚔️ <b>[토벌대 복귀]</b> {$exp['duration']}시간 파견 완료. <span style='color:#ffd700;'>{$reward}G</span> 획득!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();
		echo json_encode(array('status' => 'success', 'log' => $log, 'reward' => $reward));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_skill(PDO $pdo) {
	app_log('handle_skill.start');
	$uid = get_uid_or_fail();
	$skill_id = isset($_POST['skill_id']) ? $_POST['skill_id'] : '';
	$skills = array(
		'fireball' => array('name' => '화염구', 'cost' => 25, 'type' => 'damage', 'value' => 80),
		'heal' => array('name' => '힐', 'cost' => 30, 'type' => 'heal', 'value' => 50),
		'thunder_bolt' => array('name' => '번개', 'cost' => 28, 'type' => 'damage', 'value' => 100),
		'shield_up' => array('name' => '방어강화', 'resource' => 'none', 'type' => 'buff', 'effect' => 'vit', 'value' => 10, 'duration' => 3),
		'berserk' => array('name' => '광폭화', 'resource' => 'hp_percent', 'cost_percent' => 10, 'type' => 'buff', 'effect' => 'berserk_power', 'value' => 30, 'duration' => 3),
	);
	if (!isset($skills[$skill_id])) { json_error('알 수 없는 스킬입니다.'); return; }
	$skill = $skills[$skill_id];

	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		$is_in_combat = ((int)$cmd['is_combat'] === 1);
		if ((int)$cmd['hp'] <= 0) throw new Exception('사망 상태에서는 스킬을 사용할 수 없습니다.');
		if (!$is_in_combat && $skill_id !== 'heal') throw new Exception('힐은 비전투 중에도 사용할 수 있지만, 해당 스킬은 전투 중에만 사용 가능합니다.');

		$new_mp = (int)$cmd['mp'];
		$new_hp = (int)$cmd['hp'];
		$new_mob_hp = (int)$cmd['mob_hp'];
		$resource = isset($skill['resource']) ? (string)$skill['resource'] : 'mp';
		$cost_text = '소모 없음';
		if ($resource === 'mp') {
			$mp_cost = max(0, (int)(isset($skill['cost']) ? $skill['cost'] : 0));
			if ($new_mp < $mp_cost) throw new Exception('MP가 부족합니다.');
			$new_mp -= $mp_cost;
			$cost_text = "MP -{$mp_cost}";
		} elseif ($resource === 'hp_percent') {
			$hp_cost_percent = max(1, (int)(isset($skill['cost_percent']) ? $skill['cost_percent'] : 10));
			$hp_cost = max(1, (int)floor($new_hp * ($hp_cost_percent / 100)));
			if ($new_hp <= $hp_cost) throw new Exception('HP가 부족합니다.');
			$new_hp -= $hp_cost;
			$cost_text = "HP -{$hp_cost}";
		}
		$logs = array("🔮 <b>[{$skill['name']}]</b> 시전! ({$cost_text})");
		$mag_amp = 1 + ((int)$cmd['stat_mag'] * 0.01);
		if ($is_in_combat && !isset($_SESSION['combat_state'])) $_SESSION['combat_state'] = array('hero_attack_counts' => array(), 'enemy_debuffs' => array());

		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_agi = (int)$cmd['stat_agi'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_men = (int)$cmd['stat_men'];
		$berserk_bonus_pct = max(0, get_player_buff_value('berserk_power'));
		$newly_applied_buffs = array();
		$crit_mult = 1.5 + ($p_luk * 0.01);
		$men_mult = 1 + ($p_men * 0.005);
		$agi_double_chance = floor($p_agi / 5);
		$str_party_bonus_pct = (int)floor($p_str / 10) * 2;
		$mag_party_bonus_pct = (int)floor($p_mag / 10) * 2;
		$physical_heroes = array('늑대전사', '배트맨', '블롭', '베인', '닌자', '마스터 쿤', '골라조', '산적', '야만인', '레인저', '보안관', '호랑이사부');
		$magic_heroes = array('냥법사', '콜디', '펄스생성기', '오크주술사', '중력자탄', '전기로봇', '충격로봇', '물의정령', '샌드맨', '마마', '아토', '와트', '타르');
		$total_gold_gain = 0;

		if ($skill['type'] === 'damage') {
			if (!$is_in_combat) throw new Exception('해당 스킬은 전투 중에만 사용 가능합니다.');
			$damage = (int)floor(((int)$skill['value'] + floor((int)$cmd['stat_mag'] * 1.5)) * $mag_amp);
			if ($berserk_bonus_pct > 0) $damage = (int)floor($damage * (1 + ($berserk_bonus_pct / 100)));
			$new_mob_hp = max(0, $new_mob_hp - $damage);
			$logs[] = "💥 몬스터에게 <span style='color:red;'>{$damage}</span> 피해.";
		} elseif ($skill['type'] === 'heal') {
			$heal = (int)floor(((int)$skill['value'] + floor((int)$cmd['stat_men'] * 2) + floor((int)$cmd['stat_mag'] * 0.8)) * (1 + ((int)$cmd['stat_mag'] * 0.005)));
			$new_hp = min((int)$cmd['max_hp'], $new_hp + $heal);
			$logs[] = "💚 체력을 <span style='color:lightgreen;'>{$heal}</span> 회복.";
		} else {
			if (!$is_in_combat) throw new Exception('해당 스킬은 전투 중에만 사용 가능합니다.');
			if (!isset($_SESSION['combat_state']['player_buffs'])) $_SESSION['combat_state']['player_buffs'] = array();
			$buff_key = (string)$skill['effect'];
			$_SESSION['combat_state']['player_buffs'][$buff_key] = array('value' => (int)$skill['value'], 'turns_left' => (int)$skill['duration']);
			$newly_applied_buffs[] = $buff_key;
			if ($buff_key === 'vit') {
				$logs[] = "🛡️ <b>방어강화</b> 발동! {$skill['duration']}턴 동안 방어가 강화됩니다.";
			} elseif ($buff_key === 'berserk_power') {
				$logs[] = "🔥 <b>광폭화</b> 발동! {$skill['duration']}턴 동안 공격/마법 공격 피해 <b>+{$skill['value']}%</b>.";
			} else {
				$logs[] = '✨ 신체 능력이 일시적으로 강화됩니다!';
			}
		}

		if ($is_in_combat && $new_mob_hp > 0) {
			$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
			$deck_stmt->execute(array($uid));
			$deck = $deck_stmt->fetchAll();

			if (count($deck) > 0) {
				$logs[] = "<div style='margin:5px 0; padding-left:10px; border-left:2px solid #555; color:#aaa; font-size:0.85rem;'>▼ 영웅들이 마법에 호응해 합세합니다!</div>";
				foreach ($deck as $hero) {
					if ($new_mob_hp <= 0) break;

					$attack_times = (rand(1, 100) <= $agi_double_chance) ? 2 : 1;
					for ($i = 0; $i < $attack_times; $i++) {
						if ($new_mob_hp <= 0) break;
						if ($i === 1) $logs[] = "💨 <span style='color:#00e5ff; font-weight:bold;'>[AGI 발동]</span> <b>{$hero['hero_name']}</b> 연속 공격!";

						$range_map = array('일반'=>array(5,10,'#aaa'), '희귀'=>array(10,20,'#4caf50'), '영웅'=>array(18,30,'#2196f3'), '전설'=>array(28,45,'#9c27b0'), '신화'=>array(38,60,'#ff5252'), '불멸'=>array(45,75,'#ffeb3b'));
						$r = isset($range_map[$hero['hero_rank']]) ? $range_map[$hero['hero_rank']] : array(5,10,'#8bc34a');
						$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));
						$hero_dmg = rand($r[0], $r[1]) * $hero_count;
						$hero_dmg = (int)floor($hero_dmg * $men_mult);
						if (in_array($hero['hero_name'], $physical_heroes, true) && $str_party_bonus_pct > 0) {
							$hero_dmg = (int)floor($hero_dmg * (1 + ($str_party_bonus_pct / 100)));
						} elseif (in_array($hero['hero_name'], $magic_heroes, true) && $mag_party_bonus_pct > 0) {
							$hero_dmg = (int)floor($hero_dmg * (1 + ($mag_party_bonus_pct / 100)));
						}

						$armor_break_flat = isset($_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value']) ? (float)$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value'] : 0;
						if ($armor_break_flat > 0) $hero_dmg = (int)floor($hero_dmg * (1 + min(2.0, $armor_break_flat / 100.0)));

						$is_h_crit = false;
						if (rand(1, 100) <= floor($p_luk / 2)) { $is_h_crit = true; $hero_dmg = (int)floor($hero_dmg * $crit_mult); }

						apply_hero_skills($hero, $new_mob_hp, $logs, $total_gold_gain, $cmd, $hero_dmg, $is_h_crit);

						$hcrit = $is_h_crit ? "⚡ <span style='color:yellow; font-weight:bold;'>[치명타]</span> " : "⚔️ ";
						$logs[] = "{$hcrit}<span style='color:{$r[2]}'>[{$hero['hero_name']}]</span>(x{$hero_count})의 공격. {$hero_dmg} 피해.";
						$new_mob_hp = max(0, $new_mob_hp - $hero_dmg);
					}
				}
			}
		}

		$reward_meta = null;
		if ($is_in_combat) {
			if ($total_gold_gain > 0 && $new_mob_hp > 0) {
				$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($total_gold_gain, $uid));
			}

			if ($new_mob_hp <= 0) {
				$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 쓰러졌습니다!";
				$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
				$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => $new_mp)), (int)$battle_reward['gold'] + (int)$total_gold_gain, (int)$battle_reward['exp'], (int)$cmd['current_floor']);
				$logs[] = "🎖️ 전투 보상: <b>" . ((int)$battle_reward['gold'] + (int)$total_gold_gain) . "G</b>, 경험치 <b>+{$battle_reward['exp']}</b>.";
				foreach ($reward_meta['levelup_logs'] as $levelup_log) {
					$logs[] = $levelup_log;
				}
				$new_hp = (int)$reward_meta['new_hp'];
				$new_mp = (int)$reward_meta['new_mp'];
				unset($_SESSION['combat_state']);
				$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
					->execute(array($new_hp, $new_mp, $uid));
				$new_mob_hp = 0;
			} else {
				tick_player_buffs($logs, $newly_applied_buffs);
				$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $new_mob_hp, $uid));
			}
		} else {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $uid));
		}
		$pdo->commit();
		echo json_encode(array(
			'status' => 'success',
			'logs' => $logs,
			'is_combat' => $is_in_combat,
			'new_hp' => $new_hp,
			'max_hp' => $reward_meta ? (int)$reward_meta['max_hp'] : (int)$cmd['max_hp'],
			'new_mp' => $new_mp,
			'max_mp' => $reward_meta ? (int)$reward_meta['max_mp'] : (int)$cmd['max_mp'],
			'mob_hp' => $is_in_combat ? $new_mob_hp : null,
			'new_gold' => $reward_meta ? (int)$reward_meta['new_gold'] : ((int)$cmd['gold'] + (int)$total_gold_gain),
			'reward_gold' => $reward_meta ? (int)$reward_meta['reward_gold'] : (int)$total_gold_gain,
			'reward_exp' => $reward_meta ? (int)$reward_meta['reward_exp'] : 0,
			'new_level' => $reward_meta ? (int)$reward_meta['new_level'] : (int)$cmd['level'],
			'new_exp' => $reward_meta ? (int)$reward_meta['new_exp'] : (int)$cmd['exp'],
			'exp_to_next' => $reward_meta ? (int)$reward_meta['exp_to_next'] : get_required_exp_for_next_level((int)$cmd['level']),
			'stat_points' => $reward_meta ? (int)$reward_meta['stat_points'] : (int)$cmd['stat_points'],
			'levelup_logs' => $reward_meta ? $reward_meta['levelup_logs'] : array(),
			'levelup_count' => $reward_meta ? (int)$reward_meta['levelup_count'] : 0
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_stat_up(PDO $pdo) {
	app_log('handle_stat_up.start');
	$uid = get_uid_or_fail();
	$stat_type = isset($_POST['stat_type']) ? $_POST['stat_type'] : '';
	$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 1;
	$valid = array('str' => 'stat_str', 'mag' => 'stat_mag', 'agi' => 'stat_agi', 'luk' => 'stat_luk', 'men' => 'stat_men', 'vit' => 'stat_vit');
	if (!isset($valid[$stat_type]) || $amount < 1) { json_error('잘못된 스탯 정보'); return; }
	$col = $valid[$stat_type];

	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd || (int)$cmd['stat_points'] < $amount) throw new Exception('스탯 포인트 부족');

		$new_points = (int)$cmd['stat_points'] - $amount;
		$new_val = (int)$cmd[$col] + $amount;
		$new_max_hp = (int)$cmd['max_hp'];
		$new_hp = (int)$cmd['hp'];
		$new_max_mp = (int)$cmd['max_mp'];
		$new_mp = (int)$cmd['mp'];
		$append = '';

		if ($stat_type === 'vit') {
			$gain = 20 * $amount;
			$new_max_hp += $gain;
			$new_hp += $gain;
			$append = " <span style='color:#4caf50;'>(최대 HP +{$gain})</span>";
		} elseif ($stat_type === 'men') {
			$gain = 5 * $amount;
			$new_max_mp += $gain;
			$new_mp += $gain;
			$append = " <span style='color:#2196f3;'>(최대 MP +{$gain})</span>";
		}

		$sql = "UPDATE tb_commanders SET stat_points = ?, {$col} = ?, max_hp = ?, hp = ?, max_mp = ?, mp = ? WHERE uid = ?";
		$pdo->prepare($sql)->execute(array($new_points, $new_val, $new_max_hp, $new_hp, $new_max_mp, $new_mp, $uid));
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'stat_type' => $stat_type,
			'new_val' => $new_val,
			'new_points' => $new_points,
			'new_max_hp' => $new_max_hp,
			'new_hp' => $new_hp,
			'new_max_mp' => $new_max_mp,
			'new_mp' => $new_mp,
			'msg' => "✨ <b>" . strtoupper($stat_type) . "</b> 스탯이 상승했습니다! {$append}"
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_summon(PDO $pdo) {
	app_log('handle_summon.start');
	global $hero_data;
	$uid = get_uid_or_fail();
	$summon_cost = 100;
	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT gold, stat_luk FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		$owned_total = get_total_hero_units($pdo, $uid);
		if ($owned_total >= 30) throw new Exception('보유 영웅(출전 포함)은 최대 30명까지 가능합니다.');
		if ((int)$cmd['gold'] < $summon_cost) throw new Exception('마력석(Gold)이 부족합니다.');
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($summon_cost, $uid));

		$luk = (int)$cmd['stat_luk'];
		// 신화는 영웅 소환에서 제외합니다.
		// 1bp = 0.01% (10000bp = 100%)
		// Base rates at LUK 0:
		// 전설 0.20%, 영웅 1.50%, 희귀 8.00%, 일반 90.30%
		$weights = array('전설' => 20, '영웅' => 150, '희귀' => 800, '일반' => 9030);
		$weights['전설'] += (int)floor($luk / 35) * 3;  // +0.03% per 35 LUK
		$weights['영웅'] += (int)floor($luk / 20) * 10; // +0.10% per 20 LUK
		$weights['희귀'] += (int)floor($luk / 15) * 20; // +0.20% per 15 LUK
		$boost_total = ($weights['전설'] - 20) + ($weights['영웅'] - 150) + ($weights['희귀'] - 800);
		$weights['일반'] = max(1000, 9030 - $boost_total); // 일반 최소 10.00%
		$total_weight = array_sum($weights);
		$roll = rand(1, $total_weight);
		$acc = 0;
		$rank = '일반';
		foreach ($weights as $r => $w) {
			$acc += $w;
			if ($roll <= $acc) { $rank = $r; break; }
		}

		$pool = array();
		foreach ($hero_data as $name => $def) {
			if (isset($def['rank']) && $def['rank'] === $rank) $pool[] = $name;
		}
		if (empty($pool)) {
			foreach ($hero_data as $name => $def) {
				if (isset($def['rank']) && in_array($def['rank'], array('일반', '희귀', '영웅', '전설'), true)) {
					$pool[] = $name;
				}
			}
			$rank = (empty($pool) || !isset($hero_data[$pool[0]]['rank'])) ? '일반' : $hero_data[$pool[0]]['rank'];
		}
		$hero_name = $pool[array_rand($pool)];

		$find = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 LIMIT 1 FOR UPDATE");
		$find->execute(array($uid, $hero_name));
		$exists = $find->fetch();
		if ($exists) {
			$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($exists['inv_id']));
		} else {
			$lore = isset($hero_data[$hero_name]['hero_lore']) ? $hero_data[$hero_name]['hero_lore'] : '';
			$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, level, hero_lore) VALUES (?, ?, ?, 1, 1, ?)")->execute(array($uid, $rank, $hero_name, $lore));
		}
		$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $hero_name));

		$all = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY is_equipped DESC, hero_rank DESC, hero_name ASC");
		$all->execute(array($uid));
		$heroes = $all->fetchAll();
		list($deck_html, $inv_html, $deck_count) = generate_hero_lists($heroes);

		$gold_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$gold_st->execute(array($uid));
		$new_gold = (int)$gold_st->fetchColumn();

		$msg = "✨ <b style='color:yellow;'>[{$rank}] {$hero_name}</b> 영웅이 소환되었습니다!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		echo json_encode(array('status'=>'success','msg'=>$msg,'new_rank'=>$rank,'new_hero_name'=>$hero_name,'deck_html'=>$deck_html,'inv_html'=>$inv_html,'deck_count'=>$deck_count,'new_gold'=>$new_gold));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_synthesize(PDO $pdo) {
	app_log('handle_synthesize.start');
	global $hero_data;
	$uid = get_uid_or_fail();
	$hero_name = isset($_POST['hero_name']) ? $_POST['hero_name'] : '';

	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT inv_id, hero_rank, quantity FROM tb_heroes WHERE uid = ? AND hero_name = ? AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
		$st->execute(array($uid, $hero_name));
		$source = $st->fetch();
		if (!$source || (int)$source['quantity'] < 3) throw new Exception('합성에 필요한 영웅 수량(3)이 부족합니다.');
		$rank_up_map = array(
			'일반' => '희귀',
			'희귀' => '영웅'
		);
		if (!isset($rank_up_map[$source['hero_rank']])) throw new Exception('합성은 일반/희귀 영웅만 가능합니다.');
		$next_rank = $rank_up_map[$source['hero_rank']];

		$remain = (int)$source['quantity'] - 3;
		if ($remain > 0) $pdo->prepare("UPDATE tb_heroes SET quantity = ? WHERE inv_id = ?")->execute(array($remain, $source['inv_id']));
		else $pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($source['inv_id']));

		$pool = array();
		foreach ($hero_data as $nm => $def) if (isset($def['rank']) && $def['rank'] === $next_rank) $pool[] = $nm;
		if (empty($pool)) throw new Exception("다음 등급({$next_rank})에 해당하는 영웅이 없습니다.");
		$new_hero_name = $pool[array_rand($pool)];

		$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
		$chk->execute(array($uid, $new_hero_name));
		$exists = $chk->fetch();
		if ($exists) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($exists['inv_id']));
		else {
			$lore = isset($hero_data[$new_hero_name]['hero_lore']) ? $hero_data[$new_hero_name]['hero_lore'] : '';
			$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, level, hero_lore, is_equipped, is_on_expedition) VALUES (?, ?, ?, 1, 1, ?, 0, 0)")->execute(array($uid, $next_rank, $new_hero_name, $lore));
		}
		$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $new_hero_name));

		$all = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY is_equipped DESC, hero_rank DESC, hero_name ASC");
		$all->execute(array($uid));
		$heroes = $all->fetchAll();
		list($deck_html, $inv_html, $deck_count) = generate_hero_lists($heroes);

		$gold_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$gold_st->execute(array($uid));
		$current_gold = (int)$gold_st->fetchColumn();

		$msg = "🧬 <b>{$hero_name}</b> 3마리를 합성하여 <span style='color:#ffeb3b; font-weight:bold;'>[{$next_rank}] {$new_hero_name}</span> 획득! <span style='color:#80cbc4;'>[대기 상태 지급]</span>";
		$trace_msg = "🧬 합성 완료: {$hero_name} x3 -> [{$next_rank}] {$new_hero_name} (대기 상태 지급)";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $trace_msg));
		$pdo->commit();
		echo json_encode(array('status'=>'success','msg'=>$msg,'new_rank'=>$next_rank,'new_hero_name'=>$new_hero_name,'deck_html'=>$deck_html,'inv_html'=>$inv_html,'deck_count'=>$deck_count,'new_gold'=>$current_gold));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_auto_explore_start(PDO $pdo) {
	app_log('handle_auto_explore_start.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->prepare("UPDATE tb_commanders SET auto_explore_start_time = NOW() WHERE uid = ?")->execute(array($uid));
		echo json_encode(array('status' => 'success', 'msg' => '자동 탐험을 시작했습니다.'));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_auto_explore_status(PDO $pdo) {
	app_log('handle_auto_explore_status.start');
	$uid = get_uid_or_fail();
	try {
		$stmt = $pdo->prepare("SELECT auto_explore_start_time, current_floor FROM tb_commanders WHERE uid = ?");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();

		if ($cmd && $cmd['auto_explore_start_time'] !== null) {
			$start = new DateTime($cmd['auto_explore_start_time']);
			$now = new DateTime();
			$int = $now->diff($start);
			$minutes = ($int->days * 24 * 60) + ($int->h * 60) + $int->i;
			$bonus = max(1, floor((int)$cmd['current_floor'] / 10));
			$gold = $minutes * 10 * $bonus;
			$exp = $minutes * 5 * $bonus;
			echo json_encode(array('status' => 'exploring', 'elapsed_minutes' => $minutes, 'rewards' => array('gold' => $gold, 'exp' => $exp)));
		} else {
			echo json_encode(array('status' => 'not_exploring'));
		}
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_auto_explore_claim(PDO $pdo) {
	app_log('handle_auto_explore_claim.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd || $cmd['auto_explore_start_time'] === null) {
			$pdo->rollBack();
			json_error('진행 중인 자동 탐험이 없습니다.');
			return;
		}

		$start = new DateTime($cmd['auto_explore_start_time']);
		$now = new DateTime();
		$int = $now->diff($start);
		$minutes = ($int->days * 24 * 60) + ($int->h * 60) + $int->i;
		if ($minutes < 1) {
			$pdo->rollBack();
			json_error('최소 1분 이상 탐험해야 보상을 받을 수 있습니다.');
			return;
		}

		$bonus = max(1, floor((int)$cmd['current_floor'] / 10));
		$gold = $minutes * 10 * $bonus;
		$exp = $minutes * 5 * $bonus;
		$pdo->prepare("UPDATE tb_commanders SET gold = gold + ?, auto_explore_start_time = NULL WHERE uid = ?")->execute(array($gold, $uid));
		$prog = apply_commander_exp_gain($pdo, $uid, $cmd, $exp);
		$log = "🏕️ <b>[자동 탐험 종료]</b> {$minutes}분 탐험. <span style='color:#ffd700;'>{$gold}G</span>, <span style='color:#b388ff;'>{$exp}XP</span> 획득!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		if (!empty($prog['levelup_logs'])) {
			foreach ($prog['levelup_logs'] as $lvlog) {
				$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $lvlog));
			}
		}
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'log' => $log,
			'rewards' => array('gold' => $gold, 'exp' => $exp),
			'levelup_count' => count($prog['levelup_logs']),
			'new_level' => (int)$prog['level'],
			'new_exp' => (int)$prog['exp'],
			'exp_to_next' => (int)$prog['exp_to_next'],
			'new_stat_points' => (int)$prog['stat_points'],
			'new_hp' => (int)$prog['hp'],
			'new_max_hp' => (int)$prog['max_hp'],
			'new_mp' => (int)$prog['mp'],
			'new_max_mp' => (int)$prog['max_mp'],
			'levelup_logs' => $prog['levelup_logs']
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_equip(PDO $pdo) {
	app_log('handle_equip.start');
	$uid = get_uid_or_fail();
	$inv_id = isset($_POST['inv_id']) ? (int)$_POST['inv_id'] : 0;
	$action_val = isset($_POST['action']) ? (int)$_POST['action'] : 0;
	try {
		$pdo->beginTransaction();

		if ($inv_id > 0 && $action_val !== 0) {
			$st = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND inv_id = ? FOR UPDATE");
			$st->execute(array($uid, $inv_id));
			$hero = $st->fetch();
			if (!$hero) throw new Exception('영웅을 찾을 수 없습니다.');
			if ((int)$hero['quantity'] <= 0) throw new Exception('보유 수량이 부족합니다.');

			if ($action_val === 1) {
				if ((int)$hero['is_on_expedition'] === 1) throw new Exception('파견 중인 영웅은 출전할 수 없습니다.');
				if ((int)$hero['is_equipped'] === 1) throw new Exception('이미 출전 중인 영웅입니다.');

				$cnt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND is_on_expedition = 0 AND quantity > 0");
				$cnt->execute(array($uid));
				if ((int)$cnt->fetchColumn() >= 5) throw new Exception('출전 덱은 최대 5명까지 가능합니다.');

				if ((int)$hero['quantity'] > 1) {
					$stack = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 1 AND is_on_expedition = 0 AND inv_id != ? LIMIT 1 FOR UPDATE");
					$stack->execute(array($uid, $hero['hero_name'], $inv_id));
					$existing = $stack->fetch();

					$pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($inv_id));
					if ($existing) {
						$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($existing['inv_id']));
					} else {
						$lore = isset($hero['hero_lore']) ? $hero['hero_lore'] : '';
						$battle_count = isset($hero['battle_count']) ? (int)$hero['battle_count'] : 0;
						$level = isset($hero['level']) ? (int)$hero['level'] : 1;
						$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, battle_count, is_equipped, is_on_expedition, level, hero_lore) VALUES (?, ?, ?, 1, ?, 1, 0, ?, ?)")
							->execute(array($uid, $hero['hero_rank'], $hero['hero_name'], $battle_count, $level, $lore));
					}
				} else {
					$stack = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 1 AND is_on_expedition = 0 AND inv_id != ? LIMIT 1 FOR UPDATE");
					$stack->execute(array($uid, $hero['hero_name'], $inv_id));
					$existing = $stack->fetch();
					if ($existing) {
						$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($existing['inv_id']));
						$pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($inv_id));
					} else {
						$pdo->prepare("UPDATE tb_heroes SET is_equipped = 1 WHERE inv_id = ?")->execute(array($inv_id));
					}
				}
			} elseif ($action_val === -1) {
				if ((int)$hero['is_equipped'] === 0) throw new Exception('출전 중인 영웅이 아닙니다.');

				if ((int)$hero['quantity'] > 1) {
					$pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($inv_id));
					$stack = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 LIMIT 1 FOR UPDATE");
					$stack->execute(array($uid, $hero['hero_name']));
					$existing = $stack->fetch();
					if ($existing) {
						$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($existing['inv_id']));
					} else {
						$lore = isset($hero['hero_lore']) ? $hero['hero_lore'] : '';
						$battle_count = isset($hero['battle_count']) ? (int)$hero['battle_count'] : 0;
						$level = isset($hero['level']) ? (int)$hero['level'] : 1;
						$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, battle_count, is_equipped, is_on_expedition, level, hero_lore) VALUES (?, ?, ?, 1, ?, 0, 0, ?, ?)")
							->execute(array($uid, $hero['hero_rank'], $hero['hero_name'], $battle_count, $level, $lore));
					}
				} else {
					$stack = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 AND inv_id != ? LIMIT 1 FOR UPDATE");
					$stack->execute(array($uid, $hero['hero_name'], $inv_id));
					$existing = $stack->fetch();
					if ($existing) {
						$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($existing['inv_id']));
						$pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($inv_id));
					} else {
						$pdo->prepare("UPDATE tb_heroes SET is_equipped = 0 WHERE inv_id = ?")->execute(array($inv_id));
					}
				}
			}
		}

		$all = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY is_equipped DESC, hero_rank DESC, hero_name ASC");
		$all->execute(array($uid));
		$heroes = $all->fetchAll();
		list($deck_html, $inv_html, $deck_count) = generate_hero_lists($heroes);

		$pdo->commit();
		echo json_encode(array('status' => 'success', 'deck_html' => $deck_html, 'inv_html' => $inv_html, 'deck_count' => $deck_count));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_book(PDO $pdo) {
	app_log('handle_book.start');
	$uid = get_uid_or_fail();
	try {
		$st = $pdo->prepare("SELECT hero_name, hero_rank, SUM(quantity) AS qty FROM tb_heroes WHERE uid = ? AND quantity > 0 GROUP BY hero_name, hero_rank ORDER BY hero_rank DESC, hero_name ASC");
		$st->execute(array($uid));
		$rows = $st->fetchAll();
		$html = "<div style='display:grid; gap:6px;'>";
		if (empty($rows)) {
			$html .= "<div style='color:#777;'>아직 획득한 영웅이 없습니다.</div>";
		} else {
			foreach ($rows as $r) {
				$color = isset($GLOBALS['colors'][$r['hero_rank']]) ? $GLOBALS['colors'][$r['hero_rank']] : '#fff';
				$html .= "<div style='padding:8px; background:#222; border-left:3px solid {$color}; border-radius:4px;'><span style='color:{$color}'>[{$r['hero_rank']}]</span> {$r['hero_name']} (x{$r['qty']})</div>";
			}
		}
		$html .= "</div>";
		echo json_encode(array('status' => 'success', 'html' => $html));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_combine(PDO $pdo) {
	app_log('handle_combine.start');
	if (!isset($_SESSION['uid'])) { json_error('세션 만료'); return; }
	$uid = (int)$_SESSION['uid'];
	$mode = isset($_POST['mode']) ? $_POST['mode'] : 'view';
	$target_name = isset($_POST['target_name']) ? $_POST['target_name'] : '';
	global $hero_data;

	$mythic_recipes = array(
		'닌자' => array('enabled' => true, 'materials' => array('늑대전사', '성기사', '악마병사')),
		'블롭' => array('enabled' => true, 'materials' => array('사냥꾼', '독수리장군', '산적')),
		'중력자탄' => array('enabled' => true, 'materials' => array('전기로봇', '충격로봇', '투척병', '투척병')),
		'오크주술사' => array('enabled' => false, 'materials' => array('사냥꾼', '전기로봇', '악마병사')),
		'펄스생성기' => array('enabled' => true, 'materials' => array('전기로봇', '나무', '궁수', '궁수')),
		'냥법사' => array('enabled' => false, 'materials' => array('독수리장군', '궁수', '물의정령', '물의정령')),
		'밤바' => array('enabled' => true, 'materials' => array('호랑이사부', '늑대전사', '야만인')),
		'헤일리' => array('enabled' => true, 'materials' => array('보안관', '사냥꾼', '샌드맨')),
		'콜디' => array('enabled' => true, 'materials' => array('폭풍거인', '샌드맨', '물의정령')),
		'랜슬롯' => array('enabled' => false, 'materials' => array('보안관', '사냥꾼', '성기사')),
		'아이언미야옹' => array('enabled' => true, 'materials' => array('워머신', '산적', '산적')),
		'드래곤' => array('enabled' => true, 'materials' => array('독수리장군', '독수리장군', '물의정령')),
		'모노폴리맨' => array('enabled' => false, 'materials' => array('늑대전사', '나무', '악마병사')),
		'마마' => array('enabled' => true, 'materials' => array('사냥꾼', '나무', '전기로봇')),
		'개구리 왕자' => array('enabled' => true, 'materials' => array('늑대전사', '나무', '야만인', '투척병')),
		'배트맨' => array('enabled' => true, 'materials' => array('호랑이사부', '나무', '투척병', '투척병')),
		'베인' => array('enabled' => true, 'materials' => array('폭풍거인', '사냥꾼', '레인저', '궁수')),
		'인디' => array('enabled' => false, 'materials' => array('보안관', '늑대전사', '샌드맨')),
		'와트' => array('enabled' => false, 'materials' => array('폭풍거인', '전기로봇', '악마병사')),
		'타르' => array('enabled' => false, 'materials' => array('늑대전사', '사냥꾼', '샌드맨', '야만인')),
		'로켓츄' => array('enabled' => false, 'materials' => array('워머신', '충격로봇', '투척병')),
		'우치' => array('enabled' => false, 'materials' => array('폭풍거인', '레인저', '물의정령')),
		'지지' => array('enabled' => false, 'materials' => array('보안관', '전기로봇', '악마병사', '궁수')),
		'마스터 쿤' => array('enabled' => true, 'materials' => array('호랑이사부', '독수리장군', '성기사')),
		'초나' => array('enabled' => false, 'materials' => array('보안관', '나무', '악마병사', '야만인')),
		'펭귄악사' => array('enabled' => true, 'materials' => array('독수리장군', '늑대전사', '전기로봇')),
		'아토' => array('enabled' => true, 'materials' => array('나무', '사냥꾼', '악마병사', '야만인')),
		'로카' => array('enabled' => true, 'materials' => array('호랑이사부', '보안관', '독수리장군', '궁수')),
		'골라조' => array('enabled' => true, 'materials' => array('호랑이사부', '나무', '레인저', '산적')),
	);

	$evolution_recipes = array(
		'닌자' => '귀신 닌자',
		'블롭' => '블롭단',
		'중력자탄' => '슈퍼 중력자탄',
		'펄스생성기' => '닥터 펄스',
		'밤바' => '원시 밤바',
		'헤일리' => '각성 헤일리',
		'콜디' => '여왕 콜디',
		'아이언미야옹' => '아이엠 미야옹',
		'드래곤' => '마왕 드래곤',
		'마마' => '그랜드 마마',
		'개구리 왕자' => '사신 다이안 (사신 개구리 승천형)',
		'배트맨' => '에이스 배트맨',
		'베인' => '탑 베인',
		'마스터 쿤' => '불멸 쿤',
		'펭귄악사' => '소음킹 펭귄악사',
		'아토' => '시공 아토',
		'로카' => '캡틴 로카',
		'골라조' => '보스 골라조',
	);
	// NOTE: 현재 공통 진화 조건은 "신화 영웅 전투 1000회"입니다.
	// 추후 영웅별로 조건(전투 횟수, 재료, 층수 등)을 분기할 수 있도록 requirements 맵 구조를 유지합니다.
	$evolution_requirements = array();
	foreach ($evolution_recipes as $mythic_name => $_immortal_name) {
		$evolution_requirements[$mythic_name] = array('battle_count' => 1000);
	}

	$hero_aliases = array(
		'개구리 왕자' => array('개구리 왕자', '개구리 왕자 (▶ 킹 다이안)'),
		'소음킹 펭귄악사' => array('소음킹 펭귄악사', '소음킹'),
		'캡틴 로카' => array('캡틴 로카', '캡틴로카'),
		'보스 골라조' => array('보스 골라조', '보스골라조'),
		'불멸 쿤' => array('불멸 쿤', '마스터 쿤 (불멸)', '마스터 쿤')
	);

	$get_aliases = function($name) use ($hero_aliases) {
		if (isset($hero_aliases[$name])) return $hero_aliases[$name];
		return array($name);
	};

	$fetch_available_count = function($name) use ($pdo, $uid, $get_aliases) {
		$aliases = $get_aliases($name);
		$ph = implode(',', array_fill(0, count($aliases), '?'));
		$params = array_merge(array($uid), $aliases);
		$sql = "SELECT COALESCE(SUM(quantity),0) FROM tb_heroes WHERE uid = ? AND hero_name IN ({$ph}) AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0";
		$st = $pdo->prepare($sql);
		$st->execute($params);
		return (int)$st->fetchColumn();
	};

	$fetch_max_battle_count = function($name) use ($pdo, $uid, $get_aliases) {
		$aliases = $get_aliases($name);
		$ph = implode(',', array_fill(0, count($aliases), '?'));
		$params = array_merge(array($uid), $aliases);
		$sql = "SELECT COALESCE(MAX(battle_count),0) FROM tb_heroes WHERE uid = ? AND hero_name IN ({$ph}) AND quantity > 0";
		$st = $pdo->prepare($sql);
		$st->execute($params);
		return (int)$st->fetchColumn();
	};

	$render_view = function() use ($mythic_recipes, $evolution_recipes, $evolution_requirements, $fetch_available_count, $fetch_max_battle_count) {
		$html = '<div style="background:#222; padding:15px; border-radius:5px; margin-bottom:20px;">';
		$html .= '<h3 style="color:#ff5252; margin-top:0;">신화 조합 (레시피)</h3>';
		$html .= '<p style="color:#ccc; font-size:0.9rem;">출전/파견 중이 아닌 재료 영웅을 소모해 대상 신화를 직접 조합합니다.</p>';
		foreach ($mythic_recipes as $mythic => $info) {
			$req_counts = array_count_values($info['materials']);
			$can_craft = true;
			$parts = array();
			foreach ($req_counts as $mat => $need) {
				$owned = $fetch_available_count($mat);
				if ($owned < $need) $can_craft = false;
				$parts[] = "{$mat} {$owned}/{$need}";
			}
			$safe_name = str_replace("'", "\\'", $mythic);
			$html .= "<div style='padding:10px; border:1px solid #444; margin-top:8px; border-radius:4px;'>";
			$html .= "<b style='color:#ff8a80;'>[신화]</b> <b>{$mythic}</b><br><span style='color:#aaa; font-size:0.85rem;'>재료: " . implode(' + ', $parts) . "</span>";
			if ($can_craft) {
				$html .= "<button class='btn' style='float:right;' onclick=\"if(confirm('{$mythic} 조합을 진행하시겠습니까?')) combineHero('combine_mythic', '{$safe_name}')\">조합</button>";
			} else {
				$html .= "<button class='btn' style='float:right; background:#555;' disabled>재료 부족</button>";
			}
			$html .= "<div style='clear:both;'></div></div>";
		}
		$html .= '</div><div style="background:#222; padding:15px; border-radius:5px;">';
		$html .= '<h3 style="color:#ffeb3b; margin-top:0;">불멸 진화</h3>';
		$html .= '<p style="color:#ccc; font-size:0.9rem;">진화 조건: 대상 신화 영웅 전투 1000회. 일부 신화 영웅은 불멸 진화가 불가능합니다.</p>';
		$has_mythic = false;
		foreach ($mythic_recipes as $mythic => $_info) {
			$owned = $fetch_available_count($mythic);
			if ($owned <= 0) continue;
			$has_mythic = true;
			$can_evolve_hero = isset($evolution_recipes[$mythic]);
			$immortal = $can_evolve_hero ? $evolution_recipes[$mythic] : '';
			$need_battles = isset($evolution_requirements[$mythic]['battle_count']) ? (int)$evolution_requirements[$mythic]['battle_count'] : 1000;
			$max_battle = $fetch_max_battle_count($mythic);
			$can_evolve = ($can_evolve_hero && $max_battle >= $need_battles);
			$safe_mythic = str_replace("'", "\\'", $mythic);
			$html .= "<div style='padding:10px; border:1px solid #444; margin-top:10px; border-radius:4px;'><strong>{$mythic}</strong>";
			if ($can_evolve_hero) {
				$html .= " ▶ <strong>{$immortal}</strong>";
				$html .= "<div style='color:#aaa; font-size:0.85rem; margin-top:4px;'>전투: {$max_battle} / {$need_battles}</div>";
			} else {
				$html .= " <span style='color:#888;'>▶ 불멸 진화 없음</span>";
				$html .= "<div style='color:#888; font-size:0.85rem; margin-top:4px;'>이 신화 영웅은 현재 불멸 진화 대상이 아닙니다.</div>";
			}
			if ($can_evolve) {
				$html .= "<button class='btn' style='background:#ffeb3b; color:#000; float:right;' onclick=\"if(confirm('{$mythic}을(를) {$immortal}(으)로 진화시키겠습니까?')) combineHero('evolve', '{$safe_mythic}')\">진화</button>";
			} elseif (!$can_evolve_hero) {
				$html .= "<button class='btn' style='background:#555; float:right;' disabled>진화 불가</button>";
			} else {
				$html .= "<button class='btn' style='background:#555; float:right;' disabled>전투 횟수 부족</button>";
			}
			$html .= "<div style='clear:both;'></div></div>";
		}
		if (!$has_mythic) $html .= '<p style="color:#777; text-align:center;">보유한 신화 영웅이 없습니다.</p>';
		$html .= '</div>';
		return $html;
	};

	try {
		if ($mode === 'view') {
			echo json_encode(array('status' => 'success', 'html' => $render_view()));
			return;
		}

		if ($mode === 'combine_mythic') {
			if (!isset($mythic_recipes[$target_name])) throw new Exception('알 수 없는 신화 조합 레시피입니다.');
			$recipe = $mythic_recipes[$target_name];

			$pdo->beginTransaction();
			$req_counts = array_count_values($recipe['materials']);
			$used_text = array();
			foreach ($req_counts as $mat => $need) {
				$aliases = $get_aliases($mat);
				$ph = implode(',', array_fill(0, count($aliases), '?'));
				$params = array_merge(array($uid), $aliases);
				$sql = "SELECT inv_id, hero_name, quantity FROM tb_heroes WHERE uid = ? AND hero_name IN ({$ph}) AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0 ORDER BY quantity DESC, inv_id ASC FOR UPDATE";
				$st = $pdo->prepare($sql);
				$st->execute($params);
				$rows = $st->fetchAll();
				$total = 0;
				foreach ($rows as $r) $total += (int)$r['quantity'];
				if ($total < $need) throw new Exception("조합 재료 부족: {$mat} ({$total}/{$need})");

				$left = $need;
				foreach ($rows as $r) {
					if ($left <= 0) break;
					$have = (int)$r['quantity'];
					$use = min($left, $have);
					if ($have > $use) {
						$pdo->prepare("UPDATE tb_heroes SET quantity = quantity - ? WHERE inv_id = ?")->execute(array($use, $r['inv_id']));
					} else {
						$pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($r['inv_id']));
					}
					$left -= $use;
				}
				$used_text[] = "{$mat} x{$need}";
			}

			$new_name = $target_name;

			$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
			$chk->execute(array($uid, $new_name));
			$ex = $chk->fetch();
			if ($ex) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($ex['inv_id']));
			else {
				$rank = isset($hero_data[$new_name]['rank']) ? $hero_data[$new_name]['rank'] : '신화';
				$lore = isset($hero_data[$new_name]['hero_lore']) ? $hero_data[$new_name]['hero_lore'] : '';
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_name, hero_rank, quantity, hero_lore, level, is_equipped, is_on_expedition) VALUES (?, ?, ?, 1, ?, 1, 0, 0)")->execute(array($uid, $new_name, $rank, $lore));
			}
			$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $new_name));
			$pdo->commit();

			$msg = "✨ 조합 성공! (" . implode(' + ', $used_text) . ") → <span style='color:#ff5252; font-weight:bold;'>[신화] {$new_name}</span> 획득!";
			echo json_encode(array('status' => 'success', 'msg' => $msg, 'new_rank' => '신화', 'new_name' => $new_name, 'html' => $render_view()));
			return;
		}

		if ($mode === 'evolve') {
			if (!isset($evolution_recipes[$target_name])) throw new Exception('해당 신화 영웅은 불멸 진화가 불가능합니다.');
			$evolved_name = $evolution_recipes[$target_name];
			$need_battles = isset($evolution_requirements[$target_name]['battle_count']) ? (int)$evolution_requirements[$target_name]['battle_count'] : 1000;
			$pdo->beginTransaction();

			$aliases = $get_aliases($target_name);
			$ph = implode(',', array_fill(0, count($aliases), '?'));
			$params = array_merge(array($uid), $aliases);
			$st = $pdo->prepare("SELECT inv_id, quantity, battle_count FROM tb_heroes WHERE uid = ? AND hero_name IN ({$ph}) AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0 ORDER BY battle_count DESC, quantity DESC, inv_id ASC FOR UPDATE");
			$st->execute($params);
			$src_rows = $st->fetchAll();
			if (empty($src_rows)) throw new Exception("진화에 필요한 영웅({$target_name})이 없습니다. (출전/파견 해제 필요)");
			$src = $src_rows[0];
			$current_battle = isset($src['battle_count']) ? (int)$src['battle_count'] : 0;
			if ($current_battle < $need_battles) {
				throw new Exception("진화 조건 미충족: {$target_name} 전투 {$current_battle}/{$need_battles}");
			}
			if ((int)$src['quantity'] > 1) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($src['inv_id']));
			else $pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($src['inv_id']));

			$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
			$chk->execute(array($uid, $evolved_name));
			$ex = $chk->fetch();
			if ($ex) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($ex['inv_id']));
			else {
				$rank = isset($hero_data[$evolved_name]['rank']) ? $hero_data[$evolved_name]['rank'] : '불멸';
				$lore = isset($hero_data[$evolved_name]['hero_lore']) ? $hero_data[$evolved_name]['hero_lore'] : '';
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_name, hero_rank, quantity, hero_lore, level, is_equipped, is_on_expedition) VALUES (?, ?, ?, 1, ?, 1, 0, 0)")->execute(array($uid, $evolved_name, $rank, $lore));
			}
			$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $evolved_name));
			$pdo->commit();

			$msg = "🔮 <span style='color:#ff5252;'>{$target_name}</span> → <span style='color:#ffeb3b; font-weight:bold;'>[불멸] {$evolved_name}</span> 진화!";
			echo json_encode(array('status' => 'success', 'msg' => $msg, 'new_rank' => '불멸', 'new_name' => $evolved_name, 'html' => $render_view()));
			return;
		}

		json_error('알 수 없는 조합 모드');
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function get_hero_levelup_cost($hero_rank, $lv) {
	$rank_base_cost = array(
		'일반' => 100,
		'희귀' => 200,
		'영웅' => 400,
		'전설' => 800,
		'신화' => 1600,
		'불멸' => 3200,
		'유일' => 6400
	);
	$base = isset($rank_base_cost[$hero_rank]) ? (int)$rank_base_cost[$hero_rank] : 100;
	$step = max(1, (int)$lv);
	return (int)($base * pow(2, $step - 1));
}

function render_hero_levelup_html($heroes) {
	if (empty($heroes)) return "<div style='color:#777; text-align:center; padding:10px;'>강화할 영웅이 없습니다.</div>";
	$html = "<div style='display:grid; gap:8px;'>";
	foreach ($heroes as $h) {
		$lv = (int)$h['level'];
		$next_lv = min(15, $lv + 1);
		$cost = get_hero_levelup_cost($h['hero_rank'], $lv);
		$disabled = ($lv >= 15) ? 'disabled' : '';
		$btn_text = ($lv >= 15) ? '최대 레벨' : "강화 ({$cost}G)";
		$color = isset($GLOBALS['colors'][$h['hero_rank']]) ? $GLOBALS['colors'][$h['hero_rank']] : '#fff';
		$html .= "<div style='background:#222; border-left:3px solid {$color}; padding:10px; border-radius:4px; display:flex; justify-content:space-between; align-items:center;'>";
		$html .= "<div><span style='color:{$color}; font-size:0.8rem;'>[{$h['hero_rank']}]</span> <b>{$h['hero_name']}</b> Lv.{$lv} (x{$h['quantity']})<br><span style='font-size:0.8rem; color:#aaa;'>다음: Lv.{$next_lv}</span></div>";
		$html .= "<button class='btn' style='padding:6px 10px; font-size:0.8rem;' {$disabled} onclick='levelUpHero({$h['inv_id']})'>{$btn_text}</button>";
		$html .= "</div>";
	}
	$html .= "</div>";
	return $html;
}

function handle_hero_levelup_view(PDO $pdo) {
	app_log('handle_hero_levelup_view.start');
	$uid = get_uid_or_fail();
	try {
		$st = $pdo->prepare("SELECT inv_id, hero_rank, hero_name, quantity, level FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY hero_rank DESC, hero_name ASC");
		$st->execute(array($uid));
		$heroes = $st->fetchAll();

		$g = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$g->execute(array($uid));
		$gold = (int)$g->fetchColumn();

		echo json_encode(array('status' => 'success', 'gold' => $gold, 'html' => render_hero_levelup_html($heroes)));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_hero_levelup(PDO $pdo) {
	app_log('handle_hero_levelup.start');
	$uid = get_uid_or_fail();
	$inv_id = isset($_POST['inv_id']) ? (int)$_POST['inv_id'] : 0;
	try {
		$pdo->beginTransaction();
		$hs = $pdo->prepare("SELECT inv_id, hero_rank, hero_name, quantity, level FROM tb_heroes WHERE uid = ? AND inv_id = ? AND quantity > 0 FOR UPDATE");
		$hs->execute(array($uid, $inv_id));
		$hero = $hs->fetch();
		if (!$hero) throw new Exception('영웅을 찾을 수 없습니다.');
		$lv = (int)$hero['level'];
		if ($lv >= 15) throw new Exception('이미 최대 레벨입니다.');

		$cost = get_hero_levelup_cost($hero['hero_rank'], $lv);
		$cmd = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd->execute(array($uid));
		$gold = (int)$cmd->fetchColumn();
		if ($gold < $cost) throw new Exception("골드가 부족합니다. (필요: {$cost}G)");

		$new_lv = $lv + 1;
		$pdo->prepare("UPDATE tb_heroes SET level = ? WHERE inv_id = ?")->execute(array($new_lv, $inv_id));
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($cost, $uid));

		$list = $pdo->prepare("SELECT inv_id, hero_rank, hero_name, quantity, level FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY hero_rank DESC, hero_name ASC");
		$list->execute(array($uid));
		$heroes = $list->fetchAll();
		$new_gold = (int)$pdo->query("SELECT gold FROM tb_commanders WHERE uid = {$uid}")->fetchColumn();
		$pdo->commit();

		echo json_encode(array('status'=>'success','msg'=>"✨ {$hero['hero_name']} 레벨이 Lv.{$new_lv}로 상승했습니다!",'new_gold'=>$new_gold,'html'=>render_hero_levelup_html($heroes)));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function get_or_create_relic(PDO $pdo, $uid) {
	$st = $pdo->prepare("SELECT relic_level, atk_bonus_percent, drop_bonus_percent FROM tb_relics WHERE uid = ? LIMIT 1");
	$st->execute(array($uid));
	$r = $st->fetch();
	if ($r) return $r;
	$pdo->prepare("INSERT INTO tb_relics (uid, relic_level, atk_bonus_percent, drop_bonus_percent) VALUES (?, 1, 0, 0)")->execute(array($uid));
	return array('relic_level' => 1, 'atk_bonus_percent' => 0, 'drop_bonus_percent' => 0);
}

function render_relic_html($relic, $gold) {
	$lv = (int)$relic['relic_level'];
	$atk = (int)$relic['atk_bonus_percent'];
	$drop = (int)$relic['drop_bonus_percent'];
	$cost = 500 + ($lv * 250);
	$disabled = ($gold < $cost) ? 'disabled' : '';
	$html = "<div style='background:#222; padding:15px; border-radius:6px;'>";
	$html .= "<h3 style='margin:0 0 8px 0; color:#ffeb3b;'>🗿 유물 제련소</h3>";
	$html .= "<div style='line-height:1.8;'>유물 레벨: <b>{$lv}</b><br>공격 보너스: <b>+{$atk}%</b><br>드랍 보너스: <b>+{$drop}%</b><br>보유 골드: <b>" . number_format($gold) . "G</b></div>";
	$html .= "<button class='btn' style='margin-top:10px; width:100%;' {$disabled} onclick='upgradeRelic()'>강화 ({$cost}G)</button>";
	$html .= "</div>";
	return $html;
}

function handle_relic_info(PDO $pdo) {
	app_log('handle_relic_info.start');
	$uid = get_uid_or_fail();
	try {
		$relic = get_or_create_relic($pdo, $uid);
		$gold = (int)$pdo->query("SELECT gold FROM tb_commanders WHERE uid = {$uid}")->fetchColumn();
		echo json_encode(array('status' => 'success', 'html' => render_relic_html($relic, $gold)));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_relic_upgrade(PDO $pdo) {
	app_log('handle_relic_upgrade.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$relic = get_or_create_relic($pdo, $uid);
		$lv = (int)$relic['relic_level'];
		$cost = 500 + ($lv * 250);

		$g = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$g->execute(array($uid));
		$gold = (int)$g->fetchColumn();
		if ($gold < $cost) throw new Exception("골드가 부족합니다. (필요: {$cost}G)");

		$new_lv = $lv + 1;
		$new_atk = (int)$relic['atk_bonus_percent'] + 2;
		$new_drop = (int)$relic['drop_bonus_percent'] + (($new_lv % 5 === 0) ? 1 : 0);
		$pdo->prepare("UPDATE tb_relics SET relic_level = ?, atk_bonus_percent = ?, drop_bonus_percent = ? WHERE uid = ?")
			->execute(array($new_lv, $new_atk, $new_drop, $uid));
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($cost, $uid));
		$pdo->commit();

		$new_gold = (int)$pdo->query("SELECT gold FROM tb_commanders WHERE uid = {$uid}")->fetchColumn();
		$new_relic = array('relic_level' => $new_lv, 'atk_bonus_percent' => $new_atk, 'drop_bonus_percent' => $new_drop);
		echo json_encode(array('status' => 'success', 'msg' => "🛠️ 유물이 Lv.{$new_lv}로 강화되었습니다!", 'new_gold' => $new_gold, 'html' => render_relic_html($new_relic, $new_gold)));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_stream_ai(PDO $pdo) {
	app_log('handle_stream_ai.start');
	if (!isset($_SESSION['uid'])) { http_response_code(400); exit; }
	header('Content-Type: text/event-stream; charset=utf-8');
	header('Cache-Control: no-cache');
	header('Connection: keep-alive');
	$text = isset($_SESSION['ai_stream_text']) ? (string)$_SESSION['ai_stream_text'] : '바람이 스쳐 지나갑니다...';
	unset($_SESSION['ai_stream_text']);
	$ai = request_ai_text_with_fallback($text, false);
	$ai_text = is_array($ai) ? (string)$ai['text'] : (string)$ai;
	$meta = is_array($ai) ? array('provider' => $ai['provider'], 'model' => $ai['model']) : array('provider' => 'raw', 'model' => 'unknown');
	stream_text_as_sse($ai_text, 28000, $meta);
	echo "data: [DONE]\n\n";
	@ob_flush(); @flush();
	exit;
}

function handle_stream_combat_ai(PDO $pdo) {
	app_log('handle_stream_combat_ai.start');
	if (!isset($_SESSION['uid'])) { http_response_code(400); exit; }
	header('Content-Type: text/event-stream; charset=utf-8');
	header('Cache-Control: no-cache');
	header('Connection: keep-alive');
	$combat_seed = isset($_SESSION['combat_stream_text']) ? (string)$_SESSION['combat_stream_text'] : '칼날이 어둠을 가르고, 전투의 파편이 흩어진다.';
	unset($_SESSION['combat_stream_text']);
	$ai = request_ai_text_with_fallback($combat_seed, false, 'combat');
	$ai_text = is_array($ai) ? (string)$ai['text'] : (string)$ai;
	$meta = is_array($ai) ? array('provider' => $ai['provider'], 'model' => $ai['model']) : array('provider' => 'raw', 'model' => 'unknown');
	stream_text_as_sse($ai_text, 7000, $meta, 1, 1);
	echo "data: [DONE]\n\n";
	@ob_flush(); @flush();
	exit;
}

function handle_stream_story_ai(PDO $pdo) {
	app_log('handle_stream_story_ai.start');
	if (!isset($_SESSION['uid'])) { http_response_code(400); exit; }
	header('Content-Type: text/event-stream; charset=utf-8');
	header('Cache-Control: no-cache');
	header('Connection: keep-alive');
	$story_seed = isset($_SESSION['story_stream_text']) ? (string)$_SESSION['story_stream_text'] : "낡은 석판의 문자가 천천히 빛나며, 잊힌 기록이 하나씩 떠오른다...";
	unset($_SESSION['story_stream_text']);
	$ai = request_ai_text_with_fallback($story_seed, true);
	$ai_story = is_array($ai) ? (string)$ai['text'] : (string)$ai;
	$meta = is_array($ai) ? array('provider' => $ai['provider'], 'model' => $ai['model']) : array('provider' => 'raw', 'model' => 'unknown');
	stream_text_as_sse($ai_story, 26000, $meta);
	echo "data: [DONE]\n\n";
	@ob_flush(); @flush();
	exit;
}

function handle_ranking(PDO $pdo) {
	app_log('handle_ranking.start');
	try {
		$rows = $pdo->query("SELECT nickname, level, max_floor, gold FROM tb_commanders ORDER BY max_floor DESC, level DESC, gold DESC LIMIT 50")->fetchAll();
		$html = "<table style='width:100%; border-collapse:collapse;'><tr><th style='text-align:left;'>순위</th><th style='text-align:left;'>닉네임</th><th>레벨</th><th>최고층</th><th>골드</th></tr>";
		$rank = 1;
		foreach ($rows as $r) {
			$html .= "<tr><td>{$rank}</td><td>{$r['nickname']}</td><td style='text-align:center;'>{$r['level']}</td><td style='text-align:center;'>{$r['max_floor']}</td><td style='text-align:right;'>" . number_format((int)$r['gold']) . "</td></tr>";
			$rank++;
		}
		$html .= "</table>";
		echo json_encode(array('status' => 'success', 'html' => $html));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_restart(PDO $pdo) {
	app_log('handle_restart.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT max_hp, max_mp FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		$sql = "UPDATE tb_commanders SET hp = ?, mp = ?, current_floor = 1, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?";
		$pdo->prepare($sql)->execute(array((int)$cmd['max_hp'], (int)$cmd['max_mp'], $uid));
		unset($_SESSION['combat_state']);

		$log = "✨ 여신의 축복으로 부활했습니다. 1층에서 다시 시작합니다.";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();

		echo json_encode(array('status' => 'success', 'log' => $log, 'max_hp' => (int)$cmd['max_hp'], 'max_mp' => (int)$cmd['max_mp']));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
app_log('api.request', array('action' => $action, 'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));

switch ($action) {
	case 'rest': handle_rest($pdo); break;
	case 'flee': handle_flee($pdo); break;
	case 'action': handle_action($pdo); break;
	case 'book': handle_book($pdo); break;
	case 'combat': handle_combat($pdo); break;
	case 'combine': handle_combine($pdo); break;
	case 'equip': handle_equip($pdo); break;
	case 'stream_ai': handle_stream_ai($pdo); break;
	case 'stream_combat_ai': handle_stream_combat_ai($pdo); break;
	case 'stream_story_ai': handle_stream_story_ai($pdo); break;
	case 'ranking': handle_ranking($pdo); break;
	case 'restart': handle_restart($pdo); break;
	case 'skill': handle_skill($pdo); break;
	case 'stat_up': handle_stat_up($pdo); break;
	case 'summon': handle_summon($pdo); break;
	case 'synthesize': handle_synthesize($pdo); break;
	case 'hero_levelup_view': handle_hero_levelup_view($pdo); break;
	case 'hero_levelup': handle_hero_levelup($pdo); break;
	case 'relic_info': handle_relic_info($pdo); break;
	case 'relic_upgrade': handle_relic_upgrade($pdo); break;
	case 'auto_explore_start': handle_auto_explore_start($pdo); break;
	case 'auto_explore_status': handle_auto_explore_status($pdo); break;
	case 'auto_explore_claim': handle_auto_explore_claim($pdo); break;
	case 'next_floor': handle_next_floor($pdo); break;
	case 'expedition_info': handle_expedition_info($pdo); break;
	case 'start_expedition': handle_start_expedition($pdo); break;
	case 'claim_expedition': handle_claim_expedition($pdo); break;
	case 'diag':
		$diag = array('status' => 'ok', 'time' => date('Y-m-d H:i:s'), 'php' => PHP_VERSION, 'session' => isset($_SESSION['uid']) ? $_SESSION['uid'] : 'none');
		try { $pdo->query("SELECT 1"); $diag['db'] = 'connected'; } catch (Exception $e) { $diag['db'] = $e->getMessage(); }
		echo json_encode($diag);
		break;
	default:
		http_response_code(404);
		json_error("API endpoint not found for action: {$action}");
		break;
}

