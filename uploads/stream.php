<?php
// 音频流式播放端点：按文件真实内容嗅探 MIME（而非扩展名），并支持 Range 断点
// 用法：stream.php?f=<文件名>（文件名由 PHP rawurlencode 传入，本脚本 urldecode 还原）
// 解决：MediaRecorder 产出的 WebM/Opus 文件若被 Apache 按扩展名/容器误判为 video/webm 等，
//       在 <audio> 元素中无法稳定播放的问题。

// 仅允许读取上传目录下的音频
$BASE = __DIR__;
$name = basename($_GET['f'] ?? '');
if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, "\0") !== false) {
    http_response_code(400);
    exit('invalid');
}

$path = $BASE . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('not found');
}

$size = filesize($path);

// ---------- 嗅探真实 MIME ----------
function sniff_mime($path) {
    $h = fopen($path, 'rb');
    if (!$h) return 'application/octet-stream';
    $head = fread($h, 16); // 读文件头用于判断容器
    fclose($h);
    if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
        return 'audio/webm';        // WebM/Opus —— 明确声明为音频
    }
    if (strncmp($head, 'OggS', 4) === 0) return 'audio/ogg';
    if (strncmp($head, 'fLaC', 4) === 0) return 'audio/flac';
    if (strncmp($head, 'ID3', 3) === 0 || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
        return 'audio/mpeg';        // MP3
    }
    if (strncmp($head, 'RIFF', 4) === 0 && strncmp(substr($head, 8, 4), 'WAVE', 4) === 0) {
        return 'audio/wav';
    }
    if (strncmp(substr($head, 4, 4), 'ftyp', 4) === 0) {
        return 'audio/mp4';         // MP4/M4A
    }
    return 'application/octet-stream';
}

$ctype = sniff_mime($path);

// ---------- Range 支持（供 <audio> 拖动/定位） ----------
$start = 0;
$end = $size - 1;
$useRange = false;
if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $useRange = true;
    }
}
$len = $end - $start + 1;

$fp = fopen($path, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit('read error');
}
fseek($fp, $start);

header('Content-Type: ' . $ctype);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $len);
header('Cache-Control: no-store');
if ($useRange) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

while (!feof($fp)) {
    $chunk = fread($fp, 8192);
    if ($chunk === false || strlen($chunk) === 0) break;
    echo $chunk;
    if (ob_get_level() > 0) ob_flush();
    flush();
}
fclose($fp);