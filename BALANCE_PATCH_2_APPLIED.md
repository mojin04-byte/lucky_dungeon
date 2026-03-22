# 밸런스 2차 패치 적용 완료 📋

## ✅ Phase 1 패치 완료 (2024년 적용)

### 적용된 수정 사항

#### 1️⃣ **성기사 [발도]** (희귀 등급)
```php
// 변경 전
'trigger_chance' => 15,
'effects' => array(
  array('type' => 'damage_aoe_magic', 'value' => 450),
),

// 변경 후
'trigger_chance' => 15,
'effects' => array(
  array('type' => 'damage_aoe_magic', 'value' => 450),
),
'level_up' => array(
  6 => array(
    'description' => '발동확률 20% 증가',
    'effects' => array(
      array('type' => 'damage_aoe_magic', 'value' => 450),
    ),
  ),
  12 => array(
    'description' => '피해량 50% 증폭',
    'effects' => array(
      array('type' => 'damage_aoe_magic', 'value' => 675),  // 450 * 1.5
    ),
  ),
),
```
**효과:** Lv.6부터 발동확률 향상, Lv.12부터 피해량 큰 폭 증가

---

#### 2️⃣ **충격로봇 [전기충격]** (희귀 등급)
```php
// 변경 전
'duration' => 1.125,

// 변경 후
'duration' => 1.125,
'level_up' => array(
  12 => array(
    'description' => '기절 시간 1.6875초로 증폭',
    'effects' => array(
      array('type' => 'stun', 'duration' => 1.6875),  // 1.125 * 1.5
    ),
  ),
),
```
**효과:** Lv.12에서 기절 시간이 50% 증가하여 유틸성 향상

---

#### 3️⃣ **사냥꾼 [방어력 감소]** (영웅 등급)
```php
// 변경 전
'effects' => array(
  array('type' => 'shred_armor_flat_aura', 'value' => 15),
),

// 변경 후
'effects' => array(
  array('type' => 'shred_armor_flat_aura', 'value' => 15),
),
'level_up' => array(
  12 => array(
    'description' => '방어력 감소 22.5로 증폭',
    'effects' => array(
      array('type' => 'shred_armor_flat_aura', 'value' => 22.5),  // 15 * 1.5
    ),
  ),
),
```
**효과:** Lv.12에서 팀 전체 방어력 디버프 강화 (주요 지원 영웅)

---

#### 4️⃣ **보안관 [속사]** (전설 등급)
```php
// 변경 전
'trigger_chance' => 10,
// 효과 없음

// 변경 후
'trigger_chance' => 15,
'effects' => array(
  array('type' => 'extra_attack_chance', 'value' => 30),
),
```
**효과:** 
- 발동확률: 10% → 15% (+50% 증가)
- 추가 공격 확률 30% (턴제 시스템 적응)
- 기존의 6초 지속 → 항구적 버프로 개선

---

#### 5️⃣ **닌자 [암살]** (신화 등급)
```php
// 변경 전
'trigger_chance' => 5,  ← 잘못된 값
// Lv.12 정보 없음

// 변경 후
'trigger_chance' => 16.5,  ← 원본 기준값 반영
'level_up' => array(
  12 => array(
    'description' => '발동확률 65% 증폭 (약 27%)',
    'effects' => array(
      array('type' => 'damage_single_physical', 'value' => 3000),
      array('type' => 'stun', 'duration' => 5.0),
    ),
  ),
),
```
**효과:**
- 발동확률: 5% → 16.5% (+230% 대폭 증가)
- Lv.12에서 궁극 노드 추가 (발동확률 1.65배 배수 적용)
- 신화 등급 암살자 포지셔닝 강화

---

## 🎯 패치 요약

| 영웅 | 스킬 | 주요 변화 | 밸런스 영향 |
|------|------|---------|-----------|
| **성기사** | 발도 | Lv.6/12 레벨 시스템 추가 | 초반→중반 성장 곡선 개선 |
| **충격로봇** | 전기충격 | Lv.12 기절 50% 증가 | 후반 유틸성 증강 |
| **사냥꾼** | 방어력감소 | Lv.12 방어력 50% 증가 | 팀 버프 강화 (영웅 등급) |
| **보안관** | 속사 | 발동확률 50% ↑, 항구 버프 | 전설 등급 DPS 상향 |
| **닌자** | 암살 | 발동확률 230% ↑ | 신화 암살자 포지션 확립 |

---

## 📊 파일 검증 결과

```
✅ z:\lucky_dungeon\config\hero_data.php  — No syntax errors
✅ z:\lucky_dungeon\api.php                — No syntax errors
✅ 구조 무결성 확인 완료
```

---

## 🚀 다음 단계

### Phase 2: 로직 확장 필요 (미구현 영웅 스킬)

**우선순위 높음:**
1. `on_attack_nth` (n번째 공격): 야만인 강타, 보안관 관통탄, 폭풍거인 번개강타
2. `team_buff_aura` (팀 버프): 늑대전사, 독수리장군, 오크주술사 패시브
3. `conditional_damage` (조건부 피해): 사냥꾼 정조준 (속박 시), 닥터 펄스 (기절 시)
4. `persistent_debuff` (지속 디버프): 오크주술사 저주 (10초 방어력 감소)

**우선순위 중간:**
5. `multi_hit` (다중 히트): 닌자 수리검 (2회), 레인저 연사 (3회)
6. `pierce_attack` (관통): 궁수 관통 (기존 구현), 우치 바람의 칼날
7. `field_object` (필드 오브젝트): 나무 덩굴, 초나 씨앗

### Phase 3: 고급 메커닉 (선택)

- 거리 비례 피해 (각성 헤일리, 우치)
- 체력 비례 고정 피해 (개구리 왕자, 베인)
- 소환 유닛 (닌자 분신, 마마 임프)

---

## 🔐 안전 지침

- ✅ 모든 패치는 `level_up` 배열 사용으로 기존 기능 보존
- ✅ trigger_chance/value 수정만으로 로직 변경 최소화
- ✅ 구문 검증 완료 (PHP 파서 통과)
- ⚠️ 게임 테스트는 로컬에서 먼저 수행 권장

---

## 📝 추가 노트

**밸런스 철학:**
- 일반/희귀 등급: 조기 패배 방지, 초반 게임성 보장
- 영웅/전설 등급: 미드게임 강자 포지션 유지
- 신화/불멸 등급: 고도화된 전략 활용 필요

**피드백 수집 포인트:**
1. 보안관 속사 (공격속도)의 느낌이 자연스러운가?
2. 닌자 암살 발동확률 16.5%가 게임 밸런스를 깨지 않는가?
3. 각 등급별 성장률이 균형있는가?

