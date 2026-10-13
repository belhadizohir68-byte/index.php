<?php
// إجبار PHP على إظهار جميع الأخطاء على الشاشة مباشرة لمنع ظهور الصفحة السوداء
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// فحص ما إذا كان الامتداد المطلوب لقاعدة البيانات موجوداً
if (!extension_loaded('pdo_sqlite')) {
    die("خطأ حرج: امتداد pdo_sqlite غير مفعل في خادم الويب (PHP). تأكد من تثبيته في ملف Dockerfile.");
}

// اتصال قاعدة البيانات مع طباعة الخطأ الفعلي إن وجد
try {
    $db_file = __DIR__ . '/tracker.db';$db = new PDO('sqlite:' . $db_file);$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    die("خطأ في إنشاء أو اتصال قاعدة البيانات: " . $e->getMessage());
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
            // تخطي
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
        fputcsv($output, [$row['id'], $row['code'],$row['public_ip'], $row['local_ip'],$row['country'], $row['city'],$row['lat'], $row['lon'],$row['isp'], $row['device_info'],$row['screen_res'], $row['timezone'],$row['created_at']
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
        button, .btn { background: #0284c7; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s; text-decoration: none; display: inline-block; text-align: center; font-size:
