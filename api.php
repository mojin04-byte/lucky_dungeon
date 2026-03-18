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
	} elseif ($mode === 'intro_story') {
		$style = "한국어로 최소 100자 이상, 6~10문장의 장대한 다크 판타지 오프닝 내레이션을 출력하세요.";
		$rules = "\n규칙:"
			. "\n- 옵션/대안/후보를 나열하지 마세요."
			. "\n- '옵션 1/2/3', 제목, 마크다운(##, **, >)을 절대 쓰지 마세요."
			. "\n- 반드시 '세렌디피티 길드'와 사령관 아이디를 자연스럽게 포함하세요."
			. "\n- 마지막 문장은 1층을 향해 첫 발을 내딛는 장면으로 마무리하세요."
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

function utf8_len($text) {
	if (function_exists('mb_strlen')) {
		return (int)mb_strlen((string)$text, 'UTF-8');
	}
	return (int)strlen((string)$text);
}

function ensure_intro_story_quality($text, $uid_label = '', $commander_id = '') {
	$t = trim((string)$text);
	if ($t === '' || stripos($t, '다음 배경 설정을 바탕으로') !== false) {
		$t = "서사 생성 실패: AI 응답이 비어 있거나 생성에 실패했습니다.\n"
			. "세렌디피티 길드의 어둠 속에서 사령관 아이디 {$commander_id}와 식별 번호 {$uid_label}가 운명의 제단에 새겨진다. 잿빛 안개와 검은 성화가 회랑을 휘감고, 고대의 종이 울린다. 당신은 심연으로 첫 발을 내딛으며, 혼돈의 미궁이 장대한 어둠으로 당신을 맞이한다.";
	}

	if (strpos($t, '세렌디피티 길드') === false) {
		$t .= " 세렌디피티 길드의 인장이 갈라진 제단 위에서 붉은 번개처럼 맥동했다.";
	}

	if ($commander_id !== '' && strpos($t, $commander_id) === false) {
		$t .= " 사령관 아이디 {$commander_id}라는 이름이 저주받은 석판에 천천히 새겨졌다.";
	}

	if ($uid_label !== '' && strpos($t, $uid_label) === false) {
		$t .= " 봉인석의 마지막 홈에는 {$uid_label}가 각인되며 운명의 계약이 완성되었다.";
	}

	if (utf8_len($t) < 100) {
		$t .= " 잿빛 안개가 발목을 휘감는 가운데 오래된 종이 울리고, 당신은 비명을 삼킨 검은 하늘 아래 심연으로 향하는 첫 계단에 발을 올렸다.";
	}

	return trim($t);
}

function normalize_ai_output($text) {
	$t = trim((string)$text);
	if ($t === '') return $t;

	// 옵션 나열 출력이 들어오면 첫 옵션만 채택
	if (preg_match_all('/옵션\s*\d+\s*:\s*(.+?)(?=(옵션\s*\d+\s*:)|$)/us', $t, $m) && !empty($m[1])) {
		$t = trim((string)$m[1][0]);
	}

	// 불필요한 마크다운/접두어 제거
	$t = str_replace(array("\r\n", "\r"), "\n", $t);
	$t = preg_replace('/^\s*#{1,6}\s*/um', '', $t);
	$t = str_replace(array('**', '__'), '', $t);
	$t = preg_replace('/^\s*>\s*/um', '', $t);
	$t = preg_replace('/<\s*br\s*\/?>/iu', "\n", $t);
	$t = preg_replace('/<\s*\/\s*(p|div|li)\s*>/iu', "\n", $t);
	$t = strip_tags($t);
	$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$lines = preg_split('/\n/u', $t);
	if (is_array($lines)) {
		foreach ($lines as &$line) {
			$line = trim((string)preg_replace('/[ \t]+/u', ' ', (string)$line));
		}
		unset($line);
		$t = implode("\n", $lines);
	}
	$t = preg_replace('/\n{3,}/u', "\n\n", $t);
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

function reset_orc_frenzy_state() {
	$_SESSION['orc_frenzy_stacks'] = 0;
	if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) return;
	$_SESSION['combat_state']['orc_frenzy_stacks'] = 0;
	$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
}

function apply_orc_frenzy_decay(&$logs, $stack_max, $was_kill_turn) {
	if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) return;
	if (!isset($_SESSION['combat_state']['orc_no_kill_turns'])) {
		$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
	}

	$max_stack = max(0, (int)$stack_max);
	if ($max_stack <= 0) {
		reset_orc_frenzy_state();
		return;
	}

	if ($was_kill_turn) {
		$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
		return;
	}

	$_SESSION['combat_state']['orc_no_kill_turns'] = max(0, (int)$_SESSION['combat_state']['orc_no_kill_turns']) + 1;
	if ((int)$_SESSION['combat_state']['orc_no_kill_turns'] < 2) return;

	$current_stack = max(0, (int)(isset($_SESSION['orc_frenzy_stacks']) ? $_SESSION['orc_frenzy_stacks'] : 0));
	if ($current_stack > 0) {
		$current_stack -= 1;
		$_SESSION['orc_frenzy_stacks'] = $current_stack;
		$_SESSION['combat_state']['orc_frenzy_stacks'] = $current_stack;
		if (is_array($logs)) {
			$logs[] = "🪓 <span style='color:#ffcc80;'>[오크 광분]</span> 2턴 연속 처치 실패로 스택 감소 ({$current_stack}/{$max_stack})";
		}
	}
	$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
}

function append_balance_metrics_log(&$logs, $turn_damage, $incoming_damage, $hp_delta, $mp_delta, $first_hit_bonus_pct, $orc_bonus_pct) {
	if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) return;
	if (!isset($_SESSION['combat_state']['balance_metrics']) || !is_array($_SESSION['combat_state']['balance_metrics'])) {
		$_SESSION['combat_state']['balance_metrics'] = array('turn' => 0, 'rows' => array());
	}

	$metric =& $_SESSION['combat_state']['balance_metrics'];
	if (!isset($metric['turn'])) $metric['turn'] = 0;
	if (!isset($metric['rows']) || !is_array($metric['rows'])) $metric['rows'] = array();

	$metric['turn'] = (int)$metric['turn'] + 1;
	$metric['rows'][] = array(
		'damage' => max(0, (int)$turn_damage),
		'incoming' => max(0, (int)$incoming_damage),
		'hp_delta' => (int)$hp_delta,
		'mp_delta' => (int)$mp_delta,
		'first_hit' => max(0, (int)$first_hit_bonus_pct),
		'orc_bonus' => max(0, (int)$orc_bonus_pct),
	);

	if (count($metric['rows']) > 10) {
		$metric['rows'] = array_slice($metric['rows'], -10);
	}

	$rows = $metric['rows'];
	$count = max(1, count($rows));
	$sum_dmg = 0;
	$sum_in = 0;
	$sum_hp = 0;
	$sum_mp = 0;
	$sum_first = 0;
	$sum_orc = 0;
	foreach ($rows as $row) {
		$sum_dmg += (int)$row['damage'];
		$sum_in += (int)$row['incoming'];
		$sum_hp += (int)$row['hp_delta'];
		$sum_mp += (int)$row['mp_delta'];
		$sum_first += (int)$row['first_hit'];
		$sum_orc += (int)$row['orc_bonus'];
	}

	$avg_dmg = (int)round($sum_dmg / $count);
	$avg_in = (int)round($sum_in / $count);
	$avg_hp = (int)round($sum_hp / $count);
	$avg_mp = (int)round($sum_mp / $count);
	$avg_first = (int)round($sum_first / $count);
	$avg_orc = (int)round($sum_orc / $count);

	if (is_array($logs)) {
		$logs[] = "📊 <span style='color:#b39ddb;'>[10턴 지표]</span> 평균 딜 {$avg_dmg} | 평균 피격 {$avg_in} | 순HP {$avg_hp} | MP 수지 {$avg_mp} | 첫타 {$avg_first}% | 오크 {$avg_orc}%";
	}
}

function get_hero_traits($hero_name) {
	global $hero_traits_map;

	$default = array(
		'attack_type' => '미확인',
		'attack_range' => '미확인',
		'race' => '미확인',
		'known' => false,
	);

	$name = trim((string)$hero_name);
	if ($name === '') return $default;

	if (is_array($hero_traits_map) && isset($hero_traits_map[$name])) {
		$row = $hero_traits_map[$name];
		return array(
			'attack_type' => isset($row['attack_type']) ? (string)$row['attack_type'] : '미확인',
			'attack_range' => isset($row['attack_range']) ? (string)$row['attack_range'] : '미확인',
			'race' => isset($row['race']) ? (string)$row['race'] : '미확인',
			'known' => true,
		);
	}

	$alias = str_replace(' ', '', $name);
	if ($alias !== $name && is_array($hero_traits_map) && isset($hero_traits_map[$alias])) {
		$row = $hero_traits_map[$alias];
		return array(
			'attack_type' => isset($row['attack_type']) ? (string)$row['attack_type'] : '미확인',
			'attack_range' => isset($row['attack_range']) ? (string)$row['attack_range'] : '미확인',
			'race' => isset($row['race']) ? (string)$row['race'] : '미확인',
			'known' => true,
		);
	}

	return $default;
}

function get_hero_unit_count($hero) {
	if (isset($hero['equipped_count'])) return max(1, (int)$hero['equipped_count']);
	if (isset($hero['quantity'])) return max(1, (int)$hero['quantity']);
	return 1;
}

function get_deck_synergy_profile($floor) {
	$f = max(1, (int)$floor);
	if ($f <= 40) {
		return array(
			'tier' => '초반',
			'range' => '1-40',
			'physical_penetration_pct' => 14,
			'first_hit_bonus_pct' => 28,
			'balance_damage_reduction_pct' => 14,
			'mixed_crit_damage_bonus_pct' => 21,
			'reward_bonus_pct' => 10,
			'beast_double_attack_pp' => 8,
			'machine_shield_pct' => 6,
			'spirit_skill_damage_pct' => 18,
			'spirit_mp_regen' => 3,
			'demon_attack_bonus_pct' => 24,
			'demon_hp_drain_pct' => 2,
			'dragon_boss_damage_pct' => 18,
			'orc_kill_stack_bonus_pct' => 6,
			'orc_kill_stack_max' => 4,
			'multirace_all_damage_pct' => 10,
			'multirace_damage_reduction_pct' => 7,
			'total_damage_softcap_pct' => 110,
			'total_damage_hardcap_pct' => 140,
			'total_damage_overflow_scale' => 0.45,
			'first_hit_cap_pct' => 35,
			'boss_damage_cap_pct' => 25,
			'orc_stack_total_cap_pct' => 32,
			'incoming_reduction_cap_pct' => 30,
			'reward_bonus_cap_pct' => 15,
			'dual_core_overlap_scale' => 0.70,
			'demon_multirace_scale' => 0.70,
			'demon_overload_threshold_pct' => 140,
			'demon_overload_extra_drain_pct' => 1,
		);
	}
	if ($f <= 120) {
		return array(
			'tier' => '중반',
			'range' => '41-120',
			'physical_penetration_pct' => 20,
			'first_hit_bonus_pct' => 40,
			'balance_damage_reduction_pct' => 20,
			'mixed_crit_damage_bonus_pct' => 30,
			'reward_bonus_pct' => 15,
			'beast_double_attack_pp' => 12,
			'machine_shield_pct' => 8,
			'spirit_skill_damage_pct' => 25,
			'spirit_mp_regen' => 5,
			'demon_attack_bonus_pct' => 35,
			'demon_hp_drain_pct' => 3,
			'dragon_boss_damage_pct' => 25,
			'orc_kill_stack_bonus_pct' => 10,
			'orc_kill_stack_max' => 5,
			'multirace_all_damage_pct' => 15,
			'multirace_damage_reduction_pct' => 10,
			'total_damage_softcap_pct' => 150,
			'total_damage_hardcap_pct' => 190,
			'total_damage_overflow_scale' => 0.45,
			'first_hit_cap_pct' => 50,
			'boss_damage_cap_pct' => 35,
			'orc_stack_total_cap_pct' => 40,
			'incoming_reduction_cap_pct' => 40,
			'reward_bonus_cap_pct' => 20,
			'dual_core_overlap_scale' => 0.70,
			'demon_multirace_scale' => 0.70,
			'demon_overload_threshold_pct' => 180,
			'demon_overload_extra_drain_pct' => 1,
		);
	}

	return array(
		'tier' => '후반',
		'range' => '121+',
		'physical_penetration_pct' => 28,
		'first_hit_bonus_pct' => 56,
		'balance_damage_reduction_pct' => 28,
		'mixed_crit_damage_bonus_pct' => 42,
		'reward_bonus_pct' => 22,
		'beast_double_attack_pp' => 16,
		'machine_shield_pct' => 11,
		'spirit_skill_damage_pct' => 35,
		'spirit_mp_regen' => 7,
		'demon_attack_bonus_pct' => 48,
		'demon_hp_drain_pct' => 4,
		'dragon_boss_damage_pct' => 35,
		'orc_kill_stack_bonus_pct' => 12,
		'orc_kill_stack_max' => 6,
		'multirace_all_damage_pct' => 20,
		'multirace_damage_reduction_pct' => 13,
		'total_damage_softcap_pct' => 190,
		'total_damage_hardcap_pct' => 240,
		'total_damage_overflow_scale' => 0.45,
		'first_hit_cap_pct' => 65,
		'boss_damage_cap_pct' => 45,
		'orc_stack_total_cap_pct' => 48,
		'incoming_reduction_cap_pct' => 50,
		'reward_bonus_cap_pct' => 25,
		'dual_core_overlap_scale' => 0.70,
		'demon_multirace_scale' => 0.70,
		'demon_overload_threshold_pct' => 220,
		'demon_overload_extra_drain_pct' => 2,
	);
}

function build_deck_synergy_summary($heroes, $assume_equipped = false, $floor = 1) {
	$profile = get_deck_synergy_profile($floor);

	$summary = array(
		'total_units' => 0,
		'melee_units' => 0,
		'ranged_units' => 0,
		'physical_units' => 0,
		'magic_units' => 0,
		'race_counts' => array(),
		'distinct_race_count' => 0,
		'floor_tier' => $profile['tier'],
		'floor_range' => $profile['range'],
		'attack_bonus_percent' => 0,
		'global_damage_bonus_percent' => 0,
		'raw_total_damage_bonus_percent' => 0,
		'total_damage_bonus_percent' => 0,
		'physical_penetration_percent' => 0,
		'first_hit_bonus_percent' => 0,
		'incoming_damage_reduction_percent' => 0,
		'incoming_damage_multiplier' => 1.0,
		'crit_damage_bonus_percent' => 0,
		'reward_bonus_percent' => 0,
		'double_attack_bonus_point' => 0,
		'shield_percent' => 0,
		'skill_damage_bonus_percent' => 0,
		'skill_damage_multiplier' => 1.0,
		'mp_regen_per_turn' => 0,
		'demon_hp_drain_percent' => 0,
		'boss_damage_bonus_percent' => 0,
		'orc_kill_stack_bonus_percent' => 0,
		'orc_kill_stack_max' => 0,
		'orc_stack_total_cap_percent' => 0,
		'attack_multiplier' => 1.0,
		'all_damage_multiplier' => 1.0,
		'active_effects' => array(),
	);

	foreach ($heroes as $hero) {
		$is_equipped = $assume_equipped || ((int)(isset($hero['is_equipped']) ? $hero['is_equipped'] : 0) === 1);
		$is_expedition = ((int)(isset($hero['is_on_expedition']) ? $hero['is_on_expedition'] : 0) === 1);
		if (!$is_equipped || $is_expedition) continue;

		$count = get_hero_unit_count($hero);
		$summary['total_units'] += $count;

		$traits = get_hero_traits(isset($hero['hero_name']) ? $hero['hero_name'] : '');
		if (in_array($traits['attack_range'], array('근거리', '근접'), true)) $summary['melee_units'] += $count;
		if ($traits['attack_range'] === '원거리') $summary['ranged_units'] += $count;
		if ($traits['attack_type'] === '물리') $summary['physical_units'] += $count;
		if (in_array($traits['attack_type'], array('마법', '마법딜러'), true)) $summary['magic_units'] += $count;
		$race = trim((string)$traits['race']);
		if ($race !== '' && $race !== '미확인') {
			if (!isset($summary['race_counts'][$race])) $summary['race_counts'][$race] = 0;
			$summary['race_counts'][$race] += $count;
		}
	}

	$summary['distinct_race_count'] = count($summary['race_counts']);

	$human_units = isset($summary['race_counts']['인간']) ? (int)$summary['race_counts']['인간'] : 0;
	$animal_units = isset($summary['race_counts']['동물']) ? (int)$summary['race_counts']['동물'] : 0;
	$robot_units = isset($summary['race_counts']['로봇']) ? (int)$summary['race_counts']['로봇'] : 0;
	$spirit_units = isset($summary['race_counts']['정령']) ? (int)$summary['race_counts']['정령'] : 0;
	$demon_units = isset($summary['race_counts']['악마']) ? (int)$summary['race_counts']['악마'] : 0;
	$dragon_units = isset($summary['race_counts']['드래곤']) ? (int)$summary['race_counts']['드래곤'] : 0;
	$orc_units = isset($summary['race_counts']['오크']) ? (int)$summary['race_counts']['오크'] : 0;

	$has_melee_core = false;
	$has_magic_core = false;
	$has_demon_contract = false;

	if ($summary['melee_units'] >= 4) {
		$has_melee_core = true;
		$summary['attack_bonus_percent'] += 50;
		$summary['active_effects'][] = '근거리 영웅 4명 이상: 공격력 +50%';
	}
	if ($summary['magic_units'] >= 4) {
		$has_magic_core = true;
		$summary['attack_bonus_percent'] += 50;
		$summary['active_effects'][] = '마법 영웅 4명 이상: 공격력 +50%';
	}
	if ($has_melee_core && $has_magic_core) {
		$overlap_scale = max(0.0, min(1.0, (float)$profile['dual_core_overlap_scale']));
		$overlap_penalty = max(0, (int)round(50 * (1.0 - $overlap_scale)));
		if ($overlap_penalty > 0) {
			$summary['attack_bonus_percent'] = max(0, (int)$summary['attack_bonus_percent'] - $overlap_penalty);
			$overlap_ratio = (int)round($overlap_scale * 100);
			$summary['active_effects'][] = "근/마 동시 채용 조정: 중첩 보너스 {$overlap_ratio}% 적용 (-{$overlap_penalty}%)";
		}
	}
	if ($summary['physical_units'] >= 4) {
		$summary['physical_penetration_percent'] = $profile['physical_penetration_pct'];
		$summary['active_effects'][] = "물리 타격대: 방어 관통 +{$profile['physical_penetration_pct']}%";
	}
	if ($summary['ranged_units'] >= 4) {
		$summary['first_hit_bonus_percent'] = $profile['first_hit_bonus_pct'];
		$summary['active_effects'][] = "원거리 포격: 첫 타 피해 +{$profile['first_hit_bonus_pct']}%";
	}
	if ($summary['melee_units'] >= 2 && $summary['ranged_units'] >= 2) {
		$summary['incoming_damage_reduction_percent'] += $profile['balance_damage_reduction_pct'];
		$summary['active_effects'][] = "균형 진형: 받는 피해 -{$profile['balance_damage_reduction_pct']}%";
	}
	if ($summary['physical_units'] >= 2 && $summary['magic_units'] >= 2) {
		$summary['crit_damage_bonus_percent'] += $profile['mixed_crit_damage_bonus_pct'];
		$summary['active_effects'][] = "혼합 화력: 치명타 피해 +{$profile['mixed_crit_damage_bonus_pct']}%";
	}
	if ($human_units >= 4) {
		$summary['reward_bonus_percent'] += $profile['reward_bonus_pct'];
		$summary['active_effects'][] = "인간 연합: 전투 보상 +{$profile['reward_bonus_pct']}%";
	}
	if ($animal_units >= 3) {
		$summary['double_attack_bonus_point'] += $profile['beast_double_attack_pp'];
		$summary['active_effects'][] = "야수 본능: 연속 공격 확률 +{$profile['beast_double_attack_pp']}%p";
	}
	if ($robot_units >= 3) {
		$summary['shield_percent'] = max($summary['shield_percent'], $profile['machine_shield_pct']);
		$summary['active_effects'][] = "기계 방진: 턴 시작 보호막 {$profile['machine_shield_pct']}%";
	}
	if ($spirit_units >= 3) {
		$summary['skill_damage_bonus_percent'] += $profile['spirit_skill_damage_pct'];
		$summary['mp_regen_per_turn'] += $profile['spirit_mp_regen'];
		$summary['active_effects'][] = "정령 공명: 스킬 피해 +{$profile['spirit_skill_damage_pct']}%, 턴당 MP +{$profile['spirit_mp_regen']}";
	}
	if ($demon_units >= 3) {
		$has_demon_contract = true;
		$summary['attack_bonus_percent'] += $profile['demon_attack_bonus_pct'];
		$summary['demon_hp_drain_percent'] = max($summary['demon_hp_drain_percent'], $profile['demon_hp_drain_pct']);
		$summary['active_effects'][] = "악마 계약: 공격력 +{$profile['demon_attack_bonus_pct']}%, 턴 종료 HP {$profile['demon_hp_drain_pct']}% 소모";
	}
	if ($dragon_units >= 1 && $summary['magic_units'] >= 3) {
		$summary['boss_damage_bonus_percent'] = max($summary['boss_damage_bonus_percent'], $profile['dragon_boss_damage_pct']);
		$summary['active_effects'][] = "용혈 압도: 보스 대상 피해 +{$profile['dragon_boss_damage_pct']}%";
	}
	if ($orc_units >= 2) {
		$summary['orc_kill_stack_bonus_percent'] = max($summary['orc_kill_stack_bonus_percent'], $profile['orc_kill_stack_bonus_pct']);
		$summary['orc_kill_stack_max'] = max($summary['orc_kill_stack_max'], $profile['orc_kill_stack_max']);
		$summary['orc_stack_total_cap_percent'] = max(0, (int)$profile['orc_stack_total_cap_pct']);
		$summary['active_effects'][] = "오크 광분: 처치 스택 +{$profile['orc_kill_stack_bonus_pct']}% (최대 {$profile['orc_kill_stack_max']}스택, 총합 +{$profile['orc_stack_total_cap_pct']}% 상한)";
	}
	if ($summary['distinct_race_count'] >= 4) {
		$multirace_bonus = (int)$profile['multirace_all_damage_pct'];
		if ($has_demon_contract) {
			$demon_link_scale = max(0.0, min(1.0, (float)$profile['demon_multirace_scale']));
			$scaled_bonus = (int)round($multirace_bonus * $demon_link_scale);
			if ($scaled_bonus < $multirace_bonus) {
				$summary['active_effects'][] = "악마 계약 간섭: 다종족 피해 보너스 {$scaled_bonus}%로 조정";
			}
			$multirace_bonus = $scaled_bonus;
		}
		$summary['global_damage_bonus_percent'] += $multirace_bonus;
		$summary['incoming_damage_reduction_percent'] += $profile['multirace_damage_reduction_pct'];
		$summary['active_effects'][] = "다종족 연계: 모든 피해 +{$multirace_bonus}%, 받는 피해 -{$profile['multirace_damage_reduction_pct']}%";
	}

	$first_hit_cap = max(0, (int)$profile['first_hit_cap_pct']);
	if ((int)$summary['first_hit_bonus_percent'] > $first_hit_cap) {
		$summary['first_hit_bonus_percent'] = $first_hit_cap;
	}

	$boss_damage_cap = max(0, (int)$profile['boss_damage_cap_pct']);
	if ((int)$summary['boss_damage_bonus_percent'] > $boss_damage_cap) {
		$summary['boss_damage_bonus_percent'] = $boss_damage_cap;
		$summary['active_effects'][] = "보스 피해 상한 적용: +{$boss_damage_cap}%";
	}

	$reward_cap = max(0, (int)$profile['reward_bonus_cap_pct']);
	if ((int)$summary['reward_bonus_percent'] > $reward_cap) {
		$summary['reward_bonus_percent'] = $reward_cap;
		$summary['active_effects'][] = "보상 보너스 상한 적용: +{$reward_cap}%";
	}

	$incoming_cap = max(0, (int)$profile['incoming_reduction_cap_pct']);
	$summary['incoming_damage_reduction_percent'] = min($incoming_cap, (int)$summary['incoming_damage_reduction_percent']);

	$raw_total_damage = max(0, (int)$summary['attack_bonus_percent'] + (int)$summary['global_damage_bonus_percent']);
	$summary['raw_total_damage_bonus_percent'] = $raw_total_damage;

	$soft_cap = max(0, (int)$profile['total_damage_softcap_pct']);
	$hard_cap = max($soft_cap, (int)$profile['total_damage_hardcap_pct']);
	$overflow_scale = max(0.0, min(1.0, (float)$profile['total_damage_overflow_scale']));
	$effective_total_damage = (float)$raw_total_damage;
	if ($effective_total_damage > $soft_cap) {
		$overflow = $effective_total_damage - $soft_cap;
		$effective_total_damage = $soft_cap + ($overflow * $overflow_scale);
	}
	$effective_total_damage = min($effective_total_damage, $hard_cap);
	$summary['total_damage_bonus_percent'] = max(0, (int)round($effective_total_damage));

	if ($summary['total_damage_bonus_percent'] < $raw_total_damage) {
		$summary['active_effects'][] = "피해 상승 상한 적용: +{$raw_total_damage}% → +{$summary['total_damage_bonus_percent']}%";
	}

	if ($has_demon_contract) {
		$demon_overload_threshold = max(0, (int)$profile['demon_overload_threshold_pct']);
		if ($raw_total_damage >= $demon_overload_threshold) {
			$extra_drain = max(0, (int)$profile['demon_overload_extra_drain_pct']);
			if ($extra_drain > 0) {
				$summary['demon_hp_drain_percent'] += $extra_drain;
				$summary['active_effects'][] = "악마 과부하: HP 소모 +{$extra_drain}% (총 {$summary['demon_hp_drain_percent']}%)";
			}
		}
	}

	$summary['incoming_damage_multiplier'] = max(0.2, 1.0 - ($summary['incoming_damage_reduction_percent'] / 100.0));
	$summary['skill_damage_multiplier'] = 1.0 + (((int)$summary['skill_damage_bonus_percent']) / 100.0);
	$summary['attack_multiplier'] = 1.0 + (((int)$summary['attack_bonus_percent']) / 100.0);
	$summary['all_damage_multiplier'] = 1.0 + ($summary['total_damage_bonus_percent'] / 100.0);

	return $summary;
}

