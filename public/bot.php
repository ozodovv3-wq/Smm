<?php
////Turkiston_coders manba buni ochrb tawa ishledi
#tahrirchi:@turon_coders manbasiz kurmay!
#manba: turkiston_coders obuna buling!	
#manbasiz kursam xafa qilaman

// --- MUHIM (barqarorlik uchun): butun fayl bo'ylab "user/...", "set/...",
// "step/..." kabi NISBIY (CWD'ga bog'liq) fayl yo'llari 190+ joyda
// ishlatilgan. PHP serveri qanday papkadan ishga tushirilishidan qat'i
// nazar bu yo'llar HAR DOIM to'g'ri joyga (public/ papkasidan bitta
// yuqoridagi loyiha ildiziga) ishora qilishi uchun joriy ish papkasini
// aniq belgilab qo'yamiz:
chdir(__DIR__."/..");

// --- Production sozlamalari: xatolarni ekranga chiqarmaymiz, lekin faylga yozamiz ---
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// --- MUHIM (tezlik uchun): kodda bir nechta joyda tashqi saytlarga (SMS
// provayder, cbu.uz valyuta kursi, Google Translate) file_get_contents()
// orqali murojaat qilinadi. Standart holatda bunday so'rov 60 soniyagacha
// javob kutishi mumkin — shu vaqt ichida (PHP dev-server ko'pincha bir
// vaqtning o'zida bitta so'rovni qayta ishlagani sabab) BUTUN bot boshqa
// hech kimga javob bermay qoladi. Shu sababli standart kutish vaqtini
// qisqartiramiz:
ini_set('default_socket_timeout', 8);

// --- MUHIM (barqarorlik uchun): PHP 8.1+ da mysqli standart holatda SQL xatosida
// Exception "otadi" (throw qiladi). Bu kod esa mysqli_query natijasini eski uslubda
// (false/true tekshirish orqali) ishlatadi — shuning uchun Exception rejimini
// o'chiramiz. Aks holda vaqt o'tib baza kattalashgani sari (takroriy referal kod,
// mavjud bo'lmagan qator va h.k.) tasodifiy so'rovlar butun botni "500 xato" bilan
// yiqitib qo'yaveradi — foydalanuvchi tez-tez ko'rgan "xato ko'payishi" aynan shundan.
mysqli_report(MYSQLI_REPORT_OFF);

// --- Kutilmagan xato/Exception botni butunlay yiqitmasin va Telegram'ga baribir
// javob qaytsin (aks holda Telegram webhookni vaqtincha to'xtatib qo'yishi yoki
// qayta-qayta urinib, xatolarni yanada ko'paytirishi mumkin). Hammasi faylga
// (Railway loglariga) yoziladi, ekranga chiqmaydi. ---
set_exception_handler(function($e){
    error_log("[bot.php] Uncaught exception: ".$e->getMessage()." in ".$e->getFile().":".$e->getLine());
    if(!headers_sent()) http_response_code(200);
});
set_error_handler(function($errno,$errstr,$errfile,$errline){
    // "@" bilan bostirilgan xatolarni (masalan @file_get_contents(...)) log'ga yozmaymiz —
    // aks holda ataylab bostirilgan warning'lar ham loglarda "xato" bo'lib ko'rinaveradi.
    if(!(error_reporting() & $errno)){
        return true;
    }
    error_log("[bot.php] PHP xato [$errno]: $errstr in $errfile:$errline");
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
register_shutdown_function(function(){
    $err = error_get_last();
    if($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)){
        error_log("[bot.php] Fatal xato: ".$err['message']." in ".$err['file'].":".$err['line']);
        if(!headers_sent()) http_response_code(200);
    }
});

session_start();
date_default_timezone_set("Asia/Tashkent");
$time = date('H:i');
ob_start();

// --- MUHIM: Barcha maxfiy ma'lumotlar endi Railway'dagi Environment Variables orqali olinadi ---
// Railway paneliga quyidagi o'zgaruvchilarni qo'shing: BOT_TOKEN, ADMIN_ID, SIMKEY, CHANNEL_ID
define('API_KEY', getenv('BOT_TOKEN') ?: 'TOKEN');
$admin   = getenv('ADMIN_ID') ?: 'id';

// --- Nomer (SMS) API sozlamalari ---
// Bu qiymatlar endi admin panel orqali ("🔢 Nomer API sozlash") botning ozidan
// ozgartirilishi mumkin. set/ papkasidagi fayllar mavjud bolmasa, Environment
// Variables (SIMKEY, SMS_API_URL) dagi standart qiymatlar ishlatiladi.
if(!is_dir(__DIR__."/../set")) @mkdir(__DIR__."/../set", 0755, true);
if(!is_dir(__DIR__."/../user")) @mkdir(__DIR__."/../user", 0755, true);
if(!is_file(__DIR__."/../set/channel")) @file_put_contents(__DIR__."/../set/channel", "");
$simkey_file  = __DIR__."/../set/simkey.txt";
$sms_url_file = __DIR__."/../set/sms_api_url.txt";

$simkey = (is_file($simkey_file) && trim((string)@file_get_contents($simkey_file)) !== "")
    ? trim(file_get_contents($simkey_file))
    : (getenv('SIMKEY') ?: 'SIMKEY'); #sms-activate.org (yoki boshqa sayt) dan olingan kalit

$default_sms_api_url = 'https://api.sms-activate.org/stubs/handler_api.php';
$sms_api_url = (is_file($sms_url_file) && trim((string)@file_get_contents($sms_url_file)) !== "")
    ? trim(file_get_contents($sms_url_file))
    : (getenv('SMS_API_URL') ?: $default_sms_api_url); #nomer sotib olinadigan saytning API manzili

$simfoiz = "50"; #simkartalarga qoyiladigan foiz
$simrub  = "130"; #hozirgi rubl kursi
$channel = getenv('CHANNEL_ID') ?: "130"; #kanal idisi
$me = "🛎️"; #hohlagan emoji 
$smm12 = "https://t.me/turkiston_coders/1"; #qullanma xizmatlardan foydalanish vedio url 
// FIX: Avval "bot('getMe')->result->username" to'g'ridan-to'g'ri chaqirilardi.
// Bu qator HAR BIR so'rovda (ya'ni foydalanuvchi bosgan HAR QANDAY tugma/
// yuborgan xabar uchun) ishlaydi. Agar shu paytda Telegram API sekinlashsa
// yoki vaqtincha javob bermasa, bot('getMe') null qaytaradi va
// "null->result" PHP FATAL XATO berib, BUTUN so'rovni to'xtatib qo'yardi —
// natijada foydalanuvchi hech qanday javob olmasdi. Endi bunday holatda
// xato logga yoziladi va script ".env"/o'zgaruvchidagi oldindan ma'lum
// bot username'iga (agar mavjud bo'lsa) yoki bo'sh qiymatga tushib davom etadi.
$__getMe = bot('getMe');
if(is_object($__getMe) && isset($__getMe->result->username)){
$bot = $__getMe->result->username;
}else{
error_log("[bot.php] getMe() muvaffaqiyatsiz bo'ldi - Telegram API javob bermadi, bo'sh username bilan davom etilmoqda.");
$bot = getenv('BOT_USERNAME') ?: '';
}

function enc($var,$exception) {
if($var=="encode"){
return base64_encode($exception);
}elseif($var=="decode"){
return base64_decode($exception);
}
}

function keyboard($a=[]){
$d=json_encode([
'inline_keyboard'=>$a
]);
return $d;
}

function api_query($s){
$qas = array("ssl"=>array("verify_peer"=>false,"verify_peer_name"=>false));
$content = file_get_contents($s, false, stream_context_create($qas));
return $content ? $content : json_encode(['balance'=>" ?"]);
}

// __DIR__ ishlatildi — bu CWD (joriy ish papkasi)dan qat'i nazar, doim
// public/ papkasidan bitta yuqoriga chiqib, app/controller/ ichidan
// sql_connect.php faylini to'g'ri topishini kafolatlaydi.
require (__DIR__."/../app/controller/sql_connect.php");

	

function arr($p){
global $connect;
$s = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `providers` WHERE id = $p"));
$data = json_decode(smm_panel_post($s['api_url'],['key'=>$s['api_key'],'action'=>'services']),1);
$values=[];
$new_arr = [];
$co=0;
foreach($data as $value){

if(!in_array($value['category'], $new_arr)){
$new_arr[] = $value['category'];
$co++;
$values[] =['id'=>$co,'name'=>$value['category']];
}else{
continue;
}
}
$val = ['count'=>$co,'results'=>$values];
return $values ? json_encode($val) : json_encode(["error"=>1]);
}



function bot($method,$datas=[]){
    $url = "https://api.telegram.org/bot".API_KEY."/".$method;
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    // --- MUHIM (tezlik uchun): timeout qo'yilmagan bo'lsa, Telegram tomon
    // sekinlashganda (yoki umuman javob bermay qolsa) bitta so'rov CHEKSIZ
    // kutib turishi mumkin edi. PHP dev-server ko'pincha BITTA so'rovni bir
    // vaqtda ishlaydi, shuning uchun bitta "osilib qolgan" so'rov BOSHQA
    // BARCHA foydalanuvchilar uchun ham botni to'xtatib qo'yardi — "bot
    // sekin ishlayapti" degan hissiyot aynan shundan kelib chiqadi.
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,5);
    curl_setopt($ch,CURLOPT_TIMEOUT,15);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$datas);
    $res = curl_exec($ch);
    if(curl_error($ch)){
        error_log("[bot.php] Telegram API'ga ulanishda xato ($method): ".curl_error($ch));
    }else{
        $decoded = json_decode($res);
        // Telegram "ok:false" qaytarganda (masalan noto'g'ri BOT_TOKEN, bloklangan
        // foydalanuvchi, bo'sh/yaroqsiz matn va h.k.) buni Railway loglariga yozamiz —
        // aks holda bunday xatolar hech qanday iz qoldirmay "sokin" yo'qolib ketaveradi.
        if(is_object($decoded) && isset($decoded->ok) && $decoded->ok === false){
            error_log("[bot.php] Telegram API xatosi ($method): ".($decoded->description ?? 'noma\'lum xato'));
        }
        return $decoded;
    }
}

function rmdirPro($path){
    $scan = array_diff(scandir($path), ['.','..']);
    foreach($scan as $value){
        if(is_dir("{$path}/{$value}"))
            rmdirPro("{$path}/{$value}");
        else
            @unlink("{$path}/{$value}");
    }
    rmdir($path);
}



function trans($x){
$e = json_decode(file_get_contents("http://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=uz&dt=t&q=".urlencode($x).""),1);
return $e[0][0][0];
}







function number($a){
$form = number_format($a,00,' ',' ');
return $form;
}

function del(){
global $cid,$mid,$chat_id,$message_id;
return bot('deleteMessage',[
'chat_id'=>$chat_id.$cid,
'message_id'=>$message_id.$mid,
]);
}


function edit($id,$mid,$tx,$m){
return bot('editMessageText',[
'chat_id'=>$id,
'message_id'=>$mid,
'text'=>"<b>$tx</b>", 
'parse_mode'=>"HTML",
'disable_web_page_preview'=>true,
'reply_markup'=>$m,
]);
}



function sms($id,$tx,$m){
return bot('sendMessage',[
'chat_id'=>$id,
'text'=>"<b>$tx</b>", 
'parse_mode'=>"HTML",
'disable_web_page_preview'=>true,
'reply_markup'=>$m,
]);
}

function referal($hi){
    $daten = [];
    $rev = [];
    $fayllar = glob("./user/*.*");
    foreach($fayllar as $file){
        if(mb_stripos($file,".users")!==false){
        $value = file_get_contents($file);
        $id = str_replace(["./user/",".users"],["",""],$file);
        $daten[$value] = $id;
        $rev[$id] = $value;
        }
        // FIX: bu yerda "echo $file;" bor edi — bu ichki fayl yo'llarini
        // (masalan "./user/12345.users") to'g'ridan-to'g'ri HTTP javobi
        // (Telegram webhook javobi) ichiga chiqarib yuborardi. Bu funksional
        // xato keltirib chiqarmasa-da (Telegram webhook javob tanasini
        // e'tiborsiz qoldiradi), bu — production kodida qolib ketgan debug
        // qatori bo'lib, ichki fayl tuzilishini keraksiz oshkor qilardi.
    }

    asort($rev);
    $reversed = array_reverse($rev);
    for($i=0;$i<$hi;$i+=1){
        $order = $i+1;
        $id = $daten["$reversed[$i]"];
        $ism=bot('getChat',[
        'chat_id'=>$id,
        ])->result->first_name;
        
        $text.= "<b>{$order}</b>. <a href='tg://user?id={$id}'>{$ism}</a> - "."<code>".floor($reversed[$i])."</code>"." <b> ta</b>"."\n";
    }
    return $text;
}


function get($h){
return is_file($h) ? @file_get_contents($h) : '';
}

// Narxni chiroyli ko'rinishda chiqarish uchun: DECIMAL(15,4) ustunidan
// kelgan "203.0000" kabi qiymatlarni ortiqcha nollarsiz ko'rsatadi
// (butun son bo'lsa - "203", kasr bo'lsa - eng ko'pi 2 xonagacha, masalan "203.5").
function fmt_price($v){
    $v = (float)$v;
    if($v == floor($v)){
        return number_format($v,0,'.','');
    }
    $s = number_format($v,2,'.','');
    $s = rtrim($s,'0');
    $s = rtrim($s,'.');
    return $s;
}

function put($h,$r){
file_put_contents($h,$r);
}

// YANGI: provayder (SMM panel) API'siga tashqi (https://...) so'rov yuborish
// uchun funksiya. Avval bu o'rinlarda xato ravishda get() ishlatilgan edi —
// get() FAQAT lokal fayllarni o'qiydi (is_file() URL uchun doim FALSE
// qaytaradi), shu sabab provayderdan xizmatlar ro'yxatini olish, buyurtma
// joylashtirish (action=add) va buyurtma holatini tekshirish (action=status)
// HECH QACHON ishlamas edi — natijada "Yangi xizmat qo'shish" jarayonida
// Xizmat IDsi kiritilgach botning "javob bermay qolishi" va foydalanuvchi
// buyurtma berganda xizmatning yakunlanmasligi shundan kelib chiqqan.
function api_get($url){
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
    curl_setopt($ch,CURLOPT_TIMEOUT,20);
    $res = curl_exec($ch);
    if(curl_error($ch)){
        error_log("[bot.php] Provayder API'ga ulanishda xato: ".curl_error($ch)." URL: ".$url);
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    return $res;
}

// SMM panel API v2 standarti (JAP-klon panellar: nitrosms.uz va shu kabilar)
// har doim POST so'rov talab qiladi — GET so'rovga ko'pchiligi javob bermaydi.
function smm_panel_post($api_url,$params){
    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$api_url);
    curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($params));
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);
    // Foydalanuvchi so'roviga ko'ra: 50 soniyaga o'rnatildi.
    curl_setopt($ch,CURLOPT_TIMEOUT,50);
    $res = curl_exec($ch);
    if(curl_error($ch)){
        // FIX: bu yerda xato "javob kelmadi" (timeout, tarmoq uzilishi va h.k.)
        // sababli chiqqanini alohida belgilaymiz - chaqiruvchi kod (masalan
        // buyurtma tasdiqlash) buni ko'rib, foydalanuvchiga aniqroq xabar
        // bera oladi ("javobni kutish muddati tugadi" - buyurtma baribir
        // bajarilgan bo'lishi mumkin, admin bilan tekshiring).
        error_log("[bot.php] SMM panel POST xato (TIMEOUT/ULANISH): ".curl_error($ch)." URL: ".$api_url);
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    // Xom (raw) javobni ham logga yozamiz - agar javob JSON bo'lmasa yoki
    // kutilmagan formatda bo'lsa (masalan HTML xato sahifasi), json_decode
    // shunchaki NULL qaytaradi va sababni bilib bo'lmay qolardi.
    error_log("[bot.php] SMM panel xom javobi URL:".$api_url." ->: ".substr((string)$res,0,500));
    return $res;
}






function joinchat($id){
$array = array();
$get = @file_get_contents("set/channel");
if($get == null){
return true;
}
// FIX: "set/channel" satrlarini tozalab, bo'sh qatorlarni (masalan oxirgi
// qatordagi ortiqcha "\n" sabab paydo bo'lgan bo'sh element) chiqarib
// tashlaymiz. Bo'sh qator bilan Telegramga so'rov yuborilsa, Telegram
// "chat not found" xatosini qaytaradi va pastda $ret->result null bo'lib
// qolib, PHP FATAL XATO bilan BUTUN so'rovni (demak foydalanuvchi bosgan
// har qanday tugmani) to'xtatib qo'yardi — bu "Xizmatlar", "Referal" va
// h.k. tugmalar sababsiz ishlamay qolishining asosiy sababi edi.
$ex = array_values(array_filter(array_map('trim', explode("\n",$get)), fn($v)=>$v!==''));
if(count($ex) == 0){
return true;
}
$uns = false;
foreach($ex as $i=>$first_line){
$kanall=str_replace("@","",$first_line);
     $ret = bot("getChatMember",[
         "chat_id"=>$first_line,
         "user_id"=>$id,
         ]);
// FIX: Telegram API xato qaytarsa (masalan bot kanalda admin emas, kanal
// o'chirilgan, username xato yozilgan yoki tarmoq xatosi) $ret->result
// mavjud bo'lmaydi. Avval to'g'ridan-to'g'ri $ret->result->status
// o'qilardi — bu holatda PHP FATAL XATO berib butun skriptni to'xtatardi.
// Endi bunday holatda xatoni logga yozib, shu kanal talabini o'tkazib
// yuboramiz (foydalanuvchini bloklab qo'ymaymiz) va davom etamiz.
if(!is_object($ret) || !isset($ret->result) || !is_object($ret->result)){
error_log("[bot.php] joinchat(): '$first_line' kanali uchun getChatMember muvaffaqiyatsiz (bot admin emasmi yoki username xato) - shu kanal tekshiruvi o'tkazib yuborildi.");
continue;
}
$stat = $ret->result->status ?? '';
         if((($stat=="creator" or $stat=="administrator" or $stat=="member"))){
      $array['inline_keyboard']["$i"][0]['text'] = "✅ ".$first_line;
$array['inline_keyboard']["$i"][0]['url'] = "https://t.me/$kanall";
         }else{
$array['inline_keyboard']["$i"][0]['text'] = "❌ ".$first_line;
$array['inline_keyboard']["$i"][0]['url'] = "https://t.me/$kanall";
$uns = true;
}
}
if($uns == true){
$array['inline_keyboard'][count($ex)][0]['text'] = "🔄 Tekshirish";
$array['inline_keyboard'][count($ex)][0]['callback_data'] = "result";
     bot('sendMessage',[
         'chat_id'=>$id,
         'text'=>"⚠️ <b>Iltimos Botdan foydalanish uchun Homiy kanallarga obuna bo'ling:</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode($array),
]);  


}else{
return true;
}

}



$update = json_decode(file_get_contents('php://input'));
if(!is_object($update)){ $update = new stdClass(); }
$message = $update->message ?? $update->edited_message ?? new stdClass();
$callback = $update->callback_query ?? null;
$mychatmember = $update->my_chat_member ?? null;
$left = $message->left_chat_member ?? null;
$new = $message->new_chat_member ?? null;
$reply = $message->reply_to_message ?? null;
$for = $message->forward_from ?? null;
$contact = $message->contact ?? null;

$edituz = $callback?->message?->from?->id;
$mesuz = $callback?->message?->message_id;
$cid = $message->chat?->id ?? $callback?->message?->chat?->id ?? $callback?->from?->id ?? 0;
$cidtyp = $message->chat?->type ?? '';
$miid = $message->message_id ?? null;
$name = $message->chat?->first_name ?? '';
$user1 = $message->from?->username ?? '';
$tx = $message->text ?? '';
$mmid = $callback?->inline_message_id;
$mes = $callback?->message ?? new stdClass();
$mid = $mes->message_id ?? null;
$cmtx = $mes->text ?? '';
$idd = $callback?->message?->chat?->id ?? 0;
$cbid = $callback?->from?->id ?? 0;
$cbuser = $callback?->from?->username ?? '';
$data = $callback?->data ?? '';
$ida = $callback?->id;
$cqid = $callback?->id;
$qid=$cqid;
$cbins = $callback?->chat_instance;
$cbchtyp = $callback?->message?->chat?->type ?? '';
// ESKI QATOR OLIB TASHLANDI: "$step = file_get_contents("step/$from_id.step");"
// Sabab: $from_id bu yerda hali aniqlanmagan edi (u pastda, ~287-qatorda aniqlanadi),
// shu bois bu chaqiruv doim "step/.step" faylini qidirib, PHP Warning chiqarardi.
// Haqiqiy $step qiymati keyinroq "$step = get("user/$cid.step");" qatorida to'g'ri o'qiladi.
$type = $message->chat?->type ?? '';
$text = $message->text ?? '';
$sd = $message->text ?? '';
$uid= $message->from?->id ?? 0;
$gname = $message->chat?->title ?? '';
$name = $message->from?->first_name ?? '';
$bio = $message->from?->about ?? '';
$repid = $reply?->from?->id;
$repname = $reply?->from?->first_name ?? '';
$newid = $new?->id;
$leftid = $left?->id;

$botdel = $mychatmember?->new_chat_member ?? null;
$botdel_id = $mychatmember?->from?->id;
$userstatus = $botdel?->status ?? '';

$newname = $new?->first_name ?? '';
$leftname = $left?->first_name ?? '';
$username = $message->from?->username ?? '';
$cmid = $callback?->message?->message_id;
$cusername = $message->chat?->username ?? '';
$repmid = $reply?->message_id;
$ccid = $callback?->message?->chat?->id ?? 0;
$cuid = $callback?->message?->from?->id ?? 0;
$from_id = $message->from?->id ?? 0;
$chat_id = $callback?->message?->chat?->id ?? 0;
$message_id = $callback?->message?->message_id;
$call = $callback;
$mes = $call?->message ?? new stdClass();
$data = $call?->data ?? '';
$qid = $call?->id;
$callbackdata = $callback?->data ?? '';
$callcid = $mes->chat?->id ?? 0;
$callmid = $mes->message_id ?? null;
$callfrid = $call?->from?->id ?? 0;
$calluser = $mes->chat?->username ?? '';
$callfname = $call?->from?->first_name ?? '';
$photo = $message->photo ?? null;
$gif = $message->animation ?? null;
$video = $message->video ?? null;
$music = $message->audio ?? null;
$voice = $message->voice ?? null;
$sticker = $message->sticker ?? null;
$document = $message->document ?? null;
$for_id = $for->id ?? null;
$nomer_id = $contact->user_id ?? null;
$nomer_user = $contact->username ?? null;
$nomet_name = $contact->first_name ?? null;
$nomer_ph = $contact->phone_number ?? null;
$cid2=$chat_id;
$mid2=$message_id;
$sana=date("d/m/Y | H:i");

function generate(){
$arr = array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','R','S','T','U','V','X','Y','Z','1','2','3','4','5','6','7','8','9','0');
$pass = "";
for($i = 0; $i < 7; $i++){
$index = rand(0, count($arr) - 1);
$pass .= $arr[$index];
}
return $pass;
}

function adduser($cid){
	global $connect;
$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid");
$row = mysqli_fetch_assoc($result);
if($row){
}else{
$key = md5(uniqid());
$referal = generate();
$rew = mysqli_num_rows(mysqli_query($connect,"SELECT * FROM users"));
$new =$rew+1;
mysqli_query($connect,"INSERT INTO users(`user_id`,`id`,`status`,`balance`,`outing`,`api_key`,`referal`) VALUES ('$new','$cid','active','0','0','$key','$referal');");
}
}



if($botdel){
if($userstatus == "kicked"){
$sql = "UPDATE `users` SET `status` = 'deactive' WHERE `id` = '$botdel_id'";
$result = mysqli_query($connect, $sql);
}
}


$active_uid = $cid ?: $chat_id;
if(isset($update) && $active_uid){
$result = mysqli_query($connect,"SELECT * FROM users WHERE id = $active_uid");
$rew = $result ? mysqli_fetch_assoc($result) : null;
if($rew && $rew['status']=="deactive"){
exit();
}
}

if($update){
if(get("status.txt")=="frozen"){
sms($cid.$chat_id,"🥶 Panel vaqtincha muzlatilgan",null);

}
}

$resu = mysqli_query($connect,"SELECT * FROM `settings`");
// FIX: mysqli_fetch_assoc($resu) to'g'ridan-to'g'ri chaqirilardi. Agar
// so'rov xato bersa ($resu===false, masalan "settings" jadvali mavjud
// emas), mysqli_fetch_assoc(false) PHP 8.1+ da FATAL XATO beradi — bu esa
// bu qator FAYL BOSHIDA, HAR BIR so'rovda ishlagani uchun, BUTUN botni
// (har qanday tugma/xabarni) to'xtatib qo'yishi mumkin edi.
$setting = ($resu !== false) ? mysqli_fetch_assoc($resu) : [];
if(!is_array($setting)){ $setting = []; }

