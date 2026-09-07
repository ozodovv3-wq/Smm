-- ==========================================================================
--  schema.sql
-- --------------------------------------------------------------------------
--  DIQQAT: Bu fayl asl paketda YO'Q edi. bot.php faylidagi barcha SQL
--  so'rovlarni (SELECT/INSERT/UPDATE) diqqat bilan o'qib, ishlatilayotgan
--  jadval va ustun nomlaridan KELIB CHIQIB tuzilgan — ya'ni bu "eng yaqin
--  taxmin", sizning ASL (haqiqiy, oldin ishlatilgan) bazangiz emas.
--
--  QACHON ISHLATISH KERAK:
--   - Agar bu botni Railway'da BIRINCHI MARTA, yangi/bo'sh MySQL bilan
--     ishga tushirayotgan bo'lsangiz — shu faylni bir marta ishga tushiring.
--   - Agar sizda bundan oldin ishlagan, jadvallari to'ldirilgan HAQIQIY
--     bazangiz bor bo'lsa — BU FAYLNI ISHGA TUSHIRMANG, faqat shu bazaga
--     ulaning (Environment Variables orqali). Aks holda mavjud
--     ma'lumotlaringizni yo'qotib qo'yishingiz mumkin.
--
--  Ishga tushirish: Railway MySQL -> "Data" bo'limi -> Query -> shu faylni
--  joylashtirib bajaring (yoki mysql client/phpMyAdmin orqali import qiling).
-- ==========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` BIGINT NOT NULL,                -- ketma-ket tartib raqami (1,2,3...)
  `id` BIGINT NOT NULL,                     -- Telegram chat_id (asosiy kalit)
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',   -- active / deactive
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `outing` DECIMAL(15,2) NOT NULL DEFAULT 0,        -- jami hisobga kiritilgan (to'ldirilgan) summa (bot.php: "{outing} - Kiritgan pullar miqdori")
  `api_key` VARCHAR(64) DEFAULT NULL,               -- foydalanuvchining ichki tokeni
  `referal` VARCHAR(32) DEFAULT NULL,               -- shu foydalanuvchining o'z referal kodi
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `start` TEXT DEFAULT NULL,           -- /start xabari matni
  `kabinet` TEXT DEFAULT NULL,         -- shaxsiy kabinet matni
  `orders` TEXT DEFAULT NULL,          -- buyurtmalar bo'limi matni
  `payme_id` VARCHAR(64) DEFAULT NULL, -- Payme merchant/karta ID
  `referal` VARCHAR(32) DEFAULT '0',   -- 1 ta taklif uchun beriladigan summa (base64'da saqlanadi)
  `ref_status` VARCHAR(10) DEFAULT 'on',
  `bonus` VARCHAR(32) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- settings jadvalida kamida 1 ta qator bo'lishi SHART (bot.php shu qatorni
-- SELECT * FROM settings LIMIT 1 uslubida o'qiydi).
--
-- MUHIM: bot.php `start`, `kabinet`, `orders` va `referal` ustunlarini HAR
-- DOIM base64 formatida o'qiydi (enc("decode", $setting[...]) orqali —
-- admin panel orqali matn saqlanganda ham enc("encode", ...) bilan
-- base64'ga o'giriladi). Shuning uchun standart qiymatlar ham shu yerda
-- oldindan base64'ga o'girib qo'yilgan (aks holda /start va boshqa
-- bo'limlar bo'sh/yaroqsiz matn yuborishga urinib, JAVOB QAYTARMAYDI):
--   'start'   base64 <- '👋 Xush kelibsiz!'
--   'kabinet' base64 <- '👤 Shaxsiy kabinet'
--   'orders'  base64 <- '📦 Buyurtmalar'
--   'referal' base64 <- '0'
INSERT INTO `settings` (`start`,`kabinet`,`orders`,`referal`,`ref_status`,`bonus`)
SELECT '8J+RiyBYdXNoIGtlbGlic2l6IQ==','8J+RpCBTaGF4c2l5IGthYmluZXQ=','8J+TpiBCdXl1cnRtYWxhcg==','MA==','on','0'
WHERE NOT EXISTS (SELECT 1 FROM `settings`);

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `api_url` VARCHAR(255) NOT NULL,   -- nakrutka (SMM) provayder API manzili
  `api_key` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MUHIM: bot.php `categorys` jadvalidan HAR DOIM `category_id` nomli ustunni
-- (generik `id` emas) o'qiydi — masalan "callback_data=>tanla1=".$s['category_id'].
-- Ustun nomi mos kelmasa, bo'lim tanlanganda ID bo'sh (NULL) bo'lib qoladi va
-- "Xizmatlar" bo'limi butunlay ishlamay qoladi.
CREATE TABLE IF NOT EXISTS `categorys` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(255) NOT NULL,
  `category_status` VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MUHIM: xuddi shunday, `cates` jadvalida ustun nomi `cate_id` bo'lishi SHART
-- (bot.php: $s['cate_id'], "WHERE cate_id = ...").
CREATE TABLE IF NOT EXISTS `cates` (
  `cate_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `category_id` INT NOT NULL,
  PRIMARY KEY (`cate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- YANGI: "Ichki bo'lim" (subcategory) darajasi. Mijoz tomonida navigatsiya
-- endi 4 bosqichli: Tarmoq (categorys) -> Bo'lim (cates) -> Ichki bo'lim
-- (subcates, shu jadval) -> Xizmat/narx (services). Masalan "Obunachi"
-- bo'limi ichida "Kafolatli", "Tabiiy & Aktiv", "Onlayn", "Uzbek" kabi
-- ichki bo'limlar bo'lishi mumkin.
CREATE TABLE IF NOT EXISTS `subcates` (
  `subcate_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `cate_id` INT NOT NULL,
  PRIMARY KEY (`subcate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MUHIM: `services` jadvalida ham asosiy kalit `service_id` deb nomlanishi
-- SHART (bot.php: $s['service_id'], "WHERE service_id = ...").
CREATE TABLE IF NOT EXISTS `services` (
  `service_id` INT NOT NULL AUTO_INCREMENT,
  `service_status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `service_edit` VARCHAR(10) NOT NULL DEFAULT 'false',
  `service_price` DECIMAL(15,4) NOT NULL DEFAULT 0,
  `category_id` INT DEFAULT NULL,
  `subcate_id` INT DEFAULT NULL,         -- YANGI: subcates.subcate_id ga bog'liq (ichki bo'lim)
  `service_api` INT DEFAULT NULL,        -- providers.id ga bog'liq
  `api_service` VARCHAR(64) DEFAULT NULL,-- provayderdagi xizmat ID'si
  `api_currency` VARCHAR(10) DEFAULT NULL,
  `service_type` VARCHAR(64) DEFAULT NULL,
  `api_detail` TEXT DEFAULT NULL,
  `service_name` VARCHAR(255) DEFAULT NULL,
  `service_desc` TEXT DEFAULT NULL,
  `service_min` INT DEFAULT 0,
  `service_max` INT DEFAULT 0,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MUHIM: Agar sizda bu botning ESKI (subcates jadvali qo'shilishidan oldingi)
-- bazasi bo'lsa, yuqoridagi "CREATE TABLE IF NOT EXISTS `services`" eski
-- jadvalni o'zgartirmaydi (ustun qo'shmaydi). Shu sabab quyidagi qatorni HAM
-- ishga tushiring (MySQL 8.0.29+ talab qilinadi; Railway'ning standart MySQL
-- versiyasi buni qo'llab-quvvatlaydi):
ALTER TABLE `services` ADD COLUMN IF NOT EXISTS `subcate_id` INT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `myorder` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `order_id` VARCHAR(64) DEFAULT NULL,      -- ichki buyurtma raqami
  `user_id` BIGINT NOT NULL,                -- users.id (telegram chat_id)
  `retail` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `service` VARCHAR(255) DEFAULT NULL,
  `order_create` DATETIME DEFAULT NULL,
  `last_check` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `api_order` VARCHAR(64) DEFAULT NULL,   -- provayderdagi buyurtma ID'si
  `order_id` VARCHAR(64) DEFAULT NULL,    -- myorder.order_id bilan bog'liq
  `provider` INT DEFAULT NULL,            -- providers.id
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `percent` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `percent` DECIMAL(6,2) NOT NULL DEFAULT 0, -- narxlarga qo'shiladigan foiz
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `percent` (`percent`)
SELECT 0 WHERE NOT EXISTS (SELECT 1 FROM `percent`);

CREATE TABLE IF NOT EXISTS `mybots` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) DEFAULT NULL,
  `admin` BIGINT DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `send` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `time1` VARCHAR(20) DEFAULT NULL,
  `time2` VARCHAR(20) DEFAULT NULL,
  `time3` VARCHAR(20) DEFAULT NULL,
  `time4` VARCHAR(20) DEFAULT NULL,
  `time5` VARCHAR(20) DEFAULT NULL,
  `start_id` BIGINT DEFAULT NULL,
  `stop_id` BIGINT DEFAULT NULL,
  `admin_id` BIGINT DEFAULT NULL,
  `message_id` BIGINT DEFAULT NULL,
  `reply_markup` TEXT DEFAULT NULL,
  `step` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