function render_deck_synergy_html($summary) {
	$melee = (int)(isset($summary['melee_units']) ? $summary['melee_units'] : 0);
	$ranged = (int)(isset($summary['ranged_units']) ? $summary['ranged_units'] : 0);
	$physical = (int)(isset($summary['physical_units']) ? $summary['physical_units'] : 0);
	$magic = (int)(isset($summary['magic_units']) ? $summary['magic_units'] : 0);
	$bonus = (int)(isset($summary['total_damage_bonus_percent']) ? $summary['total_damage_bonus_percent'] : 0);
	$raw_bonus = (int)(isset($summary['raw_total_damage_bonus_percent']) ? $summary['raw_total_damage_bonus_percent'] : $bonus);
	$incoming_reduction = (int)(isset($summary['incoming_damage_reduction_percent']) ? $summary['incoming_damage_reduction_percent'] : 0);
	$distinct_races = (int)(isset($summary['distinct_race_count']) ? $summary['distinct_race_count'] : 0);
	$tier = isset($summary['floor_tier']) ? (string)$summary['floor_tier'] : '중반';
	$range_label = isset($summary['floor_range']) ? (string)$summary['floor_range'] : '41-120';
	$total = (int)(isset($summary['total_units']) ? $summary['total_units'] : 0);
	$effects = isset($summary['active_effects']) && is_array($summary['active_effects']) ? $summary['active_effects'] : array();

	$melee_active = ($melee >= 4 || ($melee >= 2 && $ranged >= 2));
	$magic_active = ($magic >= 4 || ($physical >= 2 && $magic >= 2));
	$melee_bg = $melee_active ? '#2e7d32' : '#333';
	$magic_bg = $magic_active ? '#7b1fa2' : '#333';
	$range_bg = ($ranged >= 4) ? '#1565c0' : '#333';
	$physical_bg = ($physical >= 4) ? '#8d6e63' : '#333';

	$html = "<div title='층 구간별 시너지 수치가 자동 반영됩니다.' style='margin:8px 0 10px; padding:10px; background:#1b1b1b; border:1px solid #3b3b3b; border-radius:6px;'>";
	$html .= "<div style='display:flex; justify-content:space-between; align-items:center; gap:8px;'>";
	$html .= "<span style='font-size:0.85rem; color:#cfd8dc; font-weight:bold;'>⚙️ 출전 덱 시너지</span>";
	$html .= "<span style='font-size:0.82rem; color:#90caf9; font-weight:bold;'>{$tier} ({$range_label})</span>";
	$html .= "</div>";
	$bonus_text = "+{$bonus}%";
	if ($raw_bonus > $bonus) {
		$bonus_text = "+{$bonus}% (원본 +{$raw_bonus}%)";
	}
	$html .= "<div style='font-size:0.75rem; color:#9e9e9e; margin-top:4px;'>출전 총원: {$total}명 | 피해 {$bonus_text} | 받피 -{$incoming_reduction}% | 종족 {$distinct_races}종</div>";
	$html .= "<div style='display:grid; gap:5px; margin-top:8px;'>";
	$html .= "<div style='padding:6px 8px; border-radius:5px; background:{$melee_bg}; color:#fff; font-size:0.78rem;'>근거리 {$melee}명</div>";
	$html .= "<div style='padding:6px 8px; border-radius:5px; background:{$range_bg}; color:#fff; font-size:0.78rem;'>원거리 {$ranged}명</div>";
	$html .= "<div style='padding:6px 8px; border-radius:5px; background:{$physical_bg}; color:#fff; font-size:0.78rem;'>물리 {$physical}명</div>";
	$html .= "<div style='padding:6px 8px; border-radius:5px; background:{$magic_bg}; color:#fff; font-size:0.78rem;'>마법 {$magic}명</div>";
	if (!empty($effects)) {
		$html .= "<div style='margin-top:4px; padding:7px 8px; border-radius:5px; background:#262626; color:#e0e0e0; font-size:0.74rem; line-height:1.45;'>";
		$html .= implode('<br>', array_map('htmlspecialchars', $effects));
		$html .= "</div>";
	}
	$html .= "</div></div>";

	return $html;
}

function estimate_expected_turn_damage(PDO $pdo, $uid, $cmd) {
	$p_str = (int)$cmd['stat_str'];
	$p_luk = (int)$cmd['stat_luk'];
	$p_men = (int)$cmd['stat_men'];
	$p_agi = (int)$cmd['stat_agi'];
	$current_floor = max(1, (int)(isset($cmd['current_floor']) ? $cmd['current_floor'] : 1));

	$crit_chance = min(100, max(0, (int)floor($p_luk / 2)));
	$crit_chance_rate = $crit_chance / 100.0;
	$crit_mult = 1.5 + ($p_luk * 0.01);
	$men_mult = 1 + ($p_men * 0.005);
	$agi_double_rate = min(100, max(0, (int)floor($p_agi / 5))) / 100.0;
	$agi_mult = 1 + $agi_double_rate;

	$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
	$deck_stmt->execute(array($uid));
	$deck = $deck_stmt->fetchAll();
	$deck_synergy = build_deck_synergy_summary($deck, true, $current_floor);

	$crit_bonus_pct = (int)(isset($deck_synergy['crit_damage_bonus_percent']) ? $deck_synergy['crit_damage_bonus_percent'] : 0);
	if ($crit_bonus_pct > 0) {
		$crit_mult *= (1 + ($crit_bonus_pct / 100.0));
	}
	$expected_crit_mult = 1 + ($crit_chance_rate * ($crit_mult - 1));

	$commander_base = max(1, (int)floor(($p_str * 1.8) + 8));
	$commander_expected = (float)$commander_base * $expected_crit_mult;

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

	$all_damage_mult = (float)(isset($deck_synergy['all_damage_multiplier']) ? $deck_synergy['all_damage_multiplier'] : 1.0);
	$first_hit_bonus_pct = (int)(isset($deck_synergy['first_hit_bonus_percent']) ? $deck_synergy['first_hit_bonus_percent'] : 0);
	$first_hit_weight = 1 + (($first_hit_bonus_pct / 100.0) * 0.35);

	$expected_turn_damage = ($commander_expected + $heroes_expected) * $agi_mult * $all_damage_mult * $first_hit_weight;
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

function get_hero_capacity_snapshot(PDO $pdo, $uid) {
	$prog = get_or_create_commander_progression_state($pdo, $uid);
	$hero_limit = get_hero_capacity_limit_by_progression($prog);
	$hero_owned = get_total_hero_units($pdo, $uid);
	return array('hero_owned' => $hero_owned, 'hero_limit' => $hero_limit);
}

function is_singleton_limited_hero_rank($hero_rank) {
	return in_array((string)$hero_rank, array('신화', '불멸', '유일'), true);
}

function get_hero_rank_from_catalog($hero_name) {
	global $hero_data;
	return isset($hero_data[$hero_name]['rank']) ? (string)$hero_data[$hero_name]['rank'] : '';
}

function has_owned_hero_by_names(PDO $pdo, $uid, $hero_names) {
	$names = is_array($hero_names) ? $hero_names : array($hero_names);
	$unique_names = array();
	foreach ($names as $name) {
		$name = trim((string)$name);
		if ($name !== '') $unique_names[$name] = true;
	}
	$hero_names = array_keys($unique_names);
	if (empty($hero_names)) return false;

	$ph = implode(',', array_fill(0, count($hero_names), '?'));
	$params = array_merge(array($uid), $hero_names);
	$st = $pdo->prepare("SELECT 1 FROM tb_heroes WHERE uid = ? AND hero_name IN ({$ph}) AND quantity > 0 LIMIT 1");
	$st->execute($params);
	return (bool)$st->fetchColumn();
}

function ensure_singleton_hero_not_owned(PDO $pdo, $uid, $hero_name, $hero_rank = null, $hero_names = null) {
	$rank = ($hero_rank === null || $hero_rank === '') ? get_hero_rank_from_catalog($hero_name) : (string)$hero_rank;
	if (!is_singleton_limited_hero_rank($rank)) return;

	$names_to_check = ($hero_names === null) ? array($hero_name) : $hero_names;
	if (has_owned_hero_by_names($pdo, $uid, $names_to_check)) {
		throw new Exception("이미 보유한 {$rank} 영웅 {$hero_name}은(는) 중복 획득할 수 없습니다.");
	}
}

function get_commander_progression_defaults($uid = 0) {
	return array(
		'uid' => (int)$uid,
		'hero_capacity_tier' => 0,
		'mastery_atk_level' => 0,
		'mastery_def_level' => 0,
		'mastery_gold_level' => 0,
		'mastery_drop_level' => 0
	);
}

function fetch_commander_progression_row(PDO $pdo, $uid, $for_update = false) {
	$sql = "SELECT uid, hero_capacity_tier, mastery_atk_level, mastery_def_level, mastery_gold_level, mastery_drop_level FROM tb_commander_progression WHERE uid = ?";
	if ($for_update) $sql .= " FOR UPDATE";
	$st = $pdo->prepare($sql);
	$st->execute(array($uid));
	$row = $st->fetch();
	if (!$row) return null;
	return array(
		'uid' => (int)$row['uid'],
		'hero_capacity_tier' => max(0, (int)$row['hero_capacity_tier']),
		'mastery_atk_level' => max(0, (int)$row['mastery_atk_level']),
		'mastery_def_level' => max(0, (int)$row['mastery_def_level']),
		'mastery_gold_level' => max(0, (int)$row['mastery_gold_level']),
		'mastery_drop_level' => max(0, (int)$row['mastery_drop_level'])
	);
}

function get_commander_progression_state(PDO $pdo, $uid) {
	$row = fetch_commander_progression_row($pdo, $uid, false);
	if (!$row) return get_commander_progression_defaults($uid);
	return array_merge(get_commander_progression_defaults($uid), $row);
}

function get_or_create_commander_progression_state(PDO $pdo, $uid, $for_update = false) {
	$row = fetch_commander_progression_row($pdo, $uid, $for_update);
	if ($row) return array_merge(get_commander_progression_defaults($uid), $row);

	try {
		$pdo->prepare("INSERT INTO tb_commander_progression (uid, hero_capacity_tier, mastery_atk_level, mastery_def_level, mastery_gold_level, mastery_drop_level) VALUES (?, 0, 0, 0, 0, 0)")
			->execute(array($uid));
	} catch (Exception $e) {
		// 동시 생성 레이스는 무시하고 재조회
	}

	$row = fetch_commander_progression_row($pdo, $uid, $for_update);
	if (!$row) return get_commander_progression_defaults($uid);
	return array_merge(get_commander_progression_defaults($uid), $row);
}

function get_hero_capacity_limit_by_progression($progression_state) {
	$tier = max(0, (int)(isset($progression_state['hero_capacity_tier']) ? $progression_state['hero_capacity_tier'] : 0));
	return 20 + $tier;
}

function get_blessing_type_catalog() {
	return array(
		'war_fury' => array('name' => '전투의 분노', 'description' => '전투 최종 피해 +{v}%', 'color' => '#ef5350', 'min' => 6, 'max' => 18),
		'gilded_fate' => array('name' => '황금의 운명', 'description' => '골드 획득 +{v}%', 'color' => '#ffd54f', 'min' => 8, 'max' => 24),
		'sage_wisdom' => array('name' => '현자의 통찰', 'description' => '경험치 획득 +{v}%', 'color' => '#90caf9', 'min' => 10, 'max' => 28),
		'aegis_oath' => array('name' => '방벽의 맹세', 'description' => '받는 피해 -{v}%', 'color' => '#80cbc4', 'min' => 5, 'max' => 16),
		'mana_echo' => array('name' => '마나 메아리', 'description' => '전투 턴 MP 추가 회복 +{v}', 'color' => '#b39ddb', 'min' => 4, 'max' => 14)
	);
}

function get_blessing_meta($blessing_type, $blessing_value) {
	$catalog = get_blessing_type_catalog();
	$type = (string)$blessing_type;
	if (!isset($catalog[$type])) {
		$type = 'war_fury';
	}
	$def = $catalog[$type];
	$value = max(0, (int)$blessing_value);
	$desc = str_replace('{v}', (string)$value, (string)$def['description']);
	return array(
		'type' => $type,
		'value' => $value,
		'name' => (string)$def['name'],
		'description' => $desc,
		'color' => (string)$def['color']
	);
}

function roll_random_blessing($floor = 1, $reroll_count = 0) {
	$catalog = get_blessing_type_catalog();
	$keys = array_keys($catalog);
	$type = $keys[array_rand($keys)];
	$def = $catalog[$type];
	$floor_scale = (int)floor(max(1, (int)$floor) / 35);
	$reroll_scale = (int)floor(max(0, (int)$reroll_count) / 6);
	$min_v = max(1, (int)$def['min'] + $floor_scale + $reroll_scale);
	$max_v = max($min_v, (int)$def['max'] + ($floor_scale * 2) + $reroll_scale);
	$value = rand($min_v, $max_v);
	return array('blessing_type' => $type, 'blessing_value' => $value);
}

function get_commander_blessing_defaults($uid = 0) {
	return array(
		'uid' => (int)$uid,
		'blessing_type' => '',
		'blessing_value' => 0,
		'reroll_count' => 0
	);
}

function fetch_commander_blessing_row(PDO $pdo, $uid, $for_update = false) {
	$sql = "SELECT uid, blessing_type, blessing_value, reroll_count FROM tb_commander_blessings WHERE uid = ?";
	if ($for_update) $sql .= " FOR UPDATE";
	$st = $pdo->prepare($sql);
	$st->execute(array($uid));
	$row = $st->fetch();
	if (!$row) return null;
	return array(
		'uid' => (int)$row['uid'],
		'blessing_type' => (string)$row['blessing_type'],
		'blessing_value' => max(0, (int)$row['blessing_value']),
		'reroll_count' => max(0, (int)$row['reroll_count'])
	);
}

function get_commander_blessing_state(PDO $pdo, $uid) {
	$row = fetch_commander_blessing_row($pdo, $uid, false);
	if (!$row) return get_commander_blessing_defaults($uid);
	return array_merge(get_commander_blessing_defaults($uid), $row);
}

function get_or_create_commander_blessing_state(PDO $pdo, $uid, $floor = 1, $for_update = false) {
	$row = fetch_commander_blessing_row($pdo, $uid, $for_update);
	if ($row) return array_merge(get_commander_blessing_defaults($uid), $row);

	$rolled = roll_random_blessing($floor, 0);
	try {
		$pdo->prepare("INSERT INTO tb_commander_blessings (uid, blessing_type, blessing_value, reroll_count) VALUES (?, ?, ?, 0)")
			->execute(array($uid, $rolled['blessing_type'], (int)$rolled['blessing_value']));
	} catch (Exception $e) {
		// 동시 생성 레이스는 무시
	}

	$row = fetch_commander_blessing_row($pdo, $uid, $for_update);
	if (!$row) {
		return array_merge(get_commander_blessing_defaults($uid), array(
			'blessing_type' => (string)$rolled['blessing_type'],
			'blessing_value' => (int)$rolled['blessing_value']
		));
	}
	return array_merge(get_commander_blessing_defaults($uid), $row);
}

function get_runtime_modifier_bundle(PDO $pdo, $uid) {
	$progression = get_commander_progression_state($pdo, $uid);
	$blessing = get_commander_blessing_state($pdo, $uid);

	$mods = array(
		'damage_bonus_pct' => max(0, (int)$progression['mastery_atk_level'] * 2),
		'incoming_reduction_pct' => min(65, max(0, (int)$progression['mastery_def_level'])),
		'reward_gold_bonus_pct' => max(0, (int)$progression['mastery_gold_level'] * 3),
		'reward_exp_bonus_pct' => 0,
		'drop_bonus_pct' => min(140, max(0, (int)$progression['mastery_drop_level'] * 5)),
		'combat_mp_regen' => 0,
		'hero_capacity_limit' => get_hero_capacity_limit_by_progression($progression),
		'progression' => $progression,
		'blessing' => $blessing
	);

	$blessing_type = (string)$blessing['blessing_type'];
	$blessing_value = max(0, (int)$blessing['blessing_value']);
	if ($blessing_type === 'war_fury') {
		$mods['damage_bonus_pct'] += $blessing_value;
	} elseif ($blessing_type === 'gilded_fate') {
		$mods['reward_gold_bonus_pct'] += $blessing_value;
	} elseif ($blessing_type === 'sage_wisdom') {
		$mods['reward_exp_bonus_pct'] += $blessing_value;
	} elseif ($blessing_type === 'aegis_oath') {
		$mods['incoming_reduction_pct'] = min(65, (int)$mods['incoming_reduction_pct'] + $blessing_value);
	} elseif ($blessing_type === 'mana_echo') {
		$mods['combat_mp_regen'] += $blessing_value;
	}

	return $mods;
}

function get_progression_upgrade_catalog() {
	return array(
		'atk' => array('column' => 'mastery_atk_level', 'label' => '검격 숙련', 'effect' => '전투 최종 피해 +2%', 'base_cost' => 900, 'growth' => 1.55, 'max_level' => 120),
		'def' => array('column' => 'mastery_def_level', 'label' => '강철 피부', 'effect' => '받는 피해 -1%', 'base_cost' => 920, 'growth' => 1.54, 'max_level' => 120),
		'gold' => array('column' => 'mastery_gold_level', 'label' => '황금 감각', 'effect' => '골드 획득 +3%', 'base_cost' => 980, 'growth' => 1.56, 'max_level' => 120),
		'drop' => array('column' => 'mastery_drop_level', 'label' => '전리품 감별', 'effect' => '드랍 가중치 +5%', 'base_cost' => 1000, 'growth' => 1.58, 'max_level' => 120),
		'hero_cap' => array('column' => 'hero_capacity_tier', 'label' => '용병 수송 마차', 'effect' => '영웅 보유 한도 +1명', 'base_cost' => 2400, 'growth' => 1.72, 'max_level' => 30)
	);
}

function get_progression_upgrade_cost($upgrade_key, $current_level) {
	$catalog = get_progression_upgrade_catalog();
	if (!isset($catalog[$upgrade_key])) return 0;
	$def = $catalog[$upgrade_key];
	$level = max(0, (int)$current_level);
	$base = max(1, (int)$def['base_cost']);
	$growth = max(1.01, (float)$def['growth']);
	return (int)floor($base * pow($growth, $level) + ($level * $level * 12));
}

function get_blessing_reroll_cost($reroll_count, $floor = 1) {
	$safe_reroll = max(0, (int)$reroll_count);
	$safe_floor = max(1, (int)$floor);
	return (int)floor(700 + ($safe_reroll * 420) + ($safe_floor * 8));
}

function apply_reward_bonus_by_modifiers($gold_gain, $exp_gain, $mods) {
	$gold = max(0, (int)$gold_gain);
	$exp = max(0, (int)$exp_gain);
	$gold_bonus_pct = max(0, (int)(isset($mods['reward_gold_bonus_pct']) ? $mods['reward_gold_bonus_pct'] : 0));
	$exp_bonus_pct = max(0, (int)(isset($mods['reward_exp_bonus_pct']) ? $mods['reward_exp_bonus_pct'] : 0));

	if ($gold > 0 && $gold_bonus_pct > 0) {
		$gold = (int)floor($gold * (1 + ($gold_bonus_pct / 100.0)));
	}
	if ($exp > 0 && $exp_bonus_pct > 0) {
		$exp = (int)floor($exp * (1 + ($exp_bonus_pct / 100.0)));
	}

	return array('gold' => $gold, 'exp' => $exp, 'gold_bonus_pct' => $gold_bonus_pct, 'exp_bonus_pct' => $exp_bonus_pct);
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
		'exp' => rand($exp_min, $exp_max) * (8 + (int)floor($safe_floor / 4)),
		'is_boss' => $is_boss
	);
}