if(!is_dir("user")) mkdir("user", 0755, true);
if(!is_dir("set")) mkdir("set", 0755, true);


$pul=get("user/$chat_id.pul");

$step = get("user/$cid.step");
$stepc = get("user/$chat_id.step");

// --- MUHIM (barqarorlik uchun): foydalanuvchi biror "step" jarayonida
// (masalan API manzili, referal miqdori va h.k. kiritish kutilayotganda)
// "Orqaga" tugmasini bossa, pastdagi "if($step=="...")" bloklari buni HALI
// HAM o'sha step uchun kiritilgan matn deb qabul qilib, "Noto'g'ri format"
// xatosi berardi yoki hatto noto'g'ri qiymatni (masalan "➡️ Orqaga" so'zini
// API kalit sifatida) saqlab qo'yardi. Shu sabab "Orqaga" bosilganda step
// darhol tozalanadi (fayldan HAM, joriy $step o'zgaruvchisidan HAM) —
// shunda pastdagi step tekshiruvlari ishlamay qoladi va foydalanuvchi
// to'g'ridan-to'g'ri tegishli menyuga qaytadi.
$orqaga_tugmalari = ["➡️ Orqaga","⏪ Orqaga","⏮️ Orqaga","🔙 Orqaga","⬅️ Orqaga","🗄️ Boshqaruv","🏠 Bosh sahifa"];
if($step && in_array($text, $orqaga_tugmalari, true)){
    @unlink("user/$cid.step");
    $step = "";
}
if($stepc && in_array($text, $orqaga_tugmalari, true)){
    @unlink("user/$chat_id.step");
    $stepc = "";
}

// --- MUHIM (barqarorlik uchun): pastda ko'plab bloklar ro'yxat (masalan
// bo'limlar, xizmatlar, to'lov usullari) tuzish uchun avval bo'sh massivga
// elementlarni bittalab qo'shadi ($k[]=...), so'ng array_chunk($k,...) orqali
// klaviaturaga aylantiradi. Agar ro'yxat MANBAI BO'SH bo'lsa (masalan hali
// birorta bo'lim/xizmat qo'shilmagan, yoki "set/payments.txt" fayli mavjud
// bo'lmasa), $k hech qachon yaratilmay, "undefined" holida qoladi va
// array_chunk() PHP 8'da FATAL XATO bilan yiqiladi — natijada foydalanuvchi
// hech qanday javob olmaydi ("Xizmatlar", "Pul kiritish" va h.k. "ishlamay
// qoladi"). Oldini olish uchun bu massivlarni oldindan bo'sh qilib
// belgilab qo'yamiz:
$k = []; $k2 = []; $ko = []; $key = []; $key1 = []; $keyboard2 = []; $keysboard2 = [];

$ort=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"➡️ Orqaga"]],
]
]);

$aort=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🗄️ Boshqaruv"]],
]
]);

$panel=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"⚙️ Asosiy sozlamalar"]],
[['text'=>"🔔 Xabar yuborish"]],
[['text'=>"📊 Statistika"]],
[['text'=>"👤 Foydalanuvchini boshqarish"]],
[['text'=>"⏰ Cron sozlamasi"]],
[['text'=>"📞 Nomer API balans"]],
[['text'=>"🔢 Nomer API sozlash"]],
[['text'=>"⏪ Orqaga"]],
]]);

if($text=="📞 Nomer API balans" and $cid == $admin){
$sms_domain = parse_url($sms_api_url, PHP_URL_HOST) ?: $sms_api_url;
$url = @file_get_contents("{$sms_api_url}?api_key=$simkey&action=getBalance");
$h = ($url !== false && mb_strpos($url,":")!==false) ? explode(":",$url)[1] : "❓ (ulanib bo'lmadi)";
sms($cid,"<b>📄 API ma'lumotlari: 
➖➖➖➖➖➖➖➖➖➖➖ 
Ulangan sayt:</b>
<code>$sms_domain</code>
 
<b>API kalit:</b>
<code>$simkey</code>

<b>API hisob:</b> $h ₽
➖➖➖➖➖➖➖➖➖➖➖",$panel);
@unlink("user/$cid.step");
exit;
}

if($text=="🔢 Nomer API sozlash" and $cid == $admin){
sms($cid,"🔢 Nomer (SMS) API sozlash

Nomer sotib olinadigan saytning API manzilini yuboring, namuna:
<pre>https://api.sms-activate.org/stubs/handler_api.php</pre>

Hozirgi manzil:
<code>$sms_api_url</code>",$ort);
file_put_contents("user/$cid.step","sms_api_url_step");
exit;
}

if($step=="sms_api_url_step"){
if($cid==$admin){
if(mb_stripos($text,"https://")!==false or mb_stripos($text,"http://")!==false){
file_put_contents("set/sms_api_url.txt",trim($text));
sms($cid,"✅ <b>API manzili qabul qilindi:</b>
<code>".trim($text)."</code>

Endi shu saytdan olingan <b>API kalitni (SIMKEY)</b> yuboring:",$ort);
file_put_contents("user/$cid.step","sms_api_key_step");
exit;
}else{
sms($cid,"⚠️ Noto'g'ri format. Iltimos to'liq havola (https:// bilan) yuboring:",$ort);
exit;
}
}
}

if($step=="sms_api_key_step"){
if($cid==$admin){
file_put_contents("set/simkey.txt",trim($text));
$new_url = trim(get("set/sms_api_url.txt"));
$check = @file_get_contents("{$new_url}?api_key=".trim($text)."&action=getBalance");
if($check !== false && mb_stripos($check,"BAD_KEY")===false && mb_stripos($check,"ERROR")===false){
$admsg = "✅ <b>API kalit saqlandi va tekshirildi!</b>

$check";
}else{
$admsg = "⚠️ <b>API kalit saqlandi</b>, lekin saytga ulanib bo'lmadi yoki kalit noto'g'ri bo'lishi mumkin. \"📞 Nomer API balans\" bo'limidan qayta tekshiring.";
}
sms($cid,$admsg,$panel);
@unlink("user/$cid.step");
exit;
}
}

if($text=="⏰ Cron sozlamasi" and $cid==$admin){
sms($cid,"
📝 Quyidagi manzillarni cron qiling
<pre>https://".$_SERVER['SERVER_NAME']."".$_SERVER['SCRIPT_NAME']."?update=send</pre> \n- Pochta xabari uchun cron (1 daqiqa)

 <pre>https://".$_SERVER['SERVER_NAME']."".$_SERVER['SCRIPT_NAME']."?update=status</pre>\n- Buyurtma xolati uchun cron (1 daqiqa)

<pre>https://".$_SERVER['SERVER_NAME']."/".str_replace(["/","bot.php"],["",""],$_SERVER['PHP_SELF'])."/update.php</pre> \n- Narxlarni avtomatik yangilash uchun cron (1 daqiqa)
",$panel);

}


if($text=="🗄️ Boshqaruv" and $cid==$admin){
sms($cid,"🖥️ Boshqaruv paneli",$panel);
@unlink("user/$cid.step");
exit;
}

if($text=="📊 Statistika" and $cid==$admin){
$stat=0;
$res = mysqli_query($connect, "SELECT * FROM users");
$stat = mysqli_num_rows($res);
$resi = mysqli_query($connect, "SELECT * FROM orders");
$stati = mysqli_num_rows($resi);
$ac =0;
$dc =0;
$pc =0;
$cc =0;
$bc =0;
$fc =0;
$jc =0;
$ppc=0;
$cp=0;
$stati ? $stati = $stati : $stati = "0";
while($hi=mysqli_fetch_assoc($resi)){
if($hi['status']=="Pending") {
$pc++;
}elseif($hi['status']=="Completed"){
$cc++;
}elseif($hi['status']=="Canceled") {
$bc++;
}elseif($hi['status']=="Failed"){
$fc++;
}elseif($hi['status']=="In progress"){
$jc++;
}elseif($hi['status']=="Partial"){
$ppc++;
}elseif($hi['status']=="Processing"){
$cp++;
}
}

while($h=mysqli_fetch_assoc($res)){
if($h['status']=="active") {
$ac++;
}elseif($h['status']=="deactive"){
$dc++;
}
}
$seco=0;
$resit= mysqli_query($connect, "SELECT * FROM services");
$seco = mysqli_num_rows($resit);

sms($cid,"
<b>📊 Statistika</b>
• Jami foydalanuvchilar: $stat ta
• Aktiv foydalanuvchilar: $ac ta
• O'chirilgan foydalanuvchilar: $dc ta

<b>📊 Buyurtmalar</b>
• Jami buyurtmalar: $stati ta
• Bajarilgan buyurtmalar: $cc ta
• Kutilayotgan buyurtmalar: $pc ta
• Jarayondagi buyurtmalar: $jc ta
• Bekor qilingan buyurtmalar: $bc ta
• Muvaffaqiyatsiz buyurtmalar: $fc ta
• Qisman bajarilgan buyurtmalar: $ppc ta
• Qayta ishlangan buyurtmalar: $cp ta

<b>📊 Xizmatlar</b>:
• Barcha xizmatlar: $seco ta
",keyboard([
[['text'=>"♻️ Buyurtmalar xolatini yangilash",'callback_data'=>"update=orders"]],
]));
@unlink("user/$cid.step");

}

if((stripos($data,"update=")!==false)){
$resi = mysqli_query($connect, "SELECT * FROM orders");
$stati = mysqli_num_rows($resi);
$ac =0;
$dc =0;
$pc =0;
$cc =0;
$bc =0;
$fc =0;
$jc =0;
$cp =0;
$ppc=0;

$stati ? $stati = $stati : $stati = "0";
while($hi=mysqli_fetch_assoc($resi)){
if($hi['status']=="Pending") {
$pc++;
}elseif($hi['status']=="Completed"){
$cc++;
}elseif($hi['status']=="Canceled") {
$bc++;
}elseif($hi['status']=="Failed"){
$fc++;
}elseif($hi['status']=="In progress"){
$jc++;
}elseif($hi['status']=="Processing"){
$cp++;
}elseif($hi['status']=="Partial"){
$ppc++;
}
}
	
$res = explode("=", $data)[1];
if($res=="orders") {

del();
sms($cid2,"
📊 Buyurtmalar ro'yxati:

• Jami buyurtmalar: $stati ta
• Bajarilgan buyurtmalar: $cc ta
• Kutilayotgan buyurtmalar: $pc ta
• Jarayondagi buyurtmalar: $jc ta
• Bekor qilingan buyurtmalar: $bc ta
• Muvaffaqiyatsiz buyurtmalar: $fc ta
• Qisman bajarilgan buyurtmalar: $ppc ta
• Qayta ishlangan buyurtmalar: $cp ta
",keyboard([
[['text'=>"Kutilayotgan buyurtmalarni yangilash",'callback_data'=>"update=pending"]],
[['text'=>"Jarayondagi buyurtmalarni yangilash",'callback_data'=>"update=In progress"]],
[['text'=>"Qisman bajarilgan buyurtmalarni yangilash",'callback_data'=>"update=partial"]],
[['text'=>"Qayta ishlangan buyurtmalarni yangilash",'callback_data'=>"update=processing"]],
]));
}elseif($res=="pending"){
del();
sms($cid2,"
📊 Buyurtmalar ro'yxati:

• Kutilayotgan buyurtmalar: $pc ta",keyboard([
[['text'=>"Bajarilgan xolatga o‘tkazish",'callback_data'=>"update=new=Pending=Completed"]],
[['text'=>"Jarayondagi xolatga o‘tkazish",'callback_data'=>"update=new=Pending=In progress"]],
[['text'=>"Orqaga",'callback_data'=>"update=orders"]],
]));
}elseif($res=="processing"){
del();
sms($cid2,"
📊 Buyurtmalar ro'yxati:

• qayta ishlangan buyurtmalar: $cp ta",keyboard([
[['text'=>"Bajarilgan xolatga o‘tkazish",'callback_data'=>"update=new=Processing=Completed"]],
[['text'=>"Jarayondagi xolatga o‘tkazish",'callback_data'=>"update=new=Processing=In progress"]],
[['text'=>"Orqaga",'callback_data'=>"update=orders"]],
]));
}elseif($res=="partial"){
del();
sms($cid2,"
📊 Buyurtmalar ro'yxati:

• • Qisman bajarilgan buyurtmalar: $ppc ta",keyboard([
[['text'=>"Bajarilgan xolatga o‘tkazish",'callback_data'=>"update=new=Partial=Completed"]],
[['text'=>"Jarayondagi xolatga o‘tkazish",'callback_data'=>"update=new=Partial=In progress"]],
[['text'=>"Orqaga",'callback_data'=>"update=orders"]],
]));
}elseif($res=="In progress"){
del();
sms($cid2,"
📊 Buyurtmalar ro'yxati:

• Jarayondagi buyurtmalar: $jc ta",keyboard([
[['text'=>"Bajarilgan xolatga o‘tkazish",'callback_data'=>"update=new=In progress=Completed"]],
[['text'=>"Kutilayotgan xolatga o‘tkazish",'callback_data'=>"update=new=In progress=Pending"]],
[['text'=>"Orqaga",'callback_data'=>"update=orders"]],
]));
}elseif($res=="new"){
$out = explode("=",$data)[2];
$inp = explode("=",$data)[3];
$mysqli = mysqli_query($connect, "SELECT * FROM orders WHERE status = '$out'");
while($all = mysqli_fetch_assoc($mysqli)){
$io = $all['order_id'];

$mysa=mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `myorder` WHERE order_id=$io"));
$adm=$mysa['user_id'];

mysqli_query($connect,"UPDATE orders SET status ='$inp' WHERE order_id = $io");
if($inp=="Completed") {
$sav = date("Y.m.d H:i:s");
// FIX: bu yerda "$input" degan HECH QAYERDA aniqlanmagan (undefined)
// o'zgaruvchi ishlatilgan edi - shu sabab myorder.status doim BO'SH
// qiymatga o'rnatilardi ("Completed" o'rniga). To'g'ri o'zgaruvchi - $inp.
mysqli_query($connect,"UPDATE myorder SET status='$inp', last_check='$sav' WHERE order_id=$io");
}else{
mysqli_query($connect,"UPDATE myorder SET status='$inp' WHERE order_id=$io");
}
if($inp=="Completed"){
sms($adm,"✅ Sizning $io raqamli buyurtmangiz bajarildi",null);
}
}
del();
sms($cid2,"✅ Jarayon tugallandi.",null);
}
}

if($text == "🔔 Xabar yuborish" and $cid == $admin){
$result = mysqli_query($connect, "SELECT * FROM `send`");
$row = mysqli_fetch_assoc($result);
if(!$row){
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b>📤 Foydalanuvchilarga yuboriladigan xabarni botga yuboring!</b>",
'parse_mode'=>'html',
'reply_markup'=>$aort
]);
put("user/$cid.step","send");

}else{
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b>📑 Hozirda botda xabar yuborish jarayoni davom etmoqda. Yangi xabar yuborish uchun eski yuborilayotgan xabar barcha foydalanuvchilarga yuborilishini kuting!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);

}
}

if($step== "send" and $cid==$admin){
$result = mysqli_query($connect, "SELECT * FROM users");
$stat = mysqli_num_rows($result);
$res = mysqli_query($connect,"SELECT * FROM users WHERE user_id = '$stat'");
$row = mysqli_fetch_assoc($res);
$user_id = $row['id'];
$time1 = date('H:i', strtotime('+1 minutes'));
$time2 = date('H:i', strtotime('+2 minutes'));
$time3 = date('H:i', strtotime('+3 minutes'));
$time4 = date('H:i', strtotime('+4 minutes'));
$time5 = date('H:i', strtotime('+5 minutes'));
$tugma = json_encode($update->message->reply_markup);
$reply_markup = base64_encode($tugma);
mysqli_query($connect, "INSERT INTO `send` (`time1`,`time2`,`time3`,`time4`,`time5`,`start_id`,`stop_id`,`admin_id`,`message_id`,`reply_markup`,`step`) VALUES ('$time1','$time2','$time3','$time4','$time5','0','$user_id','$admin','$mid','$reply_markup','send')");
bot('sendMessage',[
'chat_id'=>$admin,
'text'=>"<b>
📋 Saqlandi!
📑 Xabar foydalanuvchilarga $time1 da yuborish boshlanadi!</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);
@unlink("user/$cid.step");

}

$result = mysqli_query($connect, "SELECT * FROM `send`"); 
$row = mysqli_fetch_assoc($result) ?: [];
$sendstep = $row['step'] ?? null;
if((isset($_GET['update']) ? $_GET['update'] : null)=="send"){
$row1 = $row['time1'];
$row2 = $row['time2'];
$row3 = $row['time3'];
$row4 = $row['time4'];
$row5 = $row['time5'];
$start_id = $row['start_id'];
$stop_id = $row['stop_id'];
$admin_id = $row['admin_id'];
$mied = $row['message_id'];
$tugma = $row['reply_markup'];
if($tugma == "bnVsbA=="){
$reply_markup = "";
}else{
$reply_markup = base64_decode($tugma);
}
$time1 = date('H:i', strtotime('+1 minutes'));
$time2 = date('H:i', strtotime('+2 minutes'));
$time3 = date('H:i', strtotime('+3 minutes'));
$time4 = date('H:i', strtotime('+4 minutes'));
$time5 = date('H:i', strtotime('+5 minutes'));
$limit = 150;

if($time == $row1 or $time == $row2 or $time == $row3 or $time == $row4 or $time == $row5){
$sql = "SELECT * FROM `users` LIMIT $start_id,$limit";
$res = mysqli_query($connect,$sql);
while($a = mysqli_fetch_assoc($res)){
$id = $a['id'];
if($id == $stop_id){
bot('forwardMessage',[
'chat_id'=>$id,
'from_chat_id'=>$admin_id,
'message_id'=>$mied,
'disable_web_page_preview'=>true,
'reply_markup'=>$reply_markup
]);

bot('sendMessage',[
'chat_id'=>$admin_id,
'text'=>"<b>✅ ️Xabar barcha bot foydalanuvchilariga yuborildi!</b>",
'parse_mode'=>'html'
]);
mysqli_query($connect, "DELETE FROM `send`");
exit;
}else{
bot('forwardMessage',[
'chat_id'=>$id,
'from_chat_id'=>$admin_id,
'message_id'=>$mied,
'disable_web_page_preview'=>true,
'reply_markup'=>$reply_markup
]);
}
}
mysqli_query($connect, "UPDATE `send` SET `time1` = '$time1'");
mysqli_query($connect, "UPDATE `send` SET `time2` = '$time2'");
mysqli_query($connect, "UPDATE `send` SET `time3` = '$time3'");
mysqli_query($connect, "UPDATE `send` SET `time4` = '$time4'");
mysqli_query($connect, "UPDATE `send` SET `time5` = '$time5'");
$get_id = $start_id + $limit;
mysqli_query($connect, "UPDATE `send` SET `start_id` = '$get_id'");
bot('sendMessage',[
'chat_id'=>$admin_id,
'text'=>"<b>✅ Yuborildi: $get_id</b>",
'parse_mode'=>'html'
]);
}
echo json_encode(["status"=>true,"cron"=>"Sending message"]);
}



$menu=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🛍 Xizmatlar"],['text'=>"📞 Nomer olish"]],
[['text'=>"🗣 Referal"],['text'=>"📊Buyurtmalarim"],['text'=>"⭐️Premium"]],
[['text'=>"💳 Hisobim"],['text'=>"💳 Pul kiritish"]],
[['text'=>"🤖 SMM Bot"],['text'=>"📨 Yordam"],['text'=>"📕 Qo'llanma"]],
[['text'=>"🤝 Hamkorlik dasturi"]],

]
]);
$panel2=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🛍 Buyurtmalarni sozlash"]],
[['text'=>"💵 Kursni o‘rnatish"],['text'=>"⚖️ Foizni o‘rnatish"]],
[['text'=>"📊 Buyurtmani tekshirish"]],
[['text'=>"📎 Majburiy obunalar"],['text'=>"🔑 API Sozlamalari"]],
[['text'=>"⚙️ Boshqa sozlamalar"]],
[['text'=>"🏠 Bosh sahifa"]],
]]);

// FIX: "⏪ Orqaga" tugmasi $panel klaviaturasida (Boshqaruv paneli ichida)
// mavjud edi, lekin bosilganda hech qanday javob qaytaruvchi kod yo'q edi —
// shu sabab tugma bosilganda bot hech narsa qilmay "jim" qolardi.
// Bu tugma "⚙️ Asosiy sozlamalar" menyusidan ($panel2) ochilgani uchun,
// bosilganda foydalanuvchini o'sha menyuga qaytaramiz.
if($text=="⏪ Orqaga" and $cid==$admin){
sms($cid,"⚙️ Asosiy sozlamalar",$panel2);
@unlink("user/$cid.step");
exit;
}

if($text=="⚙️ Boshqa sozlamalar" and $cid==$admin){
sms($cid,"⭐ Kerakli bo'limni tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Matnlar sozlamalari",'callback_data'=>"birlamch=matn"]],
[['text'=>"💳 Hamyonlar sozlamalari",'callback_data'=>"birlamch=cards"]],
[['text'=>"💳 Avto tolov sozlamalari",'callback_data'=>"birlamch=autopays"]],
]]));

}

if((stripos($data,"birlamch=")!==false)){
$res=explode("=",$data)[1];
if($res=="matn"){
edit($chat_id,$message_id,"👉 Sozlama turini tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Nomini o‘zgartirish",'callback_data'=>"birlamch=editM"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]));
}elseif($res=="tugma"){
edit($chat_id,$message_id,"👉 Sozlama turini tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Nomini o‘zgartirish",'callback_data'=>"birlamch=editT"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]));
}elseif($res=="exit"){
del();
sms($chat_id,"⭐ Kerakli bo'limni tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"📑 Matnlarni sozlash",'callback_data'=>"birlamch=matn"]],
[['text'=>"🎛️ Tugmalarni sozlash",'callback_data'=>"birlamch=tugma"]],
[['text'=>"🎁 Referal sozlamalari",'callback_data'=>"birlamch=ref"]],
[['text'=>"💳 Hamyonlar sozlamalari",'callback_data'=>"birlamch=cards"]],
[['text'=>"💳 Avto tolov sozlamalari",'callback_data'=>"birlamch=autopays"]],
]]));
}elseif($res=="editM"){

// FIX: bu yerdagi tugmalar noto'g'ri callback_data'ga bog'langan edi -
// "2. Yangi buyurtma uchun matn" deb yozib qo'yilgan tugma aslida
// "birlamchi=referal" (referal narxi)ga, "3. Kabinet" bilan bir qatordagi
// tugma esa noto'g'ri "2" raqami bilan "birlamchi=orders"ga bog'langan edi.
// Natijada admin "2" tugmasini bossa, buyurtma matni o'rniga referal
// narxini tahrirlash oynasi ochilardi. Endi raqamlar va callback_data'lar
// yuqoridagi ro'yxatga mos qilib to'g'irlandi (bor-yo'g'i 4 ta tahrirlanadigan
// maydon mavjud: start, orders, kabinet, referal narxi).
edit($chat_id,$message_id,"
📑 Kerakli matnni tanlang:

1. /start uchun matn
2. Yangi buyurtma uchun matn
3. Kabinet uchun matn
4. Referal narxi",json_encode([
'inline_keyboard'=>[
[['text'=>"1",'callback_data'=>"birlamchi=start"],['text'=>"2",'callback_data'=>"birlamchi=orders"]],
[['text'=>"3",'callback_data'=>"birlamchi=kabinet"],['text'=>"4",'callback_data'=>"birlamchi=referal"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=matn"]],
]]));
}elseif($res=="ref"){
edit($chat_id,$mid2,"⚙️ Sozlama turini tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"🎁 Referal tugma xolati",'callback_data'=>"referr=xolati"]],
[['text'=>"🎁 Bonusni o‘zgartirish",'callback_data'=>"referr=edit"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]));
}elseif($res == "cards"){
del();
$delturi = file_get_contents("set/payments.txt");
$delmore = explode("\n",$delturi);
$delsoni = substr_count($delturi,"\n");
$key=[];
for ($delfor = 1; $delfor <= $delsoni; $delfor++) {
$title=str_replace("\n","",$delmore[$delfor]);
$key[]=["text"=>"$title - ni o'chirish","callback_data"=>"delPayMethod-$title"];
$keyboard2 = array_chunk($key, 1);
$keyboard2[] = [['text'=>"➕ Yangi to'lov tizimi qo'shish",'callback_data'=>"new"]];
$keyboard2[] = [['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]];
$pay = json_encode([
'inline_keyboard'=>$keyboard2,
]);
}
if($cid2==$admin){
if($delturi == null){
bot('SendMessage',[
	'chat_id'=>$cid2,
	'text'=>"<b>Quyidagilardan birini tanlang:</b>",
	'parse_mode'=>'html',
		'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"➕ Yangi to'lov tizimi qo'shish",'callback_data'=>"new"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]
])
]);

}else{
	bot('SendMessage',[
	'chat_id'=>$cid2,
	'text'=>"<b>Quyidagilardan birini tanlang:</b>",
	'parse_mode'=>'html',
		'reply_markup'=>$pay
]);

}
}
}elseif($res=="autopays"){
edit($cid2,$mid2,"👉 Kerakli tolov tizimini tanlang:",keyboard([
[['text'=>"💳 PAYME",'callback_data'=>"autopay=payme"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]));
}
}

if(mb_stripos($data,"autopay=")!==false){
$ex = explode("=",$data)[1];
if($ex=="payme"){
if(empty($setting['payme_id']) or $setting['payme_id']=="null"){
edit($cid2,$mid2,"👉 Kerakli sozlamani tanlang:",keyboard([
[['text'=>"➕ Karta IDsini qo‘shish",'callback_data'=>"autopay=payme_id"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]));
}else{
edit($cid2,$mid2,"👉 Kerakli sozlamani tanlang

🆔 Hozirgi karta IDsi: ".$setting['payme_id']."",keyboard([
[['text'=>"➕ Karta IDsini o‘zgartirish",'callback_data'=>"autopay=payme_id"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]));
}
}elseif($ex=="payme_id") {
del();
bot("sendMediaGroup",[ 
"chat_id"=>$cid2, 
"media"=>json_encode([ 
["type"=>"photo","media" => "https://t.me/s1_kanal/61"], 
["type"=>"photo","media" => "https://t.me/s1_kanal/62"], 
["type"=>"photo","media" => "https://t.me/s1_kanal/63","caption"=>"
1 - «<b>Kartalarim</b>» tugmasini bosing
2 - «<b>Kerakli karta</b>» ni tanlab ustiga bosing
3 - «<b>Havolani ko‘chirib olish</b>» ga bosib linkni saqlab oling va shuyerga kiriting.",'parse_mode'=>"html"],
]),
]);
sms($cid2,"💳 Kartangizning unikal manzilini kiriting

✅ Malumotlaringiz 100% maxfiy saqlanadi.",$aort);
put("user/$cid2.step","%%₹_-#");
}
}
if($step=="%%₹_-#" and $cid==$admin){
if((mb_stripos($text,"https://")!==false) and (mb_stripos($text,"https://payme.")!==false) and (mb_stripos($text,"payme.uz")!==false)){
$card = explode("/",$text)[3];
sms($cid,"✅ O‘zgartirish muvaffaqiyatli amalga oshirildi.",$panel);
mysqli_query($connect,"UPDATE settings SET `payme_id` = '$card' WHERE id = 1");
@unlink("user/$cid.step");

}

}






if(mb_stripos($data,"delPayMethod-")!==false){
	$ex = explode("-",$data)[1];
	$delturi = file_get_contents("set/payments.txt");
	$delturi = str_replace("\n".$ex."","",$delturi);
   file_put_contents("set/payments.txt",$delturi);
bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
	]);
bot('SendMessage',[
	'chat_id'=>$cid2,
	'text'=>"🗑️ <b>To'lov tizimi o'chirildi!</b>",
		'parse_mode'=>'html',
	'reply_markup'=>$panel
]);
rmdirPro("set/pay/$ex");
}

if($data == "new"){
	bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
   ]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
   'text'=>"🔠 <b>Yangi to'lov tizimi nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
	]);
	file_put_contents("user/$cid2.step",'turi');
	
}

