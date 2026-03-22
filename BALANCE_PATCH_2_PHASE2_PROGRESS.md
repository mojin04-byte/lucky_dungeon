# Phase 2 진행 상황 (로직 확장)

## 🔄 2026-03-19 추가 업데이트

### Phase 2 부족분 보완 완료
- `team_attack_speed_bonus_pct`가 실제 연속타 확률 계산에 반영되도록 `handle_combat`, `handle_skill` 동기화 완료
- `conditional_damage_when_frozen`가 실제 데미지 계산에 반영되도록 조건부 배수 로직 연결 완료
- `persistent_debuff_armor_flat`가 실제 피해 계산에 반영되도록 지속 방어력 감소 합산 로직 추가
- `persistent_debuff_armor_flat`의 턴 감소를 `handle_combat` + `handle_skill` 모두에서 처리하도록 확장
- `multi_hit`가 플래그 저장만 하던 상태에서 실제 추가 피해가 들어가도록 보완
- 소수점 확률(`16.5%` 등)이 절삭되지 않도록 trigger chance 판정을 float 기반으로 개선

### Phase 3 시작 (스타터 구현)
- 신규 효과 타입 `distance_damage_bonus_pct` 추가
- 영웅별 거리 비례 피해 보너스 저장/조회 구조 추가 (`hero_distance_bonus_pct`)
- 전투/스킬 턴 영웅 공격 계산에 거리 비례 동적 보너스 적용
- 1차 연결 영웅:
  - `우치` [거리 비례] 최대 +500%
  - `헤일리` [저격] 최대 +300%
  - `각성 헤일리` [초정밀 저격] 최대 +600%

### Phase 3 2차 구현 (execute/pull/field + 컨텍스트 보정)
- `execute_below_hp` 메커닉 추가
  - 효과 발동 시 처형 임계치(`execute_threshold_pct`)를 전투 상태에 등록
  - 실제 피해 직후 임계치 조건을 검사해 1회 처형 발동
  - 보스는 즉사 대신 제한 고정 피해(최대체력 22%) 적용
- `pull_enemies` 메커닉 추가
  - 적 행동 교란/감속을 필드 오브젝트 형태로 저장
  - 반격 단계에서 교란 확률(최대 35%) 및 피해 감쇄 반영
- `field_object` 메커닉 추가
  - 턴 시작 시 장판 틱 피해/감속/끌어당김 처리
  - 지속시간 종료 시 자동 해제 로그 출력
- 거리 비례 정교화
  - 랜덤 배수 제거
  - 전투 컨텍스트(층수, 적 타입, 선공 상태) 기반 정적 산출로 변경

  실제 운영 DB 연결 상태에서 tb_logs 30턴 실측 수집 자동화 스크립트 추가
보스 처형 제한(현재 22%)을 보스 등급별 차등(예: 일반보스/레이드보스)로 분기
field_object 중첩 규칙(동일 스킬 갱신, 이종 스킬 중첩 상한) 추가로 과중첩 방지

### 30턴 샘플 기반 캡 재조정 (대체 수집)
- 워크스페이스에 실제 `tb_logs` 전투 텍스트 샘플이 없어, 엔진 공식을 동일하게 사용한 30턴 샘플러로 분포를 계산
- 1차 캡(일반 240/보스 280)에서 `p95=280`으로 상한 포화가 자주 발생
- 2차 조정 후 캡:
  - 일반 적: `220`
  - 보스: `250`
  - 컨텍스트 ratio 상한: `0.76`
- 조정 후 30턴 통계:
  - `avg=218.4`, `p90=250`, `p95=250`, `max=250`
  - 보스 상한 접근은 유지하되 일반 전투 과증폭 빈도 감소


## ✅ 완료된 항목

### 1. on_attack_nth (n번째 공격) 설정
**상태:** ✅ 완료

#### 변경사항:
- **보안관 [관통탄]**: `passive_buff` → `on_attack_nth` (nth=15)
  - 효과: 15회 공격마다 3000% 물리피해
  - config/hero_data.php에서 `on_attack_nth` 타입으로 설정
  - effects에 `damage_single_physical: 3000` 추가

- **폭풍거인 [번개 강타]**: `passive_buff` → `on_attack_nth` (nth=15)
  - 효과: 15회 공격마다 5000% 마법피해
  - config/hero_data.php에서 `on_attack_nth` 타입으로 설정
  - effects에 `damage_aoe_magic: 5000` 추가

- **야만인 [강타]**: 이미 구현됨 (nth=3, guaranteed_critical)

#### 기술 세부사항:
```php
// apply_hero_skills()에서 on_attack_nth 처리 (라인 2714-2716)
if ($skill_type === 'on_attack_nth') {
    $nth = isset($skill_def['nth']) ? max(1, (int)$skill_def['nth']) : 1;
    if ($hero_hit_count % $nth !== 0) continue;  // n번째가 아니면 스킵
}
```

### 2. team_buff_aura 구성 (패시브 팀 버프)
**상태:** ✅ 부분 완료 (구성 완료, 적용 진행 중)

#### 구성된 효과 타입:
- `team_damage_bonus_pct`: 아군 공격력 % 증가
- `team_attack_speed_bonus_pct`: 아군 공격 속도 % 증가

#### 영웅별 설정:
```php
// 늑대전사 [패시브]: 아군 공격력 10% 증가
'effects' => array(
  array('type' => 'team_damage_bonus_pct', 'value' => 10),
),

// 독수리장군 [패시브]: 아군 공격 속도 10% 증가
'effects' => array(
  array('type' => 'team_attack_speed_bonus_pct', 'value' => 10),
),

// 오크주술사 [피의 굶주림]: 아군 공격 속도 15% 증가
'effects' => array(
  array('type' => 'team_attack_speed_bonus_pct', 'value' => 15),
),
```

