<?php
// افزایش حد حافظه و زمان اجرای اسکریپت برای فایل‌های حجیم
ini_set('memory_limit', '1024M');
set_time_limit(0);

// فراخوانی مستقیم کتابخانه MadelineProto
require_once 'madeline.php';

use danog\MadelineProto\Settings;

// ۱. تنظیمات API ID و API Hash (مطابق با استاندارد جدید MadelineProto v8+)
$settings = new Settings();
$settings->getAppInfo()
    ->setApiId(2496)                  // 👈 api_id (عددی) خود را اینجا وارد کنید
    ->setApiHash('8e45c8e4f5283727810d2d6f6412999f');  // 👈 api_hash متنی خود را اینجا وارد کنید

$MadelineProto = new \danog\MadelineProto\API('session.madeline', $settings);
$MadelineProto->start();

// ۲. پردازش استریم تکه‌تکه (Chunked Stream) برای دانلود منیجر (گزینه ۳)
if (isset($_GET['stream_id']) && isset($_GET['peer'])) {
    $messageId = (int)$_GET['stream_id'];
    $peer      = $_GET['peer'];

    try {
        $messages = $MadelineProto->channels->getMessages(['channel' => $peer, 'id' => [$messageId]]);
        if (!empty($messages['messages'][0]['media'])) {
            $media = $messages['messages'][0];
            
            // هدرهای مخصوص دانلود مستقیم در دانلود منیجر (IDM)
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="file_' . $messageId . '"');
            
            // ارسال چانک‌ها مستقیماً از تلگرام به مرورگر/IDM بدون ذخیره روی هاست
            $MadelineProto->downloadToResponse($media);
            exit;
        }
    } catch (\Exception $e) {
        die("خطا در استریم فایل: " . $e->getMessage());
    }
}

// ۳. دریافت و پردازش پیام‌های جدید و Callbackها
$update = $MadelineProto->getUpdates();

// الف) پردازش فایلی که کاربر برای ربات می‌فرستد
if (isset($update['message'])) {
    $msg     = $update['message'];
    $chat_id = $msg['to_id'] ?? null;

    if (isset($msg['media'])) {
        $msg_id = $msg['id'];
        $peer   = $msg['to_id']['channel_id'] ?? $msg['from_id'] ?? $chat_id;

        // دکمه‌های شیشه‌ای ۴ گزینه‌ای
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
            'message'      => "📦 فایل شما دریافت شد.\nلطفاً نحوه دریافت را انتخاب کنید:",
            'reply_markup' => $buttons
        ]);
    }
}

// ب) پردازش کلیک کاربر روی دکمه‌ها
if (isset($update['callback_query'])) {
    $cb   = $update['callback_query'];
    $data = $cb['data'] ?? '';
    $chat = $cb['userId'] ?? null;

    if (strpos($data, 'opt') === 0) {
        list($opt, $peer, $msg_id) = explode('_', $data);
        $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'fourgigtelbot.onrender.com');

        // گزینه ۱ یا ۴: ارسال مجدد فایل در چت (بدون دانلود)
        if ($opt === 'opt1' || $opt === 'opt4') {
            $MadelineProto->messages->forwardMessages([
                'from_peer' => $peer,
                'id'        => [$msg_id],
                'to_peer'   => $chat
            ]);
        }

        // گزینه ۲ یا ۴: ذخیره روی هاست و ارائه لینک دانلود مستقیم
        if ($opt === 'opt2' || $opt === 'opt4') {
            try {
                if (!is_dir('downloads')) {
                    mkdir('downloads', 0777, true);
                }
                
                $messages  = $MadelineProto->channels->getMessages(['channel' => $peer, 'id' => [$msg_id]]);
                $filePath  = 'downloads/' . $msg_id . '_file';
                
                // دانلود و ذخیره روی دیسک هاست
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

        // گزینه ۳ یا ۴: استریم چانک مستقیم به دانلود منیجر
        if ($opt === 'opt3' || $opt === 'opt4') {
            $streamUrl = $domain . "/index.php?stream_id={$msg_id}&peer={$peer}";
            $MadelineProto->messages->sendMessage([
                'peer'       => $chat,
                'message'    => "⚡ **لینک استریم مستقیم (مخصوص دانلود منیجر):**\n\n<code>{$streamUrl}</code>\n\n*(بدون اشغال فضای هاست، مستقیم از تلگرام به IDM)*",
                'parse_mode' => 'HTML'
            ]);
        }
    }
}