if($step == "turi"){
if($cid==$admin){
if(isset($text)){
put("set/title.txt",$text);
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"🔢 <b>Ushbu to'lov tizimidagi hamyoningiz raqamini yuboring:</b>",
	'parse_mode'=>'html',
	]);
	file_put_contents("user/$cid.step",'wallet');
	
}
}
}


if($step == "wallet"){
if($cid==$admin){

put("set/wallet.txt",$text);
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"✅ <b>Ushbu to'lov tizimi orqali hisobni to'ldirish bo'yicha ma'lumotni yuboring:</b>

<i>Misol uchun, \"Ushbu to'lov tizimi orqali pul yuborish jarayonida izoh kirita olmasligingiz mumkin. Ushbu holatda, biz bilan bog'laning.</i>\"",
'parse_mode'=>'html',
	]);
	file_put_contents("user/$cid.step",'addition');
	
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"🔢 <b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
]);


}
}

if($step == "addition"){
		if($cid==$admin){
	if(isset($text)){
$ttest=get("set/title.txt");
file_put_contents("set/payments.txt","\n".$ttest,FILE_APPEND);
mkdir("set/pay");
mkdir("set/pay/$ttest");
file_put_contents("set/pay/$ttest/addition.txt","$text");
file_put_contents("set/pay/$ttest/wallet.txt",get("set/wallet.txt"));
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"✅ <b>$ttest to'lov tizimi qo'shildi!</b>",
	'parse_mode'=>'html',
	'reply_markup'=>$panel,
	]);
	@unlink("user/$cid.step");
	
}
}
}


if((stripos($data,"referr=")!==false)){
$res = explode("=",$data)[1];
$fo = explode("=",$data)[2];
if($res=="xolati"){
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM settings WHERE id = 1"))["ref_status"];
if($m == "on"){
$tx = "✅";
$kb = json_encode([
'inline_keyboard'=>[
[['text'=>"«❌»",'callback_data'=>"referr=ok=off"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]);
}elseif($m == "off"){
$tx = "❌";
$kb = json_encode([
'inline_keyboard'=>[
[['text'=>"«✅»",'callback_data'=>"referr=ok=on"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]);
}
edit($cid2,$mid2,"🎁 Referal tugma xolati: $tx",$kb);
}elseif($res=="ok") {
mysqli_query($connect,"UPDATE settings SET ref_status = '$fo' WHERE id = 1");
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM settings WHERE id = 1"))["ref_status"];
if($m == "on"){
$tx = "✅";
$kb = json_encode([
'inline_keyboard'=>[
[['text'=>"«❌»",'callback_data'=>"referr=ok=off"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]);
}elseif($m == "off"){
$tx = "❌";
$kb = json_encode([
'inline_keyboard'=>[
[['text'=>"«✅»",'callback_data'=>"referr=ok=on"]],
[['text'=>"Orqaga",'callback_data'=>"birlamch=exit"]],
]]);
}
edit($cid2,$mid2,"🎁 Referal tugma xolati: $tx",$kb);
}elseif($res=="edit") {
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM settings WHERE id = 1"))["bonus"];
del();
sms($cid2,"
🔢 Referal bonus miqdorini kiriting. (raqamlarda)

📝 Hozirgi xolati: $m%",$aort);
put("user/$cid2.step","*##");
}
}
if($step=="*##" and $cid==$admin){
if(is_numeric($text)==1){
mysqli_query($connect,"UPDATE settings SET bonus = '$text' WHERE id = 1");
sms($cid,"✅ O‘zgarish saqlandi",$panel);
@unlink("user/$cid.step");

}
}
if((stripos($data,"birlamchi=")!==false)){
$res = explode("=",$data)[1];
if($res=="start"){
$arr = "<code>{balance} </code> - Foydalanuvchi hisobi\n<pre>{name}</pre> - Foydalanuvchi ismi\n<pre>{time} </pre> - Hozirgi vaqt (UTC+5 / UZ)";
}elseif($res=="kabinet") {
$arr ="<pre>{id}</pre> - Foydalanuvchi IDsi\n<pre>{balance}</pre> - Foydalanuvchi hisobi\n<pre>{outing}</pre> - Kiritgan pullar miqdori";
}elseif($res=="referal") {
$arr = "1 ta taklif uchun tolov miqdorini kiriting:";
}elseif($res=="orders") {
$arr ="<pre>{order}</pre> - Buyurtma IDsi (standard)\n<pre>{order_api}</pre> - Buyurtma IDsi (API)";
}
put("bir.txt",$res);
del();
sms($chat_id,"
📝 Yangi matnlarni kiriting.

⚙️ O‘zgaruvchilar:
$arr

📝 Hozirgi matnlar",$aort);
$m  = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM settings WHERE id = 1"))[$res];
sms($chat_id,enc("decode",$m),null);
put("user/$chat_id.step","!?+-");
}
if($step=="!?+-" and $cid==$admin){

$vq = get("bir.txt");
$vo = enc("encode",$text);
mysqli_query($connect,"UPDATE settings SET `$vq` = '$vo' WHERE id = 1");
sms($cid,"✅ O‘zgartirishlar saqlandi",$panel);
unlink("bir.txt");
@unlink("user/$cid.step");
exit;
}


if($text=="📊 Buyurtmani tekshirish" and joinchat($cid)==1) {
$resi = mysqli_query($connect, "SELECT * FROM orders");
$stati = mysqli_num_rows($resi);
sms($cid,"
🔢 Barcha buyurtmalar: $stati ta

➡️ Buyurtma IDsini kiriting:",$aort);
put("user/$cid.step",orders2);
exit;
}


if($step=="orders2" and $cid==$admin and is_numeric($text)==1){
$resi = mysqli_query($connect, "SELECT * FROM orders WHERE order_id = '$text'");
$stati = mysqli_fetch_assoc($resi);
if(!$stati){
sms($cid,"❌ Buyurtma topilmadi.",$aort);
}else{
$prv = $stati['provider'];
$a = mysqli_query($connect,"SELECT * FROM providers WHERE id = $prv");
$c = mysqli_fetch_assoc($a);
$prg = $stati['provider'];
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `providers` WHERE id = '$prg'"));
$surl = $m['api_url'];
$skey =$m['api_key'];

$api = json_decode(smm_panel_post($surl,['key'=>$skey,'action'=>'status','order'=>$stati['api_order']]), 1);
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$c['api_url']);
sms($cid,"
*️⃣ Server: $prtxt
🔢 Buyurtma IDsi: <code>".$stati['api_order']."</code>
✅ Buyurtma xolati ($prtxt): <code>".$api['status']."</code>",$panel2);
@unlink("user/$cid.step");
}
exit;
}



if($text == "🔑 API Sozlamalari"){
	if($cid == $admin){
	bot('SendMessage',[
	'chat_id'=>$cid,
'text'=>"Quyidagi bo'limlardan birini tanlang:",
	'parse_mode'=>'html',
	'reply_markup'=>json_encode([
	'inline_keyboard'=>[
	[['text'=>"➕ API qo‘shish",'callback_data'=>"api"]],
	[['text'=>"💵 Balansni ko'rish",'callback_data'=>"balans"]],
	[['text'=>"🗑️ O‘chirish",'callback_data'=>"deleteapi"]],
	[['text'=>"📝 Taxrirlash",'callback_data'=>"apio=taxrirlash"]],
]
	])
	]);
	exit;
}
}

if((stripos($data,"apio=")!==false)){
$res=explode("=",$data)[1];
if($res=="taxrirlash") {
edit($cid2,$mid2,"📝 Taxrirlash menyusini tanlang",keyboard([
[['text'=>"🔑 Kalitni o‘zgartirish",'callback_data'=>"apio=kalit"]],
[['text'=>"⬅️ Orqaga", 'callback_data'=>"api1"]],
]));
}elseif($res=="kalit") {
$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$s['api_url']);
$prs.="$pr: <b>$prtxt\n</b>";
$k[]=["text"=>$pr,"callback_data"=>"apio=edit=".$s['id']];
}
$keyboard2=array_chunk($k,3);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"api1"]];
$kb=json_encode(['inline_keyboard'=>$keyboard2]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"Provayderni tanlang:

$prs
",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);

}
}elseif($res=="edit") {
del();
$co=explode("=",$data)[2];
sms($cid2,"🔠 Yangi kalitni kiriting:",$aort);
put("user/$cid2.step","kalitnew=$co");
}
}


if((mb_stripos($step,"kalitnew=")!==false) and $cid==$admin){
sms($cid,"✅ O‘zgartirish muvaffaqiyatli amalga oshirildi.",$panel);
$io = explode("=",$step)[1];
mysqli_query($connect,"UPDATE providers SET api_key = '$text' WHERE id = $io");
@unlink("user/$cid.step");

}


if($data == "deleteapi"){
$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$s['api_url']);
$prs.="$pr: <b>$prtxt\n</b>";
$k[]=["text"=>$pr,"callback_data"=>"apidel=".$s['id']];
}
$keyboard2=array_chunk($k,3);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"api1"]];
$kb=json_encode(['inline_keyboard'=>$keyboard2]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"Provayderni tanlang:

$prs
",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);
exit;
}
}

if((stripos($data,"apidel=")!==false)){
$res = explode("=",$data)[1];
del();
mysqli_query($connect,"DELETE FROM providers WHERE id = $res");
mysqli_query($connect,"DELETE FROM services WHERE api_service = $res");
sms($cid2,"🗑️ Provayderni o‘chirish yakunlandi.",null);
}

if($data == "api1"){
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
	]);
	bot('SendMessage',[
	'chat_id'=>$chat_id,
'text'=>"Quyidagi bo'limlardan birini tanlang:",
	'parse_mode'=>'html',
	'reply_markup'=>json_encode([
	'inline_keyboard'=>[
	[['text'=>"➕ API qo‘shish",'callback_data'=>"api"]],
	[['text'=>"💵 Balansni ko'rish",'callback_data'=>"balans"]],
	[['text'=>"🗑️ O‘chirish",'callback_data'=>"deleteapi"]],
	[['text'=>"📝 Taxrirlash",'callback_data'=>"apio=taxrirlash"]],
]
	])
	]);
	exit;
}

if($data == "api"){
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
	]);
	bot('SendMessage',[
	'chat_id'=>$chat_id,
	'text'=>"<b>API manzilini yuboring:

Namuna:</b> <pre>https://apiseen.uz/api/v2</pre>",
	'parse_mode'=>'html',
	'reply_markup'=>$boshqarish,
	]);
	file_put_contents("user/$chat_id.step",'api_url');
	exit;
}

if($step == "api_url"){
	if($cid == $admin){
   if(mb_stripos($text, "https://")!==false){
	if(isset($text)){
	file_put_contents("set/api_url",$text);
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"$text <b>qabul qilindi!</b>
	
	Endi esa ushbu saytdan olingan API_KEY'ni kiriting:",
'disable_web_page_preview'=>true,
	'parse_mode'=>'html',
	]);
	file_put_contents("user/$cid.step",'api');
	exit;
}
}else{
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"<b>API manzilini yuboring:

Namuna:</b> <pre>https://apiseen.uz/api/v2</pre>",
	'parse_mode'=>'html',
]);
exit;
}
}
}

if($step == "api"){
	if($cid == $admin){
	if(isset($text)){
$balans_url = get("set/api_url");
$raw_balans = smm_panel_post($balans_url, ['key'=>$text, 'action'=>'balance']);
$balans = json_decode((string)$raw_balans, true);
error_log("[bot.php][api balance debug] URL: $balans_url | RAW JAVOB: ".var_export($raw_balans,true));
if(!is_array($balans) || isset($balans['error'])){
$admsg="⚠️ Balansni olish imkoni bo'lmadi

Ehtimol: API kalit noto'g'ri, API manzili noto'g'ri, yoki sayt javob bermadi.

<b>Server javobi (debug):</b>
<code>".htmlspecialchars(mb_substr((string)$raw_balans,0,300))."</code>";
}else{
global $connect;
$admsg="<b>💵 API balansi:</b> ".($balans['balance'] ?? '?')." ".($balans['currency'] ?? '');
$apc = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers"));
$api_url = get("set/api_url");
mysqli_query($connect,"INSERT INTO providers(`api_url`,`api_key`) VALUES ('$api_url','$text')");
}
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"<b>$admsg</b>",
	'parse_mode'=>'html',
	'reply_markup'=>$panel,
	]);
	@unlink("user/$cid.step");
	
}
}
}


if($data == "balans"){
$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$s['api_url']);
$sa= json_decode(smm_panel_post($s['api_url'],['key'=>$s['api_key'],'action'=>'balance']));

$prs.="<b>".$pr."</b>: $prtxt - ".(isset($sa->balance)?$sa->balance:'?')." ".(isset($sa->currency)?$sa->currency:'')." \n";
$k[]=["text"=>$pr,"url"=>$s['api_url']];
}
$keyboard2=array_chunk($k,3);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"api1"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"Provayderni tanlang:

$prs
",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);

}
}



if($text == "/tarif"){
sms($cid,"👉 Barcha ta'riflar",keyboard([
[['text'=>"📝 Ta'riflar",'url'=>"https://".$_SERVER['HTTP_HOST']."/services"]],
]));
}

if($text == "🤝 Hamkorlik dasturi") {
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$cid'");
$rew = mysqli_fetch_assoc($result);
sms($cid,"
<b>⭐ Sizning API kalitingiz:
<code>".$rew['api_key']."</code>

💵 API hisobi:
<b>".$rew['balance']."</b> so‘m
</b>",keyboard([
[['text'=>"📝 Qo‘llanma",'callback_data'=>"apidetail=qoll"]],
[['text'=>"🔄 APIni yangilash",'callback_data'=>"apidetail=newkey"]],
]));
}

if((stripos($data,"apidetail=")!==false)){
$res = explode("=",$data)[1];
if($res == "newkey"){
$newkey = md5(uniqid());
mysqli_query($connect,"UPDATE users SET api_key = '$newkey' WHERE id = '$chat_id'");
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
$rew = mysqli_fetch_assoc($result);
bot('editMessageText',[
'chat_id'=>$chat_id,
'parse_mode'=>"html",
'message_id'=>$message_id,
'text'=>"<b>
✅ API kalit yangilandi.

<code>".$rew['api_key']."</code>

💵 API hisobi:
<b>".$rew['balance']."</b> so‘m
</b>",
'reply_markup'=>keyboard([
[['text'=>"📝 Qo‘llanma",'callback_data'=>"apidetail=qoll"]],
[['text'=>"🔄 APIni yangilash",'callback_data'=>"apidetail=newkey"]],
])
]);
}elseif($res == "qoll") {
	bot('editMessageText',[
'chat_id'=>$chat_id,
'parse_mode'=>"html",
'message_id'=>$message_id,
'text'=>"<b>
❓ APi nima?
Botimizdagi xizmatlarni siz ham o'z botingizga yoki saytingizga ulab ishlatishingiz mumkin. Buni ishlatish oson va qulay. Ushbu tizim xavfsizligi taminlanagan. Ko'proq imkoniyat bilan foydalaning. Sizni api kalitingiz agarda boshqalarga ma'lum bo'lsa yangisiga almashtiring. Albatta botga ulash uchun qo'llanma mavjud.

🔑 APi kalitni ishlatish haqida web saytimiz: ".$_SERVER['HTTP_HOST']."


</b>",
'reply_markup'=>keyboard([
[['text'=>"📝 Qo‘llanma",'web_app'=>['url'=>"https://".$_SERVER['HTTP_HOST']."/api"]]],
[['text'=>"🔄 APIni yangilash",'callback_data'=>"apidetail=newkey"]],
])
]);
}
	
	
}


$menu_p=json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"🛍 Xizmatlar"],['text'=>"📞 Nomer olish"]],
[['text'=>"🗣 Referal"],['text'=>"📊Buyurtmalarim"],['text'=>"⭐️Premium"]],
[['text'=>"💳 Hisobim"],['text'=>"💳 Pul kiritish"]],
[['text'=>"🤖 SMM Bot"],['text'=>"📨 Yordam"],['text'=>"📕 Qo'llanma"]],
[['text'=>"🤝 Hamkorlik dasturi"]],
[['text'=>"🗄️ Boshqaruv"]],
]
]);
if($cid==$admin or $chat_id==$admin){
$m=$menu_p;
}else{
$m=$menu;
}

// YANGI: Boshqaruv panelida ("🗄️ Boshqaruv" bosilgach ochiladigan admin
// menyusi va uning ichki bo'limlarida) botning oddiy foydalanuvchi bosh
// sahifasiga ("$menu_p") to'g'ridan-to'g'ri qaytadigan tugma yo'q edi —
// admin panelidan chiqish uchun faqat qo'lda "/start" yozish kerak edi.
// Shu sabab "🏠 Bosh sahifa" tugmasi qo'shildi.
if($text=="🏠 Bosh sahifa" and $cid==$admin){
sms($cid,"🏠 Bosh sahifa",$m);
@unlink("user/$cid.step");
exit;
}

if($text == "🛍 Buyurtmalarni sozlash" and $cid==$admin){
		bot('sendMessage',[
		'chat_id'=>$cid,
		'text'=>"<b>Quyidagilardan birini tanlang:</b>",
		'parse_mode'=>'html',
		'reply_markup'=>json_encode([
		'inline_keyboard'=>[
		[['text'=>"📂 Bo'limlarni sozlash",'callback_data'=>"bolim"]],
		[['text'=>"📂 Ichki bo'limlarni sozlash",'callback_data'=>"ichki"]],
		[['text'=>"📂 Pastki (ichki-ichki) bo'limlarni sozlash",'callback_data'=>"pastki"]],
		[['text'=>"🛍 Xizmatlarni sozlash",'callback_data'=>"xizmat"]]
]
])
]);

}

if($data == "xsetting" ){
del();
		bot('sendMessage',[
		'chat_id'=>$chat_id,
		'text'=>"<b>Quyidagilardan birini tanlang:</b>",
		'parse_mode'=>'html',
		'reply_markup'=>json_encode([
		'inline_keyboard'=>[
		[['text'=>"📂 Bo'limlarni sozlash",'callback_data'=>"bolim"]],
		[['text'=>"📂 Ichki bo'limlarni sozlash",'callback_data'=>"ichki"]],
		[['text'=>"📂 Pastki (ichki-ichki) bo'limlarni sozlash",'callback_data'=>"pastki"]],
		[['text'=>"🛍 Xizmatlarni sozlash",'callback_data'=>"xizmat"]]
]
])
]);

}

if($data == "bolim"){
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Yangi bo'lim qo'shish",'callback_data'=>"newFol"]],
[['text'=>"Tahrirlash",'callback_data'=>"editFol"]],
[['text'=>"O'chirish",'callback_data'=>"delFol"]],
[['text'=>"Orqaga", 'callback_data'=>"xsetting"]],
]
])
]);
}

if($data == "editFol"){
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Nomini o'zgartirish",'callback_data'=>"editFols"]],
]
])
]);
}


if($data == "editFols"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"editFolss-".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "editFolss-")!==false){
	$ex = explode("-",$data)[1];
	bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
   'text'=>"<b>Yangi qiymatni kiriting:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$cid2.step","editFol-$ex");

}

if((mb_stripos($step,"editFol-")!==false)){
	$ex = explode("-",$step)[1];
if(isset($text)){
$text=enc("encode",$text);
mysqli_query($connect,"UPDATE categorys SET category_name = '$text' WHERE category_id = $ex");
		bot('SendMessage',[
		'chat_id'=>$cid,
		'text'=>"<b>Muvaffaqiyatli o'zgartirildi.</b>",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
@unlink("user/$cid.step");

}
}



if($data=="delFol"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"delFols=".$s['category_id']];
}

$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bo‘limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
edit($chat_id,$message_id,"👉 O‘zingizga kerakli tarmoqni tanlang:",$kb);

}
}

if(mb_stripos($data, "delFols=")!==false){
	$ex = explode("=",$data)[1];
	$sd = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM categorys WHERE category_id  = $ex"));
	$cd=$sd['category_id'];
	$d=enc("decode",$sd['category_name']);
$qd = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM cates WHERE category_id  = $ex"));
$sa=$qd['cate_id'];
mysqli_query($connect,"DELETE FROM services WHERE category_id=$sa");
mysqli_query($connect,"DELETE FROM cates WHERE category_id = $cd");
mysqli_query($connect,"DELETE FROM categorys WHERE category_id='$ex'");
     bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
       'text'=>"Bo'lim olib tashlandi!",
'parse_mode'=>'html',
'reply_markup'=>$panel2
]);

}



if($data == "newFol"){
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
   'text'=>"<b>Yangi bo'lim nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$chat_id.step",'newFol');

}