### 3. apply_dynamic_effect() 확장
**상태:** ✅ 완료

#### 추가된 케이스 (api.php 라인 2668~2680):
```php
case 'team_damage_bonus_pct':
    if (!isset($_SESSION['combat_state']['team_damage_bonus_pct'])) 
        $_SESSION['combat_state']['team_damage_bonus_pct'] = 0;
    $cur_dmg = max(0, (int)$_SESSION['combat_state']['team_damage_bonus_pct']);
    $_SESSION['combat_state']['team_damage_bonus_pct'] = min(100, max($cur_dmg, (int)round($value)));
    $logs[] = "💪 <span style='color:#ffb74d;'>[...] 아군 공격력 +...%";
    break;

case 'team_attack_speed_bonus_pct':
    if (!isset($_SESSION['combat_state']['team_attack_speed_bonus_pct'])) 
        $_SESSION['combat_state']['team_attack_speed_bonus_pct'] = 0;
    $cur_ats = max(0, (int)$_SESSION['combat_state']['team_attack_speed_bonus_pct']);
    $_SESSION['combat_state']['team_attack_speed_bonus_pct'] = min(100, max($cur_ats, (int)round($value)));
    $logs[] = "⚡ <span style='color:#81c784;'>[...] 아군 공격속도 +...%";
    break;
```

### 4. handle_combat() 초기화
**상태:** ✅ 완료

#### 추가된 초기화 (api.php 라인 3337~3344):
```php
if (!isset($_SESSION['combat_state']['team_damage_bonus_pct'])) {
    $_SESSION['combat_state']['team_damage_bonus_pct'] = 0;
}
if (!isset($_SESSION['combat_state']['team_attack_speed_bonus_pct'])) {
    $_SESSION['combat_state']['team_attack_speed_bonus_pct'] = 0;
}
```

---

## 🟡 진행 중 항목

### Phase 2-A: 팀 버프 효과 적용 (handle_combat)
**상태:** 부분구현

#### 필요한 작업:
1. hero_dmg 계산 후 team_damage_bonus_pct 곱하기
  - 위치: handle_combat() 내 hero 공격 루프 (현재 3620~3645줄)
  - 구현: synergy 계산 후 적용

2. 영웅의 연속 공격 확률에 team_attack_speed_bonus_pct 적용
  - 위치: handle_combat() 내 AGI 연속 공격 계산 (현재 3596~3598줄)
  - 구현: 기존 team_attack_speed_bonus와 병합

#### 기술 주의사항:
- 팀 버프는 **passive_aura**이므로, 매 턴 항상 활성화됨
- 다중 팀 버프 중첩 시 최대 100%로 cap
- session state에서 읽어올 때 null 체크 필수

### Phase 2-B: handle_skill() 동기화
**상태:** 미시작

#### 필요한 작업:
1. handle_skill()에서도 team_damage_bonus_pct 초기화 및 적용
2. 마법 스킬 사용 시 팀 버프 적용 확보

---

## ❌ 미구현 항목 (Phase 2)

### 1. conditional_damage (조건부 피해)
```
사냥꾼 [정조준]: 속박된 적 → 피해 50% 증가
닥터 펄스 [과부하]: 기절 상태 적 → 피해 100% 증가
```
**필요 구현:**
- 상태 확인 로직 (속박, 기절)
- 조건부 피해 배수 적용

### 2. persistent_debuff (지속 디버프)
```
오크주술사 [저주]: 10초 간 방어력 20 감소
```
**필요 구현:**
- 턴 카운터 로직 (지속 디버프)
- session state 추적

### 3. multi_hit (다중 히트)
```
닌자 [수리검]: 2회 공격
레인저 [연사]: 3회 공격
```
**필요 구현:**
- 공격 판정 반복 로직
- 다중 히트 간 피해 계산

### 4. movement_based / advanced_mechanics
```
각성 헤일리: 거리 비례 피해
중력자탄: 끌어당김 메커닉
드래곤: 불꽃 필드 오브젝트
```

---

## 📊 파일 검증

| 파일 | 상태 | 검증일 |
|------|------|--------|
| config/hero_data.php | ✅ No syntax errors | 2026-03-18 |
| api.php | ✅ No syntax errors | 2026-03-18 |

---

## 📝 다음 단계

### Option 1: 팀 버프 효과 적용 완료 (권장)
- hero_dmg에 team_damage_bonus_pct 곱하기 작업 완료
- handle_skill()에도 동일 로직 적용
- 전체 통합 테스트

### Option 2: Phase 2 다른 항목 진행
- conditional_damage (조건부 피해) 구현
- persistent_debuff (지속 디버프) 구현
- multi_hit (다중 히트) 구현

### Option 3: 현재 진행 상태로 게임 테스트
- Phase 1 + Phase 2 초기 구성만으로 게임 테스트
- 동작 확인 후 추가 구현

---

## 🔧 기술 참고사항

**패시브 오라 (Passive Aura) 작동 방식:**
- config에서 `type: passive_aura`로 마킹
- apply_hero_skills()에서 처음 한 번만 실행 (passives_applied flag)
- session state에 값이 저장되어 이후 모든 공격에 영향
- 중첩되는 오라들은 최대값으로 업데이트

**Session State 관리:**
- $_SESSION['combat_state']['team_damage_bonus_pct']: 현재 아군 공격력 보너스 (0~100)
- $_SESSION['combat_state']['team_attack_speed_bonus_pct']: 현재 아군 공격속도 보너스 (0~100)
- 최대값 min(100, ...)로 제한하여 극단적 버프 방지

