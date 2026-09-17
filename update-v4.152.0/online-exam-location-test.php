<?php
// File: online-exam-location-test.php - Standalone GPS diagnostic for Iran compatibility
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-3xl mx-auto space-y-4">
    <div class="card">
        <h2 class="text-lg font-bold"><svg data-ui-icon="location" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Z"/><circle cx="12" cy="9" r="3"/></svg> تست موقعیت مکانی - عیب‌یابی GPS (سازگار با ایران)</h2>
        <p class="text-xs text-muted">این صفحه بدون نیاز به آزمون، فقط GPS دستگاه شما را تست می‌کند تا بفهمیم چرا تایید نمی‌شود</p>
    </div>

    <div class="card space-y-3">
        <div id="infoBox" class="p-3 bg-blue-50 border rounded text-xs space-y-1">
            <div><svg data-ui-icon="lock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/></svg> HTTPS: <span id="httpsStatus"></span></div>
            <div><svg data-ui-icon="globe" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><ellipse cx="12" cy="12" rx="4" ry="10"/><path d="M2 12h20"/></svg> UserAgent: <span id="uaStatus"></span></div>
            <div><svg data-ui-icon="device" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg> Platform: <span id="platformStatus"></span></div>
            <div><svg data-ui-icon="location" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 22S4 13 4 9a8 8 0 0 1 16 0c0 4-8 13-8 13Z"/><circle cx="12" cy="9" r="3"/></svg> Geolocation موجود: <span id="geoExist"></span></div>
            <div><svg data-ui-icon="lock" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V6c0-6 10-6 10 0v4M12 15v3"/></svg> Permission API موجود: <span id="permExist"></span></div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button onclick="testLow()" class="btn btn-primary text-xs"><svg data-ui-icon="network" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 8c6-6 14-6 20 0M5 12c4-4 10-4 14 0M8 16c2-2 6-2 8 0M12 20h.1"/></svg> تست حالت کم‌دقت (شبکه - بدون ماهواره - پیشنهادی ایران)</button>
            <button onclick="testBalanced()" class="btn btn-secondary text-xs"><svg data-ui-icon="network" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 8c6-6 14-6 20 0M5 12c4-4 10-4 14 0M8 16c2-2 6-2 8 0M12 20h.1"/></svg> تست متعادل (شبکه + GPS)</button>
            <button onclick="testHigh()" class="btn btn-warning text-xs"><svg data-ui-icon="network" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 8c6-6 14-6 20 0M5 12c4-4 10-4 14 0M8 16c2-2 6-2 8 0M12 20h.1"/></svg> تست دقیق GPS ماهواره‌ای</button>
            <button onclick="testWatch()" class="btn btn-success text-xs"><svg data-ui-icon="eye" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12c5-10 15-10 20 0-5 10-15 10-20 0Z"/><circle cx="12" cy="12" r="3"/></svg> تست WatchPosition (مداوم)</button>
        </div>

        <div id="resultBox" class="p-3 border rounded bg-slate-50 text-xs min-h-[200px] max-h-[400px] overflow-auto">
            روی یکی از دکمه‌های بالا کلیک کنید...
        </div>

        <div class="p-3 bg-amber-50 border border-amber-200 rounded text-xs">
            <b>راهنمای رفع خطای Timeout expired در ایران:</b>
            <ul class="list-disc pr-4 mt-1 space-y-1">
                <li>1. سایت شما حتما باید <b>https://</b> باشد - در http موقعیت کار نمی‌کند</li>
                <li>2. در گوشی: Settings → Location → ON + Mode: High Accuracy</li>
                <li>3. در مرورگر کروم: روی قفل کنار آدرس → Site settings → Location → Allow</li>
                <li>4. اگر داخل ساختمان هستید، بروید نزدیک پنجره یا فضای باز</li>
                <li>5. در گوشی‌های ایرانی، حالت <b>کم‌دقت (شبکه)</b> را تست کنید - بدون ماهواره خارجی کار می‌کند</li>
                <li>6. VPN را خاموش کنید - VPN موقعیت را خراب می‌کند</li>
                <li>7. اگر دسکتاپ (کامپیوتر) دارید، GPS ندارد و باید حالت کم‌دقت یا IP استفاده کنید</li>
                <li>8. در تنظیمات آزمون، تیک <b>IP Fallback</b> و حالت <b>کم‌دقت</b> را فعال کنید</li>
            </ul>
        </div>

        <div id="ipBox" class="p-3 border rounded bg-indigo-50 text-xs">
            <b>موقعیت تقریبی بر اساس IP (Fallback سازگار ایران):</b>
            <div id="ipInfo">در حال دریافت...</div>
        </div>
    </div>
</div>

