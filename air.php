<?php
// 播出控制页强制走 HTTP（免自签名证书信任，手机内置浏览器才能播音频）
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '172.19.200.187';
    header('Location: http://' . $host . $_SERVER['REQUEST_URI']);
    exit;
}

/**
 * 播出控制页（air.php）
 * 两个待播队列：学生投稿队列（审核通过后进入）、老师喊话队列（免审核直接进入）。
 * - 手动播出：点某条立即播；自动播出：先入先出顺序播放。
 * - 老师队列优先：自动模式下，新到老师稿件可打断正在播的学生稿件（学生暂停记录进度），
 *   老师播完后学生自动续播。
 * - 播出完成的文件分别移入「已播放」（学生）/「老师已播」并登记为已播出。
 */
require_once __DIR__ . '/tougao_config.php';

date_default_timezone_set('Asia/Shanghai');
session_start();

// 权限码登录（与审核页/统计页共享同一权限码）
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
    $_SESSION['authenticated'] = false; session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    ?><!DOCTYPE html>
    <html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>播出控制</title>
    <style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5}.box{background:#fff;padding:2rem;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);width:300px}h1{text-align:center;color:#333}.g{margin:1rem 0}label{display:block;margin-bottom:.4rem;color:#666}input{width:100%;padding:.8rem;border:1px solid #ddd;border-radius:4px;box-sizing:border-box}button{width:100%;padding:.8rem;background:#2196F3;color:#fff;border:none;border-radius:4px;font-size:16px;cursor:pointer}.error{color:#f44336;background:#ffebee;border-radius:4px;padding:.5rem;margin-top:1rem}</style></head>
    <body><div class="box"><h1>播出控制 · 权限验证</h1>
    <?php if (isset($loginError)): ?><div class="error"><?php echo $loginError; ?></div><?php endif; ?>
    <form method="post"><div class="g"><label>权限码</label><input type="password" name="access_code" required autofocus></div>
    <button type="submit">进入控制台</button></div></body></html><?php
    exit;
}

$studentQueueDir = tougao_ensure_dir(TOUGAO_STUDENT_QUEUE_DIR);
$teacherQueueDir = tougao_ensure_dir(TOUGAO_TEACHER_QUEUE_DIR);
$studentPlayedDir = tougao_ensure_dir(TOUGAO_PLAYED_DIR);
$teacherPlayedDir = tougao_ensure_dir(TOUGAO_TEACHER_PLAYED_DIR);
$m = tougao_db();
$allowedExtensions = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'webm', 'mp4', 'aac'];

function scan_audio($dir, $allowed) {
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) continue;
        if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed)) {
            $out[] = ['name' => $f, 'mtime' => filemtime($dir . '/' . $f)];
        }
    }
    usort($out, function ($a, $b) { return $a['mtime'] - $b['mtime']; });
    return $out;
}

// ---------- AJAX：获取两个队列 + 元信息 ----------
if (isset($_GET['action']) && $_GET['action'] === 'getQueues') {
    header('Content-Type: application/json; charset=utf-8');
    $meta = [];
    if ($m) {
        $q = $m->query("SELECT `文件名`,`班级`,`姓名`,`用途说明`,`来源`,`提交时间` FROM `" . TOUGAO_RECORD_TABLE . "` WHERE `状态`='待播出'");
        if ($q) { while ($row = $q->fetch_assoc()) { $meta[$row['文件名']] = $row; } $q->close(); }
        $m->close();
    }
    $res = ['student' => [], 'teacher' => []];
    foreach (scan_audio($studentQueueDir, $allowedExtensions) as $af) $res['student'][] = ['name' => $af['name'], 'mtime' => $af['mtime'], 'meta' => ($meta[$af['name']] ?? null)];
    foreach (scan_audio($teacherQueueDir, $allowedExtensions) as $af) $res['teacher'][] = ['name' => $af['name'], 'mtime' => $af['mtime'], 'meta' => ($meta[$af['name']] ?? null)];
    echo json_encode($res);
    exit;
}

