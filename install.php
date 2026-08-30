<?php
declare(strict_types=1);
require __DIR__ . '/config/bootstrap.php';

$done = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $pdo->exec($sql);

        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)");
        $stmt->execute([trim($_POST['name']), trim($_POST['email']), $password, 'admin']);
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html><html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>نصب ماهر فلو</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>body{font-family:Vazirmatn,system-ui,sans-serif}</style>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/maher-flow-mobile.css"></head><body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-lg">
<h1 class="text-2xl font-extrabold text-slate-900">ماهر فلو</h1><p class="text-slate-500 mt-2">نصب نسخه ۱</p>
<?php if($done): ?><div class="mt-6 bg-emerald-50 text-emerald-700 p-4 rounded-xl">نصب انجام شد. فایل install.php را حذف کنید و وارد سیستم شوید.</div><a class="block mt-4 text-center bg-slate-900 text-white rounded-xl py-3" href="login.php">ورود به سیستم</a>
<?php else: ?>
<?php if($error): ?><div class="mt-4 bg-red-50 text-red-700 p-3 rounded-xl"><?=e($error)?></div><?php endif; ?>
<form method="post" class="mt-6 space-y-4">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<label class="block"><span class="text-sm">نام مدیر</span><input required name="name" class="mt-1 w-full border rounded-xl p-3"></label>
<label class="block"><span class="text-sm">ایمیل ورود</span><input required type="email" name="email" class="mt-1 w-full border rounded-xl p-3"></label>
<label class="block"><span class="text-sm">رمز عبور</span><input required minlength="8" type="password" name="password" class="mt-1 w-full border rounded-xl p-3"></label>
<button class="w-full bg-emerald-600 text-white rounded-xl py-3 font-bold">ساخت دیتابیس و حساب مدیر</button>
</form><?php endif; ?>
</div><script src="/assets/maher-flow-mobile.js"></script></body></html>
