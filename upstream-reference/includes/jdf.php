<?php
/**
 * Jalali (Shamsi) Date Helper Functions
 */

if (!function_exists('jdate')) {
    function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') {
        $T_sec = 0;
        if ($time_zone != 'local') date_default_timezone_set(($time_zone === '') ? 'Asia/Tehran' : $time_zone);
        $ts = $T_sec + (($timestamp === '' || $timestamp === 'now') ? time() : tr_num($timestamp));
        $date = explode('_', date('H_i_j_n_O_P_s_w_Y', $ts));
        list($j_y, $j_m, $j_d) = gregorian_to_jalali($date[8], $date[3], $date[2]);
        $doy = ($j_m < 7) ? (($j_m - 1) * 31) + $j_d - 1 : (($j_m - 7) * 30) + $j_d + 185;
        $kab = (((($j_y % 33) % 4) - 1) == ((int)(($j_y % 33) * 0.05))) ? 1 : 0;
        $sl = strlen($format);
        $out = '';
        for ($i = 0; $i < $sl; $i++) {
            $sub = substr($format, $i, 1);
            if ($sub == '\\') {
                $out .= substr($format, ++$i, 1);
                continue;
            }
            switch ($sub) {
                case 'Y': $out .= $j_y; break;
                case 'y': $out .= substr($j_y, 2, 2); break;
                case 'n': $out .= $j_m; break;
                case 'm': $out .= ($j_m > 9) ? $j_m : '0' . $j_m; break;
                case 'j': $out .= $j_d; break;
                case 'd': $out .= ($j_d > 9) ? $j_d : '0' . $j_d; break;
                case 'H': $out .= $date[0]; break;
                case 'i': $out .= $date[1]; break;
                case 's': $out .= $date[6]; break;
                case 'F':
                    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                    $out .= $months[$j_m - 1];
                    break;
                case 'l':
                    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
                    $out .= $days[$date[7]];
                    break;
                default: $out .= $sub; break;
            }
        }
        return ($tr_num != 'en') ? tr_num($out, 'fa', '.') : $out;
    }
}

if (!function_exists('gregorian_to_jalali')) {
    function gregorian_to_jalali($g_y, $g_m, $g_d) {
        $g_y = (int)$g_y; $g_m = (int)$g_m; $g_d = (int)$g_d;
        $d_4 = $g_y % 4;
        $g_a = [0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $doy_g = $g_a[$g_m] + $g_d;
        if ($d_4 == 0 && $g_m > 2) $doy_g++;
        $d_33 = (int)((($g_y - 16) % 132) * 0.0305);
        $a = ($d_33 == 3 || $d_33 < ($d_4 - 1) || $d_4 == 0) ? 286 : 287;
        $b = (($d_33 == 1 || $d_33 == 2) && ($d_33 == $d_4 || $d_4 == 1)) ? 78 : (($d_33 == 3 && $d_4 == 0) ? 80 : 79);
        if((int)(($g_y - 10) / 63) == 30) { $a = 286; $b = 78; }
        if($doy_g > $b) {
            $jy = $g_y - 621; $doy_j = $doy_g - $b;
        } else {
            $jy = $g_y - 622; $doy_j = $doy_g + $a;
        }
        if($doy_j < 187) {
            $jm = (int)(($doy_j - 1) / 31); $jd = $doy_j - (31 * $jm++);
        } else {
            $jm = (int)(($doy_j - 187) / 30); $jd = $doy_j - 186 - ($jm * 30); $jm += 7;
        }
        return [$jy, $jm, $jd];
    }
}

if (!function_exists('jalali_to_gregorian')) {
    function jalali_to_gregorian($j_y, $j_m, $j_d) {
        $j_y = (int)$j_y; $j_m = (int)$j_m; $j_d = (int)$j_d;
        $d_4 = ($j_y + 1) % 4;
        if($j_m < 7) $doy_j = (($j_m - 1) * 31) + $j_d;
        else $doy_j = (($j_m - 7) * 30) + $j_d + 186;
        $d_33 = (int)((($j_y - 55) % 132) * 0.0305);
        $a = ($d_33 != 3 && $d_4 <= $d_33) ? 287 : 286;
        $b = (($d_33 == 1 || $d_33 == 2) && ($d_33 == $d_4 || $d_4 == 1)) ? 78 : (($d_33 == 3 && $d_4 == 0) ? 80 : 79);
        if((int)(($j_y - 19) / 63) == 20) { $a = 286; $b = 78; }
        if($doy_j <= $a) {
            $gy = $j_y + 621; $gd = $doy_j + $b;
        } else {
            $gy = $j_y + 622; $gd = $doy_j - $a;
        }
        $g_a = [0, 31, (($gy % 4 == 0) ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for($gm = 0; $gm < 13; $gm++) {
            $v = $g_a[$gm];
            if($gd <= $v) break;
            $gd -= $v;
        }
        return [$gy, $gm, $gd];
    }
}

if (!function_exists('tr_num')) {
    function tr_num($str, $mod = 'en', $mf = 'd') {
        $num_a = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $key_a = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return ($mod == 'fa') ? str_replace($num_a, $key_a, $str) : str_replace($key_a, $num_a, $str);
    }
}
