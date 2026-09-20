<?php
// 投稿录音页必须走 HTTPS（浏览器 getUserMedia 录音要求安全上下文），HTTP 访问强制跳转
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '172.19.200.187';
    header('Location: https://' . $host . $_SERVER['REQUEST_URI']);
    exit;
}

// 引入共享配置（名单核验、投稿记录、稿件池目录）
require_once __DIR__ . '/tougao_config.php';

date_default_timezone_set('Asia/Shanghai');

$m = tougao_db();
$uploadMessage = '';
$uploadSuccess = false;
$classList = [];

// 从班级名提取入学年份用于排序。命名规律：16级/17级…、2018级…2026级、以及 24数字制造03班/26物流2班 等
function class_year($c) {
    $c = trim($c);
    if (preg_match('/20(\d{2})级/', $c, $m)) return 2000 + (int)$m[1];   // 2025级/2026级/2024级…
    if (preg_match('/20\d{2}/', $c, $m)) return (int)$m[0];              // 2018级/2019级…
    if (preg_match('/\b(2[0-6])\b/', $c, $m)) return 2000 + (int)$m[1]; // 26物流2班/24数字制造03班
    if (preg_match('/^(1[6-9])级/', $c, $m)) return 2000 + (int)$m[1];  // 16级-19级
    return 0;
}

// 加载班级下拉列表（名单表去重，按入学年份从新到旧排序，新生班级在前）
if ($m) {
    $stmt = $m->query("SELECT DISTINCT `班级` FROM `" . TOUGAO_ROSTER_TABLE . "` WHERE `班级` <> ''");
    if ($stmt) {
        while ($row = $stmt->fetch_row()) {
            $classList[] = $row[0];
        }
        $stmt->close();
        usort($classList, function ($a, $b) {
            $ya = class_year($a); $yb = class_year($b);
            if ($ya !== $yb) return $yb - $ya;   // 年份新的在前
            return strcmp($a, $b);               // 同年级按班级名排序
        });
    }
}

// ---------- 名单核验接口（AJAX）----------
if (isset($_GET['action']) && $_GET['action'] === 'verify') {
    header('Content-Type: application/json; charset=utf-8');
    $className = trim($_GET['class'] ?? '');
    $name = trim($_GET['name'] ?? '');
    if ($className === '' || $name === '') {
        echo json_encode(['ok' => false, 'msg' => '请先选择班级并填写姓名']);
        exit;
    }
    $res = tougao_verify_student($className, $name);
    if ($res === null) {
        echo json_encode(['ok' => false, 'msg' => '名单服务暂不可用，请稍后再试']);
    } elseif ($res) {
        echo json_encode(['ok' => true, 'msg' => '名单核验通过']);
    } else {
        echo json_encode(['ok' => false, 'msg' => '该姓名不在所选班级名单内，无法投稿']);
    }
    exit;
}

