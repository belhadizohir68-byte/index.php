<?php
// إظهار الأخطاء بوضوح على الشاشة للتشخيص الفوري
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// اتصال قاعدة البيانات مع رسالة خطأ واضحة
try {
    $db = new PDO('sqlite:tracker.db');$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT, 
        public_ip TEXT, 
        local_ip TEXT, 
        country TEXT, 
        city TEXT, 
        lat TEXT, 
        lon TEXT, 
        isp TEXT, 
        device_info TEXT, 
        screen_res TEXT, 
        timezone TEXT, 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("<div style='background:#7f1d1d; color:#f87171; padding:20px; font-family:sans-serif; direction:rtl; border-radius:8px; margin:20px;'>
        <h3>❌ خطأ في قاعدة البيانات أو الصلاحيات:</h3>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <p style='margin-top:10px; font-size:13px; color:#fca5a5;'>ملاحظة: تأكد من أن خادم الويب يمتلك صلاحيات الكتابة في مجلد المشروع لإنشاء ملف tracker.db</p>
    </div>");
}

function get_real_ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function parse_isp($isp_name, $org_name) {$combined = $isp_name . ' ' .$org_name;
    if (stripos($combined, 'Mobilis') !== false or stripos($combined, 'ATM') !== false) return 'موبيليس (Mobilis)';
    if (stripos($combined, 'Djezzy') !== false or stripos($combined, 'Optimum Telecom') !== false) return 'جازي (Djezzy)';
    if (stripos($combined, 'Ooredoo') !== false or stripos($combined, 'Wataniya') !== false) return 'أوريدو (Ooredoo)';
    if (stripos($combined, 'Algérie Télécom') !== false or stripos($combined, 'Algerie Telecom') !== false or stripos($combined, 'AT') !== false) return 'اتصالات الجزائر (Algérie Télécom)';
    return trim($isp_name) ?: 'شبكة غير معروفة';
}

function get_device_type($ua) {$brand = 'جهاز ذكي';
    if (stripos($ua, 'Samsung') !== false)$brand = 'سامسونج (Samsung)';
    elseif (stripos($ua, 'iPhone') !== false)$brand = 'آيفون (iPhone)';
    elseif (stripos($ua, 'Huawei') !== false)$brand = 'هواوي (Huawei)';
    elseif (stripos($ua, 'Xiaomi') !== false or stripos($ua, 'Redmi') !== false or stripos($ua, 'POCO') !== false)$brand = 'شاومي (Xiaomi)';
    elseif (stripos($ua, 'Oppo') !== false)$brand = 'أوبو (Oppo)';
    elseif (stripos($ua, 'Vivo') !== false)$brand = 'فيفو (Vivo)';
    elseif (stripos($ua, 'Realme') !== false)$brand = 'ريلمي (Realme)';

    if (stripos($ua, 'Android') !== false) return$brand . ' - أندرويد';
    if (stripos($ua, 'iPhone') !== false or stripos($ua, 'iPad') !== false) return$brand . ' - iOS';
    if (stripos($ua, 'Windows') !== false) return 'حاسوب - ويندوز';
    if (stripos($ua, 'Macintosh') !== false or stripos($ua, 'Mac OS') !== false) return 'حاسوب - ماك';
    if (stripos($ua, 'Linux') !== false) return 'نظام لينكس';
    return 'جهاز غير معروف';
}

function get_ip_details($ip) {
    if ($ip == '127.0.0.1' or $ip == '::1' or$ip == 'Unknown' or strpos($ip, '10.') === 0 or strpos($ip, '192.168.') === 0) {
        return ['country' => 'الجزائر (محلي)', 'city' => 'شبكة داخلية', 'lat' => '36.7538', 'lon' => '3.0588', 'isp' => 'شبكة محلية (Localhost)'];
    }
    $json = @file_get_contents("http://ip-api.com/json/" . $ip . "?fields=status,country,city,lat,lon,isp,org");
    $data = json_decode($json, true);
    if ($data and isset($data['status']) and$data['status'] == 'success') {
        return [
            'country' => $data['country'] ?? 'غير معروف',
            'city' => $data['city'] ?? 'غير معروف',
            'lat' => $data['lat'] ?? '36.7538', 'lon' =>$data['lon'] ?? '3.0588',
            'isp' => parse_isp($data['isp'] ?? '',$data['org'] ?? '')
        ];
    }
    return ['country' => 'غير معروف', 'city' => 'غير معروف', 'lat' => '36.7538', 'lon' => '3.0588', 'isp' => 'غير معروف'];
}

// 1. معالجة إعادة التوجيه
if (isset($_GET['r'])) {
    $code =$_GET['r'];
    $stmt =$db->prepare("SELECT target_url FROM links WHERE code = ?");
    $stmt->execute([$code]);
    $link =$stmt->fetch(PDO::FETCH_ASSOC);

    if ($link) {$target = $link['target_url'];$public_ip = get_real_ip();
        $local_ip =$_GET['lip'] ?? 'غير متوفر';
        $geo = get_ip_details($public_ip);
        $ua =$_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $device = get_device_type($ua);
        $screen =$_GET['screen'] ?? 'غير متوفر';
        $tz =$_GET['tz'] ?? 'غير متوفر';

        try {
            $logStmt =$db->prepare("INSERT INTO logs (code, public_ip, local_ip, country, city, lat, lon, isp, device_info, screen_res, timezone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $logStmt->execute([$code, $public_ip,$local_ip, $geo['country'],$geo['city'], $geo['lat'],$geo['lon'], $geo['isp'],$device, $screen,$tz]);
        } catch (Exception $e) {
            // الاستمرار في حالة خطأ السجل
        }

        header("Location: " . $target);
        exit();
    } else {
        die("الرابط غير موجود.");
    }
}

// 2. إنشاء رابط جديد
$generated_link = "";
$tracking_code = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['create'])) {
    $target_url = trim($_POST['target_url']);
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $target_url = "https://" . $target_url;
    }

    $code = substr(md5(uniqid(rand(), true)), 0, 7);
    $stmt =$db->prepare("INSERT INTO links (code, target_url) VALUES (?, ?)");
    $stmt->execute([$code,$target_url]);

    $tracking_code =$code;
    $domain = $_SERVER['HTTP_HOST'];$generated_link = "https://" . $domain . "/?r=" . $code;
}

