<?php
// افزایش حد حافظه و زمان اجرای اسکریپت
ini_set('memory_limit', '1024M');
set_time_limit(0);

// دانلود اتوماتیک MadelineProto
if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.org/madeline.php', 'madeline.php');
}
require_once 'madeline.php';

// تنظیمات API
$settings = [
    'app_info' => [
        'api_id'   => 1234567, // 👈 api_id خود را وارد کنید
        'api_hash' => 'YOUR_API_HASH_HERE', // 👈 api_hash خود را وارد کنید
    ],
    'logger' => ['logger' => 0]
];

$MadelineProto = new \danog\MadelineProto\API('session.madeline', $settings);
$MadelineProto->start();

// ۱. بخش استریم چانک به دانلود منیجر (برای گزینه ۳)
if (isset($_GET['stream_id']) && isset($_GET['peer'])) {
    $messageId = (int)$_GET['stream_id'];
    $peer      = $_GET['peer'];

    try {
        $messages = $MadelineProto->channels->getMessages(['channel' => $peer, 'id' => [$messageId]]);
        if (!empty($messages['messages'][0]['media'])) {
            $media = $messages['messages'][0];
            
            // ارسال فایل به صورت Chunked Stream مستقیم به دانلود منیجر (IDM)
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="file_' . $messageId . '"');
            
            // این متد دانلود منیجر را پشتیبانی کرده و فایل را تکه‌تکه ارسال می‌کند
            $MadelineProto->downloadToResponse($media);
            exit;
        }
    } catch (\Exception $e) {
        die("خطا در استریم: " . $e->getMessage());
    }
}

// ۲. پردازش پیام‌های ربات
$update = $MadelineProto->getUpdates();

// بررسی دریافت پیام جدید
if (isset($update['message'])) {
    $msg     = $update['message'];
    $chat_id = $msg['to_id'] ?? null;

    // دریافت دکمه‌های کلیک شده (Callback Query)
    if (isset($msg['media'])) {
        $msg_id = $msg['id'];
        $peer   = $msg['to_id']['channel_id'] ?? $msg['from_id'];

        // کیبورد ۴ گزینه‌ای
        $buttons = [
            'inline_keyboard' => [
                [['text' => '1️⃣ ارسال فایل در چت', 'callback_data' => "opt1_{$peer}_{$msg_id}"]],
                [['text' => '2️⃣ ذخیره روی هاست و لینک مستقیم', 'callback_data' => "opt2_{$peer}_{$msg_id}"]],
                [['text' => '3️⃣ استریم چانک به دانلود منیجر', 'callback_data' => "opt3_{$peer}_{$msg_id}"]],
                [['text' => '4️⃣ همه موارد', 'callback_data' => "opt4_{$peer}_{$msg_id}"]]
            ]
        ];

        $MadelineProto->messages->sendMessage([
            'peer'         => $chat_id,
            'message'      => "📦 فایل شما دریافت شد. یکی از گزینه‌های زیر را انتخاب کنید:",
            'reply_markup' => $buttons
        ]);
    }
}

// ۳. پردازش کلیک روی گزینه‌ها
if (isset($update['callback_query'])) {
    $cb   = $update['callback_query'];
    $data = $cb['data'];
    $chat = $cb['userId'];

    list($opt, $peer, $msg_id) = explode('_', $data);

    // لینک پایه هاست رندر شما
    $domain = "https://" . $_SERVER['HTTP_HOST']; 

    // **گزینه ۱: ارسال مجدد فایل به کاربر در چت**
    if ($opt === 'opt1' || $opt === 'opt4') {
        $MadelineProto->messages->forwardMessages([
            'from_peer' => $peer,
            'id'        => [$msg_id],
            'to_peer'   => $chat
        ]);
    }

    // **گزینه ۲: ذخیره روی دیسک هاست و ارائه لینک دانلود مستقیم**
    if ($opt === 'opt2' || $opt === 'opt4') {
        try {
            if (!is_dir('downloads')) mkdir('downloads', 0777, true);
            
            $messages = $MadelineProto->channels->getMessages(['channel' => $peer, 'id' => [$msg_id]]);
            $filePath = 'downloads/' . $msg_id . '_file';
            
            // دانلود و ذخیره روی دیسک
            $MadelineProto->downloadToFile($messages['messages'][0], $filePath);
            
            $directUrl = $domain . '/' . $filePath;
            $MadelineProto->messages->sendMessage([
                'peer'    => $chat,
                'message' => "💾 **فایل روی هاست ذخیره شد:**\n" . $directUrl
            ]);
        } catch (\Exception $e) {
            $MadelineProto->messages->sendMessage([
                'peer'    => $chat,
                'message' => "⚠️ خطا در ذخیره روی هاست (احتمال کمبود فضای دیسک): " . $e->getMessage()
            ]);
        }
    }

    // **گزینه ۳: ارسال از طریق Chunks به دانلود منیجر بدون ذخیره روی هاست**
    if ($opt === 'opt3' || $opt === 'opt4') {
        $streamUrl = $domain . "/index.php?stream_id={$msg_id}&peer={$peer}";
        $MadelineProto->messages->sendMessage([
            'peer'       => $chat,
            'message'    => "⚡ **لینک استریم مستقیم (مخصوص دانلود منیجر):**\n\n<code>{$streamUrl}</code>\n\n*(این لینک فایل را به صورت Chunked استریم می‌کند و نیازی به فضای هاست ندارد)*",
            'parse_mode' => 'HTML'
        ]);
    }
}