// ---------- 处理音频上传（录音或已有音频）----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $className = trim($_POST['class'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $usage = trim($_POST['usage'] ?? '');

    if ($className === '' || $name === '') {
        $uploadMessage = '请选择班级并填写姓名';
    } else {
        // 名单核验（服务端二次校验，防绕过）
        $v = tougao_verify_student($className, $name);
        if ($v === null) {
            $uploadMessage = '名单服务暂不可用，请稍后再试';
        } elseif ($v === false) {
            $uploadMessage = '该姓名不在所选班级名单内，无法投稿';
        } else {
            // 确定是录音还是已有音频
            $audioFile = null;
            $fileType = '';
            $recordType = '录音';
            if (isset($_FILES['recordedAudio']) && $_FILES['recordedAudio']['error'] !== UPLOAD_ERR_NO_FILE) {
                $audioFile = $_FILES['recordedAudio'];
                $fileType = '录制的音频';
            } elseif (isset($_FILES['existingAudio']) && $_FILES['existingAudio']['error'] !== UPLOAD_ERR_NO_FILE) {
                $audioFile = $_FILES['existingAudio'];
                $fileType = '上传的音频';
                $recordType = '上传';
            }

            if (!$audioFile) {
                $uploadMessage = '未检测到音频文件';
            } elseif ($audioFile['error'] !== UPLOAD_ERR_OK) {
                $uploadMessage = $fileType . '上传失败，请重试。错误代码: ' . $audioFile['error'];
            } elseif ($audioFile['size'] > 15 * 1024 * 1024) {
                $uploadMessage = '文件过大，请上传小于15MB的音频文件。';
            } else {
                $allowedMimeTypes = ['audio/wav', 'audio/mpeg', 'audio/mp3', 'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/x-m4a'];
                if (!in_array($audioFile['type'], $allowedMimeTypes)) {
                    $uploadMessage = '不支持的文件类型。请上传音频文件。';
                } else {
                    $extension = strtolower(pathinfo($audioFile['name'], PATHINFO_EXTENSION));
                    $prefix = tougao_clean_name($className) . '_' . tougao_clean_name($name);
                    if ($prefix === '_' ) $prefix = 'recording';
                    $timestamp = date('YmdHis');
                    $filename = $prefix . '_' . $timestamp . '.' . $extension;
                    $uploadDir = tougao_ensure_dir(TOUGAO_UPLOAD_DIR);
                    $destination = $uploadDir . '/' . $filename;

                    if (move_uploaded_file($audioFile['tmp_name'], $destination)) {
                        // 登记投稿记录
                        $now = date('Y-m-d H:i:s');
                        $insertOk = false;
                        if ($m) {
                            $ins = $m->prepare("INSERT INTO `" . TOUGAO_RECORD_TABLE . "` (`班级`,`姓名`,`用途说明`,`类型`,`文件名`,`状态`,`提交时间`) VALUES (?,?,?,?,?, '待审核', ?)");
                            if ($ins) {
                                $ins->bind_param('ssssss', $className, $name, $usage, $recordType, $filename, $now);
                                $insertOk = $ins->execute();
                                $ins->close();
                            }
                        }
                        $uploadSuccess = true;
                        $uploadMessage = $fileType . "投稿成功！文件已保存为: {$filename}" . ($insertOk ? '（已登记）' : '（登记失败，请留意）');
                    } else {
                        $uploadMessage = '保存文件时发生错误。';
                    }
                }
            }
        }
    }
}

// ---------- 查询「已播出」稿件用于公开展示 ----------
$playedRecords = [];
if ($m) {
    $q = $m->query("SELECT `班级`,`姓名`,`用途说明`,`文件名`,`播出时间` FROM `" . TOUGAO_RECORD_TABLE . "` WHERE `状态` = '已播出' ORDER BY `播出时间` DESC LIMIT 50");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $playedRecords[] = $row;
        }
        $q->close();
    }
}

if ($m) { $m->close(); }

