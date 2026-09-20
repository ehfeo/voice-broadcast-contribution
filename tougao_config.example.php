<?php
/**
 * 运动会广播语音投稿系统 - 配置示例
 * 复制此文件为 tougao_config.php 并在部署端填写真实值。
 * Copy this file to tougao_config.php and fill in real values on deployment.
 */
if (!defined('TOUGAO_INIT')) {
    define('TOUGAO_INIT', true);

    // ---------- 数据库（名单核验与投稿记录） ----------
    define('TOUGAO_DB_HOST', 'YOUR_DB_HOST');
    define('TOUGAO_DB_USER', 'YOUR_DB_USER');
    define('TOUGAO_DB_PASS', 'YOUR_DB_PASS');
    define('TOUGAO_DB_NAME', 'YOUR_DB_NAME');
    define('TOUGAO_ROSTER_TABLE', '学生学籍号');  // 名单表（字段：班级、姓名）
    define('TOUGAO_RECORD_TABLE', '投稿记录');     // 投稿登记表

    // ---------- 稿件池目录（本项目根目录） ----------
    define('TOUGAO_ROOT', __DIR__);
    define('TOUGAO_UPLOAD_DIR', __DIR__ . '/uploads');          // 学生待审核池
    define('TOUGAO_PLAYED_DIR', __DIR__ . '/uploads/已播放');   // 学生已播出（录用）
    define('TOUGAO_DISCARDED_DIR', __DIR__ . '/uploads/已丢弃');// 已丢弃
    define('TOUGAO_STUDENT_QUEUE_DIR', __DIR__ . '/uploads/学生待播'); // 学生待播队列（审核通过）
    define('TOUGAO_TEACHER_QUEUE_DIR', __DIR__ . '/uploads/老师待播'); // 老师待播队列（免审核）
    define('TOUGAO_TEACHER_PLAYED_DIR', __DIR__ . '/uploads/老师已播'); // 老师已播出

    // ---------- 审核/统计页权限码 ----------
    define('TOUGAO_ACCESS_CODE', 'change_me_admin_code');
    // 老师投稿页专用权限码
    define('TOUGAO_TEACHER_CODE', 'change_me_teacher_code');

    /**
     * 建立数据库连接
     * @return mysqli|null 成功返回连接，失败返回 null
     */
    function tougao_db() {
        if (!class_exists('mysqli')) {
            return null;
        }
        $m = @new mysqli(TOUGAO_DB_HOST, TOUGAO_DB_USER, TOUGAO_DB_PASS, TOUGAO_DB_NAME);
        if ($m->connect_error) {
            return null;
        }
        $m->set_charset('utf8mb4');
        return $m;
    }

    /** 确保目录存在并返回路径 */
    function tougao_ensure_dir($dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 名单核验：查询指定班级中是否存在该姓名
     * @return bool|null true=通过，false=不在名单，null=数据库不可用
     */
    function tougao_verify_student($className, $name) {
        $m = tougao_db();
        if (!$m) {
            return null;
        }
        $stmt = $m->prepare("SELECT COUNT(*) FROM `" . TOUGAO_ROSTER_TABLE . "` WHERE `班级` = ? AND `姓名` = ?");
        if (!$stmt) {
            $m->close();
            return null;
        }
        $stmt->bind_param('ss', $className, $name);
        $stmt->execute();
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();
        $m->close();
        return $cnt > 0;
    }

    /** 过滤文件名的非法字符，保留中文/字母/数字/下划线/连字符 */
    function tougao_clean_name($str) {
        $str = trim($str);
        $str = preg_replace('/[^\p{Han}a-zA-Z0-9_\-]/u', '', $str);
        return $str;
    }
}