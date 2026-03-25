-- --------------------------------------------------------
-- 호스트:                          192.168.50.77
-- 서버 버전:                        5.6.30 - Source distribution
-- 서버 OS:                        Linux
-- HeidiSQL 버전:                  12.3.0.6589
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- 테이블 lucky_dungeon_db.tb_collection 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_collection` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `hero_name` varchar(50) NOT NULL,
  `reg_date` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_collection` (`uid`,`hero_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2016 DEFAULT CHARSET=utf8mb4;

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_commanders 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_commanders` (
  `uid` int(11) NOT NULL AUTO_INCREMENT COMMENT '고유 식별자',
  `nickname` varchar(50) NOT NULL COMMENT '길드원 닉네임',
  `class_type` varchar(20) NOT NULL COMMENT '직업(산적, 물의정령 등)',
  `level` int(11) DEFAULT '1',
  `exp` int(11) DEFAULT '0',
  `stat_points` int(11) DEFAULT '0',
  `hp` int(11) DEFAULT '100' COMMENT '현재 체력',
  `max_hp` int(11) DEFAULT '100' COMMENT '최대 체력',
  `mp` int(11) DEFAULT '50' COMMENT '현재 마나',
  `max_mp` int(11) DEFAULT '50' COMMENT '최대 마나',
  `stat_str` int(11) DEFAULT '0' COMMENT '힘',
  `stat_mag` int(11) DEFAULT '0' COMMENT '마력',
  `stat_agi` int(11) DEFAULT '0' COMMENT '민첩',
  `stat_luk` int(11) DEFAULT '0' COMMENT '행운',
  `stat_men` int(11) DEFAULT '0' COMMENT '정신력',
  `stat_vit` int(11) DEFAULT '0' COMMENT '체력(내성)',
  `disposition` int(11) DEFAULT '50' COMMENT '성향 (1:극도로 조심 ~ 100:매우 과감)',
  `gold` int(11) DEFAULT '10000' COMMENT '소환 재화',
  `mythstone` int(11) NOT NULL DEFAULT '0' COMMENT '신화석 재화',
  `current_floor` int(11) DEFAULT '1' COMMENT '현재 층수',
  `background_story` text COMMENT 'AI가 생성한 캐릭터 탄생 설화',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_combat` tinyint(1) DEFAULT '0' COMMENT '0:평시, 1:전투중',
  `mob_name` varchar(50) DEFAULT '' COMMENT '현재 교전중인 몬스터',
  `mob_hp` int(11) DEFAULT '0' COMMENT '몬스터 현재 체력',
  `mob_max_hp` int(11) DEFAULT '0' COMMENT '몬스터 최대 체력',
  `mob_atk` int(11) DEFAULT '0' COMMENT '몬스터 공격력',
  `auto_explore_start_time` datetime DEFAULT NULL COMMENT '자동 탐험 시작 시간',
  `auto_explore_rewards` text COMMENT '자동 탐험 누적 보상',
  `max_floor` int(11) DEFAULT '1' COMMENT '최고 도달 층수',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `idx_nickname` (`nickname`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COMMENT='사령관(플레이어) 정보';

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_expeditions 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_expeditions` (
  `expedition_id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `duration` int(11) NOT NULL COMMENT '파견 시간 (시간 단위)',
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`expedition_id`),
  KEY `uid` (`uid`),
  CONSTRAINT `tb_expeditions_ibfk_1` FOREIGN KEY (`uid`) REFERENCES `tb_commanders` (`uid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_expedition_heroes 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_expedition_heroes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expedition_id` int(11) NOT NULL,
  `inv_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `expedition_id` (`expedition_id`),
  KEY `inv_id` (`inv_id`),
  CONSTRAINT `tb_expedition_heroes_ibfk_1` FOREIGN KEY (`expedition_id`) REFERENCES `tb_expeditions` (`expedition_id`) ON DELETE CASCADE,
  CONSTRAINT `tb_expedition_heroes_ibfk_2` FOREIGN KEY (`inv_id`) REFERENCES `tb_heroes` (`inv_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_heroes 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_heroes` (
  `inv_id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL COMMENT '소유자 UID',
  `hero_rank` varchar(20) NOT NULL COMMENT '등급(일반~불멸)',
  `hero_name` varchar(50) NOT NULL COMMENT '영웅 이름',
  `quantity` int(11) DEFAULT '0' COMMENT '보유 수량(합성용)',
  `battle_count` int(11) DEFAULT '0',
  `is_equipped` tinyint(1) NOT NULL DEFAULT '0',
  `hero_lore` text,
  `is_deck` tinyint(1) DEFAULT '0' COMMENT '전투 덱 출전 여부(1:출전, 0:대기)',
  `deck_order` int(11) DEFAULT '0' COMMENT '덱 배치 순서(밤파이어 폭탄 회피용)',
  `is_on_expedition` tinyint(1) NOT NULL DEFAULT '0' COMMENT '파견 참여 여부',
  `level` int(11) NOT NULL DEFAULT '1' COMMENT '영웅 레벨',
  PRIMARY KEY (`inv_id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB AUTO_INCREMENT=326 DEFAULT CHARSET=utf8mb4 COMMENT='보유 영웅 및 덱 편성';

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_logs 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) NOT NULL,
  `log_text` text NOT NULL COMMENT '사건 묘사 텍스트',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_uid_time` (`uid`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3414 DEFAULT CHARSET=utf8mb4 COMMENT='플레이 로그 (AI 문맥용)';

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_stories 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_stories` (
  `story_id` int(11) NOT NULL AUTO_INCREMENT,
  `floor_number` int(11) NOT NULL COMMENT '스토리가 등장하는 층',
  `story_title` varchar(255) NOT NULL COMMENT '스토리 제목',
  `story_content` text NOT NULL COMMENT '스토리 내용',
  `reward_gold` int(11) NOT NULL DEFAULT '0' COMMENT '보상 골드',
  `reward_exp` int(11) NOT NULL DEFAULT '0' COMMENT '보상 경험치',
  PRIMARY KEY (`story_id`),
  UNIQUE KEY `floor_number_unique` (`floor_number`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COMMENT='층별 스토리 데이터';

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_user_stories 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_user_stories` (
  `uid` varchar(255) NOT NULL COMMENT '유저 ID',
  `story_id` int(11) NOT NULL COMMENT '본 스토리 ID',
  `seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`uid`(191),`story_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='유저별 스토리 확인 기록';

-- 내보낼 데이터가 선택되어 있지 않습니다.

-- 테이블 lucky_dungeon_db.tb_world_state 구조 내보내기
CREATE TABLE IF NOT EXISTS `tb_world_state` (
  `state_key` varchar(50) NOT NULL COMMENT '상태 키 (예: nahatu_totem_count)',
  `state_value` int(11) DEFAULT '0' COMMENT '상태 값',
  `updated_by` varchar(50) DEFAULT NULL COMMENT '마지막으로 기여한 유저',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='길드 공유 월드 상태';

-- 내보낼 데이터가 선택되어 있지 않습니다.

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