// ---------- AJAX：标记播出完成（移动文件 + 登记） ----------
if (isset($_POST['action']) && $_POST['action'] === 'markPlayed') {
    header('Content-Type: application/json; charset=utf-8');
    $queue = $_POST['queue'] ?? '';
    $filename = $_POST['filename'] ?? '';
    if (($queue !== 'student' && $queue !== 'teacher') || $filename === '' || strpos($filename, '..') !== false) {
        echo json_encode(['ok' => false, 'msg' => '无效操作']); exit;
    }
    $srcDir = $queue === 'student' ? $studentQueueDir : $teacherQueueDir;
    $destDir = $queue === 'student' ? $studentPlayedDir : $teacherPlayedDir;
    $base = basename($filename);
    $src = $srcDir . '/' . $base;
    if (!file_exists($src)) { echo json_encode(['ok' => false, 'msg' => '文件不存在']); exit; }
    $dest = $destDir . '/' . $base;
    if (file_exists($dest)) {
        $info = pathinfo($base);
        $dest = $destDir . '/' . $info['filename'] . '_' . time() . '.' . $info['extension'];
    }
    if (rename($src, $dest)) {
        $finalName = basename($dest);
        $dbOk = false;
        if ($m) {
            $now = date('Y-m-d H:i:s');
            $stmt = $m->prepare("UPDATE `" . TOUGAO_RECORD_TABLE . "` SET `状态`='已播出', `播出时间`=?, `文件名`=? WHERE `文件名`=? AND `状态`='待播出'");
            if ($stmt) { $stmt->bind_param('sss', $now, $finalName, $base); $dbOk = $stmt->execute(); $stmt->close(); }
            $m->close();
        }
        echo json_encode(['ok' => true, 'finalName' => $finalName, 'db' => $dbOk]);
    } else {
        echo json_encode(['ok' => false, 'msg' => '移动失败']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>播出控制</title>
<style>
    body { font-family: Arial, sans-serif; max-width: 920px; margin: 0 auto; padding: 15px; background: #f5f5f5; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .topbar h1 { margin: 0; color: #333; font-size: 22px; }
    .links a { color: #2196F3; text-decoration: none; font-size: 13px; margin-left: 12px; }
    .logout { color: #f44336; }
    .now-playing { background: #222; color: #eee; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 14px; }
    .now-playing b { color: #ffd54f; }
    .controls-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
    #autoBtn { padding: 8px 20px; border: none; border-radius: 5px; font-size: 15px; cursor: pointer; }
    #autoBtn.on { background: #4CAF50; color: #fff; }
    #autoBtn.off { background: #9e9e9e; color: #fff; }
    .queue { background: #fff; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; box-shadow: 0 2px 4px rgba(0,0,0,.08); }
    .queue h2 { margin: 0 0 8px; font-size: 16px; }
    .queue h2 .badge { font-size: 12px; background: #eee; border-radius: 10px; padding: 1px 8px; margin-left: 6px; color: #555; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 7px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    th { color: #888; font-weight: normal; font-size: 12px; }
    .que th { background: #fff0e6; }
    .stu th { background: #e8f0fe; }
    .empty { color: #999; padding: 10px 0; font-size: 13px; }
    .rowbtns button { margin: 0 3px; padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
    .play-now { background: #4CAF50; color: #fff; }
    audio { height: 28px; max-width: 220px; }
    .playing-row td { background: #fffde7; }
</style>
</head>
<body>
<div class="topbar">
    <h1>播出控制台</h1>
    <div class="links">
        <a href="tongji.php">统计排行</a>
        <a href="uploads/playcontrols.php">审核页</a>
        <a href="index.php">主页</a>
        <a href="?action=logout" class="logout">退出</a>
    </div>
</div>

<div class="now-playing" id="nowPlaying">当前：<b>空闲</b></div>

<div class="controls-bar">
    <button id="autoBtn" class="off">自动播出：关</button>
    <span style="font-size:13px;color:#666;">开启后先入先出播放；老师队列优先，新老师稿件可打断学生稿件。</span>
</div>

<div class="queue que">
    <h2>老师喊话队列 <span class="badge" id="tCount">0</span></h2>
    <table><thead><tr><th>署名 / 内容</th><th>操作</th><th>试听</th></tr></thead><tbody id="tBody"></tbody></table>
    <div class="empty" id="tEmpty">当前无老师稿件</div>
</div>

<div class="queue stu">
    <h2>学生投稿队列 <span class="badge" id="sCount">0</span></h2>
    <table><thead><tr><th>班级 / 姓名</th><th>操作</th><th>试听</th></tr></thead><tbody id="sBody"></tbody></table>
    <div class="empty" id="sEmpty">当前无学生稿件</div>
</div>

<audio id="player" style="display:none;"></audio>

<script>
(function () {
    var teacherArr = [], studentArr = [];
    var playing = null;      // {kind, name}
    var pendingResume = null; // {name, pos} 被老师打断待续播的学生
    var autoOn = false;

    var player = document.getElementById('player');
    var autoBtn = document.getElementById('autoBtn');
    var nowPlaying = document.getElementById('nowPlaying');
    var tBody = document.getElementById('tBody'), sBody = document.getElementById('sBody');
    var tCount = document.getElementById('tCount'), sCount = document.getElementById('sCount');
    var tEmpty = document.getElementById('tEmpty'), sEmpty = document.getElementById('sEmpty');

    var DIRS = { teacher: 'uploads/老师待播', student: 'uploads/学生待播' };

    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function urlFor(dir, name) { return dir + '/' + encodeURIComponent(name); }

    function fmtMeta(meta, isTeacher) {
        if (!meta) return isTeacher ? '（老师稿件）' : '（学生稿件）';
        if (isTeacher) {
            var n = meta['姓名'] || meta['班级'] || '';
            return meta['用途说明'] ? (n + ' — ' + meta['用途说明']) : (n || '（老师稿件）');
        }
        var base = (meta['班级'] || '') + (meta['姓名'] ? ' · ' + meta['姓名'] : '');
        return meta['用途说明'] ? (base + '（' + meta['用途说明'] + '）') : (base || '（学生稿件）');
    }

    function setNowPlaying(kind, name) {
        playing = { kind: kind, name: name };
        var arr = kind === 'teacher' ? teacherArr : studentArr;
        var it = null;
        for (var i = 0; i < arr.length; i++) if (arr[i].name === name) { it = arr[i]; break; }
        var label = kind === 'teacher' ? '老师' : '学生';
        var b = nowPlaying.querySelector('b');
        b.textContent = '正在播出【' + label + '】' + (it ? fmtMeta(it.meta, kind === 'teacher') : '');
        render();
    }
    function clearNow() { playing = null; nowPlaying.querySelector('b').textContent = '空闲'; nowPlaying.removeAttribute('data-extra'); }

    // 立即播任意条目
    function playNow(kind, name) {
        // 若正在播学生且未结束 -> 记录恢复点（供老师打断后续播）
        if (playing && playing.kind === 'student' && !player.paused && !player.ended) {
            pendingResume = { name: playing.name, pos: player.currentTime };
        }
        player.src = urlFor(DIRS[kind], name);
        player.play();
        setNowPlaying(kind, name);
    }

    // 进入下一跳（自动模式）：老师优先；否则续播/播学生
    function autoStep() {
        if (!autoOn) return;
        var t = teacherArr.length ? teacherArr[0] : null;
        if (t) {
            if (playing && playing.kind === 'teacher' && playing.name === t.name) return; // 正在播该条，勿重入
            playNow('teacher', t.name);   // 打断学生或启动
            return;
        }
        // 无老师稿件
        if (playing) return;              // 正在播学生，等结束
        if (pendingResume) { var p = pendingResume; pendingResume = null; playNow('student', p.name); return; }
        if (studentArr.length) {
            var s = studentArr[0];
            playNow('student', s.name);
        }
    }

    // 播完 -> 移动文件 + 登记
    player.addEventListener('ended', function () {
        if (!playing) return;
        var kind = playing.kind, name = playing.name;
        var fd = new FormData();
        fd.append('action', 'markPlayed');
        fd.append('queue', kind);
        fd.append('filename', name);
        fetch('', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
            // 从本地队列移除
            var arr = kind === 'teacher' ? teacherArr : studentArr;
            for (var i = 0; i < arr.length; i++) if (arr[i].name === name) { arr.splice(i, 1); break; }
            var isTeacher = kind === 'teacher';
            var willResume = false, resumePos = 0;
            if (isTeacher && pendingResume) { willResume = true; resumePos = pendingResume.pos; var pr = pendingResume; pendingResume = null; }
            clearNow();
            if (!isTeacher && pendingResume && pendingResume.name === name) pendingResume = null; // 学生自己播完，清除其恢复点
            if (willResume) {
                var dir = DIRS.student;
                player.src = urlFor(dir, pr.name);
                player.play();
                setNowPlaying('student', pr.name);
                if (resumePos > 0) { player.addEventListener('loadedmetadata', function () { player.currentTime = resumePos; }, { once: true }); }
            }
            render();
            autoStep();
        });
    });

    // 渲染
    function renderQueue(kind) {
        var arr = kind === 'teacher' ? teacherArr : studentArr;
        var body = kind === 'teacher' ? tBody : sBody;
        var count = kind === 'teacher' ? tCount : sCount;
        var empty = kind === 'teacher' ? tEmpty : sEmpty;
        body.innerHTML = ''; count.textContent = arr.length; empty.style.display = arr.length ? 'none' : 'block';
        arr.forEach(function (it) {
            var tr = el('tr');
            if (playing && playing.kind === kind && playing.name === it.name) tr.className = 'playing-row';
            tr.appendChild(el('td', null, fmtMeta(it.meta, kind === 'teacher')));
            var td = el('td');
            var btn = el('button', 'play-now play-now', '手动播出');
            btn.onclick = (function (k, n) { return function () { playNow(k, n); }; })(kind, it.name);
            td.appendChild(btn); tr.appendChild(td);
            var td2 = el('td'); td2.appendChild(el('audio', null, '<source src="' + urlFor(DIRS[kind], it.name) + '">'));
            tr.appendChild(td2);
            body.appendChild(tr);
        });
    }
    function render() { renderQueue('teacher'); renderQueue('student'); }

    // 合并队列，按服务端顺序（先入先出），保留正在播的
    function mergeArr(oldArr, items) {
        var byIdx = {};
        items.forEach(function (n, i) { byIdx[n.name] = i; });
        var known = {};
        for (var i = 0; i < oldArr.length; i++) known[oldArr[i].name] = true;
        var merged = [];
        items.forEach(function (n) {
            if (known[n.name]) {
                for (var j = 0; j < oldArr.length; j++) if (oldArr[j].name === n.name) merged.push(oldArr[j]);
            } else {
                merged.push(n);
            }
        });
        return merged;
    }

    function poll() {
        fetch('?action=getQueues').then(function (r) { return r.json(); }).then(function (data) {
            teacherArr = mergeArr(teacherArr, data.teacher);
            studentArr = mergeArr(studentArr, data.student);
            render();
            autoStep();
        });
    }

    autoBtn.addEventListener('click', function () {
        autoOn = !autoOn;
        autoBtn.textContent = '自动播出：' + (autoOn ? '开' : '关');
        autoBtn.className = autoOn ? 'on' : 'off';
        if (autoOn) autoStep();
    });

    setInterval(poll, 2500);
    poll();
})();
</script>
</body>
</html>