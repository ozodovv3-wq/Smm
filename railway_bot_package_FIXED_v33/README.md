# Bot kodi tahlili va Railway'ga joylash qo'llanmasi

## 0. v25 — "Buyurtma tasdiqlansa ham DB'ga yozilmayabdi" muammosi tuzatildi

**Muammo:** "✅ Tasdiqlash" tugmasi bosilganda hech qanday xato ko'rinmasdi, lekin buyurtma bazaga yozilmasdi va "📊Buyurtmalarim" bo'limi doim bo'sh (yoki "❌ Buyurtma topilmadi!") chiqardi.

**Sabab (kodda topildi):** Tasdiqlash bosqichida bot SMM-provayder API'siga so'rov yuboradi; agar provayder `order` ID qaytarmasa, kod **DBga hech narsa yozmaydi** va foydalanuvchiga kichik popup-alertdan boshqa hech narsa ko'rsatmaydi — xato matni faqat `CHANNEL_ID` kanaliga yashirincha yuborilar edi.

**Aniqlangan aniq bug:** "Package" turidagi xizmatlarda buyurtma miqdori (`quantity`) hech qachon saqlanmagan edi (faqat "Default" turida saqlanardi). Tasdiqlash bosqichida API'ga bo'sh `quantity` yuborilib, provayder buyurtmani deyarli har doim rad etardi.

**Tuzatildi:**
1. Package turidagi buyurtmalar uchun endi `quantity=1` avtomatik saqlanadi.
2. Provayder xatosi endi Railway loglariga ham yoziladi (`error_log`) — `CHANNEL_ID` noto'g'ri sozlangan bo'lsa ham xato matnini ko'rish mumkin bo'ladi.

**Sizga tavsiya:** Agar tuzatishdan keyin ham buyurtma o'tmasa — Railway loglariga qarang (`SMM buyurtma xatosi` deb boshlanadi), u yerda provayderning aniq javobi (masalan noto'g'ri API key, balans yetarli emas va h.k.) ko'rinadi. Shuningdek `providers` jadvalida `api_url`/`api_key` to'g'ri kiritilganini tekshiring.


## 0.1 v27 — "Xatolik dedi, lekin buyurtma bajarildi" (timeout muammosi)

**Muammo:** Foydalanuvchi "✅ Tasdiqlash"ni bosganda bot "Noma'lum xatolik" deb ko'rsatgan, lekin provayder tomonidan buyurtma **aslida qabul qilinib, bajarilgan** (provayderning o'z xabarnomasi buni tasdiqladi).

**Sabab:** `smm_panel_post()` funksiyasidagi curl so'rovi uchun javobni kutish muddati (timeout) atigi **20 soniya** edi. Provayder so'rovni qabul qilib ulguradi, lekin HTTP javobni shu muddat ichida qaytarib ulgurmasa, bot buni "javob kelmadi = xatolik" deb hisoblab, foydalanuvchiga xato ko'rsatadi — buyurtma esa provayder tomonida baribir ishga tushib qoladi.

**Tuzatildi:**
1. Timeout 20 → **50 soniyaga** oshirildi (foydalanuvchi so'roviga ko'ra).
2. Provayderdan kelgan **xom (raw) javob** endi har doim Railway loglariga yoziladi (`SMM panel xom javobi` deb boshlanadi) — bu orqali kelajakda "xato" aniq nimadan kelib chiqqanini (timeout, noto'g'ri JSON, provayderning haqiqiy xato matni) aniq ko'rish mumkin bo'ladi.

