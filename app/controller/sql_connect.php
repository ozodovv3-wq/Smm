<?php
// ==========================================================================
//  app/controller/sql_connect.php
// --------------------------------------------------------------------------
//  DIQQAT: Bu fayl asl paketda umuman yo'q edi (bot.php uni require qiladi,
//  lekin fayl mavjud bo'lmagani uchun bot HAR DOIM darhol "Fatal error:
//  Failed opening required file" bilan yiqilardi).
//
//  Agar sizda bu botning ASL (haqiqiy) sql_connect.php fayli GitHub
//  repo'ingizda allaqachon bor bo'lsa (masalan avvalgi jadval strukturangiz
//  bilan) — O'ZINGIZNIKINI SAQLAB QOLING, bu faylni ustidan yozmang!
//  Bu fayl faqat "sizda umuman yo'q bo'lsa" ishlatilishi uchun tayyorlandi.
//
//  Railway'da MySQL qo'shsangiz (New -> Database -> MySQL), quyidagi
//  Environment Variables avtomatik yaratiladi va shu yerda o'qiladi:
//  MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE
//  (ba'zan MYSQL_URL ko'rinishida ham beriladi — pastda ikkalasi ham
//  qo'llab-quvvatlanadi).
// ==========================================================================

mysqli_report(MYSQLI_REPORT_OFF); // bot.php eski uslubda yozilgan, Exception kerak emas

$db_host = getenv('MYSQLHOST');
$db_port = getenv('MYSQLPORT') ?: 3306;
$db_user = getenv('MYSQLUSER');
$db_pass = getenv('MYSQLPASSWORD');
$db_name = getenv('MYSQLDATABASE');

// Ba'zi Railway shablonlari faqat bitta MYSQL_URL (yoki DATABASE_URL) beradi:
// mysql://user:pass@host:port/dbname — shundan ham o'qib olishga harakat qilamiz.
if(!$db_host){
    $mysql_url = getenv('MYSQL_URL') ?: getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL');
    if($mysql_url){
        $parts = parse_url($mysql_url);
        $db_host = $parts['host'] ?? null;
        $db_port = $parts['port'] ?? 3306;
        $db_user = $parts['user'] ?? null;
        $db_pass = $parts['pass'] ?? null;
        $db_name = isset($parts['path']) ? ltrim($parts['path'], '/') : null;
    }
}

$connect = @mysqli_connect($db_host, $db_user, $db_pass, $db_name, (int)$db_port);

if(!$connect){
    // Bazaga ulanib bo'lmadi — botni "500 xato" bilan yiqitish o'rniga,
    // muammoni faylga (Railway loglariga) yozib, Telegram'ga baribir 200
    // qaytaramiz va ishni to'xtatamiz.
    error_log("[sql_connect.php] MySQL ulanish xatosi: ".mysqli_connect_error());
    if(!headers_sent()) http_response_code(200);
    exit;
}

mysqli_set_charset($connect, "utf8mb4");