if($step == "newFol"){
$res = mysqli_query($connect, "SELECT * FROM `categorys`");
// FIX: mysqli_query xato qaytarganda (masalan "categorys" jadvali bazada
// mavjud emas yoki schema.sql hali ishga tushirilmagan bo'lsa), $res
// "false" bo'lib qoladi. Shu holatda to'g'ridan-to'g'ri
// mysqli_fetch_assoc($res) chaqirilsa, PHP 8.1+ da FATAL XATO beradi va
// BUTUN so'rov (shu jumladan quyidagi "Bo'lim qo'shildi!" javobi ham)
// hech narsa yubormay to'xtab qoladi — aynan shu sabab admin nomni
// yuborgach bot "jim" (stop) bo'lib qolgan edi.
if($res === false){
error_log("[bot.php] newFol: 'categorys' jadvalini o'qishda SQL xatosi: ".mysqli_error($connect));
sms($cid,"⚠️ Bo'lim qo'shishda xatolik yuz berdi (baza bilan bog'liq). Iltimos 'categorys' jadvali bazangizda mavjudligini tekshiring (schema.sql ishga tushirilganini tekshiring).",$panel2);
@unlink("user/$cid.step");
}else{
$n = mysqli_fetch_assoc($res);
$text=enc("encode",$text);
$ins = mysqli_query($connect,"INSERT INTO categorys(category_name,category_status) VALUES('$text','ON');");
if($ins === false){
error_log("[bot.php] newFol: categorys jadvaliga yozishda SQL xatosi: ".mysqli_error($connect));
sms($cid,"⚠️ Bo'lim qo'shishda xatolik yuz berdi. Birozdan so'ng qayta urinib ko'ring yoki admin bilan bog'laning.",$panel2);
}else{
		bot('SendMessage',[
		'chat_id'=>$cid,
		'text'=>"Bo'lim qo'shildi!",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
}
@unlink("user/$cid.step");
}

}


if($data == "ichki"){
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Yangi ichki bo'lim qo'shish",'callback_data'=>"newFold"]],
[['text'=>"Tahrirlash",'callback_data'=>"editFold"]],
[['text'=>"O'chirish",'callback_data'=>"delFold"]],
[['text'=>"Orqaga", 'callback_data'=>"xsetting"]],
]
])
]);
}

if($data == "editFold"){
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Nomini o'zgartirish",'callback_data'=>"editFolds"]],
]
])
]);
}



if($data == "editFolds"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"editFolds-".$s['category_id']];
}

$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}

if(mb_stripos($data, "editFolds-")!==false){
$n = explode("-",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"editFoldm-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "editFoldm-")!==false){
	$ex = explode("-",$data)[1];
	bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
   'text'=>"<b>Yangi qiymatni kiriting:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$boshqarish
]);
file_put_contents("user/$cid2.step","editFoldms-$ex");

}

if(mb_stripos($step, "editFoldms-")!==false){
	$ex = explode("-",$step)[1];
	if(isset($text)){
	$text=enc("encode",$text);
		mysqli_query($connect,"UPDATE cates SET name = '$text' WHERE cate_id = $ex");
		bot('SendMessage',[
		'chat_id'=>$cid,
		'text'=>"<b>Muvaffaqiyatli o'zgartirildi.</b>",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
@unlink("user/$cid.step");

}

}





if($data == "delFold"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"delFolds=".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}

if(mb_stripos($data, "delFolds=")!==false){
$bolim = explode("=",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $bolim");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"delFolm=".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"absd"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
     'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "delFolm=")!==false){
	$ex = explode("=",$data)[1];

$qd = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM cates WHERE cate_id  = $ex"));
$sa=$qd['cate_id'];
$d = enc("decode",$qd['name']);
mysqli_query($connect,"DELETE FROM services WHERE category_id=$sa");
mysqli_query($connect,"DELETE FROM cates WHERE cate_id=$ex");
     bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
       'text'=>"Ichki bo'lim olib tashlandi!",
'parse_mode'=>'html',
'reply_markup'=>$panel2
]);

}


if($data == "newFold"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"adFol=".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}


if(mb_stripos($data, "adFol=")!==false){
	$ex = explode("=",$data)[1];
	file_put_contents("set/c.txt",$ex);
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
   'text'=>"<b>Yangi ichki bo'lim nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$chat_id.step",'newFold');

}


if($step == "newFold"){
		if(isset($text)){
$ci=get("set/c.txt");
$to=enc("encode",$text);
mysqli_query($connect,"INSERT INTO cates(`name`,`category_id`) VALUES ('$to','$ci')");
		bot('sendMessage',[
		'chat_id'=>$cid,
		'text'=>"Ichki bo'lim qo'shildi!",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
@unlink("user/$cid.step");

}
}


// ==========================================================================
// YANGI: "Pastki bo'lim" (subcates) uchun admin boshqaruvi — "cates" (Ichki
// bo'lim) uchun yuqoridagi blok bilan bir xil naqshda qurilgan, faqat bitta
// qo'shimcha bosqich bilan: bu yerda avval TARMOQ (categorys), keyin BO'LIM
// (cates), keyin esa PASTKI BO'LIM (subcates) tanlanadi/kiritiladi.
// ==========================================================================

if($data == "pastki"){
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Yangi pastki bo'lim qo'shish",'callback_data'=>"newSub"]],
[['text'=>"Tahrirlash",'callback_data'=>"editSub"]],
[['text'=>"O'chirish",'callback_data'=>"delSub"]],
[['text'=>"Orqaga", 'callback_data'=>"xsetting"]],
]
])
]);
}

if($data == "editSub"){
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Nomini o'zgartirish",'callback_data'=>"editSubs"]],
]
])
]);
}

if($data == "editSubs"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"editSubs-".$s['category_id']];
}

$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Tarmoqni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}

if(mb_stripos($data, "editSubs-")!==false){
$n = explode("-",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"editSubm-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu tarmoq uchun bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "editSubm-")!==false){
$n = explode("-",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM subcates WHERE cate_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"editSubx-".$s['subcate_id']];
}
}
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun pastki bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Pastki bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "editSubx-")!==false){
	$ex = explode("-",$data)[1];
	bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
   'text'=>"<b>Yangi qiymatni kiriting:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$boshqarish
]);
file_put_contents("user/$cid2.step","editSubxs-$ex");

}

if(mb_stripos($step, "editSubxs-")!==false){
	$ex = explode("-",$step)[1];
	if(isset($text)){
	$text=enc("encode",$text);
		mysqli_query($connect,"UPDATE subcates SET name = '$text' WHERE subcate_id = $ex");
		bot('SendMessage',[
		'chat_id'=>$cid,
		'text'=>"<b>Muvaffaqiyatli o'zgartirildi.</b>",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
@unlink("user/$cid.step");

}

}


if($data == "delSub"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"delSubs=".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Tarmoqni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}

if(mb_stripos($data, "delSubs=")!==false){
$bolim = explode("=",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $bolim");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"delSubm=".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"absd"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu tarmoq uchun bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
     'text'=>"<b>Bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "delSubm=")!==false){
$cate = explode("=",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM subcates WHERE cate_id = $cate");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"delSubx=".$s['subcate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"absd"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun pastki bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
     'text'=>"<b>Pastki bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "delSubx=")!==false){
	$ex = explode("=",$data)[1];
mysqli_query($connect,"DELETE FROM services WHERE subcate_id=$ex");
mysqli_query($connect,"DELETE FROM subcates WHERE subcate_id=$ex");
     bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
       'text'=>"Pastki bo'lim olib tashlandi!",
'parse_mode'=>'html',
'reply_markup'=>$panel2
]);

}


if($data == "newSub"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"adSub=".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Tarmoqni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}

if(mb_stripos($data, "adSub=")!==false){
	$ex = explode("=",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $ex");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"adSub2-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu tarmoq uchun bo'limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "adSub2-")!==false){
	$ex = explode("-",$data)[1];
	file_put_contents("set/c3.txt",$ex);
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
   'text'=>"<b>Yangi pastki bo'lim nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$chat_id.step",'newSub');

}


if($step == "newSub"){
		if(isset($text)){
$ci=get("set/c3.txt");
$to=enc("encode",$text);
$ins = mysqli_query($connect,"INSERT INTO subcates(`name`,`cate_id`) VALUES ('$to','$ci')");
// FIX: Avval bu yerda INSERT natijasi tekshirilmasdan, har doim "Pastki
// bo'lim qo'shildi!" deb yozilardi — hatto INSERT xato bergan taqdirda
// ham (masalan "subcates" jadvali bazada hali mavjud emas bo'lsa, ya'ni
// schema.sql ishga tushirilmagan bo'lsa). Bu admin uchun chalg'ituvchi
// edi: bo'lim "qo'shildi" deyilgan bo'lsa-da, aslida hech narsa
// saqlanmagan va keyinchalik xizmat qo'shishda bu pastki bo'lim
// hech qayerda ko'rinmagan.
if($ins === false){
error_log("[bot.php] newSub: subcates jadvaliga yozishda SQL xatosi: ".mysqli_error($connect));
sms($cid,"⚠️ Pastki bo'lim qo'shishda xatolik yuz berdi. 'subcates' jadvali bazangizda mavjudligini tekshiring (yangilangan schema.sql faylini Railway MySQL'da ishga tushirishingiz kerak bo'lishi mumkin).",$panel2);
}else{
		bot('sendMessage',[
		'chat_id'=>$cid,
		'text'=>"Pastki bo'lim qo'shildi!",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
}
@unlink("user/$cid.step");

}
}


if($data == "xizmat"){
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"Yangi xizmat qo'shish",'callback_data'=>"newXiz"]],
[['text'=>"Xizmatlarni yuklab olish",'callback_data'=>"uplXiz"]],
[['text'=>"Tahrirlash",'callback_data'=>"editXiz"]],
[['text'=>"O'chirish",'callback_data'=>"delXiz"]],
[['text'=>"Orqaga", 'callback_data'=>"xsetting"]],
]
])
]);
}

if($data == "uplXiz"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"uplad=".$s['category_id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bo‘limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}


if(mb_stripos($data, "uplad=")!==false){
$n = explode("=",$data)[1];
$upx = json_decode(get("set/upladd.json"),1);
$upx['category_id']=$n;
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT));
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"uplads-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"uplXiz"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$kb
]);
}
}

if(stripos($data,"uplads-")!==false){
$n = explode("-",$data)[1];
$upx = json_decode(get("set/upladd.json"),1);
$upx['cate_id']=$n;
unset($upx['subcate_id']);
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT));

// YANGI: bo'lim (cate) tanlangandan keyin, agar shu bo'limda pastki
// bo'lim(lar) mavjud bo'lsa, avval o'shani tanlashni so'raymiz (xuddi
// "Yangi xizmat qo'shish" oqimidagi kabi). Aks holda eski oqim davom etadi.
$new_arr = [];
$k = [];
$asub = mysqli_query($connect,"SELECT * FROM subcates WHERE cate_id = $n");
$csub = ($asub !== false) ? mysqli_num_rows($asub) : 0;
while($asub !== false && ($s = mysqli_fetch_assoc($asub))){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"upladss-".$s['subcate_id']];
}
}
if($csub){
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
bot('editMessageText',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<b>Pastki bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
exit;
}

$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$s['api_url']);
$prs.="<b>".$pr."</b>: $prtxt\n";
$k[]=['text'=>$pr,'callback_data'=>"uplprv-".$s['id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){

	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
		del();
     bot('sendMessage',[
        'chat_id'=>$chat_id,
       'text'=>"Provayderni tanlang:
 
$prs",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);

}
}

// YANGI: "Xizmatlarni yuklab olish" oqimida pastki bo'lim tanlangandan
// keyin — subcate_id'ni saqlab qolamiz, so'ng provayder ro'yxatini
// ko'rsatib davom etamiz (yuqoridagi "uplads-" bilan bir xil oynani).
if(stripos($data,"upladss-")!==false){
$n = explode("-",$data)[1];
$upx = json_decode(get("set/upladd.json"),1);
$upx['subcate_id']=$n;
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT));
$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/adapter/default/index","/api/v1","/api/v2","https://"],["","","",""],$s['api_url']);
$prs.="<b>".$pr."</b>: $prtxt\n";
$k[]=['text'=>$pr,'callback_data'=>"uplprv-".$s['id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
		del();
     bot('sendMessage',[
        'chat_id'=>$chat_id,
       'text'=>"Provayderni tanlang:
 
$prs",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);
}
}

if(stripos($data,"uplprv-")!==false){
$n = explode("-",$data)[1];
$upx = json_decode(get("set/upladd.json"),1);
$upx['provider']=$n;
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT));
edit($chat_id,$message_id,"Provayderning API valyutasini tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"UZS",'callback_data'=>"uplval-UZS-".$upx['provider']]],
[['text'=>"USD",'callback_data'=>"uplval-USD-".$upx['provider']]],
[['text'=>"RUB",'callback_data'=>"uplval-RUB-".$upx['provider']]],
[['text'=>"INR",'callback_data'=>"uplval-INR-".$upx['provider']]],
[['text'=>"TRY",'callback_data'=>"uplval-TRY-".$upx['provider']]],
]]));

}


if(stripos($data,"uplval-")!==false){
$n = explode("-",$data)[1];
$prv = explode("-",$data)[2];
$upx = json_decode(get("set/upladd.json"),1);
$upx['currency']=$n;
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT));
$h = json_decode(arr($prv));
$ko=1;
if($h->error) {
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Serverda nosozlik

Qaytadan urining",
		'show_alert'=>true,
		]);
		
		}else{
for($i=0;$i<=22;$i++){
if($h->results[$i]->name){
$arr3 []=['text'=>"".$h->results[$i]->name."",'callback_data'=>"apload=$i=$prv"];
}
}
}
$arr = array_chunk($arr3,1);
$arr[]=[['text'=>"Orqaga",'callback_data'=>"xizmat"],['text'=>"▶️ Keyingi",'callback_data'=>"nexti=next=$prv=$ko=$i"]];
$kb = json_encode([
'inline_keyboard'=>$arr,
]);

edit($chat_id,$message_id,"Kerakli xizmat turini tanlang",$kb);

}

if((stripos($data,"nexti=")!==false)){
$res=explode("=",$data)[1];
$prv=explode("=",$data)[2];
$ko=explode("=", $data)[3];
$kl=explode("=",$data)[4];
$h = json_decode(arr($prv));
$ko=$kl;
if($h->error) {
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Serverda nosozlik

Qaytadan urining",
		'show_alert'=>true,
		]);
		
		}else{
if($res=="next"){
$ma = $kl*2;
for($i=$kl;$i<=$ma;$i++){
$d = $h->results[$i]->name ? $h->results[$i]->name : "";
if($h->results[$i]->name){
$arr3 []=['text'=>$d,'callback_data'=>"apload=$i=$prv"];
}}}

$arr = array_chunk($arr3,1);

$arr[]=[['text'=>"Orqaga",'callback_data'=>"xizmat"],['text'=>"▶️ Keyingi",'callback_data'=>"nexti=next=$prv=$ko=$i"]];
$kb = json_encode([
'inline_keyboard'=>$arr,
]);
edit($chat_id,$message_id,"Kerakli xizmat turini tanlang:",$kb);
exit();
}
}

if((stripos($data,"apload=")!==false)){
$qa = explode("=", $data)[1];
$qa=$qa+1;
$prv=explode("=",$data)[2];
$h = json_decode(arr($prv),1);
if($h['error']){
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Serverda nosozlik
	
Qaytadan urining",
		'show_alert'=>true,
		]);

		}
foreach($h['results'] as $vs){
if($vs['id']==$qa){
$nq = $vs['name'] ? $nq=$vs['name'] : "";
}
}
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"$nq - uchun xizmatlar qidirilmoqda

Iltimos kuting...",
		'show_alert'=>true,
		]);
$upx = json_decode(get("set/upladd.json"),1);
$upx['category']=$nq;
file_put_contents("set/upladd.json",json_encode($upx,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$s = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `providers` WHERE id = $prv"));
$j=json_decode(smm_panel_post($s['api_url'],['key'=>$s['api_key'],'action'=>'services']),1);
$service_count = 0;
$serviceid = 0;
foreach($j as $el){
if($el['category']==$nq){

$service_count++;
$serviceid++;
$name=$el["name"];
$txe = $el['service'];
$min=$el["min"];
$max=$el["max"];
$type=$el['type'];
$service_ide=$el['service'];
$cancel=$el['cancel'] ? 'true':'false';
$dripfeed=$el['dripfeed'] ? 'true':'false';
$refill=$el['refill'] ? 'true':'false';
$k[]=['text'=>($name),'callback_data'=>"couple=".$txe];
}
}
$ko =array_chunk($k,1);
if(empty($service_count)) {
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Serverda nosozlik
	
Qaytadan urining",
		'show_alert'=>true,
		]);

}else{
$ko[]=[['text'=>"✅ Barchasini yuklab olish",'callback_data'=>"allapl=$prv"]];
}
$ko[]=[['text'=>"Orqaga",'callback_data'=>"xizmat"]];
$kb = json_encode([
'inline_keyboard'=>$ko
]);
edit($chat_id,$message_id,"
$nq

🔢 Xizmatlar soni: $service_count - ta",$kb);
}


if((stripos($data,"allapl=")!==false)){
del();
	$prv=explode("=",$data)[1];
$mas=bot('sendMessage',[
		'chat_id'=>$chat_id,
		'text'=>"📂 Yuklab olish boshlandi!..

🔔 Iltimos kuting.",
		])->result->message_id;
		
		$upx = json_decode(get("set/upladd.json"),1);
		
$s = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `providers` WHERE id = $prv"));

$j=json_decode(smm_panel_post($s['api_url'],['key'=>$s['api_key'],'action'=>'services']),1);
if(empty($j)){
edit($cid2,$mas,"⚠️ Serverda nosozlik

Qaytadan urining",null);

}else{
$service_id = mysqli_num_rows(mysqli_query($connect,"SELECT * FROM `services`"));
foreach($j as $el){
if($el['category']==$upx['category']){
$service_id++;
$name=($el["name"]);
$tas = $el['service'];
$min=$el["min"];
$max=$el["max"];
$rate=$el["rate"];
$type=$el['type'];
$cancel=$el['cancel'] ? 'true':'false';
$dripfeed=$el['dripfeed'] ? 'true':'false';
$refill=$el['refill'] ? 'true':'false';

if($upx['currency']=="USD"){
$fr=get("set/usd");
}elseif($upx['currency']=="RUB"){
$fr=get("set/rub");
}elseif($upx['currency']=="INR"){
$fr=get("set/inr");
}elseif($upx['currency']=="TRY"){
$fr=get("set/try");
}elseif($upx['currency']=="UZS"){
$fr = 1;
}

$foiz=mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM percent WHERE id = 1"))['percent'];
$rate=$rate*$fr;
$rp=$rate/100;
$rp=$rp*$foiz+$rate;


$service_price = $rp;
$category_id=$upx['cate_id'];
$api_service=$prv; 
$api_currency =$upx['currency']; 
$service_name = base64_encode(mb_convert_encoding(trans($name),"UTF-8","UTF-8"));
$service_desc=null;
$service_edit = "true";
$subcate_id = isset($upx['subcate_id']) ? intval($upx['subcate_id']) : null;
$subcate_sql = $subcate_id ? "'$subcate_id'" : "NULL";
$sq=mysqli_query($connect,"INSERT INTO 
services(`service_status`,`service_edit`,`service_price`,`category_id`,`subcate_id`,`service_api`,`api_service`,`api_currency`,`service_type`,`api_detail`,`service_name`,`service_desc`,`service_min`,`service_max`) VALUES ('on','$service_edit','$service_price','$category_id',$subcate_sql,'$tas','$api_service','$api_currency','$type','{\"name\":\"$name\",\"min\":\"$min\",\"max\":\"$max\",\"type\":\"$type\",\"cancel\":\"$cancel\",\"refill\":\"$refill\",\"dripfeed\":\"$dripfeed\"}','$service_name','$service_desc','$min','$max');");
}
}

edit($chat_id,$mas,"✅ Yuklab olish jarayoni tugallandi.",null);
@unlink("user/$cid2.step");

}
}



if($data == "editXiz"){
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"API xizmat IDsini o'zgartirish",'callback_data'=>"editXizmat-service_api"]],
[['text'=>"Xizmat nomini o'zgartirish",'callback_data'=>"editXizmat-service_name"]],
[['text'=>"Malumotlarni o'zgartirish", 'callback_data'=>"editXizmat-service_desc"]],
[['text'=>"Narxini o‘zgartirish",'callback_data'=>"editXizmat-service_price"]],
[['text'=>".Min buyurtmani o‘zgartirish",'callback_data'=>"editXizmat-service_min"]],
[['text'=>".Max buyurtmani o‘zgartirish",'callback_data'=>"editXizmat-service_max"]],
[['text'=>"Orqaga", 'callback_data'=>"xizmat"]],
]
])
]);
}

if(mb_stripos($data, "editXizmat-")!==false){
$nomi = explode("-",$data)[1];
file_put_contents("user/$cid2.txt",$nomi);
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"editXizmats-".$s['category_id']];
}

$keyboard2=array_chunk($k,3);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"editXiz"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Tarmoqlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}


if(mb_stripos($data, "editXizmats-")!==false){
$bolim = explode("-",$data)[1];
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $bolim");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"editXt-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"editXizmat-$bolim"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}


if(mb_stripos($data, "editXt-")!==false){
$n=explode("-",$data)[1];
$as=1;
$a = mysqli_query($connect,"SELECT * FROM services WHERE category_id = '$n'");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$txts.="<b>".$as."</b>: ".base64_decode($s['service_name'])."\n";
$k[]=['text'=>$as++,'callback_data'=>"editXts-".$s['service_id']];
}
$keyboard2=array_chunk($k,3);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"editXizmats-$n"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>" ⚠️ Ushbu bo'lim uchun xizmatlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$cid2,
       'message_id'=>$mid2,
    'text'=>"<b>Quyidagilardan birini tanlang:\n\n$txts</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "editXts-")!==false){
	$xiz = explode("-",$data)[1];
	bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
   'text'=>"<b>Yangi qiymatni kiriting:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$boshqarish
]);
file_put_contents("user/$cid2.step","editXizmatlar-$xiz");

}

if(mb_stripos($step, "editXizmatlar-")!==false){
	$xiz = explode("-",$step)[1];
	$ex = file_get_contents("user/$cid.txt");
	if($cid == $admin and isset($text)){
		if($ex=="service_desc"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = base64_encode($text);
		mysqli_query($connect,"UPDATE services SET service_desc='$vo' WHERE service_id = $xiz");
		}elseif($ex=="service_name"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = base64_encode($text);
		mysqli_query($connect,"UPDATE services SET service_name='$vo' WHERE service_id = $xiz");
		}elseif($ex=="service_id"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = $text;
		mysqli_query($connect,"UPDATE services SET service_api='$vo' WHERE service_id = $xiz");
		}elseif($ex=="service_price"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = $text;
		mysqli_query($connect,"UPDATE services SET service_edit='false', service_price='$vo' WHERE service_id = $xiz");
		}elseif($ex=="service_min"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = $text;
		mysqli_query($connect,"UPDATE services SET service_edit='false', service_min='$vo' WHERE service_id = $xiz");
		}elseif($ex=="service_max"){
		$ex = file_get_contents("user/$cid.txt");
		$vo = $text;
		mysqli_query($connect,"UPDATE services SET service_edit='false', service_max='$vo' WHERE service_id = $xiz");
		}
		bot('SendMessage',[
		'chat_id'=>$cid,
		'text'=>"<b> Muvaffaqiyatli o'zgartirildi.</b>",
		'parse_mode'=>'html',
		'reply_markup'=>$panel2
]);
@unlink("user/$cid.step");
@unlink("user/$cid.txt");

}
}




if($data == "delXiz"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"deleteXiz-".$s['category_id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bo‘limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);

}
}

if(mb_stripos($data, "deleteXiz-")!==false){
	$n = explode("-",$data)[1];
   file_put_contents("set/c.txt",$ex);
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"delx-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"newXiz"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "delx-")!==false){
	$n=explode("-",$data)[1];
$as=0;
$a = mysqli_query($connect,"SELECT * FROM services WHERE category_id = '$n'");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$as++;
$narx = fmt_price($s['service_price']);
$txts.="<b>".$as."</b>: ".base64_decode($s['service_name'])." $narx - so‘m\n";

$k[]=['text'=>$as,'callback_data'=>"delmat-".$s['service_id']];
}
$keyboard2=array_chunk($k,5);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmatlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
edit($chat_id,$message_id,"
💎 Xizmatlardan birini tanlang! 
💴 Narxlar 1000 tasi uchun berilgan

$txts",$kb);

}
}

if(mb_stripos($data, "delmat-")!==false){
$ichki = explode("-",$data)[1];
mysqli_query($connect,"DELETE FROM services WHERE service_id = $ichki");
     bot('deleteMessage',[
	'chat_id'=>$cid2,
	'message_id'=>$mid2,
]);
   bot('sendMessage',[
   'chat_id'=>$cid2,
       'text'=>"Xizmat o‘chirildi!",
'parse_mode'=>'html',
'reply_markup'=>$panel
]);

}







if($data == "newXiz"){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"add=".$s['category_id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bo‘limlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
     bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
       'text'=>"<b>Quyidagilardan birini tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
}
}


if(mb_stripos($data, "add=")!==false){
$n = explode("=",$data)[1];
file_put_contents("set/c.txt",$n);
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"adds-".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"Orqaga",'callback_data'=>"newXiz"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
bot('editMessageText',[
        'chat_id'=>$chat_id,
       'message_id'=>$message_id,
'text'=>"<b>Quyidagilardan birini tanlang:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$kb
]);
}
}

