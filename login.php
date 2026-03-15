<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // 프로덕션 환경에서는 반드시 0으로 설정

require_once 'bootstrap.php'; // DB 연결 및 세션 시작

// 이미 로그인된 상태라면 메인 게임 화면으로 강제 이동
if (isset($_SESSION['uid'])) {
    header("Location: index.php");
    exit;
}

$error_msg = '';

// 폼이 제출되었을 때(POST 요청) 실행되는 로직
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nickname = trim($_POST['nickname']);
    
    if (!empty($nickname)) {
        try {
            // [핵심 변경점] 1. 본진 DB(lucky_db)의 길드원 명단에 있는지 먼저 검사!
            $check_stmt = $pdo->prepare("SELECT name FROM lucky_db.guild_members WHERE name = ?");
            $check_stmt->execute([$nickname]);
            $is_guild_member = $check_stmt->fetch();

            if (!$is_guild_member) {
                // 길드원 명단에 없으면 즉시 입구컷 (세렌디피티 길드로 수정)
                $error_msg = "명단에 없는 이름입니다. 세렌디피티 길드원 닉네임을 정확히 입력하세요.";
            } else {
                // 2. 길드원이 맞다면, 사령관 캐릭터를 이미 만들었는지(lucky_dungeon_db) 검사
                $stmt = $pdo->prepare("SELECT uid, class_type, hp, narrative_tone FROM tb_commanders WHERE nickname = ?");
                $stmt->execute([$nickname]);
                $user = $stmt->fetch();

                if ($user) {
                    // 3. 이미 캐릭터를 만든 길드원: 바로 던전 진입
                    $_SESSION['uid'] = $user['uid'];
                    $_SESSION['nickname'] = $nickname;
                    $_SESSION['class_type'] = $user['class_type'];
                    $_SESSION['narrative_tone'] = !empty($user['narrative_tone']) ? $user['narrative_tone'] : '다크 판타지 톤';
                    
                    header("Location: index.php");
                    exit;
                } else {
                    // 4. 길드원은 맞지만 던전에 처음 온 경우: 캐릭터 생성소로 이동
                    $_SESSION['temp_nickname'] = $nickname;
                    header("Location: character_create.php");
                    exit;
                }
            }
        } catch (\PDOException $e) {
            $error_msg = "DB 통신 중 문제가 발생했습니다: " . $e->getMessage();
        }
    } else {
        $error_msg = "길드원 닉네임을 입력해주세요.";
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>운빨 던전: 혼돈의 미궁 - 입장</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Malgun Gothic', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background-color: #1e1e1e; padding: 40px; border-radius: 8px; box-shadow: 0 0 15px rgba(0, 255, 0, 0.2); text-align: center; width: 300px; }
        h1 { color: #4caf50; font-size: 24px; margin-bottom: 20px; }
        input[type="text"] { width: 90%; padding: 10px; margin-bottom: 20px; background-color: #2c2c2c; border: 1px solid #4caf50; color: #fff; border-radius: 4px; }
        button { width: 100%; padding: 12px; background-color: #4caf50; color: #121212; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .error { color: #ff5252; font-size: 14px; margin-bottom: 15px; font-weight: bold; }
    </style>
</head>
<body>

<div class="login-box">
    <h1>운빨 던전<br><span style="font-size:16px; color:#aaa;">혼돈의 미궁</span></h1>
    
    <?php if ($error_msg): ?>
        <div class="error">🚫 <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="text" name="nickname" placeholder="길드원 닉네임 입력" required autocomplete="off">
        <button type="submit">던전 입장하기</button>
    </form>
    <p style="font-size: 12px; color: #888; margin-top: 20px;">
        ※ 세렌디피티 길드원 명단에 등록된<br>닉네임만 입장 가능합니다.
    </p>
</div>

</body>
</html>