**Diqqat:** Bu — vaqtinchalik yumshatish, 100% kafolat emas. Agar provayder doimiy ravishda 50 soniyadan sekinroq javob bersa, muammo qaytalanishi mumkin. Bunday hollarda eng yaxshi yechim — buyurtmani **asinxron** qilish (foydalanuvchiga "buyurtmangiz qabul qilindi, tekshirilmoqda" deb darhol javob berish, so'ng natijani keyinroq, masalan cron orqali, tasdiqlash) — bu ancha katta o'zgarish, xohlasangiz alohida qilib beraman.


## 0.2 v29 — "Buyurtma bajarildi, lekin ko'rishda xatolik" tuzatildi

**Muammo:** Buyurtma muvaffaqiyatli tasdiqlanadi (DBga yoziladi), lekin "Buyurtmalarim" ro'yxatidan uni bosib ko'rmoqchi bo'lganda "❌ Buyurtma topilmadi!" chiqadi — garchi buyurtma bazada mavjud bo'lsa ham.

**Sabab:** Buyurtmani ko'rish kodi ikkita har xil narsani bitta shart ichida aralashtirib yuborgan edi: (1) buyurtma DBda **haqiqatan topilmadi** va (2) provayderdan **hozirgi holatni so'rashda xato/timeout** yuz berdi (masalan yana o'sha sekin-javob-berish muammosi). Ikkalasi ham bir xil "❌ Buyurtma topilmadi!" xabarini chiqarardi.

**Tuzatildi:** Endi bu ikkisi ajratilgan:
- Buyurtma DBda haqiqatan yo'q bo'lsa → "❌ Buyurtma topilmadi!"
- Buyurtma DBda bor, lekin provayderdan qoldiq-miqdorini hozir so'rab bo'lmasa → buyurtma holati (DBda saqlangan oxirgi holat) ko'rsatiladi, faqat "qoldiq miqdorini hozir tekshirib bo'lmadi" deb ogohlantiriladi.

Shuningdek, shu bloklarning birida aniqlanmagan `$my` o'zgaruvchisi ishlatilgan edi (buyurtma sanasi noto'g'ri/bo'sh chiqishiga sabab bo'lardi) — endi to'g'ri `$rew['order_create']` ustunidan olinadi.

2. Provayderdan kelgan **xom (raw) javob** endi har doim Railway loglariga yoziladi (`SMM panel xom javobi` deb boshlanadi) — bu orqali kelajakda "xato" aniq nimadan kelib chiqqanini (timeout, noto'g'ri JSON, provayderning haqiqiy xato matni) aniq ko'rish mumkin bo'ladi.

**Diqqat:** Bu — vaqtinchalik yumshatish, 100% kafolat emas. Agar provayder doimiy ravishda 45 soniyadan sekinroq javob bersa, muammo qaytalanishi mumkin. Bunday hollarda eng yaxshi yechim — buyurtmani **asinxron** qilish (foydalanuvchiga "buyurtmangiz qabul qilindi, tekshirilmoqda" deb darhol javob berish, so'ng natijani keyinroq, masalan cron orqali, tasdiqlash) — bu ancha katta o'zgarish, xohlasangiz alohida qilib beraman.


| # | Muammo | Qayerda | Nega xavfli edi |
|---|--------|---------|------------------|
| 1 | **Tirnoqsiz array kalitlar** (`inline_keyboard=>`, `callback_data=>`, `chat_id=>`, `url=>`, `show_alert=>`, `from_chat_id=>`) — jami ~50 joyda | Butun fayl bo'ylab | PHP 8+ da bular "aniqlanmagan konstanta" deb hisoblanadi va **Fatal Error** beradi. Railway'da standart PHP 8.2/8.3 ishlatiladi — bot birinchi so'rovdayoq yiqiladi edi. |
| 2 | `bot(getMe)` | 18-qator | Xuddi shu sabab — `getMe` tirnoqsiz yozilgan edi |
| 3 | `'parse_mode'=>html` | 2 joyda | Xuddi shu sabab |
| 4 | `php://input` ikki marta o'qilishi | ~256-258 qator | `php://input` ni ikkinchi marta o'qishga urinish ba'zi serverlarda **bo'sh natija** qaytaradi → `$message` `null` bo'lib qoladi → `$message->message_id` chaqirilganda **"Attempt to read property on null"** xatosi bilan bot yiqiladi. Bu qator olib tashlandi. |
| 5 | `$step = file_get_contents("step/$from_id.step")` | 255-qator | `$from_id` bu yerda hali aniqlanmagan edi (pastda, 287-qatorda aniqlanadi) — bu qator PHP Warning chiqarardi va baribir keyinroq qayta yoziladi. Butunlay olib tashlandi. |
| 6 | `mkdir("user"); mkdir("set");` | ~370-qator | Papka mavjud bo'lsa ham har safar `mkdir` chaqirilar edi → har bir xabarga **Warning**. Endi `is_dir()` bilan tekshiriladi. |
| 7 | Bo'sh tugma matni `'text'=>""` | "❌ Yo'q" tugmasida, 2 joyda | Foydalanuvchiga bo'sh tugma ko'rinardi. `"❌ Yo'q"` matni qo'shildi. |
| 8 | Qattiq yozilgan tokenlar (`API_KEY`, `admin`, `simkey`, `channel`) | Fayl boshi | Endi `getenv()` orqali Railway Environment Variables'dan olinadi (pastga qarang). |
| 9 | Xatolar ekranga chiqishi | Fayl boshi | `display_errors` o'chirildi, `log_errors` yoqildi — production uchun xavfsiz. |

## 0.3 v30 — Admin panelida "Foydalanuvchini boshqarish" ishlamasligi tuzatildi

**Muammo:** Admin "👤 Foydalanuvchini boshqarish" orqali haqiqiy Telegram ID (masalan `5205037267`) kiritganda, foydalanuvchi bazada bo'lsa ham "Foydalanuvchi topilmadi" chiqardi.

**Sabab:** `users` jadvalida ikkita har xil ID bor: `user_id` (ichki tartib raqami — 1, 2, 3...) va `id` (haqiqiy Telegram chat ID). Admin qidiruv qutisiga tabiiy ravishda haqiqiy Telegram ID kiritadi, lekin kod uni noto'g'ri — kichik tartib raqamlari saqlanadigan `user_id` — ustunidan qidirardi.

**Tuzatildi:** Qidiruv endi to'g'ri `id` ustunidan (haqiqiy Telegram ID) amalga oshiriladi. (Referal tizimidagi shunga o'xshash qidiruv tekshirildi — u ataylab `user_id` bo'yicha ishlaydi va to'g'ri, tegilmadi.)


## 0.4 v31 — "Qidirilmoqda..." abadiy qotib qolishi tuzatildi

**Muammo:** Admin foydalanuvchi ID'sini kiritganda "Qidirilmoqda..." xabari chiqib, keyin hech qachon natija bilan almashtirilmasdi (abadiy shu holatda qolaverardi).

**Sabab:** Kod "Qidirilmoqda..." xabarini yuborgandan so'ng, uni **taxmin qilingan** `message_id` (adminning oxirgi xabari + 1) bilan tahrirlashga urinardi — bu shunchaki taxmin edi, haqiqiy ID emas. Taxmin xato chiqsa (masalan orada boshqa xabar yuborilgan bo'lsa), Telegram tahrirlashni rad etardi va bu xato jim yutib yuborilardi — natijada "Qidirilmoqda..." xabari hech qachon yangilanmay qolardi.

**Tuzatildi:** Endi "Qidirilmoqda..." xabari yuborilganda Telegram'ning o'zi qaytargan **haqiqiy** `message_id` saqlab qolinadi va aynan shu ID orqali tahrirlanadi — taxmin qilish shart emas.


### b) [TUZATILDI] Ikki marta takrorlangan "botopen=" / "mydomen=" callback ishlovchisi
Botda ikkita narx bo'limi bor edi (45 000 so'm va 30 000 so'm), lekin ikkalasi ham bir xil `callback_data` prefiksidan (`botopen=`, keyin `mydomen=`) foydalangani sabab, foydalanuvchi qaysi tugmani bosishidan qat'i nazar **ikkala ishlovchi blok ham ketma-ket ishga tushar edi** — bu takroriy xabar yuborilishiga (va Telegram API'ga keraksiz qo'shimcha so'rovlarga) olib kelardi. Endi har bir blok faqat o'ziga tegishli narx qiymati (`=45000=` yoki `=30000=`) callback_data ichida bo'lgandagina ishga tushadi.

### c) [TUZATILDI] "Premium/Professional bot sotish" (reseller/domen ochish) funksiyasi — Fatal Error manbai
Shu blok ichida chaqirilayotgan `url_query()`, `generatemysql()`, `plusmysql()` funksiyalari va `$isp_user`, `$acc` o'zgaruvchilari **faylning hech qayerida aniqlanmagan edi**. Bu — asl muallifning **shaxsiy hosting-paneliga** (`ispsystem.sysdc.uz` / `wolfgram.uz`) ulanadigan, xususiy infratuzilmaga bog'liq qism bo'lib, u sizda (yoki Railway'da) ishlay olmaydi — bu funksiyalarni chaqirishga urinish "Call to undefined function" xatosi bilan botni har safar to'xtatib qo'yar edi (global exception handler buni ushlab qolgani uchun bot butunlay yiqilmasdi, lekin foydalanuvchi hech qanday javob olmay "osilib" qolardi).
**Nima qilindi:** bu funksiya butunlay xavfsiz o'chirildi. Endi foydalanuvchi shu bosqichga yetganda (pul yechilmasdan) "⚠️ Ushbu xizmat hozircha faol emas, admin bilan bog'laning" degan tushunarli xabar oladi. Agar bu funksiya (botlarni avtomatik klonlab, alohida domenda sotish) kerak bo'lsa — bu butunlay boshqa, katta ishlanma talab qiladi (Railway API yoki boshqa hosting-provayder bilan integratsiya) va alohida so'rov sifatida qilinishi kerak.

### e) [TUZATILDI] Payme to'lov oqimida o'lik kod
`user/$cid.step` faylini o'chiradigan qator (`@unlink(...)`) xato tartibda — `exit;` dan **keyin** yozilgan edi, shuning uchun hech qachon ishlamas edi (PHP `exit;`dan keyingi qatorlarni bajarmaydi). Natijada to'lov summasi kiritilgach "payme" bosqichi tozalanmay qolardi. Tartib to'g'irlandi.

## 3. TUZATILMAGAN, lekin JIDDIY muammo (qaror sizga bog'liq)

### a) SQL Injection — ~170 ta joyda
Deyarli barcha `mysqli_query($connect, "... WHERE id = $cid ...")` ko'rinishidagi so'rovlar foydalanuvchi kiritgan qiymatni **to'g'ridan-to'g'ri** SQL ichiga qo'yadi. Masalan:
```php
mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
```
Telegram `chat_id` odatda raqam bo'lgani uchun xavf past, lekin `$text`, `$data`, `$tx` kabi foydalanuvchi matni SQL ichiga tushadigan joylar (masalan promokod, admin xabar matni, va h.k.) bor va ular orqali **butun bazani o'chirish/o'g'irlash** mumkin.
**Tavsiya:** kamida foydalanuvchi matni ishtirok etadigan so'rovlarni `mysqli_real_escape_string()` yoki `mysqli_prepare()`/`bind_param()` ga o'tkazish kerak. Bu alohida, katta ish — xohlasangiz shu bilan ham yordam beraman.

### d) Fayl-asosidagi saqlash (`user/*.step`, `set/channel`, `msgs.json`)
Railway konteynerlari **vaqtinchalik disk** bilan ishlaydi — har safar qayta deploy qilinganda yoki konteyner qayta ishga tushganda, shu fayllar **butunlay o'chib ketadi** (foydalanuvchi bosqichlari, referal statistikasi va h.k. yo'qoladi).
**Ikki yechim bor:**
1. **Tezkor:** Railway'da "Volume" (doimiy disk) ulash — kod o'zgarmaydi, lekin faqat 1 ta instance uchun ishlaydi.
2. **To'g'ri yechim:** bu fayllarni MySQL bazasidagi jadvalga ko'chirish (masalan `user_steps` jadvali). Buni ham xohlasangiz qilib beraman.

## 3. Railway'ga joylash — qadamlar

1. **GitHub repo yarating** va shu papkadagi barcha fayllarni (`public/bot.php`, `Procfile`, `nixpacks.toml`, `user/`, `set/`, `step/` papkalari) shu repoga yuklang.
2. Railway'da **New Project → Deploy from GitHub repo**.
3. **MySQL qo'shing:** Railway loyihasida "New → Database → MySQL" bosing. U sizga `MYSQL_URL`, host, user, parol beradi.
4. `app/controller/sql_connect.php` faylini (bu fayl sizda yuklanmagan, uni ham menga bersangiz tekshirib beraman) Railway'ning MySQL ma'lumotlari bilan yangilang — afzali, u yerda ham `getenv('MYSQLHOST')`, `getenv('MYSQLUSER')` va h.k. ishlatish.
5. **Environment Variables** qo'shing (Railway → Variables):
   - `BOT_TOKEN` — @BotFather'dan olingan token
   - `ADMIN_ID` — sizning Telegram ID'ingiz
   - `SIMKEY` — sms-activate.org kaliti
   - `CHANNEL_ID` — majburiy obuna kanali ID'si
6. **Volume qo'shing** (agar fayl-asosidagi saqlashni hozircha shu ko'yi qoldirsangiz): Settings → Volumes → mount path: `/app/user`, `/app/set`, `/app/step`.
7. Deploy tugagach, Railway sizga domen beradi (masalan `https://sizning-loyiha.up.railway.app`). Webhook o'rnating:
   ```
   https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://sizning-loyiha.up.railway.app/bot.php
   ```