if(mb_stripos($data, "adds-")!==false){
$pw=explode("-",$data)[1];
$adds=json_decode(get("set/adds.json"),1);
$adds['cate_id']=$pw;
$adds['category_id']=file_get_contents("set/c.txt");
put("set/adds.json",json_encode($adds,JSON_UNESCAPED_UNICODE));

// YANGI: Endi bo'lim (cate) tanlangandan keyin, agar shu bo'limda pastki
// bo'lim(lar) (subcates) mavjud bo'lsa, avval o'shani tanlash so'raladi.
// Agar hali pastki bo'lim qo'shilmagan bo'lsa (eski uslub), to'g'ridan-
// to'g'ri eski oqimga (provayder tanlash) o'tiladi.
$new_arr = [];
$k = [];
$asub = mysqli_query($connect,"SELECT * FROM subcates WHERE cate_id = $pw");
$csub = ($asub !== false) ? mysqli_num_rows($asub) : 0;
while($asub !== false && ($s = mysqli_fetch_assoc($asub))){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>enc("decode",$s['name']),'callback_data'=>"addss-".$s['subcate_id']];
}
}
if($csub){
$keyboard2=array_chunk($k,1);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
bot('editMessageText',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<b>Pastki bo'limni tanlang:</b>",
'parse_mode'=>'html',
'reply_markup'=>$kb
]);
exit;
}

$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
if(!$c){
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
   'text'=>"<b>Yangi xizmat nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$chat_id.step",'servisw');

}
}

// YANGI: pastki bo'lim tanlangandan keyin - eski oqim (provayder tanlash)
// davom etadi, faqat endi $adds['subcate_id'] ham saqlanadi.
if(mb_stripos($data, "addss-")!==false){
$pw2=explode("-",$data)[1];
$adds=json_decode(get("set/adds.json"),1);
$adds['subcate_id']=$pw2;
put("set/adds.json",json_encode($adds,JSON_UNESCAPED_UNICODE));
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
if(!$c){
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
	bot('deleteMessage',[
	'chat_id'=>$chat_id,
	'message_id'=>$message_id,
]);
   bot('sendMessage',[
   'chat_id'=>$chat_id,
   'text'=>"<b>Yangi xizmat nomini yuboring:</b>",
   'parse_mode'=>'html',
   'reply_markup'=>$aort
]);
file_put_contents("user/$chat_id.step",'servisw');

}
}

if($step == "servisw"){
$pr=0;
$prs="";
$a = mysqli_query($connect,"SELECT * FROM providers");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$pr++;
$prtxt=str_replace(["/api/v1","/api/v2","https://"],["","",""],$s['api_url']);
$prs.="<b>".$pr."</b>: $prtxt\n";
$k[]=['text'=>$pr,'callback_data'=>"checkC-".$s['id']];
}
$keyboard2=array_chunk($k,3);
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('sendMessage',[
		'chat_id'=>$cid,
		'text'=>"⚠️ Provayderlar topilmadi!",
		]);
	}else{
     bot('sendMessage',[
        'chat_id'=>$cid,
       'text'=>"Provayderni tanlang:
 
$prs",
'parse_mode'=>"HTML",
'reply_markup'=>$kb,
]);

put("set/adds.json.name",$text);
file_put_contents("user/$cid.step","servis0");

}
}

if((stripos($data,"checkC-")!==false and $stepc=="servis0" and $chat_id==$admin)){
$pw=explode("-",$data)[1];
sms($chat_id,"Provayderning API xizmatlari bolimida korsatilgan valyutani tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"UZS ",'callback_data'=>"checkP-UZS"]],
[['text'=>"USD ",'callback_data'=>"checkP-USD"]],
[['text'=>"RUB ",'callback_data'=>"checkP-RUB"]],
[['text'=>"INR ",'callback_data'=>"checkP-INR"]],
[['text'=>"TRY ",'callback_data'=>"checkP-TRY"]],
]]));
$adds=json_decode(get("set/adds.json"),1);
$adds['api_service']=$pw;
put("set/adds.json",json_encode($adds,JSON_UNESCAPED_UNICODE));
file_put_contents("user/$chat_id.step",'servis1');
}

if((stripos($data,"checkP-")!==false and  $stepc=="servis1" and $chat_id==$admin)){
$pw=explode("-",$data)[1];
if(isset($data)){
del();
sms($chat_id,"📝 Xizmat xaqida malumotlar kiriting:

⚠️ Ma'lumot kiritish ni xoxlamasangiz <b>Kiritilmagan</b> tugmasini bosing",json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"Kiritilmagan"]],
[['text'=>"🗄️ Boshqaruv"]],
]]));
$adds=json_decode(get("set/adds.json"),1);
$adds['api_currency']=$pw;
put("set/adds.json",json_encode($adds,JSON_UNESCAPED_UNICODE));
file_put_contents("user/$chat_id.step",'servis2');
}
}
if(($step=="servis2" and $cid==$admin)){
if(isset($text)){
sms($cid,"💵 Buyurtma narxini yuboring (1000 ta) uchun",$aort);
if($text=="Kiritilmagan"){
put("set/adds.json.desc","");
}else{
put("set/adds.json.desc",$text);
}
file_put_contents("user/$cid.step",'servis3');
}

}


if(($step=="servis3" and $cid==$admin)){
if(is_numeric($text)){
sms($cid,"🆔 Xizmat IDsini yuboring:",$aort);
$adds=json_decode(get("set/adds.json"),1);
$adds['service_price']=$text;
put("set/adds.json",json_encode($adds,JSON_UNESCAPED_UNICODE));
file_put_contents("user/$cid.step",'servisID');
}

}


if($step=="servisID"){
if(is_numeric($text)){
$pw = json_decode(get("set/adds.json"));
$cure = $pw->api_service;
$ap = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers WHERE id = $cure"));
$surl=$ap['api_url'];
$skey=$ap['api_key'];
$j=json_decode(smm_panel_post($surl,['key'=>$skey,'action'=>'services']), true);
foreach($j as $el){
if($el['service']=="$text"){
$name=$el["name"];
$min=$el["min"];
$max=$el["max"];
$rate=$el["rate"];
$rate=$el["rate"];
$type=$el['type'];
$tas = $el['service'];
$cancel=$el['cancel'] ? 'true':'false';
$dripfeed=$el['dripfeed'] ? 'true':'false';
$refill=$el['refill'] ? 'true':'false';
break;
}
}


if(empty($min) and empty($max)){
sms($cid,"
🔕 Noma'lum xatolik yuz berdi.

Qaytadan xizmat IDsini yuboring:",null);
}else{
$category_id=$pw->cate_id;
// YANGI: agar admin oldingi bosqichda pastki bo'lim (subcate) tanlagan
// bo'lsa, $pw->subcate_id shu yerda mavjud bo'ladi; aks holda (eski
// bo'limlar uchun) NULL saqlanadi.
$subcate_id = isset($pw->subcate_id) ? intval($pw->subcate_id) : null;
$subcate_sql = $subcate_id ? "'$subcate_id'" : "NULL";
$service_price = $pw->service_price;
$api_service=$pw->api_service; 
$api_currency =$pw->api_currency; 
$service_name = base64_encode(mb_convert_encoding(get("set/adds.json.name"),"UTF-8","UTF-8"));
$service_desc = base64_encode(get("set/adds.json.desc"));
$service_edit = "true";
mysqli_query($connect,"INSERT INTO services(`service_status`,`service_price`,`service_edit`,`category_id`,`subcate_id`,`service_api`,`api_service`,`api_currency`,`service_type`,`api_detail`,`service_name`,`service_desc`,`service_min`,`service_max`) VALUES ('on','$service_price','$service_edit','$category_id',$subcate_sql,'$text','$api_service','$api_currency','$type','{\"name\":\"$name\",\"min\":\"$min\",\"max\":\"$max\",\"type\":\"$type\",\"cancel\":\"$cancel\",\"refill\":\"$refill\",\"dripfeed\":\"$dripfeed\"}','$service_name','$service_desc','$min','$max');");

sms($cid,"✅ Yangi xizmat qo'shildi.",$panel2);
}
}

}




if($text=="💳 Pul kiritish" and joinchat($cid)==1){
$ops=get("set/payments.txt");
$s=explode("\n",$ops);
$soni = substr_count($ops,"\n");
for($i=1;$i<=$soni;$i++){
$k[]=['text'=>$s[$i],'callback_data'=>"payBot=".$s[$i]];
}
$keyboard2=array_chunk($k,2);
$keyboard2[]=[['text'=>"☎️ Admin yordamida",'url'=>"tg://user?id=$admin"]];

$keyboard2[]=[['text'=>"💳 PAYME",'callback_data'=>"menu=PAYME"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
sms($cid,"🔔 O'zingizga Qulay to'lov tizimini tanlang:",$kb);

}

if($text=="⚙️ Asosiy sozlamalar" and $cid==$admin){
sms($cid,$text,$panel2);

}

if($text=="💵 Kursni o‘rnatish" and $cid==$admin){
sms($cid,"👉 Kerakli valyutasi tanlang:",json_encode([
'inline_keyboard'=>[
[['text'=>"AQSH dollari ($)",'callback_data'=>"course=usd"]],
[['text'=>"Rossiya rubli (₽)",'callback_data'=>"course=rub"]],
[['text'=>"Hindston rupiysi (₹)",'callback_data'=>"course=inr"]],
[['text'=>"Turkiya lirasi (₺)",'callback_data'=>"course=try"]],
]]));

}

if((stripos($data,"course=")!==false)){
$val=explode("=",$data)[1];
if(get("set/".$val."")){
$VAL=get("set/".$val);
}else{
$VAL=0;
}
del();
sms($chat_id,"
1 - ".strtoupper($val)." narxini kiriting:

♻️ Joriy narx: ".$VAL." so‘m",$aort);
put("user/$chat_id.step","course=$val");
}

if((mb_stripos($step,"course=")!==false and is_numeric($text))){
$val=explode("=",$step)[1];
put("set/".$val,"$text");
sms($cid,"
✅ 1 - ".strtoupper($val)." narxi $text so‘mga o‘zgardi",$panel);
@unlink("user/$cid.step");

}

#--------------------------------------------------------------------------------------------


if($text=="🗣 Referal" || $text=="/ref"){
// FIX: Bu yerda 'SendPhoto' ishlatilib, 'photo' parametriga
// "https://t.me/akobir_bio/13" (ya'ni bir kanal postining ODDIY HAVOLASI,
// haqiqiy rasm fayli EMAS) berilgan edi. Telegram API 'photo' parametrida
// faqat file_id, to'g'ridan-to'g'ri rasm fayliga URL yoki yuklangan faylni
// qabul qiladi — t.me/... post havolasi rasm sifatida ISH BERMAYDI va
// Telegram "Bad Request" xatosi bilan rad etadi. bot() funksiyasi bu
// xatoni faqat logga yozadi (error_log) va HECH NARSA yubormaydi — shu
// sabab "Referal" tugmasi bosilganda foydalanuvchiga JAVOB umuman
// kelmasdi. Rasm shart emasligi uchun oddiy sendMessage'ga o'tkazildi —
// bu variant har doim yetkaziladi.
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b>🧲 Sizning referal havolangiz:

<i>https://t.me/$bot?start=user".$rew['user_id']."</i>

Sizga har bir taklif qilgan referalingiz uchun ".enc("decode",$setting['referal'])." so'm beriladi 
(Ko'proq Do'stlaringizni Taklif Qilib Pul Ishlang)

👤ID raqam:<code> ".$rew['user_id']." </code></b>", 
'parse_mode'=>"html",
'disable_web_page_preview'=>1,
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[["text"=>"♻️ Ulashish (Oddiy) ","switch_inline_query"=>"🎯 Telegram va Instagram uchun arzon va sifatli SMM xizmatlari kerakmi?
 
Kanal/Guruh uchun obunachilar 👤
Postlaringiz uchun ko'rishlar 👀
Postlaringiz uchun reaksiyalar 🔥
Postlaringizga yoqtirishlar ❤️

🎁 BEPUL xizmatlar 🎁

👇 Boshlash uchun bosing:

https://t.me/$bot?start=user".$rew['user_id'].""]],
//[['text'=>"🏆 TOP Foydalanuvchilar",'callback_data'=>"konkurs"]],
[['text'=>"🔙 Orqaga",'callback_data'=>"bekormenu"]],
]])
]);
}





#--------------------------------------------------------------------------------------------

if($text == "56uggyftfyfygubu6f45" and joinchat($cid)==1) {
$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid");
$row = mysqli_fetch_assoc($result);
$myid = $row['user_id'];
sms($cid,"
Sizning referal havolangiz:

https://t.me/$bot?start=user$myid

Sizga har bir taklif qilgan referalingiz uchun ".enc("decode",$setting['referal'])." so'm beriladi.

👤ID raqam: $myid",json_encode([
'inline_keyboard'=>[
//[['text'=>"🗣 Referal konkurs",'callback_data'=>"konkurs"]],
]]));
}
if($data == "konkurs" and joinchat($chat_id)==1){
edit($cid2,$mid2,referal(10),null);
}

if($text=="⚖️ Foizni o‘rnatish" and $cid==$admin){
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM percent WHERE id = 1"))['percent'];
$m ? $m : 0;
sms($cid,"
⭐ Bot xizmatlari uchun foizni kiriting

♻️ Joriy foiz: $m%",$aort);
put("user/$cid.step","updFoiz");

}

if($step=="updFoiz"){
if(is_numeric($text)){
mysqli_query($connect,"UPDATE percent SET percent = '$text' WHERE id = 1");
sms($cid,"✅ O‘zgartirish muvaffaqiyatli bajarildi.",$panel);
}
put("user/$cid.step","");

}

$saved = is_file("user/us.id") ? @file_get_contents("user/us.id") : '';

if($text == "👤 Foydalanuvchini boshqarish"){
if($cid == $admin){
	bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"<b>Kerakli foydalanuvchining ID raqamini kiriting:</b>",
	'parse_mode'=>'html',
	'reply_markup'=>$aort,
	]);
file_put_contents("user/$cid.step",'iD');
}

}

if($step == "iD"){
if($cid == $admin){
// FIX: avval "WHERE user_id = $text" edi. `user_id` ustuni ICHKI tartib
// raqami (1,2,3...), admin esa bu yerga odatda HAQIQIY Telegram ID'ni
// (masalan 5205037267) kiritadi - bu qiymat `id` ustunida saqlanadi. Shu
// sabab admin haqiqiy foydalanuvchi ID'sini kiritsa ham "topilmadi"
// chiqardi. Endi to'g'ri `id` ustunidan qidiriladi.
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $text"));
if($rew){
$idi = $rew['id'];
file_put_contents("user/us.id",$idi);
$pul = $rew['balance'];
$ban = $rew['status'];
if($ban == "active"){
	$bans = "🔔 Banlash";
}
if($ban == "deactive"){
	$bans = "🔕 Bandan olish";
}

// FIX: kod avval "Qidirilmoqda..." xabarini yuborib, keyin uni
// TAXMIN qilingan message_id ($mid + 1, ya'ni "adminning oxirgi xabari + 1")
// bilan tahrirlashga urinardi. Bu taxmin har doim to'g'ri kelavermaydi
// (masalan orada boshqa xabar yuborilgan bo'lsa) - shunday holatda
// editMessageText Telegram'dan xato oladi, u esa jim yutib yuboriladi va
// "Qidirilmoqda..." xabari ABADIY shu holatda qotib qoladi (natija hech
// qachon ko'rsatilmaydi). Endi haqiqiy message_id yuborilgan xabarning
// javobidan olinadi - bu har doim to'g'ri ishlaydi.
$qm = bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Qidirilmoqda...</b>",
'parse_mode'=>'html',
]);
$qmid = $qm->result->message_id ?? ($mid + 1);
bot('editMessageText',[
      'chat_id'=>$cid,
     'message_id'=>$qmid,
'text'=>"<b>Foydalanuvchi topildi!

ID:</b> <a href='tg://user?id=$idi'>$text</a>
<b>Balans: $pul so‘m</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
	'inline_keyboard'=>[
[['text'=>"$bans",'callback_data'=>"ban"]],
[['text'=>"➕ Pul qo'shish",'callback_data'=>"plus"],['text'=>"➖ Pul ayirish",'callback_data'=>"minus"]],
]
])
]);
@unlink("user/$cid.step");
}else{
bot('SendMessage',[
	'chat_id'=>$cid,
	'text'=>"<b>Foydalanuvchi topilmadi.</b>

Qayta urinib ko'ring:",
'parse_mode'=>'html',
]);
}
}

}

if($data == "plus"){
bot('sendMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<a href='tg://user?id=$saved'>$saved</a> <b>ning hisobiga qancha pul qo'shmoqchisiz?</b>",
'parse_mode'=>"html",
	'reply_markup'=>$aort,
]);
file_put_contents("user/$chat_id.step",'plus');

}

if($step == "plus"){
if($cid == $admin){
if(is_numeric($text)=="true"){
// FIX: avval "qo'shildi" xabari yuborilib, KEYIN UPDATE bajarilardi.
// mysqli_report OFF bo'lgani uchun UPDATE xato bersa ham (masalan
// $saved bo'sh/noto'g'ri, ulanish uzilgan va h.k.) bu HECH QANDAY
// xatolik chiqarmay jim o'tib ketardi - natijada admin va
// foydalanuvchiga "pul qo'shildi" xabari ketardi, lekin balans
// bazada aslida yangilanmagan bo'lardi. Endi avval UPDATE bajariladi,
// natija ($miqdor bazada haqiqatan ham saqlanganmi) tekshiriladi,
// va faqat shundan keyin muvaffaqiyat xabari yuboriladi.
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $saved"));
if(!$rew){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>❌ Xatolik: foydalanuvchi (ID: $saved) bazada topilmadi. Balans yangilanmadi.</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
error_log("[bot.php][plus] Foydalanuvchi topilmadi: saved=$saved");
@unlink("user/$cid.step");
}else{
$miqdor = $text+$rew['balance'];
$p2 =$text+$rew['outing'];
mysqli_query($connect,"UPDATE users SET balance=$miqdor, outing=$p2 WHERE id =$saved");
$err = mysqli_error($connect);
$tekshir = mysqli_fetch_assoc(mysqli_query($connect,"SELECT balance FROM users WHERE id = $saved"));
if($err || !$tekshir || (float)$tekshir['balance'] != (float)$miqdor){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>❌ Xatolik: balansni yangilab bo'lmadi.</b>\n<code>".htmlspecialchars((string)$err)."</code>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
error_log("[bot.php][plus] UPDATE muvaffaqiyatsiz. saved=$saved miqdor=$miqdor mysqli_error=".$err);
}else{
bot('sendMessage',[
'chat_id'=>$saved,
'text'=>"<b>Adminlar tomonidan hisobingiz $text so‘m to'ldirildi</b>",
'parse_mode'=>"html",
'reply_markup'=>$menu,
]);
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Foydalanuvchi hisobiga $text so‘m qo'shildi!</b>",
'parse_mode'=>"html",
'reply_markup'=>$panel,
]);
}
@unlink("user/$cid.step");
}
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
'protect_content'=>true,
]);

}
}

}

if($data == "minus"){
bot('sendMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<a href='tg://user?id=$saved'>$saved</a> <b>ning hisobidan qancha pul ayirmoqchisiz?</b>",
'parse_mode'=>"html",
	'reply_markup'=>$aort,
]);
file_put_contents("user/$chat_id.step",'minus');

}

if($step == "minus"){
if($cid == $admin){
if(is_numeric($text)=="true"){
// FIX: "plus" bo'limidagi bilan bir xil sabab - avval UPDATE bajariladi,
// natija tekshiriladi, faqat shundan keyin "olindi" xabari yuboriladi.
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $saved"));
if(!$rew){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>❌ Xatolik: foydalanuvchi (ID: $saved) bazada topilmadi. Balans yangilanmadi.</b>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
error_log("[bot.php][minus] Foydalanuvchi topilmadi: saved=$saved");
@unlink("user/$cid.step");
}else{
$miqdor =$rew['balance'] - $text;
$p2 =$rew['outing'] - $text;
mysqli_query($connect,"UPDATE users SET balance=$miqdor, outing=$p2 WHERE id =$saved");
$err = mysqli_error($connect);
$tekshir = mysqli_fetch_assoc(mysqli_query($connect,"SELECT balance FROM users WHERE id = $saved"));
if($err || !$tekshir || (float)$tekshir['balance'] != (float)$miqdor){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>❌ Xatolik: balansni yangilab bo'lmadi.</b>\n<code>".htmlspecialchars((string)$err)."</code>",
'parse_mode'=>'html',
'reply_markup'=>$panel,
]);
error_log("[bot.php][minus] UPDATE muvaffaqiyatsiz. saved=$saved miqdor=$miqdor mysqli_error=".$err);
}else{
bot('sendMessage',[
'chat_id'=>$saved,
'text'=>"<b>Adminlar tomonidan hisobingizdan $text so‘m olindi.</b>",
'parse_mode'=>"html",
'reply_markup'=>$menu,
]);
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Foydalanuvchi hisobidan $text so‘m olindi!</b>",
'parse_mode'=>"html",
'reply_markup'=>$panel,
]);
}
@unlink("user/$cid.step");
}
}else{
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"<b>Faqat raqamlardan foydalaning!</b>",
'parse_mode'=>'html',
'protect_content'=>true,
]);
}
}

}

if($data=="ban"){
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $saved"));
if($admin!=$saved){
if($rew['status'] == "deactive"){
mysqli_query($connect,"UPDATE users SET status='active' WHERE id =$saved");
bot('sendMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<b>Foydalanuvchi ($saved) bandan olindi!</b>",
'parse_mode'=>"html",
	'reply_markup'=>$panel,
]);
}else{
mysqli_query($connect,"UPDATE users SET status='deactive' WHERE id =$saved");
bot('sendMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
'text'=>"<b>Foydalanuvchi ($saved) banlandi!</b>",
'parse_mode'=>"html",
	'reply_markup'=>$panel,
]);
}
}else{
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"Bloklash mumkin emas!",
'show_alert'=>true,
]);
}

}


if($data=="result" and joinchat($chat_id)==1){
if(joinchat($chat_id)==1){
	$usid = get("user/$chat_id.id");
$pul = mysqli_fetch_assoc(mysqli_query($connect,"SELECT*FROM users WHERE id=$usid"))['balance'];
$a = $pul+enc("decode",$setting['referal']);
mysqli_query($connect,"UPDATE users SET balance = $a WHERE id = $usid");
$text = "
<a href='tg://user?id=$chat_id'>✅ Foydalanuvchi</a> <b> botimizdan foydalanib boshladi!</b>

Hisobingizga ".enc("decode",$setting['referal'])." so‘m qo'shildi!";
sms($usid,"$text",$m);
$p = get("user/$usid.users");
put("user/$usid.users",$p+1);
@unlink("user/$chat_id.id");
}
del();
sms($chat_id,"🖥️ Asosiy menyudasiz",$m);

}



if($text=="📊Buyurtmalarim" || $text == "/order" and joinchat($cid)==1) {
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM myorder WHERE user_id = $cid"));
if(!$rew){
$keys = json_encode([
'inline_keyboard'=>[
[['text'=>"🔎 Izlab topish",'callback_data'=>"bytopish"]],
]]);
sms($cid,"❗️Sizda faol buyurtmalar yoʻq.",$keys,null);

}else{
$rew = mysqli_query($connect,"SELECT * FROM myorder WHERE user_id = $cid");
while($my=mysqli_fetch_assoc($rew)){
$title = $my['order_id'];
$pulll = $my['retail'];
$k[]=["text"=>"$title","callback_data"=>"idby-$title"];

}
$keysboard2 = array_chunk($k,4);
$keysboard2[]=[['text'=>"🔎 Buyurtma ma'lumoti","callback_data"=>"bytopish"]];
$key = json_encode([
'inline_keyboard'=>$keysboard2,
]);
sms($cid,"🛍️ Barcha buyurtmalaringiz!",$key);

}
}