$uploadDirUrl = 'uploads/';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>机电系运动会广播投稿</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin: 0 auto 30px;
            max-width: 100%;
        }
        .nav-links {
            display: flex;
            justify-content: center;
            gap: 14px;
            flex-wrap: wrap;
            margin: -12px 0 24px;
        }
        .nav-links a {
            display: inline-block;
            padding: 8px 20px;
            background: #2196F3;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-size: 15px;
        }
        .nav-links a:hover { background: #1976D2; }
        .function-section {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            text-align: left;
        }
        .function-section h2 {
            text-align: center;
        }
        button {
            padding: 10px 20px;
            font-size: 16px;
            margin: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        #startBtn {
            background-color: #4CAF50;
            color: white;
        }
        #stopBtn {
            background-color: #f44336;
            color: white;
            display: none;
        }
        .submit-btn {
            background-color: #2196F3;
            color: white;
            display: none;
        }
        .status {
            margin: 20px 0;
            padding: 10px;
            border-radius: 5px;
            display: none;
        }
        .success {
            background-color: #dff0d8;
            color: #3c763d;
            display: block;
        }
        .error {
            background-color: #f2dede;
            color: #a94442;
            display: block;
        }
        .preview {
            margin: 20px 0;
        }
        .recording-indicator {
            color: #f44336;
            font-weight: bold;
            display: none;
            text-align: center;
        }
        .identity-form {
            background-color: #f8f9fa;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        .identity-form label {
            display: inline-block;
            width: 70px;
            font-weight: bold;
        }
        .identity-form select,
        .identity-form input {
            padding: 8px;
            width: 260px;
            max-width: 100%;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .verify-result {
            padding: 8px;
            margin: 8px 0;
            border-radius: 4px;
            text-align: center;
            display: none;
            font-weight: bold;
        }
        .verify-result.ok {
            background-color: #dff0d8;
            color: #3c763d;
            display: block;
        }
        .verify-result.bad {
            background-color: #f2dede;
            color: #a94442;
            display: block;
        }
        .hint-text {
            font-size: 12px;
            color: #666;
            margin: 5px 0 0 0;
        }
        .progress-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            width: 300px;
            text-align: center;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #eee;
            border-radius: 10px;
            margin: 15px 0;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background-color: #4CAF50;
            width: 0%;
            transition: width 0.3s ease;
        }
        .listen-card {
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 12px;
            margin: 10px 0;
        }
        .listen-card .meta {
            font-weight: bold;
            margin-bottom: 6px;
        }
        .listen-card .usage {
            color: #666;
            font-size: 13px;
            margin-bottom: 6px;
        }
        .listen-card audio {
            width: 100%;
        }
        /* 波形图 + 电平表 */
        .record-viz {
            display: flex;
            align-items: stretch;
            gap: 8px;
            margin: 12px 0 8px;
            position: relative;
        }
        .viz-wave {
            flex: 1;
            min-width: 0;
            background: #14181d;
            border: 1px solid #2a2f37;
            border-radius: 6px;
            overflow: hidden;
            height: 120px;
        }
        #waveformCanvas {
            display: block;
            width: 100%;
            height: 120px;
        }
        .viz-meter {
            width: 44px;
            height: 120px;
            background: #14181d;
            border: 1px solid #2a2f37;
            border-radius: 6px;
            padding: 3px 2px;
            box-sizing: border-box;
            position: relative;
        }
        #meterCanvas {
            display: block;
            width: 34px;
            height: 92px;
            margin: 0 auto;
        }
        #dbReadout {
            color: #e6a23c;
            font-size: 10px;
            text-align: center;
            line-height: 1.2;
            margin-top: 3px;
            font-family: Menlo, Consolas, monospace;
        }
        .viz-time {
            position: absolute;
            right: 2px;
            top: 2px;
            color: #9aa0a6;
            font-size: 11px;
            background: rgba(0,0,0,0.5);
            padding: 1px 5px;
            border-radius: 3px;
            font-family: Menlo, Consolas, monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="title">机电系运动会广播投稿</h2>

        <!-- 功能页导航 -->
        <div class="nav-links">
            <a href="uploads/playcontrols.php">后台审核播放</a>
            <a href="air.php">播出控制</a>
            <a href="teacher.php" style="background:#e67e22;">老师投稿</a>
            <a href="tongji.php">投稿统计排行</a>
        </div>

        <?php if ($uploadMessage): ?>
            <div class="status <?php echo $uploadSuccess ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($uploadMessage); ?>
            </div>
        <?php endif; ?>

        <!-- 投稿身份表单（班级 + 姓名，必须核验通过才能投稿） -->
        <div class="identity-form" id="identityForm">
            <div>
                <label for="classSelect">班级</label>
                <select id="classSelect" required>
                    <option value="">请选择班级</option>
                    <?php foreach ($classList as $cls): ?>
                        <option value="<?php echo htmlspecialchars($cls); ?>"><?php echo htmlspecialchars($cls); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="nameInput">姓名</label>
                <input type="text" id="nameInput" placeholder="请输入姓名" maxlength="30" autocomplete="off">
            </div>
            <div>
                <label for="usageInput">用途说明</label>
                <input type="text" id="usageInput" placeholder="可选：点歌《xxx》/ 背景音乐等用途" maxlength="100">
            </div>
            <div class="verify-result" id="verifyResult"></div>
            <p class="hint-text" style="color:#f44336;">
                * 投稿须选班级并填写真实姓名，点击「开始录音」时自动核验名单，不在该班名单内无法投稿。
            </p>
        </div>

        <!-- 录音功能区 -->
        <div class="function-section">
            <h2>录音投稿</h2>
            <div class="recording-indicator" id="recordingIndicator">正在录制...</div>
            <!-- 波形图 + 电平表（-40dB ~ 0dB） -->
            <div class="record-viz">
                <div class="viz-wave">
                    <canvas id="waveformCanvas" width="660" height="120"></canvas>
                </div>
                <div class="viz-meter">
                    <canvas id="meterCanvas" width="40" height="120"></canvas>
                    <div id="dbReadout">-- dB</div>
                </div>
                <div class="viz-time" id="vizTime">00:00</div>
            </div>
            <div class="preview">
                <audio id="audioPreview" controls>您的浏览器不支持音频播放。</audio>
            </div>
            <div style="text-align:center;">
                <button type="button" id="startBtn">开始录音</button>
                <button type="button" id="stopBtn">停止录音</button>
                <button type="button" id="recordSubmitBtn" class="submit-btn">提交录音</button>
            </div>
        </div>

        <!-- 已有音频上传功能区 -->
        <div class="function-section">
            <h2>上传已有音频</h2>
            <p style="text-align:center;">支持格式：wav, mp3, webm, ogg（最大15MB），用途可在上方「用途说明」填写。</p>
            <div class="preview">
                <audio id="existingAudioPreview" controls>您的浏览器不支持音频播放。</audio>
            </div>
            <div style="text-align:center;">
                <input type="file" id="existingAudioInput" accept="audio/*" style="margin:10px 0;">
                <p id="fileInfo" style="margin:10px 0; color:#666;"></p>
                <button type="button" id="uploadSubmitBtn" class="submit-btn">提交上传</button>
            </div>
        </div>

        <!-- 倾听他人投稿（已录用播出） -->
        <div class="function-section">
            <h2>倾听已录用的投稿</h2>
            <?php if (empty($playedRecords)): ?>
                <p class="hint-text" style="text-align:center;">暂无已播出稿件</p>
            <?php else: ?>
                <?php foreach ($playedRecords as $rec): ?>
                    <div class="listen-card">
                        <div class="meta"><?php echo htmlspecialchars($rec['班级'] . '   ' . $rec['姓名']); ?>
                            <span style="font-weight:normal;font-size:12px;color:#666;"> · 播出 <?php echo htmlspecialchars($rec['播出时间']); ?></span>
                        </div>
                        <?php if (!empty($rec['用途说明'])): ?>
                            <div class="usage"><?php echo htmlspecialchars($rec['用途说明']); ?></div>
                        <?php endif; ?>
                        <audio controls preload="none">
                            <source src="<?php echo $uploadDirUrl . '已播放/' . rawurlencode($rec['文件名']); ?>">
                            您的浏览器不支持音频播放
                        </audio>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 上传进度弹窗 -->
    <div class="progress-modal" id="progressModal">
        <div class="modal-content">
            <h3>正在上传...</h3>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <p id="progressText">0%</p>
        </div>
    </div>

    <script>
        // 名单核验状态
        let identityVerified = false;

        // 记住上次投稿的班级与姓名，下次打开本页自动填好，避免重复选择/输入
        (function () {
            const LSK = 'tougao_identity';
            const restore = function () {
                try {
                    const saved = JSON.parse(localStorage.getItem(LSK) || '{}');
                    const sel = document.getElementById('classSelect');
                    const inp = document.getElementById('nameInput');
                    if (sel && saved.cls) {
                        // 班级下拉由 PHP 渲染，选项可能因名单变化而变化；仅当选项仍存在时才恢复
                        const opts = Array.prototype.slice.call(sel.options);
                        if (opts.some(o => o.value === saved.cls)) sel.value = saved.cls;
                    }
                    if (inp && saved.name) inp.value = saved.name;
                } catch (e) { /* 忽略，仅作辅助记忆 */ }
            };
            const persist = function () {
                try {
                    localStorage.setItem(LSK, JSON.stringify({
                        cls: document.getElementById('classSelect').value,
                        name: document.getElementById('nameInput').value
                    }));
                } catch (e) { /* 忽略 */ }
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    restore();
                    document.getElementById('classSelect').addEventListener('change', persist);
                    document.getElementById('nameInput').addEventListener('input', persist);
                });
            } else {
                restore();
                document.getElementById('classSelect').addEventListener('change', persist);
                document.getElementById('nameInput').addEventListener('input', persist);
            }
        })();

        // 录音变量
        let mediaRecorder, audioChunks = [], stream;

        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const recordSubmitBtn = document.getElementById('recordSubmitBtn');
        const uploadSubmitBtn = document.getElementById('uploadSubmitBtn');
        const audioPreview = document.getElementById('audioPreview');
        const existingAudioPreview = document.getElementById('existingAudioPreview');
        const recordingIndicator = document.getElementById('recordingIndicator');
        const recordedAudioFile = document.createElement('input');
        recordedAudioFile.type = 'file';
        recordedAudioFile.name = 'recordedAudio';
        recordedAudioFile.style.display = 'none';
        document.body.appendChild(recordedAudioFile);
        const existingAudioInput = document.getElementById('existingAudioInput');
        const classSelect = document.getElementById('classSelect');
        const nameInput = document.getElementById('nameInput');
        const usageInput = document.getElementById('usageInput');
        const verifyResult = document.getElementById('verifyResult');
        const fileInfo = document.getElementById('fileInfo');
        const progressModal = document.getElementById('progressModal');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');

        // 名单核验
        async function verifyIdentity(silent) {
            const cls = classSelect.value.trim();
            const name = nameInput.value.trim();
            if (!cls || !name) {
                if (!silent) { showVerify(false, '请先选择班级并填写姓名'); }
                identityVerified = false;
                return false;
            }
            try {
                const resp = await fetch('?action=verify&class=' + encodeURIComponent(cls) + '&name=' + encodeURIComponent(name));
                const data = await resp.json();
                identityVerified = !!data.ok;
                showVerify(data.ok, data.msg);
                return identityVerified;
            } catch (e) {
                if (!silent) { showVerify(false, '核验失败，请检查网络'); }
                identityVerified = false;
                return false;
            }
        }
        function showVerify(ok, msg) {
            verifyResult.textContent = msg;
            verifyResult.className = 'verify-result ' + (ok ? 'ok' : 'bad');
        }

        // 开始录音：先核验，通过才启动麦克风
        startBtn.addEventListener('click', async () => {
            if (!await verifyIdentity(false)) return;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                startRecordingViz(stream);
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];
                mediaRecorder.addEventListener('dataavailable', e => audioChunks.push(e.data));
                mediaRecorder.addEventListener('stop', () => {
                    // 用记录器真实输出的格式，避免标错 extension/type 导致无法播放
                    const recMime = mediaRecorder.mimeType || 'audio/webm';
                    let ext = 'webm', cleanMime = 'audio/webm';
                    if (recMime.includes('mp4') || recMime.includes('aac')) { ext = 'm4a'; cleanMime = 'audio/mp4'; }
                    else if (recMime.includes('ogg')) { ext = 'ogg'; cleanMime = 'audio/ogg'; }
                    const blob = new Blob(audioChunks, { type: cleanMime });
                    recordedBlob = blob;
                    stopRecordingViz();
                    setupPreviewWaveform(blob);
                    audioPreview.src = URL.createObjectURL(blob);
                    const file = new File([blob], 'recorded.' + ext, { type: cleanMime });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    recordedAudioFile.files = dt.files;
                    recordSubmitBtn.style.display = 'inline-block';
                    recordingIndicator.style.display = 'none';
                });
                mediaRecorder.start();
                startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';
                recordingIndicator.style.display = 'block';
            } catch (err) {
                console.error(err);
                alert('无法访问麦克风，请确保已授予麦克风权限。');
            }
        });

        stopBtn.addEventListener('click', () => {
            mediaRecorder && mediaRecorder.stop();
            if (stream) stream.getTracks().forEach(t => t.stop());
            stopBtn.style.display = 'none';
            startBtn.style.display = 'inline-block';
        });

        // 已有文件预览
        existingAudioInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                if (file.size > 10 * 1024 * 1024) { alert('文件过大，请上传小于10MB的音频文件。'); existingAudioInput.value=''; uploadSubmitBtn.style.display='none'; return; }
                const allowed = ['audio/wav','audio/mpeg','audio/mp3','audio/webm','audio/ogg','audio/mp4','audio/aac','audio/x-m4a'];
                if (!allowed.includes(file.type)) { alert('不支持的文件类型。'); existingAudioInput.value=''; uploadSubmitBtn.style.display='none'; return; }
                existingAudioPreview.src = URL.createObjectURL(file);
                fileInfo.textContent = '已选择文件: ' + file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
                uploadSubmitBtn.style.display = 'inline-block';
            } else {
                uploadSubmitBtn.style.display = 'none';
                fileInfo.textContent = '';
            }
        });

        // 提交流程（录音或已有音频共用）
        async function handleSubmit(fileInput, mode) {
            if (!await verifyIdentity(false)) return;
            if (!fileInput.files.length) { alert('请先选择或录制音频文件'); return; }
            const fd = new FormData();
            fd.append('class', classSelect.value.trim());
            fd.append('name', nameInput.value.trim());
            fd.append('usage', usageInput.value.trim());
            fd.append(mode === 'record' ? 'recordedAudio' : 'existingAudio', fileInput.files[0]);

            progressModal.style.display = 'flex';
            progressFill.style.width = '0%';
            progressText.textContent = '0%';
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) {
                    const pct = Math.round(e.loaded / e.total * 100);
                    progressFill.style.width = pct + '%';
                    progressText.textContent = pct + '%';
                }
            });
            xhr.addEventListener('load', () => { progressModal.style.display = 'none'; window.location.reload(); });
            xhr.addEventListener('error', () => { progressModal.style.display = 'none'; alert('上传失败，请重试'); });
            xhr.send(fd);
        }

        recordSubmitBtn.addEventListener('click', () => handleSubmit(recordedAudioFile, 'record'));
        uploadSubmitBtn.addEventListener('click', () => handleSubmit(existingAudioInput, 'upload'));

        // ================= 波形图 + 电平表（-40dB ~ 0dB） =================
        let AC = null, strAna = null, elAna = null, elSrc = null;
        let recordedBlob = null, fullWave = null, peakHold = -60, recordStart = 0;
        let rafR = null, rafP = null, _meterGrad = null;
        const wfCanvas = document.getElementById('waveformCanvas');
        let wfCtx = null, meterCtx = null;
        const vizTime = document.getElementById('vizTime');
        const dbReadout = document.getElementById('dbReadout');

        function gu() {
            if (AC) { if (AC.state === 'suspended') AC.resume(); return; }
            const Ctx = window.AudioContext || window.webkitAudioContext;
            AC = new Ctx();
            if (AC.state === 'suspended') AC.resume();
            wfCtx = wfCanvas.getContext('2d');
            meterCtx = document.getElementById('meterCanvas').getContext('2d');
        }
        function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
        function fmtTime(sec) {
            if (!isFinite(sec) || sec < 0) sec = 0;
            const m = Math.floor(sec / 60), s = Math.floor(sec % 60);
            return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }
        function gain2dB(g) { return g > 1e-4 ? 20 * Math.log10(g) : -60; }
        function rmsOf(analyser) {
            const buf = new Float32Array(analyser.fftSize);
            analyser.getFloatTimeDomainData(buf);
            let s = 0;
            for (let i = 0; i < buf.length; i++) { const v = buf[i]; s += v * v; }
            return Math.sqrt(s / buf.length);
        }
        function rmsOfArray(buf) {
            let s = 0;
            for (let i = 0; i < buf.length; i++) { const v = buf[i]; s += v * v; }
            return Math.sqrt(s / buf.length);
        }
        // 电平表：y 从 dB 映射（0dB 在顶，-40dB 在底）
        function yOf(dB, H) { return H * (1 - ((dB + 40) / 40)); }
        function drawMeter(dB) {
            const H = meterCtx.canvas.height, W = meterCtx.canvas.width;
            meterCtx.clearRect(0, 0, W, H);
            // 刻度与标签（-40 -30 -20 -12 -6 0）
            const ticks = [[-40, '-40'], [-30, '-30'], [-20, '-20'], [-12, '-12'], [-6, '-6'], [0, '0']];
            meterCtx.font = '8px Arial';
            ticks.forEach(t => {
                const yy = yOf(t[0], H);
                meterCtx.strokeStyle = 'rgba(255,255,255,0.12)';
                meterCtx.beginPath(); meterCtx.moveTo(2, yy); meterCtx.lineTo(t[0] === 0 ? W - 2 : 7, yy); meterCtx.stroke();
                meterCtx.fillStyle = '#8a9199';
                meterCtx.fillText(t[1], 0, yy - 1);
            });
            // 电平条（绿->黄->橙->红）
            if (!_meterGrad) { _meterGrad = meterCtx.createLinearGradient(0, H, 0, 0); _meterGrad.addColorStop(0, '#4caf50'); _meterGrad.addColorStop(0.45, '#ffeb3b'); _meterGrad.addColorStop(0.72, '#ff9800'); _meterGrad.addColorStop(1, '#f44336'); }
            const fillTop = yOf(clamp(dB, -40, 0), H);
            meterCtx.fillStyle = _meterGrad;
            meterCtx.fillRect(9, fillTop, W - 9, H - fillTop);
            // 峰值保持
            if (dB > peakHold) peakHold = dB; else peakHold -= 0.7;
            if (peakHold < -40) peakHold = -40;
            const py = yOf(peakHold, H);
            meterCtx.fillStyle = '#e0e0e0';
            meterCtx.fillRect(9, py - 1, W - 9, 2);
            // dB 读数
            dbReadout.textContent = (peakHold <= -40 ? '≤-40' : (peakHold >= 0 ? '0' : peakHold.toFixed(1))) + ' dB';
        }
        // 录制时：实时示波波形
        function drawOsc(analyser) {
            const H = wfCanvas.height, W = wfCanvas.width, cx = H / 2;
            wfCtx.clearRect(0, 0, W, H);
            const buf = new Float32Array(analyser.fftSize);
            analyser.getFloatTimeDomainData(buf);
            wfCtx.beginPath();
            wfCtx.moveTo(0, cx - buf[0] * (cx - 6));
            for (let x = 1; x < W; x++) {
                const i = Math.min(buf.length - 1, ((x / (W - 1)) * buf.length) | 0);
                const y = cx - buf[i] * (cx - 6);
                wfCtx.lineTo(x, y);
            }
            wfCtx.strokeStyle = '#42a5f5'; wfCtx.lineWidth = 2; wfCtx.stroke();
            wfCtx.lineTo(W, cx); wfCtx.lineTo(0, cx); wfCtx.closePath();
            wfCtx.fillStyle = 'rgba(66,165,245,0.16)'; wfCtx.fill();
        }
        // 回放时：整段波形 + 播放头
        function drawFullWave(frac) {
            const H = wfCanvas.height, W = wfCanvas.width, cx = H / 2;
            wfCtx.clearRect(0, 0, W, H);
            if (!fullWave) { drawOsc(elAna); return; }
            const n = fullWave.length, step = W / n;
            for (let i = 0; i < n; i++) {
                const hgt = Math.max(1, fullWave[i] * (cx - 6));
                const x = i * step;
                wfCtx.fillStyle = x <= frac * W ? '#4fc3f7' : '#37424d';
                wfCtx.fillRect(x, cx - hgt, Math.max(1, step * 0.92), hgt * 2);
            }
            const px = frac * W;
            wfCtx.fillStyle = '#ffd54f';
            wfCtx.fillRect(px - 1, 2, 2, H - 4);
            wfCtx.beginPath(); wfCtx.arc(px, 2, 5, 0, 7); wfCtx.fill();
        }
        // 开始录音：接入麦克风分析器并启动波形/电平绘制
        function startRecordingViz(stream) {
            gu();
            strAna = AC.createAnalyser(); strAna.fftSize = 1024;
            const ms = AC.createMediaStreamSource(stream);
            ms.connect(strAna);
            fullWave = null; peakHold = -40; recordStart = performance.now();
            if (rafP) cancelAnimationFrame(rafP);
            if (rafR) cancelAnimationFrame(rafR);
            const loop = () => {
                drawMeter(gain2dB(rmsOf(strAna)));
                drawOsc(strAna);
                vizTime.textContent = fmtTime((performance.now() - recordStart) / 1000);
                rafR = requestAnimationFrame(loop);
            };
            rafR = requestAnimationFrame(loop);
        }
        function stopRecordingViz() {
            if (rafR) cancelAnimationFrame(rafR);
            rafR = null;
        }
        // 回放：解码录好的音频，生成整段波形
        async function setupPreviewWaveform(blob) {
            try {
                gu();
                const ab = await blob.arrayBuffer();
                const ad = await AC.decodeAudioData(ab);
                const d = ad.getChannelData(0);
                const W = wfCanvas.width, n = W, step = Math.max(1, Math.floor(d.length / n));
                fullWave = new Float32Array(n);
                for (let i = 0; i < n; i++) {
                    let mx = 0;
                    for (let j = i * step; j < (i + 1) * step && j < d.length; j++) { const a = Math.abs(d[j]); if (a > mx) mx = a; }
                    fullWave[i] = mx;
                }
            } catch (e) { fullWave = null; }
        }
        // 播放预览：接入音频元素分析器，更新波形与播放头
        function ensurePreviewAudio() {
            gu();
            if (!elAna) {
                elAna = AC.createAnalyser(); elAna.fftSize = 1024;
                elSrc = AC.createMediaElementSource(audioPreview);
                elSrc.connect(elAna); elAna.connect(AC.destination);
            }
            if (AC.state === 'suspended') AC.resume();
        }
        audioPreview.addEventListener('play', () => {
            ensurePreviewAudio();
            if (rafP) cancelAnimationFrame(rafP);
            peakHold = -40;
            const loop = () => {
                drawMeter(gain2dB(rmsOf(elAna)));
                const dur = audioPreview.duration;
                const frac = isFinite(dur) && dur > 0 ? audioPreview.currentTime / dur : 0;
                drawFullWave(frac);
                vizTime.textContent = fmtTime(audioPreview.currentTime) + ' / ' + fmtTime(dur);
                rafP = requestAnimationFrame(loop);
            };
            rafP = requestAnimationFrame(loop);
        });
        audioPreview.addEventListener('pause', () => { if (rafP) { cancelAnimationFrame(rafP); rafP = null; } });
        audioPreview.addEventListener('ended', () => { if (rafP) { cancelAnimationFrame(rafP); rafP = null; } });
    </script>
</body>
</html>