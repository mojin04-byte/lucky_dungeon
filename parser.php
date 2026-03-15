<?php
// parser.php

// 파싱 함수 추가
function parse_skill_details($description) {
    $details = [];
    $effects = [];

    // --- Type 파싱 ---
    if (strpos($description, '(궁극기)') !== false) {
        $details['type'] = 'ultimate';
    } elseif (strpos($description, '소환 시') !== false) {
        $details['type'] = 'on_summon';
    } elseif (preg_match('/(\d+)번째 공격마다/', $description, $m)) {
        $details['type'] = 'on_attack_nth';
        $details['nth'] = (int)$m[1];
    } elseif (strpos($description, '공격 시') !== false || strpos($description, '타격 시') !== false) {
        $details['type'] = 'on_attack';
    } elseif (strpos($description, '모든 적') !== false || strpos($description, '아군 전체') !== false || strpos($description, '주변 아군') !== false) {
        $details['type'] = 'passive_aura';
    } else {
        $details['type'] = 'passive_buff';
    }

    // --- Trigger Chance 파싱 ---
    if (preg_match('/(\d+)% 확률/', $description, $m)) {
        $details['trigger_chance'] = (int)$m[1];
    }

    // --- Effects 파싱 (간단한 키워드 기반) ---
    // 데미지
    if (preg_match('/공격력의 ([\d,]+)% (물리|마법)피해/', $description, $m)) {
        $damage_type = (strpos($description, '범위') !== false) ? 'damage_aoe_' : 'damage_single_';
        $damage_type .= ($m[2] === '물리') ? 'physical' : 'magic';
        $effects[] = ['type' => $damage_type, 'value' => (int)str_replace(',', '', $m[1])];
    } elseif (preg_match('/([\d,]+)%의 (방어력 무시 추가 )?고정 피해/', $description, $m)) {
        $effects[] = ['type' => 'true_damage_fixed', 'value' => (int)str_replace(',', '', $m[1])];
    }


    // 기절
    if (preg_match('/([\d\.]+)초 간 기절/', $description, $m)) {
        $effects[] = ['type' => 'stun', 'duration' => (float)$m[1]];
    }

    // 빙결
    if (preg_match('/([\d\.]+)초 간 빙결/', $description, $m)) {
        $effects[] = ['type' => 'freeze', 'duration' => (float)$m[1]];
    }
    
    // 이동속도 감소
    if (preg_match('/이동속도를 ([\d\.]+) 둔화/', $description, $m)) {
        $effects[] = ['type' => 'slow_enemies_flat', 'value' => (float)$m[1]];
    }
    
    // 골드 획득
    if (preg_match('/코인을 ([\d\.]+) 획득/', $description, $m)) {
        $effects[] = ['type' => 'add_gold', 'value' => (int)$m[1]];
    }
    
    // 방어력 감소
    if (preg_match('/방어력을 ([\d\.]+) 감소/', $description, $m)) {
        $value = (int)$m[1];
        if (strpos($description, '모든 적') !== false) {
             $effects[] = ['type' => 'shred_armor_flat_aura', 'value' => $value];
        } else {
             $effects[] = ['type' => 'shred_armor_flat_single', 'value' => $value];
        }
    }

    if (!empty($effects)) {
        $details['effects'] = $effects;
    }

    return $details;
}


$inputFile = 'skills.txt';
$outputFile = 'config/hero_data.php';

$lines = file($inputFile, FILE_IGNORE_NEW_LINES);

$heroes = [];
$currentRank = null;
$currentHero = null;

$rankMap = [
    '일반 (Common)' => '일반',
    '🔵 희귀 (Rare)' => '희귀',
    '🟣 영웅 (Epic)' => '영웅',
    '🟡 전설 (Legend)' => '전설',
    '🔴 신화 (Mythic)' => '신화',
    '🟤 불멸 (Immortal)' => '불멸',
];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    if (isset($rankMap[$line])) {
        $currentRank = $rankMap[$line];
        $currentHero = null;
        continue;
    }

    if (strpos($line, '•') === 0) {
        if ($currentHero) {
            $line = trim(substr($line, strpos($line, '•') + 1));
            preg_match('/\[(.*?)\]\s*(.*)/', $line, $matches);
            if (count($matches) === 3) {
                $skillName = trim($matches[1]);
                $skillDescription = trim($matches[2]);
                
                // 스킬 상세 정보 파싱
                $new_skill_details = parse_skill_details($skillDescription);

                $heroes[$currentHero]['skills'][] = array_merge([
                    'name' => $skillName,
                    'description' => $skillDescription,
                ], $new_skill_details);
            }
        }
    } 
    else {
        $candidateHero = trim($line);

        // 방어 로직: 스킬 문장/괄호문/명백한 설명문은 영웅명으로 취급하지 않음
        $name_len = function_exists('mb_strlen') ? mb_strlen($candidateHero, 'UTF-8') : strlen($candidateHero);

        if (
            strpos($candidateHero, '[') === 0 ||
            strpos($candidateHero, ']') !== false ||
            strpos($candidateHero, '(궁극기)') !== false ||
            $name_len > 30
        ) {
            continue;
        }

        $currentHero = $candidateHero;
        if (!isset($heroes[$currentHero])) {
            $heroes[$currentHero] = [
                'rank' => $currentRank,
                'skills' => []
            ];
        }
    }
}

$existing_heroes = [];
if (file_exists($outputFile)) {
    $existing_heroes = require $outputFile;
}

foreach($heroes as $name => $data) {
    if(isset($existing_heroes[$name])) {
       foreach($data['skills'] as $i => $new_skill) {
           $found = false;
           if (!isset($existing_heroes[$name]['skills'])) {
                $existing_heroes[$name]['skills'] = [];
           }
           foreach($existing_heroes[$name]['skills'] as $j => $existing_skill) {
               if($existing_skill['name'] === $new_skill['name']) {
                   // 기존 상세 속성은 유지하고, 파싱된 정보로 보강
                   $updated_skill = array_merge($existing_skill, $new_skill);
                   $updated_skill['description'] = $new_skill['description']; // 설명은 항상 최신으로
                   $existing_heroes[$name]['skills'][$j] = $updated_skill;

                   $found = true;
                   break;
               }
           }
           if (!$found) {
               $existing_heroes[$name]['skills'][] = $new_skill;
           }
       }
    } else {
        $existing_heroes[$name] = $data;
    }
}


$outputContent = "<?php

return " . var_export($existing_heroes, true) . ";
";
file_put_contents($outputFile, $outputContent);

echo "Hero data has been successfully parsed and updated in {$outputFile}
";