## 4. Keyingi qadam

Yuqoridagi (a) va (d) bandlari bo'yicha qanday yo'l tutishimni ayting — shunga qarab faylni yanada to'liqroq tuzatib beraman (masalan SQL Injection'dan himoya yoki fayl-asosidagi saqlashni MySQL'ga ko'chirish).

## 5. Yangi qo'shilgan: Nomer (SMS) API'ni bot ichidan sozlash

Avval faqat **Nakrutka (SMM) API** admin panel orqali ("🔑 API Sozlamalari" -> "➕ API qo'shish") qo'shilar edi; **Nomer (SMS) API** esa faqat Railway Environment Variables (`SIMKEY`) orqali qattiq kiritilgan edi.

Endi "🗄️ Boshqaruv" panelida yangi tugma bor: **"🔢 Nomer API sozlash"**. U orqali admin:
1. Nomer sotib olinadigan saytning API manzilini yuboradi (masalan `https://api.sms-activate.org/stubs/handler_api.php`, yoki shu protokolga mos boshqa sayt).
2. So'ng o'sha saytdan olingan API kalitni (SIMKEY) yuboradi.
3. Bot darhol saytga ulanib balansni tekshiradi va natijani ko'rsatadi.

Bu qiymatlar `set/sms_api_url.txt` va `set/simkey.txt` fayllarida saqlanadi va **Environment Variables'dagi `SIMKEY`/`SMS_API_URL`dan ustun turadi** — ya'ni Railway'da o'zgartirmasdan, to'g'ridan-to'g'ri botning o'zidan almashtirish mumkin. Fayllar topilmasa, avvalgidek `.env`dagi standart qiymatlarga qaytadi (orqaga moslik saqlangan). Kod ichidagi barcha ~20 ta joyda hardcode qilingan `sms-activate.org` manzili endi shu dinamik o'zgaruvchidan o'qiladi.

