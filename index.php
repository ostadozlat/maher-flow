<?php
declare(strict_types=1);

/* Maher Flow V1.1 front-controller */
$mfPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mfBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($mfBase !== '' && $mfBase !== '/') {
    $mfPath = preg_replace('#^'.preg_quote($mfBase, '#').'#', '', $mfPath) ?: '/';
}
if ($mfPath === '/login' || $mfPath === '/login/') {
    require __DIR__ . '/login.php';
    exit;
}
require __DIR__ . '/config/bootstrap.php';
require_login();

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard','orders','order','new-order','customers','customer','equipment','projects','parts','users','reports'];
if (!in_array($page,$allowed,true)) $page='dashboard';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action==='customer_save') {
            $stmt=$pdo->prepare("INSERT INTO customers(name,company,phone,email,address,notes) VALUES(?,?,?,?,?,?)");
            $stmt->execute([trim($_POST['name']),trim($_POST['company']),trim($_POST['phone']),trim($_POST['email']),trim($_POST['address']),trim($_POST['notes'])]);
            flash('success','مشتری با موفقیت ثبت شد.');
            header('Location: index.php?page=customers'); exit;
        }
        if ($action==='equipment_save') {
            $stmt=$pdo->prepare("INSERT INTO equipment(customer_id,name,model,serial_number,category,location,notes) VALUES(?,?,?,?,?,?,?)");
            $stmt->execute([(int)$_POST['customer_id'],trim($_POST['name']),trim($_POST['model']),trim($_POST['serial_number']),trim($_POST['category']),trim($_POST['location']),trim($_POST['notes'])]);
            flash('success','تجهیز ثبت شد.');
            header('Location: index.php?page=customers'); exit;
        }
        if ($action==='order_save') {
            $code='MF-'.date('ymd').'-'.str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
            $stmt=$pdo->prepare("INSERT INTO work_orders(tracking_code,customer_id,equipment_id,project_id,assigned_to,title,problem_description,initial_notes,status,priority,estimated_cost,deposit,due_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$code,(int)$_POST['customer_id'],$_POST['equipment_id']?:null,$_POST['project_id']?:null,$_POST['assigned_to']?:null,trim($_POST['title']),trim($_POST['problem_description']),trim($_POST['initial_notes']),'پذیرش',$_POST['priority'],(float)$_POST['estimated_cost'],(float)$_POST['deposit'],$_POST['due_at']?:null]);
            $id=(int)$pdo->lastInsertId();
            $h=$pdo->prepare("INSERT INTO work_order_history(work_order_id,user_id,action,new_value) VALUES(?,?,?,?)");
            $h->execute([$id,auth_user()['id'],'ایجاد سفارش','پذیرش']);
            flash('success',"سفارش $code ثبت شد.");
            header("Location: index.php?page=order&id=$id"); exit;
        }
        if ($action==='order_status') {
            $id=(int)$_POST['id'];
            $stmt=$pdo->prepare("SELECT status FROM work_orders WHERE id=?"); $stmt->execute([$id]); $old=$stmt->fetchColumn();
            $new=trim($_POST['status']);
            $pdo->prepare("UPDATE work_orders SET status=? WHERE id=?")->execute([$new,$id]);
            $pdo->prepare("INSERT INTO work_order_history(work_order_id,user_id,action,old_value,new_value) VALUES(?,?,?,?,?)")->execute([$id,auth_user()['id'],'تغییر وضعیت',$old,$new]);
            flash('success','وضعیت سفارش به‌روزرسانی شد.');
            header("Location: index.php?page=order&id=$id"); exit;
        }
        if ($action==='part_save') {
            $pdo->prepare("INSERT INTO parts(part_number,name,category,stock,min_stock,unit_price,supplier) VALUES(?,?,?,?,?,?,?)")
                ->execute([trim($_POST['part_number']),trim($_POST['name']),trim($_POST['category']),floatval($_POST['stock']),floatval($_POST['min_stock']),floatval($_POST['unit_price']),trim($_POST['supplier'])]);
            flash('success','قطعه ثبت شد.'); header('Location: index.php?page=parts'); exit;
        }
        if ($action==='payment_save') {
            $id=(int)$_POST['work_order_id'];
            $pdo->prepare("INSERT INTO payments(work_order_id,amount,method,note) VALUES(?,?,?,?)")->execute([$id,(float)$_POST['amount'],$_POST['method'],trim($_POST['note'])]);
            flash('success','پرداخت ثبت شد.'); header("Location: index.php?page=order&id=$id"); exit;
        }
        if ($action==='user_save') {
            $pdo->prepare("INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)")->execute([trim($_POST['name']),trim($_POST['email']),password_hash($_POST['password'],PASSWORD_DEFAULT),$_POST['role']]);
            flash('success','کاربر ثبت شد.'); header('Location: index.php?page=users'); exit;
        }
    } catch(Throwable $e) {
        flash('error','خطا: '.$e->getMessage());
    }
}

