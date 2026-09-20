<?php
// 审核/播出页强制走 HTTP（免自签名证书信任，手机内置浏览器才能播音频）
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '172.19.200.187';
    header('Location: http://' . $host . $_SERVER['REQUEST_URI']);
    exit;
}

// 引入共享配置（稿件池目录、投稿记录表、权限码）
require_once __DIR__ . '/../tougao_config.php';

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

// 登出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['authenticated'] = false;
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 未登录则显示登录界面
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?><!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>权限验证</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #f5f5f5; }
            .login-box { background-color: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 300px; }
            h1 { text-align: center; color: #333; margin-bottom: 1.5rem; }
            .form-group { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.5rem; color: #666; }
            input { width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { width: 100%; padding: 0.8rem; background-color: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
            button:hover { background-color: #0b7dda; }
            .error { color: #f44336; text-align: center; margin-top: 1rem; padding: 0.5rem; background-color: #ffebee; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>请输入权限码</h1>
            <?php if (isset($loginError)): ?><div class="error"><?php echo $loginError; ?></div><?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="access_code">权限码</label>
                    <input type="password" id="access_code" name="access_code" required autofocus>
                </div>
                <button type="submit">验证</button>
            </form>
        </div>
    </body>
    </html><?php
    exit;
}

$allowedExtensions = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'webm', 'mp4', 'aac'];
$uploadDir = tougao_ensure_dir(TOUGAO_UPLOAD_DIR);
$studentQueueDir = tougao_ensure_dir(TOUGAO_STUDENT_QUEUE_DIR);
$playedDir = tougao_ensure_dir(TOUGAO_PLAYED_DIR);
$discardedDir = tougao_ensure_dir(TOUGAO_DISCARDED_DIR);

$m = tougao_db();

// 嗅探音频真实 MIME（与 stream.php 逻辑一致），供 <source type> 使用，避免与文件真实内容不一致
function audio_mime_for($fullPath) {
    if (!is_file($fullPath)) return 'audio/webm';
    $h = fopen($fullPath, 'rb');
    if (!$h) return 'audio/webm';
    $head = fread($h, 16);
    fclose($h);
    if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) return 'audio/webm';
    if (strncmp($head, 'OggS', 4) === 0) return 'audio/ogg';
    if (strncmp($head, 'fLaC', 4) === 0) return 'audio/flac';
    if (strncmp($head, 'ID3', 3) === 0 || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) return 'audio/mpeg';
    if (strncmp($head, 'RIFF', 4) === 0 && strncmp(substr($head, 8, 4), 'WAVE', 4) === 0) return 'audio/wav';
    if (strncmp(substr($head, 4, 4), 'ftyp', 4) === 0) return 'audio/mp4';
    return 'audio/webm';
}

// ---------- 审核通过（进入学生待播队列，AJAX） ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    $filename = $_POST['filename'] ?? '';
    if ($filename === '' || strpos($filename, '..') !== false) { die("无效的文件操作"); }
    $src = $uploadDir . '/' . basename($filename);
    if (!file_exists($src)) { die("error"); }
    $dest = $studentQueueDir . '/' . basename($filename);
    if (file_exists($dest)) {
        $info = pathinfo($filename);
        $dest = $studentQueueDir . '/' . $info['filename'] . '_' . time() . '.' . $info['extension'];
    }
    if (rename($src, $dest)) {
        if ($m) {
            $stmt = $m->prepare("UPDATE `" . TOUGAO_RECORD_TABLE . "` SET `状态`='待播出' WHERE `文件名`=? AND `状态`='待审核'");
            if ($stmt) { $stmt->bind_param('s', basename($filename)); $stmt->execute(); $stmt->close(); }
        }
        echo "success";
    } else {
        echo "error";
    }
    exit;
}

// ---------- 丢弃（AJAX 免刷新） ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'discard') {
    $filename = $_POST['filename'] ?? '';
    if ($filename === '' || strpos($filename, '..') !== false) { die("无效的文件操作"); }
    $src = $uploadDir . '/' . basename($filename);
    if (!file_exists($src)) { die("error"); }
    $dest = $discardedDir . '/' . basename($filename);
    if (file_exists($dest)) {
        $info = pathinfo($filename);
        $dest = $discardedDir . '/' . $info['filename'] . '_' . time() . '.' . $info['extension'];
    }
    if (rename($src, $dest)) {
        if ($m) {
            $stmt = $m->prepare("UPDATE `" . TOUGAO_RECORD_TABLE . "` SET `状态`='已丢弃' WHERE `文件名`=? AND `状态`='待审核'");
            if ($stmt) { $stmt->bind_param('s', basename($filename)); $stmt->execute(); $stmt->close(); }
        }
        echo "success";
    } else {
        echo "error";
    }
    exit;
}

// ---------- 待审核记录（用于补充显示班级/姓名/提交时间）----------
$pendingMeta = [];
if ($m) {
    $q = $m->query("SELECT `文件名`,`班级`,`姓名`,`用途说明`,`提交时间` FROM `" . TOUGAO_RECORD_TABLE . "` WHERE `状态`='待审核'");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $pendingMeta[$row['文件名']] = $row;
        }
        $q->close();
    }
    $m->close();
}

