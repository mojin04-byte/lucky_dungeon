<?php
// ==================================================================
// API 메인 진입점 (복구본)
// ==================================================================

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

$GLOBALS['colors'] = array('일반'=>'#aaa', '희귀'=>'#4caf50', '영웅'=>'#2196f3', '전설'=>'#9c27b0', '신화'=>'#ff5252', '불멸'=>'#ffeb3b', '유일'=>'#00e5ff');

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

function generate_hero_lists($heroes) {
	$deck_html = '';
	$inv_html = '';
	$deck_count = 0;

	foreach ($heroes as $h) {
		$color = isset($GLOBALS['colors'][$h['hero_rank']]) ? $GLOBALS['colors'][$h['hero_rank']] : '#fff';
		$card = "<div style='background:#222; padding:10px; margin-bottom:5px; border-radius:4px; border-left:3px solid {$color}; display:flex; justify-content:space-between; align-items:center;'>";
		$card .= "<div><span style='color:{$color}; font-size:0.8rem;'>[{$h['hero_rank']}]</span> <span style='font-weight:bold;'>{$h['hero_name']}</span> (x{$h['quantity']})</div>";

		if ((int)$h['is_equipped'] === 1 && (int)$h['is_on_expedition'] === 0) {
			$card .= "<button class='btn' style='padding:5px 10px; font-size:0.8rem; background:#555;' onclick='toggleEquip({$h['inv_id']}, -1)'>해제</button></div>";
			$deck_html .= $card;
			$deck_count += max(1, (int)$h['quantity']);
		} else {
			if ((int)$h['is_on_expedition'] === 1) {
				$card .= "<span style='color:#ff9800; font-size:0.8rem; font-weight:bold;'>[파견중]</span></div>";
			} else {
				$card .= "<button class='btn' style='padding:5px 10px; font-size:0.8rem;' onclick='toggleEquip({$h['inv_id']}, 1)'>출전</button></div>";
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
		if (!$is_passive && $chance > 0 && rand(1, 100) > $chance) continue;

		$effects = get_skill_effects_for_level($skill_def, $skill_level);
		foreach ($effects as $eff) {
			apply_dynamic_effect($eff, $skill_def, $hero, $hero_count, (int)$base_damage, (bool)$is_critical, $cmd, $new_mob_hp, $logs, $total_gold_gain);
		}
	}
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
		$event_roll = rand(1, 100);

		$resp = array('status' => 'safe');
		$log = '';

		if ($event_roll <= 28) {
			// 전투 조우
			if (rand(1, 100) <= 12) {
				$new_floor += 1;
				$max_floor = max($max_floor, $new_floor);
			}
			$diff = pow(2, floor(($new_floor - 1) / 10));
			$is_boss = ($new_floor % 10 === 0);
			if ($is_boss) {
				$bosses = array('거대 고기 슬라임', '폭주 로봇', '심연의 뱀파이어', '파괴자 나하투', '고대 드래곤', '리치 왕');
				$mob_name = '[보스] ' . $bosses[array_rand($bosses)];
				$mob_max_hp = (40 + rand(0, 20)) * $diff * 10;
				$mob_atk = (8 + rand(0, 5)) * $diff;
			} else {
				$mobs = array('고블린', '스켈레톤', '오크', '슬라임', '늑대인간', '동굴거미', '미믹', '광신도');
				$mob_name = (($new_floor > 50) ? '타락한 ' : '굶주린 ') . $mobs[array_rand($mobs)];
				$mob_max_hp = (40 + rand(0, 20)) * $diff;
				$mob_atk = (8 + rand(0, 5)) * $diff;
			}

			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, is_combat = 1, mob_name = ?, mob_hp = ?, mob_max_hp = ?, mob_atk = ? WHERE uid = ?")
				->execute(array($new_floor, $max_floor, $mob_name, $mob_max_hp, $mob_max_hp, $mob_atk, $uid));
			$resp['status'] = 'encounter';
			$resp['mob_name'] = $mob_name;
			$resp['mob_max_hp'] = $mob_max_hp;
			$log = "⚔️ <b>[{$mob_name}]</b> 출현!";
		} else {
			// 안전 이벤트
			$hp = (int)$cmd['hp'];
			$max_hp = (int)$cmd['max_hp'];
			$mp = (int)$cmd['mp'];
			$max_mp = (int)$cmd['max_mp'];

			if ($event_roll <= 48) {
				$new_floor += 1;
				$max_floor = max($max_floor, $new_floor);
				$hp = min($max_hp, $hp + 10);
				$log = "👣 <b>[층 이동]</b> 지하 {$new_floor}층으로 내려갑니다.";
			} elseif ($event_roll <= 68) {
				$gold = rand(20, 120) * max(1, floor($current_floor / 2));
				$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($gold, $uid));
				$log = "💰 <b>[획득]</b> {$gold}G를 발견했습니다.";
			} elseif ($event_roll <= 84) {
				$heal = rand(8, 20);
				$hp = min($max_hp, $hp + $heal);
				$log = "💚 <b>[회복]</b> 체력 +{$heal}";
			} else {
				$dmg = rand(5, 15);
				$hp = max(1, $hp - $dmg);
				$log = "🩸 <b>[함정]</b> 체력 -{$dmg}";
			}

			$pdo->prepare("UPDATE tb_commanders SET current_floor = ?, max_floor = ?, hp = ?, mp = ? WHERE uid = ?")
				->execute(array($new_floor, $max_floor, $hp, $mp, $uid));

			$resp['new_hp'] = $hp;
			$resp['max_hp'] = $max_hp;
			$resp['new_mp'] = $mp;
			$resp['max_mp'] = $max_mp;
			$resp['new_floor'] = $new_floor;
		}

		$resp['log'] = $log;
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();
		echo json_encode($resp);
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error('탐색 중 오류가 발생했습니다.');
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

		$deck_stmt = $pdo->prepare("SELECT hero_rank, hero_name, MAX(level) AS level, SUM(quantity) AS equipped_count FROM tb_heroes WHERE uid = ? AND is_equipped = 1 AND quantity > 0 GROUP BY hero_rank, hero_name");
		$deck_stmt->execute(array($uid));
		$deck = $deck_stmt->fetchAll();

		$p_str = (int)$cmd['stat_str'];
		$p_mag = (int)$cmd['stat_mag'];
		$p_agi = (int)$cmd['stat_agi'];
		$p_luk = (int)$cmd['stat_luk'];
		$p_men = (int)$cmd['stat_men'];
		$p_vit = (int)$cmd['stat_vit'];

		$relic_stmt = $pdo->prepare("SELECT atk_bonus_percent FROM tb_relics WHERE uid = ? LIMIT 1");
		$relic_stmt->execute(array($uid));
		$relic = $relic_stmt->fetch();
		$relic_atk_bonus = (int)(isset($relic['atk_bonus_percent']) ? $relic['atk_bonus_percent'] : 0);

		$crit_chance = floor($p_luk / 2);
		$crit_mult = 1.5 + ($p_luk * 0.01);
		$men_mult = 1 + ($p_men * 0.005);
		$agi_double_chance = floor($p_agi / 5);
		$vit_block_chance = floor($p_vit / 5);

		$player_base = ($p_str * 2) + floor($p_mag / 2) + rand(5, 15);
		if ($relic_atk_bonus > 0) $player_base = (int)floor($player_base * (1 + ($relic_atk_bonus / 100)));
		$is_crit = (rand(1, 100) <= $crit_chance);
		$player_dmg = $is_crit ? floor($player_base * $crit_mult) : $player_base;
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
					$hero_count = max(1, (int)$hero['equipped_count']);
					$hero_dmg = rand($r[0], $r[1]) * $hero_count;
					$hero_dmg = (int)floor($hero_dmg * $men_mult);

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

		if ($total_gold_gain > 0) {
			$pdo->prepare("UPDATE tb_commanders SET gold = gold + ? WHERE uid = ?")->execute(array($total_gold_gain, $uid));
		}

		if ($new_mob_hp <= 0) {
			$logs[] = "🏆 <b>{$cmd['mob_name']}</b>(이)가 쓰러졌습니다!";
			unset($_SESSION['combat_state']);
			$status = 'victory';
		} else {
			$stun_turns = isset($_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left']) ? (int)$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] : 0;
			if ($stun_turns > 0) {
				$logs[] = "🧊 <b>{$cmd['mob_name']}</b>은(는) 상태이상으로 행동하지 못했습니다.";
				$_SESSION['combat_state']['enemy_debuffs']['stun']['turns_left'] = max(0, $stun_turns - 1);
				$status = 'ongoing';
			} elseif (rand(1, 100) <= $vit_block_chance) {
				$logs[] = "🛡️ <span style='color:orange; font-weight:bold;'>[VIT 특성 발동]</span> 사령관이 공격을 막아냈습니다!";
				$status = 'ongoing';
			} else {
				$mob_dmg = max(1, (int)$cmd['mob_atk'] - floor($p_vit / 2));
				$logs[] = "🩸 <b>{$cmd['mob_name']}</b>의 반격! <span style='color:#ff5252;'>{$mob_dmg}</span> 피해.";
				$new_hp = max(0, $new_hp - $mob_dmg);
				if ($new_hp <= 0) {
					$logs[] = "💀 사령관이 쓰러졌습니다...";
					unset($_SESSION['combat_state']);
					$status = 'defeat';
				} else {
					$status = 'ongoing';
				}
			}
		}

		if ($status === 'victory' || $status === 'defeat') {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, is_combat = 0, mob_name = '', mob_hp = 0, mob_max_hp = 0, mob_atk = 0 WHERE uid = ?")
				->execute(array($new_hp, $uid));
		} else {
			$pdo->prepare("UPDATE tb_commanders SET hp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mob_hp, $uid));
		}

		$final_log = implode('<br>', $logs);
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $final_log));
		$pdo->commit();

		echo json_encode(array(
			'status' => $status,
			'stream' => false,
			'logs' => $logs,
			'new_hp' => $new_hp,
			'max_hp' => (int)$cmd['max_hp'],
			'new_mp' => (int)$cmd['mp'],
			'max_mp' => (int)$cmd['max_mp'],
			'mob_hp' => $new_mob_hp,
			'mob_max_hp' => (int)$cmd['mob_max_hp']
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
		'shield_up' => array('name' => '방어강화', 'cost' => 20, 'type' => 'buff', 'effect' => 'vit', 'value' => 10, 'duration' => 3),
		'berserk' => array('name' => '광폭화', 'cost' => 35, 'type' => 'buff', 'effect' => 'str', 'value' => 15, 'duration' => 3),
	);
	if (!isset($skills[$skill_id])) { json_error('알 수 없는 스킬입니다.'); return; }
	$skill = $skills[$skill_id];

	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT * FROM tb_commanders WHERE uid = ? FOR UPDATE");
		$st->execute(array($uid));
		$cmd = $st->fetch();
		if (!$cmd) throw new Exception('유저 정보 없음');
		if ((int)$cmd['is_combat'] === 0) throw new Exception('전투 중에만 스킬 사용 가능');
		if ((int)$cmd['mp'] < (int)$skill['cost']) throw new Exception('MP가 부족합니다.');

		$new_mp = (int)$cmd['mp'] - (int)$skill['cost'];
		$new_hp = (int)$cmd['hp'];
		$new_mob_hp = (int)$cmd['mob_hp'];
		$logs = array("🔮 <b>[{$skill['name']}]</b> 시전! (MP -{$skill['cost']})");

		if ($skill['type'] === 'damage') {
			$damage = (int)$skill['value'] + floor((int)$cmd['stat_mag'] * 1.5);
			$new_mob_hp = max(0, $new_mob_hp - $damage);
			$logs[] = "💥 몬스터에게 <span style='color:red;'>{$damage}</span> 피해.";
		} elseif ($skill['type'] === 'heal') {
			$heal = (int)$skill['value'] + floor((int)$cmd['stat_men'] * 2);
			$new_hp = min((int)$cmd['max_hp'], $new_hp + $heal);
			$logs[] = "💚 체력을 <span style='color:lightgreen;'>{$heal}</span> 회복.";
		} else {
			if (!isset($_SESSION['combat_state']['player_buffs'])) $_SESSION['combat_state']['player_buffs'] = array();
			$_SESSION['combat_state']['player_buffs'][$skill['effect']] = array('value' => (int)$skill['value'], 'turns_left' => (int)$skill['duration']);
			$logs[] = '✨ 신체 능력이 일시적으로 강화됩니다!';
		}

		$pdo->prepare("UPDATE tb_commanders SET hp = ?, mp = ?, mob_hp = ? WHERE uid = ?")->execute(array($new_hp, $new_mp, $new_mob_hp, $uid));
		$pdo->commit();
		echo json_encode(array('status' => 'success', 'logs' => $logs, 'new_hp' => $new_hp, 'max_hp' => (int)$cmd['max_hp'], 'new_mp' => $new_mp, 'max_mp' => (int)$cmd['max_mp'], 'mob_hp' => $new_mob_hp));
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
		if ((int)$cmd['gold'] < $summon_cost) throw new Exception('마력석(Gold)이 부족합니다.');
		$pdo->prepare("UPDATE tb_commanders SET gold = gold - ? WHERE uid = ?")->execute(array($summon_cost, $uid));

		$weights = array('신화' => 1, '전설' => 4, '영웅' => 10, '희귀' => 25, '일반' => 60);
		$roll = rand(1, 100);
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
			foreach ($hero_data as $name => $def) { $pool[] = $name; }
			$rank = isset($hero_data[$pool[0]]['rank']) ? $hero_data[$pool[0]]['rank'] : '일반';
		}
		$hero_name = $pool[array_rand($pool)];

		$find = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? FOR UPDATE");
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
	$rank_order = array('일반', '희귀', '영웅', '전설', '신화', '불멸', '유일');

	try {
		$pdo->beginTransaction();
		$st = $pdo->prepare("SELECT inv_id, hero_rank, quantity FROM tb_heroes WHERE uid = ? AND hero_name = ? AND quantity > 0 AND is_equipped = 0 AND is_on_expedition = 0 FOR UPDATE");
		$st->execute(array($uid, $hero_name));
		$source = $st->fetch();
		if (!$source || (int)$source['quantity'] < 3) throw new Exception('합성에 필요한 영웅 수량(3)이 부족합니다.');

		$idx = array_search($source['hero_rank'], $rank_order, true);
		if ($idx === false || $idx >= count($rank_order) - 1) throw new Exception('더 이상 합성할 수 없는 등급입니다.');
		$next_rank = $rank_order[$idx + 1];

		$remain = (int)$source['quantity'] - 3;
		if ($remain > 0) $pdo->prepare("UPDATE tb_heroes SET quantity = ? WHERE inv_id = ?")->execute(array($remain, $source['inv_id']));
		else $pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($source['inv_id']));

		$pool = array();
		foreach ($hero_data as $nm => $def) if (isset($def['rank']) && $def['rank'] === $next_rank) $pool[] = $nm;
		if (empty($pool)) throw new Exception("다음 등급({$next_rank})에 해당하는 영웅이 없습니다.");
		$new_hero_name = $pool[array_rand($pool)];

		$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? FOR UPDATE");
		$chk->execute(array($uid, $new_hero_name));
		$exists = $chk->fetch();
		if ($exists) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($exists['inv_id']));
		else {
			$lore = isset($hero_data[$new_hero_name]['hero_lore']) ? $hero_data[$new_hero_name]['hero_lore'] : '';
			$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, level, hero_lore) VALUES (?, ?, ?, 1, 1, ?)")->execute(array($uid, $next_rank, $new_hero_name, $lore));
		}
		$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $new_hero_name));

		$all = $pdo->prepare("SELECT * FROM tb_heroes WHERE uid = ? AND quantity > 0 ORDER BY is_equipped DESC, hero_rank DESC, hero_name ASC");
		$all->execute(array($uid));
		$heroes = $all->fetchAll();
		list($deck_html, $inv_html, $deck_count) = generate_hero_lists($heroes);

		$gold_st = $pdo->prepare("SELECT gold FROM tb_commanders WHERE uid = ?");
		$gold_st->execute(array($uid));
		$current_gold = (int)$gold_st->fetchColumn();

		$pdo->commit();
		$msg = "🧬 <b>{$hero_name}</b> 3마리를 합성하여 <span style='color:#ffeb3b; font-weight:bold;'>[{$next_rank}] {$new_hero_name}</span> 획득!";
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
		$pdo->prepare("UPDATE tb_commanders SET gold = gold + ?, exp = exp + ?, auto_explore_start_time = NULL WHERE uid = ?")->execute(array($gold, $exp, $uid));
		$log = "🏕️ <b>[자동 탐험 종료]</b> {$minutes}분 탐험. <span style='color:#ffd700;'>{$gold}G</span>, <span style='color:#b388ff;'>{$exp}XP</span> 획득!";
		$pdo->prepare("INSERT INTO tb_logs (uid, log_text) VALUES (?, ?)")->execute(array($uid, $log));
		$pdo->commit();

		echo json_encode(array('status' => 'success', 'log' => $log, 'rewards' => array('gold' => $gold, 'exp' => $exp)));
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
					$pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($inv_id));
					$lore = isset($hero['hero_lore']) ? $hero['hero_lore'] : '';
					$battle_count = isset($hero['battle_count']) ? (int)$hero['battle_count'] : 0;
					$level = isset($hero['level']) ? (int)$hero['level'] : 1;
					$pdo->prepare("INSERT INTO tb_heroes (uid, hero_rank, hero_name, quantity, battle_count, is_equipped, is_on_expedition, level, hero_lore) VALUES (?, ?, ?, 1, ?, 1, 0, ?, ?)")
						->execute(array($uid, $hero['hero_rank'], $hero['hero_name'], $battle_count, $level, $lore));
				} else {
					$pdo->prepare("UPDATE tb_heroes SET is_equipped = 1 WHERE inv_id = ?")->execute(array($inv_id));
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
					$pdo->prepare("UPDATE tb_heroes SET is_equipped = 0 WHERE inv_id = ?")->execute(array($inv_id));
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

	$evolution_recipes = array('개구리 왕자 (▶ 킹 다이안)' => '사신 다이안 (사신 개구리 승천형)');

	try {
		if ($mode === 'view') {
			$st = $pdo->prepare("SELECT hero_name, hero_rank, quantity, level, inv_id FROM tb_heroes WHERE uid = ? AND quantity > 0");
			$st->execute(array($uid));
			$owned = $st->fetchAll();

			$legendary = array();
			$evolvable = array();
			foreach ($owned as $h) {
				if ($h['hero_rank'] === '전설') $legendary[] = $h;
				if (array_key_exists($h['hero_name'], $evolution_recipes)) $evolvable[] = $h;
			}

			$html = '<div style="background:#222; padding:15px; border-radius:5px; margin-bottom:20px;">';
			$html .= '<h3 style="color:#ff5252; margin-top:0;">신화 조합</h3>';
			$html .= '<p style="color:#ccc; font-size:0.9rem;">고유한 전설 등급 영웅 4명을 소모하여 무작위 신화 영웅 1명을 소환합니다.</p>';
			$html .= '<strong>보유한 전설 영웅:</strong> ' . count($legendary) . '명<br><br>';
			if (count($legendary) >= 4) $html .= '<button class="btn" style="background:#ff5252; width:100%;" onclick="combineHero(\'combine_mythic\', \'\')">신화 조합 실행</button>';
			else $html .= '<button class="btn" style="background:#555; width:100%;" disabled>전설 영웅 부족</button>';
			$html .= '</div><div style="background:#222; padding:15px; border-radius:5px;">';
			$html .= '<h3 style="color:#ffeb3b; margin-top:0;">불멸 진화</h3>';
			if (!empty($evolvable)) {
				foreach ($evolvable as $h) {
					$evolved = $evolution_recipes[$h['hero_name']];
					$html .= "<div style='padding:10px; border:1px solid #444; margin-top:10px; border-radius:4px;'><strong>{$h['hero_name']}</strong> ▶ <strong>{$evolved}</strong><button class='btn' style='background:#ffeb3b; color:#000; float:right;' onclick=\"if(confirm('{$h['hero_name']}을(를) {$evolved}(으)로 진화시키겠습니까?')) combineHero('evolve', '{$h['hero_name']}')\">진화</button></div>";
				}
			} else {
				$html .= '<p style="color:#777; text-align:center;">진화 가능한 영웅이 없습니다.</p>';
			}
			$html .= '</div>';
			echo json_encode(array('status' => 'success', 'html' => $html));
			return;
		}

		if ($mode === 'combine_mythic') {
			$pdo->beginTransaction();
			$st = $pdo->prepare("SELECT inv_id, hero_name, quantity FROM tb_heroes WHERE uid = ? AND hero_rank = '전설' AND quantity > 0 FOR UPDATE");
			$st->execute(array($uid));
			$legs = $st->fetchAll();
			if (count($legs) < 4) throw new Exception('조합에 필요한 전설 영웅이 부족합니다.');

			$consumed = array_slice($legs, 0, 4);
			$names = array();
			foreach ($consumed as $h) {
				$names[] = $h['hero_name'];
				if ((int)$h['quantity'] > 1) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($h['inv_id']));
				else $pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($h['inv_id']));
			}

			$mythic_pool = array();
			foreach ($hero_data as $n => $d) if (isset($d['rank']) && $d['rank'] === '신화') $mythic_pool[] = $n;
			if (empty($mythic_pool)) throw new Exception('신화 영웅 데이터가 없습니다.');
			$new_name = $mythic_pool[array_rand($mythic_pool)];

			$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? FOR UPDATE");
			$chk->execute(array($uid, $new_name));
			$ex = $chk->fetch();
			if ($ex) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($ex['inv_id']));
			else {
				$rank = isset($hero_data[$new_name]['rank']) ? $hero_data[$new_name]['rank'] : '신화';
				$lore = isset($hero_data[$new_name]['hero_lore']) ? $hero_data[$new_name]['hero_lore'] : '';
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_name, hero_rank, quantity, hero_lore, level) VALUES (?, ?, ?, 1, ?, 1)")->execute(array($uid, $new_name, $rank, $lore));
			}
			$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $new_name));
			$pdo->commit();

			$msg = "✨ 전설 영웅 (" . implode(', ', $names) . ") 4명을 조합하여 <span style='color:#ff5252; font-weight:bold;'>[신화] {$new_name}</span> 획득!";
			echo json_encode(array('status' => 'success', 'msg' => $msg, 'new_rank' => '신화', 'new_name' => $new_name));
			return;
		}

		if ($mode === 'evolve') {
			if (!array_key_exists($target_name, $evolution_recipes)) throw new Exception('알 수 없는 진화 레시피입니다.');
			$evolved_name = $evolution_recipes[$target_name];
			$pdo->beginTransaction();
			$st = $pdo->prepare("SELECT inv_id, quantity FROM tb_heroes WHERE uid = ? AND hero_name = ? AND quantity > 0 FOR UPDATE");
			$st->execute(array($uid, $target_name));
			$src = $st->fetch();
			if (!$src) throw new Exception("진화에 필요한 영웅({$target_name})이 없습니다.");
			if ((int)$src['quantity'] > 1) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity - 1 WHERE inv_id = ?")->execute(array($src['inv_id']));
			else $pdo->prepare("DELETE FROM tb_heroes WHERE inv_id = ?")->execute(array($src['inv_id']));

			$chk = $pdo->prepare("SELECT inv_id FROM tb_heroes WHERE uid = ? AND hero_name = ? FOR UPDATE");
			$chk->execute(array($uid, $evolved_name));
			$ex = $chk->fetch();
			if ($ex) $pdo->prepare("UPDATE tb_heroes SET quantity = quantity + 1 WHERE inv_id = ?")->execute(array($ex['inv_id']));
			else {
				$rank = isset($hero_data[$evolved_name]['rank']) ? $hero_data[$evolved_name]['rank'] : '불멸';
				$lore = isset($hero_data[$evolved_name]['hero_lore']) ? $hero_data[$evolved_name]['hero_lore'] : '';
				$pdo->prepare("INSERT INTO tb_heroes (uid, hero_name, hero_rank, quantity, hero_lore, level) VALUES (?, ?, ?, 1, ?, 1)")->execute(array($uid, $evolved_name, $rank, $lore));
			}
			$pdo->prepare("INSERT IGNORE INTO tb_collection (uid, hero_name) VALUES (?, ?)")->execute(array($uid, $evolved_name));
			$pdo->commit();

			$msg = "🔮 <span style='color:#ff5252;'>{$target_name}</span> → <span style='color:#ffeb3b; font-weight:bold;'>[불멸] {$evolved_name}</span> 진화!";
			echo json_encode(array('status' => 'success', 'msg' => $msg, 'new_rank' => '불멸', 'new_name' => $evolved_name));
			return;
		}

		json_error('알 수 없는 조합 모드');
	} catch (Exception $e) {
		if ($pdo->inTransaction()) $pdo->rollBack();
		json_error($e->getMessage());
	}
}

function render_hero_levelup_html($heroes) {
	if (empty($heroes)) return "<div style='color:#777; text-align:center; padding:10px;'>강화할 영웅이 없습니다.</div>";
	$html = "<div style='display:grid; gap:8px;'>";
	foreach ($heroes as $h) {
		$lv = (int)$h['level'];
		$next_lv = min(15, $lv + 1);
		$cost = $lv * 120;
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

		$cost = $lv * 120;
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