if(mb_stripos($data, "idby-")!==false){
$ex = explode("-",$data);
$text = $ex[1];
$info = mysqli_query($connect,"SELECT * FROM myorder WHERE user_id = $cid2");
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM orders WHERE order_id = $text"));
$ori =$rew['api_order'];
$prov =$rew['provider'];
$ap = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers WHERE id = $prov"));
$ourl=$ap['api_url'];
$okey=$ap['api_key'];
$s=json_decode(smm_panel_post($ourl,['key'=>$okey,'action'=>'status','order'=>$ori]),1);
$err=$s['error'];
$son=$s['remains'];
$response=$rew['status'];
// FIX: $my hech qayerda aniqlanmagan edi (Undefined variable) - shu joyda
// buyurtma yaratilgan sanasini ko'rsatishi kerak, DBdagi $rew['order_create']
// ustunidan olinadi (pastdagi "step==orders" bloki bilan bir xilda).
$vaqt = $rew['order_create'];
if($response=="Completed") {
   $status="✅ Bajarilgan";
   }
   if($response=="In progress") {
   $status="♻️ Jarayonda";
   }
   if($response=="Partial"){
   $status="⭕ Qisman bajarilgan";
   }
   if($response=="Pending"){
  $status="⏰ Kutilmoqda";
  }
  if($response=="Processing"){
  $status="🔁 Qayta ishlanmoqda";
  }
  if($response=="Canceled"){
  $status="❌ Bekor qilingan";
  }
// FIX: avval "!$rew or $err" edi - bu DBda buyurtma haqiqatan TOPILMAGAN
// holat bilan provayderning "holat" so'roviga XATO/JAVOBSIZ qaytgan holatni
// (masalan yana o'sha timeout muammosi) bir xil "❌ Buyurtma topilmadi!"
// deb ko'rsatardi - garchi buyurtma DBda bor, shunchaki hozir uning jonli
// qoldiq-miqdorini so'rab bo'lmagan bo'lsa ham. Endi ikkalasi ajratilgan:
if(!$rew){
sms($cid,"❌ Buyurtma topilmadi!",$m);
@unlink("user/$cid.step");
}elseif($err){
sms($cid2,"
<b>✅ Buyurtma topildi!</b>

<b>📯 Buyurtma holati:</b> $status
<b>⚠️ Qoldiq miqdorini hozir tekshirib bo'lmadi</b> (provayder javob bermadi, keyinroq qayta urinib ko'ring)
<b>$vaqt</b>",$ss);
@unlink("user/$cid2.step");
}else{
del();
sms($cid2,"
<b>✅ Buyurtma topildi!</b>

<b>📯 Buyurtma holati:</b> $status
<b>🔎 Qoldiq miqdori:</b> $son ta
<b>$vaqt</b>",$ss);
@unlink("user/$cid2.step");
}
}


if($data=="bytopish" and joinchat($cid2)=="true"){
bot('deleteMessage',[ 
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>🆔 Buyurtma IDsini yuboring:</b>",
'parse_mode'=>"html",
'reply_markup'=>$ort,
]);
put("user/$cid2.step",orders);
} 

if($step=="orders" and is_numeric($text) and joinchat($cid)==1) {
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM orders WHERE order_id = $text"));
$ori =$rew['api_order'];
$prov =$rew['provider'];
$ap = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers WHERE id = $prov"));
$ourl=$ap['api_url'];
$okey=$ap['api_key'];
$s=json_decode(smm_panel_post($ourl,['key'=>$okey,'action'=>'status','order'=>$ori]),1);
$err=$s['error'];
$son=$s['remains'];
$sev_id = $a['sev_id'];
$response=$rew['status'];
$vaqt = $rew['order_create'];
if($response=="Completed") {
   $status="✅ Bajarilgan";
   }
   if($response=="In progress") {
   $status="♻️ Jarayonda";
   }
   if($response=="Partial"){
   $status="⭕ Qisman bajarilgan";
   }
   if($response=="Pending"){
  $status="⏰ Kutilmoqda";
  }
  if($response=="Processing"){
  $status="🔁 Qayta ishlanmoqda";
  }
  if($response=="Canceled"){
  $status="❌ Bekor qilingan";
  }
// FIX: xuddi yuqoridagi "idby-" blokidagi kabi - buyurtma DBda topilmagan
// holat bilan provayderning "status" so'roviga xato/javobsiz qaytishi
// (masalan timeout) ajratildi.
if(!$rew){
sms($cid,"❌ Buyurtma topilmadi!",$m);
@unlink("user/$cid.step");
}elseif($err){
sms($cid,"
<b>✅ Buyurtma topildi!</b>

<b>📯 Buyurtma holati:</b> $status
<b>⚠️ Qoldiq miqdorini hozir tekshirib bo'lmadi</b> (provayder javob bermadi, keyinroq qayta urinib ko'ring)
<b>$vaqt</b>",$m);
@unlink("user/$cid.step");
}else{
sms($cid,"
<b>✅ Buyurtma topildi!</b>

<b>📯 Buyurtma holati:</b> $status
<b>🔎 Qoldiq miqdori:</b> $son ta
<b>$vaqt</b>",$m);
@unlink("user/$cid.step");
}

}



if($data == "bekormenu"){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('SendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>🏠 Bosh sahifa</b>",
'parse_mode'=>'html',
'reply_markup'=>$m,
]);
}



if($text=="/start" and joinchat($cid)==1){
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $cid"));
$start =str_replace(["{name}","{balance}","{time}"],["$name","".$rew['balance']."","$time"],enc("decode",$setting['start']));
sms($cid,$start,$m);

}

if($text=="➡️ Orqaga" and joinchat($cid)==1){
sms($cid,"🖥️ Asosiy menyudasiz",$m);
@unlink("user/$cid.step");
exit();
}


if($text=="🇺🇿 Valyuta kursi" and $cid==$admin){
$json3=json_decode(file_get_contents("https://cbu.uz/uz/arkhiv-kursov-valyut/json/"),1);
foreach($json3 as $json4){
if($json4['Ccy']=="USD"){
$usd=$json4['Rate'];
break;
}
}
foreach($json3 as $json4){
if($json4['Ccy']=="RUB"){
$rub=$json4['Rate'];
break;
}
}
foreach($json3 as $json4){
if($json4['Ccy']=="INR"){
$inr=$json4['Rate'];
break;
}
}
foreach($json3 as $json4){
if($json4['Ccy']=="TRY"){
$try=$json4['Rate'];
break;
}
}

sms($cid,"<b> 
1 $(USD) - $usd UZS
1 ₽(RUB) - $rub UZS
1 ₹(INR) - $inr UZS
1 ₺(TRY) - $try UZS
</b>",$panel);

}


if($text=="❓ Yordam" and joinchat($cid)==1) {
sms($cid,"
⭐ Bizga savollaringiz bormi?

📑 Murojaat matnini yozib yuboring.
",$ort);
put("user/$cid.step","murojaat");

}

if($step=="murojaat"){
sms($cid,"✅ Murojaatingiz qabul qilindi",$m);
bot('copyMessage',[
'chat_id'=>$admin,
'from_chat_id'=>$cid,
'message_id'=>$mid,
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"👁️ Ko‘rish",'url'=>"tg://user?id=$cid"]],
[['text'=>"📑 Javob yozish",'callback_data'=>"javob=$cid"]],
]
]),
]);
put("user/$cid.step","");

}
/*
if($text == "/otkazchi") {
	sms($cid,"Boshlandi",null);
$us = get("users.txt");
$a = explode("\n",$us);
$co = substr_count($us,"\n");
for($i = 1;$i<=$co;$i++){
adduser($a[$i]);
}
sms($cid,"Tugadi",null);
}*/

if((stripos($data,"javob=")!==false)){
$ida = explode("=", $data)[1];
sms($admin,"$ida Foydalanuvchiga yuboriladigan xabaringizni kiriting.",$ort);
put("user/$cid2.step","ticket=$ida");

}
if((mb_stripos($step,"ticket=")!==false) and ($cid==$admin)){
$ida = explode("=",$step)[1];
$if = bot('copyMessage',[
'chat_id'=>$ida,
'from_chat_id'=>$admin,
'message_id'=>$mid,
]);

if($if->ok == 1){
sms($cid,"✅ Xabar yuborildi",$panel);
}else{
sms($cid,"❌ Xabar yuborilmadi, ehtimol foydalanuvchi botni bloklagan.",$panel);
}
@unlink("user/$cid.step");

}

if($text=="💳 Hisobim" and joinchat($cid)==1) {
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $cid"));
$kabinet =str_replace(["{outing}","{balance}","{id}"],["".$rew['outing']."","".$rew['balance']."",$rew['user_id']],enc("decode",$setting['kabinet']));
sms($cid,$kabinet,json_encode([
'inline_keyboard'=>[
[['text'=>"💳 Pul kiritish",'callback_data'=>"menu=tolov"]],
//[['text'=>"Buyurtma Berish Uchun QOLLANMA",'url'=>"https://t.me/Infinsmm/111"]],
]]));
}

if((stripos($data,"menu=")!==false and joinchat($chat_id)==1)){
$res=explode("=",$data)[1];
if($res=="tolov"){
$ops=get("set/payments.txt");
$s=explode("\n",$ops);
$soni = substr_count($ops,"\n");
for($i=1;$i<=$soni;$i++){
$k[]=['text'=>$s[$i],'callback_data'=>"payBot=".$s[$i]];
}
$keyboard2=array_chunk($k,2);
//$keyboard2[]=[['text'=>"💳 PAYME",'callback_data'=>"menu=PAYME"]];
$keyboard2[]=[['text'=>"➡️ Orqaga",'callback_data'=>"menu=back"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
edit($chat_id,$message_id,"💳 Kerakli tolov tizimini tanlang:",$kb);

}elseif($res=="back"){
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $chat_id"));
del();
$kabinet =str_replace(["{outing}","{balance}","{id}"],["".$rew['outing']."","".$rew['balance']."","$cid"],enc("decode",$setting['kabinet']));
sms($chat_id,"$kabinet",json_encode([
'inline_keyboard'=>[
[['text'=>"💳 Pul kiritish",'callback_data'=>"menu=tolov"]],
]]));

}elseif($res=="PAYME") {
if(empty($setting['payme_id']) or $setting['payme_id']=="null" or $setting['payme_id']=="NULL"){
bot('answerCallbackQuery',[
'callback_query_id'=>$cqid,
'text'=>"⚠️ Ushbu tolov tizimidagi kerakli malumotlar yetishmaydi",
'show_alert'=>true,
]);
}else{
del();
sms($chat_id,"
💵 To‘lov miqdorini kiriting:

⬇️ Minimal 10000 so‘m
⬆️ Maksimal 12000000 so‘m",$ort);
put("user/$chat_id.step","payme");
}
}
}

if((stripos($data,"payBot=")!==false)){
$h=explode("=", $data)[1];
$card=get("set/pay/$h/wallet.txt");
$info=get("set/pay/$h/addition.txt");
edit($cid2,$mid2,"
To'lov tizimi: $h

Hamyon: $card
Izoh: $cid2
   
$info
",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ To‘lov qildim",'callback_data'=>"tolovqldm"]],
[['text'=>"➡️ Orqaga",'callback_data'=>"menu=tolov"]],
]]));
}

if($data == "tolovqldm") {

sms($chat_id,"💳 To‘lov cheki yoki rasmini yuboring",$ort);
put("user/$chat_id.step","tolovqldm");
}

if($step=="tolovqldm"){
sms($cid,"✅ Hisobni to‘ldirish arizangiz qabul qilindi.",$m);
file_put_contents("user/us.id",$cid);
if($text){
bot('forwardMessage',[
'chat_id'=>$admin,
'message_id'=>$mid,
'from_chat_id'=>$cid,
]);
sms($admin,"👤 Kerakli tugmani tanlang",json_encode([
'inline_keyboard'=>[
[['text'=>"Pul qo‘shish",'callback_data'=>'plus']],
]
]));
}elseif($message->photo){
bot('forwardMessage',[
'chat_id'=>$admin,
'message_id'=>$mid,
'from_chat_id'=>$cid,
]);
sms($admin,"👤 Kerakli tugmani tanlang",json_encode([
'inline_keyboard'=>[
[['text'=>"Pul qo‘shish",'callback_data'=>'plus']],
]
]));


}
@unlink("user/$cid.step");
}




if($step=="payme"){
if($text>="10000" and $text<="12000000"){
$checkout=json_decode(file_get_contents("https://".$_SERVER['HTTP_HOST']."/payme.php?action=create&card=".$setting['payme_id']."&sum=$text&desc=@$bot"),true);
$checkout=$checkout['_result']['_details']['_pay_url'];  
$checkid=str_replace("https://checkout.paycom.uz/",'',$checkout);
sms($cid,"💵 To‘lov miqdori: $text so‘m",json_encode([
'inline_keyboard'=>[
[['text'=>"💵 To‘lovga o‘tish",'url'=>"$checkout"]],
[['text'=>"💵 Shuyerda to‘lash",'web_app'=>['url'=>"$checkout"]]],
[['text'=>"✅ Tekshirish",'callback_data'=>"checkout=$checkid=$text"]],
]]));
sms($cid,"🖥️ Asosiy menyudasiz",$menu);
@unlink("user/$cid.step");
exit; 
}else{
sms($cid,"
⬇️ Minimal 10000 so‘m
⬆️ Maksimal 12000000 so‘m",$ort);
exit; 
}
}


if((stripos($data,"checkout=")!==false and joinchat($chat_id)==1)){
$checkid=explode("=",$data)[1];
$plus=explode("=",$data)[2];
$checkids=file_get_contents("payments.txt");
if(mb_stripos($checkids,$checkid)!==false){
bot('answerCallbackQuery',[
'callback_query_id'=>$cqid,
'text'=>"⚠️ To‘lov bajarilgan.",
'show_alert'=>true,
]);

}else{
$js=json_decode(file_get_contents("https://".$_SERVER['HTTP_HOST']."/payme.php?id=$checkid&action=info"),true);
$pay_time=$js['mess'];
if(empty($pay_time)){
bot('answerCallbackQuery',[
'callback_query_id'=>$cqid,
'text'=>"⚠️ To‘lov bajarilmagan.",
'show_alert'=>true,
]);

}else{
del();
sms($chat_id,"💳 Hisobingizga $plus so‘m qo‘shildi",$menu);
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $chat_id"));
$miqdor = $plus+$rew['balance'];
$p2 =$plus+$rew['outing'];
mysqli_query($connect,"UPDATE users SET balance=$miqdor, outing=$p2 WHERE id = $chat_id");
file_put_contents("payments.txt","\n".$checkid,FILE_APPEND);
sms($admin,"
💳 Hisob to'ldirildi
👤 Foydalanuvchi: $chat_id
💰 Summa: $plus so'm",null);
}
}

}

if($text=="📎 Majburiy obunalar" and $cid==$admin){
sms($cid,$text,json_encode([
'inline_keyboard'=>[
[['text'=>"➕ Qo‘shish",'callback_data'=>"kanal=add"]],
[['text'=>"*️⃣ Ro‘yxat",'callback_data'=>"kanal=list"],['text'=>"🗑️ O'chirish",'callback_data'=>"kanal=dl"]],
]]));

}

if((stripos($data,"kanal=")!==false)){
$rp=explode("=",$data)[1];
if($rp=="list"){
$ops=get("set/channel");
if(empty($ops)){
sms($chat_id,"🤷‍♂️ Xechqanday kanal topilmadi.",null);

}else{
$s=explode("\n",$ops);
$soni = substr_count($ops,"\n");
for($i=0;$i<=count($s)-1;$i++){
$k[]=['text'=>$s[$i],'url'=>"t.me/".str_replace("@","",$s[$i])];
}
$keyboard2=array_chunk($k,2);
$keyboard=json_encode([
'inline_keyboard'=>$keyboard2,
]);
sms($chat_id,"👉 Barcha kanallar:",$keyboard);

}
}elseif($rp=="dl"){
$ops=get("set/channel");
if(empty($ops)){
sms($chat_id,"🤷‍♂️ Xechqanday kanal topilmadi.",null);

}else{
$s=explode("\n",$ops);
$soni = substr_count($ops,"\n");
for($i=0;$i<=count($s)-1;$i++){
$k[]=['text'=>$s[$i],'callback_data'=>"kanal=del".$s[$i]];
}
$keyboard2=array_chunk($k,2);
$keyboard=json_encode([
'inline_keyboard'=>$keyboard2,
]);
sms($chat_id,"🗑️ O‘chiriladigan kanalni tanlang:",$keyboard);
}
}elseif(mb_stripos($rp,"del@")!==false){
$d=explode("@",$rp)[1];
$ops=get("set/channel");
$soni = explode("\n",$ops);
if(count($soni)==1){
unlink("set/channel");
}else{
$ss="@".$d;
$ops=str_replace("\n".$ss."","",$ops);
put("set/channel",$ops);
}
del();
sms($chat_id,"✅ O‘chirildi",null);
}elseif($rp=="add"){
del();
sms($chat_id,"
♻️ Kanal userini kiriting

Namuna: @tamakixor",$aort);
put("user/$chat_id.step","kanal_add");

}
}

if($step=="kanal_add"){
if(mb_stripos($text,"@")!==false){
$kanal=get("set/channel");
sms($cid,"✅ Saqlandi!",$panel);
if($kanal==null){
file_put_contents("set/channel",$text);
}else{
file_put_contents("set/channel","$kanal\n$text");
}
@unlink("user/$chat_id.step");

}
}



if($text == "📞 Nomer olish") {
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"❗️*Bo'limdan foydalanish uchun ushbu shartlarga roziligingizni bildiring

- Sizga virtual nomer berilganda uni bemalol almashtirishingiz yoki bekor qilishingiz mumkin bo'ladi va buning uchun pul olinmaydi
- Agar sizga sms kod kelsa virtual nomerni boshqa almashtirolmaysiz va nomer uchun pul yechiladi
- Agarda kelgan kod notog'ri bo'lsa siz berilgan 20 daqiqa ichida yangi sms kod so'rashingiz mumkin va buning uchun ortiqcha pul olinmaydi
- Agar sizga sms kelsa lekin nomerga kira olmasangiz hamda berilgan 20 daqiqani ham o'tkazib yuborsangiz nomer baribir sizga sotilgan hisoblanadi va buning uchun da'volar qabul qilinmaydi
- Bot orqali olgan nomeringizni o'chirsangiz yoki u block bo'lsa nomer tiklab berilmaydi
- Telegram uchun nomer olganingizda Kod telegram orqali yuborildi deyilgan habar chiqsa nomerni darhol bekor qiling! (Aks holda katta ehtimol bilan nomerda 2 bosqichli parol o'rnatilgan bo'lishi mumkin)

☝️ Yuqoridagi holatlar uchun da'volar qabul qilinmaydi chunki bunga rozilik bildirgan bo'lasiz*",
'parse_mode'=>"markdown",
'reply_markup'=>json_encode([
'remove_keyboard'=>true,
'inline_keyboard'=>[
[['text'=>"✅ Roziman",'callback_data'=>"hop"]],
[['text'=>"❌ Bekor qilish",'callback_data'=>"menu_tolov"]],
]])
]);
}


// --- MUHIM: "📞 Nomer olish" bo'limidagi davlat tanlash sahifalarida
// "⏮️ Orqaga" tugmasi (callback_data="orqa") ishlatiladi, lekin bu qiymat
// uchun HECH QANDAY handler yozilmagan edi — shuning uchun bosilganda
// hech qanday javob kelmasdi. Bosilganda foydalanuvchini shartlar
// (T&C) ekraniga qaytaramiz — bu yerdan yana "✅ Roziman" bosib davlatlar
// ro'yxatiga qaytishi mumkin.
if($data == "orqa"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"❗️*Bo'limdan foydalanish uchun ushbu shartlarga roziligingizni bildiring*",
'parse_mode'=>"markdown",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Roziman",'callback_data'=>"hop"]],
[['text'=>"❌ Bekor qilish",'callback_data'=>"menu_tolov"]],
]])
]);
bot('answerCallbackQuery',['callback_query_id'=>$qid]);
}

// --- MUHIM: xuddi shunday, "❌ Bekor qilish" tugmasi (callback_data=
// "menu_tolov") uchun ham hech qanday handler yo'q edi.
if($data == "menu_tolov"){
bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"🖥️ Bekor qilindi.",
'parse_mode'=>"markdown",
]);
bot('answerCallbackQuery',['callback_query_id'=>$qid]);
}

if($data == "b"){
bot('editmessagetext',[
'chat_id'=>$cid2, 
'message_id'=>$mid2, 
'text'=>"*‍Asosiy menyuga qaytdingiz.*", 
'parse_mode'=>"markdown", 
'reply_markup'=>$m, 
]);
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"⚙️ Ta'mirlanmoqda...!",
'show_alert'=>true,
]);
}


if($data=="hop") {
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
$urla = file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries");
if($urla=="BAD_KEY" or $urla=="NO_KEY"){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"⚠️ Botga API kalit ulanmagan!",
'show_alert'=>true,
]);
}else{
$key = [];
for ($i = 0; $i < 10; $i++) {
if($url["$i"]['eng'] == "Russia"){
$n = "🇷🇺 Rossiya";
}elseif ($url["$i"]['eng'] == "Ukraine"){
$n = "🇺🇦 Ukraina";
}elseif ($url["$i"]['eng'] == "Kazakhstan"){
$n = "🇰🇿 Qozog'iston";
}elseif ($url["$i"]['eng'] == "China"){
$n = "🇨🇳 Xitoy";
}elseif ($url["$i"]['eng'] == "Philippines"){
$n = "🇵🇭 Filippin";
}elseif ($url["$i"]['eng'] == "Myanmar"){
$n = "🇲🇲 Myanma";
}elseif ($url["$i"]['eng'] == "Indonesia"){
$n = "🇮🇩 Indoneziya";
}elseif ($url["$i"]['eng'] == "Malaysia"){
$n = "🇲🇾 Malayziya";
}elseif ($url["$i"]['eng'] == "Kenya"){
$n = "🇰🇪 Keniya";
}elseif ($url["$i"]['eng'] == "Tanzania"){
$n = "🇹🇿 Tanzaniya";
}
$id = $url["$i"]['id'];
$name = $url["$i"]['eng'];
$key[] = ["text" =>"$n",'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$n"];
}
$key1 = array_chunk($key,2);
$key1[]=[["text"=>"1/6","callback_data"=>"null"],['text'=>"⏭️",'callback_data'=>"davlat2"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('EditMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 
'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
]),
]);
}}

if($data=="davlat2") {
$key = [];
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
for ($i = 10; $i < 20; $i++) {
if($url["$i"]['eng'] == "Vietnam"){
$flang = '🇻🇳 Vetnam';
}else if($url["$i"]['eng'] == "Kyrgyzstan"){
$flang = "🇰🇬 Qirg'iziston";
}else if($url["$i"]['eng'] == "USA (virtual)"){
$flang = '🇺🇸 AQSH';
}else if($url["$i"]['eng'] == "Israel"){
$flang = '🇮🇱 Isroil';
}else if($url["$i"]['eng'] == "HongKong"){
$flang = '🇭🇰 Gonkong';
}else if($url["$i"]['eng'] == "Poland"){
$flang = '🇲🇨 Polsha';
}else if($url["$i"]['eng'] == "England"){
$flang = '🇬🇧 Angilya';
}else if($url["$i"]['eng'] == "Madagascar"){
$flang = '🇲🇬 Madagaskar';
}else if($url["$i"]['eng'] == "DCongo"){
$flang = '🇨🇩 Kongo';
}else if($url["$i"]['eng'] == "Nigeria"){
$flang = '🇳🇬 Nigeriya'; 
}
$id = $url["$i"]['id'];
$name = $url["$i"]['eng'];
$key[] = ["text" =>"$flang", 'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$flang"];
}
$key1 = array_chunk($key,2);
$key1[]=[['text'=>"⏮️",'callback_data'=>"hop"],["text"=>"2/6","callback_data"=>"null"],['text'=>"⏭️",'callback_data'=>"davlat4"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('EditMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 

'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
 ]),
]);
}

if($data=="davlat3") {
$key = [];
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
for ($i = 20; $i < 30; $i++) {
if($url["$i"]['eng'] == "Macao"){
$n = "🇲🇴 Makao";
}elseif ($url["$i"]['eng'] == "Egypt"){
$n = "🇪🇬 Misr";
}elseif ($url["$i"]['eng'] == "India"){
$n = "🇮🇳 Hindiston";
}elseif ($url["$i"]['eng'] == "Ireland"){
$n = "🇮🇪 Irlandiya";
}elseif ($url["$i"]['eng'] == "Cambodia"){
$n = "🇰🇭 Kambodja";
}elseif ($url["$i"]['eng'] == "Laos"){
$n = "🇱🇦 Laos";
}elseif ($url["$i"]['eng'] == "Haiti"){
$n = "🇭🇹 Gaiti";
}elseif ($url["$i"]['eng'] == "Ivory"){
$n = "🇨🇮 Ivory";
}elseif ($url["$i"]['eng'] == "Gambia"){
$n = "🇬🇲 Gambiya";
}elseif ($url["$i"]['eng'] == "Serbia"){
$n = "🇷🇸 Serbiya";
}                                
$id = $url["$i"]['id'];
$name = $url["$i"]['eng'];
$key[] = ["text" =>"$n", 'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$n"];
}
$key1 = array_chunk($key,2);
$key1[]=[['text'=>"⏮️",'callback_data'=>"davlat2"],["text"=>"3/6","callback_data"=>"null"],['text'=>"⏭️",'callback_data'=>"davlat3"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('EditMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 

'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
 ]),
]);
}

