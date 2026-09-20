<?php
// 统计页强制走 HTTP（免自签名证书信任，访客/手机均正常）
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '172.19.200.187';
    header('Location: http://' . $host . $_SERVER['REQUEST_URI']);
    exit;
}

// 运动会广播投稿统计排行榜
require_once __DIR__ . '/tougao_config.php';

date_default_timezone_set('Asia/Shanghai');
session_start();

// 权限码登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
    if ($_POST['access_code'] === TOUGAO_ACCESS_CODE) {
        $_SESSION['authenticated'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = "权限码不正确，请重新输入";
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['authenticated'] = false;
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?><!DOCTYPE html>
    <html lang="zh-CN">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>权限验证</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #f5f5f5; }
        .login-box { background-color: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 300px; }
        h1 { text-align: center; color: #333; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: .5rem; color: #666; }
        input { width: 100%; padding: .8rem; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: .8rem; background-color: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .error { color: #f44336; text-align: center; margin-top: 1rem; padding: .5rem; background-color: #ffebee; border-radius: 4px; }
    </style></head>
    <body>
        <div class="login-box"><h1>请输入权限码</h1>
        <?php if (isset($loginError)): ?><div class="error"><?php echo $loginError; ?></div><?php endif; ?>
        <form method="post"><div class="form-group"><label>权限码</label><input type="password" name="access_code" required autofocus></div><button type="submit">验证</button></form></div>
    </body></html><?php
    exit;
}

$m = tougao_db();
$stats = [];
$totalSubmit = 0; $totalPlayed = 0; $totalPending = 0; $totalDiscarded = 0;
if ($m) {
    $q = $m->query("SELECT `班级`,
            COUNT(*) AS `投稿数`,
            SUM(`状态`='已播出') AS `录用数`,
            SUM(`状态`='待审核') AS `待审数`,
            SUM(`状态`='已丢弃') AS `丢弃数`
        FROM `" . TOUGAO_RECORD_TABLE . "`
        GROUP BY `班级`
        ORDER BY `录用数` DESC, `投稿数` DESC, `班级`");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $stats[] = $row;
            $totalSubmit += (int)$row['投稿数'];
            $totalPlayed += (int)$row['录用数'];
            $totalPending += (int)$row['待审数'];
            $totalDiscarded += (int)$row['丢弃数'];
        }
        $q->close();
    }
    $m->close();
}
$rankName = ['冠军', '亚军', '季军'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>投稿与录用统计排行榜</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 860px; margin: 0 auto; padding: 20px; background-color: #f5f5f5; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { color: #333; margin: 0; }
        .logout-btn { padding: 8px 16px; background-color: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #2196F3; text-decoration: none; font-size: 14px; }
        .totals { background-color: #e8f5e9; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 15px; }
        .totals b { color: #2e7d32; }
        .card { background-color: white; border-radius: 8px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .empty { text-align: center; color: #666; padding: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #eee; }
        th { background-color: #fafafa; color: #555; }
        tr.top { background-color: #fff8e1; font-weight: bold; }
        td.class-name { text-align: left; }
        .rate { color: #888; }
    </style>
</head>
<body>
    <div class="header">
        <h1>各班投稿 · 录用统计</h1>
        <a href="?action=logout" class="logout-btn">退出登录</a>
    </div>
    <a href="uploads/playcontrols.php" class="back-link">← 返回审核播出页</a>

    <div class="totals">
        <span>累计投稿：<b><?php echo $totalSubmit; ?></b></span>
        <span>录用播出：<b><?php echo $totalPlayed; ?></b></span>
        <span>待审核：<b><?php echo $totalPending; ?></b></span>
        <span>已丢弃：<b><?php echo $totalDiscarded; ?></b></span>
    </div>

    <div class="card">
        <?php if (empty($stats)): ?>
            <div class="empty">暂无投稿记录</div>
        <?php else: ?>
            <table>
                <tr><th>排名</th><th>班级</th><th>投稿数</th><th>录用播出</th><th>待审核</th><th>已丢弃</th><th>录用率</th></tr>
                <?php foreach ($stats as $i => $row):
                    $submit = (int)$row['投稿数'];
                    $played = (int)$row['录用数'];
                    $rate = $submit > 0 ? round($played / $submit * 100) . '%' : '-';
                    $rank = $i + 1;
                ?>
                    <tr class="<?php echo $i < 3 ? 'top' : ''; ?>">
                        <td><?php echo $i < 3 ? '<b>' . $rankName[$i] . '</b>' : $rank; ?></td>
                        <td class="class-name"><?php echo htmlspecialchars($row['班级']); ?></td>
                        <td><?php echo $submit; ?></td>
                        <td><?php echo $played; ?></td>
                        <td><?php echo (int)$row['待审数']; ?></td>
                        <td><?php echo (int)$row['丢弃数']; ?></td>
                        <td class="rate"><?php echo $rate; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>