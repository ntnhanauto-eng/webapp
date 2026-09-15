<?php
// cron_notify.php - Tự động thông báo thời tiết trước giờ tan học & Lịch học tối
date_default_timezone_set('Asia/Ho_Chi_Minh');

include 'db.php';

// === 1. CẤU HÌNH TELEGRAM ===
define('TELEGRAM_TOKEN', 'ĐIỀN_BOT_TOKEN_VÀO_ĐÂY');
define('TELEGRAM_CHAT_ID', 'ĐIỀN_CHAT_ID_VÀO_ĐÂY');

// Hàm gửi tin nhắn qua Telegram
function sendTelegramMessage($message) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $postData = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// Hàm phụ trợ đổi chuỗi giờ "hh:mm" sang tổng số phút trong ngày
function timeStrToMinutes($timeStr) {
    if (preg_match('/(\d{1,2})[:hH](\d{2})/', $timeStr, $m)) {
        return (int)$m[1] * 60 + (int)$m[2];
    }
    return 0;
}

// Lấy khung giờ mặc định của tiết học nếu không có giờ cụ thể trong ghi chú
function getDefaultTimeRange($buoi, $tiet) {
    $num = (int)preg_replace('/\D/', '', $tiet) ?: 1;
    $b = strtoupper(trim($buoi));
    if (strpos($b, 'SÁNG') !== false || strpos($b, 'SANG') !== false) {
        $times = [
            ['07:15', '08:00'], ['08:05', '08:50'], ['09:05', '09:50'], ['10:00', '10:45'], ['10:50', '11:35']
        ];
        return $times[$num - 1] ?? ['07:30', '11:15'];
    } elseif (strpos($b, 'CHIỀU') !== false || strpos($b, 'CHIEU') !== false) {
        $times = [
            ['13:30', '14:15'], ['14:20', '15:05'], ['15:20', '16:05'], ['16:10', '16:55'], ['17:00', '17:45']
        ];
        return $times[$num - 1] ?? ['13:30', '17:00'];
    }
    return ['18:00', '19:30'];
}

// Lấy thời gian bắt đầu và kết thúc của một tiết học từ cơ sở dữ liệu
function parseSlotTime($item) {
    $customText = ($item['note'] ?? '') . ' ' . ($item['subject'] ?? '');
    if (preg_match('/(\d{1,2})[:hH](\d{2})\s*[~-]\s*(\d{1,2})[:hH](\d{2})/', $customText, $m)) {
        return ['start' => timeStrToMinutes($m[1].':'.$m[2]), 'end' => timeStrToMinutes($m[3].:$m[4])];
    }
    if (preg_match('/(\d{1,2})[:hH](\d{2})\s*[~-]\s*(\d{1,2})[:hH](\d{2})/', $item['tiet'] ?? '', $m)) {
        return ['start' => timeStrToMinutes($m[1].':'.$m[2]), 'end' => timeStrToMinutes($m[3].:$m[4])];
    }
    $def = getDefaultTimeRange($item['buoi'] ?? '', $item['tiet'] ?? '');
    return ['start' => timeStrToMinutes($def[0]), 'end' => timeStrToMinutes($def[1])];
}

// Lấy cấu hình ứng dụng
$settings = [
    'kid1_name' => 'Trâm Anh', 'kid1_class' => '6A3',
    'kid2_name' => 'Thành Phát', 'kid2_class' => '10A4'
];
try {
    $st_set = $conn->query("SELECT setting_key, setting_val FROM app_settings");
    if ($st_set) {
        $settings = array_merge($settings, $st_set->fetchAll(PDO::FETCH_KEY_PAIR));
    }
} catch (Exception $e) {}

$mode = $_GET['type'] ?? '';
$now_minutes = (int)date('G') * 60 + (int)date('i');
$today_w = (int)date('w');
$today_field = ($today_w == 0) ? 'cn' : 'thu' . ($today_w + 1);