**Muhim (Railway uchun):** `set/` papkasi vaqtinchalik diskda saqlanadi (yuqoridagi (d) bandga qarang) — konteyner qayta ishga tushsa, bu yerga yozilgan API manzil/kalit ham o'chib ketishi mumkin, agar Volume ulanmagan bo'lsa. Doimiy saqlash uchun Volume ulashni yoki bu sozlamalarni MySQL'ga ko'chirishni tavsiya qilaman.

## 6. Funksiyalarni tekshirish bo'yicha eslatma

Men faylni statik (kodni o'qish, qavslar balansi, o'zgaruvchilar qamrovi) tarzda diqqat bilan tekshirdim va quyidagilarni tasdiqladim:
- **Nakrutka (SMM) API qo'shish/o'chirish/tahrirlash** — to'liq ishlaydigan holatda, `providers` jadvaliga yozadi ("🔑 API Sozlamalari" bo'limi).
- **Referal tizimi** — kanalga a'zo bo'lgach balansga referal puli qo'shiladi, referal soni oshiriladi.
- **Hisobni to'ldirish (pul kiritish)** va **buyurtma/balansdan yechish** oqimlari — bir xil naqshda (`UPDATE users SET balance=...`) ishlangan.
- Yangi qo'shilgan Nomer API bloki qavslar/qo'shtirnoqlar balansini buzmagani tekshirildi (butun fayl bo'yicha `{}`, `()` balansi 0, o'zgarmagan).