// ---------- 扫描待审核稿件文件夹 ----------
$audioFiles = [];
$files = scandir($uploadDir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..' || is_dir($uploadDir . '/' . $file)) continue;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, $allowedExtensions)) {
        $audioFiles[] = ['name' => $file, 'mtime' => filemtime($uploadDir . '/' . $file)];
    }
}
usort($audioFiles, function ($a, $b) { return $a['mtime'] - $b['mtime']; });
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>音频审核与播出</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background-color: #f5f5f5; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { color: #333; margin: 0; }
        .logout-btn { padding: 8px 16px; background-color: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .logout-btn:hover { background-color: #d32f2f; }
        .audio-container { background-color: white; border-radius: 8px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .audio-info { margin-bottom: 10px; font-weight: bold; }
        .audio-meta { font-size: 13px; color: #555; margin-bottom: 8px; line-height: 1.5; }
        .audio-meta .times { color: #888; font-weight: normal; }
        .audio-meta .usage { color: #666; font-weight: normal; }
        .controls { display: flex; gap: 10px; flex-wrap: wrap; }
        button { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .review-btn { background-color: #42bd56; color: white; }
        .play-btn { background-color: #2196F3; color: white; }
        .discard-btn { background-color: #f44336; color: white; }
        button:hover { opacity: 0.9; }
        .empty-message { text-align: center; color: #666; padding: 20px; }
        .status { margin-top: 10px; color: #666; font-style: italic; }
        .listen-link { font-size: 12px; color: #2196F3; text-decoration: none; margin-left: 6px; }
        .back-link { display: inline-block; margin-bottom: 15px; color: #2196F3; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>音频审核与播出</h1>
        <a href="?action=logout" class="logout-btn">退出登录</a>
    </div>
    <a href="../air.php" class="back-link">→ 前往播出控制台</a>
    <a href="../tongji.php" class="back-link" style="margin-left:10px;">→ 查看各班投稿统计排行榜</a>

    <?php if (empty($audioFiles)): ?>
        <div class="empty-message">当前没有待审核的投稿</div>
    <?php else: ?>
        <?php foreach ($audioFiles as $af):
            $file = $af['name'];
            $meta = $pendingMeta[$file] ?? null;
            $id = md5($file);
        ?>
            <div class="audio-container" id="card-<?php echo $id; ?>">
                <div class="audio-info">
                    <?php if ($meta): ?>
                        <?php echo htmlspecialchars($meta['班级'] . ' · ' . $meta['姓名']); ?>
                        <?php if (!empty($meta['用途说明'])): ?>
                            <span class="usage">（<?php echo htmlspecialchars($meta['用途说明']); ?>）</span>
                        <?php endif; ?>
                        <div class="audio-meta">
                            提交时间：<span class="times"><?php echo htmlspecialchars($meta['提交时间']); ?></span>
                            <br>文件名：<span class="times"><?php echo htmlspecialchars($file); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="audio-meta">旧稿件（无登记记录 · 提交时间：<span class="times"><?php echo date('Y-m-d H:i', $af['mtime']); ?></span>）<br>文件名：<span class="times"><?php echo htmlspecialchars($file); ?></span></div>
                    <?php endif; ?>
                </div>
                <audio id="audio-<?php echo $id; ?>" controls preload="none" style="width:100%; margin-bottom:10px;">
                    <source src="stream.php?f=<?php echo rawurlencode($file); ?>" type="<?php echo audio_mime_for($uploadDir . '/' . $file); ?>">
                    您的浏览器不支持音频播放
                </audio>
                <div class="controls">
                    <button class="review-btn" onclick="document.getElementById('audio-<?php echo $id; ?>').play()">审核</button>
                    <button class="play-btn" onclick="approveFile(<?php echo htmlspecialchars(json_encode($file), ENT_QUOTES); ?>, '<?php echo $id; ?>')">审核通过</button>
                    <button class="discard-btn" onclick="discardFile(<?php echo htmlspecialchars(json_encode($file), ENT_QUOTES); ?>, '<?php echo $id; ?>')">丢弃</button>
                </div>
                <div id="status-<?php echo $id; ?>" class="status"></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        // 审核通过：把文件移入学生待播队列（AJAX），并支持连续审核下一条
        function approveFile(filename, id) {
            const status = document.getElementById('status-' + id);
            status.textContent = '正在提交到待播队列...';
            const fd = new FormData();
            fd.append('action', 'approve');
            fd.append('filename', filename);
            fetch('', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(res => {
                if (res === 'success') {
                    status.textContent = '已通过，进入待播队列。';
                    const card = document.getElementById('card-' + id);
                    setTimeout(() => { card.style.transition = 'opacity .5s'; card.style.opacity = '0'; setTimeout(() => { card.remove(); autoPlayNext(); }, 500); }, 800);
                } else {
                    status.textContent = '操作失败，请重试';
                }
            })
            .catch(() => { status.textContent = '操作失败，请重试'; });
        }

        // 处理完当前，自动选中下一条待审稿件（首个卡片）
        function autoPlayNext() {
            const card = document.querySelector('.audio-container');
            if (card) {
                const audio = card.querySelector('audio');
                if (audio) audio.play();
            } else {
                showEmptyMessage();
            }
        }

        // 显示空提示
        function showEmptyMessage() {
            if (!document.querySelector('.empty-message')) {
                const div = document.createElement('div');
                div.className = 'empty-message';
                div.textContent = '当前没有待审核的投稿';
                document.body.appendChild(div);
            }
        }

        // 丢弃：免刷新（AJAX）
        function discardFile(filename, id) {
            if (!confirm('确定要丢弃这个稿件吗？')) return;
            const status = document.getElementById('status-' + id);
            status.textContent = '正在丢弃...';
            const fd = new FormData();
            fd.append('action', 'discard');
            fd.append('filename', filename);
            fetch('', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(res => {
                if (res === 'success') {
                    const card = document.getElementById('card-' + id);
                    card.style.transition = 'opacity .5s';
                    card.style.opacity = '0';
                    setTimeout(() => { card.remove(); if (!document.querySelector('.audio-container')) { showEmptyMessage(); } }, 500);
                } else {
                    status.textContent = '丢弃失败，请重试';
                }
            })
            .catch(() => { status.textContent = '丢弃出错，请重试'; });
        }
    </script>
</body>
</html>