$flashes=flashes();
function countv(PDO $pdo,string $sql,array $p=[]): int { $s=$pdo->prepare($sql);$s->execute($p);return (int)$s->fetchColumn(); }
$stats=[
 'pending'=>countv($pdo,"SELECT COUNT(*) FROM work_orders WHERE status IN ('پذیرش','بررسی اولیه','منتظر قطعه')"),
 'repair'=>countv($pdo,"SELECT COUNT(*) FROM work_orders WHERE status IN ('عیب‌یابی','تعمیر','تست نهایی')"),
 'ready'=>countv($pdo,"SELECT COUNT(*) FROM work_orders WHERE status='آماده تحویل'"),
 'delivered'=>countv($pdo,"SELECT COUNT(*) FROM work_orders WHERE status='تحویل شده'")
];
$customers=$pdo->query("SELECT * FROM customers ORDER BY id DESC LIMIT 100")->fetchAll();
$orders=$pdo->query("SELECT w.*,c.name customer_name,u.name tech FROM work_orders w JOIN customers c ON c.id=w.customer_id LEFT JOIN users u ON u.id=w.assigned_to ORDER BY w.id DESC LIMIT 100")->fetchAll();
$parts=$pdo->query("SELECT * FROM parts ORDER BY id DESC LIMIT 100")->fetchAll();
$users=$pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$statuses=$pdo->query("SELECT * FROM workflow_statuses WHERE is_active=1 ORDER BY sort_order")->fetchAll();
$customersAll=$pdo->query("SELECT id,name FROM customers ORDER BY name")->fetchAll();
$usersAll=$pdo->query("SELECT id,name FROM users WHERE is_active=1 ORDER BY name")->fetchAll();
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($config['app_name'])?> | <?=e($page)?></title>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{font-family:Vazirmatn,system-ui,sans-serif}.glass{backdrop-filter:blur(10px)}
.sidebar{transition:transform .25s ease}.ltr{direction:ltr}
</style>
<link rel="stylesheet" href="/assets/maher-flow-v1.1.css"><link rel="stylesheet" href="/assets/maher-flow-mobile.css"></head>
<body class="bg-slate-100 text-slate-800">
<div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden" onclick="toggleSidebar()"></div>
<aside id="sidebar" class="sidebar fixed z-40 right-0 top-0 bottom-0 w-72 bg-slate-900 text-white p-5 md:translate-x-0 translate-x-full">
<div class="flex items-center gap-3 mb-8"><div class="w-11 h-11 rounded-2xl bg-emerald-500 grid place-items-center font-black text-xl">م</div><div><div class="font-extrabold text-lg">ماهر فلو</div><div class="text-xs text-slate-400">Maher Flow · V1</div></div></div>
<nav class="space-y-1 text-sm">
<?php $nav=[
'dashboard'=>['داشبورد','⌂'],'orders'=>['سفارش‌های کاری','▣'],'new-order'=>['ثبت کار جدید','＋'],
'customers'=>['مشتریان','♙'],'equipment'=>['تجهیزات','◈'],'projects'=>['پروژه‌ها','◫'],
'parts'=>['انبار و قطعات','◉'],'reports'=>['گزارشات','◌'],'users'=>['کاربران','⚙']];
foreach($nav as $k=>$n): ?>
<a href="?page=<?=$k?>" class="flex items-center gap-3 px-4 py-3 rounded-xl <?=$page===$k?'bg-white/10 text-emerald-300':'text-slate-300 hover:bg-white/5'?>"><span class="text-lg"><?=$n[1]?></span><?=$n[0]?></a>
<?php endforeach;?>
</nav>
<div class="absolute bottom-5 right-5 left-5">
<div class="rounded-xl bg-white/5 p-3 text-xs text-slate-300 flex items-center justify-between"><span>وضعیت سیستم</span><span class="text-emerald-400">● آنلاین</span></div>
<div class="mt-2 text-xs text-slate-500">DB Sync · فعال</div>
</div>
</aside>