<script>
function log(msg, isError=false){
    let box = document.getElementById('resultBox');
    let div = document.createElement('div');
    div.style.padding='4px'; div.style.borderBottom='1px solid #eee';
    if(isError) div.style.color='red'; else div.style.color='#333';
    div.innerHTML = new Date().toLocaleTimeString('fa-IR') + ' - ' + msg;
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
}

document.addEventListener('DOMContentLoaded', function(){
    document.getElementById('httpsStatus').textContent = location.protocol + (location.protocol==='https:' ? ' ✅ اوکی' : ' ❌ باید https باشد');
    document.getElementById('uaStatus').textContent = navigator.userAgent.substring(0,80);
    document.getElementById('platformStatus').textContent = navigator.platform;
    document.getElementById('geoExist').textContent = !!navigator.geolocation ? '✅ موجود' : '❌ موجود نیست';
    document.getElementById('permExist').textContent = !!navigator.permissions ? '✅ موجود' : '❌ نیست';

    // Check permission state if available
    if(navigator.permissions){
        navigator.permissions.query({name:'geolocation'}).then(r=>{
            log('Permission state: '+r.state);
            r.onchange = ()=>{ log('Permission changed to: '+r.state); };
        }).catch(e=>{ log('Permission query error: '+e.message, true); });
    }

    // IP fallback test
    fetch('online-exam-api.php?action=heartbeat&exam_id=0&attempt_id=0', {method:'POST', body: new FormData()}).catch(()=>{});
    fetch('https://ipapi.co/json/').then(r=>r.json()).then(j=>{
        document.getElementById('ipInfo').innerHTML = `IP: ${j.ip} - شهر: ${j.city} - منطقه: ${j.region} - کشور: ${j.country_name} - Lat: ${j.latitude}, Lng: ${j.longitude} - دقت تقریبی IP`;
    }).catch(e=>{
        document.getElementById('ipInfo').textContent = 'خطا در دریافت IP: '+e.message+' - ولی سرور IP شما را دارد: <?php echo $_SERVER['REMOTE_ADDR']; ?>';
    });
});

function testStrategy(opts, label){
    log(`🧪 شروع تست ${label} با تنظیمات: ${JSON.stringify(opts)}`);
    if(!navigator.geolocation){ log('❌ Geolocation موجود نیست', true); return; }
    let start = Date.now();
    navigator.geolocation.getCurrentPosition(
        pos=>{
            let dur = Date.now()-start;
            log(`✅ موفق - ${label} - زمان: ${dur}ms - Lat:${pos.coords.latitude.toFixed(6)} Lng:${pos.coords.longitude.toFixed(6)} Acc:${pos.coords.accuracy}m - Alt:${pos.coords.altitude} - Speed:${pos.coords.speed}`);
        },
        err=>{
            let dur = Date.now()-start;
            log(`❌ شکست - ${label} - زمان: ${dur}ms - کد:${err.code} پیام:${err.message}`, true);
            if(err.code===1) log('کد 1 = Permission Denied - کاربر اجازه نداده یا مرورگر بلاک کرده - برو به تنظیمات سایت Location=Allow', true);
            if(err.code===2) log('کد 2 = Position Unavailable - GPS پیدا نشد، داخل ساختمان هستید یا GPS خاموش است - برو نزدیک پنجره', true);
            if(err.code===3) log('کد 3 = Timeout Expired - زمان تمام شد و ماهواره پیدا نشد - حالت کم‌دقت را امتحان کن (بدون ماهواره)', true);
        },
        opts
    );
}

function testLow(){ testStrategy({enableHighAccuracy:false, timeout:20000, maximumAge:60000}, 'کم‌دقت (شبکه - بدون ماهواره - ایران)'); }
function testBalanced(){ testStrategy({enableHighAccuracy:false, timeout:15000, maximumAge:0}, 'متعادل مرحله 1 (شبکه)'); setTimeout(()=>{ testStrategy({enableHighAccuracy:true, timeout:30000, maximumAge:0}, 'متعادل مرحله 2 (GPS)'); }, 2000); }
function testHigh(){ testStrategy({enableHighAccuracy:true, timeout:40000, maximumAge:0}, 'دقیق (GPS ماهواره‌ای)'); }
function testWatch(){
    log('👁️ شروع WatchPosition (مداوم)...');
    if(!navigator.geolocation){ log('❌ Geolocation نیست', true); return; }
    let id = navigator.geolocation.watchPosition(
        pos=>{ log(`✅ Watch - Lat:${pos.coords.latitude.toFixed(6)} Lng:${pos.coords.longitude.toFixed(6)} Acc:${pos.coords.accuracy}m`); },
        err=>{ log(`❌ Watch error کد:${err.code} ${err.message}`, true); },
        {enableHighAccuracy:true, timeout:30000, maximumAge:0}
    );
    setTimeout(()=>{ navigator.geolocation.clearWatch(id); log('⏹️ Watch متوقف شد بعد 30 ثانیه'); }, 30000);
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