$total_links =$db->query("SELECT COUNT(*) FROM links")->fetchColumn();
$total_visits =$db->query("SELECT COUNT(*) FROM logs")->fetchColumn();

$filter_code = trim($_GET['track'] ?? '');

// 3. تصدير البيانات إلى ملف CSV
if (isset($_GET['export']) && $_GET['export'] == 'true') {$filename = !empty($filter_code) ? "report_{$filter_code}.csv" : "all_logs_report.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['ID', 'Code', 'Public IP', 'Local IP', 'Country', 'City', 'Latitude', 'Longitude', 'ISP', 'Device Info', 'Screen Resolution', 'Timezone', 'Created At']);

    if (!empty($filter_code)) {
        $stmt =$db->prepare("SELECT * FROM logs WHERE code = ? ORDER BY id DESC");
        $stmt->execute([$filter_code]);
        $export_logs =$stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $export_logs =$db->query("SELECT * FROM logs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($export_logs as$row) {
        fputcsv($output, [$row['id'], 
            $row['code'],$row['public_ip'], 
            $row['local_ip'],$row['country'], 
            $row['city'],$row['lat'], 
            $row['lon'],$row['isp'], 
            $row['device_info'],$row['screen_res'], 
            $row['timezone'],$row['created_at']
        ]);
    }
    fclose($output);
    exit();
}

if (!empty($filter_code)) {
    $stmt =$db->prepare("SELECT * FROM logs WHERE code = ? ORDER BY id DESC");
    $stmt->execute([$filter_code]);
    $logs =$stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $logs =$db->query("SELECT * FROM logs ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hello World from azez</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background-color: #0b0f19; color: #f3f4f6; padding: 15px; line-height: 1.5; }
        .container { max-width: 1050px; margin: 0 auto; display: flex; flex-direction: column; gap: 15px; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        h1 { color: #38bdf8; font-size: 22px; font-weight: 800; }
        .stats-group { display: flex; gap: 10px; }
        .stat-box { background: #1f2937; padding: 8px 14px; border-radius: 8px; text-align: center; border: 1px solid #374151; }
        .stat-box span { display: block; font-size: 10px; color: #9ca3af; }
        .stat-box strong { font-size: 16px; color: #38bdf8; }
        h2 { color: #38bdf8; font-size: 16px; margin-bottom: 12px; font-weight: 700; }
        .form-group { display: flex; flex-direction: column; gap: 10px; }
        @media(min-width: 600px) { .form-group { flex-direction: row; } }
        input[type="text"] { flex: 1; background: #030712; border: 1px solid #374151; border-radius: 8px; padding: 12px; color: #fff; font-size: 14px; outline: none; width: 100%; }
        input[type="text"]:focus { border-color: #38bdf8; }
        button, .btn { background: #0284c7; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s; text-decoration: none; display: inline-block; text-align: center; font-size: 14px; white-space: nowrap; }
        button:hover, .btn:hover { background: #0369a1; }
        .result-box { background: #030712; border: 1px solid rgba(56, 189, 248, 0.3); padding: 14px; border-radius: 8px; margin-top: 12px; word-break: break-all; }
        .table-container { overflow-x: auto; margin-top: 12px; border: 1px solid #1f2937; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 12px; min-width: 950px; }
        th { background: #030712; color: #9ca3af; padding: 12px; border-bottom: 1px solid #1f2937; white-space: nowrap; }
        td { padding: 12px; border-bottom: 1px solid #1f2937; color: #e5e7eb; white-space: nowrap; }
        tr:hover { background: rgba(31, 41, 55, 0.4); }
        .badge { background: #064e3b; color: #34d399; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid #065f46; }
        .ip-public { font-family: monospace; color: #38bdf8; font-weight: bold; }
        .ip-local { font-family: monospace; color: #34d399; font-weight: bold; }
        .copy-btn { background: #374151; padding: 6px 10px; font-size: 11px; border-radius: 4px; cursor: pointer; color: #fff; border: none; }
        .copy-btn.success { background: #059669; }
        .map-btn { background: #047857; color: #fff; padding: 5px 10px; border-radius: 6px; font-size: 11px; text-decoration: none; font-weight: bold; display: inline-block; }
        .map-btn:hover { background: #065f46; }
        .link-row { display: flex; gap: 8px; margin-top: 6px; align-items: center; }
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const redirectCode = urlParams.get('r');
            const hasScreen = urlParams.has('screen');

            if (redirectCode && !hasScreen) {
                const screenRes = screen.width + "x" + screen.height;
                const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;

                window.location.href = `/?r=${redirectCode}&screen=${encodeURIComponent(screenRes)}&tz=${encodeURIComponent(tz)}`;
            }
        });

        function copyText(text, btnElement) {
            navigator.clipboard.writeText(text).then(() => {
                const original = btnElement.innerText;
                btnElement.innerText = "تم ✓";
                btnElement.classList.add("success");
                setTimeout(() => {
                    btnElement.innerText = original;
                    btnElement.classList.remove("success");
                }, 2000);
            });
        }
    </script>
</head>
<body>
    <div class="container">
        
        <div class="card header">
            <div>
                <h1>Hello World from azez</h1>
                <p style="color: #9ca3af; font-size: 12px; margin-top: 2px;">لوحة تحكم تتبع الروابط المتقدمة</p>
            </div>
            <div class="stats-group">
                <div class="stat-box">
                    <span>الروابط</span>
                    <strong><?php echo $total_links; ?></strong>
                </div>
                <div class="stat-box">
                    <span>النقرات</span>
                    <strong style="color: #34d399;"><?php echo $total_visits; ?></strong>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>🔗 إنشاء رابط تتبع جديد</h2>
            <form method="POST" class="form-group">
                <input type="text" name="target_url" placeholder="أدخل الرابط المستهدف (مثال: youtube.com)" required>
                <button type="submit" name="create">توليد الرابط</button>
            </form>

            <?php if (!empty($generated_link)): ?>
                <div class="result-box">
                    <span style="font-size: 11px; color: #9ca3af;">رابط التتبع النشط:</span>
                    <div class="link-row">
                        <input type="text" readonly value="<?php echo htmlspecialchars($generated_link); ?>">
                        <button type="button" class="copy-btn" style="padding: 10px 14px;" onclick="copyText('<?php echo htmlspecialchars($generated_link); ?>', this)">نسخ الرابط</button>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: 11px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span>كود التتبع: <strong style="color: #34d399; font-size: 13px;"><?php echo htmlspecialchars($tracking_code); ?></strong></span>
                            <button type="button" class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($tracking_code); ?>', this)">نسخ الكود</button>
                        </div>
                        <a href="/?track=<?php echo htmlspecialchars($tracking_code); ?>" style="color: #38bdf8; text-decoration: none;">إحصائيات هذا الرابط ←</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                <h2>📊 سجلات الزيارات الحية</h2>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <form method="GET" style="display: flex; gap: 5px; margin: 0; max-width: 300px;">
                        <input type="text" name="track" placeholder="بحث بالكود..." value="<?php echo htmlspecialchars($filter_code); ?>" style="padding: 8px; font-size: 12px;">
                        <button type="submit" style="padding: 8px 12px; font-size: 12px;">بحث</button>
                        <?php if(!empty($filter_code)): ?>
                            <a href="/" class="btn" style="background: #dc2626; padding: 8px 10px; font-size: 12px; text-decoration:none;">إلغاء</a>
                        <?php endif; ?>
                    </form>
                    
                    <?php 
                        $export_url = "?export=true";
                        if(!empty($filter_code)) {
                            $export_url .= "&track=" . urlencode($filter_code);
                        }
                    ?>
                    <a href="<?php echo $export_url; ?>" class="btn" style="background: #059669; padding: 8px 12px; font-size: 12px; text-decoration:none;">📥 تنزيل السجلات (CSV)</a>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>عنوان IP العام</th>
                            <th>عنوان IP المحلي</th>
                            <th>نوع الجهاز والموديل</th>
                            <th>الموقع الخريطة</th>
                            <th>مزود الخدمة</th>
                            <th>الشاشة والتوقيت</th>
                            <th>الوقت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="9" style="text-align: center; color: #6b7280; padding: 25px;">لا توجد أي زيارات مسجلة حتى الآن.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as$log): ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td>
                                    <span class="badge"><?php echo htmlspecialchars($log['code']); ?></span>
                                    <button type="button" class="copy-btn" style="font-size: 9px; padding: 2px 5px; margin-right: 3px;" onclick="copyText('<?php echo htmlspecialchars($log['code']); ?>', this)">نسخ</button>
                                </td>
                                <td class="ip-public"><?php echo htmlspecialchars($log['public_ip'] ?? $log['ip'] ?? 'N/A'); ?></td>
                                <td class="ip-local"><?php echo htmlspecialchars($log['local_ip'] ?? '192.168.1.X'); ?></td>
                                <td style="color: #fbbf24; font-weight: bold;"><?php echo htmlspecialchars($log['device_info']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['country']); ?> / <?php echo htmlspecialchars($log['city']); ?></strong><br>
                                    <a class="map-btn" href="https://www.google.com/maps?q=<?php echo htmlspecialchars($log['lat']); ?>,<?php echo htmlspecialchars($log['lon']); ?>" target="_blank">📍 خريطة جوجل</a>
                                </td>
                                <td><strong style="color: #38bdf8;"><?php echo htmlspecialchars($log['isp']); ?></strong></td>
                                <td><?php echo htmlspecialchars($log['screen_res']); ?> - <?php echo htmlspecialchars($log['timezone']); ?></td>
                                <td style="color: #9ca3af; white-space: nowrap;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</body>
</html>