function apply_commander_rewards(PDO $pdo, $uid, $cmd_state, $gold_gain, $exp_gain, $floor = 0) {
	$gold_gain = max(0, (int)$gold_gain);
	$exp_gain = max(0, (int)$exp_gain);
	$base_gold = isset($cmd_state['gold']) ? (int)$cmd_state['gold'] : 0;

	if ($gold_gain > 0) {
		add_commander_gold($pdo, $uid, $gold_gain, true);
	}

	if ($exp_gain > 0) {
		if ($floor > 0) {
			$ovr = max(0, (int)(isset($cmd_state['level']) ? $cmd_state['level'] : 1) - (int)$floor);
			if ($ovr >= 5) {
				$exp_gain = (int)floor($exp_gain * 0.5);
			}
		}
		$exp_gain = (int)floor($exp_gain * 0.5);
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

function get_item_system_catalog() {
	static $catalog = null;
	if ($catalog !== null) return $catalog;

	$catalog = array(
		'artifacts' => array(
			'artifact_poison_eye' => array(
				'name' => '독 두꺼비의 눈알',
				'grade' => '전설',
				'description' => "파티 전체 '중독' 계열 피해 +30%",
				'effects' => array('poison_damage_pct' => 30)
			),
			'artifact_clover' => array(
				'name' => '행운의 네잎클로버',
				'grade' => '희귀',
				'description' => '탐색 중 함정 회피 확률 +15%',
				'effects' => array('trap_evade_bonus_pct' => 15)
			),
			'artifact_greed_jar' => array(
				'name' => '탐욕의 항아리',
				'grade' => '신화',
				'description' => '전투 승리 골드 +20%, 획득 시 최대 HP 10% 감소',
				'effects' => array('battle_gold_bonus_pct' => 20, 'max_hp_penalty_on_gain_pct' => 10)
			)
		),
		'consumables' => array(
			'cons_flashbang' => array(
				'name' => '섬광탄',
				'grade' => '영웅',
				'description' => '보스전에서 사용 시 적의 턴을 1회 스킵',
				'shop_cost' => 220,
				'shop_buyable' => true
			),
			'cons_escape_scroll' => array(
				'name' => '비상 탈출 스크롤',
				'grade' => '희귀',
				'description' => '전투 중 즉시 도망쳐 이전 층으로 후퇴',
				'shop_cost' => 180,
				'shop_buyable' => true
			),
			'cons_mana_potion' => array(
				'name' => '고농축 마나 물약',
				'grade' => '희귀',
				'description' => '사령관 MP 즉시 50% 회복',
				'shop_cost' => 130,
				'shop_buyable' => true
			),
			'cons_mythic_ticket' => array(
				'name' => '신화 소환 티켓',
				'grade' => '전설',
				'description' => '사용 시 신화 등급 영웅 1명을 즉시 소환',
				'shop_cost' => 0,
				'shop_buyable' => false
			)
		),
		'equipment_bases' => array(
			array('code' => 'eq_iron_sword', 'name' => '철검', 'slot' => 'weapon', 'grade' => '일반', 'stats' => array('attack_pct' => 6)),
			array('code' => 'eq_leather_armor', 'name' => '가죽 갑옷', 'slot' => 'armor', 'grade' => '일반', 'stats' => array('damage_reduction_pct' => 4)),
			array('code' => 'eq_bronze_ring', 'name' => '청동 반지', 'slot' => 'accessory', 'grade' => '일반', 'stats' => array('crit_chance' => 3)),
			array('code' => 'eq_knight_blade', 'name' => '기사검', 'slot' => 'weapon', 'grade' => '희귀', 'stats' => array('attack_pct' => 10)),
			array('code' => 'eq_guardian_armor', 'name' => '수호자 갑옷', 'slot' => 'armor', 'grade' => '희귀', 'stats' => array('damage_reduction_pct' => 8)),
			array('code' => 'eq_eagle_pendant', 'name' => '독수리 펜던트', 'slot' => 'accessory', 'grade' => '희귀', 'stats' => array('crit_chance' => 6)),
			array('code' => 'eq_dragonslayer', 'name' => '용살검', 'slot' => 'weapon', 'grade' => '영웅', 'stats' => array('attack_pct' => 15)),
			array('code' => 'eq_titan_plate', 'name' => '타이탄 플레이트', 'slot' => 'armor', 'grade' => '영웅', 'stats' => array('damage_reduction_pct' => 12)),
			array('code' => 'eq_storm_amulet', 'name' => '폭풍 부적', 'slot' => 'accessory', 'grade' => '영웅', 'stats' => array('crit_chance' => 10, 'double_attack_bonus' => 5)),
			array('code' => 'eq_ancient_relic_blade', 'name' => '고대 유물 검', 'slot' => 'weapon', 'grade' => '전설', 'stats' => array('attack_pct' => 22, 'lifesteal_pct' => 1)),
			array('code' => 'eq_immortal_armor', 'name' => '불멸의 갑주', 'slot' => 'armor', 'grade' => '전설', 'stats' => array('damage_reduction_pct' => 17)),
			array('code' => 'eq_fate_necklace', 'name' => '운명의 목걸이', 'slot' => 'accessory', 'grade' => '전설', 'stats' => array('crit_chance' => 14, 'double_attack_bonus' => 8))
		),
		'equipment_prefixes' => array(
			array('name' => '흡혈의', 'stats' => array('lifesteal_pct' => 1)),
			array('name' => '신속의', 'stats' => array('double_attack_bonus' => 6)),
			array('name' => '강인한', 'stats' => array('damage_reduction_pct' => 3)),
			array('name' => '집중의', 'stats' => array('crit_chance' => 4)),
			array('name' => '맹렬한', 'stats' => array('attack_pct' => 4))
		),
		'grade_order' => array('일반' => 1, '희귀' => 2, '영웅' => 3, '전설' => 4, '신화' => 5),
		'synthesis_chain' => array('일반' => '희귀', '희귀' => '영웅', '영웅' => '전설')
	);

	return $catalog;
}

function item_stat_add(&$base, $key, $value) {
	if (!isset($base[$key])) $base[$key] = 0;
	$base[$key] += (float)$value;
}

function merge_item_stats($base_stats, $extra_stats) {
	$out = is_array($base_stats) ? $base_stats : array();
	if (!is_array($extra_stats)) return $out;
	foreach ($extra_stats as $k => $v) {
		if (!is_numeric($v)) continue;
		item_stat_add($out, (string)$k, (float)$v);
	}
	return $out;
}

function decode_item_stats($stats_json) {
	if ($stats_json === null || $stats_json === '') return array();
	$decoded = json_decode((string)$stats_json, true);
	return is_array($decoded) ? $decoded : array();
}

function get_user_items(PDO $pdo, $uid) {
	$st = $pdo->prepare("SELECT * FROM tb_items WHERE uid = ? ORDER BY category ASC, is_equipped DESC, item_grade DESC, item_id DESC");
	$st->execute(array($uid));
	return $st->fetchAll();
}

function get_item_effect_text($stats) {
	if (!is_array($stats) || empty($stats)) return '효과 없음';
	$parts = array();
	if (!empty($stats['attack_pct'])) $parts[] = '공격력 +' . (int)$stats['attack_pct'] . '%';
	if (!empty($stats['damage_reduction_pct'])) $parts[] = '받는 피해 -' . (int)$stats['damage_reduction_pct'] . '%';
	if (!empty($stats['crit_chance'])) $parts[] = '치명타 확률 +' . (int)$stats['crit_chance'] . '%';
	if (!empty($stats['double_attack_bonus'])) $parts[] = '연속 공격 +' . (int)$stats['double_attack_bonus'] . '%p';
	if (!empty($stats['lifesteal_pct'])) $parts[] = '타격 시 HP 흡수 ' . (int)$stats['lifesteal_pct'] . '%';
	if (!empty($stats['poison_damage_pct'])) $parts[] = '중독 계열 피해 +' . (int)$stats['poison_damage_pct'] . '%';
	if (!empty($stats['trap_evade_bonus_pct'])) $parts[] = '함정 회피 +' . (int)$stats['trap_evade_bonus_pct'] . '%';
	if (!empty($stats['battle_gold_bonus_pct'])) $parts[] = '전투 골드 +' . (int)$stats['battle_gold_bonus_pct'] . '%';
	if (empty($parts)) return '효과 없음';
	return implode(', ', $parts);
}

function get_slot_label($slot) {
	$map = array('weapon' => '무기', 'armor' => '방어구', 'accessory' => '장신구');
	return isset($map[$slot]) ? $map[$slot] : '기타';
}

function get_user_artifact_codes(PDO $pdo, $uid) {
	$st = $pdo->prepare("SELECT item_code FROM tb_items WHERE uid = ? AND category = 'artifact'");
	$st->execute(array($uid));
	$codes = array();
	foreach ($st->fetchAll() as $r) {
		$code = isset($r['item_code']) ? (string)$r['item_code'] : '';
		if ($code !== '') $codes[$code] = true;
	}
	return $codes;
}

function get_user_artifact_effects(PDO $pdo, $uid) {
	$catalog = get_item_system_catalog();
	$defs = $catalog['artifacts'];
	$owned = get_user_artifact_codes($pdo, $uid);
	$effects = array(
		'poison_damage_pct' => 0,
		'trap_evade_bonus_pct' => 0,
		'battle_gold_bonus_pct' => 0
	);
	foreach ($owned as $code => $_unused) {
		if (!isset($defs[$code])) continue;
		$ef = isset($defs[$code]['effects']) && is_array($defs[$code]['effects']) ? $defs[$code]['effects'] : array();
		foreach ($ef as $k => $v) {
			if (!isset($effects[$k])) $effects[$k] = 0;
			if (is_numeric($v)) $effects[$k] += (int)$v;
		}
	}
	return $effects;
}

function get_user_equipment_effects(PDO $pdo, $uid) {
	$st = $pdo->prepare("SELECT stats_json FROM tb_items WHERE uid = ? AND category = 'equipment' AND is_equipped = 1");
	$st->execute(array($uid));
	$effects = array(
		'attack_pct' => 0,
		'damage_reduction_pct' => 0,
		'crit_chance' => 0,
		'double_attack_bonus' => 0,
		'lifesteal_pct' => 0
	);
	foreach ($st->fetchAll() as $row) {
		$stats = decode_item_stats(isset($row['stats_json']) ? $row['stats_json'] : '');
		foreach ($effects as $k => $_v) {
			if (isset($stats[$k]) && is_numeric($stats[$k])) {
				$effects[$k] += (int)$stats[$k];
			}
		}
	}
	return $effects;
}

function get_equipment_comparison_stat_keys() {
	return array(
		'attack_pct' => '공격력',
		'damage_reduction_pct' => '받는 피해 감소',
		'crit_chance' => '치명타 확률',
		'double_attack_bonus' => '연속 공격',
		'lifesteal_pct' => '흡혈'
	);
}

function format_equipment_stat_value($stat_key, $value) {
	$int_v = (int)round((float)$value);
	$suffix = ($stat_key === 'double_attack_bonus') ? '%p' : '%';
	if ($stat_key === 'lifesteal_pct') {
		return $int_v . '%';
	}
	return $int_v . $suffix;
}

function normalize_equipment_effect_set($effects) {
	$keys = get_equipment_comparison_stat_keys();
	$out = array();
	foreach ($keys as $k => $_label) {
		$out[$k] = isset($effects[$k]) ? (int)$effects[$k] : 0;
	}
	return $out;
}

function apply_equipment_effect_delta($effects, $delta_stats, $direction = 1) {
	$out = normalize_equipment_effect_set($effects);
	if (!is_array($delta_stats)) return $out;
	$mul = ($direction >= 0) ? 1 : -1;
	foreach ($out as $k => $cur) {
		if (!isset($delta_stats[$k]) || !is_numeric($delta_stats[$k])) continue;
		$out[$k] = (int)$cur + ((int)$delta_stats[$k] * $mul);
	}
	return $out;
}

function build_equipment_compare_tooltip($before_effects, $after_effects, $headline = '장착 후 예상') {
	$keys = get_equipment_comparison_stat_keys();
	$before = normalize_equipment_effect_set($before_effects);
	$after = normalize_equipment_effect_set($after_effects);
	$lines = array((string)$headline);
	$changed = false;

	foreach ($keys as $k => $label) {
		$from = (int)$before[$k];
		$to = (int)$after[$k];
		if ($from === $to) continue;
		$changed = true;
		$delta = $to - $from;
		$delta_prefix = ($delta > 0) ? '+' : '';
		$lines[] = $label . ': ' . format_equipment_stat_value($k, $from) . ' -> ' . format_equipment_stat_value($k, $to) . ' (' . $delta_prefix . format_equipment_stat_value($k, $delta) . ')';
	}

	if (!$changed) {
		$lines[] = '스탯 변화 없음';
	}

	return implode("\n", $lines);
}

function grant_consumable_item(PDO $pdo, $uid, $item_code, $quantity = 1) {
	$quantity = max(1, (int)$quantity);
	$catalog = get_item_system_catalog();
	if (!isset($catalog['consumables'][$item_code])) return false;
	$def = $catalog['consumables'][$item_code];

	$st = $pdo->prepare("SELECT item_id, quantity FROM tb_items WHERE uid = ? AND category = 'consumable' AND item_code = ? LIMIT 1 FOR UPDATE");
	$st->execute(array($uid, $item_code));
	$row = $st->fetch();
	if ($row) {
		$pdo->prepare("UPDATE tb_items SET quantity = quantity + ? WHERE item_id = ?")->execute(array($quantity, (int)$row['item_id']));
	} else {
		$pdo->prepare("INSERT INTO tb_items (uid, item_code, category, item_name, item_grade, quantity) VALUES (?, ?, 'consumable', ?, ?, ?)")
			->execute(array($uid, $item_code, $def['name'], $def['grade'], $quantity));
	}
	return true;
}

function roll_equipment_by_grade($target_grade = '', $floor = 1) {
	$catalog = get_item_system_catalog();
	$bases = $catalog['equipment_bases'];
	$prefixes = $catalog['equipment_prefixes'];
	$target = trim((string)$target_grade);

	$grade_roll = array('일반' => 65, '희귀' => 25, '영웅' => 8, '전설' => 2);
	if ((int)$floor >= 80) {
		$grade_roll = array('일반' => 45, '희귀' => 32, '영웅' => 16, '전설' => 7);
	} elseif ((int)$floor >= 40) {
		$grade_roll = array('일반' => 55, '희귀' => 30, '영웅' => 11, '전설' => 4);
	}

	if ($target === '') {
		$roll = rand(1, 100);
		$acc = 0;
		foreach ($grade_roll as $grade => $w) {
			$acc += (int)$w;
			if ($roll <= $acc) {
				$target = $grade;
				break;
			}
		}
		if ($target === '') $target = '일반';
	}

	$candidates = array();
	foreach ($bases as $base) {
		if ((string)$base['grade'] === $target) $candidates[] = $base;
	}
	if (empty($candidates)) {
		foreach ($bases as $base) {
			if ((string)$base['grade'] === '일반') $candidates[] = $base;
		}
	}
	if (empty($candidates)) return null;

	$base = $candidates[array_rand($candidates)];
	$prefix = null;
	if (!empty($prefixes) && rand(1, 100) <= 45) {
		$prefix = $prefixes[array_rand($prefixes)];
	}

	$stats = isset($base['stats']) && is_array($base['stats']) ? $base['stats'] : array();
	$prefix_name = null;
	if ($prefix) {
		$stats = merge_item_stats($stats, isset($prefix['stats']) ? $prefix['stats'] : array());
		$prefix_name = isset($prefix['name']) ? (string)$prefix['name'] : null;
	}

	$item_name = ($prefix_name !== null && $prefix_name !== '') ? ('[' . $prefix_name . '] ' . $base['name']) : $base['name'];
	return array(
		'item_code' => (string)$base['code'],
		'item_name' => (string)$item_name,
		'grade' => (string)$base['grade'],
		'slot' => (string)$base['slot'],
		'prefix_name' => $prefix_name,
		'stats' => $stats
	);
}

function grant_equipment_item(PDO $pdo, $uid, $equipment_info) {
	if (!is_array($equipment_info) || !isset($equipment_info['item_code'])) return false;
	$stats_json = json_encode(isset($equipment_info['stats']) ? $equipment_info['stats'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$pdo->prepare("INSERT INTO tb_items (uid, item_code, category, item_name, item_grade, quantity, slot_type, prefix_name, stats_json, is_equipped) VALUES (?, ?, 'equipment', ?, ?, 1, ?, ?, ?, 0)")
		->execute(array(
			$uid,
			(string)$equipment_info['item_code'],
			(string)$equipment_info['item_name'],
			(string)$equipment_info['grade'],
			(string)$equipment_info['slot'],
			isset($equipment_info['prefix_name']) ? (string)$equipment_info['prefix_name'] : null,
			$stats_json
		));
	return true;
}

function grant_artifact_item(PDO $pdo, $uid, $artifact_code) {
	$catalog = get_item_system_catalog();
	if (!isset($catalog['artifacts'][$artifact_code])) return array('granted' => false);
	$def = $catalog['artifacts'][$artifact_code];

	$chk = $pdo->prepare("SELECT item_id FROM tb_items WHERE uid = ? AND category = 'artifact' AND item_code = ? LIMIT 1 FOR UPDATE");
	$chk->execute(array($uid, $artifact_code));
	$exists = $chk->fetch();
	if ($exists) return array('granted' => false, 'already_owned' => true, 'name' => $def['name']);

	$effects_json = json_encode(isset($def['effects']) ? $def['effects'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$pdo->prepare("INSERT INTO tb_items (uid, item_code, category, item_name, item_grade, quantity, stats_json, is_equipped) VALUES (?, ?, 'artifact', ?, ?, 1, ?, 1)")
		->execute(array($uid, $artifact_code, $def['name'], $def['grade'], $effects_json));

	$result = array('granted' => true, 'name' => $def['name']);
	if ($artifact_code === 'artifact_greed_jar') {
		$cmd_st = $pdo->prepare("SELECT hp, max_hp FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd_st->execute(array($uid));
		$cmd = $cmd_st->fetch();
		if ($cmd) {
			$new_max_hp = max(1, (int)floor((int)$cmd['max_hp'] * 0.9));
			$new_hp = min($new_max_hp, (int)$cmd['hp']);
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, max_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_max_hp, $uid));
			$result['hp_adjusted'] = true;
			$result['new_hp'] = $new_hp;
			$result['new_max_hp'] = $new_max_hp;
		}
	}

	return $result;
}

function pick_random_unowned_artifact_code(PDO $pdo, $uid, $preferred_grades = array()) {
	$catalog = get_item_system_catalog();
	$artifacts = isset($catalog['artifacts']) && is_array($catalog['artifacts']) ? $catalog['artifacts'] : array();
	if (empty($artifacts)) return '';
	$owned = get_user_artifact_codes($pdo, $uid);
	$preferred = array();
	$fallback = array();
	$grade_filter = array();
	if (is_array($preferred_grades)) {
		foreach ($preferred_grades as $gr) {
			$g = trim((string)$gr);
			if ($g !== '') $grade_filter[$g] = true;
		}
	}
	foreach ($artifacts as $code => $def) {
		if (isset($owned[$code])) continue;
		$grade = isset($def['grade']) ? (string)$def['grade'] : '';
		if (!empty($grade_filter) && isset($grade_filter[$grade])) {
			$preferred[] = $code;
		}
		$fallback[] = $code;
	}
	if (!empty($preferred)) {
		return (string)$preferred[array_rand($preferred)];
	}
	if (!empty($fallback)) {
		return (string)$fallback[array_rand($fallback)];
	}
	return '';
}

function fetch_item_drop_rows(PDO $pdo, $source_key, $floor) {
	$st = $pdo->prepare("SELECT drop_id, source_key, reward_type, item_code, target_grade, weight FROM tb_item_drops WHERE source_key = ? AND is_enabled = 1 AND ? BETWEEN min_floor AND max_floor");
	$st->execute(array((string)$source_key, max(1, (int)$floor)));
	return $st->fetchAll();
}

function pick_weighted_drop_row($rows, $drop_bonus_pct = 0) {
	if (!is_array($rows) || empty($rows)) return null;
	$drop_bonus = max(0, (int)$drop_bonus_pct);
	$total_weight = 0;
	$weighted_rows = array();

	foreach ($rows as $row) {
		$base_weight = max(1, (int)(isset($row['weight']) ? $row['weight'] : 1));
		$type = isset($row['reward_type']) ? (string)$row['reward_type'] : 'none';
		if ($drop_bonus > 0) {
			if ($type === 'none') {
				$base_weight = max(1, (int)floor($base_weight * max(0.20, 1 - (min(85, $drop_bonus) / 100.0))));
			} else {
				$base_weight = max(1, (int)floor($base_weight * (1 + (min(180, $drop_bonus) / 100.0))));
			}
		}
		$total_weight += $base_weight;
		$weighted_rows[] = array('row' => $row, 'weight' => $base_weight);
	}

	if ($total_weight <= 0) return null;
	$roll = rand(1, $total_weight);
	$acc = 0;
	foreach ($weighted_rows as $item) {
		$acc += (int)$item['weight'];
		if ($roll <= $acc) {
			return $item['row'];
		}
	}

	return isset($weighted_rows[0]['row']) ? $weighted_rows[0]['row'] : null;
}

function resolve_item_drop_row(PDO $pdo, $uid, $row, $floor) {
	$catalog = get_item_system_catalog();
	$reward_type = isset($row['reward_type']) ? (string)$row['reward_type'] : 'none';
	$item_code = isset($row['item_code']) ? trim((string)$row['item_code']) : '';
	$target_grade = isset($row['target_grade']) ? trim((string)$row['target_grade']) : '';
	$safe_floor = max(1, (int)$floor);

	if ($reward_type === 'none') {
		return array('dropped' => false, 'is_none' => true);
	}

	if ($reward_type === 'consumable') {
		if ($item_code === '') {
			$cons_codes = array_keys(isset($catalog['consumables']) ? $catalog['consumables'] : array());
			if (empty($cons_codes)) return array('dropped' => false);
			$item_code = (string)$cons_codes[array_rand($cons_codes)];
		}
		if (!isset($catalog['consumables'][$item_code])) return array('dropped' => false);
		if (!grant_consumable_item($pdo, $uid, $item_code, 1)) return array('dropped' => false);
		$name = $catalog['consumables'][$item_code]['name'];
		return array('dropped' => true, 'log' => "🎒 <b>[소모품 획득]</b> {$name} x1", 'reward_type' => 'consumable');
	}

	if ($reward_type === 'equipment') {
		$eq = roll_equipment_by_grade($target_grade, $safe_floor);
		if (!$eq) return array('dropped' => false);
		grant_equipment_item($pdo, $uid, $eq);
		return array('dropped' => true, 'log' => "🛡️ <b>[장비 획득]</b> <span style='color:#90caf9;'>{$eq['item_name']}</span>", 'reward_type' => 'equipment');
	}

	if ($reward_type === 'artifact') {
		if ($item_code === '' || strtoupper($item_code) === 'RANDOM_UNOWNED') {
			$item_code = pick_random_unowned_artifact_code($pdo, $uid);
		}
		if ($item_code === '') return array('dropped' => false);
		$res = grant_artifact_item($pdo, $uid, $item_code);
		if (empty($res['granted'])) {
			return array('dropped' => false);
		}
		$msg = "🗿 <b>[유물 획득]</b> <span style='color:#ffd54f;'>{$res['name']}</span>";
		if (!empty($res['hp_adjusted'])) {
			$msg .= " (탐욕의 대가로 최대 HP가 감소했습니다.)";
		}
		return array('dropped' => true, 'log' => $msg, 'artifact_result' => $res, 'reward_type' => 'artifact');
	}

	return array('dropped' => false);
}

function grant_item_drop_from_source(PDO $pdo, $uid, $source_key, $floor) {
	$rows = fetch_item_drop_rows($pdo, $source_key, $floor);
	if (empty($rows)) return array('dropped' => false);

	$mods = get_runtime_modifier_bundle($pdo, $uid);
	$drop_bonus_pct = max(0, (int)(isset($mods['drop_bonus_pct']) ? $mods['drop_bonus_pct'] : 0));
	$attempts = max(1, min(10, count($rows) * 2));

	for ($i = 0; $i < $attempts; $i++) {
		$picked = pick_weighted_drop_row($rows, $drop_bonus_pct);
		if (!$picked) break;
		$result = resolve_item_drop_row($pdo, $uid, $picked, $floor);
		if (!empty($result['dropped'])) return $result;
		if (!empty($result['is_none'])) {
			return array('dropped' => false);
		}
	}

	if ((string)$source_key === 'battle_boss') {
		$fallback_grade = ((int)$floor >= 80) ? '전설' : (((int)$floor >= 40) ? '영웅' : '희귀');
		$eq = roll_equipment_by_grade($fallback_grade, $floor);
		if ($eq) {
			grant_equipment_item($pdo, $uid, $eq);
			return array('dropped' => true, 'log' => "🛡️ <b>[장비 획득]</b> <span style='color:#90caf9;'>{$eq['item_name']}</span>");
		}
	}

	return array('dropped' => false);
}

function grant_battle_item_drop(PDO $pdo, $uid, $floor, $mob_name) {
	$is_boss = (strpos((string)$mob_name, '[보스]') !== false);
	$source_key = $is_boss ? 'battle_boss' : 'battle_normal';
	return grant_item_drop_from_source($pdo, $uid, $source_key, max(1, (int)$floor));
}

function render_item_bag_html(PDO $pdo, $uid, $gold) {
	$catalog = get_item_system_catalog();
	$items = get_user_items($pdo, $uid);
	$artifacts = $catalog['artifacts'];
	$consumables = $catalog['consumables'];
	$grade_order = $catalog['grade_order'];

	$artifact_by_code = array();
	$consumable_by_code = array();
	$equipment_rows = array();
	foreach ($items as $it) {
		$cat = isset($it['category']) ? (string)$it['category'] : '';
		if ($cat === 'artifact') {
			$artifact_by_code[(string)$it['item_code']] = $it;
		} elseif ($cat === 'consumable') {
			$consumable_by_code[(string)$it['item_code']] = $it;
		} elseif ($cat === 'equipment') {
			$equipment_rows[] = $it;
		}
	}

	usort($equipment_rows, function($a, $b) use ($grade_order) {
		$ga = isset($grade_order[$a['item_grade']]) ? (int)$grade_order[$a['item_grade']] : 0;
		$gb = isset($grade_order[$b['item_grade']]) ? (int)$grade_order[$b['item_grade']] : 0;
		if ($ga !== $gb) return ($gb - $ga);
		if ((int)$a['is_equipped'] !== (int)$b['is_equipped']) return ((int)$b['is_equipped'] - (int)$a['is_equipped']);
		return ((int)$b['item_id'] - (int)$a['item_id']);
	});

	$artifact_effects = get_user_artifact_effects($pdo, $uid);
	$equip_effects = get_user_equipment_effects($pdo, $uid);
	$equipped_by_slot = array();
	foreach ($equipment_rows as $eq_row) {
		if ((int)$eq_row['is_equipped'] !== 1) continue;
		$slot_key = isset($eq_row['slot_type']) ? (string)$eq_row['slot_type'] : '';
		if ($slot_key === '') continue;
		if (!isset($equipped_by_slot[$slot_key])) {
			$equipped_by_slot[$slot_key] = $eq_row;
		}
	}

	$html = "<div style='display:grid; gap:10px;'>";
	$html .= "<div style='background:#1f1f1f; border:1px solid #3b3b3b; border-radius:6px; padding:10px;'>";
	$html .= "<div style='display:flex; justify-content:space-between; align-items:center; gap:8px;'><b style='color:#ffd54f;'>🎒 아이템 가방</b><span style='color:#ffecb3;'>보유 골드: <b>" . number_format((int)$gold) . "G</b></span></div>";
	$html .= "<div style='margin-top:6px; color:#9e9e9e; font-size:0.82rem;'>소모품은 수동 조작 시에만 사용되며 자동 전투 AI는 사용하지 않습니다.</div>";
	$html .= "</div>";

	$html .= "<div style='background:#222; border-radius:6px; padding:10px; border:1px solid #3a3a3a;'>";
	$html .= "<h3 style='margin:0 0 6px 0; color:#ffb74d;'>🗿 유물 (Artifacts)</h3>";
	$html .= "<div style='font-size:0.8rem; color:#bdbdbd; margin-bottom:8px;'>활성 효과: " . htmlspecialchars(get_item_effect_text($artifact_effects), ENT_QUOTES, 'UTF-8') . "</div>";
	foreach ($artifacts as $code => $def) {
		$owned = isset($artifact_by_code[$code]);
		$status = $owned ? "<span style='color:#66bb6a;'>[보유 중]</span>" : "<span style='color:#90a4ae;'>[미보유]</span>";
		$html .= "<div style='background:#1a1a1a; border:1px solid #333; border-radius:5px; padding:8px; margin-bottom:6px;'>";
		$html .= "<div><b style='color:#ffd54f;'>" . htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8') . "</b> {$status}</div>";
		$html .= "<div style='font-size:0.82rem; color:#cfd8dc; margin-top:4px;'>" . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . "</div>";
		$html .= "</div>";
	}
	$html .= "<div style='font-size:0.76rem; color:#90a4ae;'>획득처: 10층 단위 보스 확정 드랍</div>";
	$html .= "</div>";

	$html .= "<div style='background:#222; border-radius:6px; padding:10px; border:1px solid #3a3a3a;'>";
	$html .= "<h3 style='margin:0 0 6px 0; color:#80cbc4;'>🧪 소모품 (Consumables)</h3>";
	foreach ($consumables as $code => $def) {
		$row = isset($consumable_by_code[$code]) ? $consumable_by_code[$code] : null;
		$qty = $row ? max(0, (int)$row['quantity']) : 0;
		$item_id = $row ? (int)$row['item_id'] : 0;
		$is_shop_buyable = !isset($def['shop_buyable']) || (bool)$def['shop_buyable'];
		$use_btn = ($qty > 0)
			? "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#26a69a;' onclick='useItem({$item_id})'>사용</button>"
			: "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#555;' disabled>사용</button>";
		if ($is_shop_buyable) {
			$buy_btn = "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#546e7a;' onclick=\"buyItem('{$code}')\">구매(" . number_format((int)$def['shop_cost']) . "G)</button>";
		} else {
			$buy_btn = "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#555;' disabled>상점 미판매</button>";
		}
		$html .= "<div style='background:#1a1a1a; border:1px solid #333; border-radius:5px; padding:8px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center; gap:8px;'>";
		$html .= "<div><div><b style='color:#b2dfdb;'>" . htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8') . "</b> x{$qty}</div><div style='font-size:0.8rem; color:#b0bec5; margin-top:2px;'>" . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . "</div></div>";
		$html .= "<div style='display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;'>{$use_btn}{$buy_btn}</div>";
		$html .= "</div>";
	}
	$html .= "</div>";

	$grade_counts = array('일반' => 0, '희귀' => 0, '영웅' => 0);
	foreach ($equipment_rows as $eq) {
		if ((int)$eq['is_equipped'] === 1) continue;
		$gr = isset($eq['item_grade']) ? (string)$eq['item_grade'] : '일반';
		if (isset($grade_counts[$gr])) $grade_counts[$gr] += 1;
	}

	$html .= "<div style='background:#222; border-radius:6px; padding:10px; border:1px solid #3a3a3a;'>";
	$html .= "<h3 style='margin:0 0 6px 0; color:#90caf9;'>🛡️ 장비 (Equipment)</h3>";
	$html .= "<div style='font-size:0.8rem; color:#bdbdbd; margin-bottom:8px;'>적용 효과: " . htmlspecialchars(get_item_effect_text($equip_effects), ENT_QUOTES, 'UTF-8') . "</div>";
	if (empty($equipment_rows)) {
		$html .= "<div style='color:#90a4ae;'>보유한 장비가 없습니다.</div>";
	} else {
		foreach ($equipment_rows as $eq) {
			$item_id = (int)$eq['item_id'];
			$is_equipped = ((int)$eq['is_equipped'] === 1);
			$stats = decode_item_stats(isset($eq['stats_json']) ? $eq['stats_json'] : '');
			$slot_type = isset($eq['slot_type']) ? (string)$eq['slot_type'] : '';
			$before_effects = normalize_equipment_effect_set($equip_effects);
			$after_effects = $before_effects;
			$tooltip_title = $is_equipped ? '해제 후 예상' : '장착 후 예상';
			if ($is_equipped) {
				$after_effects = apply_equipment_effect_delta($after_effects, $stats, -1);
			} else {
				if ($slot_type !== '' && isset($equipped_by_slot[$slot_type])) {
					$equipped_row = $equipped_by_slot[$slot_type];
					if ((int)$equipped_row['item_id'] !== $item_id) {
						$equipped_stats = decode_item_stats(isset($equipped_row['stats_json']) ? $equipped_row['stats_json'] : '');
						$after_effects = apply_equipment_effect_delta($after_effects, $equipped_stats, -1);
					}
				}
				$after_effects = apply_equipment_effect_delta($after_effects, $stats, 1);
			}
			$compare_tooltip_text = build_equipment_compare_tooltip($before_effects, $after_effects, $tooltip_title);
			$compare_tooltip_attr = escape_attr_text($compare_tooltip_text);
			$btn = $is_equipped
				? "<button class='btn' data-tooltip='{$compare_tooltip_attr}' style='padding:6px 10px; font-size:0.8rem; background:#8d6e63;' onclick='toggleEquipItem({$item_id}, -1)'>해제</button>"
				: "<button class='btn' data-tooltip='{$compare_tooltip_attr}' style='padding:6px 10px; font-size:0.8rem; background:#42a5f5;' onclick='toggleEquipItem({$item_id}, 1)'>장착</button>";
			$state = $is_equipped ? "<span style='color:#81c784;'>[장착 중]</span>" : "<span style='color:#90a4ae;'>[대기]</span>";
			$html .= "<div title='{$compare_tooltip_attr}' style='background:#1a1a1a; border:1px solid #333; border-radius:5px; padding:8px; margin-bottom:6px; display:flex; justify-content:space-between; gap:8px; align-items:center;'>";
			$html .= "<div title='{$compare_tooltip_attr}'><div><b style='color:#90caf9;'>" . htmlspecialchars((string)$eq['item_name'], ENT_QUOTES, 'UTF-8') . "</b> {$state}</div>";
			$html .= "<div style='font-size:0.8rem; color:#b0bec5; margin-top:2px;'>등급: " . htmlspecialchars((string)$eq['item_grade'], ENT_QUOTES, 'UTF-8') . " | 슬롯: " . htmlspecialchars(get_slot_label((string)$eq['slot_type']), ENT_QUOTES, 'UTF-8') . "</div>";
			$html .= "<div style='font-size:0.8rem; color:#c5e1a5; margin-top:2px;'>" . htmlspecialchars(get_item_effect_text($stats), ENT_QUOTES, 'UTF-8') . "</div></div>";
			$html .= $btn;
			$html .= "</div>";
		}
	}

	$can_common = ($grade_counts['일반'] >= 3) ? '' : 'disabled';
	$can_rare = ($grade_counts['희귀'] >= 3) ? '' : 'disabled';
	$can_epic = ($grade_counts['영웅'] >= 3) ? '' : 'disabled';
	$html .= "<div style='margin-top:8px; padding-top:8px; border-top:1px dashed #444;'>";
	$html .= "<div style='font-size:0.8rem; color:#b0bec5; margin-bottom:6px;'>동일 등급 장비 3개 합성 -> 상위 등급 1개 무작위 획득</div>";
	$html .= "<div style='display:flex; gap:6px; flex-wrap:wrap;'>";
	$html .= "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#455a64;' {$can_common} onclick=\"synthesizeEquipment('일반')\">일반 3합성 (" . (int)$grade_counts['일반'] . "/3)</button>";
	$html .= "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#5d4037;' {$can_rare} onclick=\"synthesizeEquipment('희귀')\">희귀 3합성 (" . (int)$grade_counts['희귀'] . "/3)</button>";
	$html .= "<button class='btn' style='padding:6px 10px; font-size:0.8rem; background:#6a1b9a;' {$can_epic} onclick=\"synthesizeEquipment('영웅')\">영웅 3합성 (" . (int)$grade_counts['영웅'] . "/3)</button>";
	$html .= "</div></div>";
	$html .= "</div>";

	$html .= "</div>";
	return $html;
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
	$traits = get_hero_traits($name);

	$lines = array(
		"{$name} [{$rank}]",
		"레벨: {$level}",
		"보유 수량: {$quantity}",
		"공격 타입: {$traits['attack_type']}",
		"사거리: {$traits['attack_range']}",
		"종족: {$traits['race']}"
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

function generate_hero_lists($heroes, $current_floor = 1) {
	$deck_html = '';
	$inv_html = '';
	$deck_count = 0;
	$deck_synergy_html = render_deck_synergy_html(build_deck_synergy_summary($heroes, false, $current_floor));

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
	return array($deck_html, $inv_html, $deck_count, $deck_synergy_html);
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
	$artifact_poison_bonus_pct = 0;
	if (isset($_SESSION['combat_state']) && is_array($_SESSION['combat_state']) && isset($_SESSION['combat_state']['artifact_poison_bonus_pct'])) {
		$artifact_poison_bonus_pct = max(0, (int)$_SESSION['combat_state']['artifact_poison_bonus_pct']);
	}
	$is_poison_skill = (strpos((string)$skill_name, '독') !== false || strpos((string)$skill_name, '중독') !== false || strpos((string)$skill_name, '맹독') !== false);

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
				if ($artifact_poison_bonus_pct > 0 && $is_poison_skill) {
					$extra = (int)floor($extra * (1 + ($artifact_poison_bonus_pct / 100.0)));
					$logs[] = "☠️ <span style='color:#aed581;'>[독 유물 공명]</span> {$skill_name} 피해 +{$artifact_poison_bonus_pct}%";
				}
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

function normalize_disposition_value($value) {
	return max(0, min(100, (int)$value));
}

function get_disposition_combat_modifiers($value) {
	$disp = normalize_disposition_value($value);
	$mods = array(
		'crit_chance_bonus' => 0,
		'crit_damage_bonus_pct' => 0,
		'evasion_bonus' => 0,
		'block_bonus' => 0,
		'status_resist_pct' => 0,
		'player_initiative_bonus' => 0,
		'enemy_initiative_bonus' => 0,
		'disposition' => $disp,
	);

	if ($disp > 50) {
		$bold = $disp - 50;
		$mods['crit_chance_bonus'] = (int)floor($bold / 3);      // 최대 +16
		$mods['crit_damage_bonus_pct'] = (int)floor($bold * 0.8); // 최대 +40%
		$mods['player_initiative_bonus'] = (int)floor($bold * 0.5);
		$mods['enemy_initiative_bonus'] = 0 - (int)floor($bold * 0.3);
	} elseif ($disp < 50) {
		$cautious = 50 - $disp;
		$mods['evasion_bonus'] = (int)floor($cautious / 2);       // 최대 +25
		$mods['block_bonus'] = (int)floor($cautious / 3);         // 최대 +16
		$mods['status_resist_pct'] = min(60, (int)floor($cautious * 1.2));
		$mods['player_initiative_bonus'] = 0 - (int)floor($cautious * 0.25);
		$mods['enemy_initiative_bonus'] = (int)floor($cautious * 0.5);
	}

	return $mods;
}

function resolve_first_turn_initiative(&$combat_state, $disposition_mods) {
	if (!is_array($combat_state)) {
		$combat_state = array();
	}
	if (isset($combat_state['initiative_resolved']) && (int)$combat_state['initiative_resolved'] === 1) {
		return array('is_first_turn' => false, 'side' => 'none', 'player_chance' => 0, 'enemy_chance' => 0);
	}

	$player_chance = 22 + (int)(isset($disposition_mods['player_initiative_bonus']) ? $disposition_mods['player_initiative_bonus'] : 0);
	$enemy_chance = 18 + (int)(isset($disposition_mods['enemy_initiative_bonus']) ? $disposition_mods['enemy_initiative_bonus'] : 0);
	$player_chance = max(5, min(85, $player_chance));
	$enemy_chance = max(5, min(75, $enemy_chance));

	$side = 'neutral';
	if (rand(1, 100) <= $player_chance) {
		$side = 'player';
	} elseif (rand(1, 100) <= $enemy_chance) {
		$side = 'enemy';
	}

	$combat_state['initiative_resolved'] = 1;
	$combat_state['initiative_side'] = $side;

	return array(
		'is_first_turn' => true,
		'side' => $side,
		'player_chance' => $player_chance,
		'enemy_chance' => $enemy_chance,
	);
}

function get_disposition_flee_modifiers($value) {
	$disp = normalize_disposition_value($value);
	$chance_bonus = 0;
	$gold_penalty_mult = 1.0;
	$fail_damage_mult = 1.0;

	if ($disp > 50) {
		$bold = $disp - 50;
		$chance_bonus = 0 - (int)floor($bold * 0.4);
		$gold_penalty_mult += ($bold * 0.01);
		$fail_damage_mult += ($bold * 0.006);
	} elseif ($disp < 50) {
		$cautious = 50 - $disp;
		$chance_bonus = (int)floor($cautious * 0.5);
		$gold_penalty_mult -= min(0.55, $cautious * 0.009);
		$fail_damage_mult -= min(0.35, $cautious * 0.006);
	}

	return array(
		'disposition' => $disp,
		'chance_bonus' => $chance_bonus,
		'gold_penalty_mult' => max(0.25, $gold_penalty_mult),
		'fail_damage_mult' => max(0.5, $fail_damage_mult),
	);
}

function roll_commander_base_stats_by_class($class_type) {
	$str = rand(1, 20);
	$mag = rand(1, 20);
	$agi = rand(1, 20);
	$luk = rand(1, 20);
	$men = rand(1, 20);
	$vit = rand(1, 20);

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

	return array(
		'str' => (int)$str,
		'mag' => (int)$mag,
		'agi' => (int)$agi,
		'luk' => (int)$luk,
		'men' => (int)$men,
		'vit' => (int)$vit,
		'disposition' => rand(1, 100),
	);
}

function distribute_random_bonus_stats(&$stats, $bonus_points) {
	$pool = max(0, (int)$bonus_points);
	if ($pool <= 0 || !is_array($stats)) return;
	$keys = array('str', 'mag', 'agi', 'luk', 'men', 'vit');
	for ($i = 0; $i < $pool; $i++) {
		$target = $keys[array_rand($keys)];
		$stats[$target] = (int)(isset($stats[$target]) ? $stats[$target] : 0) + 1;
	}
}

function add_commander_gold(PDO $pdo, $uid, $gold_amount, $track_lifetime = true) {
	$amount = max(0, (int)$gold_amount);
	if ($amount <= 0) return;
	if ($track_lifetime) {
		$pdo->prepare("UPDATE tb_commanders SET gold = gold + ?, lifetime_gold_earned = lifetime_gold_earned + ? WHERE uid = ?")
			->execute(array($amount, $amount, $uid));
		return;
	}
	$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")
		->execute(array($amount, $uid));
}

function apply_monster_power_multiplier($mob_hp, $mob_atk) {
	$power_multiplier = 3.0;
	$scaled_hp = (int)max(1, floor((float)$mob_hp * $power_multiplier));
	$scaled_atk = (int)max(1, floor((float)$mob_atk * $power_multiplier));
	return array($scaled_hp, $scaled_atk);
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
		$artifact_effects = get_user_artifact_effects($pdo, $uid);
		$artifact_trap_evade_bonus = max(0, (int)(isset($artifact_effects['trap_evade_bonus_pct']) ? $artifact_effects['trap_evade_bonus_pct'] : 0));
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
				$mob_atk = (int)max(1, $mob_atk * 2);
				$mob_max_hp = (int)max(1, $mob_max_hp * 2);
				list($mob_max_hp, $mob_atk) = apply_monster_power_multiplier($mob_max_hp, $mob_atk);
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
			if ($is_boss) {
				$mob_atk = (int)max(1, $mob_atk * 2);
				$mob_max_hp = (int)max(1, $mob_max_hp * 2);
			}
			list($mob_max_hp, $mob_atk) = apply_monster_power_multiplier($mob_max_hp, $mob_atk);

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
			$trap_damage = 0;
			$item_drop_log = '';
			$disposition = normalize_disposition_value(isset($cmd['disposition']) ? $cmd['disposition'] : 50);

			if ($event_type === 'gold') {
				$base_gold = rand(30, 140) * max(1, floor($current_floor / 2));
				$reward_gold = (int)floor($base_gold * (1 + ($p_luk * 0.01)));
				$log = "💰 <b>[{$event_title}]</b> {$event_seed} <b>{$reward_gold}G</b> 획득.";
			} elseif ($event_type === 'chest') {
				$base_gold = rand(80, 260) * max(1, floor($current_floor / 2));
				$reward_gold = (int)floor($base_gold * (1 + ($p_luk * 0.01)));
				$heal = rand(4, 12);
				$item_roll_chance = 35;
				$chest_trap_base_damage = max(1, rand(8, 20) - (int)floor($p_vit / 2));

				if ($disposition >= 80) {
					if (rand(0, 1) === 0) {
						$reward_gold = (int)max(1, floor($reward_gold * 2.0));
						$heal = max(1, (int)floor($heal * 2.0));
						$item_roll_chance = 55;
						$hp = min($max_hp, $hp + $heal);
						$log = "🎲 <b>[{$event_title}]</b> 과감하게 상자를 거칠게 열어젖혔습니다. 대박! <b>{$reward_gold}G</b> + HP <b>{$heal}</b> (보상 200%).";
					} else {
						$trap_damage = max(1, (int)floor($chest_trap_base_damage * 2.0));
						$hp = max(1, $hp - $trap_damage);
						$reward_gold = 0;
						$heal = 0;
						$item_roll_chance = 0;
						$log = "💣 <b>[{$event_title}]</b> 과감하게 상자를 열다 함정이 폭발했습니다. HP <b>-{$trap_damage}</b> (함정 피해 200%).";
					}
				} elseif ($disposition <= 20) {
					$reward_gold = (int)max(1, floor($reward_gold * 0.8));
					$heal = max(1, (int)floor($heal * 0.8));
					$item_roll_chance = 25;
					if (rand(1, 100) <= 20) {
						$trap_damage = max(1, (int)floor($chest_trap_base_damage * 0.5));
						$hp = max(1, $hp - $trap_damage);
						$reward_gold = 0;
						$heal = 0;
						$item_roll_chance = 0;
						$log = "🪤 <b>[{$event_title}]</b> 아주 조심스럽게 열었지만 미세한 함정이 발동했습니다. HP <b>-{$trap_damage}</b> (함정 피해 50%).";
					} else {
						$hp = min($max_hp, $hp + $heal);
						$log = "🔐 <b>[{$event_title}]</b> 아주 조심스럽게 상자를 열어 <b>{$reward_gold}G</b> + HP <b>{$heal}</b>를 안전하게 확보했습니다. (보상 80%)";
					}
				} else {
					$hp = min($max_hp, $hp + $heal);
					$log = "🎁 <b>[{$event_title}]</b> {$event_seed} <b>{$reward_gold}G</b> + HP <b>{$heal}</b>.";
				}

				if ($item_roll_chance > 0 && rand(1, 100) <= $item_roll_chance) {
					$catalog = get_item_system_catalog();
					$cons_codes = array_keys($catalog['consumables']);
					if (!empty($cons_codes)) {
						$drop_code = $cons_codes[array_rand($cons_codes)];
						if (grant_consumable_item($pdo, $uid, $drop_code, 1)) {
							$item_name = $catalog['consumables'][$drop_code]['name'];
							$item_drop_log = "🎒 보물상자에서 <b>{$item_name}</b> x1 발견.";
						}
					}
				}
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
					$trap_evade_chance = min(85, min(40, (int)floor($p_luk / 2)) + $artifact_trap_evade_bonus);
					if (rand(1, 100) <= $trap_evade_chance) {
						$reward_gold = (int)floor(rand(5, 25) * (1 + ($p_luk * 0.01)));
						$log = "🍀 <b>[{$event_title}]</b> 함정을 회피하고 <b>{$reward_gold}G</b> 획득.";
						if ($artifact_trap_evade_bonus > 0) {
							$log .= " <span style='color:#9ccc65;'>[네잎클로버 +{$artifact_trap_evade_bonus}%]</span>";
						}
					} else {
						$hp = max(1, $hp - $dmg);
						$trap_damage = (int)$dmg;
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
			if ($event_type === 'trap') {
				$resp['trap_damage'] = (int)$trap_damage;
			}

			if ($reward_gold > 0 || $reward_exp > 0) {
				$reward_state = $cmd;
				$reward_state['hp'] = $hp;
				$reward_state['max_hp'] = $max_hp;
				$reward_state['mp'] = $mp;
				$reward_state['max_mp'] = $max_mp;
				$reward_meta = apply_commander_rewards($pdo, $uid, $reward_state, $reward_gold, $reward_exp, $new_floor);
				$resp = array_merge($resp, $reward_meta);
			}
			if ($item_drop_log !== '') {
				$log .= ' ' . $item_drop_log;
				$resp['item_drop_text'] = $item_drop_log;
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
			$mob_atk = (int)max(1, $mob_atk * 2);
			$mob_max_hp = (int)max(1, $mob_max_hp * 2);
			list($mob_max_hp, $mob_atk) = apply_monster_power_multiplier($mob_max_hp, $mob_atk);

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

		if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) {
			$_SESSION['combat_state'] = array('hero_attack_counts' => array(), 'enemy_debuffs' => array(), 'orc_frenzy_stacks' => 0);
		}
		if (!isset($_SESSION['combat_state']['hero_attack_counts']) || !is_array($_SESSION['combat_state']['hero_attack_counts'])) {
			$_SESSION['combat_state']['hero_attack_counts'] = array();
		}
		if (!isset($_SESSION['combat_state']['enemy_debuffs']) || !is_array($_SESSION['combat_state']['enemy_debuffs'])) {
			$_SESSION['combat_state']['enemy_debuffs'] = array();
		}
		if (!isset($_SESSION['combat_state']['orc_frenzy_stacks'])) {
			$_SESSION['combat_state']['orc_frenzy_stacks'] = 0;
		}
		if (!isset($_SESSION['combat_state']['orc_no_kill_turns'])) {
			$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
		}
		if (!isset($_SESSION['orc_frenzy_stacks'])) {
			$_SESSION['orc_frenzy_stacks'] = 0;
		}

		$logs = array();
		$new_mob_hp = (int)$cmd['mob_hp'];
		$new_hp = (int)$cmd['hp'];
		$new_mp = (int)$cmd['mp'];
		$turn_hp_before = $new_hp;
		$turn_mp_before = $new_mp;
		$reflect_damage_turn = 0;
		$player_dmg = 0;
		$is_crit = false;
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
		$current_floor = max(1, (int)$cmd['current_floor']);
		$deck_synergy = build_deck_synergy_summary($deck, true, $current_floor);
		$is_boss_mob = (strpos((string)$cmd['mob_name'], '[보스]') !== false);

		$synergy_all_damage_mult = (float)(isset($deck_synergy['all_damage_multiplier']) ? $deck_synergy['all_damage_multiplier'] : 1.0);
		$synergy_first_hit_bonus_pct = (int)(isset($deck_synergy['first_hit_bonus_percent']) ? $deck_synergy['first_hit_bonus_percent'] : 0);
		$synergy_physical_pen_pct = (int)(isset($deck_synergy['physical_penetration_percent']) ? $deck_synergy['physical_penetration_percent'] : 0);
		$synergy_crit_bonus_pct = (int)(isset($deck_synergy['crit_damage_bonus_percent']) ? $deck_synergy['crit_damage_bonus_percent'] : 0);
		$synergy_reward_bonus_pct = (int)(isset($deck_synergy['reward_bonus_percent']) ? $deck_synergy['reward_bonus_percent'] : 0);
		$synergy_double_attack_pp = (int)(isset($deck_synergy['double_attack_bonus_point']) ? $deck_synergy['double_attack_bonus_point'] : 0);
		$synergy_shield_pct = (int)(isset($deck_synergy['shield_percent']) ? $deck_synergy['shield_percent'] : 0);
		$synergy_mp_regen = (int)(isset($deck_synergy['mp_regen_per_turn']) ? $deck_synergy['mp_regen_per_turn'] : 0);
		$synergy_demon_hp_drain_pct = (int)(isset($deck_synergy['demon_hp_drain_percent']) ? $deck_synergy['demon_hp_drain_percent'] : 0);
		$synergy_boss_bonus_pct = (int)(isset($deck_synergy['boss_damage_bonus_percent']) ? $deck_synergy['boss_damage_bonus_percent'] : 0);
		$synergy_incoming_mult = (float)(isset($deck_synergy['incoming_damage_multiplier']) ? $deck_synergy['incoming_damage_multiplier'] : 1.0);
		$synergy_orc_stack_pct = (int)(isset($deck_synergy['orc_kill_stack_bonus_percent']) ? $deck_synergy['orc_kill_stack_bonus_percent'] : 0);
		$synergy_orc_stack_max = (int)(isset($deck_synergy['orc_kill_stack_max']) ? $deck_synergy['orc_kill_stack_max'] : 0);
		$synergy_orc_stack_total_cap_pct = (int)(isset($deck_synergy['orc_stack_total_cap_percent']) ? $deck_synergy['orc_stack_total_cap_percent'] : 0);
		if ($synergy_orc_stack_max <= 0 || $synergy_orc_stack_pct <= 0) {
			reset_orc_frenzy_state();
		}
		$orc_stacks = max(0, (int)$_SESSION['orc_frenzy_stacks']);
		if ($synergy_orc_stack_max > 0) $orc_stacks = min($orc_stacks, $synergy_orc_stack_max);
		$synergy_orc_stack_total_pct = $orc_stacks * $synergy_orc_stack_pct;
		if ($synergy_orc_stack_total_cap_pct > 0) {
			$synergy_orc_stack_total_pct = min($synergy_orc_stack_total_pct, $synergy_orc_stack_total_cap_pct);
		}
		$_SESSION['combat_state']['orc_frenzy_stacks'] = $orc_stacks;

		if ($synergy_mp_regen > 0) {
			$regen_mp = min($synergy_mp_regen, max(0, (int)$cmd['max_mp'] - $new_mp));
			if ($regen_mp > 0) {
				$new_mp += $regen_mp;
				$logs[] = "🔷 <span style='color:#90caf9;'>[정령 공명]</span> 턴 시작 MP +{$regen_mp}";
			}
		}

		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_agi = (int)$cmd['stat_agi'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_men = (int)$cmd['stat_men'];
		$p_vit = (int)$cmd['stat_vit'];
		$buff_vit = get_player_buff_value('vit');
		if ($buff_vit > 0) $p_vit += $buff_vit;
		$berserk_bonus_pct = max(0, get_player_buff_value('berserk_power'));
		$disp_mods = get_disposition_combat_modifiers(isset($cmd['disposition']) ? $cmd['disposition'] : 50);
		$disp_crit_bonus = (int)$disp_mods['crit_chance_bonus'];
		$disp_crit_damage_bonus_pct = (int)$disp_mods['crit_damage_bonus_pct'];
		$disp_evasion_bonus = (int)$disp_mods['evasion_bonus'];
		$disp_block_bonus = (int)$disp_mods['block_bonus'];
		$disp_status_resist_pct = (int)$disp_mods['status_resist_pct'];
		$initiative_info = resolve_first_turn_initiative($_SESSION['combat_state'], $disp_mods);
		$skip_enemy_counter_this_turn = false;

		$relic_stmt = $pdo->prepare("SELECT atk_bonus_percent FROM tb_relics WHERE uid = ? LIMIT 1");
		$relic_stmt->execute(array($uid));
		$relic = $relic_stmt->fetch();
		$relic_atk_bonus = (int)(isset($relic['atk_bonus_percent']) ? $relic['atk_bonus_percent'] : 0);

		$artifact_effects = get_user_artifact_effects($pdo, $uid);
		$equipment_effects = get_user_equipment_effects($pdo, $uid);
		$runtime_mods = get_runtime_modifier_bundle($pdo, $uid);
		$mod_damage_bonus_pct      = max(0, (int)(isset($runtime_mods['damage_bonus_pct'])      ? $runtime_mods['damage_bonus_pct']      : 0));
		$mod_incoming_reduction_pct= max(0, (int)(isset($runtime_mods['incoming_reduction_pct']) ? $runtime_mods['incoming_reduction_pct'] : 0));
		$mod_gold_bonus_pct        = max(0, (int)(isset($runtime_mods['gold_bonus_pct'])        ? $runtime_mods['gold_bonus_pct']        : 0));
		$mod_exp_bonus_pct         = max(0, (int)(isset($runtime_mods['exp_bonus_pct'])         ? $runtime_mods['exp_bonus_pct']         : 0));
		$mod_mp_regen              = max(0, (int)(isset($runtime_mods['combat_mp_regen'])        ? $runtime_mods['combat_mp_regen']       : 0));
		$artifact_poison_bonus_pct = max(0, (int)(isset($artifact_effects['poison_damage_pct']) ? $artifact_effects['poison_damage_pct'] : 0));
		$artifact_battle_gold_bonus_pct = max(0, (int)(isset($artifact_effects['battle_gold_bonus_pct']) ? $artifact_effects['battle_gold_bonus_pct'] : 0));
		$equip_attack_pct = max(0, (int)(isset($equipment_effects['attack_pct']) ? $equipment_effects['attack_pct'] : 0));
		$equip_damage_reduction_pct = max(0, (int)(isset($equipment_effects['damage_reduction_pct']) ? $equipment_effects['damage_reduction_pct'] : 0));
		$equip_crit_chance = max(0, (int)(isset($equipment_effects['crit_chance']) ? $equipment_effects['crit_chance'] : 0));
		$equip_double_attack_bonus = max(0, (int)(isset($equipment_effects['double_attack_bonus']) ? $equipment_effects['double_attack_bonus'] : 0));
		$equip_lifesteal_pct = max(0, (int)(isset($equipment_effects['lifesteal_pct']) ? $equipment_effects['lifesteal_pct'] : 0));
		$_SESSION['combat_state']['artifact_poison_bonus_pct'] = $artifact_poison_bonus_pct;

		// 축복/내실 MP 리젠은 synergy 이후 다시 적용
		if ($mod_mp_regen > 0) {
			$regen_mp2 = min($mod_mp_regen, max(0, (int)$cmd['max_mp'] - $new_mp));
			if ($regen_mp2 > 0) {
				$new_mp += $regen_mp2;
				$logs[] = "✨ <span style='color:#ce93d8;'>[제단 축복]</span> 턴 시작 MP +{$regen_mp2}";
			}
		}

		$crit_chance = min(100, max(0, floor($p_luk / 2) + $equip_crit_chance + $disp_crit_bonus));
		$crit_mult = 1.5 + ($p_luk * 0.01);
		if ($disp_crit_damage_bonus_pct > 0) {
			$crit_mult *= (1 + ($disp_crit_damage_bonus_pct / 100.0));
		}
		if ($synergy_crit_bonus_pct > 0) {
			$crit_mult *= (1 + ($synergy_crit_bonus_pct / 100.0));
		}
		$men_mult = 1 + ($p_men * 0.005);
		$agi_double_chance = min(95, floor($p_agi / 5) + $synergy_double_attack_pp + $equip_double_attack_bonus);
		$agi_evasion_chance = min(100, max(0, (int)floor($p_agi / 4) + $disp_evasion_bonus));
		$vit_block_chance = min(100, floor($p_vit / 5) + $disp_block_bonus);
		$hero_shield_chance = (count($deck) > 0) ? min(40, (int)floor($p_vit / 10)) : 0;
		$str_party_bonus_pct = (int)floor($p_str / 10) * 2;
		$mag_party_bonus_pct = (int)floor($p_mag / 10) * 2;

		if (!empty($deck_synergy['active_effects'])) {
			$logs[] = "⚙️ <span style='color:#ffd54f;'>시너지 활성 {$deck_synergy['floor_tier']} ({$deck_synergy['floor_range']})</span>";
		}
		if (!empty($initiative_info['is_first_turn'])) {
			if ($initiative_info['side'] === 'player') {
				$skip_enemy_counter_this_turn = true;
				$logs[] = "⚡ <span style='color:#ffeb3b; font-weight:bold;'>[선공 확보]</span> 성향 영향으로 첫 턴 반격을 차단했습니다.";
			} elseif ($initiative_info['side'] === 'enemy') {
				$skip_enemy_counter_this_turn = true;
				$opening_dmg = max(1, (int)floor(((int)$cmd['mob_atk'] - floor($p_vit / 2)) * 1.2));
				$opening_dmg = max(1, (int)floor($opening_dmg * $synergy_incoming_mult));
				if ($equip_damage_reduction_pct > 0) {
					$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(80, $equip_damage_reduction_pct) / 100.0)));
				}
				if ($mod_incoming_reduction_pct > 0) {
					$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(80, $mod_incoming_reduction_pct) / 100.0)));
				}
				if ($disp_status_resist_pct > 0) {
					$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(35, $disp_status_resist_pct) / 100.0)));
				}
				if ($agi_evasion_chance > 0 && rand(1, 100) <= min(85, (int)floor($agi_evasion_chance * 0.6))) {
					$opening_dmg = 0;
					$logs[] = "💨 <span style='color:#80d8ff; font-weight:bold;'>[기습 회피]</span> 적의 선제타를 흘려냈습니다.";
				}
				if ($opening_dmg > 0) {
					$incoming_damage += (int)$opening_dmg;
					$incoming_damage_source = (string)$cmd['mob_name'];
					$new_hp = max(0, $new_hp - $opening_dmg);
					$logs[] = "⚠️ <span style='color:#ff8a80; font-weight:bold;'>[선공 빼앗김]</span> {$cmd['mob_name']}의 기습으로 <b>{$opening_dmg}</b> 피해.";
				}
			}
		}

		// 공격 버튼 기본 피해는 STR 중심으로 계산
		if ($new_hp <= 0) {
			$player_dmg = 0;
			$is_crit = false;
			$logs[] = "💀 적의 기습을 버티지 못하고 전열이 무너졌습니다.";
		} else {
			$player_base = max(1, (int)floor(($p_str * 1.8) + rand(4, 12)));
			if ($relic_atk_bonus > 0) $player_base = (int)floor($player_base * (1 + ($relic_atk_bonus / 100)));
			if ($equip_attack_pct > 0) $player_base = (int)floor($player_base * (1 + ($equip_attack_pct / 100.0)));
			if ($berserk_bonus_pct > 0) $player_base = (int)floor($player_base * (1 + ($berserk_bonus_pct / 100)));
			if ($synergy_first_hit_bonus_pct > 0) {
				$player_base = (int)floor($player_base * (1 + ($synergy_first_hit_bonus_pct / 100.0)));
				$logs[] = "🎯 <span style='color:#64b5f6;'>[원거리 포격]</span> 첫 타 피해 +{$synergy_first_hit_bonus_pct}%";
			}
			$player_base = (int)floor($player_base * $synergy_all_damage_mult);
			if ($synergy_physical_pen_pct > 0) $player_base = (int)floor($player_base * (1 + ($synergy_physical_pen_pct / 100.0)));
			if ($is_boss_mob && $synergy_boss_bonus_pct > 0) $player_base = (int)floor($player_base * (1 + ($synergy_boss_bonus_pct / 100.0)));
			if ($synergy_orc_stack_total_pct > 0) $player_base = (int)floor($player_base * (1 + ($synergy_orc_stack_total_pct / 100.0)));
			if ($mod_damage_bonus_pct > 0) $player_base = (int)floor($player_base * (1 + ($mod_damage_bonus_pct / 100.0)));
			$player_base = max(1, (int)$player_base);

			$is_crit = (rand(1, 100) <= $crit_chance);
			$player_dmg = $is_crit ? floor($player_base * $crit_mult) : $player_base;
			$turn_damage_details[] = array('name' => '사령관', 'damage' => (int)$player_dmg);
			$crit_txt = $is_crit ? "💥 <span style='color:#ffeb3b; font-weight:bold;'>[치명타!]</span> " : "";
			$logs[] = "{$crit_txt}🗡️ <b>[사령관]</b>의 공격! <b>{$cmd['mob_name']}</b>에게 <span style='color:#ff9800;'>{$player_dmg}</span> 피해.";
			$new_mob_hp = max(0, $new_mob_hp - $player_dmg);
			if ($equip_lifesteal_pct > 0 && $player_dmg > 0 && $new_hp > 0) {
				$heal = max(1, (int)floor($player_dmg * ($equip_lifesteal_pct / 100.0)));
				$before_hp = $new_hp;
				$new_hp = min((int)$cmd['max_hp'], $new_hp + $heal);
				$actual_heal = max(0, $new_hp - $before_hp);
				if ($actual_heal > 0) {
					$logs[] = "🩸 <span style='color:#a5d6a7;'>[흡혈]</span> 공격 피해를 흡수해 HP <b>+{$actual_heal}</b>";
				}
			}
		}

		$total_gold_gain = 0;
		if ($new_hp > 0 && $new_mob_hp > 0 && count($deck) > 0) {
			$logs[] = "<div style='margin:5px 0; padding-left:10px; border-left:2px solid #555; color:#aaa; font-size:0.85rem;'>▼ 영웅들이 합세합니다!</div>";
			foreach ($deck as $hero) {
				if ($new_mob_hp <= 0) break;
				$hero_traits = get_hero_traits($hero['hero_name']);
				$hero_is_physical = ((string)$hero_traits['attack_type'] === '물리');
				$hero_is_magic = in_array((string)$hero_traits['attack_type'], array('마법', '마법딜러'), true);

				$attack_times = (rand(1, 100) <= $agi_double_chance) ? 2 : 1;
				for ($i = 0; $i < $attack_times; $i++) {
					if ($new_mob_hp <= 0) break;
					if ($i === 1) $logs[] = "💨 <span style='color:#00e5ff; font-weight:bold;'>[AGI 발동]</span> <b>{$hero['hero_name']}</b> 연속 공격!";

					$range_map = array('일반'=>array(5,10,'#aaa'), '희귀'=>array(10,20,'#4caf50'), '영웅'=>array(18,30,'#2196f3'), '전설'=>array(28,45,'#9c27b0'), '신화'=>array(38,60,'#ff5252'), '불멸'=>array(45,75,'#ffeb3b'));
					$r = isset($range_map[$hero['hero_rank']]) ? $range_map[$hero['hero_rank']] : array(5,10,'#8bc34a');
					$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));
					$hero_dmg = rand($r[0], $r[1]) * $hero_count;
					$hero_dmg = (int)floor($hero_dmg * $men_mult);
					if ($hero_is_physical && $str_party_bonus_pct > 0) {
						$hero_dmg = (int)floor($hero_dmg * (1 + ($str_party_bonus_pct / 100)));
					} elseif ($hero_is_magic && $mag_party_bonus_pct > 0) {
						$hero_dmg = (int)floor($hero_dmg * (1 + ($mag_party_bonus_pct / 100)));
					}
					$hero_dmg = (int)floor($hero_dmg * $synergy_all_damage_mult);
					if ($hero_is_physical && $synergy_physical_pen_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_physical_pen_pct / 100.0)));
					if ($is_boss_mob && $synergy_boss_bonus_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_boss_bonus_pct / 100.0)));
					if ($synergy_orc_stack_total_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_orc_stack_total_pct / 100.0)));

					$armor_break_flat = isset($_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value']) ? (float)$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value'] : 0;
					if ($armor_break_flat > 0) $hero_dmg = (int)floor($hero_dmg * (1 + min(2.0, $armor_break_flat / 100.0)));
					$hero_dmg = max(1, (int)$hero_dmg);

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
		$orc_kill_this_turn = false;
		if ($new_mob_hp <= 0) {
			$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 쓰러졌습니다!";
			$orc_kill_this_turn = true;
			if ($synergy_orc_stack_max > 0) {
				$next_orc_stack = min($synergy_orc_stack_max, $orc_stacks + 1);
				if ($next_orc_stack > $orc_stacks) {
					$_SESSION['orc_frenzy_stacks'] = $next_orc_stack;
					$_SESSION['combat_state']['orc_frenzy_stacks'] = $next_orc_stack;
					$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
					$logs[] = "🪓 <span style='color:#ffcc80;'>[오크 광분]</span> 스택 {$next_orc_stack}/{$synergy_orc_stack_max}";
				}
			}
			$logs[] = "⚡ <span style='color:#ffeb3b; font-weight:bold;'>[선제 제압]</span> 반격 없이 전투를 끝냈습니다!";
			$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
			$reward_gold_total = (int)$battle_reward['gold'] + (int)$total_gold_gain;
			$reward_exp_total = (int)$battle_reward['exp'];
			if ($synergy_reward_bonus_pct > 0) {
				$reward_gold_total = (int)floor($reward_gold_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
				$reward_exp_total = (int)floor($reward_exp_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
			}
			if ($artifact_battle_gold_bonus_pct > 0) {
				$reward_gold_total = (int)floor($reward_gold_total * (1 + ($artifact_battle_gold_bonus_pct / 100.0)));
			}
			if ($mod_gold_bonus_pct > 0) {
				$reward_gold_total = (int)floor($reward_gold_total * (1 + ($mod_gold_bonus_pct / 100.0)));
			}
			if ($mod_exp_bonus_pct > 0) {
				$reward_exp_total = (int)floor($reward_exp_total * (1 + ($mod_exp_bonus_pct / 100.0)));
			}
			$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => $new_mp)), $reward_gold_total, $reward_exp_total, (int)$cmd['current_floor']);
			$logs[] = "🎖️ 전투 보상: <b>{$reward_gold_total}G</b>, 경험치 <b>+{$reward_exp_total}</b>.";
			foreach ($reward_meta['levelup_logs'] as $levelup_log) {
				$logs[] = $levelup_log;
			}
			$new_hp = (int)$reward_meta['new_hp'];
			$new_mp = (int)$reward_meta['new_mp'];
			$item_drop = grant_battle_item_drop($pdo, $uid, (int)$cmd['current_floor'], (string)$cmd['mob_name']);
			if (!empty($item_drop['dropped']) && !empty($item_drop['log'])) {
				$logs[] = $item_drop['log'];
			}
			if (!empty($item_drop['artifact_result']['hp_adjusted'])) {
				$new_hp = (int)$item_drop['artifact_result']['new_hp'];
				$reward_meta['new_hp'] = $new_hp;
				$reward_meta['max_hp'] = (int)$item_drop['artifact_result']['new_max_hp'];
			}
			unset($_SESSION['combat_state']);
			$status = 'victory';
		} else {
			$stun_turns = isset($_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left']) ? (int)$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] : 0;
			if ($new_hp <= 0) {
				$logs[] = "💀 사령관이 쓰러졌습니다...";
				reset_orc_frenzy_state();
				unset($_SESSION['combat_state']);
				$status = 'defeat';
			} elseif ($skip_enemy_counter_this_turn) {
				if (isset($initiative_info['side']) && $initiative_info['side'] === 'player') {
					$logs[] = "⚡ <span style='color:#ffeb3b; font-weight:bold;'>[선공 유지]</span> 적의 반격 타이밍을 봉쇄했습니다.";
				}
				$status = 'ongoing';
			} elseif ($stun_turns > 0) {
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
				$mob_dmg = max(1, ((int)$cmd['mob_atk'] - floor($p_vit / 2)) * 2);
				$mob_dmg = max(1, (int)floor($mob_dmg * $synergy_incoming_mult));
				if ($equip_damage_reduction_pct > 0) {
					$mob_dmg = max(1, (int)floor($mob_dmg * (1 - min(80, $equip_damage_reduction_pct) / 100.0)));
				}
				if ($mod_incoming_reduction_pct > 0) {
					$mob_dmg = max(1, (int)floor($mob_dmg * (1 - min(80, $mod_incoming_reduction_pct) / 100.0)));
				}
				if ($disp_status_resist_pct > 0) {
					$mob_dmg = max(1, (int)floor($mob_dmg * (1 - min(35, $disp_status_resist_pct) / 100.0)));
				}
				$shield_left = 0;
				if ($synergy_shield_pct > 0) {
					$shield_left = max(1, (int)floor((int)$cmd['max_hp'] * ($synergy_shield_pct / 100.0)));
					$blocked = min($shield_left, $mob_dmg);
					$mob_dmg -= $blocked;
					if ($blocked > 0) {
						$logs[] = "🧱 <span style='color:#80deea;'>[기계 방진]</span> 보호막이 <b>{$blocked}</b> 피해를 흡수했습니다.";
					}
				}
				$incoming_damage += (int)$mob_dmg;
				$incoming_damage_source = (string)$cmd['mob_name'];
				if ($mob_dmg > 0) {
					$logs[] = "🩸 <b>{$cmd['mob_name']}</b>의 반격! <span style='color:#ff5252;'>{$mob_dmg}</span> 피해.";
					$new_hp = max(0, $new_hp - $mob_dmg);
				} else {
					$logs[] = "🛡️ 반격 피해를 모두 흡수했습니다.";
				}

				$reflect_dmg = max(0, (int)floor(($p_vit * 0.8) + ((int)$cmd['max_hp'] * 0.03)));
				if ($reflect_dmg > 0) {
					$reflect_damage_turn += $reflect_dmg;
					$new_mob_hp = max(0, $new_mob_hp - $reflect_dmg);
					$logs[] = "🛡️ <span style='color:#8bc34a; font-weight:bold;'>[가시 갑옷]</span> 단단한 방어력으로 <b>{$cmd['mob_name']}</b>에게 <span style='color:#8bc34a;'>{$reflect_dmg}</span> 반사 피해!";
				}

				if ($new_hp <= 0) {
					$logs[] = "💀 사령관이 쓰러졌습니다...";
					reset_orc_frenzy_state();
					unset($_SESSION['combat_state']);
					$status = 'defeat';
				} elseif ($new_mob_hp <= 0) {
					$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 반사 피해로 쓰러졌습니다!";
					$orc_kill_this_turn = true;
					if ($synergy_orc_stack_max > 0) {
						$next_orc_stack = min($synergy_orc_stack_max, $orc_stacks + 1);
						if ($next_orc_stack > $orc_stacks) {
							$_SESSION['orc_frenzy_stacks'] = $next_orc_stack;
							$_SESSION['combat_state']['orc_frenzy_stacks'] = $next_orc_stack;
							$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
							$logs[] = "🪓 <span style='color:#ffcc80;'>[오크 광분]</span> 스택 {$next_orc_stack}/{$synergy_orc_stack_max}";
						}
					}
					$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
					$reward_gold_total = (int)$battle_reward['gold'] + (int)$total_gold_gain;
					$reward_exp_total = (int)$battle_reward['exp'];
					if ($synergy_reward_bonus_pct > 0) {
						$reward_gold_total = (int)floor($reward_gold_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
						$reward_exp_total = (int)floor($reward_exp_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
					}
					if ($artifact_battle_gold_bonus_pct > 0) {
						$reward_gold_total = (int)floor($reward_gold_total * (1 + ($artifact_battle_gold_bonus_pct / 100.0)));
					}
					$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => $new_mp)), $reward_gold_total, $reward_exp_total, (int)$cmd['current_floor']);
					$logs[] = "🎖️ 전투 보상: <b>{$reward_gold_total}G</b>, 경험치 <b>+{$reward_exp_total}</b>.";
					foreach ($reward_meta['levelup_logs'] as $levelup_log) {
						$logs[] = $levelup_log;
					}
					$new_hp = (int)$reward_meta['new_hp'];
					$new_mp = (int)$reward_meta['new_mp'];
					$item_drop = grant_battle_item_drop($pdo, $uid, (int)$cmd['current_floor'], (string)$cmd['mob_name']);
					if (!empty($item_drop['dropped']) && !empty($item_drop['log'])) {
						$logs[] = $item_drop['log'];
					}
					if (!empty($item_drop['artifact_result']['hp_adjusted'])) {
						$new_hp = (int)$item_drop['artifact_result']['new_hp'];
						$reward_meta['new_hp'] = $new_hp;
						$reward_meta['max_hp'] = (int)$item_drop['artifact_result']['new_max_hp'];
					}
					unset($_SESSION['combat_state']);
					$status = 'victory';
				} else {
					$status = 'ongoing';
				}
			}
		}

		if ($status === 'ongoing' && $synergy_demon_hp_drain_pct > 0) {
			$drain = max(1, (int)floor(((int)$cmd['max_hp']) * ($synergy_demon_hp_drain_pct / 100.0)));
			$new_hp = max(0, $new_hp - $drain);
			$logs[] = "🩸 <span style='color:#ff8a80;'>[악마 계약]</span> 턴 종료 HP <b>-{$drain}</b>";
			if ($new_hp <= 0) {
				$logs[] = "💀 악마의 대가로 사령관이 쓰러졌습니다...";
				reset_orc_frenzy_state();
				unset($_SESSION['combat_state']);
				$status = 'defeat';
			}
		}

		if ($status === 'ongoing') {
			apply_orc_frenzy_decay($logs, $synergy_orc_stack_max, $orc_kill_this_turn);
			$current_orc_stacks = max(0, (int)(isset($_SESSION['orc_frenzy_stacks']) ? $_SESSION['orc_frenzy_stacks'] : 0));
			$current_orc_bonus_pct = $current_orc_stacks * $synergy_orc_stack_pct;
			if ($synergy_orc_stack_total_cap_pct > 0) {
				$current_orc_bonus_pct = min($current_orc_bonus_pct, $synergy_orc_stack_total_cap_pct);
			}
			$turn_outgoing_damage = max(0, (int)$player_dmg + (int)$total_hero_turn_damage + (int)$reflect_damage_turn);
			$turn_hp_delta = (int)$new_hp - (int)$turn_hp_before;
			$turn_mp_delta = (int)$new_mp - (int)$turn_mp_before;
			append_balance_metrics_log($logs, $turn_outgoing_damage, $incoming_damage, $turn_hp_delta, $turn_mp_delta, $synergy_first_hit_bonus_pct, $current_orc_bonus_pct);
		}

		if ($status === 'defeat') {
			reset_orc_frenzy_state();
		}

		if ($reward_meta === null && $total_gold_gain > 0) {
			add_commander_gold($pdo, $uid, $total_gold_gain, true);
		}

		if ($status === 'victory' || $status === 'defeat') {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
				->execute(array($new_hp, $new_mp, $uid));
		} else {
			tick_player_buffs($logs);
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $new_mob_hp, $uid));
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
			'new_mp' => $reward_meta ? (int)$reward_meta['new_mp'] : $new_mp,
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
		reset_orc_frenzy_state();
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

		$disp = normalize_disposition_value(isset($cmd['disposition']) ? $cmd['disposition'] : 50);
		$flee_mods = get_disposition_flee_modifiers($disp);
		$chance = 40 + (int)$cmd['stat_agi'] + (int)$flee_mods['chance_bonus'];
		$chance = max(10, min(95, $chance));

		$current_floor = max(1, (int)$cmd['current_floor']);
		$current_gold = max(0, (int)$cmd['gold']);
		$base_penalty = max(8, (int)floor(($current_floor * 12) + ((int)$cmd['mob_atk'] * 1.5)));
		$success_penalty = (int)floor($base_penalty * 0.40 * (float)$flee_mods['gold_penalty_mult']);
		$fail_penalty = (int)floor($base_penalty * 0.80 * (float)$flee_mods['gold_penalty_mult']);
		$success_penalty = max(0, min($current_gold, $success_penalty));
		$fail_penalty = max(0, min($current_gold, $fail_penalty));

		if (rand(1, 100) <= $chance) {
			$new_floor = max(1, $current_floor - 1);
			$new_gold = max(0, $current_gold - $success_penalty);
			$penalty_text = ($success_penalty > 0) ? " 도주 중 자원을 소모해 <b>{$success_penalty}G</b>를 잃었습니다." : '';
			$log = "💨 <b>[도주 성공!]</b> {$cmd['mob_name']}에게서 도망쳤고 {$new_floor}층으로 후퇴했습니다." . $penalty_text;
			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, gold = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
				->execute(array($new_floor, $new_gold, $uid));
			reset_orc_frenzy_state();
			unset($_SESSION['combat_state']);
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
			$pdo->commit();
			echo json_encode(array(
				'status' => 'success',
				'log' => $log,
				'new_floor' => $new_floor,
				'new_max_floor' => (int)$cmd['max_floor'],
				'new_gold' => $new_gold,
				'flee_chance' => $chance
			));
		} else {
			$dmg = (int)floor(rand(10, 20) * (float)$flee_mods['fail_damage_mult']);
			$dmg = max(1, $dmg);
			$new_hp = max(0, (int)$cmd['hp'] - $dmg);
			$new_gold = max(0, $current_gold - $fail_penalty);
			$gold_text = ($fail_penalty > 0) ? " <span style='color:#ffd54f;'>골드 -{$fail_penalty}</span>" : '';
			$log = "🩸 <b>[도주 실패!]</b> {$cmd['mob_name']}의 기습! 체력 -{$dmg}{$gold_text}";
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, gold = ? WHERE uid = ?")->execute(array($new_hp, $new_gold, $uid));
			$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
			$pdo->commit();
			echo json_encode(array(
				'status' => 'fail',
				'log' => $log,
				'new_hp' => $new_hp,
				'max_hp' => (int)$cmd['max_hp'],
				'new_gold' => $new_gold,
				'flee_chance' => $chance
			));
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
		add_commander_gold($pdo, $uid, $reward, true);
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
		$was_in_combat_at_start = $is_in_combat;
		if ((int)$cmd['hp'] <= 0) throw new Exception('사망 상태에서는 스킬을 사용할 수 없습니다.');
		if (!$is_in_combat && $skill_id !== 'heal') throw new Exception('힐은 비전투 중에도 사용할 수 있지만, 해당 스킬은 전투 중에만 사용 가능합니다.');

		if ($is_in_combat) {
			if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) {
				$_SESSION['combat_state'] = array('hero_attack_counts' => array(), 'enemy_debuffs' => array(), 'orc_frenzy_stacks' => 0);
			}
			if (!isset($_SESSION['combat_state']['hero_attack_counts']) || !is_array($_SESSION['combat_state']['hero_attack_counts'])) {
				$_SESSION['combat_state']['hero_attack_counts'] = array();
			}
			if (!isset($_SESSION['combat_state']['enemy_debuffs']) || !is_array($_SESSION['combat_state']['enemy_debuffs'])) {
				$_SESSION['combat_state']['enemy_debuffs'] = array();
			}
			if (!isset($_SESSION['combat_state']['orc_frenzy_stacks'])) {
				$_SESSION['combat_state']['orc_frenzy_stacks'] = 0;
			}
			if (!isset($_SESSION['combat_state']['orc_no_kill_turns'])) {
				$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
			}
			if (!isset($_SESSION['orc_frenzy_stacks'])) {
				$_SESSION['orc_frenzy_stacks'] = 0;
			}
		}

		$new_mp = (int)$cmd['mp'];
		$new_hp = (int)$cmd['hp'];
		$new_mob_hp = (int)$cmd['mob_hp'];
		$turn_hp_before = $new_hp;
		$turn_mp_before = $new_mp;
		$turn_damage_dealt = 0;
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

		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_agi = (int)$cmd['stat_agi'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_men = (int)$cmd['stat_men'];
		$berserk_bonus_pct = max(0, get_player_buff_value('berserk_power'));
		$disp_mods = get_disposition_combat_modifiers(isset($cmd['disposition']) ? $cmd['disposition'] : 50);
		$disp_crit_bonus = (int)$disp_mods['crit_chance_bonus'];
		$disp_crit_damage_bonus_pct = (int)$disp_mods['crit_damage_bonus_pct'];
		$newly_applied_buffs = array();
		$crit_mult = 1.5 + ($p_luk * 0.01);
		if ($disp_crit_damage_bonus_pct > 0) {
			$crit_mult *= (1 + ($disp_crit_damage_bonus_pct / 100.0));
		}
		$hero_crit_chance = min(100, max(0, floor($p_luk / 2) + $disp_crit_bonus));
		$men_mult = 1 + ($p_men * 0.005);
		$str_party_bonus_pct = (int)floor($p_str / 10) * 2;
		$mag_party_bonus_pct = (int)floor($p_mag / 10) * 2;
		$total_gold_gain = 0;
		$deck = array();
		$agi_double_chance = floor($p_agi / 5);
		$first_hit_pending = true;

		$synergy_all_damage_mult = 1.0;
		$synergy_first_hit_bonus_pct = 0;
		$synergy_physical_pen_pct = 0;
		$synergy_crit_bonus_pct = 0;
		$synergy_reward_bonus_pct = 0;
		$synergy_double_attack_pp = 0;
		$synergy_skill_damage_mult = 1.0;
		$synergy_mp_regen = 0;
		$synergy_demon_hp_drain_pct = 0;
		$synergy_boss_bonus_pct = 0;
		$synergy_orc_stack_pct = 0;
		$synergy_orc_total_cap_pct = 0;
		$orc_synergy_max = 0;
		$synergy_orc_stack_total_pct = 0;
		$artifact_poison_bonus_pct = 0;
		$artifact_battle_gold_bonus_pct = 0;
		$equip_attack_pct = 0;
		$equip_damage_reduction_pct = 0;
		$equip_crit_chance = 0;
		$equip_double_attack_bonus = 0;
		$mod_incoming_reduction_pct = 0;
		$disp_status_resist_pct = (int)$disp_mods['status_resist_pct'];
		$initiative_info = array('is_first_turn' => false, 'side' => 'none');
		$is_boss_mob = (strpos((string)$cmd['mob_name'], '[보스]') !== false);

		if ($is_in_combat) {
			$artifact_effects = get_user_artifact_effects($pdo, $uid);
			$equipment_effects = get_user_equipment_effects($pdo, $uid);
			$runtime_mods = get_runtime_modifier_bundle($pdo, $uid);
			$artifact_poison_bonus_pct = max(0, (int)(isset($artifact_effects['poison_damage_pct']) ? $artifact_effects['poison_damage_pct'] : 0));
			$artifact_battle_gold_bonus_pct = max(0, (int)(isset($artifact_effects['battle_gold_bonus_pct']) ? $artifact_effects['battle_gold_bonus_pct'] : 0));
			$equip_attack_pct = max(0, (int)(isset($equipment_effects['attack_pct']) ? $equipment_effects['attack_pct'] : 0));
			$equip_damage_reduction_pct = max(0, (int)(isset($equipment_effects['damage_reduction_pct']) ? $equipment_effects['damage_reduction_pct'] : 0));
			$equip_crit_chance = max(0, (int)(isset($equipment_effects['crit_chance']) ? $equipment_effects['crit_chance'] : 0));
			$equip_double_attack_bonus = max(0, (int)(isset($equipment_effects['double_attack_bonus']) ? $equipment_effects['double_attack_bonus'] : 0));
			$mod_incoming_reduction_pct = max(0, (int)(isset($runtime_mods['incoming_reduction_pct']) ? $runtime_mods['incoming_reduction_pct'] : 0));
			$hero_crit_chance = min(100, max(0, floor($p_luk / 2) + $equip_crit_chance + $disp_crit_bonus));
			$_SESSION['combat_state']['artifact_poison_bonus_pct'] = $artifact_poison_bonus_pct;

			$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
			$deck_stmt->execute(array($uid));
			$deck = $deck_stmt->fetchAll();
			$deck_synergy = build_deck_synergy_summary($deck, true, (int)$cmd['current_floor']);
			$synergy_all_damage_mult = (float)(isset($deck_synergy['all_damage_multiplier']) ? $deck_synergy['all_damage_multiplier'] : 1.0);
			$synergy_first_hit_bonus_pct = (int)(isset($deck_synergy['first_hit_bonus_percent']) ? $deck_synergy['first_hit_bonus_percent'] : 0);
			$synergy_physical_pen_pct = (int)(isset($deck_synergy['physical_penetration_percent']) ? $deck_synergy['physical_penetration_percent'] : 0);
			$synergy_crit_bonus_pct = (int)(isset($deck_synergy['crit_damage_bonus_percent']) ? $deck_synergy['crit_damage_bonus_percent'] : 0);
			$synergy_reward_bonus_pct = (int)(isset($deck_synergy['reward_bonus_percent']) ? $deck_synergy['reward_bonus_percent'] : 0);
			$synergy_double_attack_pp = (int)(isset($deck_synergy['double_attack_bonus_point']) ? $deck_synergy['double_attack_bonus_point'] : 0);
			$synergy_skill_damage_mult = (float)(isset($deck_synergy['skill_damage_multiplier']) ? $deck_synergy['skill_damage_multiplier'] : 1.0);
			$synergy_mp_regen = (int)(isset($deck_synergy['mp_regen_per_turn']) ? $deck_synergy['mp_regen_per_turn'] : 0);
			$synergy_demon_hp_drain_pct = (int)(isset($deck_synergy['demon_hp_drain_percent']) ? $deck_synergy['demon_hp_drain_percent'] : 0);
			$synergy_boss_bonus_pct = (int)(isset($deck_synergy['boss_damage_bonus_percent']) ? $deck_synergy['boss_damage_bonus_percent'] : 0);
			$synergy_orc_stack_pct = (int)(isset($deck_synergy['orc_kill_stack_bonus_percent']) ? $deck_synergy['orc_kill_stack_bonus_percent'] : 0);
			$orc_synergy_max = (int)(isset($deck_synergy['orc_kill_stack_max']) ? $deck_synergy['orc_kill_stack_max'] : 0);
			$synergy_orc_total_cap_pct = (int)(isset($deck_synergy['orc_stack_total_cap_percent']) ? $deck_synergy['orc_stack_total_cap_percent'] : 0);
			if ($orc_synergy_max <= 0 || $synergy_orc_stack_pct <= 0) {
				reset_orc_frenzy_state();
			}
			$orc_stacks = max(0, (int)$_SESSION['orc_frenzy_stacks']);
			$orc_max = (int)(isset($deck_synergy['orc_kill_stack_max']) ? $deck_synergy['orc_kill_stack_max'] : 0);
			if ($orc_max > 0) $orc_stacks = min($orc_stacks, $orc_max);
			$synergy_orc_stack_total_pct = $orc_stacks * $synergy_orc_stack_pct;
			if ($synergy_orc_total_cap_pct > 0) {
				$synergy_orc_stack_total_pct = min($synergy_orc_stack_total_pct, $synergy_orc_total_cap_pct);
			}
			$_SESSION['combat_state']['orc_frenzy_stacks'] = $orc_stacks;

			$agi_double_chance = min(95, $agi_double_chance + $synergy_double_attack_pp + $equip_double_attack_bonus);
			if ($synergy_crit_bonus_pct > 0) {
				$crit_mult *= (1 + ($synergy_crit_bonus_pct / 100.0));
			}
			if (!empty($deck_synergy['active_effects'])) {
				$logs[] = "⚙️ <span style='color:#ffd54f;'>시너지 활성 {$deck_synergy['floor_tier']} ({$deck_synergy['floor_range']})</span>";
			}
			$initiative_info = resolve_first_turn_initiative($_SESSION['combat_state'], $disp_mods);
			if (!empty($initiative_info['is_first_turn'])) {
				if ($initiative_info['side'] === 'player') {
					$logs[] = "⚡ <span style='color:#ffeb3b; font-weight:bold;'>[선공 확보]</span> 성향 영향으로 주문 주도권을 잡았습니다.";
				} elseif ($initiative_info['side'] === 'enemy') {
					$opening_dmg = max(1, (int)floor(((int)$cmd['mob_atk'] - floor((int)$cmd['stat_vit'] / 2)) * 1.2));
					if ($equip_damage_reduction_pct > 0) {
						$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(80, $equip_damage_reduction_pct) / 100.0)));
					}
					if ($mod_incoming_reduction_pct > 0) {
						$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(80, $mod_incoming_reduction_pct) / 100.0)));
					}
					if ($disp_status_resist_pct > 0) {
						$opening_dmg = max(1, (int)floor($opening_dmg * (1 - min(35, $disp_status_resist_pct) / 100.0)));
					}
					$new_hp = max(0, $new_hp - $opening_dmg);
					$logs[] = "⚠️ <span style='color:#ff8a80; font-weight:bold;'>[선공 빼앗김]</span> {$cmd['mob_name']}의 기습으로 <b>{$opening_dmg}</b> 피해.";
				}
			}
		}

		if ($is_in_combat && $new_hp <= 0) {
			$logs[] = "💀 적의 기습에 쓰러져 스킬을 발동하지 못했습니다.";
		} elseif ($skill['type'] === 'damage') {
			if (!$is_in_combat) throw new Exception('해당 스킬은 전투 중에만 사용 가능합니다.');
			$damage = (int)floor(((int)$skill['value'] + floor((int)$cmd['stat_mag'] * 1.5)) * $mag_amp);
			if ($equip_attack_pct > 0) $damage = (int)floor($damage * (1 + ($equip_attack_pct / 100.0)));
			if ($berserk_bonus_pct > 0) $damage = (int)floor($damage * (1 + ($berserk_bonus_pct / 100)));
			if ($synergy_first_hit_bonus_pct > 0) {
				$damage = (int)floor($damage * (1 + ($synergy_first_hit_bonus_pct / 100.0)));
				$logs[] = "🎯 <span style='color:#64b5f6;'>[원거리 포격]</span> 첫 타 피해 +{$synergy_first_hit_bonus_pct}%";
			}
			$first_hit_pending = false;
			$damage = (int)floor($damage * $synergy_all_damage_mult);
			$damage = (int)floor($damage * $synergy_skill_damage_mult);
			if ($is_boss_mob && $synergy_boss_bonus_pct > 0) $damage = (int)floor($damage * (1 + ($synergy_boss_bonus_pct / 100.0)));
			if ($synergy_orc_stack_total_pct > 0) $damage = (int)floor($damage * (1 + ($synergy_orc_stack_total_pct / 100.0)));
			$damage = max(1, (int)$damage);
			$new_mob_hp = max(0, $new_mob_hp - $damage);
			$turn_damage_dealt += (int)$damage;
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

		if ($is_in_combat && $new_hp > 0 && $new_mob_hp > 0) {
			if (count($deck) > 0) {
				$logs[] = "<div style='margin:5px 0; padding-left:10px; border-left:2px solid #555; color:#aaa; font-size:0.85rem;'>▼ 영웅들이 마법에 호응해 합세합니다!</div>";
				foreach ($deck as $hero) {
					if ($new_mob_hp <= 0) break;
					$hero_traits = get_hero_traits($hero['hero_name']);
					$hero_is_physical = ((string)$hero_traits['attack_type'] === '물리');
					$hero_is_magic = in_array((string)$hero_traits['attack_type'], array('마법', '마법딜러'), true);

					$attack_times = (rand(1, 100) <= $agi_double_chance) ? 2 : 1;
					for ($i = 0; $i < $attack_times; $i++) {
						if ($new_mob_hp <= 0) break;
						if ($i === 1) $logs[] = "💨 <span style='color:#00e5ff; font-weight:bold;'>[AGI 발동]</span> <b>{$hero['hero_name']}</b> 연속 공격!";

						$range_map = array('일반'=>array(5,10,'#aaa'), '희귀'=>array(10,20,'#4caf50'), '영웅'=>array(18,30,'#2196f3'), '전설'=>array(28,45,'#9c27b0'), '신화'=>array(38,60,'#ff5252'), '불멸'=>array(45,75,'#ffeb3b'));
						$r = isset($range_map[$hero['hero_rank']]) ? $range_map[$hero['hero_rank']] : array(5,10,'#8bc34a');
						$hero_count = max(1, (int)(isset($hero['equipped_count']) ? $hero['equipped_count'] : (isset($hero['quantity']) ? $hero['quantity'] : 1)));
						$hero_dmg = rand($r[0], $r[1]) * $hero_count;
						$hero_dmg = (int)floor($hero_dmg * $men_mult);
						if ($hero_is_physical && $str_party_bonus_pct > 0) {
							$hero_dmg = (int)floor($hero_dmg * (1 + ($str_party_bonus_pct / 100)));
						} elseif ($hero_is_magic && $mag_party_bonus_pct > 0) {
							$hero_dmg = (int)floor($hero_dmg * (1 + ($mag_party_bonus_pct / 100)));
						}
						if ($first_hit_pending && $synergy_first_hit_bonus_pct > 0) {
							$hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_first_hit_bonus_pct / 100.0)));
							$first_hit_pending = false;
							$logs[] = "🎯 <span style='color:#64b5f6;'>[원거리 포격]</span> 첫 타 피해 +{$synergy_first_hit_bonus_pct}%";
						}
						$hero_dmg = (int)floor($hero_dmg * $synergy_all_damage_mult);
						if ($hero_is_physical && $synergy_physical_pen_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_physical_pen_pct / 100.0)));
						if ($is_boss_mob && $synergy_boss_bonus_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_boss_bonus_pct / 100.0)));
						if ($synergy_orc_stack_total_pct > 0) $hero_dmg = (int)floor($hero_dmg * (1 + ($synergy_orc_stack_total_pct / 100.0)));

						$armor_break_flat = isset($_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value']) ? (float)$_SESSION['combat_state']['enemy_debuffs']['armor_break_flat']['value'] : 0;
						if ($armor_break_flat > 0) $hero_dmg = (int)floor($hero_dmg * (1 + min(2.0, $armor_break_flat / 100.0)));
						$hero_dmg = max(1, (int)$hero_dmg);

						$is_h_crit = false;
						if (rand(1, 100) <= $hero_crit_chance) { $is_h_crit = true; $hero_dmg = (int)floor($hero_dmg * $crit_mult); }

						apply_hero_skills($hero, $new_mob_hp, $logs, $total_gold_gain, $cmd, $hero_dmg, $is_h_crit);

						$hcrit = $is_h_crit ? "⚡ <span style='color:yellow; font-weight:bold;'>[치명타]</span> " : "⚔️ ";
						$logs[] = "{$hcrit}<span style='color:{$r[2]}'>[{$hero['hero_name']}]</span>(x{$hero_count})의 공격. {$hero_dmg} 피해.";
						$turn_damage_dealt += (int)$hero_dmg;
						$new_mob_hp = max(0, $new_mob_hp - $hero_dmg);
					}
				}
			}
		}

		$reward_meta = null;
		$orc_kill_this_turn = false;
		if ($is_in_combat) {
			if ($synergy_mp_regen > 0) {
				$regen_mp = min($synergy_mp_regen, max(0, (int)$cmd['max_mp'] - $new_mp));
				if ($regen_mp > 0) {
					$new_mp += $regen_mp;
					$logs[] = "🔷 <span style='color:#90caf9;'>[정령 공명]</span> 턴 종료 MP +{$regen_mp}";
				}
			}

			if ($total_gold_gain > 0 && $new_mob_hp > 0) {
				add_commander_gold($pdo, $uid, $total_gold_gain, true);
			}

			if ($new_mob_hp <= 0) {
				$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 쓰러졌습니다!";
				$orc_kill_this_turn = true;
				$orc_gain_max = (int)(isset($deck_synergy['orc_kill_stack_max']) ? $deck_synergy['orc_kill_stack_max'] : 0);
				if ($orc_gain_max > 0) {
					$next_orc_stack = min($orc_gain_max, max(0, (int)$_SESSION['orc_frenzy_stacks']) + 1);
					$_SESSION['orc_frenzy_stacks'] = $next_orc_stack;
					$_SESSION['combat_state']['orc_frenzy_stacks'] = $next_orc_stack;
					$_SESSION['combat_state']['orc_no_kill_turns'] = 0;
					$logs[] = "🪓 <span style='color:#ffcc80;'>[오크 광분]</span> 스택 {$next_orc_stack}/{$orc_gain_max}";
				}
				$turn_hp_delta = (int)$new_hp - (int)$turn_hp_before;
				$turn_mp_delta = (int)$new_mp - (int)$turn_mp_before;
				append_balance_metrics_log($logs, $turn_damage_dealt, 0, $turn_hp_delta, $turn_mp_delta, $synergy_first_hit_bonus_pct, $synergy_orc_stack_total_pct);
				$battle_reward = get_battle_reward_bundle((int)$cmd['current_floor'], (string)$cmd['mob_name']);
				$reward_gold_total = (int)$battle_reward['gold'] + (int)$total_gold_gain;
				$reward_exp_total = (int)$battle_reward['exp'];
				if ($synergy_reward_bonus_pct > 0) {
					$reward_gold_total = (int)floor($reward_gold_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
					$reward_exp_total = (int)floor($reward_exp_total * (1 + ($synergy_reward_bonus_pct / 100.0)));
				}
				if ($artifact_battle_gold_bonus_pct > 0) {
					$reward_gold_total = (int)floor($reward_gold_total * (1 + ($artifact_battle_gold_bonus_pct / 100.0)));
				}
				$reward_meta = apply_commander_rewards($pdo, $uid, array_merge($cmd, array('hp' => $new_hp, 'mp' => $new_mp)), $reward_gold_total, $reward_exp_total, (int)$cmd['current_floor']);
				$logs[] = "🎖️ 전투 보상: <b>{$reward_gold_total}G</b>, 경험치 <b>+{$reward_exp_total}</b>.";
				foreach ($reward_meta['levelup_logs'] as $levelup_log) {
					$logs[] = $levelup_log;
				}
				$new_hp = (int)$reward_meta['new_hp'];
				$new_mp = (int)$reward_meta['new_mp'];
				$item_drop = grant_battle_item_drop($pdo, $uid, (int)$cmd['current_floor'], (string)$cmd['mob_name']);
				if (!empty($item_drop['dropped']) && !empty($item_drop['log'])) {
					$logs[] = $item_drop['log'];
				}
				if (!empty($item_drop['artifact_result']['hp_adjusted'])) {
					$new_hp = (int)$item_drop['artifact_result']['new_hp'];
					$reward_meta['new_hp'] = $new_hp;
					$reward_meta['max_hp'] = (int)$item_drop['artifact_result']['new_max_hp'];
				}
				unset($_SESSION['combat_state']);
				$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
					->execute(array($new_hp, $new_mp, $uid));
				$new_mob_hp = 0;
			} else {
				if ($synergy_demon_hp_drain_pct > 0) {
					$drain = max(1, (int)floor(((int)$cmd['max_hp']) * ($synergy_demon_hp_drain_pct / 100.0)));
					$new_hp = max(0, $new_hp - $drain);
					$logs[] = "🩸 <span style='color:#ff8a80;'>[악마 계약]</span> 턴 종료 HP <b>-{$drain}</b>";
				}

				if ($new_hp <= 0) {
					$logs[] = "💀 악마의 대가로 사령관이 쓰러졌습니다...";
					reset_orc_frenzy_state();
					unset($_SESSION['combat_state']);
					$pdo->prepare("UPDATE tb_commanders SET hp = 0, mp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
						->execute(array($new_mp, $uid));
				} else {
					apply_orc_frenzy_decay($logs, $orc_synergy_max, $orc_kill_this_turn);
					$current_orc_stacks = max(0, (int)(isset($_SESSION['orc_frenzy_stacks']) ? $_SESSION['orc_frenzy_stacks'] : 0));
					$current_orc_bonus_pct = $current_orc_stacks * $synergy_orc_stack_pct;
					if ($synergy_orc_total_cap_pct > 0) {
						$current_orc_bonus_pct = min($current_orc_bonus_pct, $synergy_orc_total_cap_pct);
					}
					$turn_hp_delta = (int)$new_hp - (int)$turn_hp_before;
					$turn_mp_delta = (int)$new_mp - (int)$turn_mp_before;
					append_balance_metrics_log($logs, $turn_damage_dealt, 0, $turn_hp_delta, $turn_mp_delta, $synergy_first_hit_bonus_pct, $current_orc_bonus_pct);
					tick_player_buffs($logs, $newly_applied_buffs);
					$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $new_mob_hp, $uid));
				}
			}
		} else {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $uid));
		}

		if ($new_hp <= 0) {
			reset_orc_frenzy_state();
		}
		if ($was_in_combat_at_start) {
			$final_log = implode('<br>', $logs);
			$stream_seed = html_log_to_plain_text($final_log);
			$_SESSION['combat_stream_text'] = $stream_seed;
		}
		$pdo->commit();
		echo json_encode(array(
			'status' => 'success',
			'stream' => $was_in_combat_at_start ? true : false,
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
		$st = $pdo->prepare("SELECT gold, stat_luk, current_floor FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		$owned_total = get_total_hero_units($pdo, $uid);
		$progression = get_or_create_commander_progression_state($pdo, $uid);
		$hero_limit = get_hero_capacity_limit_by_progression($progression);
		if ($owned_total >= $hero_limit) throw new Exception("보유 영웅(출전 포함)은 최대 {$hero_limit}명까지 가능합니다.");
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
		list($deck_html, $inv_html, $deck_count, $deck_synergy_html) = generate_hero_lists($heroes, (int)$cmd['current_floor']);

		$gold_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$gold_st->execute(array($uid));
		$new_gold = (int)$gold_st->fetchColumn();

		$msg = "✨ <b style='color:yellow;'>[{$rank}] {$hero_name}</b> 영웅이 소환되었습니다!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();
		$cap = get_hero_capacity_snapshot($pdo, $uid);

		echo json_encode(array('status'=>'success','msg'=>$msg,'new_rank'=>$rank,'new_hero_name'=>$hero_name,'deck_html'=>$deck_html,'inv_html'=>$inv_html,'deck_count'=>$deck_count,'deck_synergy_html'=>$deck_synergy_html,'new_gold'=>$new_gold,'hero_owned'=>$cap['hero_owned'],'hero_limit'=>$cap['hero_limit']));
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
		$current_floor_st = $pdo->prepare("SELECT current_floor, gold FROM tb_commanders WHERE uid = ?");
		$current_floor_st->execute(array($uid));
		$cmd_state = $current_floor_st->fetch();
		$current_floor = (int)(isset($cmd_state['current_floor']) ? $cmd_state['current_floor'] : 1);
		list($deck_html, $inv_html, $deck_count, $deck_synergy_html) = generate_hero_lists($heroes, $current_floor);

		$current_gold = (int)(isset($cmd_state['gold']) ? $cmd_state['gold'] : 0);

		$msg = "🧬 <b>{$hero_name}</b> 3마리를 합성하여 <span style='color:#ffeb3b; font-weight:bold;'>[{$next_rank}] {$new_hero_name}</span> 획득! <span style='color:#80cbc4;'>[대기 상태 지급]</span>";
		$trace_msg = "🧬 합성 완료: {$hero_name} x3 -> [{$next_rank}] {$new_hero_name} (대기 상태 지급)";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $trace_msg));
		$pdo->commit();
		$cap = get_hero_capacity_snapshot($pdo, $uid);
		echo json_encode(array('status'=>'success','msg'=>$msg,'new_rank'=>$next_rank,'new_hero_name'=>$new_hero_name,'deck_html'=>$deck_html,'inv_html'=>$inv_html,'deck_count'=>$deck_count,'deck_synergy_html'=>$deck_synergy_html,'new_gold'=>$current_gold,'hero_owned'=>$cap['hero_owned'],'hero_limit'=>$cap['hero_limit']));
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
		$floor_stmt = $pdo->prepare("SELECT current_floor FROM tb_commanders WHERE uid = ?");
		$floor_stmt->execute(array($uid));
		$current_floor = (int)$floor_stmt->fetchColumn();
		list($deck_html, $inv_html, $deck_count, $deck_synergy_html) = generate_hero_lists($heroes, max(1, $current_floor));
		$cap = get_hero_capacity_snapshot($pdo, $uid);

		$pdo->commit();
		echo json_encode(array('status' => 'success', 'deck_html' => $deck_html, 'inv_html' => $inv_html, 'deck_count' => $deck_count, 'deck_synergy_html' => $deck_synergy_html, 'hero_owned' => $cap['hero_owned'], 'hero_limit' => $cap['hero_limit']));
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

	$owns_singleton_target = function($name) use ($pdo, $uid, $get_aliases) {
		$rank = get_hero_rank_from_catalog($name);
		if (!is_singleton_limited_hero_rank($rank)) return false;
		return has_owned_hero_by_names($pdo, $uid, $get_aliases($name));
	};

	$render_view = function() use ($mythic_recipes, $evolution_recipes, $evolution_requirements, $fetch_available_count, $fetch_max_battle_count, $owns_singleton_target) {
		$html = '<div style="background:#222; padding:15px; border-radius:5px; margin-bottom:20px;">';
		$html .= '<h3 style="color:#ff5252; margin-top:0;">신화 조합 (레시피)</h3>';
		$html .= '<p style="color:#ccc; font-size:0.9rem;">출전/파견 중이 아닌 재료 영웅을 소모해 대상 신화를 직접 조합합니다.</p>';
		foreach ($mythic_recipes as $mythic => $info) {
			$req_counts = array_count_values($info['materials']);
			$can_craft = true;
			$already_owned_target = $owns_singleton_target($mythic);
			$parts = array();
			foreach ($req_counts as $mat => $need) {
				$owned = $fetch_available_count($mat);
				if ($owned < $need) $can_craft = false;
				$parts[] = "{$mat} {$owned}/{$need}";
			}
			$safe_name = str_replace("'", "\\'", $mythic);
			$html .= "<div style='padding:10px; border:1px solid #444; margin-top:8px; border-radius:4px;'>";
			$html .= "<b style='color:#ff8a80;'>[신화]</b> <b>{$mythic}</b><br><span style='color:#aaa; font-size:0.85rem;'>재료: " . implode(' + ', $parts) . "</span>";
			if ($already_owned_target) {
				$html .= "<button class='btn' style='float:right; background:#555;' disabled>이미 보유</button>";
				$html .= "<div style='color:#888; font-size:0.85rem; margin-top:4px;'>이미 보유한 신화 영웅은 중복 조합할 수 없습니다.</div>";
			} elseif ($can_craft) {
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
			$already_owned_evolved = $can_evolve_hero ? $owns_singleton_target($immortal) : false;
			$can_evolve = ($can_evolve_hero && !$already_owned_evolved && $max_battle >= $need_battles);
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
			} elseif ($already_owned_evolved) {
				$html .= "<button class='btn' style='background:#555; float:right;' disabled>이미 보유</button>";
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
			$target_rank = isset($hero_data[$target_name]['rank']) ? (string)$hero_data[$target_name]['rank'] : '신화';
			ensure_singleton_hero_not_owned($pdo, $uid, $target_name, $target_rank, $get_aliases($target_name));
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
			$evolved_rank = isset($hero_data[$evolved_name]['rank']) ? (string)$hero_data[$evolved_name]['rank'] : '불멸';
			ensure_singleton_hero_not_owned($pdo, $uid, $evolved_name, $evolved_rank, $get_aliases($evolved_name));

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

function handle_item_info(PDO $pdo) {
	app_log('handle_item_info.start');
	$uid = get_uid_or_fail();
	try {
		$g = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$g->execute(array($uid));
		$gold = (int)$g->fetchColumn();
		echo json_encode(array(
			'status' => 'success',
			'html' => render_item_bag_html($pdo, $uid, $gold),
			'new_gold' => $gold
		));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_item_buy(PDO $pdo) {
	app_log('handle_item_buy.start');
	$uid = get_uid_or_fail();
	$item_code = isset($_POST['item_code']) ? trim((string)$_POST['item_code']) : '';
	$catalog = get_item_system_catalog();
	if (!isset($catalog['consumables'][$item_code])) {
		json_error('구매할 수 없는 아이템입니다.');
		return;
	}
	$def = $catalog['consumables'][$item_code];
	if (isset($def['shop_buyable']) && !$def['shop_buyable']) {
		json_error('해당 아이템은 상점에서 구매할 수 없습니다.');
		return;
	}
	$cost = max(1, (int)$def['shop_cost']);

	try {
		$pdo->beginTransaction();
		$cmd_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd_st->execute(array($uid));
		$cmd = $cmd_st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		$gold = (int)$cmd['gold'];
		if ($gold < $cost) throw new Exception("골드가 부족합니다. (필요: {$cost}G)");

		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($cost, $uid));
		grant_consumable_item($pdo, $uid, $item_code, 1);

		$new_gold = $gold - $cost;
		$msg = "🛒 {$def['name']}을(를) 구매했습니다. (-{$cost}G)";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'msg' => $msg,
			'new_gold' => $new_gold,
			'html' => render_item_bag_html($pdo, $uid, $new_gold)
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_item_use(PDO $pdo) {
	app_log('handle_item_use.start');
	global $hero_data;
	$uid = get_uid_or_fail();
	$item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
	if ($item_id <= 0) {
		json_error('잘못된 아이템 선택입니다.');
		return;
	}

	try {
		$pdo->beginTransaction();
		$cmd_st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd_st->execute(array($uid));
		$cmd = $cmd_st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		$item_st = $pdo->prepare("SELECT * FROM tb_items WHERE item_id = ? AND uid = ? FOR UPDATE");
		$item_st->execute(array($item_id, $uid));
		$item = $item_st->fetch();
		if (!$item) throw new Exception('아이템을 찾을 수 없습니다.');
		if ((string)$item['category'] !== 'consumable') throw new Exception('소모품만 사용할 수 있습니다.');
		if ((int)$item['quantity'] <= 0) throw new Exception('수량이 부족합니다.');

		$item_code = (string)$item['item_code'];
		$msg = '';
		$new_floor = (int)$cmd['current_floor'];
		$new_hp = (int)$cmd['hp'];
		$new_mp = (int)$cmd['mp'];
		$max_hp = (int)$cmd['max_hp'];
		$max_mp = (int)$cmd['max_mp'];
		$left_combat = false;
		$deck_html = null;
		$inv_html = null;
		$deck_count = null;
		$deck_synergy_html = null;

		if ($item_code === 'cons_flashbang') {
			if ((int)$cmd['is_combat'] !== 1) throw new Exception('전투 중에만 사용할 수 있습니다.');
			if (strpos((string)$cmd['mob_name'], '[보스]') === false) throw new Exception('섬광탄은 보스전에서만 사용할 수 있습니다.');
			if (!isset($_SESSION['combat_state']) || !is_array($_SESSION['combat_state'])) $_SESSION['combat_state'] = array();
			if (!isset($_SESSION['combat_state']['enemy_debuffs']) || !is_array($_SESSION['combat_state']['enemy_debuffs'])) {
				$_SESSION['combat_state']['enemy_debuffs'] = array();
			}
			$cur_turn = isset($_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left']) ? (int)$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] : 0;
			$_SESSION['combat_state']['enemy_debuffs']['stun'] = array('turns_left' => max(1, $cur_turn), 'source' => '섬광탄');
			$msg = "💥 섬광탄 사용! <b>{$cmd['mob_name']}</b>의 다음 행동을 봉쇄했습니다.";
		} elseif ($item_code === 'cons_escape_scroll') {
			if ((int)$cmd['is_combat'] !== 1) throw new Exception('전투 중에만 사용할 수 있습니다.');
			$new_floor = max(1, (int)$cmd['current_floor'] - 1);
			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
				->execute(array($new_floor, $uid));
			unset($_SESSION['combat_state']);
			$left_combat = true;
			$msg = "🌀 비상 탈출 스크롤을 찢어 {$new_floor}층으로 후퇴했습니다.";
		} elseif ($item_code === 'cons_mana_potion') {
			if ((int)$cmd['hp'] <= 0) throw new Exception('사망 상태에서는 사용할 수 없습니다.');
			$gain = max(1, (int)floor($max_mp * 0.5));
			$new_mp = min($max_mp, (int)$cmd['mp'] + $gain);
			$actual = max(0, $new_mp - (int)$cmd['mp']);
			$pdo->prepare("UPDATE tb_commanders SET mp = ? WHERE uid = ?")->execute(array($new_mp, $uid));
			$msg = "🔷 고농축 마나 물약 사용! MP <b>+{$actual}</b> 회복.";
		} elseif ($item_code === 'cons_mythic_ticket') {
			if ((int)$cmd['is_combat'] === 1) throw new Exception('전투 중에는 신화 소환 티켓을 사용할 수 없습니다.');

			$progression = get_commander_progression_state($pdo, $uid);
			$hero_limit = get_hero_capacity_limit_by_progression($progression);
			$owned_total = get_total_hero_units($pdo, $uid);
			if ($owned_total >= $hero_limit) {
				throw new Exception("영웅 보유 한도({$hero_limit}명)가 가득 찼습니다. 내실 강화에서 한도를 확장하세요.");
			}

			$owned_singleton_names = array();
			$owned_singleton_st = $pdo->prepare("SELECT hero_name FROM tb_heroes WHERE uid = ? AND quantity > 0 AND hero_rank IN ('신화', '불멸', '유일')");
			$owned_singleton_st->execute(array($uid));
			foreach ($owned_singleton_st->fetchAll(PDO::FETCH_COLUMN) as $owned_singleton_name) {
				$owned_singleton_names[(string)$owned_singleton_name] = true;
			}

			$pool = array();
			foreach ($hero_data as $hero_name => $def) {
				if (isset($def['rank']) && (string)$def['rank'] === '신화') {
					if (isset($owned_singleton_names[(string)$hero_name])) continue;
					$pool[] = (string)$hero_name;
				}
			}
			if (empty($pool)) throw new Exception('이미 획득 가능한 신화 영웅을 모두 보유 중입니다.');

			$hero_name = $pool[array_rand($pool)];
			$rank = '신화';
			ensure_singleton_hero_not_owned($pdo, $uid, $hero_name, $rank);
			$lore = isset($hero_data[$hero_name]['hero_lore']) ? $hero_data[$hero_name]['hero_lore'] : '';

			$find = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? AND is_equipped = 0 AND is_on_expedition = 0 LIMIT 1 FOR UPDATE");
			$find->execute(array($uid, $hero_name));
			$exists = $find->fetch();
			if ($exists) {
				$pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array((int)$exists['inv_id']));
			} else {
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, level, hero_lore) VALUES (?, ?, ?, 1, 1, ?)")
					->execute(array($uid, $rank, $hero_name, $lore));
			}
			$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $hero_name));

			$all = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY is_equipped DESC, hero_rank DESC, hero_name ASC");
			$all->execute(array($uid));
			$heroes = $all->fetchAll();
			list($deck_html, $inv_html, $deck_count, $deck_synergy_html) = generate_hero_lists($heroes, (int)$cmd['current_floor']);

			$msg = "🎟️ 신화 소환 티켓 사용! <b style='color:#ffb74d;'>[신화] {$hero_name}</b>을(를) 영입했습니다.";
		} else {
			throw new Exception('아직 사용할 수 없는 소모품입니다.');
		}

		$remain = (int)$item['quantity'] - 1;
		if ($remain > 0) {
			$pdo->prepare("UPDATE tb_items SET quantity = ? WHERE item_id = ?")->execute(array($remain, $item_id));
		} else {
			$pdo->prepare("DELETE FROM tb_items WHERE item_id = ?")->execute(array($item_id));
		}

		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		$response = array(
			'status' => 'success',
			'msg' => $msg,
			'left_combat' => $left_combat,
			'new_floor' => $new_floor,
			'new_hp' => $new_hp,
			'max_hp' => $max_hp,
			'new_mp' => $new_mp,
			'max_mp' => $max_mp,
			'new_gold' => (int)$cmd['gold'],
			'html' => render_item_bag_html($pdo, $uid, (int)$cmd['gold'])
		);
		if ($deck_html !== null) {
			$cap = get_hero_capacity_snapshot($pdo, $uid);
			$response['deck_html'] = $deck_html;
			$response['inv_html'] = $inv_html;
			$response['deck_count'] = $deck_count;
			$response['deck_synergy_html'] = $deck_synergy_html;
			$response['hero_owned'] = $cap['hero_owned'];
			$response['hero_limit'] = $cap['hero_limit'];
		}

		echo json_encode($response);
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

// ─── 내실 강화 업그레이드 ─────────────────────────────────────────────────────
function handle_progression_upgrade(PDO $pdo) {
	app_log('handle_progression_upgrade.start');
	$uid = get_uid_or_fail();
	$upgrade_key = isset($_POST['upgrade_key']) ? trim((string)$_POST['upgrade_key']) : '';
	$catalog = get_progression_upgrade_catalog();
	if (!isset($catalog[$upgrade_key])) {
		json_error('올바르지 않은 강화 항목입니다.');
		return;
	}

	try {
		$pdo->beginTransaction();
		$cmd_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd_st->execute(array($uid));
		$cmd = $cmd_st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		$prog = get_or_create_commander_progression_state($pdo, $uid);
		$col = $catalog[$upgrade_key]['column'];
		$current_level = isset($prog[$col]) ? (int)$prog[$col] : 0;
		$max_level = $catalog[$upgrade_key]['max_level'];
		if ($current_level >= $max_level) {
			throw new Exception("이미 최대 레벨({$max_level})에 도달했습니다.");
		}

		$cost = get_progression_upgrade_cost($upgrade_key, $current_level);
		$gold = (int)$cmd['gold'];
		if ($gold < $cost) {
			throw new Exception("골드가 부족합니다. (필요: {$cost} / 보유: {$gold})");
		}

		$new_level = $current_level + 1;
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($cost, $uid));
		$pdo->prepare("UPDATE tb_commander_progression SET `{$col}` = ? WHERE uid = ?")->execute(array($new_level, $uid));

		$msg = "⚔️ [{$catalog[$upgrade_key]['label']}] Lv.{$new_level} 강화 완료! (비용: {$cost}G)";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		$gold_after = $gold - $cost;
		echo json_encode(array(
			'status' => 'success',
			'msg' => $msg,
			'new_gold' => $gold_after,
			'upgrade_key' => $upgrade_key,
			'new_level' => $new_level,
			'next_cost' => ($new_level < $max_level) ? get_progression_upgrade_cost($upgrade_key, $new_level) : null
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

// ─── 제단 축복 리롤 ───────────────────────────────────────────────────────────
function handle_blessing_reroll(PDO $pdo) {
	app_log('handle_blessing_reroll.start');
	$uid = get_uid_or_fail();

	try {
		$pdo->beginTransaction();
		$cmd_st = $pdo->prepare("SELECT gold, current_floor FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$cmd_st->execute(array($uid));
		$cmd = $cmd_st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		$blessing = get_or_create_commander_blessing_state($pdo, $uid);
		$reroll_count = isset($blessing['reroll_count']) ? (int)$blessing['reroll_count'] : 0;
		$floor = (int)$cmd['current_floor'];
		$cost = get_blessing_reroll_cost($reroll_count, $floor);
		$gold = (int)$cmd['gold'];
		if ($gold < $cost) {
			throw new Exception("골드가 부족합니다. (필요: {$cost} / 보유: {$gold})");
		}

		$new_blessing = roll_random_blessing();
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($cost, $uid));
		$pdo->prepare("UPDATE tb_commander_blessings SET blessing_type = ?, blessing_value = ?, reroll_count = ? WHERE uid = ?")
			->execute(array($new_blessing['type'], $new_blessing['value'], $reroll_count + 1, $uid));

		$meta = get_blessing_meta($new_blessing['type'], $new_blessing['value']);
		$label = isset($meta['name']) ? $meta['name'] : $new_blessing['type'];
		$msg = "✨ 제단 축복이 갱신되었습니다! [{$label}] +{$new_blessing['value']}% (비용: {$cost}G)";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		$gold_after = $gold - $cost;
		$next_cost = get_blessing_reroll_cost($reroll_count + 1, $floor);
		echo json_encode(array(
			'status' => 'success',
			'msg' => $msg,
			'new_gold' => $gold_after,
			'blessing_type' => $new_blessing['type'],
			'blessing_label' => $label,
			'blessing_value' => $new_blessing['value'],
			'reroll_count' => $reroll_count + 1,
			'next_cost' => $next_cost
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

// ─── 진행 상태 조회 ───────────────────────────────────────────────────────────
function handle_get_progression_state(PDO $pdo) {
	app_log('handle_get_progression_state.start');
	$uid = get_uid_or_fail();

	$prog = get_or_create_commander_progression_state($pdo, $uid);
	$blessing = get_or_create_commander_blessing_state($pdo, $uid);
	$floor_st = $pdo->prepare("SELECT current_floor, gold FROM tb_commanders WHERE uid = ?");
	$floor_st->execute(array($uid));
	$cmd = $floor_st->fetch();
	$floor = $cmd ? (int)$cmd['current_floor'] : 1;
	$gold = $cmd ? (int)$cmd['gold'] : 0;

	$catalog = get_progression_upgrade_catalog();
	$upgrades = array();
	foreach ($catalog as $key => $def) {
		$col = $def['column'];
		$current = isset($prog[$col]) ? (int)$prog[$col] : 0;
		$max_level = $def['max_level'];
		$upgrades[$key] = array(
			'label' => $def['label'],
			'desc' => $def['effect'],
			'current_level' => $current,
			'max_level' => $max_level,
			'cost' => ($current < $max_level) ? get_progression_upgrade_cost($key, $current) : null
		);
	}

	$blessing_type = isset($blessing['blessing_type']) ? (string)$blessing['blessing_type'] : 'none';
	$blessing_value = isset($blessing['blessing_value']) ? (float)$blessing['blessing_value'] : 0.0;
	$reroll_count = isset($blessing['reroll_count']) ? (int)$blessing['reroll_count'] : 0;
	$blessing_meta = $blessing_type !== 'none' ? get_blessing_meta($blessing_type, $blessing_value) : array('name' => '없음', 'description' => '');

	$hero_limit = get_hero_capacity_limit_by_progression($prog);
	$hero_owned = get_total_hero_units($pdo, $uid);

	echo json_encode(array(
		'status' => 'success',
		'gold' => $gold,
		'hero_limit' => $hero_limit,
		'hero_owned' => $hero_owned,
		'upgrades' => $upgrades,
		'blessing' => array(
			'type' => $blessing_type,
			'label' => isset($blessing_meta['name']) ? $blessing_meta['name'] : $blessing_type,
			'desc' => isset($blessing_meta['description']) ? $blessing_meta['description'] : '',
			'value' => $blessing_value,
			'reroll_count' => $reroll_count,
			'reroll_cost' => get_blessing_reroll_cost($reroll_count, $floor)
		)
	));
}

function handle_item_toggle_equip(PDO $pdo) {
	app_log('handle_item_toggle_equip.start');
	$uid = get_uid_or_fail();
	$item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
	$action = isset($_POST['action']) ? (int)$_POST['action'] : 0;
	if ($item_id <= 0 || !in_array($action, array(1, -1), true)) {
		json_error('잘못된 요청입니다.');
		return;
	}

	try {
		$pdo->beginTransaction();
		$item_st = $pdo->prepare("SELECT * FROM tb_items WHERE item_id = ? AND uid = ? FOR UPDATE");
		$item_st->execute(array($item_id, $uid));
		$item = $item_st->fetch();
		if (!$item) throw new Exception('장비를 찾을 수 없습니다.');
		if ((string)$item['category'] !== 'equipment') throw new Exception('장비만 장착할 수 있습니다.');

		if ($action === 1) {
			$slot = isset($item['slot_type']) ? (string)$item['slot_type'] : '';
			if ($slot !== '') {
				$pdo->prepare("UPDATE tb_items SET is_equipped = 0 WHERE uid = ? AND category = 'equipment' AND slot_type = ? AND item_id != ?")
					->execute(array($uid, $slot, $item_id));
			}
			$pdo->prepare("UPDATE tb_items SET is_equipped = 1 WHERE item_id = ?")->execute(array($item_id));
			$msg = "🛡️ {$item['item_name']} 장비를 장착했습니다.";
		} else {
			$pdo->prepare("UPDATE tb_items SET is_equipped = 0 WHERE item_id = ?")->execute(array($item_id));
			$msg = "📦 {$item['item_name']} 장비를 해제했습니다.";
		}

		$g = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$g->execute(array($uid));
		$gold = (int)$g->fetchColumn();
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'msg' => $msg,
			'new_gold' => $gold,
			'html' => render_item_bag_html($pdo, $uid, $gold)
		));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_item_synthesize(PDO $pdo) {
	app_log('handle_item_synthesize.start');
	$uid = get_uid_or_fail();
	$base_grade = isset($_POST['base_grade']) ? trim((string)$_POST['base_grade']) : '';
	$catalog = get_item_system_catalog();
	$chain = isset($catalog['synthesis_chain']) ? $catalog['synthesis_chain'] : array();
	if (!isset($chain[$base_grade])) {
		json_error('해당 등급은 합성할 수 없습니다.');
		return;
	}
	$next_grade = $chain[$base_grade];

	try {
		$pdo->beginTransaction();
		$eq_st = $pdo->prepare("SELECT item_id FROM tb_items WHERE uid = ? AND category = 'equipment' AND item_grade = ? AND is_equipped = 0 FOR UPDATE");
		$eq_st->execute(array($uid, $base_grade));
		$rows = $eq_st->fetchAll();
		if (count($rows) < 3) throw new Exception("{$base_grade} 등급 장비가 3개 이상 필요합니다.");

		$ids = array();
		foreach ($rows as $r) $ids[] = (int)$r['item_id'];
		shuffle($ids);
		$consume_ids = array_slice($ids, 0, 3);

		$del = $pdo->prepare("DELETE FROM tb_items WHERE item_id = ? AND uid = ?");
		foreach ($consume_ids as $cid) {
			$del->execute(array($cid, $uid));
		}

		$floor_st = $pdo->prepare("SELECT current_floor, gold FROM tb_commanders WHERE uid = ?");
		$floor_st->execute(array($uid));
		$cmd = $floor_st->fetch();
		$floor = $cmd ? (int)$cmd['current_floor'] : 1;
		$gold = $cmd ? (int)$cmd['gold'] : 0;

		$equipment = roll_equipment_by_grade($next_grade, $floor);
		if (!$equipment) throw new Exception('합성 결과 장비 생성에 실패했습니다.');
		grant_equipment_item($pdo, $uid, $equipment);

		$msg = "🔧 {$base_grade} 장비 3개를 합성해 <b>{$equipment['item_name']}</b> 획득!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $msg));
		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'msg' => $msg,
			'new_gold' => $gold,
			'html' => render_item_bag_html($pdo, $uid, $gold)
		));
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
	if (
		(isset($meta['provider']) && strtolower((string)$meta['provider']) === 'raw') ||
		(isset($meta['model']) && strtolower((string)$meta['model']) === 'local-fallback')
	) {
		$ai_text = '';
	}
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
	$story_meta = (isset($_SESSION['story_stream_meta']) && is_array($_SESSION['story_stream_meta'])) ? $_SESSION['story_stream_meta'] : array();
	unset($_SESSION['story_stream_text']);
	unset($_SESSION['story_stream_meta']);
	$is_intro_story = isset($story_meta['is_intro']) && (int)$story_meta['is_intro'] === 1;
	$story_mode = $is_intro_story ? 'intro_story' : 'default';
	$ai = request_ai_text_with_fallback($story_seed, true, $story_mode);
	$ai_story = is_array($ai) ? (string)$ai['text'] : (string)$ai;
	if ($is_intro_story) {
		$raw_uid = isset($story_meta['uid']) ? (string)$story_meta['uid'] : '';
		$uid_label = $raw_uid !== '' ? ('UID ' . preg_replace('/[^0-9A-Za-z_-]/', '', $raw_uid)) : '';
		$commander_id = isset($story_meta['commander_id']) ? (string)$story_meta['commander_id'] : '';
		$ai_story = ensure_intro_story_quality($ai_story, $uid_label, $commander_id);
	}
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
		$stmt = $pdo->prepare("SELECT max_hp, max_mp, current_floor FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		$new_floor = max(1, (int)$cmd['current_floor'] - 5);

		$sql = "UPDATE tb_commanders SET hp = ?, mp = ?, current_floor = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?";
		$pdo->prepare($sql)->execute(array((int)$cmd['max_hp'], (int)$cmd['max_mp'], $new_floor, $uid));
		unset($_SESSION['combat_state']);

		$log = "✨ 여신의 축복으로 부활했습니다. {$new_floor}층에서 다시 시작합니다.";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();

		echo json_encode(array('status' => 'success', 'log' => $log, 'new_floor' => $new_floor, 'max_hp' => (int)$cmd['max_hp'], 'max_mp' => (int)$cmd['max_mp']));
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function handle_reincarnate_preview(PDO $pdo) {
	app_log('handle_reincarnate_preview.start');
	$uid = get_uid_or_fail();
	try {
		$stmt = $pdo->prepare("SELECT uid, class_type, level, gold, lifetime_gold_earned, reincarnation_count, reincarnation_level_total, reincarnation_stat_bonus FROM tb_commanders WHERE uid = ? LIMIT 1");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');

		$life_levels = max(0, (int)$cmd['level'] - 1);
		$new_level_total = max(0, (int)$cmd['reincarnation_level_total']) + $life_levels;
		$new_bonus_gain = $life_levels;
		$new_stat_bonus_total = max(0, (int)$cmd['reincarnation_stat_bonus']) + $new_bonus_gain;
		$gold_bonus = (int)floor(max(0, (int)$cmd['lifetime_gold_earned']) * 0.10);

		echo json_encode(array(
			'status' => 'success',
			'class_type' => (string)$cmd['class_type'],
			'level' => (int)$cmd['level'],
			'life_levels' => $life_levels,
			'reincarnation_count' => (int)$cmd['reincarnation_count'],
			'projected_reincarnation_count' => (int)$cmd['reincarnation_count'] + 1,
			'current_stat_bonus' => (int)$cmd['reincarnation_stat_bonus'],
			'new_stat_bonus_gain' => $new_bonus_gain,
			'projected_stat_bonus_total' => $new_stat_bonus_total,
			'projected_level_total' => $new_level_total,
			'lifetime_gold_earned' => (int)$cmd['lifetime_gold_earned'],
			'gold_bonus' => $gold_bonus,
			'base_gold_after_reincarnate' => 1000,
			'projected_start_gold' => 1000 + $gold_bonus,
			'kept_systems' => '유물/내실 강화 유지, 영웅/출전 덱/파견 초기화'
		));
	} catch (Exception $e) {
		json_error($e->getMessage());
	}
}

function handle_reincarnate(PDO $pdo) {
	app_log('handle_reincarnate.start');
	$uid = get_uid_or_fail();
	try {
		$pdo->beginTransaction();
		$stmt = $pdo->prepare("SELECT uid, nickname, level, lifetime_gold_earned, reincarnation_count, reincarnation_level_total, reincarnation_stat_bonus, is_combat FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$stmt->execute(array($uid));
		$cmd = $stmt->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['is_combat'] === 1) throw new Exception('전투 중에는 환생할 수 없습니다.');

		$life_levels = max(0, (int)$cmd['level'] - 1);
		$new_bonus_gain = $life_levels;
		$new_reincarnation_count = max(0, (int)$cmd['reincarnation_count']) + 1;
		$new_level_total = max(0, (int)$cmd['reincarnation_level_total']) + $life_levels;
		$new_stat_bonus_total = max(0, (int)$cmd['reincarnation_stat_bonus']) + $new_bonus_gain;

		$gold_bonus = (int)floor(max(0, (int)$cmd['lifetime_gold_earned']) * 0.10);
		$new_gold = 1000 + $gold_bonus;

		$_SESSION['reincarnation_pending'] = array(
			'uid' => (int)$uid,
			'nickname' => (string)$cmd['nickname'],
			'new_reincarnation_count' => (int)$new_reincarnation_count,
			'new_level_total' => (int)$new_level_total,
			'new_stat_bonus_total' => (int)$new_stat_bonus_total,
			'new_bonus_gain' => (int)$new_bonus_gain,
			'life_levels' => (int)$life_levels,
			'gold_bonus' => (int)$gold_bonus,
			'start_gold' => (int)$new_gold,
			'created_at' => time(),
		);

		$pdo->commit();

		echo json_encode(array(
			'status' => 'success',
			'log' => '♻️ 환생 준비가 완료되었습니다. 재탄생 생성창에서 직업과 주사위를 확정하세요.',
			'redirect_url' => 'character_create.php?mode=reincarnation',
			'projected_start_gold' => $new_gold,
			'projected_new_bonus_gain' => $new_bonus_gain,
			'reincarnation_count' => $new_reincarnation_count,
			'reincarnation_level_total' => $new_level_total,
			'reincarnation_stat_bonus' => $new_stat_bonus_total,
			'pending' => true,
		));
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
	case 'stream_ai': json_error('탐색 AI는 비활성화되었습니다.'); break;
	case 'stream_combat_ai': handle_stream_combat_ai($pdo); break;
	case 'stream_story_ai': handle_stream_story_ai($pdo); break;
	case 'ranking': handle_ranking($pdo); break;
	case 'restart': handle_restart($pdo); break;
	case 'reincarnate_preview': handle_reincarnate_preview($pdo); break;
	case 'reincarnate': handle_reincarnate($pdo); break;
	case 'skill': handle_skill($pdo); break;
	case 'stat_up': handle_stat_up($pdo); break;
	case 'summon': handle_summon($pdo); break;
	case 'synthesize': handle_synthesize($pdo); break;
	case 'hero_levelup_view': handle_hero_levelup_view($pdo); break;
	case 'hero_levelup': handle_hero_levelup($pdo); break;
	case 'relic_info': handle_relic_info($pdo); break;
	case 'relic_upgrade': handle_relic_upgrade($pdo); break;
	case 'item_info': handle_item_info($pdo); break;
	case 'item_buy': handle_item_buy($pdo); break;
	case 'item_use': handle_item_use($pdo); break;
	case 'item_toggle_equip': handle_item_toggle_equip($pdo); break;
	case 'item_synthesize': handle_item_synthesize($pdo); break;
	case 'progression_upgrade': handle_progression_upgrade($pdo); break;
	case 'blessing_reroll': handle_blessing_reroll($pdo); break;
	case 'get_progression_state': handle_get_progression_state($pdo); break;
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

