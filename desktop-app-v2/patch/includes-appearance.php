<?php
/** Shared local font and four-color palette for UI and report exports. */
function app_font_spec($bold=false) {
    $family=get_setting('font_family','Vazirmatn');
    $fonts=['Vazirmatn'=>'uploads/Vazirmatn/Vazirmatn-'.($bold?'Bold':'Regular').'.ttf','Sahel'=>'uploads/Sahel/Sahel.ttf','Yekan'=>'uploads/Yekan/Yekan.ttf','Tahoma'=>'','CustomUploadedFont'=>get_setting('custom_font_url','')];
    if(!array_key_exists($family,$fonts))$family='Vazirmatn';
    $url=$fonts[$family]; $root=realpath(dirname(__DIR__));$path=$url!==''?realpath($root.'/'.$url):false;
    if($url!=='' && (!$path || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/uploads/') || !preg_match('/\.(ttf|otf|woff2?)$/i',$path))) {
        $family='Vazirmatn';$url=$fonts[$family];$path=realpath($root.'/'.$url);
    }
    return ['family'=>$family,'url'=>$url,'path'=>$path?:null];
}
function app_color($value,$fallback) { return is_string($value)&&preg_match('/^#[0-9a-fA-F]{6}$/D',$value)?strtolower($value):$fallback; }
function app_palette() {
    return ['primary'=>app_color(get_setting('theme_color','#2563eb'),'#2563eb'),'accent'=>app_color(get_setting('accent_color','#d97706'),'#d97706'),'success'=>app_color(get_setting('success_color','#15803d'),'#15803d'),'danger'=>app_color(get_setting('danger_color','#b91c1c'),'#b91c1c')];
}
function app_color_ink($hex) {
    $c=[];foreach([1,3,5] as $i){$x=hexdec(substr($hex,$i,2))/255;$c[]=$x<=.04045?$x/12.92:pow(($x+.055)/1.055,2.4);}
    $l=.2126*$c[0]+.7152*$c[1]+.0722*$c[2];return ($l+.05)/.05>1.05/($l+.05)?'#000000':'#ffffff';
}
/**
 * V4.208.0 — هش فایل با حافظهٔ ماندگار.
 * پیش از این، هر بار باز شدن صفحه، sha256 فونت (۳۵ تا ۱۰۰ کیلوبایت) دوباره
 * محاسبه می‌شد؛ هم برای preload و هم برای @font-face. نتیجه در
 * config/.state نگه داشته می‌شود و با تغییر mtime/size باطل می‌گردد.
 */
function app_file_sha256($path) {
    if(!$path||!is_file($path))return '';
    static $memo=[];
    $stamp=@filesize($path).':'.@filemtime($path);
    if(isset($memo[$path])&&$memo[$path][0]===$stamp)return $memo[$path][1];
    $hash='';
    if(function_exists('perf_state_dir')&&($dir=perf_state_dir())){
        $cache=$dir.'/font-'.sha1($path).'.sha';
        if(is_file($cache)){ $raw=@file_get_contents($cache); if(is_string($raw)&&strpos($raw,'|')!==false){ [$cStamp,$cHash]=explode('|',$raw,2); if($cStamp===$stamp&&preg_match('/^[a-f0-9]{64}$/',$cHash))$hash=$cHash; } }
        if($hash===''){ $hash=@hash_file('sha256',$path)?:''; if($hash!=='')@file_put_contents($cache,$stamp.'|'.$hash); }
    }
    if($hash==='')$hash=@hash_file('sha256',$path)?:'';
    $memo[$path]=[$stamp,$hash];
    return $hash;
}

/** Prefer compressed equivalents only when the installed source font is unchanged. */
function app_screen_font_spec($bold=false) {
    $spec=app_font_spec($bold);
    if($spec['family']==='CustomUploadedFont')return $spec;
    $manifest=__DIR__.'/../assets/fonts/screen/manifest.json';
    static $map=null,$verified=[];
    if($map===null)$map=is_file($manifest)?(json_decode(file_get_contents($manifest),true)?:[]):[];
    if(isset($map[$spec['url']])&&$spec['path']){
        $item=$map[$spec['url']];$key=$spec['path'];
        if(!array_key_exists($key,$verified))$verified[$key]=($item['sha256']==='')||hash_equals($item['sha256'],app_file_sha256($key));
        if($verified[$key]&&is_file(dirname(__DIR__).'/'.$item['url']))$spec['url']=$item['url'];
    }
    return $spec;
}
function app_screen_font_preload() {
    $out='';$seen=[];
    foreach([false,true] as $bold){$font=app_screen_font_spec($bold);$url=$font['url'];if($url===''||isset($seen[$url]))continue;$seen[$url]=true;
        $ext=strtolower(pathinfo($url,PATHINFO_EXTENSION));$mime=['woff2'=>'font/woff2','woff'=>'font/woff','ttf'=>'font/ttf','otf'=>'font/otf'][$ext]??'font/ttf';
        $out.='<link rel="preload" as="font" type="'.$mime.'" crossorigin href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'">';
    }
    return $out;
}

/**
 * V4.208.0 — خنثی‌کردن اعلان‌های قدیمیِ @font-face در assets/css/style.css.
 *
 * style.css برای چهار خانواده (Vazirmatn/Sahel/Yekan) مسیر
 * «uploads/<Family>/<File>.woff2» را اعلام می‌کند. روی نصب‌هایی که فایل
 * فشرده را ندارد، مرورگر همان مسیر را می‌خواهد و چون فایل نیست، درخواست به
 * PHP می‌رسد و کل برنامه برای یک ۴۰۴ اجرا می‌شود (در trace واقعی: ۷۲۵
 * میلی‌ثانیه). حالا برای هر خانواده‌ای که نسخهٔ WOFF2 بستهٔ ما موجود است،
 * یک @font-face «آخر» اعلام می‌کنیم؛ چون آخرین اعلان برنده است، هیچ درخواستی
 * به مسیر ناموجود نمی‌رود. اگر فایل موجود نباشد، هیچ اعلانی اضافه نمی‌شود و
 * رفتار دقیقاً همان قبل است.
 */
function app_legacy_font_faces($skipFamily = '') {
    $dir = dirname(__DIR__) . '/assets/fonts/screen/';
    /* V4.209.0 — وزن‌ها «دقیق» اعلام می‌شوند (۴۰۰ و ۷۰۰)، نه بازهٔ ۱۰۰..۹۰۰.
       دلیل: در style.css همین خانواده‌ها با وزن دقیق ۴۰۰ اعلام شده‌اند و
       مرورگر آن اعلان را ترجیح می‌داد؛ نتیجه دو درخواست مرده به
       uploads/Yekan/Yekan.woff2 (۴۰۴) و .ttf بود. با وزن دقیق و اعلانِ
       بعدی، همین قاعده برنده است و هیچ درخواستی به مسیر ناموجود نمی‌رود.
       خانوادهٔ فعال هم دیگر مستثنا نمی‌شود تا در همهٔ حالت‌ها اعلان ما آخر
       باشد. */
    $map = [
        'Yekan'      => [['Yekan.woff2', '400'], ['Yekan.woff2', '700']],
        'Sahel'      => [['Sahel.woff2', '400'], ['Sahel.woff2', '700']],
        'Vazirmatn'  => [['Vazirmatn-Regular.woff2', '400'], ['Vazirmatn-Bold.woff2', '700']],
    ];
    $css = '';
    foreach ($map as $family => $faces) {
        foreach ($faces as $face) {
            [$file, $weight] = $face;
            if (!is_file($dir . $file)) continue;
            $css .= '@font-face{font-family:"' . $family . '";src:url("assets/fonts/screen/' . $file
                 . '") format("woff2");font-weight:' . $weight . ';font-style:normal;font-display:swap}';
        }
    }
    return $css;
}

/**
 * V4.209.0 — قاعدهٔ @font-face برای یک خانواده، بر پایهٔ فایلی که واقعاً هست.
 *
 * صفحات چاپ/سند (report-print، exam-print، bulk-print) و style.css قبلاً
 * مسیر «uploads/<Family>/<File>.woff2» را اعلام می‌کردند که روی بیشتر نصب‌ها
 * وجود ندارد؛ آن ۴۰۴ در nginx به PHP می‌رسید و کل برنامه برای یک فونت اجرا
 * می‌شد. این تابع همیشه فایل موجود را انتخاب می‌کند و اگر هیچ فایلی نبود،
 * رشتهٔ خالی برمی‌گرداند (یعنی هیچ درخواست مرده‌ای ساخته نمی‌شود).
 *
 * @param string $family Yekan | Sahel | Vazirmatn | BTitr
 * @param string $weight وزن اعلان ('' یعنی پیش‌فرض)
 * @param bool   $bold   استفاده از فایل Bold در صورت وجود
 * @param string $prefer 'print' → TTF کامل (پوشش همهٔ حروف)، 'screen' → WOFF2 سبک‌تر
 */
function app_font_face_rule($family, $weight = '', $bold = false, $prefer = 'screen') {
    $root = dirname(__DIR__);
    $candidates = [];
    $ttf = [
        'Yekan' => 'uploads/Yekan/Yekan.ttf',
        'Sahel' => 'uploads/Sahel/Sahel.ttf',
        'Vazirmatn' => $bold ? 'uploads/Vazirmatn/Vazirmatn-Bold.ttf' : 'uploads/Vazirmatn/Vazirmatn-Regular.ttf',
        'BTitr' => 'uploads/B-Titr/B-Titr.ttf',
    ];
    $woff2 = [
        'Yekan' => 'assets/fonts/screen/Yekan.woff2',
        'Sahel' => 'assets/fonts/screen/Sahel.woff2',
        'Vazirmatn' => $bold ? 'assets/fonts/screen/Vazirmatn-Bold.woff2' : 'assets/fonts/screen/Vazirmatn-Regular.woff2',
    ];
    if ($prefer === 'print') {
        if (isset($ttf[$family])) $candidates[] = [$ttf[$family], 'truetype'];
        if (isset($woff2[$family])) $candidates[] = [$woff2[$family], 'woff2'];
    } else {
        if (isset($woff2[$family])) $candidates[] = [$woff2[$family], 'woff2'];
        if (isset($ttf[$family])) $candidates[] = [$ttf[$family], 'truetype'];
    }
    foreach ($candidates as $item) {
        if (!is_file($root . '/' . $item[0])) continue;
        return '@font-face{font-family:"' . $family . '";src:url("' . $item[0] . '") format("' . $item[1] . '");'
             . ($weight !== '' ? 'font-weight:' . $weight . ';' : '') . 'font-style:normal;font-display:swap}';
    }
    return '';
}

function app_appearance_head($screen=false) {
    $font=$screen?app_screen_font_spec():app_font_spec();$bold=$screen?app_screen_font_spec(true):app_font_spec(true);$css='<style id="app-appearance">';
    /* V4.208.0 — یک @font-face برای همهٔ وزن‌ها (font-weight: 100 900).
       دلیل: پیش از این فقط وزن ۴۰۰ و ۷۰۰ اعلام می‌شد؛ متن‌های وزن ۵۰۰/۶۰۰
       به @font-face قدیمیِ style.css می‌افتادند که به فایلِ ناموجودِ
       uploads/Yekan/Yekan.woff2 اشاره می‌کرد. آن درخواست ۴۰۴ به PHP می‌رسید
       و کل برنامه را اجرا می‌کرد (در trace: ۷۲۵ میلی‌ثانیه برای یک فونت).
       حالا هر وزن با همین یک قلم پاسخ داده می‌شود و هیچ درخواست مرده‌ای
       ساخته نمی‌شود. */
    /* اگر فایل «معمولی» و «ضخیم» یکی باشد (مثل Yekan که فقط یک وزن دارد)
       یک قلم با بازهٔ وزن ۱۰۰..۹۰۰ اعلام می‌شود تا هیچ وزنی به اعلان قدیمیِ
       style.css (با فایل ناموجود) نرسد. اگر دو فایل جدا باشند، همان دو وزن
       ۴۰۰ و ۷۰۰ اعلام می‌شوند تا متن ضخیم از فایل Bold استفاده کند. */
    $faces=[];
    if($font['url']!=='' && $font['url']===$bold['url']){
        $faces[]=[$font,'100 900'];
    } else {
        if($font['url']!=='')$faces[]=[$font,'400'];
        if($bold['url']!=='' && $bold['url']!==$font['url'])$faces[]=[$bold,'700'];
    }
    foreach($faces as $item) { $spec=$item[0]; $wt=$item[1];
        $url=str_replace(['<','>'],['%3C','%3E'],json_encode($spec['url'],JSON_UNESCAPED_SLASHES));
        $src='url('.$url.')';
        if(preg_match('/\.woff2$/i',$spec['url']))$src.=' format("woff2")';
        elseif(preg_match('/\.woff$/i',$spec['url']))$src.=' format("woff")';
        $css.='@font-face{font-family:"'.$font['family'].'";src:'.$src.';font-weight:'.$wt.';font-style:normal;font-display:'.($screen?'swap':'block').'}';
    }
    /* V4.208.0 — خانواده‌های دیگر را هم از فایل‌های موجود اعلام کن (نه مسیر ناموجود) */
    $css.=app_legacy_font_faces();
    $css.=':root{--app-font:"'.$font['family'].'",Tahoma,sans-serif;';
    foreach(app_palette() as $name=>$color){$rgb=implode(',',[hexdec(substr($color,1,2)),hexdec(substr($color,3,2)),hexdec(substr($color,5,2))]);$css.='--'.$name.':'.$color.'!important;--'.$name.'-ink:'.app_color_ink($color).';--'.$name.'-soft:rgba('.$rgb.',.12)!important;';}
    $css.='}body,body *{font-family:var(--app-font)!important}body .fa{font-family:var(--fa-style-family,FontAwesome)!important}body .fas,body .far{font-family:var(--fa-style-family,"Font Awesome 5 Free")!important}body .fab{font-family:"Font Awesome 5 Brands"!important}body .material-icons{font-family:"Material Icons"!important}.auth-captcha[hidden]{display:none!important}';
    foreach(['primary'=>'primary','accent'=>'accent','success'=>'success','danger'=>'danger'] as $cls=>$color){$css.='.btn-'.$cls.'{background:var(--'.$color.')!important;color:var(--'.$color.'-ink)!important}.btn-'.$cls.':hover{filter:brightness(.92)}.text-'.$cls.'{color:var(--'.$color.')!important}.badge-'.$cls.',.alert-'.$cls.'{background:var(--'.$color.'-soft)!important;color:var(--'.$color.')!important}';}
    $css.='.q-content *,.rich-editor *{font-family:inherit!important}.math-token,.math-token *{font-family:Cambria Math,Times New Roman,serif!important}';
    $css.='.text-green-600,.text-green-700{color:var(--success)!important}.text-red-500,.text-red-600,.text-red-700{color:var(--danger)!important}.bg-green-100{background:var(--success-soft)!important}.bg-red-100{background:var(--danger-soft)!important}</style>';
    return $css.'<script>document.addEventListener("DOMContentLoaded",function(){if(window.Chart){Chart.defaults.font.family=getComputedStyle(document.body).fontFamily;if(document.fonts)document.fonts.ready.then(function(){Object.keys(Chart.instances).forEach(function(k){Chart.instances[k].update();});});}});window.appPrint=function(){return (document.fonts?document.fonts.ready:Promise.resolve()).then(function(){var f=getComputedStyle(document.body).fontFamily.split(",")[0];var faces=document.fonts?Array.from(document.fonts).filter(function(face){return face.family===f.replace(/"/g,"").trim();}):[];if(faces.length&&!faces.some(function(face){return face.status==="loaded";})){alert("قلم انتخابی بارگذاری نشد؛ پیش از چاپ، فایل قلم و اتصال را بررسی کنید.");return false;}if(window.fitCustomCards)fitCustomCards(document);window.print();});};</script>';
}
/** Exact TTF embedding, generated in a private writable cache, never silently substitute. */
function app_pdf_font($bold=false) {
    $font=app_font_spec($bold);
    if(!$font['path'] || strtolower(pathinfo($font['path'],PATHINFO_EXTENSION))!=='ttf')return null;
    $dir=dirname(__DIR__).'/backups/font-cache/';
    if(!is_dir($dir) && !mkdir($dir,0700,true))throw new RuntimeException('پوشهٔ کش فونت قابل نوشتن نیست.');
    $cache=$dir.hash_file('sha256',$font['path']).'/';
    if(!is_dir($cache) && !mkdir($cache,0700,true))throw new RuntimeException('ساخت کش فونت ممکن نشد.');
    $lock=fopen($cache.'.lock','c');if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('قفل کش فونت ممکن نشد.');
    try {
        $name=TCPDF_FONTS::addTTFfont($font['path'],'TrueTypeUnicode','',32,$cache);
        if(!$name || !is_file($cache.$name.'.php'))throw new RuntimeException('فونت انتخابی برای PDF قابل تبدیل نیست؛ از چاپ مرورگر استفاده کنید.');
        return ['name'=>$name,'file'=>$cache.$name.'.php'];
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

/** Spreadsheet font family is the real TTF name, not a browser-only CSS alias. */
function app_export_font_family() {
    $spec=app_font_spec();if($spec['family']!=='CustomUploadedFont')return $spec['family'];
    if(!$spec['path'])return $spec['family'];$b=file_get_contents($spec['path']);
    $u16=function($p)use($b){return strlen($b)>=$p+2?unpack('n',substr($b,$p,2))[1]:0;};
    $u32=function($p)use($b){return strlen($b)>=$p+4?unpack('N',substr($b,$p,4))[1]:0;};
    for($i=0;$i<min(128,$u16(4));$i++){
        $p=12+16*$i;if(substr($b,$p,4)!=='name')continue;$off=$u32($p+8);$base=$off+$u16($off+4);
        for($j=0;$j<min(256,$u16($off+2));$j++){
            $r=$off+6+12*$j;if($u16($r+6)!==1||!in_array($u16($r),[0,3],true))continue;
            $name=mb_convert_encoding(substr($b,$base+$u16($r+10),$u16($r+8)),'UTF-8','UTF-16BE');
            if($name!==''&&mb_strlen($name)<100)return $name;
        }
    }
    return $spec['family'];
}
