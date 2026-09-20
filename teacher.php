<?php
// 老师投稿页需录音（getUserMedia 要求安全上下文），HTTP 访问强制跳转 HTTPS
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '172.19.200.187';
    header('Location: https://' . $host . $_SERVER['REQUEST_URI']);
    exit;
}

// 老师投稿页：输入密码后可直接录制/上传喊话，免审核，直接进入「老师待播队列」
require_once __DIR__ . '/tougao_config.php';

date_default_timezone_set('Asia/Shanghai');
session_start();

// 权限码登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
    if ($_POST['access_code'] === TOUGAO_TEACHER_CODE) {
        $_SESSION['teacher_auth'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $loginError = "权限码不正确，请重新输入";
    }
}
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['teacher_auth'] = false;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 未登录显示登录界面
if (!isset($_SESSION['teacher_auth']) || $_SESSION['teacher_auth'] !== true) {
    ?><!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>老师投稿</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background-color: #f5f5f5; }
            .login-box { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 300px; }
            h1 { text-align: center; color: #333; }
            .form-group { margin: 1rem 0; }
            label { display: block; margin-bottom: .4rem; color: #666; }
            input { width: 100%; padding: .8rem; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
            button { width: 100%; padding: .8rem; background: #2196F3; color: #fff; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; }
            .error { color: #f44336; background: #ffebee; border-radius: 4px; padding: .5rem; margin-top: 1rem; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>老师投稿 · 权限验证</h1>
            <?php if (isset($loginError)): ?><div class="error"><?php echo $loginError; ?></div><?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="access_code">权限码</label>
                    <input type="password" id="access_code" name="access_code" required autofocus>
                </div>
                <button type="submit">进入投稿</button>
            </form>
        </div>
    </body>
    </html><?php
    exit;
}

$m = tougao_db();
$uploadDir = tougao_ensure_dir(TOUGAO_TEACHER_QUEUE_DIR); // 老师待播队列
$msg = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shoutName = trim($_POST['shout_name'] ?? '');       // 署名（可空）
    $usage = trim($_POST['usage'] ?? '');                // 用途/内容说明
    $audioFile = null; $fileType = ''; $recordType = '录音';
    if (isset($_FILES['recordedAudio']) && $_FILES['recordedAudio']['error'] !== UPLOAD_ERR_NO_FILE) {
        $audioFile = $_FILES['recordedAudio']; $fileType = '录制的音频';
    } elseif (isset($_FILES['existingAudio']) && $_FILES['existingAudio']['error'] !== UPLOAD_ERR_NO_FILE) {
        $audioFile = $_FILES['existingAudio']; $fileType = '上传的音频'; $recordType = '上传';
    }
    if (!$audioFile) {
        $msg = '未检测到音频文件';
    } elseif ($audioFile['error'] !== UPLOAD_ERR_OK) {
        $msg = $fileType . '上传失败，请重试。错误代码: ' . $audioFile['error'];
    } elseif ($audioFile['size'] > 15 * 1024 * 1024) {
        $msg = '文件过大，请上传小于15MB的音频文件。';
    } else {
        $allowed = ['audio/wav','audio/mpeg','audio/mp3','audio/webm','audio/ogg','audio/mp4','audio/aac','audio/x-m4a'];
        if (!in_array($audioFile['type'], $allowed)) {
            $msg = '不支持的文件类型。请上传音频文件。';
        } else {
            $ext = strtolower(pathinfo($audioFile['name'], PATHINFO_EXTENSION));
            $prefix = '老师_' . tougao_clean_name($shoutName !== '' ? $shoutName : '广播室');
            if ($prefix === '老师_') $prefix = '老师';
            $filename = $prefix . '_' . date('YmdHis') . '.' . $ext;
            $dest = $uploadDir . '/' . $filename;
            if (move_uploaded_file($audioFile['tmp_name'], $dest)) {
                $insertOk = false;
                if ($m) {
                    $now = date('Y-m-d H:i:s');
                    $ins = $m->prepare("INSERT INTO `" . TOUGAO_RECORD_TABLE . "` (`班级`,`姓名`,`用途说明`,`类型`,`来源`,`文件名`,`状态`,`提交时间`) VALUES (?,?,?,?, '老师', ?, '待播出', ?)");
                    if ($ins) {
                        $ins->bind_param('ssssss', '老师', $shoutName === '' ? '广播室' : $shoutName, $usage, $recordType, $filename, $now);
                        $insertOk = $ins->execute();
                        $ins->close();
                    }
                }
                $ok = true;
                $msg = $fileType . "投稿成功，已进入播出队列！文件名: {$filename}" . ($insertOk ? '（已登记）' : '（登记失败，请留意）');
            } else {
                $msg = '保存文件时发生错误。';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>老师投稿</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 700px; margin: 0 auto; padding: 20px; background-color: #f5f5f5; }
        .container { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .title { text-align: center; margin: 0 0 20px; }
        .field { margin: 12px 0; }
        label { display: inline-block; width: 90px; font-weight: bold; }
        input[type=text] { padding: 8px; width: 300px; max-width: 100%; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .status { padding: 10px; border-radius: 5px; margin: 15px 0; display: none; }
        .status.ok { background: #dff0d8; color: #3c763d; display: block; }
        .status.err { background: #f2dede; color: #a94442; display: block; }
        .audio-box { margin: 15px 0; }
        .btn-row { text-align: center; margin-top: 15px; }
        button { padding: 10px 24px; font-size: 16px; margin: 5px; border: none; border-radius: 5px; cursor: pointer; }
        #startBtn { background: #4CAF50; color: #fff; }
        #stopBtn { background: #f44336; color: #fff; display: none; }
        #submitBtn { background: #2196F3; color: #fff; display: none; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .back, .logout { color: #2196F3; text-decoration: none; font-size: 14px; }
        .logout { color: #f44336; }
        .hint { font-size: 12px; color: #888; text-align: center; margin-top: 15px; }
        /* 波形图 + 电平表 */
        .record-viz { display: flex; align-items: stretch; gap: 8px; margin: 12px 0 8px; position: relative; }
        .viz-wave { flex: 1; min-width: 0; background: #14181d; border: 1px solid #2a2f37; border-radius: 6px; overflow: hidden; height: 120px; }
        #waveformCanvas { display: block; width: 100%; height: 120px; }
        .viz-meter { width: 44px; height: 120px; background: #14181d; border: 1px solid #2a2f37; border-radius: 6px; padding: 3px 2px; box-sizing: border-box; position: relative; }
        #meterCanvas { display: block; width: 34px; height: 92px; margin: 0 auto; }
        #dbReadout { color: #e6a23c; font-size: 10px; text-align: center; line-height: 1.2; margin-top: 3px; font-family: Menlo, Consolas, monospace; }
        .viz-time { position: absolute; right: 2px; top: 2px; color: #9aa0a6; font-size: 11px; background: rgba(0,0,0,0.5); padding: 1px 5px; border-radius: 3px; font-family: Menlo, Consolas, monospace; }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <a href="index.php" class="back">← 返回主页</a>
            <span class="title">老师投稿（免审核，直接进入播出队列）</span>
            <a href="?action=logout" class="logout">退出</a>
        </div>

        <div id="msg" class="status <?php echo $ok ? 'ok' : ($msg ? 'err' : ''); ?>"><?php echo htmlspecialchars($msg); ?></div>

        <div class="field">
            <label for="shoutName">署名</label>
            <input type="text" id="shoutName" placeholder="可空，例如：广播室 / 学生处" maxlength="30">
        </div>
        <div class="field">
            <label for="usage">内容说明</label>
            <input type="text" id="usage" placeholder="可选：喊话内容 / 提醒事项等" maxlength="100">
        </div>

        <div class="audio-box">
            <audio id="audioPreview" controls style="width:100%;">您的浏览器不支持音频播放</audio>
        </div>
        <!-- 波形图 + 电平表（-40dB ~ 0dB），与主投稿页一致 -->
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
        <div class="btn-row">
            <button id="startBtn">开始录音</button>
            <button id="stopBtn">停止录音</button>
        </div>
        <div class="btn-row">
            <button id="uploadBtn">选择已有音频</button>
            <input type="file" id="existingAudio" accept="audio/*" style="display:none;">
            <button id="submitBtn">提交投稿</button>
        </div>
        <p class="hint">老师投稿无需审核，提交后立即进入「老师待播队列」。</p>
    </div>

    <script>
        const existingAudioInput = (() => { const i = document.createElement('input'); i.type = 'file'; i.name = 'existingAudio'; i.style.display='none'; document.body.appendChild(i); return i; })();
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const uploadBtn = document.getElementById('uploadBtn');
        const submitBtn = document.getElementById('submitBtn');
        const audioPreview = document.getElementById('audioPreview');

        let mediaRecorder, chunks = [], stream;
        let file = null;
        let fileMode = null; // 'record' | 'upload'

        document.getElementById('existingAudio').addEventListener('change', e => {
            const f = e.target.files[0];
            if (!f) return;
            if (f.size > 15 * 1024 * 1024) { alert('文件过大，请小于15MB'); e.target.value=''; return; }
            if (!['audio/wav','audio/mpeg','audio/mp3','audio/webm','audio/ogg','audio/mp4','audio/aac','audio/x-m4a'].includes(f.type)) { alert('不支持的类型'); e.target.value=''; return; }
            fileMode = 'upload';
            file = f;
            audioPreview.src = URL.createObjectURL(f);
            uploadBtn.textContent = '已选: ' + f.name;
            submitBtn.style.display = 'inline-block';
        });

        uploadBtn.addEventListener('click', () => document.getElementById('existingAudio').click());

        startBtn.addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                startRecordingViz(stream);
                mediaRecorder = new MediaRecorder(stream);
                chunks = [];
                mediaRecorder.addEventListener('dataavailable', e => chunks.push(e.data));
                mediaRecorder.addEventListener('stop', () => {
                    // 记录器真实格式，避免标错 extension/type
                    const recMime = mediaRecorder.mimeType || 'audio/webm';
                    let ext = 'webm', cleanMime = 'audio/webm';
                    if (recMime.includes('mp4') || recMime.includes('aac')) { ext = 'm4a'; cleanMime = 'audio/mp4'; }
                    else if (recMime.includes('ogg')) { ext = 'ogg'; cleanMime = 'audio/ogg'; }
                    const blob = new Blob(chunks, { type: cleanMime });
                    file = new File([blob], 'record.' + ext, { type: cleanMime });
                    fileMode = 'record';
                    stopRecordingViz();
                    setupPreviewWaveform(blob);
                    audioPreview.src = URL.createObjectURL(blob);
                    stream.getTracks().forEach(t => t.stop());
                    submitBtn.style.display = 'inline-block';
                });
                mediaRecorder.start();
                startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';
            } catch (err) {
                alert('无法访问麦克风，请授予权限（需 HTTPS）');
            }
        });
        stopBtn.addEventListener('click', () => { mediaRecorder && mediaRecorder.stop(); stopBtn.style.display = 'none'; startBtn.style.display = 'inline-block'; });

        submitBtn.addEventListener('click', () => {
            if (!file) { alert('请先录音或选择音频'); return; }
            const fd = new FormData();
            fd.append('shout_name', document.getElementById('shoutName').value.trim());
            fd.append('usage', document.getElementById('usage').value.trim());
            if (fileMode === 'record') fd.append('recordedAudio', file, 'record.wav');
            else fd.append('existingAudio', file, file.name);
            submitBtn.disabled = true;
            fetch('', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(t => { location.href = location.pathname; })
            .catch(() => { submitBtn.disabled = false; alert('投稿失败，请重试'); });
        });

        // ================= 波形图 + 电平表（-40dB ~ 0dB），与主投稿页一致 =================
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
            const ticks = [[-40, '-40'], [-30, '-30'], [-20, '-20'], [-12, '-12'], [-6, '-6'], [0, '0']];
            meterCtx.font = '8px Arial';
            ticks.forEach(t => {
                const yy = yOf(t[0], H);
                meterCtx.strokeStyle = 'rgba(255,255,255,0.12)';
                meterCtx.beginPath(); meterCtx.moveTo(2, yy); meterCtx.lineTo(t[0] === 0 ? W - 2 : 7, yy); meterCtx.stroke();
                meterCtx.fillStyle = '#8a9199';
                meterCtx.fillText(t[1], 0, yy - 1);
            });
            if (!_meterGrad) { _meterGrad = meterCtx.createLinearGradient(0, H, 0, 0); _meterGrad.addColorStop(0, '#4caf50'); _meterGrad.addColorStop(0.45, '#ffeb3b'); _meterGrad.addColorStop(0.72, '#ff9800'); _meterGrad.addColorStop(1, '#f44336'); }
            const fillTop = yOf(clamp(dB, -40, 0), H);
            meterCtx.fillStyle = _meterGrad;
            meterCtx.fillRect(9, fillTop, W - 9, H - fillTop);
            if (dB > peakHold) peakHold = dB; else peakHold -= 0.7;
            if (peakHold < -40) peakHold = -40;
            const py = yOf(peakHold, H);
            meterCtx.fillStyle = '#e0e0e0';
            meterCtx.fillRect(9, py - 1, W - 9, 2);
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