if($data=="davlat4") {
$key = [];
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
for ($i = 30; $i < 40; $i++) {
if($url["$i"]['eng'] == "Yemen"){
$n = "🇾🇪 Yaman";
}elseif ($url["$i"]['eng'] == "Southafrica"){
$n = "🇿🇦 Janubiy Afrika";
}elseif ($url["$i"]['eng'] == "Romania"){
$n = "🇷🇴 Ruminiya";
}elseif ($url["$i"]['eng'] == "Colombia"){
$n = "🇨🇴 Kolumbiya";
}elseif ($url["$i"]['eng'] == "Estonia"){
$n = "🇪🇪 Estoniya";
}elseif ($url["$i"]['eng'] == "Azerbaijan"){
$n = "🇦🇿 Ozarbayjon";
}elseif ($url["$i"]['eng'] == "Canada"){
$n = "🇨🇦 Kanada";
}elseif ($url["$i"]['eng'] == "Morocco"){
$n = "Marokash";
}elseif ($url["$i"]['eng'] == "Ghana"){
$n = "🇬🇭 Gana";
}elseif ($url["$i"]['eng'] == "Argentina"){
$n = "🇦🇷 Argentina";
}  
$id = $url["$i"]['id'];
$name = $url["$i"]['eng'];
$key[] = ["text" =>"$n", 'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$n"];
}
$key1 = array_chunk($key,2);
$key1[]=[['text'=>"⏮️",'callback_data'=>"davlat3"],["text"=>"4/6","callback_data"=>"null"],['text'=>"⏭️",'callback_data'=>"davlat5"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('EditMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 

'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
]),
]);
}

if($data=="davlat5") {
$key = [];
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
for ($i = 40; $i < 50; $i++) {
if($url["$i"]['eng'] == "Uzbekistan"){
$n = "🇺🇿 O'zbekiston";
}elseif ($url["$i"]['eng'] == "Cameroon"){
$n = "🇨🇲 Kamerun";
}elseif ($url["$i"]['eng'] == "Chad"){
$n = "🇹🇩 Chad";
}elseif ($url["$i"]['eng'] == "Germany"){
$n = "🇩🇪 Germaniya";
}elseif ($url["$i"]['eng'] == "Lithuania"){
$n = "🇱🇹 Litva";
}elseif ($url["$i"]['eng'] == "Croatia"){
$n = "🇭🇷 Xorvatiya";
}elseif ($url["$i"]['eng'] == "Sweden"){
$n = "🇸🇪 Shvetsiya";
}elseif ($url["$i"]['eng'] == "Iraq"){
$n = "🇮🇶 Iroq";
}elseif ($url["$i"]['eng'] == "Netherlands"){
$n = "🇳🇱 Niderlandiya";
}elseif ($url["$i"]['eng'] == "Latvia"){
$n = "🇱🇻 Latviya";
} 
$id = $url["$i"]['id'];
$name = $url["$i"]['eng'];
$key[] = ["text" =>"$n", 'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$n"];
}
$key1 = array_chunk($key,2);
$key1[]=[['text'=>"⏮️",'callback_data'=>"davlat3"],["text"=>"5/6","callback_data"=>"null"],['text'=>"⏭️",'callback_data'=>"davlat6"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('EditMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 

'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
 ]),
]);
}

if($data=="davlat6") {
$key = [];
$url = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries"), true);
for ($i = 53; $i < 63; $i++) {
if($url["$i"]['eng'] == "Saudiarabia"){
$n = "🇸🇦 Saudiya Arabistoni";
}else if($url["$i"]['eng'] == "Mexico"){
$n = "🇲🇽 Meksika";
}else if($url["$i"]['eng'] == "Taiwan"){
$n = "🇹🇼 Tayvan";
}else if($url["$i"]['eng'] == "Spain"){
$n = "🇪🇸 Ispaniya";
}else if($url["$i"]['eng'] == "Iran"){
$n = "🇮🇷 Eron";
}else if($url["$i"]['eng'] == "Algeria"){
$n = "🇩🇿 Jazoir";
}else if($url["$i"]['eng'] == "Slovenia"){
$n = "🇸🇮 Sloveniya";
}else if($url["$i"]['eng'] == "Bangladesh"){
$n = "🇧🇩 Bangladesh";
}else if($url["$i"]['eng'] == "Senegal"){
$n = "🇸🇳 Senegal";
}else if($url["$i"]['eng'] == "Turkey"){
$n = "🇹🇷 Turkiya";
} 
$id = $url["$i"]['id'];
$key[] = ["text" =>"$n",'callback_data' => "raqam=tg=ig=fb=tw=vi=oi=ts=go=$id=$n"];
}
$key1 = array_chunk($key,3);
$key1[]=[['text'=>"⏮️",'callback_data'=>"davlat5"],["text"=>"6/6","callback_data"=>"null"]];
$key1[]=[['text'=>"⏮️ Orqaga","callback_data"=>"orqa"]];
bot('editmessagetext',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"*Nomer olish uchun davlatlar ro'yxati:*", 

'parse_mode'=>'markdown',
'reply_markup' => json_encode([
 'inline_keyboard'=>$key1,
 ]),
]);
}


if(mb_stripos($data,"buy=")!==false){
$ex=explode("=",$data);
$xizmat=$ex[1];
$dav=$ex[3];
$json = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$xizmat), true);
$id=$ex[2];
$country = $id;
foreach($json as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$tson=$element['count'];
break; 
}
}
if(empty($tson)){
$tson=0;
}else{
$tson=$tson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$na=$rp*$simfoiz+$rate;
$a = json_decode(file_get_contents("http://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=uz&dt=t&q=$dav"),1);
$tar = $a[0][0][0];
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"<b>🌍 Davlat:</b> $tar

<b>🔢 Qolgan raqamlar: $tson ta
💰 Raqam narxi: $na so‘m</b>",
'parse_mode'=>'html',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Sotib olish",'callback_data'=>"olish=$xizmat=$id=any=$na"]],
[['text'=>"🔙 Orqaga",'callback_data'=>"raqam=$id=$dav"]],
]])
]);
}


if(mb_stripos($data, "raqam=")!==false){
$ex = explode("=",$data);
$tg=$ex[1];
$Insta=$ex[2];
$fb=$ex[3];
$twitter=$ex[4];
$imo=$ex[5];
$str=$ex[6];
$snap=$ex[7];
$google=$ex[8];
$davlat = $ex[10];
$urla = file_get_contents("{$sms_api_url}?api_key=$simkey&action=getCountries");
if($urla=="BAD_KEY" or $urla=="NO_KEY"){
bot('answerCallbackQuery',['show_alert'=>1,
'callback_query_id'=>$qid,
'text'=>"⚠️ Botga API kalit ulanmagan!",
]);
}else{

##------------------------------------->Telegram<------------------------------------##

$json = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$tg), true);
$id=$ex[9];
$country = $id;
foreach($json as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$tson=$element['count'];
break; 
}
}
if(empty($tson)){
$tson=0;
}else{
$tson=$tson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$tna=$rp*$simfoiz+$rate;

##------------------------------------->Instagram<------------------------------------##

$igson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$Insta), true);
$id=$ex[9];
$country = $id;
foreach($igson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$ison=$element['count'];
break; 
}
}
if(empty($ison)){
$ison=0;
}else{
$ison=$ison;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$ina=$rp*$simfoiz+$rate;

##------------------------------------->Fecbook<------------------------------------##

$fbson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$fb), true);
$id=$ex[9];
$country = $id;
foreach($fbson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$fson=$element['count'];
break; 
}
}
if(empty($fson)){
$fson=0;
}else{
$fson=$fson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$fna=$rp*$simfoiz+$rate;

##------------------------------------->Twitter<------------------------------------##

$twson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$twitter), true);
$id=$ex[9];
$country = $id;
foreach($twson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$wson=$element['count'];
break; 
}
}
if(empty($wson)){
$ttson=0;
}else{
$wson=$wson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$wna=$rp*$simfoiz+$rate;


##------------------------------------->Imo<------------------------------------##

$mson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$imo), true);
$id=$ex[9];
$country = $id;
foreach($mson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$son=$element['count'];
break; 
}
}
if(empty($son)){
$son=0;
}else{
$son=$son;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$imna=$rp*$simfoiz+$rate;



##------------------------------------->Snapchat<------------------------------------##

$chson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$snap), true);
$id=$ex[9];
$country = $id;
foreach($chson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$schson=$element['count'];
break; 
}
}
if(empty($schson)){
$schson=0;
}else{
$schson=$schson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$schna=$rp*$simfoiz+$rate;

##------------------------------------->Tinder<------------------------------------##

$tunson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$str), true);
$id=$ex[9];
$country = $id;
foreach($tunson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$stson=$element['count'];
break; 
}
}
if(empty($stson)){
$stson=0;
}else{
$stson=$stson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$stna=$rp*$simfoiz+$rate;


##------------------------------------->Google<------------------------------------##

$jgson = json_decode(file_get_contents("{$sms_api_url}?api_key=$simkey&action=getTopCountriesByService&operator=any&service=".$google), true);
$id=$ex[9];
$country = $id;
foreach($jgson as $element){
if($element['country'] == $country){
$rate=$element['retail_price'];
$gson=$element['count'];
break; 
}
}
if(empty($gson)){
$gson=0;
}else{
$gson=$gson;
}
$rate=$rate*$simrub;
$rp=$rate/100;
$gna=$rp*$simfoiz+$rate;


bot('editMessageText',[
'chat_id'=>$cid2,
'message_id'=>$mid2, 
'text'=>"*📞 Nomerni qaysi ijtimoiy tarmoq uchun olmoqchisiz?

 Davlat: $davlat

$me Telegram - $tna so'm
$me Instagram -  $ina so'm
$me Facebook - $fna so'm
$me Twitter - $wna so'm
$me Google - $gna so'm 
$me Viber - $imna so'm
$me Tinder $stna so'm 
$me PayPal - $schna so'm*",
'parse_mode'=>'markdown',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"$me Telegram - $tson ta",'callback_data'=>"olish=tg=$id=any=$tna=$davlat"],['text'=>"$me Instagram - $ison ta",'callback_data'=>"olish=ig=$id=any=$davlat"]],
[['text'=>"$me Facebook - $fson ta",'callback_data'=>"olish=fb=$id=any=$davlat"],['text'=>"$me Twitter - $wson ta",'callback_data'=>"olish=tw=$id=any=$davlat"]],
/*[['text'=>"$me Mail.ru - $mason ta",'callback_data'=>"olish=ma=$id=$davlat"],*/[['text'=>"$me Google - $gson ta",'callback_data'=>"olish=go=$id=any=$davlat"],
['text'=>"$me Viber - $son ta",'callback_data'=>"olish=vi=$id=any=$davlat"]],[['text'=>"$me Tinder $stson ta",'callback_data'=>"olish=oi=$id=any=$davlat"], 
['text'=>"$me PayPal - $schson ta",'callback_data'=>"olish=ts=$id=any=$davlat"]],
[['text'=>"🔙 Orqaga",'callback_data'=>"hop"],['text'=>"Menu",'callback_data'=>"orqa"]], 
]])
]);
}}



if(stripos($data,"olish=")!==false){
$xiz=explode("=",$data)[1];
$id=explode("=",$data)[2];
$op=explode("=",$data)[3];
$pric=explode("=",$data)[4];
$davlat=explode("=",$data)[5];
$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid2");
$row = mysqli_fetch_assoc($result);
$foyid= $row['user_id'];
$pul = $row['balance'];
if(($row['balance']>=$pric)){
$arrContextOptions=array(
"ssl"=>array(
"verify_peer"=>false,
"verify_peer_name"=>false,),);
$response = file_get_contents("{$sms_api_url}?api_key=$simkey&action=getNumber&service=$xiz&country=$id&operator=$op", false, stream_context_create($arrContextOptions));
$pieces = explode(":",$response);
$simid = $pieces[1];
$phone = $pieces[2];
if($response=="NO_NUMBERS") {
$msgs="❌ Bu tarmoq uchun nomer mavjud emas!";
}elseif($response=="NO_BALANCE") {
$msgs="⚠️ Xatolik yuz berdi!";
}
if($response == "NO_NUMBERS" or $response == "NO_BALANCE"){
bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>$msgs,
"show_alert"=>true,
]);
}elseif(mb_stripos($response,"ACCESS_NUMBER")!==false){
$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid2");
$row = mysqli_fetch_assoc($result);
$foyid= $row['user_id'];
$pul = $row['balance'];
$miqdor = $row['balance']-$pric;
mysqli_query($connect,"UPDATE users SET balance=$miqdor  WHERE id =$cid2");
bot('editmessagetext',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
'text'=>"
🛎 *Sizga nomer berildi
🌍 Davlat: $davlat 
💸 Narxi: $pric so'm
📞 Nomeringiz: +$phone

Nusxalash:* `$phone`

*📨 Kodni olish uchun « 📩 SMS-kod olish » tugmasini bosing! 

❗️Nomer uchun sms xabarni botning o'zidan olasiz
Agar sms kelmasa yoki raqam blocklangan bo'lsa uni bekor qiling va pulingiz qaytariladi
Smsni kutishga 20 daqiqa berildi
Agar Kod telegram orqali yuborildi deyilgan xabar chiqsa nomerni darhol bekor qiling! (Aks holda katta ehtimol bilan nomerda 2 bosqichli parol o'rnatilgan bo'lishi mumkin)
Yangi sms xabar olish uchun yangi sms tugmasini bosing

👤ID raqam:* `$foyid`",
'parse_mode'=>'markdown',
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"📩 SMS-kod olish",'callback_data'=>"pcode_".$simid."_".$pric]],
[['text'=>"❌ Bekor qilish",'callback_data'=>"otmena_".$simid."_".$pric],],
]
])
]);
}
}else{
bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>"❗Sizda mablag' yetarli emas!",
"show_alert"=>true,
]);
}
}

if(stripos($data,"pcode_")!==false){
$ex=explode("_",$data);
$simid=$ex[1];
$so=$ex[2];
$sims=file_get_contents("simcard.txt");
if(mb_stripos($sims,$simid)!==false){
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❌ Kech qoldingiz yoki raqamni olib bo'ldingiz!",
'show_alert'=>true,
]);
exit();
}else{
$response = file_get_contents("{$sms_api_url}?api_key=$simkey&action=getStatus&id=$simid", false);
if (mb_stripos($response,"STATUS_OK")!==false){
$pieces = explode(":", $response);
$smskod = $pieces[1];
bot('deleteMessage',[
'chat_id'=>$cid2,
'message_id'=>$mid2,
]);
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"📩 *SMS keldi! 

🔢 KOD:* `$smskod`",
'parse_mode'=>'markdown',
]);
}elseif($response=="STATUS_CANCEL") {
bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>"✅ Balansingizga $so so‘m qaytarildi!",
"show_alert"=>true,
]);
$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid2");
$row = mysqli_fetch_assoc($result);
$miqdor = $so+$row['balance'];
mysqli_query($connect,"UPDATE users SET balance=$miqdor  WHERE id =$cid2");
file_put_contents("simcard.txt","\n".$simid,FILE_APPEND);
}elseif($response=="BAD_STATUS") {
bot('sendMessage',[
'chat_id'=>$cid2,
'text'=>"",
'parse_mode'=>'markdown',
]);
}else{
bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>"⏰ SMS kutilmoqda!",
"show_alert"=>true,
]);
}
}
}

if(stripos($data,"otmena_")!==false){
$simid=explode("_",$data)[1];
$so=explode("_",$data)[2];
$sims=file_get_contents("simcard.txt");
$response = file_get_contents("{$sms_api_url}?api_key=$simkey&action=setStatus&status=8&id=$simid");
if(mb_stripos($sims,$simid)!==false){
bot('answerCallbackQuery',[
'callback_query_id'=>$qid,
'text'=>"❌ Kech qoldingiz yoki raqamni olib bo'ldingiz!",
'show_alert'=>true,
]);
exit();
}else{
if(mb_stripos($response,"ACCESS_CANCEL")!==false){ bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>"✅ Balansingizga $so so‘m qaytarildi",
"show_alert"=>true,
]);

$result = mysqli_query($connect, "SELECT * FROM users WHERE id = $cid2");
$row = mysqli_fetch_assoc($result);
$miqdor = $so+$row['balance'];
mysqli_query($connect,"UPDATE users SET balance=$miqdor  WHERE id =$cid2");
file_put_contents("simcard.txt","\n".$simid,FILE_APPEND);
}else{
bot("answerCallbackQuery",[
"callback_query_id"=>$update->callback_query->id,
'text'=>"❗ Kuting..... ",
"show_alert"=>true,
]);

}
}
}




if($text=="🛍 Xizmatlar" and joinchat($cid)==1){
$a = mysqli_query($connect,"SELECT * FROM `categorys`");
// FIX: Avval to'g'ridan-to'g'ri mysqli_num_rows($a) chaqirilardi. Agar
// so'rov xato bersa (masalan "categorys" jadvali bazada mavjud emas,
// yoki jadval/ustun nomi mos kelmasa), mysqli_query "false" qaytaradi va
// mysqli_num_rows(false) PHP 8.1+ da FATAL XATO (TypeError) beradi —
// bu esa butun so'rovni to'xtatib, foydalanuvchiga hech qanday javob
// yubormay qo'yardi. Endi bunday holatda xato logga yoziladi va
// foydalanuvchiga tushunarli xabar ko'rsatiladi.
if($a === false){
error_log("[bot.php] Xizmatlar: 'categorys' jadvalini o'qishda SQL xatosi: ".mysqli_error($connect));
sms($cid,"⚠️ Xizmatlarni yuklashda xatolik yuz berdi. Birozdan so'ng qayta urinib ko'ring yoki admin bilan bog'laning.",null);
exit;
}
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>"".enc("decode",$s['category_name']),'callback_data'=>"tanla1=".$s['category_id']];
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"🔥 Eng yaxshi xizmatlar ⚡️",'url'=>"https://".$_SERVER['HTTP_HOST']."/services"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if($c){
sms($cid,"✅ Bizning xizmatlarimizni tanlaganingizdan xursandmiz!
👇 Quydagi Ijtimoiy tarmoqlardan birini tanlang.",$kb);

}else{
sms($cid,"⚠️ Tarmoqlar topilmadi.",null);
exit; 
}
}


if($data=="absd" and joinchat($chat_id)==1){
$a = mysqli_query($connect,"SELECT * FROM categorys");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
$k[]=['text'=>enc("decode",$s['category_name']),'callback_data'=>"tanla1=".$s['category_id']];
}
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Tarmoqlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"🔥 Eng yaxshi xizmatlar ⚡️",'url'=>"https:".$_SERVER['HTTP_HOST']."/services"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
edit($chat_id,$mid2,"✅ Bizning xizmatlarimizni tanlaganingizdan xursandmiz!
👇 Quydagi Ijtimoiy tarmoqlardan birini tanlang.",$kb);
exit; 
}
}


if((mb_stripos($data,"tanla1=")!==false and joinchat($chat_id)==1)){
$n=explode("=",$data)[1];

$adds=json_decode(get("user/$chat_id.sub.json"),1);
$adds['cate_id']=$n;
put("user/$chat_id.sub.json",json_encode($adds));


$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM cates WHERE category_id = $n");
$c = mysqli_num_rows($a);
while($s = mysqli_fetch_assoc($a)){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>"".enc("decode",$s['name']),'callback_data'=>"tanla2=".$s['cate_id']];
}
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"⏪ Orqaga",'callback_data'=>"absd"]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu tarmq uchun xizmat turlari topilmadi!",
		'show_alert'=>true,
		]);
	}else{
edit($chat_id,$message_id,"⬇️ Kerakli xizmat turini tanlang:",$kb);
exit; 
}
}

if(mb_stripos($data,"tanla2=")!==false and joinchat($chat_id)==1){
$n=explode("=",$data)[1];

$adds=json_decode(get("user/$chat_id.sub.json"),1);
$adds['bolim_id']=$n;
put("user/$chat_id.sub.json",json_encode($adds));

// YANGI: Endi bu bosqichda to'g'ridan-to'g'ri xizmatlar (narxlar) emas,
// balki "Ichki bo'lim" (subcates) ro'yxati ko'rsatiladi — masalan
// "Obunachi" bo'limi ichida "Kafolatli", "Tabiiy & Aktiv" va h.k.
// Orqaga muvofiqlik uchun: agar bu bo'limda hali BIRORTA HAM ichki bo'lim
// qo'shilmagan bo'lsa (eski, subcates funksiyasidan oldingi sozlash), eski
// xatti-harakatga qaytib, xizmatlarni to'g'ridan-to'g'ri shu yerda
// ko'rsatamiz — shunda oldindan sozlangan botlar buzilib qolmaydi.
$new_arr = [];
$k = [];
$a = mysqli_query($connect,"SELECT * FROM subcates WHERE cate_id = $n");
$c = ($a !== false) ? mysqli_num_rows($a) : 0;
while($a !== false && ($s = mysqli_fetch_assoc($a))){
if(!in_array(enc("decode",$s['name']), $new_arr)){
$new_arr[] = enc("decode",$s['name']);
$k[]=['text'=>"".enc("decode",$s['name']),'callback_data'=>"tanla3=".$s['subcate_id']];
}
}

if($c){
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"⏪ Orqaga",'callback_data'=>"tanla1=".$adds['cate_id']]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
edit($chat_id,$message_id,"Ichki bo'limlaridan birini tanlang.",$kb);
exit;
}

// Ichki bo'lim mavjud emas — eski uslubda to'g'ridan-to'g'ri xizmatlarni
// ko'rsatamiz (quyida, servis darajasidagi kod bilan bir xil).
$as=0;
$k=[];
$a = mysqli_query($connect,"SELECT * FROM services WHERE category_id = '$n' AND service_status = 'on'");
$c = ($a !== false) ? mysqli_num_rows($a) : 0;
while($a !== false && ($s = mysqli_fetch_assoc($a))){
$as++;
$narx = fmt_price($s['service_price']);
$k[]=['text'=>"".base64_decode($s['service_name'])." $narx - so‘m",'callback_data'=>"ordered=".$s['service_id']."=".$n];
}
$keyboard2=array_chunk($k,1);
$keyboard2[]=[['text'=>"⏪ Orqaga",'callback_data'=>"tanla1=".$adds['cate_id']]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu bo'lim uchun xizmatlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
edit($chat_id,$message_id,"⬇️ O‘zingizga kerakli xizmatni tanlang:",$kb);
exit; 
}
}


// YANGI: "Ichki bo'lim" (subcates) tanlangandan keyin — shu ichki bo'limga
// tegishli xizmatlar (narxlar) ro'yxati. Avval bu vazifani "tanla2="
// bajarardi, endi u "Ichki bo'lim" ro'yxatini ko'rsatadi, shu bosqich esa
// yakuniy narxlar ro'yxatini ko'rsatadi.
if(mb_stripos($data,"tanla3=")!==false and joinchat($chat_id)==1){
$n=explode("=",$data)[1];
$as=0;

$a = mysqli_query($connect,"SELECT * FROM services WHERE subcate_id = '$n' AND service_status = 'on'");
$c = ($a !== false) ? mysqli_num_rows($a) : 0;
while($a !== false && ($s = mysqli_fetch_assoc($a))){
$as++;
$narx = fmt_price($s['service_price']);
$k[]=['text'=>"".base64_decode($s['service_name'])." $narx - so‘m",'callback_data'=>"ordered=".$s['service_id']."=".$n];
}
$keyboard2=array_chunk($k,1);
$adds=json_decode(get("user/$chat_id.sub.json"),1);
$keyboard2[]=[['text'=>"⏪ Orqaga",'callback_data'=>"tanla2=".$adds['bolim_id']]];
$kb=json_encode([
'inline_keyboard'=>$keyboard2,
]);
if(!$c){
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Ushbu ichki bo'lim uchun xizmatlar topilmadi!",
		'show_alert'=>true,
		]);
	}else{
edit($chat_id,$message_id,"Marhamat, kerakli ta'rifni tanlang!
Narxlar 1000 tasi uchun berilgan.",$kb);
exit; 
}
}







if((stripos($data,"ordered=")!==false)){
$n=explode("=",$data)[1];
$n2=explode("=",$data)[2];
$a = mysqli_query($connect,"SELECT * FROM services WHERE service_id= '$n'");
$subcate_id=null;
while($s = mysqli_fetch_assoc($a)){
$nam = base64_decode($s['service_name']);
$sid = $s['service_id'];
$narx = fmt_price($s['service_price']);
$curr = $s['api_currency'];
$ab = $s['service_desc'] ? $ab=$s['service_desc'] : null;
$api = $s['api_service'];
$type=$s['service_type'];
$spi = $s['service_api'];
$min=$s["service_min"];
$max=$s["service_max"];
$subcate_id = $s['subcate_id'] ?? null;
}


$ap = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers WHERE id = $api"));
$surl=$ap['api_url'];
$skey=$ap['api_key'];
$j=json_decode(smm_panel_post($surl,['key'=>$skey,'action'=>'services']), true);
foreach($j as $el){
if($el['service']==$spi){
$amin=$el["min"];
$amax=$el["max"];
break;
}
}


if($curr=="USD"){
$fr=get("set/usd");
}elseif($curr=="RUB"){
$fr=get("set/rub");
}elseif($curr=="INR"){
$fr=get("set/inr");
}elseif($curr=="TRY"){
$fr=get("set/try");
}
$ab ? $abs = "".base64_decode($ab)."": null;

if($type=="Default" or $type=="default"){
$ab = "🔽 Minimal buyurtma: $min ta
🔼 Maksimal buyurtma: $max ta

$abs";
}elseif($type=="Package"){
$ab = "$abs";
}
if(empty($min) or empty($max)){
bot('answerCallbackQuery',[
'callback_query_id'=>$update->callback_query->id,
'text'=>"⚠️ Nimadir xato ketdi qaytadan urining.",
'show_alert'=>true,
]);
}else{
// FIX/YANGI: "Orqaga" tugmasi avval doim "tanla2=$n2" ga qaytardi. Endi
// xizmat "Ichki bo'lim" (subcates) orqali tanlangan bo'lsa, "tanla3="ga
// (ichki bo'lim ro'yxatiga), aks holda (eski, ichki bo'limsiz bo'limlar
// uchun) eskicha "tanla2="ga qaytaramiz.
$orqaga_cb = $subcate_id ? ("tanla3=".$subcate_id) : ("tanla2=".$n2);
edit($chat_id,$message_id,"
<b>".($nam)."</b>

🔑 Xizmat IDsi: <code>$sid</code>
💵 Narxi (1000 ta) - $narx so‘m

$ab

",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Tanlash",'callback_data'=>"order=$spi=$min=$max=".$narx."=$type=".$api."=$sid"]],
[['text'=>"⏪ Orqaga",'callback_data'=>$orqaga_cb]],
]]));
exit; 
}
}

