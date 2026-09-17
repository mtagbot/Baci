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
function app_appearance_head() {
    $font=app_font_spec();$bold=app_font_spec(true);$css='<style id="app-appearance">';
    foreach([[$font,'400'],[$bold,'700']] as $item) if($item[0]['url']!=='') {
        $url=str_replace(['<','>'],['%3C','%3E'],json_encode($item[0]['url'],JSON_UNESCAPED_SLASHES));
        $css.='@font-face{font-family:"'.$font['family'].'";src:url('.$url.');font-weight:'.$item[1].';font-display:block}';
    }
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
