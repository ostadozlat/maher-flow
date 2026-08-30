<?php
declare(strict_types=1);
require __DIR__ . '/config/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1");
    $stmt->execute([trim($_POST['email'])]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user'] = ['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
        header('Location: index.php'); exit;
    }
    $error = 'ایمیل یا رمز عبور صحیح نیست.';
}
?>
<!doctype html><html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود | ماهر فلو</title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="/assets/maher-flow-v1.1.css"><link rel="stylesheet" href="/assets/maher-flow-mobile.css"></head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center p-4" style="font-family:Vazirmatn,system-ui,sans-serif">
<div class="w-full max-w-md bg-white rounded-3xl p-8 shadow-2xl">
<div class="flex items-center gap-3"><div class="w-12 h-12 rounded-2xl bg-emerald-600 text-white grid place-items-center font-black text-xl">م</div><div><h1 class="font-extrabold text-xl">ماهر فلو</h1><p class="text-xs text-slate-500">مدیریت هوشمند کارگاه</p></div></div>
<?php if($error): ?><div class="mt-6 bg-red-50 text-red-700 p-3 rounded-xl"><?=$error?></div><?php endif; ?>
<form method="post" class="mt-6 space-y-4">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input required type="email" name="email" placeholder="ایمیل" class="w-full border border-slate-200 rounded-xl p-3">
<input required type="password" name="password" placeholder="رمز عبور" class="w-full border border-slate-200 rounded-xl p-3">
<button class="w-full bg-slate-900 hover:bg-slate-800 text-white rounded-xl py-3 font-bold">ورود</button>
</form></div><script src="/assets/maher-flow-mobile.js"></script></body></html>