// FIX: eski tekshiruv stripos($data,"order=") edi - bu "checkorder=..."
// callback_data'sida ham "order=" so'zi uchrashi sababli NOTO'G'RI ishga
// tushib qolardi (har safar "Tasdiqlash" bosilganda ham), natijada
// "Undefined array key 2/3/4/5/6/7" ogohlantirishlari va keraksiz kod
// bajarilishi yuz berardi. Endi faqat $data ANIQ "order=" bilan
// BOSHLANGANDA ishga tushadi (haqiqiy xizmat tanlash tugmasi bosilganda).
if((strpos($data,"order=")===0)){
$oid=explode("=",$data)[1];
$omin=explode("=",$data)[2];
$omax=explode("=", $data)[3];
$orate=explode("=", $data)[4];
$otype=explode("=", $data)[5];
$prov=explode("=",$data)[6];
$serv=explode("=",$data)[7];

if($otype=="Default" or $otype=="default"){
del();
sms($chat_id,"⬇️ Kerakli buyurtma miqdorini kiriting:",$ort);
put("user/$chat_id.step","order=default=sp1");
put("user/$chat_id.params","$oid=$omin=$omax=$orate=$prov=$serv");
put("user/$chat_id.si",$oid);
exit; 
}elseif($otype=="Package") {
del();
sms($chat_id,"📎 Kerakli havolani kiriting (https://):",$ort);
put("user/$chat_id.step","order=package=sp2=1=$orate");
put("user/$chat_id.params","$oid=$omin=$omax=$orate=$prov=$serv");
put("user/$chat_id.si",$oid);
// FIX: Package turidagi buyurtmalarda quantity fayli hech qachon
// yozilmagan edi (faqat Default turida yoziladi) - tasdiqlash bosqichida
// API'ga bo'sh quantity yuborilib, provayder buyurtmani rad etardi (order
// ID qaytmasdi), natijada DBga hech narsa yozilmay, "Buyurtmalarim"
// bo'limi doim bo'sh ko'rinardi. Package = 1 dona sifatida saqlanadi.
put("user/$chat_id.qu","1");
exit; 
}
}

$s=explode("=",$step);
if($s[0]=="order" and $s[1]=="default" and $s[2]=="sp1" and is_numeric($text) and joinchat($cid)==1) {
$p=explode("=",get("user/$cid.params"));
$narxi=$p[3]/1000*$text;
if($text>=$p[1] and $text<=$p[2]){
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $cid"));
if(($rew['balance']>=$narxi)){
sms($cid,"
🛍️ $text saqlandi endi kerakli havolani yuboring.

⚠️ Sahifangiz ochiq (ommaviy) boʻlishi kerak! 

📜 Namuna https://t.me/Infinsmm",$ort);
put("user/$cid.step","order=$s[1]=sp2=$text=$narxi");
put("user/$cid.qu",$text);
exit; 
}else{
sms($cid,"❌ Yetarli mablag‘ mavjud emas
💰 Narxi: $narxi so‘m

Boshqa miqdor kiritib koring:",null);
exit; 
}
}else{
sms($cid,"
⚠️ Buyurtma miqdorini notog’ri kiritilmoqda
 
 ⬇️ Minimal buyurtma: $p[1]
 ⬆️ Maksimal: buyurtma: $p[2]
 
 Boshqa miqdor kiriting",null);
 exit;
 }
 }
 
 

if(($s[0]=="order" and ($s[1]=="default" or $s[1]=="package") and $s[2]=="sp2" and joinchat($cid)==1)){
if($s[1]=="default"){
$pc="🔢 Buyurtma miqdori: $s[3] ta";
}
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $cid"));
if(($rew['balance']>=$s[4])){
if((mb_stripos($tx,"https://")!==false) or (mb_stripos($text,"@")!==false) ){
$msid=sms($cid,"
➡️ Malumotlarni o‘qib chiqing:

💵 Buyurtma narxi: $s[4] so‘m
📎 Buyurtma manzili: $text
$pc

⚠️ Malumotlar to‘g‘ri bo‘lsa (✅ Tasdiqlash) tugmasiga bosing va sizning xisobingizdan $s[4] so‘m miqdorda pul yechib olinadi va buyurtma yuboriladi
buyurtmani bekor qilish imkoni bo'lmaydi",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Tasdiqlash",'callback_data'=>"checkorder=".uniqid()]],
]]))->result->message_id;
put("user/$cid.step","order=$s[1]=sp3=$s[3]=$s[4]=$text");
put("user/$cid.ur",$text);
exit;
}else{
// FIX: "exit;" so'zi xato bilan xabar matni ICHIGA yozilgan edi (kod sifatida
// emas) - foydalanuvchiga xunuk xabar ketardi va haqiqiy exit; yo'qligi sabab
// skript to'xtamay pastdagi keyingi if-bloklarga kirishga urinardi.
sms($cid,"⚠️ Havola noto‘g‘ri yuborilmoqda

Qaytadan xarakat qiling",null);
exit;
}
}else{
sms($cid,"
❌ Yetarli mablag‘ mavjud emas

Hisobingizni to‘ldirib urinib koring.",$ort);
}
}

$sc=explode("=",get("user/$chat_id.step"));
if((stripos($data,"checkorder=")!==false and $sc[0]=="order" and ($sc[1]=="default" or $sc[1]=="package") and $sc[2]=="sp3" and joinchat($chat_id)==1)){
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $chat_id"));
if($rew['balance']>=$sc[4]){
$sc=explode("=",get("user/$chat_id.step"));
$sp=explode("=",get("user/$chat_id.params"));
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM providers WHERE id = ".$sp[4].""));
$surl = $m['api_url'];
$skey =$m['api_key'];
$j=json_decode(smm_panel_post($surl,['key'=>$skey,'action'=>'add','service'=>get("user/$chat_id.si"),'link'=>get("user/$chat_id.ur"),'quantity'=>get("user/$chat_id.qu")]),1);
$jid=$j['order'];
$jer=$j['error'];
if(empty($jid)){
	// FIX: xato faqat $channel (CHANNEL_ID) ga yuborilardi - agar bu
	// o'zgaruvchi noto'g'ri/sozlanmagan bo'lsa (standart qiymati "130" -
	// haqiqiy chat emas), xato hech qayerda ko'rinmasdi. Endi Railway
	// loglariga ham (error_log) yoziladi - bu har doim ishlaydi.
	error_log("[bot.php] SMM buyurtma xatosi - user:$chat_id url:$surl javob:".json_encode($j));
	sms($channel,$surl.$skey.$jer,null);
bot('answerCallbackQuery', [
'callback_query_id'=>$cqid,
'text'=>"
⚠️ Noma'lum xatolik yuz berdi 

Keyinroq urinib ko‘ring",
'show_alert'=>1,
]);
sms($chat_id,"🖥️ Asosiy menyudasiz",$menu);
@unlink("user/$chat_id.step");
@unlink("user/$chat_id.params");
exit;
}else{
$oe = mysqli_num_rows(mysqli_query($connect,"SELECT * FROM orders"));
$or=$oe+1;
$sav = date("Y.m.d H:i:s");
mysqli_query($connect,"INSERT INTO myorder(`order_id`,`user_id`,`retail`,`status`,`service`,`order_create`,`last_check`) VALUES ('$or','$chat_id','$sc[4]','Pending','$sp[5]','$sav','$sav');");
mysqli_query($connect,"INSERT INTO orders(`api_order`,`order_id`,`provider`,`status`) VALUES ('$jid','$or','$sp[4]','Pending');");
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $cid"));
$order =str_replace(["{order}","{order_api}"],["$or","$jid"],enc("decode",$setting['orders']));
sms($chat_id,$order,null);
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $chat_id"));
$miqdor = $rew['balance']-$sc[4];
mysqli_query($connect,"UPDATE users SET balance=$miqdor WHERE id =$chat_id");
@unlink("user/$chat_id.step");
del();
exit;
}
}
}

if((isset($_GET['update']) ? $_GET['update'] : null)=="status"){
echo json_encode(["status"=>true,"cron"=>"Orders status"]);

$mysql=mysqli_query($connect,"SELECT * FROM `orders`");
while($mys=mysqli_fetch_assoc($mysql)){
$prv=$mys['provider'];
$order=$mys['api_order'];
$uorder=$mys['order_id'];
$mysa=mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `myorder` WHERE order_id=$uorder"));
$adm=$mysa['user_id'];
$retail=$mysa['retail'];
if($mys['status']=="Canceled" or $mys['status']=="Completed"){
}else{
$m = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM `providers` WHERE id = $prv"));
$surl = $m['api_url'];
$skey =$m['api_key'];
$sav = date("Y.m.d H:i:s");
$j=json_decode(smm_panel_post($surl,['key'=>$skey,'action'=>'status','order'=>$order]),1);
$status=$j['status'];
if($status){
mysqli_query($connect,"UPDATE orders SET status='$status' WHERE order_id=$uorder");
mysqli_query($connect,"UPDATE myorder SET status='$status', last_check='$sav' WHERE order_id=$uorder");
}
$error=$j['error'];
if(isset($error)){
$oi = $mys['order_id'];
mysqli_query($connect,"DELETE FROM myorder WHERE order_id = $uorder");
}elseif($status=="Completed"){
sms($adm,"✅ Sizning $uorder raqamli buyurtmangiz bajarildi",null);
mysqli_query($connect,"DELETE FROM myorder WHERE order_id = $uorder");
}elseif($status=="Canceled"){
sms($adm,"❌ Sizning $uorder raqamli buyurtmangiz bekor qilindi

💳 Hisobingizga $retail so‘m qaytarildi",null);
$rew = mysqli_fetch_assoc(mysqli_query($connect,"SELECT * FROM users WHERE id = $adm"));
$miqdor = $retail+$rew['balance'];
mysqli_query($connect,"UPDATE users SET balance=$miqdor WHERE id =$adm");
}
}
}
}


$res = mysqli_query($connect,"SELECT*FROM users WHERE id=$cid");
while($a = mysqli_fetch_assoc($res)){
$flid = $a['id'];
}
if(mb_stripos($text,"/start user")!==false){
$id = str_replace("/start user","",$text);
$refid = mysqli_fetch_assoc(mysqli_query($connect,"SELECT*FROM users WHERE user_id = $id"))['id'];

if(strlen($refid)>0 and $refid>0){
if($refid == $cid){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"⚠️ Siz o‘zingizga referal bo‘lishingiz mumkin emas",
'parse_mode'=>'html',
'reply_markup'=>$m,
]);

}else{
if(mb_stripos($flid,"$cid")!==false){
bot('SendMessage',[
'chat_id'=>$cid,
'text'=>"⚠️ Siz bizning botimizda allaqachon mavjudsiz.",
'parse_mode'=>'html',
'reply_markup'=>$m
]);

}else{
$kanal = file_get_contents("set/channel");
if(joinchat($cid)==1){
$pul = mysqli_fetch_assoc(mysqli_query($connect,"SELECT*FROM users WHERE id=$refid"))['balance'];
$a = $pul+enc("decode",$setting['referal']);
mysqli_query($connect,"UPDATE users SET balance = $a WHERE id = $refid");
$text = "📳 <b>Sizda yangi</b> <a href='tg://user?id=$cid'>taklif</a> <b>mavjud!</b>

Hisobingizga ".enc("decode",$setting['referal'])." so‘m qo'shildi!";
$p = get("user/$refid.users");
put("user/$refid.users",$p+1);
}else{
file_put_contents("user/$cid.id",$refid);
$text = "📳 <b>Sizda yangi</b> <a href='tg://user?id=$cid'>taklif</a> <b>mavjud!</b>";
}
bot('sendMessage',[
'chat_id'=>$cid,
    'text'=>"🖥 Asosiy menyudasiz",
    'parse_mode'=>'html',
'reply_markup'=>$m,
]);
bot('SendMessage',[
'chat_id'=>$refid,
'text'=>$text,
'parse_mode'=>'html',
]);

}
}
}
}




if($message){
adduser($cid);
}
if($text=="📨 Yordam" and joinchat($cid)==1) {
sms($cid,"
⭐ Bizga savollaringiz bormi?


📑 Murojaat matnini yozib yuboring
",$ort);
put("user/$cid.step","murojaat");

}
if($text == "⭐️Premium" and joinchat($cid)==1){
sms($cid,"<b><i></i></b>",json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"⭐️Telegram Premium olish"]],[['text'=>"➡️ Orqaga"]],
]
]));
}

if($text == "⭐️Premium") {
sms($cid,"
💵 ⭐️Telegram Premium olish 45,000 so‘m

📅  ⭐️31 kunlik tolov ".(45000)." so‘m

🎁 SIZGA FOYDALI TOMONLARI

Telegram Premium

Telegram Premium-ga obuna bo'lgandan so'ng, ilovadagi deyarli barcha limitlar ikki baravar oshiriladi. Abonentlar 4 GB gacha bo‘lgan fayllarni yuklashlari, fayllarni maksimal tezlikda yuklab olishlari, noyob stikerlar va reaksiyalar yuborishlari, chatlarni boshqarish uchun qo‘shimcha vositalardan foydalanishlari va boshqa ko‘plab imtiyozlarga ega bo‘lishlari mumkin.
Premium ilovaga 4 GB gacha bo'lgan fayllarni yuklang
Barcha foydalanuvchilar har biri 2 GB hajmgacha bo'lgan fotosuratlar, videolar va boshqa fayllarni bepul yuklab olishlari va Telegram bulutli xotirasida cheksiz hajmdagi joydan foydalanishlari mumkin. Telegram Premium obunasi bilan yuklab olishning maksimal hajmi 4 GB gacha ko'tariladi - bu 1080p piksellar soniga ega 4 soatlik video yoki 18 kunlik yuqori sifatli audioni tashkil etadi.

Telegram’ning barcha foydalanuvchilari obunasi bor-yo‘qligidan qat’i nazar, kattalashtirilgan hajmdagi fayllarni yuklab olishlari mumkin bo‘ladi.
‼️ Unutmang! 
⭐️Telegram Premium olingan kundan boshlab, 31 kundan song, ⭐️Telegram Premium  uchun oylik tolov tolashingiz kerak!
",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Tanlash",'callback_data'=>"botopen=Premium=45000=1000=31"]],
]
]));
}
// FIX: avval bu yerda faqat umumiy "botopen=" substring tekshirilardi - ikkala
// narx bo'limi (45000 va 30000 so'mlik) BIR XIL "botopen=" prefiksidan
// foydalangani uchun, foydalanuvchi qaysi tugmani bosishidan qat'i nazar,
// ikkala blok ham navbat bilan ishga tushib, foydalanuvchiga takroriy xabar
// yuborar edi. Endi har bir blok faqat O'ZINING narxiga mos callback_data'ni
// qayta ishlaydi.
if((stripos($data,"botopen=Premium=45000=")!==false and joinchat($chat_id)==1)){
$res = explode("=",$data)[1];
$narx = explode("=",$data)[2];
$kun=explode("=",$data)[3];
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
$rew = mysqli_fetch_assoc($result);

if($res == "Premium") {
if($rew['balance']>=$narx){
edit($chat_id,$message_id,"❓ Siz xaqiqatdan xam premium olmoqchimisz ?",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Ha",'callback_data'=>"mydomen=ha=$narx=$kun"], ['text'=>"❌ Yo'q",'callback_data'=>"mydomen=yoq=$narx=$kun"]],
[['text'=>"",'callback_data'=>"botnext=1"]],
]
]));
}else{
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}else{
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bot aktiv emas",
		'show_alert'=>true,
		]);
}


}

// FIX: xuddi "botopen=" bilan bo'lgani kabi - avval bu yerda faqat umumiy
// "mydomen=" substring tekshirilardi, shu sabab ikkala narx bo'limi
// (45000 va 30000 so'mlik) bir vaqtda ishga tushib, foydalanuvchiga
// takroriy xabar yuborar edi. Endi faqat tegishli narxga mos
// callback_data qayta ishlanadi.
if((stripos($data,"mydomen=")!==false and stripos($data,"=45000=")!==false and joinchat($chat_id)==1)){
$res = explode("=",$data)[1];
$narx = explode("=",$data)[2];
$kun = explode("=",$data)[3];
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
$rew = mysqli_fetch_assoc($result);
if($res == "ha") {
	
	if($rew['balance']>=$narx){
	sms($chat_id,"
✅ Qabul qilindi ",$ort);
	put("user/$chat_id.step","Professional=domenbor=$narx=$kun");
	}else{
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}elseif($res == "yoq") {
if($rew['balance']>=$narx){
sms($chat_id,"✅ Raqamingizni yuboring:",$ort);
put("user/$chat_id.step","Professional=domenyoq=$narx=$kun");
}else{
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}
}

if($step){
// FIX: Bu yerda avval "Premium/Professional bot sotish" (boshqa foydalanuvchiga
// tayyor botni ISPmanager orqali avtomatik klonlab, alohida domen+baza bilan
// ishga tushirib berish) xizmati bo'lgan. Lekin u url_query(), generatemysql(),
// plusmysql() kabi FAYLNING HECH BIR JOYIDA aniqlanmagan funksiyalarni, hamda
// hech qachon belgilanmagan $isp_user/$acc o'zgaruvchilarini chaqirar edi.
// Bu funksiyalar faqat muallifning shaxsiy ISPmanager panelida (sysdc.uz/
// wolfgram.uz) mavjud bo'lgan - ular hech qanday umumiy hosting'da (jumladan
// Railway'da) YO'Q. Natijada bu bo'lim ishga tushganda "Call to undefined
// function" xatosi berib botni to'xtatar edi (global exception handler buni
// ushlab qolgani uchun bot butunlay yiqilmasdi, lekin foydalanuvchi hech
// qanday javob olmay, to'lov ham amalga oshmay "osilib" qolardi).
// Bu xizmat boshqa (uchinchi tomon) serverga TO'LIQ bog'liq bo'lgani va
// Railway kabi oddiy PHP hosting'da ishlay olmasligi sababli, xavfsiz tarzda
// o'chirib qo'yildi va tushunarli xabar bilan almashtirildi - endi bu qadamga
// yetgan foydalanuvchi hisobidan pul yechilmaydi, shunchaki xabar oladi.
if((mb_stripos($step,"Professional=domenyoq=")!==false or mb_stripos($step,"Professional=domenbor=")!==false) and joinchat($cid)==1){
@unlink("user/$cid.step");
sms($cid,"⚠️ Ushbu xizmat (botni avtomatik klonlash / premium hosting) hozircha faol emas.

Iltimos, admin bilan bog'laning.",$menu);
}
}

if($text=="📕 Qo'llanma"){
bot('sendMessage',[
'chat_id'=>$cid,
'text'=>"<b><u>@$bot </u> Botdan foydalanish haqida to'liq video roliklar

Video qo'llanmalardan foydalanish uchun pastdagi tugmalarni bosing!</b>",
'parse_mode'=>"html",
'reply_markup'=>json_encode([
'inline_keyboard'=>[
[['text'=>"🗂️ Xizmatlardan foydalanish",'callback_data'=>"xiz_uz"]],//[['text'=>"🗂️ Xizmatlardan foydalanish",'callback_data'=>"xizmat_uz"]],
]])
]);
}

if($data == "xizmat_uz"){
bot('deleteMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
]);
bot('SendVideo',[
'chat_id'=>$chat_id,
'video'=>"https://t.me/Infinsmm/111",
'caption'=>"<b>🛍️ Xizmatlardan foydalanish - qo'llanmasi.

@$bot.</b>", 
'parse_mode'=>'html',
]);
}

if($data == "xiz_uz"){
bot('deleteMessage',[
'chat_id'=>$chat_id,
'message_id'=>$message_id,
]);
bot('SendVideo',[
'chat_id'=>$chat_id,
'video'=>"$smm12",
'caption'=>"<b>🗂️ Xizmatlardan foydalanish

2024-year Sizdan ishonch bizdan kafolat 

@$bot </b>", 
'parse_mode'=>'html',
]);
}
if($text == "🤖 SMM Bot" and joinchat($cid)==1){
sms($cid,"<b><i>🤖 SMM Bot Ochish Uchun Pastdgagi. ⬇️
 ➕ Yangi bot qo‘shish Tugmasini bosing</i></b>",json_encode([
'resize_keyboard'=>true,
'keyboard'=>[
[['text'=>"➕ Yangi bot qo‘shish"]],[['text'=>"➡️ Orqaga"]],
]
]));
}

if($text == "➕ Yangi bot qo‘shish") {
sms($cid,"
💵 Bot ochish narxi: 30,000 so‘m
📅 1 Kunlik tolov 1000 so’m (31 kunlik tolov ".(31000)." so‘m)

🎁 Bonus sifatida 31 kunlik tolov taqdim etiladi.

✅ Majburiy obuna qo‘shish (cheksiz)
✅ Admin paneli mavjud
✅ To‘lov tizimi qoshish (cheksiz)
✅ Buyurtmalarni tekshirish
✅ Adminga murojaat yozish
✅ Cheklanmagan buyurtma berish
✅ Payme avto tolov tizimi bor
✅ Matnlarni o‘zgartirish mumkin 
✅ Cheklanmagan API ulash mumkin 
✅ Xizmatlar qoshish mumkin
✅ Xizmatlar yuklash mumkin 
✅ Buyurtma xaqida malumot yuboriladi
✅ Ommaviy api berish tizimi bor (shaxsiy yoki 3 darajali domen)
‼️ Unutmang! 
Bot ochilgan kundan boshlab, 31 kundan song, bot uchun oylik tolov tolashingiz kerak!
",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Tanlash",'callback_data'=>"botopen=Premium=30000=1100=31"]],
]
]));
}
// FIX: xuddi shu sababdan (yuqoridagi izohga qarang) - faqat 30000 so'mlik
// narxga tegishli callback_data qayta ishlanadi.
if((stripos($data,"botopen=Premium=30000=")!==false and joinchat($chat_id)==1)){
$res = explode("=",$data)[1];
$narx = explode("=",$data)[2];
$kun=explode("=",$data)[3];
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
$rew = mysqli_fetch_assoc($result);

if($res == "Premium") {
if($rew['balance']>=$narx){
edit($chat_id,$message_id,"❓ Siz Xaqiqatdan Xam tolov qilmoqchimisiz ??",json_encode([
'inline_keyboard'=>[
[['text'=>"✅ Ha",'callback_data'=>"mydomen=ha=$narx=$kun"], ['text'=>"❌ Yo'q",'callback_data'=>"mydomen=yoq=$narx=$kun"]],
//[['text'=>"⬅️ Orqaga",'callback_data'=>"botnext=1"]],
]
]));
}else{
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}else{
bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Bot aktiv emas",
		'show_alert'=>true,
		]);
}


}

// FIX: yuqoridagi izohga qarang - faqat 30000 so'mlik narxga tegishli
// callback_data qayta ishlanadi.
if((stripos($data,"mydomen=")!==false and stripos($data,"=30000=")!==false and joinchat($chat_id)==1)){
$res = explode("=",$data)[1];
$narx = explode("=",$data)[2];
$kun = explode("=",$data)[3];
$result = mysqli_query($connect,"SELECT * FROM `users` WHERE id = '$chat_id'");
$rew = mysqli_fetch_assoc($result);
if($res == "ha") {
	
	if($rew['balance']>=$narx){
	sms($chat_id,"
✅ telegram useringizni kiriting adminlarimiz 24soat ichida siz bilan bog'lanadi

⚙️admin lichkasi @infinsmmhelp
	
	➡️@infinsmmhelp",$ort);
	put("user/$chat_id.step","Professional=domenbor=$narx=$kun");
	}else{
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}elseif($res == "yoq") {
if($rew['balance']>=$narx){
sms($chat_id,"🔑 Botingiz tokenini kiriting:",$ort);
put("user/$chat_id.step","Professional=domenyoq=$narx=$kun");
}else{
	bot('answerCallbackQuery',[
		'callback_query_id'=>$qid,
		'text'=>"⚠️ Mablag‘ yetarli emas!",
		'show_alert'=>true,
		]);
}
}
}

