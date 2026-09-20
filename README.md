# 运动会广播语音投稿系统 / Voice Broadcast Contribution System

> 学生在线录音投稿 → 名单核验 → 后台审核 → 队列播出，一套运行在校园网内的广播投稿管理平台。
> A campus-radio contribution management platform: students record & submit audio → roster verification → admin review → queue-based live broadcast.

[![PHP](https://img.shields.io/badge/PHP-8.4-blue)](https://www.php.net/)
[![Apache](https://img.shields.io/badge/Apache-2.4-green)](https://httpd.apache.org/)
[![Lang](https://img.shields.io/badge/lang-Chinese%20%7C%20English-lightgrey)]()

## 简介 / Overview

面向学校运动会等活动的广播主持场景：学生在手机端直接用浏览器「录音」投稿，系统自动核验该生是否在所选的班级名单里，管理员在后审核通过后进入「学生待播队列」，广播员在播出控制台一键播放或按队列顺序自动播出。老师可免审核直接投递喊话，优先插入播出队列。所有页面可在手机 / 电脑浏览器使用，无需安装 App。

Built for school sports-meet broadcasting: students record voice submissions directly in a browser, the name is checked against the class roster, an admin reviews and queues approved clips, and a broadcaster plays them from a console (manual or auto-queue). Teachers can submit announcements without review and preempt the student queue. Everything runs in the browser on phones or desktops — no app install required.

## 核心功能 / Features

- **在线录音**：网页端调用 `MediaRecorder` + `getUserMedia` 录音，实时示波波形 + 电平表（-40~0 dB）、峰值保持、时长显示
- **名单核验**：投稿时自动校验「班级 + 姓名」是否在学生名单中
- **后台审核**：逐条试听，通过 → 进入待播队列，或丢弃
- **播出控制台**：学生 / 老师双队列，手动或自动播放，老师可优先打断
- **HTML5 播放**：自定义 `stream.php` 端点按文件头嗅探真实 MIME 并支持 Range，确保手机内置浏览器可稳定播放；移动端免证书走 HTTP
- **统计排行**：按班级统计投稿 / 播出数量
- **移动端友好**：自适应布局，无需安装 App

- **In-browser recording**: uses `MediaRecorder` + `getUserMedia` with a live oscilloscope waveform, a decibel level meter (-40 to 0 dB, peak hold), and an elapsed-time readout
- **Roster verification**: automatically checks that the submitted 班级(class) + 姓名(name) exists in the student list
- **Admin review**: listen to each pending clip, approve into the play queue, or discard it
- **Broadcast console**: student and teacher queues, manual or auto playback, teacher announcements can preempt the queue
- **HTML5 playback**: a custom `stream.php` endpoint sniffs the real MIME from the file header and supports HTTP Range, so in-built mobile browsers play reliably; non-recording pages run over plain HTTP to avoid self-signed-certificate issues
- **Statistics**: per-class submission / broadcast ranking
- **Mobile-friendly**: responsive, no app installation required

## 技术栈 / Tech Stack

- PHP 8.4 + MySQL (mysqli, `utf8mb4`)
- Apache 2.4 (HTTPS 用于录音页，HTTP 用于管理 / 播放页)
- 纯前端 HTML / CSS / JavaScript（无构建步骤）
- PHP 端自签名嗅探 MIME 的音频流端点（`stream.php`）

- PHP 8.4 + MySQL (mysqli, `utf8mb4`)
- Apache 2.4 (HTTPS for recording pages, HTTP for management / playback pages)
- Plain HTML / CSS / JavaScript frontend (no build step)
- PHP streaming endpoint (`stream.php`) that sniffs the real MIME and supports Range

## 目录结构 / Structure

```
.
├── index.php                  # 学生投稿页（录音 / 上传，HTTPS）
├── teacher.php                # 老师投稿页（免审核喊话，HTTPS）
├── air.php                    # 播出控制台（学生 / 老师双队列）
├── tongji.php                 # 投稿统计排行榜
├── uploads/
│   ├── playcontrols.php       # 后台审核页
│   └── stream.php             # 音频流端点（嗅探 MIME + Range）
├── tougao_config.example.php  # 配置示例（复制为 tougao_config.php 后填写）
└── uploads/                   # 稿件池目录（运行期生成，不入库）
```

## 部署 / Deployment

1. 将文件放入 Apache 站点根目录（建议 PHP ≥ 8.0、开启 `mysqli`）。
2. 复制 `tougao_config.example.php` 为 `tougao_config.php`，填写数据库连接、名单表名、上传目录与权限码。
3. 数据库需存在名单表（含 `班级`、`姓名` 字段，表名默认为 `学生学籍号`）与投稿登记表（`投稿记录`）。
4. 录音页（`index.php` / `teacher.php`）必须通过 HTTPS 访问（浏览器 `getUserMedia` 需要安全上下文）；管理 / 播放 / 统计页可走 HTTP 以兼容手机内置浏览器。
5. 访问：`index.php` 投稿、`uploads/playcontrols.php` 审核、`air.php` 播出。

1. Put the files into your Apache site root (PHP ≥ 8.0 with `mysqli`).
2. Copy `tougao_config.example.php` to `tougao_config.php` and fill in the database connection, roster table name, upload directories and access codes.
3. The database must have a roster table (with `班级`/`姓名` columns, default name `学生学籍号`) and a submission log table (`投稿记录`).
4. Recording pages (`index.php`, `teacher.php`) must be served over HTTPS (`getUserMedia` requires a secure context); management / playback / statistic pages can run over HTTP for compatibility with in-built mobile browsers.
5. Open `index.php` to submit, `uploads/playcontrols.php` to review, and `air.php` to broadcast.

## 安全说明 / Security Notes

- 仓库不包含真实配置：数据库密码与权限码需在部署端自行填写
- 学生投稿的录音文件与 `temp/` 目录均被 `.gitignore` 排除，不入库
- 生产部署建议在 Web 服务器层限制稿件目录的目录列表

- The real config is NOT committed: fill in the database password and access codes on your deployment
- Student recording files and the `temp/` directory are excluded via `.gitignore`
- For production, restrict directory listing for the upload folders at the web-server level

## License

[MIT](LICENSE)