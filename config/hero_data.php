<?php

return array (
  '산적' => 
  array (
    'rank' => '일반',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '약탈',
        'description' => '소환 시 코인을 10 획득합니다.',
        'type' => 'on_summon',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'add_gold',
            'value' => 10,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '노획',
        'description' => '공격 시 5% 확률로 코인을 10 획득합니다. (Lv.6 증폭 적용)',
        'type' => 'on_attack',
        'trigger_chance' => 5,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'add_gold',
            'value' => 10,
          ),
        ),
        'level_up' => 
        array (
          6 => 
          array (
            'description' => '획득 코인 100% 증가',
            'effects' => 
            array (
              0 => 
              array (
                'type' => 'add_gold',
                'value' => 20,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
  '물의정령' => 
  array (
    'rank' => '일반',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '이동속도 감소',
        'description' => '모든 적의 이동속도를 1.5 둔화시킵니다. (Lv.12 증폭 적용)',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 1.5,
          ),
        ),
        'level_up' => 
        array (
          12 => 
          array (
            'description' => '둔화 효과 50% 증폭',
            'effects' => 
            array (
              0 => 
              array (
                'type' => 'slow_enemies_flat',
                'value' => 2.25,
              ),
            ),
          ),
        ),
      ),
      1 => 
      array (
        'name' => '물대포',
        'description' => '보스에게 입히는 피해가 100% 증가합니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_to_boss_increase_percentage',
            'value' => 100,
          ),
        ),
      ),
    ),
  ),
  '여왕 콜디' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '얼음 폭풍',
        'description' => '공격 시 25% 확률로 거대한 얼음 폭풍을 일으켜 5000% 마법피해 및 2초 간 완벽히 빙결시킵니다.',
        'type' => 'on_attack',
        'trigger_chance' => 25,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 5000,
          ),
          1 => 
          array (
            'type' => 'freeze_aoe',
            'duration' => 2,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '혹한의 오라',
        'description' => '모든 적의 이동속도를 20 감소시키고, 적들의 빙결 저항력을 50% 깎아냅니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 20,
          ),
          1 => 
          array (
            'type' => 'decrease_enemy_freeze_resistance',
            'value' => 50,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '얼음 파편',
        'description' => '빙결 상태인 적을 타격 시마다 1000%의 방어력 무시 추가 고정 피해가 들어갑니다.',
        'type' => 'on_attack',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'true_damage_fixed',
            'value' => 1000,
          ),
        ),
      ),
      3 => 
      array (
        'name' => '아이스 에이지',
        'description' => '(궁극기) 5초 동안 필드 전체를 꽁꽁 얼려버려 완전 빙결시키고 매초 10000% 마법피해를 입힙니다.',
        'type' => 'ultimate',
        'duration' => 5,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'freeze_all_enemies',
            'duration' => 5,
          ),
          1 => 
          array (
            'type' => 'damage_all_enemies_per_second_magic',
            'value' => 10000,
          ),
        ),
      ),
    ),
  ),
  '야만인' => 
  array (
    'rank' => '일반',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '강타',
        'description' => '3번째 공격마다 무조건 치명타가 발생합니다.',
        'type' => 'on_attack_nth',
        'nth' => 3,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'guaranteed_critical',
          ),
        ),
      ),
      1 => 
      array (
        'name' => '치명타 증폭',
        'description' => '치명타 피해량이 50% 증가합니다. (Lv.12 증폭 적용)',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'critical_damage_increase',
            'value' => 50,
          ),
        ),
        'level_up' => 
        array (
          12 => 
          array (
            'description' => '치명타 피해량 50% 추가 증가',
            'effects' => 
            array (
              0 => 
              array (
                'type' => 'critical_damage_increase',
                'value' => 100,
              ),
            ),
          ),
        ),
      ),
    ),
  ),
  '투척병' => 
  array (
    'rank' => '일반',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '돌팔매',
        'description' => '공격 시 10% 확률로 범위 내 적에게 공격력의 200% 물리피해를 줍니다.',
        'type' => 'on_attack',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_physical',
            'value' => 200,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '연속 투척',
        'description' => '공격 속도가 20% 증가합니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'extra_attack_chance',
            'value' => 20,
          ),
        ),
      ),
    ),
  ),
  '궁수' => 
  array (
    'rank' => '일반',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '독화살',
        'description' => '10% 확률로 적에게 3초 동안 매초 공격력의 100% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 100,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '관통',
        'description' => '공격이 20% 확률로 적을 관통하여 일직선상의 적들에게 피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'piercing_attack',
          ),
        ),
      ),
    ),
  ),
  '악마병사' => 
  array (
    'rank' => '희귀',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '방어력 감소',
        'description' => '모든 적의 방어력을 5 감소시킵니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_aura',
            'value' => 5,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '찌르기',
        'description' => '12% 확률로 범위 내 적에게 공격력의 500% 마법피해를 주고 0.75초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 12,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 500,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 0.75,
          ),
        ),
      ),
    ),
  ),
  '샌드맨' => 
  array (
    'rank' => '희귀',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '모래 뿌리기',
        'description' => '15% 확률로 범위 내 적에게 공격력의 300% 마법피해를 주고 1.5초 간 이동속도를 30 둔화시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 300,
          ),
          1 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 30.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '모래바람',
        'description' => '모래 뿌리기의 이동속도 감소 지속시간이 50% 증가합니다. (Lv.12 증폭 적용)',
        'type' => 'passive_buff',
      ),
    ),
  ),
  '성기사' => 
  array (
    'rank' => '희귀',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '발도',
        'description' => '15% 확률로 범위 내 적에게 공격력의 450% 마법피해를 줍니다. (Lv.6 확률 증가, Lv.12 피해량 증폭 적용)',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 450,
          ),
        ),
      ),
    ),
  ),
  '충격로봇' => 
  array (
    'rank' => '희귀',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '전기 충격',
        'description' => '12% 확률로 범위 내 적에게 공격력의 300% 마법피해를 주고 1.125초 간 기절시킵니다. (Lv.12 기절 시간 50% 증폭 적용)',
        'type' => 'passive_buff',
        'trigger_chance' => 12,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 300,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 1.125,
          ),
        ),
      ),
    ),
  ),
  '레인저' => 
  array (
    'rank' => '희귀',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '연사',
        'description' => '15% 확률로 3발의 화살을 연속으로 발사하여 공격력의 300% 물리피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_physical',
            'value' => 300,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '헤드샷',
        'description' => '연사 적중 시 10% 확률로 적을 즉사시킵니다. (보스 제외)',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
    ),
  ),
  '늑대전사' => 
  array (
    'rank' => '영웅',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '패시브',
        'description' => '아군 전체의 공격력을 10% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      1 => 
      array (
        'name' => '물어뜯기',
        'description' => '10% 확률로 적에게 공격력의 1500% 마법피해를 주고 3초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 1500,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 3.0,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '할퀴기',
        'description' => '공격 시 타격 횟수가 1회 증가합니다. (Lv.12 타격 추가 적용)',
        'type' => 'on_attack',
      ),
    ),
  ),
  '독수리장군' => 
  array (
    'rank' => '영웅',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '패시브',
        'description' => '아군 전체의 공격 속도를 10% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      1 => 
      array (
        'name' => '강풍',
        'description' => '10% 확률로 범위 내 적을 넉백시키고 공격력의 500% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 500,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '폭풍',
        'description' => '강풍 발동 시 적을 2초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
    ),
  ),
  '사냥꾼' => 
  array (
    'rank' => '영웅',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '방어력 감소',
        'description' => '모든 적의 방어력을 15 감소시킵니다. (Lv.12 방깎 50% 증폭 적용)',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_aura',
            'value' => 15,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '그물',
        'description' => '10% 확률로 범위 내 적을 2초 간 속박합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '정조준',
        'description' => '속박된 적에게 입히는 피해가 50% 증가합니다.',
        'type' => 'passive_buff',
      ),
    ),
  ),
  '나무' => 
  array (
    'rank' => '영웅',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '이동속도 감소',
        'description' => '모든 적의 이동속도를 5 둔화시킵니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 5.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '덩굴',
        'description' => '10% 확률로 3초 동안 유지되는 덩굴을 설치합니다. (Lv.6 지속시간 50% 증가 적용)',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '덩굴 효과',
        'description' => '덩굴은 0.5초마다 공격력의 500% 마법피해를 주고 이동속도를 50 둔화시킵니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 500,
          ),
          1 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 50.0,
          ),
        ),
      ),
    ),
  ),
  '전기로봇' => 
  array (
    'rank' => '영웅',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '연쇄번개',
        'description' => '공격 시 최대 5회 연쇄되는 번개로 적에게 공격력의 100% 마법피해를 줍니다. (Lv.12 연쇄 2회 추가 적용)',
        'type' => 'on_attack',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 100,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '감전',
        'description' => '10% 확률로 범위 내 적에게 공격력의 2000% 마법피해를 주고 1초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 2000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 1.0,
          ),
        ),
      ),
    ),
  ),
  '보안관' => 
  array (
    'rank' => '전설',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '속사',
        'description' => '10% 확률로 6초 동안 공격속도가 50% 증가합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '관통탄',
        'description' => '15회 공격마다 일직선상의 적에게 3000% 물리피해를 주고 3회 동안 기본 공격 피해가 100% 증가합니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '헤드샷',
        'description' => '(궁극기) 공격 시 1% 확률로 체력 비례 10% 피해를 줍니다.',
        'type' => 'ultimate',
        'trigger_chance' => 1,
      ),
    ),
  ),
  '폭풍거인' => 
  array (
    'rank' => '전설',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '폭풍우',
        'description' => '10% 확률로 범위 내 적에게 공격력의 2000% 마법피해를 주고 1초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 2000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 1.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '번개 강타',
        'description' => '15회 공격마다 범위 내 적에게 공격력의 5000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 5000,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '벼락',
        'description' => '(궁극기) 무작위 적 3명에게 벼락을 떨어뜨려 공격력의 10000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 10000,
          ),
        ),
      ),
    ),
  ),
  '호랑이사부' => 
  array (
    'rank' => '전설',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '무술',
        'description' => '15% 확률로 전방의 적들에게 공격력의 3000% 물리피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_physical',
            'value' => 3000,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '기공파',
        'description' => '20회 공격마다 전방으로 기공파를 날려 공격력의 8000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 8000,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '호포',
        'description' => '(궁극기) 거대한 호랑이 기운을 날려 범위 내 적에게 공격력의 20000% 마법피해를 주고 2초 간 기절시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 20000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
    ),
  ),
  '워머신' => 
  array (
    'rank' => '전설',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '방어력 감소',
        'description' => '모든 적의 방어력을 10 감소시킵니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_aura',
            'value' => 10,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '에너지 파장',
        'description' => '8% 확률로 범위 내 적에게 공격력의 2000% 마법피해를 주고 1.5초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 8,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 2000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 1.5,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '화염 방사',
        'description' => '(궁극기) 1초 동안 전방에 화염을 뿜어 0.1초마다 공격력의 600% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 600,
          ),
        ),
      ),
    ),
  ),
  '닌자' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '수리검',
        'description' => '공격 시 2개의 수리검을 던져 각각 공격력의 150% 물리피해를 줍니다.',
        'type' => 'on_attack',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_physical',
            'value' => 150,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '암살',
        'description' => '16.5% 확률로 대상에게 공격력의 3000% 물리피해를 주고 5초 간 기절시킵니다. (Lv.12 발동확률 65% 증폭 적용)',
        'type' => 'passive_buff',
        'trigger_chance' => 5,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_physical',
            'value' => 3000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 5.0,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '그림자 분신',
        'description' => '(궁극기) 5초 동안 본체와 동일한 능력을 가진 그림자 분신을 소환합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '중력자탄' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '중력장',
        'description' => '공격 시 10% 확률로 중력장을 생성하여 적을 끌어당기고 이동속도를 늦춥니다.',
        'type' => 'on_attack',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '사상의 지평선',
        'description' => '중력장 및 블랙홀의 범위와 당기는 힘이 대폭 증가합니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '블랙홀',
        'description' => '(궁극기) 거대한 블랙홀을 생성해 적들을 모으고 5초 간 매초 공격력의 1000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 1000,
          ),
        ),
      ),
    ),
  ),
  '오크주술사' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '저주',
        'description' => '10% 확률로 적의 방어력을 10초 동안 20 감소시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '피의 굶주림',
        'description' => '아군 전체의 공격 속도를 15% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '블러드러스트',
        'description' => '(궁극기) 8초 동안 아군 전체의 공격력을 50%, 공격 속도를 50% 증가시킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '펄스생성기' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '전자기장',
        'description' => '모든 적의 이동속도를 15 둔화시킵니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 15.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => 'EMP',
        'description' => '12% 확률로 범위 내 적을 2초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 12,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '펄스 방출',
        'description' => '(궁극기) 필드 전체의 적에게 공격력의 5000% 마법피해를 주고 3초 간 기절시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 5000,
          ),
          1 => 
          array (
            'type' => 'stun',
            'duration' => 3.0,
          ),
        ),
      ),
    ),
  ),
  '냥법사' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '마나 획득',
        'description' => '주변 1칸 범위 아군의 마나를 매초 30 회복시킵니다. (Lv.12 마나회복량 2배 적용)',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '응원',
        'description' => '주변 아군의 공격력을 20% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '냥냥펀치',
        'description' => '(궁극기) 15% 확률로 냥냥펀치를 날려 공격력의 2000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 2000,
          ),
        ),
      ),
    ),
  ),
  '밤바' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '호랑이 기운',
        'description' => '10초마다 호랑이 기운을 획득하여 공격력이 50% 증가합니다. (최대 5스택 중첩)',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '할퀴기',
        'description' => '15% 확률로 3연속 공격을 가합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '호랑이 춤',
        'description' => '(궁극기) 전방 범위에 공격력의 50000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 50000,
          ),
        ),
      ),
    ),
  ),
  '콜디' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '눈보라',
        'description' => '모든 적의 이동속도를 10 둔화시킵니다.',
        'type' => 'passive_aura',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 10.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '얼음 송곳',
        'description' => '공격 시 15% 확률로 송곳을 날려 2000% 마법피해를 주고 1초 간 빙결시킵니다.',
        'type' => 'on_attack',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'freeze',
            'duration' => 1.0,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '절대영도',
        'description' => '(궁극기) 3초 간 필드 전체에 눈보라를 내려 매초 공격력의 4000% 마법피해를 주고 빙결시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 4000,
          ),
        ),
      ),
    ),
  ),
  '랜슬롯' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '불꽃',
        'description' => '적의 최대 체력 비례 0.6%의 마법피해를 추가로 입힙니다. (Lv.12 비례피해 0.1% 추가 증폭 적용)',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '불꽃 검기',
        'description' => '16% 확률로 검기를 날려 범위 내 적에게 공격력의 2200% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 16,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_aoe_magic',
            'value' => 2200,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '흑점 폭발',
        'description' => '8% 확률로 1500% 피해를 주고 응축된 불꽃을 심어 일정 시간 뒤 1500% 마법피해로 폭발시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 8,
      ),
      3 => 
      array (
        'name' => '불기둥',
        'description' => '(궁극기) 지면을 내리쳐 0.1초마다 공격력의 360% 마법피해를 주는 거대한 불기둥을 생성합니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 360,
          ),
        ),
      ),
    ),
  ),
  '아이언미야옹' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '레이저',
        'description' => '10% 확률로 일직선상의 적에게 공격력의 3000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 3000,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '미사일 폭격',
        'description' => '15회 공격마다 5개의 미사일을 쏴 각각 1000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '위성 타격',
        'description' => '(궁극기) 지정된 좁은 범위에 강력한 위성 타격을 가해 30000% 마법피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '블롭' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '산성액',
        'description' => '공격 시 10% 확률로 적의 방어력을 5초 동안 10 감소시킵니다.',
        'type' => 'on_attack',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '포식',
        'description' => '적을 처치할 때마다 공격력이 0.5%씩 무한하게 증가합니다. (스택 누적)',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '대지진',
        'description' => '(궁극기) 거대한 몸으로 점프하여 범위 내 적에게 15000% 물리피해를 주고 2초 간 기절시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
    ),
  ),
  '드래곤' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '용의 위엄',
        'description' => '필드 내 동물 종족 영웅 1명당 공격력이 30% 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '화염 탄',
        'description' => '8% 확률로 불덩어리를 뿜어 5000% 마법피해를 주고, 2.5초 간 0.5초마다 500% 피해를 주는 불꽃을 남깁니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 8,
      ),
      2 => 
      array (
        'name' => '지면 폭발',
        'description' => '10% 확률로 지면을 폭발시켜 6000% 마법피해를 주고 불꽃을 남깁니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      3 => 
      array (
        'name' => '화염 숨결',
        'description' => '(궁극기) 9000% 마법피해를 2번 주고, 2.5초 간 0.5초마다 1500% 마법피해를 주는 불바다를 만듭니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '모노폴리맨' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '황금 열쇠',
        'description' => '주사위 눈금 6이 나올 경우 5% 확률로 행운석을 1개 획득합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 5,
      ),
      1 => 
      array (
        'name' => '주사위',
        'description' => '15% 확률로 굴린 주사위 눈금 × 500%의 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '파산',
        'description' => '(궁극기) 적 전체에게 코인을 쏟아부어 공격력의 10000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 10000,
          ),
        ),
      ),
    ),
  ),
  '마마' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '패시브',
        'description' => '전방 적들의 방어력을 20 감소시킵니다.',
        'type' => 'passive_buff',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_single',
            'value' => 20,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '저주',
        'description' => '15% 확률로 적에게 5초 간 매초 공격력의 1000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 1000,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '사령술',
        'description' => '(궁극기) 적 처치 시 100% 확률로 \'임프(방어력 감소 5 보유)\'를 무한대로 소환합니다.',
        'type' => 'ultimate',
        'trigger_chance' => 100,
      ),
    ),
  ),
  '개구리 왕자 (▶ 킹 다이안)' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '왕의 자질',
        'description' => '적에게 피해를 줄 때 체력이 3% 미만인 적은 즉시 처형합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '번개',
        'description' => '15% 확률로 무작위 5마리에게 천둥을 내리쳐 3000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '번개 구체',
        'description' => '번개 구슬을 소환해 20초 간 0.1초마다 500% 마법피해를 줍니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '천벌',
        'description' => '(궁극기) 전방에 거대한 번개를 떨어뜨려 공격력의 50000% 마법피해를 줍니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'damage_single_magic',
            'value' => 50000,
          ),
        ),
      ),
    ),
  ),
  '배트맨' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '어둠의 기사',
        'description' => '자신의 물리피해가 100% 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '배트랑',
        'description' => '15% 확률로 배트랑을 던져 2000% 물리피해를 주고 주변으로 튕깁니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '강화',
        'description' => '코인을 소모하여 배트맨의 기본 공격력을 대폭 증가시킵니다. (최대 15강)',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '배트 스웜',
        'description' => '(궁극기) 박쥐 떼를 소환하여 5초 간 주변 적에게 매초 1500% 물리피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '베인' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '어둠의 화살',
        'description' => '공격 속도가 느리게 고정되지만 기본 공격 피해가 300% 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '은화살',
        'description' => '3번째 공격마다 적의 최대 체력 3% 고정 피해를 줍니다.',
        'type' => 'on_attack_nth',
        'nth' => 3,
      ),
      2 => 
      array (
        'name' => '결전의 시간',
        'description' => '(궁극기) 8초 동안 공격 속도가 2배 빨라지고, 공격력이 100% 증가합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '인디' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '채찍',
        'description' => '15% 확률로 채찍을 휘둘러 1500% 물리피해를 주고 1초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 1.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '탐험',
        'description' => '10회 공격마다 유물 1개를 발굴하여 무작위 보상을 얻습니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '고대 유물',
        'description' => '(궁극기) 발굴한 유물에 따라 코인, 행운석, 공격력 버프 중 하나를 획득합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '와트' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '에너지 축적',
        'description' => '보유한 코인 1000당 공격력이 5% 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '과전압',
        'description' => '10% 확률로 적에게 2000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '방전',
        'description' => '(궁극기) 보유한 코인의 1%를 소모하여 적 전체에게 (소모 코인 × 1000%)의 마법피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '타르' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '끈적이는 액체',
        'description' => '모든 적의 이동 속도를 15 둔화시킵니다.',
        'type' => 'passive_aura',
      ),
      1 => 
      array (
        'name' => '분열',
        'description' => '적을 처치할 때마다 5% 확률로 돕는 소형 타르를 생성합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 5,
      ),
      2 => 
      array (
        'name' => '타르 폭발',
        'description' => '(궁극기) 10초 간 적에게 달라붙어 0.5초마다 800% 마법피해를 폭발시킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '로켓츄' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '로켓 펀치',
        'description' => '12% 확률로 펀치를 날려 2500% 물리피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 12,
      ),
      1 => 
      array (
        'name' => '오버클럭',
        'description' => '공격 시 5% 확률로 5초 동안 공격 속도가 100% 증가합니다.',
        'type' => 'on_attack',
        'trigger_chance' => 5,
      ),
      2 => 
      array (
        'name' => '자폭',
        'description' => '(궁극기) 50% 확률로 맵 전체에 99999% 물리피해를 주고 대폭발합니다. (실패 시 소멸)',
        'type' => 'ultimate',
        'trigger_chance' => 50,
      ),
    ),
  ),
  '우치' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '거리 비례',
        'description' => '대상과 거리가 멀수록 피해량이 최대 500%까지 증폭됩니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '바람의 칼날',
        'description' => '10% 확률로 적들을 관통하는 칼날을 날려 3000% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '폭풍의 눈',
        'description' => '(궁극기) 중앙에 폭풍을 소환해 5초 간 매초 2000% 마법피해를 주고 적을 끌어당깁니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '지지' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '마나 보호막',
        'description' => '주변 아군이 받는 피해를 20% 감소시킵니다.',
        'type' => 'passive_aura',
      ),
      1 => 
      array (
        'name' => '완벽한 계산',
        'description' => '기본 평타가 100% 마법피해로 전환되며, 5개의 마법 미사일이 발사됩니다. (Lv.6 미사일 2개 추가 적용)',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '마력 증폭',
        'description' => '(궁극기) 발동 시 마법 공격력이 200% 증가하며, 이 효과는 무제한 유지됩니다. (Lv.12 무제한 적용)',
        'type' => 'ultimate',
      ),
    ),
  ),
  '마스터 쿤' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '호랑이 발톱',
        'description' => '15% 확률로 3000% 물리피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      1 => 
      array (
        'name' => '기 집중',
        'description' => '15회 타격마다 주변 아군의 공격력을 5초 간 30% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '궁극의 일격',
        'description' => '(궁극기) 기를 모아 전방에 80000% 물리피해를 주고 적의 방어력을 100% 무시합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '초나' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '씨앗 뿌리기',
        'description' => '공격 시 10% 확률로 씨앗을 심어 3초 뒤 2500% 마법피해 폭발을 일으킵니다.',
        'type' => 'on_attack',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '가시 덤불',
        'description' => '공격력의 300% 지속 마법피해를 주는 가시를 생성합니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '나아무 소환',
        'description' => '(궁극기) 10초 간 적을 막는 거대 나아무를 소환하여 길을 완벽히 차단하고 지속 피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '펭귄악사' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '불협화음',
        'description' => '15% 확률로 음파를 날려 1500% 마법피해를 주고 1초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 1.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '흥겨운 연주',
        'description' => '필드 위 아군 전체의 공격 속도를 15% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '피날레',
        'description' => '(궁극기) 5초 간 무작위 적에게 0.5초마다 1000% 마법피해를 주고 이동속도를 90% 급감시킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '헤일리' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '저격',
        'description' => '거리가 가장 먼 적을 우선 타격하며, 거리에 비례하여 최대 300%의 추가 물리피해를 줍니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '관통탄',
        'description' => '10% 확률로 적의 방어력을 완벽히 무시하는 4000% 물리피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '헤드헌트',
        'description' => '(궁극기) 보스 타격 시 입히는 피해가 무조건 500% 증폭되는 궁극의 저격 탄환을 발사합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '아토' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '시간 왜곡',
        'description' => '10% 확률로 적의 이동 속도와 공격 속도를 3초 동안 50% 둔화시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      1 => 
      array (
        'name' => '시공간 베기',
        'description' => '15% 확률로 3000% 마법피해를 입힙니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '시간 정지',
        'description' => '(궁극기) 3초 동안 필드 위 모든 적의 움직임을 완벽히 멈춥니다. (기절과 별개 판정)',
        'type' => 'ultimate',
      ),
    ),
  ),
  '로카' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '장거리 사격',
        'description' => '대상과 거리가 멀수록 치명타 확률이 최대 30%까지 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '폭발탄',
        'description' => '10초마다 1~5개의 폭발탄을 장전하여 2000% 물리피해 광역 폭발을 일으킵니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '속사',
        'description' => '7% 확률로 6초 간 공격 속도가 15% 증가합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 7,
      ),
      3 => 
      array (
        'name' => '관통탄',
        'description' => '15회 공격마다 6500% 물리피해를 주고 다음 3회의 평타 피해가 100% 증가합니다.',
        'type' => 'passive_buff',
      ),
    ),
  ),
  '골라조' => 
  array (
    'rank' => '신화',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '카드 증폭',
        'description' => '카드를 뽑아 자신의 공격력을 영구히 증폭시킵니다. (성공 스택 누적)',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '연속 공격',
        'description' => '10% 확률로 3회 연속 공격을 가합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      2 => 
      array (
        'name' => '올인',
        'description' => '(궁극기) 뽑은 카드의 조합결과에 따라 막대한 광역 물리피해 또는 아군 강력 버프를 무작위로 발동합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '각성 헤일리' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '초정밀 저격',
        'description' => '가장 먼 적을 타격하며 거리 비례 추가 피해가 최대 600%로 증폭됩니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '철갑탄',
        'description' => '방어력 무시 4000% 물리피해를 주고, 2초 간 적의 방어력을 추가로 30 감소시킵니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '처형',
        'description' => '체력 5% 미만인 보스 몬스터를 즉시 즉사시킵니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '원샷 원킬',
        'description' => '(궁극기) 맵 끝까지 관통하는 100000% 물리피해 레이저 저격을 발사합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '사신 다이안 (사신 개구리 승천형)' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '사신의 자질',
        'description' => '적에게 피해를 줄 때 체력이 5% 미만인 적은 즉시 처형합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '죽음의 번개',
        'description' => '평타 공격이 타격 당 공격력의 1000% 범위 마법피해로 변경됩니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '연쇄 번개',
        'description' => '13% 확률로 적에게 20000% 마법피해를 입히고 10회 만큼 주변 적에게 연속 전이됩니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 13,
      ),
      3 => 
      array (
        'name' => '죽음의 손길',
        'description' => '(궁극기) 20초 동안 지옥을 소환하여 0.1초마다 600% 마법피해를 지속적으로 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '그랜드 마마' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '공포의 오라',
        'description' => '전방 적들의 방어력을 40이나 감소시킵니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '심연의 저주',
        'description' => '15% 확률로 5초 간 초당 2500% 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '군단장',
        'description' => '아군이 소환한 \'임프\'의 공격력과 공격 속도를 100% 증가시킵니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '대사령술',
        'description' => '(궁극기) 적 처치 시 100% 확률로 더 강력한 \'정예 임프\'를 끝없이 소환합니다.',
        'type' => 'ultimate',
        'trigger_chance' => 100,
      ),
    ),
  ),
  '원시 밤바' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '태초의 기운',
        'description' => '5초마다 호랑이 기운을 획득해 공격력이 100% 증가합니다. (최대 5스택)',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '찢기',
        'description' => '20% 확률로 5연속 공격을 가해 적을 찢어버립니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
      ),
      2 => 
      array (
        'name' => '포효',
        'description' => '10% 확률로 포효하여 필드 위 모든 적을 1초 간 기절시킵니다.',
        'type' => 'passive_aura',
        'trigger_chance' => 10,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 1.0,
          ),
        ),
      ),
      3 => 
      array (
        'name' => '야성의 춤',
        'description' => '(궁극기) 맵 전체를 휩쓰는 150000%의 압도적인 마법피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '귀신 닌자' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '수리검 폭풍',
        'description' => '평타 공격 시 무조건 4개의 수리검을 동시 투척합니다. (각 200% 물리피해)',
        'type' => 'on_attack',
      ),
      1 => 
      array (
        'name' => '은신 암살',
        'description' => '25% 확률로 대상에게 6000% 물리피해를 주고 5초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 25,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 5.0,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '그림자 일격',
        'description' => '체력이 10% 미만인 일반 몬스터를 즉시 암살(즉사)시킵니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '귀신 분신',
        'description' => '(궁극기) 지속시간이 무제한인 그림자 분신 2개체를 영구히 소환합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '시공 아토' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '절대 왜곡',
        'description' => '15% 확률로 적의 이동 속도와 공격 속도를 80% 감소시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      1 => 
      array (
        'name' => '차원 베기',
        'description' => '20% 확률로 6000% 마법피해를 주고 적의 방어력을 15 감소시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_single',
            'value' => 15,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '영겁의 틈',
        'description' => '보스 몬스터 등장 시, 등장과 동시에 보스의 체력을 10% 증발시킵니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '영원한 정지',
        'description' => '(궁극기) 5초 동안 필드 위 모든 적의 시간과 움직임을 영원히 정지시킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '닥터 펄스' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '플라즈마 EMP',
        'description' => '20% 확률로 범위 내 적들을 3초 간 완벽히 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
      ),
      1 => 
      array (
        'name' => '자기장 폭풍',
        'description' => '적의 이동속도를 30 감소시키고 초당 1000% 마법피해를 지속적으로 줍니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '과부하',
        'description' => '기절 상태인 적에게 입히는 피해량이 100% 증폭됩니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '하이퍼 펄스',
        'description' => '(궁극기) 필드 전체에 15000% 마법피해를 주고 모든 적을 5초 간 절대 기절시킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '탑 베인' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '은화살 극',
        'description' => '3번째 평타마다 적 최대 체력의 6%를 깎아내는 절대 고정 피해를 줍니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '구르기',
        'description' => '적 몬스터의 특수 스킬이나 상태 이상을 50% 확률로 무시(회피)합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 50,
      ),
      2 => 
      array (
        'name' => '벽 꿍',
        'description' => '15% 확률로 적을 밀쳐내고 충돌시켜 2초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
      3 => 
      array (
        'name' => '궁극의 결전',
        'description' => '(궁극기) 12초 동안 공격 속도가 3배로 증가하고, 공격력이 200% 폭증합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '마왕 드래곤' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '마왕의 위엄',
        'description' => '종족에 상관없이 필드 내 아군 \'모든 영웅\' 1명당 마왕의 공격력이 20%씩 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '마계의 불꽃',
        'description' => '15% 확률로 10000% 마법피해를 주고, 5초 간 초당 1500% 지옥불 지속 피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      2 => 
      array (
        'name' => '파멸의 일격',
        'description' => '15% 확률로 방어력을 뚫어버리는 15000% 마법피해를 입힙니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      3 => 
      array (
        'name' => '메테오 스트라이크',
        'description' => '(궁극기) 맵 전체에 거대한 운석을 3회 연속 떨어뜨려 매 타격 30000% 마법피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '슈퍼 중력자탄' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '사건의 지평선',
        'description' => '기본 평타 공격이 타격 지점으로 적을 지속적으로 끌어당기는 블랙홀 판정을 가집니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '초중력',
        'description' => '필드 위 모든 적의 이동 속도를 30 감소시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '질량 붕괴',
        'description' => '10% 확률로 적의 \'현재 체력\'의 15%를 즉시 날려버리는 마법피해를 줍니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 10,
      ),
      3 => 
      array (
        'name' => '빅뱅',
        'description' => '(궁극기) 10초 동안 모든 적을 맵 중앙으로 뭉쳐버리며, 매초 3000% 마법피해 대폭발을 일으킵니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '캡틴 로카' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '초정밀 스나이핑',
        'description' => '대상과 거리가 멀수록 치명타 확률이 최대 50%까지 극대화됩니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '네이팜 탄',
        'description' => '5초마다 광범위 5000% 물리피해를 입히는 소이탄을 발사합니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '속사 극',
        'description' => '15% 확률로 6초 동안 공격 속도가 30% 증가합니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 15,
      ),
      3 => 
      array (
        'name' => '위성 저격',
        'description' => '(궁극기) 하늘에서 위성 레이저가 꽂혀 200000% 물리피해를 입히고 5초 간 방어력을 30 감소시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_single',
            'value' => 30,
          ),
        ),
      ),
    ),
  ),
  '에이스 배트맨' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '다크나이트',
        'description' => '자신의 물리피해 깡스탯이 200% 증가합니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '폭탄 배트랑',
        'description' => '20% 확률로 5000% 물리피해를 입히는 폭발성 배트랑을 던집니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
      ),
      2 => 
      array (
        'name' => '자본주의',
        'description' => '코인(골드)으로 올리는 기본 공격력 강화의 상한선이 사라져 무한 강화가 가능해집니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '나이트크롤러',
        'description' => '(궁극기) 10초 동안 맵 전체를 덮는 박쥐 떼를 소환하여 매초 4000% 물리피해를 갈아버립니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '소음킹 펭귄악사' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '데스메탈',
        'description' => '25% 확률로 4000% 마법피해를 입히고 적의 고막을 찢어 2초 간 기절시킵니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 25,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'stun',
            'duration' => 2.0,
          ),
        ),
      ),
      1 => 
      array (
        'name' => '광란의 연주',
        'description' => '음악을 듣는 아군 전체의 공격 속도를 무려 30% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      2 => 
      array (
        'name' => '고막 파열',
        'description' => '기절 상태이상에 빠진 적들의 방어력을 20 깎아냅니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '소음 공해',
        'description' => '(궁극기) 10초 동안 맵 전체 적의 이동속도를 99% 감소시키고 매초 3000% 마법피해를 줍니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '아이엠 미야옹' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '히트빔',
        'description' => '20% 확률로 맵 끝까지 닿는 8000% 일직선 마법피해 레이저를 쏩니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
      ),
      1 => 
      array (
        'name' => '드론 폭격',
        'description' => '10회 공격마다 유도 미사일 10발을 동시다발적으로 쏘아 각 2000% 피해를 줍니다.',
        'type' => 'passive_buff',
      ),
      2 => 
      array (
        'name' => '나노 머신',
        'description' => '냥법사 시절의 능력이 극대화되어 아군 전체의 마나 회복 속도를 20% 증가시킵니다.',
        'type' => 'passive_aura',
      ),
      3 => 
      array (
        'name' => '궤도 폭격',
        'description' => '(궁극기) 우주에서 궤도 폭격을 쏟아부어 화면 전체에 100000% 마법피해를 입힙니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '보스 골라조' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '올인 극',
        'description' => '영구 공격력 증가를 위한 카드 증폭 시스템의 상한선 한도가 완전히 해제(무제한)됩니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '연속기',
        'description' => '20% 확률로 5회 연속 타격을 몰아칩니다.',
        'type' => 'passive_buff',
        'trigger_chance' => 20,
      ),
      2 => 
      array (
        'name' => '잭팟',
        'description' => '공격 시 1% 확률로 스킬과 강화에 소모된 코인 전액을 되돌려받는(페이백) 기적을 일으킵니다.',
        'type' => 'on_attack',
        'trigger_chance' => 1,
      ),
      3 => 
      array (
        'name' => '로얄 스트레이트 플러쉬',
        'description' => '(궁극기) 100000% 광역 물리피해 폭발과 함께 맵 위 모든 아군의 공격력을 50% 버프합니다.',
        'type' => 'ultimate',
      ),
    ),
  ),
  '블롭단' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
      0 => 
      array (
        'name' => '폭식',
        'description' => '적을 삼켜 처치할 때마다 영구적으로 오르는 공격력이 타격 당 1%씩 무한하게 쌓입니다.',
        'type' => 'passive_buff',
      ),
      1 => 
      array (
        'name' => '독성 지대',
        'description' => '공격 시 20% 확률로 3초 동안 방어력을 20 감소시키는 맹독 장판을 생성합니다.',
        'type' => 'on_attack',
        'trigger_chance' => 20,
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'shred_armor_flat_single',
            'value' => 20,
          ),
        ),
      ),
      2 => 
      array (
        'name' => '분열',
        'description' => '적에게 피격당할 경우 3개의 작은 자아 분신 블롭을 소환해 다구리를 칩니다.',
        'type' => 'passive_buff',
      ),
      3 => 
      array (
        'name' => '슬라임 웨이브',
        'description' => '(궁극기) 화면 전체를 끈적한 슬라임 해일로 덮어 50000% 물리피해를 주고 이동속도를 50 둔화시킵니다.',
        'type' => 'ultimate',
        'effects' => 
        array (
          0 => 
          array (
            'type' => 'slow_enemies_flat',
            'value' => 50.0,
          ),
        ),
      ),
    ),
  ),
  '[아이스 에이지] (궁극기) 5초 동안 필드 전체를 꽁꽁 얼려버려 완전 빙결시키고 매초 10000% 마법피해를 입힙니다.' => 
  array (
    'rank' => '불멸',
    'skills' => 
    array (
    ),
  ),
);