<main class="md:mr-72 min-h-screen">
<header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 md:px-7 sticky top-0 z-20">
<button class="md:hidden text-2xl" onclick="toggleSidebar()">☰</button>
<div class="font-bold text-slate-700"><?= $page==='dashboard'?'داشبورد':($page==='new-order'?'ثبت کار جدید':ucwords(str_replace('-',' ',$page))) ?></div>
<div class="flex items-center gap-3"><div class="text-right hidden sm:block"><div class="text-sm font-bold"><?=e(auth_user()['name'])?></div><div class="text-xs text-slate-400"><?=e(auth_user()['role'])?></div></div><a href="logout.php" class="text-sm text-red-500">خروج</a></div>
</header>
<section class="p-4 md:p-7 max-w-7xl mx-auto">
<?php foreach($flashes as $f): ?><div class="mb-4 rounded-xl p-3 <?=$f['type']==='success'?'bg-emerald-50 text-emerald-700':'bg-red-50 text-red-700'?>"><?=e($f['message'])?></div><?php endforeach; ?>

<?php if($page==='dashboard'): ?>
<div class="flex flex-col lg:flex-row gap-4 mb-6"><div><h1 class="text-2xl font-extrabold">خلاصه وضعیت کارگاه</h1><p class="text-slate-500 text-sm mt-1">نمای کلی سفارش‌ها و عملیات جاری</p></div><div class="lg:mr-auto flex gap-2"><a href="?page=new-order" class="bg-emerald-600 text-white px-4 py-2.5 rounded-xl font-bold">+ ثبت کار جدید</a><a href="?page=reports" class="bg-white border px-4 py-2.5 rounded-xl">گزارشات</a></div></div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
<?php $cards=[['pending','در انتظار','blue'],['repair','در حال تعمیر','amber'],['ready','آماده تحویل','emerald'],['delivered','تحویل شده','slate']]; foreach($cards as $c): ?>
<div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-100"><div class="text-sm text-slate-500"><?=$c[1]?></div><div class="text-3xl font-extrabold mt-2"><?=$stats[$c[0]]?></div><div class="text-xs mt-2 text-<?=$c[2]?>-600">سفارش</div></div>
<?php endforeach;?></div>
<div class="grid lg:grid-cols-3 gap-5 mt-6">
<div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border p-5"><div class="flex justify-between mb-4"><h2 class="font-extrabold">آخرین سفارش‌ها</h2><a class="text-sm text-blue-600" href="?page=orders">مشاهده همه</a></div>
<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-right text-slate-400 border-b"><th class="pb-3">کد</th><th>مشتری</th><th>تجهیز</th><th>وضعیت</th><th></th></tr></thead><tbody>
<?php foreach(array_slice($orders,0,8) as $o): ?><tr class="border-b last:border-0"><td class="py-3 font-bold ltr text-right"><?=e($o['tracking_code'])?></td><td><?=$o['customer_name']?></td><td><?=e($o['title'])?></td><td><span class="px-2.5 py-1 rounded-full bg-slate-100 text-xs"><?=$o['status']?></span></td><td><a class="text-blue-600" href="?page=order&id=<?=$o['id']?>">جزئیات</a></td></tr><?php endforeach;?>
</tbody></table></div></div>
<div class="bg-white rounded-2xl shadow-sm border p-5"><h2 class="font-extrabold mb-4">وضعیت سفارش‌ها</h2><canvas id="statusChart" height="250"></canvas></div>
</div>
<?php elseif($page==='new-order'): ?>
<div class="max-w-4xl"><h1 class="text-2xl font-extrabold">ثبت کار جدید</h1><p class="text-slate-500 mt-1 mb-6">اطلاعات پذیرش اولیه سفارش را وارد کنید.</p>
<form method="post" class="bg-white rounded-2xl shadow-sm border p-5 md:p-7 space-y-6"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="order_save">
<div class="grid md:grid-cols-2 gap-4">
<label>مشتری<select required name="customer_id" class="mt-1 w-full border rounded-xl p-3"><option value="">انتخاب مشتری</option><?php foreach($customersAll as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select></label>
<label>تکنسین مسئول<select name="assigned_to" class="mt-1 w-full border rounded-xl p-3"><option value="">بدون تخصیص</option><?php foreach($usersAll as $u):?><option value="<?=$u['id']?>"><?=e($u['name'])?></option><?php endforeach;?></select></label>
<label>عنوان کار<input required name="title" class="mt-1 w-full border rounded-xl p-3" placeholder="مثلاً تعمیر UPS 10kVA"></label>
<label>اولویت<select name="priority" class="mt-1 w-full border rounded-xl p-3"><option value="normal">عادی</option><option value="high">بالا</option><option value="urgent">فوری</option><option value="low">کم</option></select></label>
<label>هزینه برآوردی<input name="estimated_cost" type="number" min="0" class="mt-1 w-full border rounded-xl p-3"></label>
<label>بیعانه<input name="deposit" type="number" min="0" class="mt-1 w-full border rounded-xl p-3"></label>
<label>تاریخ تحویل<input name="due_at" type="date" class="mt-1 w-full border rounded-xl p-3"></label>
</div>
<label class="block">شرح علائم خرابی<textarea name="problem_description" rows="4" class="mt-1 w-full border rounded-xl p-3"></textarea></label>
<label class="block">توضیحات اولیه<textarea name="initial_notes" rows="4" class="mt-1 w-full border rounded-xl p-3"></textarea></label>
<div class="flex justify-end"><button class="bg-emerald-600 text-white px-6 py-3 rounded-xl font-bold">ثبت سفارش</button></div>
</form></div>
<?php elseif($page==='customers'): ?>
<div class="flex justify-between items-center mb-6"><div><h1 class="text-2xl font-extrabold">بانک مشتریان</h1><p class="text-slate-500 text-sm">مدیریت مشتریان و اطلاعات تماس</p></div></div>
<div class="grid lg:grid-cols-3 gap-5">
<div class="lg:col-span-2 bg-white rounded-2xl border shadow-sm p-5 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-slate-400"><th class="pb-3 text-right">نام</th><th>شرکت</th><th>تلفن</th><th>مانده</th></tr></thead><tbody><?php foreach($customers as $c):?><tr class="border-b last:border-0"><td class="py-3 font-bold"><?=e($c['name'])?></td><td><?=e($c['company'])?></td><td class="ltr"><?=e($c['phone'])?></td><td><?=number_format((float)$c['balance'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">مشتری جدید</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="customer_save"><input required name="name" placeholder="نام مشتری" class="w-full border rounded-xl p-3"><input name="company" placeholder="شرکت" class="w-full border rounded-xl p-3"><input name="phone" placeholder="شماره تماس" class="w-full border rounded-xl p-3"><input name="email" type="email" placeholder="ایمیل" class="w-full border rounded-xl p-3"><textarea name="address" placeholder="آدرس" class="w-full border rounded-xl p-3"></textarea><textarea name="notes" placeholder="یادداشت" class="w-full border rounded-xl p-3"></textarea><button class="w-full bg-slate-900 text-white rounded-xl py-3">ثبت مشتری</button></form></div></div>
<?php elseif($page==='parts'): ?>
<div class="flex justify-between items-center mb-6"><div><h1 class="text-2xl font-extrabold">انبار و قطعات</h1><p class="text-slate-500 text-sm">موجودی و قیمت قطعات مصرفی</p></div></div>
<div class="grid lg:grid-cols-3 gap-5"><div class="lg:col-span-2 bg-white rounded-2xl border shadow-sm p-5 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-slate-400"><th class="pb-3">Part Number</th><th>نام</th><th>موجودی</th><th>قیمت</th><th>تأمین‌کننده</th></tr></thead><tbody><?php foreach($parts as $p):?><tr class="border-b last:border-0"><td class="py-3 font-bold ltr"><?=$p['part_number']?></td><td><?=e($p['name'])?></td><td class="<?=((float)$p['stock'] <= (float)$p['min_stock'])?'text-red-600 font-bold':''?>"><?=$p['stock']?></td><td><?=number_format((float)$p['unit_price'])?></td><td><?=e($p['supplier'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">ثبت قطعه</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="part_save"><input required name="part_number" placeholder="Part Number" class="w-full border rounded-xl p-3"><input required name="name" placeholder="نام قطعه" class="w-full border rounded-xl p-3"><input name="category" placeholder="دسته‌بندی" class="w-full border rounded-xl p-3"><div class="grid grid-cols-2 gap-2"><input name="stock" type="number" step=".01" placeholder="موجودی" class="border rounded-xl p-3"><input name="min_stock" type="number" step=".01" placeholder="حداقل" class="border rounded-xl p-3"></div><input name="unit_price" type="number" placeholder="قیمت واحد" class="w-full border rounded-xl p-3"><input name="supplier" placeholder="تأمین‌کننده" class="w-full border rounded-xl p-3"><button class="w-full bg-slate-900 text-white rounded-xl py-3">ثبت قطعه</button></form></div></div>
<?php elseif($page==='users'): ?>
<div class="grid lg:grid-cols-3 gap-5"><div class="lg:col-span-2 bg-white rounded-2xl border shadow-sm p-5"><h1 class="text-xl font-extrabold mb-4">کاربران و تکنسین‌ها</h1><table class="w-full text-sm"><thead><tr class="border-b text-slate-400"><th class="pb-3 text-right">نام</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th></tr></thead><tbody><?php foreach($users as $u):?><tr class="border-b last:border-0"><td class="py-3 font-bold"><?=e($u['name'])?></td><td class="ltr"><?=$u['email']?></td><td><?=$u['role']?></td><td><?=$u['is_active']?'فعال':'غیرفعال'?></td></tr><?php endforeach;?></tbody></table></div><div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">کاربر جدید</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="user_save"><input required name="name" placeholder="نام" class="w-full border rounded-xl p-3"><input required type="email" name="email" placeholder="ایمیل" class="w-full border rounded-xl p-3"><input required minlength="8" type="password" name="password" placeholder="رمز عبور" class="w-full border rounded-xl p-3"><select name="role" class="w-full border rounded-xl p-3"><option value="technician">تکنسین</option><option value="reception">پذیرش</option><option value="manager">مدیر</option><option value="admin">مدیر سیستم</option></select><button class="w-full bg-slate-900 text-white rounded-xl py-3">ثبت کاربر</button></form></div></div>
<?php elseif($page==='orders'): ?>
<div class="flex justify-between items-center mb-6"><div><h1 class="text-2xl font-extrabold">سفارش‌های کاری</h1><p class="text-slate-500 text-sm">جستجو و پیگیری سفارش‌ها</p></div><a href="?page=new-order" class="bg-emerald-600 text-white px-4 py-2.5 rounded-xl">+ ثبت کار جدید</a></div>
<div class="bg-white rounded-2xl border shadow-sm p-5 overflow-x-auto"><div class="mb-4"><input id="orderSearch" oninput="filterOrders()" placeholder="جستجو بر اساس کد، مشتری یا عنوان..." class="w-full border rounded-xl p-3"></div><table class="w-full text-sm" id="ordersTable"><thead><tr class="border-b text-slate-400"><th class="pb-3">کد</th><th>مشتری</th><th>عنوان</th><th>تکنسین</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead><tbody><?php foreach($orders as $o):?><tr class="border-b last:border-0"><td class="py-3 font-bold ltr"><?=$o['tracking_code']?></td><td><?=e($o['customer_name'])?></td><td><?=e($o['title'])?></td><td><?=e($o['tech'])?></td><td><span class="px-2 py-1 bg-slate-100 rounded-lg"><?=$o['status']?></span></td><td><?=substr($o['created_at'],0,10)?></td><td><a class="text-blue-600" href="?page=order&id=<?=$o['id']?>">مشاهده</a></td></tr><?php endforeach;?></tbody></table></div>
<?php elseif($page==='order'):
$id=(int)($_GET['id']??0); $st=$pdo->prepare("SELECT w.*,c.name customer_name,c.phone customer_phone,e.name equipment_name,e.model,e.serial_number,u.name tech FROM work_orders w JOIN customers c ON c.id=w.customer_id LEFT JOIN equipment e ON e.id=w.equipment_id LEFT JOIN users u ON u.id=w.assigned_to WHERE w.id=?");$st->execute([$id]);$o=$st->fetch();
$hist=$pdo->prepare("SELECT h.*,u.name FROM work_order_history h LEFT JOIN users u ON u.id=h.user_id WHERE work_order_id=? ORDER BY h.id DESC");$hist->execute([$id]);$history=$hist->fetchAll();
$pay=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE work_order_id=?");$pay->execute([$id]);$paid=(float)$pay->fetchColumn();
if (!$o): ?>
<div class="bg-red-50 p-5 rounded-xl">سفارش پیدا نشد.</div>
<?php else: ?>
<div class="flex flex-col lg:flex-row gap-4 justify-between mb-6"><div><div class="flex items-center gap-3"><h1 class="text-2xl font-extrabold"><?=e($o['title'])?></h1><span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs"><?=$o['status']?></span></div><p class="text-slate-500 mt-1 ltr text-right"><?=e($o['tracking_code'])?></p></div><a href="?page=orders" class="bg-white border px-4 py-2 rounded-xl">بازگشت</a></div>
<div class="grid lg:grid-cols-3 gap-5">
<div class="lg:col-span-2 space-y-5">
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-5">Timeline سفارش</h2><div class="flex gap-2 overflow-x-auto pb-2"><?php foreach($statuses as $s):?><div class="min-w-[120px] text-center"><div class="h-2 rounded-full <?=$s['sort_order'] <= array_search($o['status'],array_column($statuses,'name'))+1?'bg-emerald-500':'bg-slate-200'?>"></div><div class="text-xs mt-2"><?=$s['name']?></div></div><?php endforeach;?></div><form method="post" class="mt-5 flex gap-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="order_status"><input type="hidden" name="id" value="<?=$id?>"><select name="status" class="flex-1 border rounded-xl p-3"><?php foreach($statuses as $s):?><option <?=$s['name']===$o['status']?'selected':''?>><?=$s['name']?></option><?php endforeach;?></select><button class="bg-slate-900 text-white px-5 rounded-xl">به‌روزرسانی</button></form></div>
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">شرح فنی</h2><div class="grid md:grid-cols-2 gap-4"><div><div class="text-xs text-slate-400 mb-1">علائم خرابی</div><div class="bg-slate-50 rounded-xl p-4 min-h-24"><?=nl2br(e($o['problem_description']))?></div></div><div><div class="text-xs text-slate-400 mb-1">توضیحات اولیه</div><div class="bg-slate-50 rounded-xl p-4 min-h-24"><?=nl2br(e($o['initial_notes']))?></div></div></div></div>
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">تاریخچه فعالیت</h2><?php foreach($history as $h):?><div class="flex gap-3 py-3 border-b last:border-0"><div class="w-2 h-2 rounded-full bg-emerald-500 mt-2"></div><div><div class="font-bold text-sm"><?=e($h['action'])?></div><div class="text-xs text-slate-500"><?=e($h['old_value'])?> → <?=e($h['new_value'])?></div><div class="text-xs text-slate-400 mt-1"><?=e($h['name'])?> · <?=$h['created_at']?></div></div></div><?php endforeach;?></div>
</div>
<div class="space-y-5">
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">اطلاعات</h2><div class="space-y-3 text-sm"><div><span class="text-slate-400">مشتری:</span> <?=e($o['customer_name'])?></div><div><span class="text-slate-400">تماس:</span> <?=e($o['customer_phone'])?></div><div><span class="text-slate-400">تجهیز:</span> <?=e($o['equipment_name'])?></div><div><span class="text-slate-400">مدل:</span> <?=e($o['model'])?></div><div><span class="text-slate-400">سریال:</span> <?=e($o['serial_number'])?></div><div><span class="text-slate-400">تکنسین:</span> <?=e($o['tech'])?></div></div></div>
<div class="bg-white rounded-2xl border shadow-sm p-5"><h2 class="font-extrabold mb-4">مالی</h2><div class="flex justify-between py-2"><span>برآورد</span><b><?=number_format((float)$o['estimated_cost'])?></b></div><div class="flex justify-between py-2"><span>بیعانه</span><b><?=number_format((float)$o['deposit'])?></b></div><div class="flex justify-between py-2 border-t mt-2 pt-3"><span>پرداخت ثبت‌شده</span><b class="text-emerald-600"><?=number_format($paid)?></b></div><form method="post" class="mt-4 space-y-2"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="payment_save"><input type="hidden" name="work_order_id" value="<?=$id?>"><input name="amount" type="number" min="0" placeholder="مبلغ پرداخت" class="w-full border rounded-xl p-3"><select name="method" class="w-full border rounded-xl p-3"><option value="card">کارت</option><option value="cash">نقد</option><option value="transfer">انتقال</option><option value="other">سایر</option></select><input name="note" placeholder="یادداشت" class="w-full border rounded-xl p-3"><button class="w-full bg-emerald-600 text-white rounded-xl py-3">ثبت پرداخت</button></form></div>
</div></div>
<?php endif; ?>
<?php endif; ?>

<?php if($page==='reports'): ?>
<div class="mt-6 bg-white rounded-2xl border p-5"><h2 class="font-extrabold mb-4">گزارش اولیه عملکرد</h2><div class="grid md:grid-cols-3 gap-4"><div class="bg-slate-50 p-4 rounded-xl"><div class="text-slate-400 text-sm">کل سفارش‌ها</div><div class="text-2xl font-black"><?=countv($pdo,"SELECT COUNT(*) FROM work_orders")?></div></div><div class="bg-slate-50 p-4 rounded-xl"><div class="text-slate-400 text-sm">کل مشتریان</div><div class="text-2xl font-black"><?=countv($pdo,"SELECT COUNT(*) FROM customers")?></div></div><div class="bg-slate-50 p-4 rounded-xl"><div class="text-slate-400 text-sm">قطعات کم‌موجودی</div><div class="text-2xl font-black"><?=countv($pdo,"SELECT COUNT(*) FROM parts WHERE stock<=min_stock")?></div></div></div></div>
<?php endif; ?>
</section></main>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('translate-x-full');document.getElementById('overlay').classList.toggle('hidden')}
function filterOrders(){const q=document.getElementById('orderSearch').value.toLowerCase();document.querySelectorAll('#ordersTable tbody tr').forEach(r=>r.style.display=r.innerText.toLowerCase().includes(q)?'':'none')}
<?php if($page==='dashboard'): ?>
new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:['در انتظار','در حال تعمیر','آماده تحویل','تحویل شده'],datasets:[{data:[<?=$stats['pending']?>,<?=$stats['repair']?>,<?=$stats['ready']?>,<?=$stats['delivered']?>]}]},options:{plugins:{legend:{position:'bottom'}}}});
<?php endif; ?>
</script>
<script src="/assets/maher-flow-mobile.js"></script></body></html>
