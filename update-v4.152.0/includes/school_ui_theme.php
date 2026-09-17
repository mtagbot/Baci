<?php
/** Derived, readable screen colors. Does not change stored palette or print/export colors. */
function school_ui_luminance($hex) {
    $v=[];foreach([1,3,5] as $i){$c=hexdec(substr($hex,$i,2))/255;$v[]=$c<=.04045?$c/12.92:pow(($c+.055)/1.055,2.4);}
    return .2126*$v[0]+.7152*$v[1]+.0722*$v[2];
}
function school_ui_dark_tone($hex,$ratio=9) {
    for($i=0;$i<30 && 1.05/(school_ui_luminance($hex)+.05)<$ratio;$i++){
        $new='#';foreach([1,3,5] as $p)$new.=str_pad(dechex((int)floor(hexdec(substr($hex,$p,2))*.9)),2,'0',STR_PAD_LEFT);$hex=$new;
    }
    return $hex;
}
function school_ui_theme() {
    $css='<style>@media screen{';
    foreach(app_palette() as $name=>$hex){
        $lum=school_ui_luminance($hex);$ink=($lum+.05)/.05>=7?'#000000':'#ffffff';
        $bg=$ink==='#000000'?$hex:school_ui_dark_tone($hex,7.2);$text=school_ui_dark_tone($hex,9);
        $names=$name==='accent'?['accent','warning']:[$name];
        foreach($names as $n){
            $css.='.school-app .btn-'.$n.'{background:'.$bg.'!important;color:'.$ink.'!important;box-shadow:none!important}.school-app .text-'.$n.'{color:'.$text.'!important}.school-app .badge-'.$n.',.school-app .alert-'.$n.'{color:#14213a!important;border-color:'.$hex.'}';
        }
    }
    return $css.'}</style>';
}
