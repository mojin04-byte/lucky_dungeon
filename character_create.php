<?php
// character_create.php
error_reporting(E_ALL);
ini_set('display_errors', 0); // 프로덕션 환경에서는 반드시 0으로 설정

require_once 'bootstrap.php';

// 1. 임시 닉네임이 없으면(비정상 접근) 로그인 페이지로 쫓아냄
if (!isset($_SESSION['temp_nickname'])) {
    header("Location: login.php");
    exit;
}

$nickname = $_SESSION['temp_nickname'];
$error_msg = '';

    // 2. 폼이 제출되었을 때 (사령관 특성 선택 완료)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_type = $_POST['class_type'] ?? '';
    $background_story = $_POST['background_story'] ?? ''; // 배경 이야기 수집
    $narrative_tone = '다크 판타지 톤';

    // 정상적인 클래스가 넘어왔는지 해킹 방지 검증
    if (in_array($class_type, array('돌격', '신비', '전술'), true)) {
        
        // [TRPG 다이스 굴림 핵심 로직] 기본 1~20 주사위
        $str = rand(1, 20); // 힘
        $mag = rand(1, 20); // 마력
        $agi = rand(1, 20); // 민첩
        $luk = rand(1, 20); // 행운
        $men = rand(1, 20); // 정신력
        $vit = rand(1, 20); // 체력
        $disp = rand(1, 100); // 성향 (1:극도로 조심 ~ 100:매우 과감)

        // 클래스별 특화 보너스 스탯 부여 (+10 고정 보너스)
        if ($class_type === '돌격') {
            $str += 10; $vit += 10;
        } elseif ($class_type === '신비') {
            $mag += 10; $men += 10;
        } elseif ($class_type === '전술') {
            $agi += 10; $luk += 10;
        }

        try {
            // 3. DB에 생성된 사령관 정보 INSERT
            $stmt = $pdo->prepare("
                INSERT INTO tb_commanders 
                (nickname, class_type, narrative_tone, hp, max_hp, mp, max_mp, stat_str, stat_mag, stat_agi, stat_luk, stat_men, stat_vit, disposition, gold, current_floor, stat_points, level, exp, background_story) 
                VALUES (?, ?, ?, 100, 100, 50, 50, ?, ?, ?, ?, ?, ?, ?, 1000, 1, 5, 1, 0, ?)
            ");
            $stmt->execute([$nickname, $class_type, $narrative_tone, $str, $mag, $agi, $luk, $men, $vit, $disp, $background_story]);
            
            // 방금 생성된 유저의 고유 번호(UID) 가져오기
            $new_uid = $pdo->lastInsertId();

            // 4. 세션 승급 (임시 닉네임 -> 정식 로그인 세션으로 변환)
            unset($_SESSION['temp_nickname']);
            $_SESSION['uid'] = $new_uid;
            $_SESSION['nickname'] = $nickname;
            $_SESSION['class_type'] = $class_type;
            $_SESSION['narrative_tone'] = $narrative_tone;

            // 5. 메인 던전(index.php)으로 당당하게 입장!
            header("Location: index.php");
            exit;

        } catch (\PDOException $e) {
            // 혹시 누군가 이미 닉네임을 쓰고 있다면 에러 처리
            if ($e->getCode() == 23000) { 
                $error_msg = "앗! 길드 내에 이미 같은 닉네임이 존재합니다.";
                unset($_SESSION['temp_nickname']); // 임시 세션 지우고 돌아가게 함
            } else {
                $error_msg = "DB 저장 중 오류가 발생했습니다: " . $e->getMessage();
            }
        }
    } else {
        $error_msg = "올바른 사령관 특성을 선택해주세요.";
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>사령관 임명 - 혼돈의 미궁</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Malgun Gothic', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .create-box { background-color: #1e1e1e; padding: 40px; border-radius: 8px; box-shadow: 0 0 15px rgba(255, 165, 0, 0.2); width: 450px; max-height: 90vh; overflow-y: auto; }
        h2 { color: #ffa500; margin-bottom: 10px; text-align: center; }
        .welcome { text-align: center; color: #aaa; margin-bottom: 30px; }
        .class-card { background-color: #2c2c2c; border: 1px solid #444; padding: 15px; margin-bottom: 15px; border-radius: 6px; cursor: pointer; transition: 0.3s; }
        .class-card:hover { border-color: #ffa500; }
        .class-card input[type="radio"] { display: none; }
        .class-card input[type="radio"]:checked + div h3 { color: #ffa500; }
        .class-card input[type="radio"]:checked + div { font-weight: bold; }
        .class-title { margin: 0 0 5px 0; font-size: 18px; color: #fff; }
        .class-desc { margin: 0; font-size: 13px; color: #888; font-weight: normal; }
        textarea { width: 100%; background-color: #2c2c2c; color: #e0e0e0; border: 1px solid #444; border-radius: 4px; padding: 10px; box-sizing: border-box; resize: vertical; font-family: 'Malgun Gothic', sans-serif;}
        button { width: 100%; padding: 15px; background-color: #ffa500; color: #121212; border: none; border-radius: 4px; font-weight: bold; font-size: 16px; cursor: pointer; margin-top: 10px; }
        button:hover { background-color: #e69500; }
        .error { color: #ff5252; text-align: center; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="create-box">
    <h2>차원 사령관 임명</h2>
    <div class="welcome">[ <?= htmlspecialchars($nickname) ?> ] 님, 주특기를 선택하십시오.</div>

    <?php if ($error_msg): ?>
        <div class="error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="돌격" required>
            <div>
                <h3 class="class-title">🗡️ 돌격 사령관</h3>
                <p class="class-desc">물리 전투의 대가입니다. 힘(STR)과 체력(VIT) 다이스에 강력한 보너스를 받습니다.</p>
            </div>
        </label>

        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="신비" required>
            <div>
                <h3 class="class-title">✨ 신비 사령관</h3>
                <p class="class-desc">마법과 퍼즐에 능합니다. 지능(INT)과 정신력(MEN) 다이스에 보너스를 받습니다.</p>
            </div>
        </label>

        <label class="class-card" style="display:block;">
            <input type="radio" name="class_type" value="전술" required>
            <div>
                <h3 class="class-title">🎲 전술 사령관</h3>
                <p class="class-desc">함정 회피와 운에 기대는 변수 창출의 대가입니다. 민첩(AGI)과 행운(LUK)에 보너스를 받습니다.</p>
            </div>
        </label>

		<div style="margin-bottom: 15px;">
			<label for="background_story" style="display:block; margin-bottom:5px; color: #ccc;">캐릭터 탄생 설화 (AI 생성 등)</label>
			<textarea id="background_story" name="background_story" rows="4" placeholder="캐릭터의 배경 이야기를 자유롭게 작성하거나 붙여넣어 주세요."></textarea>
		</div>

        <button type="submit">주사위 굴리기 및 던전 입장 🎲</button>
    </form>
</div>

</body>
</html>