// Lấy dữ liệu thời tiết Ninh Hòa qua API Open-Meteo
function fetchNinhHoaWeather($targetHour) {
    try {
        $res = @file_get_contents('https://api.open-meteo.com/v1/forecast?latitude=12.5000&longitude=109.1333&hourly=temperature_2m,precipitation,precipitation_probability&timezone=Asia%2FBangkok');
        if ($res) {
            $wData = json_decode($res, true);
            if (isset($wData['hourly']['time'])) {
                foreach ($wData['hourly']['time'] as $idx => $tStr) {
                    $tTime = strtotime($tStr);
                    if (date('d', $tTime) == date('d') && (int)date('G', $tTime) == $targetHour) {
                        $precip = $wData['hourly']['precipitation'][$idx] ?? 0;
                        $prob = $wData['hourly']['precipitation_probability'][$idx] ?? 0;
                        $temp = round($wData['hourly']['temperature_2m'][$idx] ?? 30);
                        
                        if ($precip > 0.2 || $prob > 60) {
                            return "⚠️ <b>Có mưa hoặc khả năng mưa cao ({$temp}°C)</b>. Ba mẹ nhớ chuẩn bị <b>áo mưa/ô</b> cho con nhé!";
                        } elseif ($temp >= 33) {
                            return "☀️ <b>Trời nắng gắt ({$temp}°C)</b>. Nhắc con đội <b>nón/mũ</b> che nắng khi ra về!";
                        } else {
                            return "🌤️ Thời tiết dịu mát (${temp}°C), thuận lợi đón con.";
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {}
    return "🌤️ Thời tiết ổn định, thuận lợi đón con.";
}

// --- 1. XỬ LÝ NHẮC NHỞ TRƯỚC GIỜ TAN HỌC 60 PHÚT ---
if ($mode === 'check_dismissal') {
    $stmt = $conn->query("SELECT * FROM schedule");
    $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $morning_dismiss_min = 0;
    $afternoon_dismiss_min = 0;

    foreach ($all_rows as $row) {
        $content = trim($row[$today_field] ?? '');
        if (empty($content) || $content === '-') continue;

        $slot = parseSlotTime($row);
        $buoi = strtoupper($row['buoi']);

        if (strpos($buoi, 'SÁNG') !== false || strpos($buoi, 'SANG') !== false) {
            if ($slot['end'] > $morning_dismiss_min) $morning_dismiss_min = $slot['end'];
        } elseif (strpos($buoi, 'CHIỀU') !== false || strpos($buoi, 'CHIEU') !== false) {
            if ($slot['end'] > $afternoon_dismiss_min) $afternoon_dismiss_min = $slot['end'];
        }
    }

    // Kiểm tra nếu hiện tại đang đúng trước giờ tan học sáng 60 phút (cho phép sai số trong vòng 5 phút)
    if ($morning_dismiss_min > 0 && abs($now_minutes - ($morning_dismiss_min - 60)) <= 5) {
        $dismiss_time_str = sprintf('%02d:%02d', floor($morning_dismiss_min / 60), $morning_dismiss_min % 60);
        $weather_msg = fetchNinhHoaWeather(floor($morning_dismiss_min / 60));
        
        $msg = "⏰ <b>NHẮC CHUẨN BỊ ĐÓN CON TAN HỌC SÁNG</b>\n";
        $msg .= "⏱️ Dự kiến tan trường lúc: <b>{$dismiss_time_str}</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📍 <b>Thời tiết Ninh Hòa:</b> {$weather_msg}";
        
        sendTelegramMessage($msg);
        echo "Đã gửi thông báo trước giờ tan học sáng.";
        exit;
    }

    // Kiểm tra nếu hiện tại đang đúng trước giờ tan học chiều 60 phút
    if ($afternoon_dismiss_min > 0 && abs($now_minutes - ($afternoon_dismiss_min - 60)) <= 5) {
        $dismiss_time_str = sprintf('%02d:%02d', floor($afternoon_dismiss_min / 60), $afternoon_dismiss_min % 60);
        $weather_msg = fetchNinhHoaWeather(floor($afternoon_dismiss_min / 60));
        
        $msg = "⏰ <b>NHẮC CHUẨN BỊ ĐÓN CON TAN HỌC CHIỀU</b>\n";
        $msg .= "⏱️ Dự kiến tan trường lúc: <b>{$dismiss_time_str}</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "📍 <b>Thời tiết Ninh Hòa:</b> {$weather_msg}";
        
        sendTelegramMessage($msg);
        echo "Đã gửi thông báo trước giờ tan học chiều.";
        exit;
    }

    echo "Chưa đến khung giờ cần nhắc nhở tan học.";
}

// --- 2. XỬ LÝ LỊCH HỌC NGÀY HÔM SAU (LÚC 19H TỐI) ---
elseif ($mode === 'evening') {
    $tomorrow_ts = strtotime('+1 day');
    $tomorrow_w = (int)date('w', $tomorrow_ts);
    $target_day = ($tomorrow_w == 0) ? 'cn' : 'thu' . ($tomorrow_w + 1);
    
    $day_names = [
        'thu2' => 'Thứ Hai', 'thu3' => 'Thứ Ba', 'thu4' => 'Thứ Tư',
        'thu5' => 'Thứ Năm', 'thu6' => 'Thứ Sáu', 'thu7' => 'Thứ Bảy', 'cn' => 'Chủ Nhật'
    ];

    $msg = "🌙 <b>LỊCH HỌC NGÀY HÔM SAU</b>\n";
    $msg .= "📅 " . $day_names[$target_day] . " (" . date('d/m/Y', $tomorrow_ts) . ")\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━\n\n";

    $stmt = $conn->query("SELECT * FROM schedule ORDER BY FIELD(buoi, 'SÁNG', 'CHIỀU', 'TỐI'), id ASC");
    $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kids = [
        '1' => ['name' => $settings['kid1_name'], 'class' => $settings['kid1_class'], 'icon' => '👧'],
        '2' => ['name' => $settings['kid2_name'], 'class' => $settings['kid2_class'], 'icon' => '👦']
    ];

    foreach ($kids as $kId => $kInfo) {
        $msg .= "{$kInfo['icon']} <b>Bé {$kInfo['name']} ({$kInfo['class']}):</b>\n";
        $has_subject = false;

        foreach ($all_rows as $row) {
            if ((string)$row['be_name'] !== (string)$kId) continue;
            
            $content = trim($row[$target_day] ?? '');
            if (!empty($content) && $content !== '-') {
                $has_subject = true;
                $lines = array_values(array_filter(explode("\n", str_replace("\r", "", $content))));
                $subject = $lines[0] ?? '';
                $note = isset($lines[1]) ? implode(' ', array_slice($lines, 1)) : '';
                
                $msg .= "  ▫️ [{$row['buoi']} - {$row['tiet']}] <b>{$subject}</b>";
                if ($note) $msg .= " <i>({$note})</i>";
                $msg .= "\n";
            }
        }

        if (!$has_subject) {
            $msg .= "  🎉 Ngày mai không có lịch học!\n";
        }
        $msg .= "\n";
    }

    $msg .= "👉 Ba mẹ nhắc các bé soạn sách vở đầy đủ nhé!";
    sendTelegramMessage($msg);
    echo "Đã gửi thông báo lịch học ngày mai thành công.";
} else {
    echo "Lỗi: Tham số type không hợp lệ. Sử dụng ?type=check_dismissal hoặc ?type=evening";
}
?>