**Lekin:** menda bu muhitda ishlaydigan PHP interpretatori, jonli MySQL baza va tarmoq (internet) ulanishi yo'q — shuning uchun kodni haqiqatan **ishga tushirib**, botni Telegram orqali bosib-sinab ko'ra olmadim. Railway'ga joylagandan so'ng albatta quyidagilarni qo'lda tekshiring: `/start`, referal havolasi, hisob to'ldirish, "🔢 Nomer API sozlash" orqali API kiritish, nomer sotib olish, va nakrutka buyurtma berish. Xatolik chiqsa — Railway loglariga qarang (`log_errors` yoqilgan) va menga xato matnini yuboring, tezda tuzataman.

Shuningdek, 2-band (a)dagi **SQL Injection** muammosi hali ham tuzatilmagan (bu alohida katta ish) — xohlasangiz shu bilan ham yordam beraman.

## 8. Ikkinchi tekshiruv (v17) — yana topilgan va tuzatilgan xatolar

Fayl yana bir bor, boshidan oxirigacha, statik tarzda (funksiya chaqiruvlari, o'zgaruvchilar qamrovi, tugma callback_data'lari) tekshirildi. `php -l` bilan sintaksis xatosi yo'qligi, va butun fayl bo'yicha qavslar balansi (`{}`=773/773, `()`=2537/2537) tasdiqlandi. Quyidagi yangi, haqiqiy xatolar topildi va tuzatildi:

| # | Muammo | Qayerda | Ta'siri |
|---|--------|---------|---------|
| 1 | **"exit;" so'zi xabar matni ICHIGA yozilgan edi** (kod sifatida emas) | Buyurtma berish oqimi, "havola noto'g'ri" xabari | Foydalanuvchiga "exit;" so'zi ko'rinadigan xunuk xabar ketardi. Bundan tashqari haqiqiy `exit;` buyrug'i yo'q edi — skript to'xtamay pastdagi keyingi (aloqasiz) if-bloklariga kirishga urinishi mumkin edi. Endi xabar tozalandi va haqiqiy `exit;` qo'shildi. |
| 2 | **2 ta buzilgan (mojibake) emoji tugma matni** — "?? Balansni ko'rish" va "?? Kartangizning unikal manzilini kiriting" | API sozlamalari paneli, karta so'rash bosqichi | Tugmalar/xabarlar "??" bilan ko'rinardi. Tegishli emoji (💵, 💳) qaytarildi. |
| 3 | **Referal funksiyasida qolib ketgan debug qatori** (`echo $file;`) | `referal()` funksiyasi | Ichki fayl yo'llari (masalan `./user/12345.users`) HTTP javobiga chiqib ketardi. Funksional xato bermasa-da (Telegram webhook javob tanasini o'qimaydi), bu xavfsizlik/tozalik nuqtai-nazaridan noto'g'ri edi. Olib tashlandi. |
| 4 | **"Matnlarni sozlash" (admin) menyusida noto'g'ri callback_data'li tugma** | `birlamch=editM` bo'limi | "2. Yangi buyurtma uchun matn" deb yozilgan tugma aslida `birlamchi=referal`ga (referal narxi) bog'langan edi, "3. Kabinet" bilan bir qatordagi tugma esa noto'g'ri "2" raqami bilan `birlamchi=orders`ga bog'langan edi. **Natija:** admin "2" tugmasini bossa, buyurtma matni o'rniga referal narxini tahrirlash oynasi ochilardi. Raqamlar va callback_data'lar to'g'irlandi (endi 1=start, 2=orders, 3=kabinet, 4=referal narxi — mos ravishda). |
| 5 | **Kichik imlo xatosi** ("yuborilmari", "extimol") | Ticket/qo'llab-quvvatlash javob yuborish bloki | Kosmetik, lekin tushunarsiz xabar edi. "yuborilmadi", "ehtimol" ga to'g'irlandi. |
| 6 | **`nixpacks.toml`da ishlatilmagan `simplexml` kengaytmasi** | Build sozlamalari | Butun `bot.php` faylida (grep bilan tasdiqlandi) simplexml/XML funksiyalari umuman chaqirilmaydi (SMS API javobi JSON, XML emas). Har bir keraksiz `nixPkgs` yozuvi build'ni yiqitish xavfini oshirgani sabab olib tashlandi. |
| 7 | **`schema.sql`da chalg'ituvchi izoh** (`outing` ustuni) | Baza strukturasi izohi | Izohda "jami sarflangan summa" deb yozilgan edi, lekin bot.php'ning o'z ichidagi hujjatlashtirilishi (`{outing} - Kiritgan pullar miqdori`) va kod mantig'i buning aslida "jami hisobga KIRITILGAN (to'ldirilgan) summa" ekanini ko'rsatadi. Izoh to'g'irlandi — bu funksional xato emas, lekin kelajakda noto'g'ri tushunib xato qilishning oldini oladi. |

**Tekshirilib, XATO TOPILMAGAN (tasdiqlangan to'g'ri ishlaydigan) joylar:**
- Xizmat qo'shish (`newXiz`), Bo'lim qo'shish (`newFol`), Ichki bo'lim qo'shish (`newFold`), API provayder qo'shish/tahrirlash/o'chirish (`api`, `apio=`, `apidel=`) — barchasi statik tarzda oxirigacha kuzatib chiqildi. Xususan `service_api` va `api_service` ustunlari (nomlari bir-biriga juda o'xshash bo'lgani uchun chalkashtirish oson) — qo'shish bosqichidan (INSERT) buyurtma berish bosqichigacha (SELECT/WHERE) to'liq mos kelishi tasdiqlandi.
- Balans qo'shish/ayirish (admin), Payme to'lov oqimi, referal mukofot oqimi, buyurtma holatini tekshirish cron (`?update=status`), ommaviy xabar yuborish (`send`) — asosiy mantiqda jiddiy xato topilmadi (fayl-asosidagi vaqtinchalik saqlash bilan bog'liq, README 3(d)-bandida allaqachon hujjatlashtirilgan cheklov bundan mustasno).

**Hali ham ATayin tuzatilmagan** (avvalgi tekshiruvda ham aytilganidek, alohida katta ish talab qiladi, xohlasangiz qilib beraman):
- SQL Injection (foydalanuvchi matni SQL so'rovlar ichiga to'g'ridan-to'g'ri qo'yiladigan ~170 joy)
- Fayl-asosidagi saqlash (`user/*.step`, `set/*`)ni MySQL'ga ko'chirish (Railway'da Volume ulamasdan ham survive qilishi uchun)


## 7. "Umuman xato chiqmasin" so'rovi bo'yicha qilingan tuzatishlar

Siz yuborgan skrinshotda `composer install: command not found` xatosi bor edi — sababi va yechimi:

- **Composer xatosi:** paketda ishlatilmayotgan `composer.json` bor edi, Nixpacks uni ko'rib avtomatik `composer install` ishga tushirmoqchi bo'lgan, lekin composer o'rnatilmagan edi. **`composer.json` butunlay olib tashlandi** — endi bu bosqich umuman ishga tushmaydi.

Shundan keyin fayllarni yana chuqurroq tekshirganimda yana uchta jiddiy, "vaqt o'tgan sari xato ko'payishi"ga sabab bo'lishi mumkin bo'lgan muammoni topdim va tuzatdim:

- **`app/controller/sql_connect.php` fayli umuman yo'q edi** — bot.php uni `require` qilgani uchun, agar sizning haqiqiy repo'ingizda ham bu fayl bo'lmasa, bot HAR BIR so'rovda darhol yiqilishi kerak edi. Endi bu faylni yaratib qo'ydim (Railway MySQL Environment Variables orqali avtomatik ulanadi). **Agar sizda bu faylning ASL versiyasi allaqachon mavjud bo'lsa (masalan eski ma'lumotlaringiz bilan) — meniki bilan ALMASHTIRMANG**, faqat o'zingiznikini qoldiring.
- **`app/controller/schema.sql`** — agar hali hech qanday jadvalingiz bo'lmasa (yangi/bo'sh baza), shu faylni bir marta ishga tushirsangiz barcha kerakli jadvallar (`users`, `settings`, `providers`, `services` va h.k.) yaratiladi. Bu ham bot.php ichidagi so'rovlardan "qayta tiklangan" taxminiy struktura — agar eski bazangiz bor bo'lsa, bu faylni ishlatmang.
- **mysqli xatolar sababli butun bot yiqilishi (eng katta ehtimoliy sabab!):** PHP 8.1+ da `mysqli` standart holatda har qanday SQL xatosida (masalan takroriy yozuv, mavjud bo'lmagan qator) `Exception` "otadi". Bu kod esa eski uslubda (`if($natija){...}` deb tekshiradigan) yozilgan — Exception kutilmagan joyda chiqsa, butun so'rov 500-xato bilan yiqiladi. Baza kattalashib, turli-tuman holatlar (edge case'lar) ko'proq uchray boshlagani sari bunday yiqilishlar ham ko'payadi — siz ko'rgan **"xato borgan sari ko'payib boryapti"** degani aynan shu bo'lishi ehtimoli katta. Endi `mysqli_report(MYSQLI_REPORT_OFF)` qo'shildi — bu xatti-harakatni eski (xavfsiz) rejimga qaytaradi.
- **Har qanday kutilmagan xato endi botni yiqitmaydi:** global xato/Exception ushlagichlar qo'shildi — har qanday muammo Railway loglariga yoziladi, lekin foydalanuvchiga yoki Telegram'ga "500 xato" ko'rinishida chiqmaydi (Telegram webhook doim 200 javob oladi, aks holda Telegram webhookni vaqtincha o'chirib qo'yishi mumkin edi).
- **Nisbiy fayl yo'llari (`user/...`, `set/...`, `step/...`) barqarorlashtirildi:** botning ishga tushirilgan joyidan (CWD) qat'i nazar, bu 190+ joydagi yo'llar doim to'g'ri papkaga ishora qilishi uchun skript boshida `chdir()` qo'shildi.

**Halollik uchun aytib qo'yay:** bu — 5000+ qatorli, ko'p yillik "meros" (legacy) kod, va menda uni jonli internet, MySQL va Telegram bilan haqiqatan ishga tushirib sinash imkoniyati yo'q. Yuqoridagilar — men aniqlab, ishonchli tarzda tuzata olgan **haqiqiy, tekshirilgan muammolar**. Shu bilan birga, 2-banddagi SQL Injection kabi tuzatilmagan joylar yoki men bilmagan sizning maxsus sozlamalaringizga bog'liq boshqa nozik holatlar hali ham xato berishi mumkin — "100% hech qachon hech qanday xato chiqmaydi" deb va'da bera olmayman, lekin endi xato chiqsa ham, u botni yiqitmaydi va Railway loglarida aniq ko'rinadi, shu orqali tezda tuzatish mumkin bo'ladi.
