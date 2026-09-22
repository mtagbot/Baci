#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
analyze_exam_pdf.py — v2 — تحلیل PDF نمونه سوالات آزمون (سیستم آموزشی ایران) و ساخت
فایل ایمپورت بانک سوالات (فرمت baci-bank-import@1) برای exam-bank-import.php

اصول نسخه ۲ (حداکثر متن، حداقل تصویر):
  • استخراج در سطح «قطعه» (span) و چینش راست→چپ = بازسازی رشتهٔ منطقی RTL که
    در Word تایپ شده؛ مرورگر RTL آن را عیناً مثل برگه اصلی نمایش می‌دهد —
    بنابراین عبارات ریاضی تک‌خطی (پرانتز/ضرب/تقسیم/مساوی) به‌صورت «متن» می‌مانند.
  • کسرهای پله‌ای (صورت/مخرج با خط کسری) به متن (صورت)/(مخرج) تبدیل می‌شوند.
  • تصویر فقط برای: شکل‌های هندسی/نمودار واقعی، گلیف‌های بدون نگاشت یونی‌کد،
    و صفحات کاملاً اسکن‌شده.
  • حذف واترمارک: قطعات متنی واترمارک حذف؛ در برش‌های تصویری، پیکسل‌های روشن
    (واترمارک خاکستری) سفید می‌شوند.
  • سربرگ/تبلیغ/پاصفحه حذف؛ پاسخ‌نامه انتهای فایل نادیده گرفته می‌شود.

استفاده:
  python3 tools/analyze_exam_pdf.py exams/file.pdf [-o out.json]
                                    [--subject ریاضی] [--grade هفتم] [--dpi 200]
"""
import argparse, base64, io, json, os, re, sys, unicodedata

try:
    import fitz  # PyMuPDF
except ImportError:
    sys.exit("PyMuPDF لازم است:  pip install PyMuPDF")
try:
    from bidi.algorithm import get_display
except ImportError:
    sys.exit("python-bidi لازم است:  pip install python-bidi")
try:
    from PIL import Image
    HAS_PIL = True
except ImportError:
    HAS_PIL = False

FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹'
AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'

def fa2en(s):
    for i, d in enumerate(FA_DIGITS): s = s.replace(d, str(i))
    for i, d in enumerate(AR_DIGITS): s = s.replace(d, str(i))
    return s

Q_START = re.compile(r'^\s*(?:سوال\s*)?([0-9۰-۹٠-٩]{1,3})\s*[-–—ـ.)("::؛]\s*(.*)$')
OPT_START = re.compile(r'^\s*(الف|أ|ب|ج|جـ|د|هـ?|[1-4۱-۴])\s*[-–—.)("]\s*(.*)$')
ANSWER_SOL = re.compile(r'(?:^|\s)(?:گزینه|هنیزگ)\s*[0-9۰-۹]|[0-9۰-۹]\s*(?:گزینه|هنیزگ)(?:\s|$|[-–])|\bپاسخ\s*(?:تشریحی|صحیح)|خساپ\s*یحیرشت')
ANSWER_KEY = re.compile(r'پاسخ\s*[‌\s]*نامه|پاسخنامه|همانخساپ|همان\s*خساپ|کلید\s*(سوالات|پاسخ|آزمون)|تالاوس\s*دیلک|جواب\s*نامه|همان\s*باوج|راهنمای\s*تصحیح|حیحصت\s*یامنهار|پاسخ\s*تشریحی\s*سوالات')
SCORE_PAT = re.compile(r'([0-9۰-۹٠-٩]+(?:[./⁄,][0-9۰-۹٠-٩]+)?)\s*(?:نمره|بارم)|(?:بارم|نمره)\s*[():«»]?\s*([0-9۰-۹٠-٩]+(?:[./⁄,][0-9۰-۹٠-٩]+)?)')
DOTS_RUN = re.compile(r'(\.{4,}|…{2,}|ـ{4,}|(?:\.\s){4,}\.?)')
TF_HINT = re.compile(r'(صحیح|درست)\s*[\s□◻☐]*\s*(غلط|نادرست)|ص\s*[□◻☐]\s*غ')
FOOTER_PAT = re.compile(r'صفحه\s*[0-9۰-۹٠-٩]|موفق باشید|@[A-Za-z0-9_]{3,}|www\.|http')
WATERMARK_PAT = re.compile(r'(?i)^\s*(?:www[-.][\w.-]*|[\w-]*kanoon[\w.-]*|[\w.-]+\.(?:ir|com)|t\.me/\S+|@[A-Za-z0-9_]{3,})\s*$')

_FA_WORDS = {'را','است','که','از','به','در','با','هر','این','آن','کدام','نمره','بارم','گزینه',
             'عدد','حاصل','زیر','کنید','دهید','باشید','صفحه','سوال','پاسخ','درست','غلط','صحیح',
             'الف','چند','چیست','کدامیک','مقدار','عبارت','جمله','کلمه','متن','شکل','تصویر',
             'نام','برای','یک','دو','سه','چهار','اگر','باشد','شود','می','های','ها','ترین',
             # ادبیات فارسی / دینی / قرآن / عربی
             'بیت','شعر','قافیه','ردیف','آرایه','تشبیه','استعاره','کنایه','مصراع','نهاد','گزاره',
             'مفعول','متمم','قید','صفت','ضمیر','فعل','اسم','مصدر','ماضی','مضارع','آینده','امر',
             'معنی','معنای','ترجمه','آیه','سوره','قرآن','حدیث','پیامبر','امام','خداوند','نماز',
             'مفرد','جمع','مثنی','مذکر','مونث','اعراب','ترکیب','وزن','املای','املا','انشا',
             'اکتب','ترجم','عین','صحح','أجب','اقرأ','املأ','ضع','اذکر','لماذا','ماذا','بین',
             # علوم / فیزیک / شیمی / زیست
             'انرژی','نیرو','حرکت','سرعت','دما','فشار','حجم','جرم','چگالی','اتم','مولکول',
             'سلول','بافت','اندام','گیاه','جانور','خون','قلب','مغز','عنصر','ترکیب','محلول',
             'اسید','باز','واکنش','آهنربا','الکتریسیته','نور','صوت','موج','گرما','ماده',
             # مطالعات / جغرافیا / تاریخ
             'کشور','استان','شهر','رود','کوه','دریا','اقلیم','جمعیت','حکومت','سلسله','دوره',
             'جنگ','صلح','قانون','مجلس','انقلاب','تمدن','باستان','هجری','میلادی',
             # افعال و ساخت‌های رایج سوال
             'بنویسید','توضیح','تعریف','مشخص','تعیین','محاسبه','رسم','کامل','مرتب','مقایسه',
             'دلیل','علت','چرا','چگونه','کجا','کیست','نادرست','جاهای','خالی','مناسب','دقت',
             'وصل','رابطه','فرمول','واحد','اندازه','طول','عرض','مساحت','محیط','زاویه','ضلع',
             'مثلث','مربع','مستطیل','دایره','قطر','شعاع','کسر','مخرج','صورت','ساده','بزرگ',
             'کوچک','برابر','مجموع','تفاضل','ضرب','تقسیم','قرینه','مختصات','محور','نمودار'}

# اعراب و علائم عربی که در امتیازدهی/تطبیق واژه باید نادیده گرفته شوند
_DIACRITICS = re.compile(r'[\u064B-\u065F\u0670\u06D6-\u06ED\u0640]')
def strip_diac(t):
    return _DIACRITICS.sub('', t)

def _fa_score(t):
    toks = re.split(r'[^\w\u0600-\u06FF\u200c]+', strip_diac(t))
    return sum(1 for w in toks if w in _FA_WORDS)

def _pattern_bonus(t):
    b = 0
    en = fa2en(t)
    # شماره سوال هم در ابتدای متن visual هم انتهای متن logical ظاهر می‌شود — به هر دو امتیاز بده
    if re.match(r'^\s*(?:سوال\s*)?[0-9]{1,3}\s*[-–—ـ.)(]', en) or re.search(r'[-–—ـ]\s*[0-9]{1,3}\s*$', en): b += 3
    if re.match(r'^\s*(الف|ب|ج|د)\s*[-–—.)(]', t): b += 3
    if len(re.findall(r'(الف|ب|ج|د|هـ)\s*[()]', t)) >= 2: b += 4
    if re.search(r'\(\s*[0-9]+(?:[./][0-9]+)?\s*نمره\s*\)', en): b += 2
    if re.search(r'(کنید|دهید|چیست|است|بنویسید|آورید)\s*[.؟?]?', t): b += 1
    return b

def fix_rtl(text):
    """(متن اصلاح‌شده, اطمینان) — اگر متن visual (معکوس) ذخیره شده باشد به منطقی برمی‌گردد.
    تکنیک لیگاتور (pdfminer.six #850): در متن visual فقط «لا» معکوس نمی‌شود؛
    با تبدیل موقت به U+FEFB به‌صورت یک نویسه با bidi برمی‌گردد و NFKC بازش می‌کند."""
    if not re.search(r'[\u0600-\u06FF\uFB50-\uFEFF]', text):
        return text, True
    base = unicodedata.normalize('NFKC', text)
    try:
        disp_plain = unicodedata.normalize('NFKC', get_display(base))
        # نسخه FEFB: در متن visual «لا» معکوس نشده — به‌صورت تک‌نویسه برگردد (pdfminer #850)
        disp_fefb = unicodedata.normalize('NFKC', get_display(unicodedata.normalize('NFC', text).replace('\u0644\u0627', '\uFEFB')))
        # انتخاب بین دو نسخه فقط وقتی متفاوت‌اند: نسخه با واژگان معتبرتر
        if disp_fefb != disp_plain:
            sp = _fa_score(disp_plain) * 2 + _pattern_bonus(disp_plain)
            sf = _fa_score(disp_fefb) * 2 + _pattern_bonus(disp_fefb)
            # جدول لام-الف شکسته هم رأی می‌دهد (کالس→کلاس یعنی نسخه FEFB واژه سالم ساخته)
            sf += sum(1 for w in re.split(r'[^\u0600-\u06FF\u200c]+', disp_fefb) if w and w in LAM_ALEF_FIXES.values())
            sp += sum(1 for w in re.split(r'[^\u0600-\u06FF\u200c]+', disp_plain) if w and w in LAM_ALEF_FIXES.values())
            disp = disp_fefb if sf > sp else disp_plain
        else:
            disp = disp_plain
    except Exception:
        return base, True
    s0 = _fa_score(base) * 2 + _pattern_bonus(base)
    s1 = _fa_score(disp) * 2 + _pattern_bonus(disp)
    # نشانه قوی متن معکوس: «ال» عربی به‌صورت «لا» در انتهای واژه‌ها (اَلْعِلْمُ ↔ ُمْلِعْلَا)
    if s0 == s1:
        b0 = strip_diac(base); d0 = strip_diac(disp)
        s0 += len(re.findall(r'\bال[\u0621-\u064A]', b0))
        s1 += len(re.findall(r'\bال[\u0621-\u064A]', d0))
        s0 += len(re.findall(r'[\u0621-\u064A]+(?:ه|ة|ی|ا)\b', b0)) // 3
        s1 += len(re.findall(r'[\u0621-\u064A]+(?:ه|ة|ی|ا)\b', d0)) // 3
    best = disp if s1 > s0 else base
    fa_chars = len(re.findall(r'[\u0600-\u06FF]', text))
    confident = max(s0, s1) >= 2 or fa_chars < 6 or disp == base
    if not confident:
        # واژه‌های فارسی/عربی سالم (بدون اعراب هم بررسی شود) نشانه ترتیب منطقی درست است
        words = [w for w in re.split(r'[^\u0600-\u06FF\u200c]+', strip_diac(base)) if len(w) >= 3]
        if len(words) >= 2:
            confident = True   # متن چندکلمه‌ای؛ bidi برعکسش نکرده چون امتیازی نداشت
    return best, confident

def is_garbled(text):
    """گلیف بدون نگاشت یونی‌کد (فونت‌های نقشه‌نشده) → فقط این‌ها تصویر می‌شوند.
    اشکال ارائه عربی (FB50-FEFF) خراب نیستند — NFKC آن‌ها را به حروف پایه باز می‌کند."""
    if not text.strip(): return False
    text = unicodedata.normalize('NFKC', text)   # presentation forms → پایه
    bad = ok = 0
    for ch in text:
        if ch.isspace(): continue
        o = ord(ch)
        if ch == '\ufffd' or 0xE000 <= o <= 0xF8FF or o in (0xFFFE, 0xFFFF): bad += 1
        elif 0xFB50 <= o <= 0xFEFF: bad += 1     # presentation form که NFKC باز نکرد = نگاشت شکسته
        elif unicodedata.category(ch).startswith(('C',)) and ch not in '\u200c\u200d\u200e\u200f': bad += 1
        else: ok += 1
    tot = bad + ok
    return tot > 0 and bad / tot > 0.34

def rect_union(a, b):
    return fitz.Rect(min(a.x0, b.x0), min(a.y0, b.y0), max(a.x1, b.x1), max(a.y1, b.y1))

OPT_ORDER = {'الف': 0, 'أ': 0, 'ب': 1, 'ج': 2, 'جـ': 2, 'د': 3, 'هـ': 4, 'ه': 4,
             '1': 0, '2': 1, '3': 2, '4': 3, '۱': 0, '۲': 1, '۳': 2, '۴': 3}

def find_score_tokens(row, pw):
    """توکن‌های بارم داخل/کنار سطر: ران‌های عددی مجاور که با هم الگوی «N/N» می‌سازند
    (مثل «5»+« /0» = ۰/۵ بارم)، یا عدد ایزوله در ستون‌های کناری صفحه.
    ارقام داخل عبارت (چسبیده به عملگر/متغیر) هرگز بارم نیستند."""
    numeric = [s0 for s0 in row if re.fullmatch(r'[\s0-9./\u2044]+', s0['t']) and s0['t'].strip()]
    others = [s0 for s0 in row if s0 not in numeric]
    drop = set()
    # ۱) ران‌های عددی مجاور (فاصله < 4pt) که concat آن‌ها «N/N» می‌شود
    numeric_sorted = sorted(numeric, key=lambda s0: -s0['r'].x1)
    run = []
    def flush(run):
        if not run: return
        cat = ''.join(x['t'] for x in run)
        if re.search(r'[0-9]\s*[/\u2044]\s*[0-9]', cat) and len(re.sub(r'[^0-9]', '', cat)) <= 4:
            for x in run: drop.add(id(x))
    for s0 in numeric_sorted:
        if run and (run[-1]['r'].x0 - s0['r'].x1) > 4:
            flush(run); run = []
        run.append(s0)
    flush(run)
    # ۲) عدد ایزوله در ستون کناری: فاصله تا نزدیک‌ترین قطعه غیرعددی > 14pt
    #    یا قطعه دارای الگوی کسر بارم (/N یا N/N) کاملاً داخل ستون کناری
    for s0 in numeric:
        if id(s0) in drop: continue
        if s0['r'].x1 <= pw * 0.19 or s0['r'].x0 >= pw * 0.86:
            mind = min((max(o['r'].x0 - s0['r'].x1, s0['r'].x0 - o['r'].x1) for o in others), default=99)
            if mind > 14 or re.search(r'[/\u2044]', s0['t']): drop.add(id(s0))
    return drop

def looks_like_question_start(t):
    """«N - جمله بلند فارسی» = شروع سوال است حتی اگر N بین ۱و۴ باشد؛
    گزینه واقعی معمولاً کوتاه است و فعل جمله‌ای ندارد."""
    m = re.match(r'^\s*([0-9۰-۹]{1,3})\s*[-–—ـ.)]\s*(.*)$', fa2en(t))
    if not m: return False
    body = m.group(2).strip()
    if len(re.findall(r'[\u0600-\u06FF\u200c]{2,}', body)) >= 4: return True
    if re.search(r'(کنید|دهید|بنویسید|آورید|بیابید|چیست|چقدر|چند|مشخص|کامل|حل کنید)\b', body): return True
    return False

def norm_opt(t):
    return re.sub(r'^\s*(الف|ب|ج|د|هـ?|[1-4۱-۴])\s*[()]\s*', lambda m: m.group(1) + ') ', t).strip()

def split_options(line):
    labs = list(re.finditer(r'(الف|أ|ب|جـ|ج|د|هـ)\s*[()]', line))
    if len(labs) < 2: return None
    parts = re.split(r'(الف|أ|ب|جـ|ج|د|هـ)\s*[()]', line)
    pre = parts[0].strip()
    pairs = []
    if pre:
        vals = [pre] + [parts[i].strip() for i in range(2, len(parts), 2)]
        for i, li in enumerate(range(1, len(parts), 2)):
            pairs.append((parts[li], vals[i] if i < len(vals) else ''))
    else:
        for li in range(1, len(parts), 2):
            v = parts[li + 1].strip() if li + 1 < len(parts) else ''
            pairs.append((parts[li], v))
    pairs.sort(key=lambda pv: OPT_ORDER.get(pv[0], 9))
    return [f"{lab}) {val}".strip() for lab, val in pairs if val or lab]

# homoglyph fold (تکنیک arafix، معکوس برای فارسی): نویسه‌های عربی هم‌شکل → فارسی
# فقط در متن فارسی (تشخیص با حروف ویژه فارسی گ چ پ ژ یا واژگان) اعمال می‌شود
AR2FA = str.maketrans({'\u0643': 'ک',   # ك عربی → ک
                       '\u064A': 'ی',   # ي عربی → ی
                       '\u0649': 'ی',   # ى مقصوره → ی (در فارسی)
                       })
def fold_homoglyphs_fa(t):
    """در سطرهای فارسی، ك/ي عربی به ک/ی تبدیل می‌شود؛ متن عربی واقعی دست‌نخورده می‌ماند."""
    if not re.search(r'[كي]', t): return t
    # نشانه فارسی بودن: حروف ویژه فارسی یا واژه‌های پرتکرار فارسی
    if re.search(r'[گچپژ]', t) or _fa_score(t) >= 1:
        return t.translate(AR2FA)
    return t

# لیگاتور «لا» در فونت‌های قدیمی به‌صورت «ا+ل» وارونه استخراج می‌شود
LAM_ALEF_FIXES = {
    'کالس': 'کلاس', 'کالسی': 'کلاسی', 'عالمت': 'علامت', 'عالمت‌ها': 'علامت‌ها',
    'سواالت': 'سوالات', 'سؤاالت': 'سؤالات', 'باال': 'بالا', 'باالی': 'بالای',
    'باالتر': 'بالاتر', 'کالمی': 'کلامی', 'حاال': 'حالا', 'اکنون': 'اکنون',
    'اشکاالت': 'اشکالات', 'مشکالت': 'مشکلات', 'بزرگترین': 'بزرگ‌ترین',
    'وسایل': 'وسایل', 'طالیی': 'طلایی', 'میالدی': 'میلادی', 'امال': 'املا',
    'تالش': 'تلاش', 'خالصه': 'خلاصه', 'اختالف': 'اختلاف', 'انقالب': 'انقلاب',
    'اطالعات': 'اطلاعات', 'عالوه': 'علاوه', 'مثال‌ها': 'مثال‌ها',
}
def fix_lam_alef(t):
    def rep(m):
        w = m.group(0)
        return LAM_ALEF_FIXES.get(w, w)
    return re.sub(r'[\u0600-\u06FF\u200c]+', rep, t)

def fix_floating_dot(t):
    """نقطه شناور بعد از برچسب: «الف- .قرینة …» → «الف- قرینة … .»"""
    m = re.match(r'^(\s*[ا-ی]{1,3}\s*[-–—)]\s*)\.\s*(.+)$', t)
    if m:
        t = m.group(1) + m.group(2).strip()
        if not re.search(r'[.؟?!:…]\s*$', t): t = t + '.'
    return t

def fix_misplaced_dot(t):
    """نقطه پایان جمله که bidi آن را به ابتدای/وسط کلمه چسبانده: «را.بدست» → «را بدست …»."""
    moved = False
    # نقطه ابتدای خط فارسی
    m = re.match(r'^\s*\.\s*(.+)$', t)
    if m and re.search(r'[\u0600-\u06FF]', m.group(1)):
        t = m.group(1); moved = True
    # نقطه چسبیده بین دو حرف فارسی (بدون فاصله) → حذف از وسط
    if re.search(r'[\u0600-\u06FF]\.[\u0600-\u06FF]', t):
        t = re.sub(r'([\u0600-\u06FF])\.([\u0600-\u06FF])', r'\1 \2', t); moved = True
    if moved and not re.search(r'[.؟?!:…]\s*$', t):
        t = t.rstrip() + '.'
    return t

def tidy_text(t):
    t = re.sub(r'\s{2,}', ' ', t).strip()
    # پرانتز بارم آینه‌شده: «)۱ نمره(» یا «)2 نمره(» → «(۱ نمره)»
    t = re.sub(r'\)\s*([0-9۰-۹]+(?:[./٫][0-9۰-۹]+)?\s*نمره)\s*\(', r'(\1)', t)
    t = fold_homoglyphs_fa(t)
    t = fix_lam_alef(t)
    t = fix_floating_dot(t)
    t = fix_misplaced_dot(t)
    # علائم «؟ ! :» که bidi به ابتدای خط برده → انتهای جمله
    m0 = re.match(r'^\s*([؟?!:؛])\s*(.+)$', t)
    if m0 and re.search(r'[\u0600-\u06FF]', m0.group(2)) and not re.search(r'[؟?!:؛.]\s*$', m0.group(2)):
        t = m0.group(2).strip() + m0.group(1)
    # گیومه آینه‌شده bidi: »کلمه« → «کلمه»
    if t.count('»') and t.count('«'):
        fq = re.search(r'[«»]', t)
        if fq and fq.group(0) == '»':
            t = t.translate(str.maketrans('«»', '»«'))
    # فاصله‌گذاری استاندارد گیومه
    t = re.sub(r'\s*«\s*', ' «', t); t = re.sub(r'\s*»\s*', '» ', t)
    t = re.sub(r'\s{2,}', ' ', t).strip()
    # فاصله بین حرف فارسی و رقم چسبیده (طول9 → طول ۹)
    t = re.sub(r'([\u0600-\u06FF])([0-9])', r'\1 \2', t)
    t = re.sub(r'([0-9])([\u0600-\u06FF])', r'\1 \2', t)
    m = re.match(r'^(?:نمره|نم|بارم)\s*[()]?\s*([0-9۰-۹٠-٩]+(?:[./⁄][0-9۰-۹٠-٩]+)?)\s*(.+?)\s*[()]?\s*$', t)
    if m and re.search(r'[\u0600-\u06FF]', m.group(2)):
        t = m.group(2).strip() + ' (' + m.group(1) + ' نمره)'
    t = re.sub(r'\s*[()]\s*$', '', t) if re.search(r'[()]\s*$', t) and t.count('(') != t.count(')') else t
    return t

def esc(t):
    return (t.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;'))

EN2FA = str.maketrans('0123456789', FA_DIGITS)
def to_fa_digits(t):
    return t.translate(EN2FA)

# الگوی «ران ریاضی LTR» داخل جمله فارسی: عبارت با =، عملگر، متغیر لاتین یا مجموعه {…}
INLINE_MATH = re.compile(
    r'((?:[({\[]|[0-9۰-۹a-zA-Z+\-−±])'
    r'[0-9۰-۹a-zA-Z+\-−±×÷=/.,٫\u2044\s()\[\]{}]*'
    r'(?:[=×÷\u2044]|[0-9۰-۹]\s*[+\-−]|[+\-−]\s*[0-9۰-۹]|[a-zA-Z])'
    r'[0-9۰-۹a-zA-Z+\-−±×÷=/.,٫\u2044\s()\[\]{}]*'
    r'(?:[)}\]=]|[0-9۰-۹a-zA-Z]))')

LABEL_MATH = re.compile(r'^\s*([ا-ی]{1,3})\s*[)\-–]\s*(.*[=×÷+−].*)$')

FRAC_HTML_STYLE_WRAP = 'display:inline-block;vertical-align:middle;text-align:center;margin:0 2px'
FRAC_HTML_STYLE_NUM = 'display:block;padding:0 4px;border-bottom:1.2px solid currentColor;line-height:1.15'
FRAC_HTML_STYLE_DEN = 'display:block;padding:0 4px;line-height:1.15'

def frac_html(num, den):
    """کسر فارسی ایرانی: صورت بالا، خط افقی، مخرج پایین."""
    return ('<span class="pfrac" style="' + FRAC_HTML_STYLE_WRAP + '">'
            + '<span style="' + FRAC_HTML_STYLE_NUM + '">' + esc(to_fa_digits(num.strip())) + '</span>'
            + '<span style="' + FRAC_HTML_STYLE_DEN + '">' + esc(to_fa_digits(den.strip())) + '</span></span>')

SCORE_PAREN = re.compile(r'\(\s*[0-9۰-۹]+(?:[/.٫\u2044][0-9۰-۹]+)?\s*نمره\s*\)')
def mathtext_to_html(expr):
    """متن ریاضی → HTML: نشانه‌های کسر U+2044 به کسر پله‌ای ایرانی تبدیل می‌شوند."""
    out = []
    last = 0
    for m in re.finditer(r'(\([^()\u2044]{1,14}\)|[0-9۰-۹]+(?:[.,][0-9۰-۹]+)?)\s*\u2044\s*(\([^()\u2044]{1,14}\)|[0-9۰-۹]+(?:[.,][0-9۰-۹]+)?)', expr):
        out.append(esc(to_fa_digits(expr[last:m.start()])))
        out.append(frac_html(m.group(1), m.group(2)))
        last = m.end()
    out.append(esc(to_fa_digits(expr[last:])))
    return ''.join(out)

def html_math_span(expr):
    """عبارت ریاضی → span چپ‌به‌راست با ارقام فارسی (نظیر برگه ایرانی)."""
    return '<span dir="ltr" style="unicode-bidi:isolate">' + mathtext_to_html(expr.strip()) + '</span>'

def render_line_html(body_raw):
    """یک سطر متن سوال → HTML با جهت‌دهی صریح ریاضی/فارسی."""
    t = body_raw
    # بارم «(۱/۵ نمره)» جزو متن فارسی است — علامت کسر آن به «/» ساده تبدیل شود
    t = SCORE_PAREN.sub(lambda m: m.group(0).replace('\u2044', '/'), t)
    # حالت «برچسب) عبارت ریاضی خالص»
    m = LABEL_MATH.match(t)
    if m and not re.search(r'[\u0600-\u06FF]', m.group(2)):
        return esc(m.group(1)) + ') ' + html_math_span(m.group(2))
    # سطر ریاضی خالص بدون برچسب
    if not FA_LETTER.search(t) and re.search(r'[=×÷]|[0-9]\s*[+\-−]|[+\-−]\s*[0-9]|[a-zA-Z]', t) and len(t.strip()) >= 3:
        return html_math_span(t)
    # جمله فارسی با ران‌های ریاضی داخلی
    out = []
    last = 0
    for m2 in INLINE_MATH.finditer(t):
        seg = m2.group(1)
        # فقط ران‌هایی که واقعاً ریاضی‌اند (نه عدد ساده داخل جمله)
        if not (re.search(r'[=×÷{}\[\]\u2044]', seg) or re.search(r'[a-zA-Z]', seg) or re.search(r'[()].*[()]', seg)):
            continue
        if len(seg.strip()) < 2 or (len(seg.strip()) < 3 and not re.search(r'[a-zA-Z\u2044]', seg)):
            continue
        pre = t[last:m2.start()]
        out.append(mathtext_to_html(pre))
        out.append(html_math_span(seg))
        last = m2.end()
    out.append(mathtext_to_html(t[last:]))
    html = ''.join(out)
    # ضریب‌های جبری کوتاه باقی‌مانده در متن فارسی: ۴n، ۵b، x2 …
    html = re.sub(r'(?<![\w>])([0-9۰-۹]{1,3}[a-zA-Z]|[a-zA-Z][0-9۰-۹]{1,3}|[a-zA-Z])(?![\w<])',
                  lambda m: '<span dir="ltr" style="unicode-bidi:isolate">' + to_fa_digits(m.group(1)) + '</span>', html)
    return html

# ---------------------------------------------------------------- تصویر
def whiten_png(png_bytes, thr=175):
    """پیکسل‌های روشن (واترمارک/پس‌زمینه خاکستری) → سفید کامل."""
    if not HAS_PIL: return png_bytes
    try:
        im = Image.open(io.BytesIO(png_bytes)).convert('RGB')
        g = im.convert('L')
        mask = g.point(lambda v: 255 if v > thr else 0)
        white = Image.new('RGB', im.size, (255, 255, 255))
        im = Image.composite(white, im, mask)
        buf = io.BytesIO(); im.save(buf, 'PNG', optimize=True)
        return buf.getvalue()
    except Exception:
        return png_bytes

def png_ink_ratio(png_bytes):
    """نسبت پیکسل‌های جوهردار (تیره) تصویر — برای دورانداختن برش‌های تقریباً خالی."""
    if not HAS_PIL: return 1.0
    try:
        im = Image.open(io.BytesIO(png_bytes)).convert('L')
        if im.width * im.height == 0: return 0.0
        hist = im.histogram()
        dark = sum(hist[:176])
        return dark / float(im.width * im.height)
    except Exception:
        return 1.0

def png_autotrim(png_bytes, pad=6):
    """برش خودکار حاشیه سفید + حذف خطوط جدول چسبیده به لبه‌ها (ستون/ردیف تیره تمام‌قد)."""
    if not HAS_PIL: return png_bytes
    try:
        im = Image.open(io.BytesIO(png_bytes)).convert('RGB')
        g = im.convert('L')
        W, H = im.size
        px = g.load()
        def col_dark_ratio(x):
            c = 0
            for y in range(0, H, 2):
                if px[x, y] < 128: c += 1
            return c / max(1, len(range(0, H, 2)))
        def row_dark_ratio(y):
            c = 0
            for x in range(0, W, 2):
                if px[x, y] < 128: c += 1
            return c / max(1, len(range(0, W, 2)))
        # ۱) خطوط جدول در ۶٪ کناری: ستون/ردیفی که >۶۰٪ تیره است حذف می‌شود
        x0, x1, y0, y1 = 0, W, 0, H
        lim = max(2, int(0.06 * W))
        while x0 < lim and col_dark_ratio(x0) > 0.6: x0 += 1
        while x1 - 1 > W - lim and col_dark_ratio(x1 - 1) > 0.6: x1 -= 1
        limh = max(2, int(0.06 * H))
        while y0 < limh and row_dark_ratio(y0) > 0.6: y0 += 1
        while y1 - 1 > H - limh and row_dark_ratio(y1 - 1) > 0.6: y1 -= 1
        if x1 - x0 < 8 or y1 - y0 < 8: return png_bytes
        im2 = im.crop((x0, y0, x1, y1))
        # ۲) trim سفید
        from PIL import ImageChops
        bg = Image.new('RGB', im2.size, (255, 255, 255))
        diff = ImageChops.difference(im2, bg).convert('L').point(lambda v: 255 if v > 24 else 0)
        bbox = diff.getbbox()
        if bbox:
            l, t, r2, b = bbox
            l = max(0, l - pad); t = max(0, t - pad)
            r2 = min(im2.width, r2 + pad); b = min(im2.height, b + pad)
            im2 = im2.crop((l, t, r2, b))
        buf = io.BytesIO(); im2.save(buf, 'PNG', optimize=True)
        return buf.getvalue()
    except Exception:
        return png_bytes

def crop_b64(page, rect, dpi, min_ink=0.004):
    zoom = dpi / 72.0
    r = fitz.Rect(rect) & page.rect
    if r.is_empty or r.width < 2 or r.height < 2: return None, 0, 0
    pm = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=r, alpha=False)
    png = whiten_png(pm.tobytes('png'))
    png = png_autotrim(png)
    if min_ink and png_ink_ratio(png) < min_ink:
        return None, 0, 0   # برش تقریباً خالی (خط‌های جدا/فاصله سفید) — دور انداخته می‌شود
    if HAS_PIL:
        try:
            im0 = Image.open(io.BytesIO(png))
            return base64.b64encode(png).decode(), im0.width, im0.height
        except Exception:
            pass
    return base64.b64encode(png).decode(), pm.width, pm.height

def figure_rects(page, first_q_y):
    """فقط شکل‌های واقعی (هندسی/نمودار/تصویر) — خطوط جدول و خط کسری مستثنا."""
    rects = []
    pw, ph = page.rect.width, page.rect.height
    for info in page.get_image_info():
        r = fitz.Rect(info['bbox'])
        if r.width < 14 or r.height < 14: continue
        if r.y1 <= first_q_y + 2: continue
        if r.width > 0.97 * pw and r.height > 0.97 * ph: continue
        rects.append(r)
    try: draws = page.get_drawings()
    except Exception: draws = []
    boxes = []
    for dr in draws:
        r = fitz.Rect(dr['rect'])
        if r.y1 <= first_q_y + 2: continue
        if r.width >= 0.9 * pw: continue
        if r.width < 8 and r.height < 8: continue
        if r.height < 3 and r.width > 0.35 * pw: continue
        if (r.width < 3 and r.height > 60) or (r.height < 3 and r.width > 60): continue
        if r.height <= 2.8 and r.width <= 130: continue   # خط کسری/زیرخط — تصویر نیست
        if r.width * r.height > 0.5 * pw * ph: continue
        boxes.append(r)
    changed = True
    while changed and boxes:
        changed = False
        merged = []
        while boxes:
            cur = boxes.pop()
            grew = True
            while grew:
                grew = False
                keep = []
                for b in boxes:
                    infl = fitz.Rect(cur.x0 - 8, cur.y0 - 8, cur.x1 + 8, cur.y1 + 8)
                    if infl.intersects(b): cur = rect_union(cur, b); grew = True; changed = True
                    else: keep.append(b)
                boxes = keep
            merged.append(cur)
        boxes = merged
        break
    for b in boxes:
        if b.width >= 24 and b.height >= 16: rects.append(b)
    out = []
    for r in rects:
        merged = False
        for i, o in enumerate(out):
            if r.intersects(o): out[i] = rect_union(o, r); merged = True; break
        if not merged: out.append(r)
    return out

# ---------------------------------------------------------------- صفحات اسکن‌شده
def split_scanned_page(page, dpi):
    zoom = dpi / 72.0
    pm = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), alpha=False)
    W, H, n = pm.width, pm.height, pm.n
    buf = pm.samples
    step = 2
    xs = list(range(0, W, step))
    col_dark = [0] * len(xs)
    for y in range(0, H, 4):
        row = buf[y * W * n:(y + 1) * W * n]
        for i, x in enumerate(xs):
            o = x * n
            if (row[o] + row[o + 1] + row[o + 2]) < 450: col_dark[i] += 1
    rows_sampled = len(range(0, H, 4))
    vline = {i for i, c in enumerate(col_dark) if c > 0.28 * rows_sampled}
    margin = max(1, int(0.03 * len(xs)))
    for i in range(margin): vline.add(i); vline.add(len(xs) - 1 - i)
    keep = [i for i in range(len(xs)) if i not in vline]
    dark = []
    for y in range(H):
        row = buf[y * W * n:(y + 1) * W * n]
        c = 0
        for i in keep:
            o = xs[i] * n
            if (row[o] + row[o + 1] + row[o + 2]) < 450: c += 1
        dark.append(c)
    thr = max(2, len(keep) // 300)
    blocks = []
    y = 0
    min_gap = max(6, int(0.008 * H))
    min_blk = max(10, int(0.012 * H))
    while y < H:
        while y < H and dark[y] <= thr: y += 1
        if y >= H: break
        y0 = y
        gap = 0
        while y < H and gap < min_gap:
            if dark[y] <= thr: gap += 1
            else: gap = 0
            y += 1
        y1 = y - gap
        if y1 - y0 >= min_blk: blocks.append((y0, y1))
    groups = []
    if blocks:
        gaps = [blocks[i + 1][0] - blocks[i][1] for i in range(len(blocks) - 1)]
        med = sorted(gaps)[len(gaps) // 2] if gaps else 0
        boundary = max(int(0.022 * H), int(med * 1.45)) if gaps else int(0.022 * H)
        g = [blocks[0]]
        for i in range(1, len(blocks)):
            if blocks[i][0] - g[-1][1] >= boundary: groups.append(g); g = []
            g.append(blocks[i])
        groups.append(g)
    if groups and groups[0][-1][1] / H < 0.15 and len(groups) > 1:
        groups = groups[1:]
    out = []
    for g in groups:
        y0, y1 = g[0][0], g[-1][1]
        clip = fitz.Rect(0, y0 / zoom - 3, page.rect.width, y1 / zoom + 3) & page.rect
        pmc = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=clip, alpha=False)
        png = whiten_png(pmc.tobytes('png'), thr=165)
        out.append({'b64': base64.b64encode(png).decode(),
                    'w': pmc.width, 'h': pmc.height,
                    'top_frac': y0 / H, 'h_frac': (y1 - y0) / H})
    return out

# ---------------------------------------------------------------- استخراج ردیف‌ها (سطح span)
def assemble_fractions(page, spans):
    """کسر پله‌ای: خط کسری + صورت/مخرج ریاضی → قطعه متنی (صورت)/(مخرج)."""
    try: draws = page.get_drawings()
    except Exception: draws = []
    bars = []
    for dr in draws:
        r = fitz.Rect(dr['rect'])
        if r.height <= 2.8 and 8 <= r.width <= 130:
            bars.append(r)
    for bar in bars:
        num = [s for s in spans if s['r'].x0 >= bar.x0 - 5 and s['r'].x1 <= bar.x1 + 8
               and bar.y0 - 16 <= s['r'].y1 <= bar.y0 + 2]
        den = [s for s in spans if s['r'].x0 >= bar.x0 - 5 and s['r'].x1 <= bar.x1 + 8
               and bar.y1 - 2 <= s['r'].y0 <= bar.y1 + 16]
        if not num or not den: continue
        joined = ''.join(s['t'] for s in num + den)
        if re.search(r'[\u0600-\u06FF]', joined): continue   # متن فارسی زیرخط‌دار — کسر نیست
        num.sort(key=lambda s: s['r'].x0); den.sort(key=lambda s: s['r'].x0)
        ntxt = ' '.join(s['t'].strip() for s in num if s['t'].strip())
        dtxt = ' '.join(s['t'].strip() for s in den if s['t'].strip())
        if not ntxt or not dtxt: continue
        R = fitz.Rect(bar)
        for s in num + den:
            R = rect_union(R, s['r'])
            spans.remove(s)
        nn = ntxt if re.fullmatch(r'[0-9۰-۹.,/ ]+', ntxt) else '(' + ntxt + ')'
        dd = dtxt if re.fullmatch(r'[0-9۰-۹.,/ ]+', dtxt) else '(' + dtxt + ')'
        spans.append({'t': f' {nn}\u2044{dd} ', 'r': R, 'f': 'synth-frac'})
    # کسرهای پله‌ای بدون خط کسری: دو قطعه عددی کوتاه دقیقاً روی هم
    pw_f = page.rect.width
    digit_spans = [s for s in spans if s['f'] != 'synth-frac'
                   and re.fullmatch(r'\s*[0-9۰-۹]{1,3}\s*', s['t']) and s['r'].width <= 26
                   and pw_f * 0.20 < s['r'].x0 and s['r'].x1 < pw_f * 0.915]  # ستون بارم/ردیف جدول مستثنا
    used = set()
    for i, a in enumerate(digit_spans):
        if id(a) in used: continue
        for b in digit_spans:
            if b is a or id(b) in used: continue
            ox = min(a['r'].x1, b['r'].x1) - max(a['r'].x0, b['r'].x0)
            if ox < 0.7 * max(a['r'].width, b['r'].width): continue
            top, bot = (a, b) if a['r'].y0 < b['r'].y0 else (b, a)
            vgap = bot['r'].y0 - top['r'].y1
            if not (-3 <= vgap <= 5): continue
            R = rect_union(a['r'], b['r'])
            try:
                spans.remove(a); spans.remove(b)
            except ValueError:
                continue
            spans.append({'t': f" {top['t'].strip()}\u2044{bot['t'].strip()} ", 'r': R, 'f': 'synth-frac'})
            used.add(id(a)); used.add(id(b))
            break
    return spans

def _paren_variant_score(t):
    """امتیاز سلامت عبارت ریاضی: پرانتز باز قبل از عدد/علامت + عدد قبل از پرانتز بسته."""
    sc = len(re.findall(r'\(\s*[+\-−±]?\s*[0-9]', t)) + len(re.findall(r'[0-9]\s*\)', t))
    bal = 0; viol = 0
    for c in t:
        if c == '(': bal += 1
        elif c == ')':
            bal -= 1
            if bal < 0: viol += 1; bal = 0
    viol += bal
    return sc - viol * 2

def math_chars_text(page, rects):
    """متن ریاضی حرف‌به‌حرف به ترتیب چپ→راست صفحه، فقط از داخل ناحیه(های) داده‌شده.
    جهت پرانتزها (آینه bidi) با امتیازدهی هر دو حالت انتخاب می‌شود."""
    if isinstance(rects, fitz.Rect): rects = [rects]
    R = rects[0]
    for r in rects[1:]: R = rect_union(R, r)
    try:
        rd = page.get_text('rawdict', clip=fitz.Rect(R))
    except Exception:
        return None
    chars = []
    for blk in rd.get('blocks', []):
        if blk.get('type') != 0: continue
        for ln in blk.get('lines', []):
            for sp in ln.get('spans', []):
                for ch in sp.get('chars', []):
                    c = ch.get('c', '')
                    if not c or c == '\x00': continue
                    r = fitz.Rect(ch['bbox'])
                    cx, cy = (r.x0 + r.x1) / 2, (r.y0 + r.y1) / 2
                    if not any(rr.x0 - 1.5 <= cx <= rr.x1 + 1.5 and rr.y0 - 1.5 <= cy <= rr.y1 + 1.5 for rr in rects):
                        continue
                    chars.append((r.x0, r.y0, c, r.y1))
    if not chars: return None
    # ---- کسرهای عمودی (صورت بالای مخرج): خوشه‌بندی باند بالا/پایین ارقام ----
    ys = sorted(t[1] for t in chars)
    base_y = ys[len(ys) // 2]
    # فقط ارقامی که واقعاً بین کاراکترهای خط پایه محصورند (کسر داخل عبارت)
    base_chars = [t for t in chars if abs(t[1] - base_y) <= 4]
    bx0 = min((t[0] for t in base_chars), default=0)
    bx1 = max((t[0] for t in base_chars), default=1e9)
    top = [t for t in chars if t[2].isdigit() and t[1] < base_y - 4 and bx0 - 4 <= t[0] <= bx1 + 4]
    bot = [t for t in chars if t[2].isdigit() and t[1] > base_y + 4 and bx0 - 4 <= t[0] <= bx1 + 4]
    if top and bot:
        def runs(band):
            band = sorted(band)
            out = [[band[0]]]
            for t in band[1:]:
                if t[0] - out[-1][-1][0] <= 7: out[-1].append(t)
                else: out.append([t])
            return out
        used = set()
        for tr in runs(top):
            tx0, tx1 = tr[0][0], tr[-1][0]
            for br in runs(bot):
                bx0, bx1 = br[0][0], br[-1][0]
                if min(tx1, bx1) - max(tx0, bx0) >= -4:  # هم‌ستون
                    num = ''.join(t[2] for t in tr)
                    den = ''.join(t[2] for t in br)
                    x_anchor = min(tx0, bx0)
                    y_anchor = tr[0][1]
                    for t in tr + br: used.add(id(t))
                    chars.append((x_anchor, y_anchor, f'\u0000FRAC:{num}\u2044{den}\u0000', tr[0][3]))
                    break
        chars = [t for t in chars if id(t) not in used]
    chars.sort(key=lambda t: (t[0], t[1]))
    txt = ''
    prev = None
    for x, y, c, y1 in chars:
        if c.startswith('\u0000FRAC:'):
            c = ' ' + c[6:-1] + ' '
        if prev is not None:
            px, py, pc, py1 = prev
            if x - px > 3.5 and not txt.endswith(' '):
                txt += ' '
        txt += c
        prev = (x, y, c, y1)
    txt = unicodedata.normalize('NFKC', txt)
    txt = re.sub(r'\s+', ' ', txt).strip()
    # انتخاب جهت پرانتز: اصلی یا آینه‌شده — هر کدام سالم‌تر
    mirrored = txt.translate(str.maketrans('()[]{}', ')(][}{'))
    if _paren_variant_score(mirrored) > _paren_variant_score(txt):
        txt = mirrored
    # فاصله‌گذاری تمیز
    txt = re.sub(r'\(\s+', '(', txt); txt = re.sub(r'\s+\)', ')', txt)
    txt = re.sub(r'\s*([+×÷=])\s*', r' \1 ', txt)
    txt = re.sub(r'([0-9])\s+([0-9])(?![0-9]*\s*/)', r'\1\2', txt)  # فاصله عدد مخلوط (۸ ۱/۵) حفظ شود
    txt = re.sub(r'\s{2,}', ' ', txt).strip()
    # برچسب گزینه انتهایی: «۳t + ۳a (۱» → «۱) ۳t + ۳a»
    mtail = re.match(r'^(.*?)[\s]*\(\s*([1-4۱-۴])\s*$', txt)
    if mtail and re.search(r'[0-9a-zA-Z]', mtail.group(1)):
        txt = fa2en(mtail.group(2)) + ') ' + mtail.group(1).strip()
    # «(N» ابتدای عبارت (برچسب گزینه آینه‌شده): «(1) expr» → «1) expr»
    txt = re.sub(r'^\(\s*([1-4۱-۴])\s*\)\s*', lambda m: fa2en(m.group(1)) + ') ', txt)
    # توازن پرانتز: پرانتز حذف‌شده در مرز برچسب را جبران کن
    opens, closes = txt.count('('), txt.count(')')
    if closes == opens + 1 and not txt.lstrip().startswith('('):
        txt = '(' + txt
    elif opens == closes + 1 and not txt.rstrip().endswith(')') and txt.rstrip().endswith('='):
        pass  # = انتهایی طبیعی است؛ دست نمی‌زنیم
    txt = re.sub(r'^\(\s*([1-4۱-۴])\s*\)\s*', lambda m: fa2en(m.group(1)) + ') ', txt)
    return txt

HAS_FA = re.compile(r'[\u0600-\u06FF\uFB50-\uFEFF]')
FA_LETTER = re.compile(r'[\u0621-\u064A\u067E\u0686\u0698\u06A9\u06AF\u06CC\u06C0-\u06C3]')

def valid_math_line(t):
    """درستی ساختاری عبارت ریاضی تک‌خطی بازسازی‌شده. اگر نامعتبر → آن خط تصویر می‌شود."""
    body = re.sub(r'^\s*(?:[ا-ی]{1,3}|[1-4۱-۴])\s*[)\-–]\s*', '', t).strip()   # حذف برچسب الف) / 1) …
    body = re.sub(r'\s*\((?:[0-9۰-۹]{1,2}|[ا-ی]{1,3})\s*$', '', body).strip()  # برچسب انتهایی «(1» یا «(الف»
    if body.startswith('='): return False
    if '//' in body or '××' in body or '÷÷' in body: return False
    bal = 0
    for c in body:
        if c == '(': bal += 1
        elif c == ')':
            bal -= 1
            if bal < 0: return False
    if bal != 0: return False
    # هر گروه پرانتزی: عدد علامت‌دار یا عبارت جبری ساده (متغیرهای لاتین مجاز)
    for g in re.findall(r'\(([^()]*)\)', body):
        gg = g.strip()
        if re.fullmatch(r'[+\-−±]?\s*[0-9۰-۹]+(?:[./][0-9۰-۹]+)?', gg): continue
        if re.fullmatch(r'[0-9۰-۹a-zA-Z+\-−±×÷/.\u2044\s]{1,30}', gg): continue
        return False
    # عملگر پشت‌سرهم بی‌معنا (× ÷ کنار هم)
    if re.search(r'[×÷]\s*[×÷=]', body): return False
    # تکرار بی‌معنای متغیر-عدد چسبیده مثل «a1 a−»
    if re.search(r'[a-z][0-9][a-z]', body): return False
    return True
MATHY_SEG = re.compile(r'[=+×÷−±√\u2044]|[0-9][./][0-9]')

def fix_digit_runs(page, rect, txt):
    """اعداد چندرقمی داخل سطر فارسی گاهی با ترتیب وارونه استخراج می‌شوند (bidi legacy).
    ارقام از rawdict به ترتیب x واقعی صفحه خوانده می‌شوند — عدد همیشه چپ→راست خوانده می‌شود."""
    runs_txt = list(re.finditer(r'[0-9]{2,}', txt))
    if not runs_txt: return txt
    try:
        rd = page.get_text('rawdict', clip=fitz.Rect(rect))
    except Exception:
        return txt
    digs = []
    for blk in rd.get('blocks', []):
        if blk.get('type') != 0: continue
        for ln in blk.get('lines', []):
            for sp in ln.get('spans', []):
                for ch in sp.get('chars', []):
                    c = ch.get('c', '')
                    if c in '0123456789۰۱۲۳۴۵۶۷۸۹':
                        r = fitz.Rect(ch['bbox'])
                        digs.append((r.x0, fa2en(c)))
    if not digs: return txt
    digs.sort()
    vruns = []
    curr = ''
    prev_x1 = None
    for x, c in digs:
        if prev_x1 is not None and x - prev_x1 > 7.5:
            if len(curr) >= 1: vruns.append((run_x0, curr))
            curr = ''
        if curr == '': run_x0 = x
        curr += c
        prev_x1 = x
    if curr: vruns.append((run_x0, curr))
    vruns_big = [(x, v) for x, v in vruns if len(v) >= 2]
    if len(vruns_big) != len(runs_txt): return txt
    # چندمجموعه ارقام باید یکی باشد
    if sorted(''.join(v for _, v in vruns_big)) != sorted(''.join(m.group(0) for m in runs_txt)):
        return txt
    # سطر فارسی RTL: اولین عدد در متنِ منطقی = راست‌ترین عدد صفحه
    vsorted = [v for _, v in sorted(vruns_big, key=lambda t: -t[0])]
    for m, v in zip(runs_txt, vsorted):
        if len(m.group(0)) != len(v): return txt
    out = []
    last = 0
    for m, v in zip(runs_txt, vsorted):
        out.append(txt[last:m.start()]); out.append(v); last = m.end()
    out.append(txt[last:])
    return ''.join(out)

def row_chars_text(page, rect, frac_spans=None):
    """بازسازی متن یک سطر فارسی از سطح کاراکتر (rawdict):
    فاصله‌ها از فاصله واقعی گلیف‌ها تعیین می‌شوند — نه مرزهای شکسته span ها.
    این تابع خطاهایی مثل «کهت عدادشان» (فاصله جابه‌جا) و «هوایرشت» (فاصله حذف‌شده)
    را ریشه‌ای حل می‌کند. فقط برای سطرهای غیرریاضی استفاده شود."""
    try:
        rd = page.get_text('rawdict', clip=fitz.Rect(rect))
    except Exception:
        return None
    chars = []
    for blk in rd.get('blocks', []):
        if blk.get('type') != 0: continue
        for ln in blk.get('lines', []):
            for sp in ln.get('spans', []):
                for ch in sp.get('chars', []):
                    c = ch.get('c', '')
                    if not c or c == '\x00': continue
                    r = fitz.Rect(ch['bbox'])
                    cx, cy = (r.x0 + r.x1) / 2, (r.y0 + r.y1) / 2
                    if not (rect.x0 - 1.5 <= cx <= rect.x1 + 1.5 and rect.y0 - 1.5 <= cy <= rect.y1 + 1.5):
                        continue
                    if c.isspace(): continue          # فاصله‌ها از روی گپ گلیف‌ها ساخته می‌شوند
                    if frac_spans and any(fr['r'].x0 - 1 <= cx <= fr['r'].x1 + 1 and fr['r'].y0 - 1 <= cy <= fr['r'].y1 + 1 for fr in frac_spans):
                        continue                       # داخل ناحیه کسر مونتاژ‌شده — جداگانه درج می‌شود
                    chars.append((r.x0, r.x1, r.height, c))
    if not chars: return None
    # حذف گلیف‌های ناهنجار (اشکال/واترمارک با bbox خیلی بزرگ نسبت به میانه سطر)
    hs0 = sorted(t[2] for t in chars)
    h_med0 = hs0[len(hs0) // 2] if hs0 else 12
    chars = [t for t in chars if t[2] <= 2.6 * h_med0 and (t[1] - t[0]) <= 6.0 * h_med0]
    if not chars: return None
    chars.sort(key=lambda t: -((t[0] + t[1]) / 2))    # راست → چپ = ترتیب منطقی RTL
    # آستانه فاصله کلمه: تفکیک خوشه «گپ حروف» از «گپ فاصله» با بزرگ‌ترین پرش نسبی
    gaps_all = []
    prev0 = None
    for x0, x1, h, c in chars:
        if prev0 is not None:
            gaps_all.append(prev0 - x1)
        prev0 = x0
    # خوشه‌بندی دوتایی گپ‌ها (۱بعدی): مرز = وسط بزرگ‌ترین پرش در ناحیه ممکن فاصله
    pos = sorted(g for g in gaps_all if -0.5 < g < 20)
    gap_thr = 2.0
    if len(pos) >= 4:
        cands = []
        for i in range(len(pos) - 1):
            lo, hi = pos[i], pos[i + 1]
            jump = hi - lo
            mid = (lo + hi) / 2
            # مرز معتبر: بالای 0.8pt و آستانه بین 0.8 و 6 (فاصله کلمه فونت‌های فارسی)
            if jump >= 0.8 and 0.8 <= mid <= 6.0:
                cands.append((jump, mid))
        if cands:
            # اولین مرز معتبر (کوچک‌ترین آستانه) = جداکننده گپ حروف/فاصله کلمه؛
            # مرزهای بزرگ‌تر جداکننده فاصله/تب هستند و نباید انتخاب شوند
            cands.sort(key=lambda c: c[1])
            gap_thr = cands[0][1]
        else:
            ws = sorted(t[1] - t[0] for t in chars)
            w_med = ws[len(ws) // 2] if ws else 5.0
            gap_thr = max(1.6, 0.45 * w_med)
    out = []
    prev = None
    for x0, x1, h, c in chars:
        if prev is not None:
            gap = prev[0] - x1                        # فاصله افقی واقعی بین دو گلیف
            if gap > gap_thr:
                out.append(' ')
        out.append(c)
        prev = (x0, x1)
    txt = ''.join(out)
    # جزیره‌های LTR (تکنیک arafix): تاریخ 1404/08/26، ساعت 8:30، بازه 12-18 در قرائت
    # راست→چپ به‌صورت وارونه جزء‌به‌جزء درمی‌آیند (26/08/1404) → کل جزیره برگردانده شود
    def _flip_island(m):
        seg = m.group(0)
        parts = re.split(r'([/:.\-])', seg)
        return ''.join(parts[::-1])
    txt = re.sub(r'[0-9]{1,4}(?:[/:.\-][0-9]{1,4}){1,4}', _flip_island, txt)
    # درج کسرهای مونتاژ‌شده در جای درست: به‌عنوان توکن در جریان راست→چپ گلیف‌ها
    if frac_spans:
        stream = [((t[0] + t[1]) / 2, t[3], False) for t in chars]   # (مرکز x، متن، کسر؟)
        for fr in frac_spans:
            stream.append(((fr['r'].x0 + fr['r'].x1) / 2, ' ' + fr['t'].strip() + ' ', True))
        stream.sort(key=lambda t: -t[0])
        rebuilt = []
        pi = 0   # اشاره‌گر متن txt (که فاصله‌گذاری درست دارد)
        for cx0, tok, is_frac in stream:
            if is_frac:
                rebuilt.append(tok)
            else:
                # کاراکتر بعدی متن + فاصله‌های پیش از آن
                while pi < len(txt) and txt[pi].isspace():
                    rebuilt.append(txt[pi]); pi += 1
                if pi < len(txt):
                    rebuilt.append(txt[pi]); pi += 1
        rebuilt.append(txt[pi:])
        txt = ''.join(rebuilt)
    # ران‌های رقمی/لاتین در قرائت راست→چپ معکوس خوانده می‌شوند → برگردان هر ران
    txt = re.sub(r'[0-9]{2,}', lambda m: m.group(0)[::-1], txt)
    txt = re.sub(r'[A-Za-z]{2,}', lambda m: m.group(0)[::-1], txt)
    txt = unicodedata.normalize('NFKC', txt)
    txt = re.sub(r'\s{2,}', ' ', txt).strip()
    return txt

def collect_rows(page):
    """ردیف‌های بصری صفحه: قطعات هر ردیف راست→چپ (ترتیب منطقی RTL) به هم می‌چسبند —
    همان رشته‌ای که در Word تایپ شده و مرورگر RTL عیناً بازسازی‌اش می‌کند."""
    d = page.get_text('dict')
    spans = []
    for blk in d.get('blocks', []):
        if blk.get('type') != 0: continue
        for ln in blk.get('lines', []):
            for sp in ln.get('spans', []):
                t = sp.get('text', '')
                if not t.strip(): continue
                if WATERMARK_PAT.match(t.strip()): continue
                spans.append({'t': t, 'r': fitz.Rect(sp['bbox']), 'f': sp.get('font', '')})
    if not spans: return []
    spans = assemble_fractions(page, spans)
    spans.sort(key=lambda s: ((s['r'].y0 + s['r'].y1) / 2, s['r'].x0))
    rows = []           # هر سطر: {'spans':[…], 'cy': مرکز خط بر اساس قطعات کوتاه}
    heights = sorted(x['r'].height for x in spans)
    h_med = heights[len(heights) // 2] if heights else 12
    tol = max(5.0, 0.55 * h_med)
    for s0 in spans:
        cy = (s0['r'].y0 + s0['r'].y1) / 2
        tall = s0['r'].height > 1.9 * h_med
        placed = False
        for row in rows:
            if abs(cy - row['cy']) <= (tol * (1.8 if tall else 1.0)):
                row['spans'].append(s0)
                if not tall:
                    cores = [x for x in row['spans'] if x['r'].height <= 1.9 * h_med]
                    row['cy'] = sum((x['r'].y0 + x['r'].y1) / 2 for x in cores) / len(cores)
                placed = True; break
        if not placed:
            rows.append({'spans': [s0], 'cy': cy})
    rows = [r['spans'] for r in rows]
    ph = page.rect.height
    out = []
    segs_all = []
    for row in rows:
        row.sort(key=lambda s: -s['r'].x1)     # راست → چپ = ترتیب منطقی RTL
        # شکستن ردیف به قطعه‌ها در فاصله‌های افقی بزرگ (ستون ردیف/بارم/ستون‌های موازی)
        pw_ = page.rect.width
        segs = [[row[0]]]
        for s in row[1:]:
            gap_ = segs[-1][-1]['r'].x0 - s['r'].x1
            # ستون «ردیف» (x>=0.86pw) همیشه قطعه جدا — حتی با فاصله کم
            leaving_rowcol = segs[-1][-1]['r'].x0 >= pw_ * 0.86 and s['r'].x1 < pw_ * 0.86
            if gap_ > 12 or (leaving_rowcol and gap_ > 2):
                segs.append([s])
            else:
                segs[-1].append(s)
        segs_all.extend(segs)
    for row in segs_all:
        txt = ''
        prev = None
        for s in row:
            if prev is not None and (prev['r'].x0 - s['r'].x1) > 1.5:
                txt += ' '
            txt += s['t']
            prev = s
        R = row[0]['r']
        for s in row[1:]: R = rect_union(R, s['r'])
        # قطعه ریاضی خالص (بدون فارسی): بازخوانی حرف‌به‌حرف به ترتیب چپ→راست
        has_frac = any(s['f'] == 'synth-frac' for s in row)
        if not FA_LETTER.search(txt) and MATHY_SEG.search(txt) and not has_frac:
            mt = math_chars_text(page, R)
            if mt: txt = mt
        elif not FA_LETTER.search(txt) and has_frac:
            # قطعه ریاضی دارای کسر پله‌ای: اجزا چپ→راست مرتب شوند (ترتیب ریاضی واقعی)
            lr = sorted(row, key=lambda s0: s0['r'].x0)
            txt = ' '.join(x['t'].strip() for x in lr if x['t'].strip())
            txt = txt.translate(str.maketrans('()[]{}', ')(][}{')) if re.search(r'[()]', txt) and re.search(r'[()]', txt).group(0) == ')' else txt
            txt = re.sub(r'\s{2,}', ' ', txt).strip()
        elif FA_LETTER.search(txt) and MATHY_SEG.search(txt) and not has_frac:
            # قطعه مخلوط: برچسب/کلمات فارسی + عبارت ریاضی LTR جابه‌جاشده.
            # اگر بخش فارسی فقط برچسب کوتاه است (الف/ب/ج/د/ه/و/ی + نهایتاً یک عدد بارم)،
            # ریاضی را حرف‌به‌حرف بازمی‌خوانیم و برچسب را جلوی آن می‌گذاریم.
            fa_spans = [s0 for s0 in row if FA_LETTER.search(s0['t'])]
            other = [s0 for s0 in row if not FA_LETTER.search(s0['t'])]
            fa_txt = ' '.join(s0['t'].strip() for s0 in fa_spans).strip()
            fa_core = re.sub(r'[\s()​]+', '', fa_txt)
            if other and len(fa_core) <= 4 and re.fullmatch(r'[الفبجدهویـ‌]+', fa_core or 'x') :
                # تشخیص هوشمند بارم: جفت «N/N» مجاور یا عدد ایزوله ستون کناری
                sc_drop = find_score_tokens(row, page.rect.width)
                mspans = [s0 for s0 in other if id(s0) not in sc_drop]
                if mspans:
                    mt = math_chars_text(page, [s0['r'] for s0 in mspans])
                    if mt:
                        lab = fa_core if fa_core else ''
                        txt = (lab + ') ' if lab else '') + mt
        # آخرین شانس متن‌سازی: الگوی معکوس «= (» در ابتدای متن یا «(د /0» ➜ بازخوانی حرف‌به‌حرف
        if MATHY_SEG.search(txt) and (txt.lstrip().startswith('=') or re.search(r'=\s*\([0-9+\-]', txt) or re.search(r'\([ا-ی0-9۰-۹]{1,3}\s*$', txt) or re.search(r'[0-9]\s*/\s*/[0-9]', txt)):
            # بارم Bold را جدا کن
            sc_drop2 = find_score_tokens(row, page.rect.width)
            mspans = [s0 for s0 in row if id(s0) not in sc_drop2]
            labs = [s0 for s0 in mspans if FA_LETTER.search(s0['t'])]
            lab_txt = re.sub(r'[^ا-ی]', '', ' '.join(x['t'] for x in labs))
            if mspans and len(lab_txt) <= 3:
                mt = math_chars_text(page, [s0['r'] for s0 in mspans])
                if mt:
                    # برچسب فارسی داخل خروجی «(ی)…» یا «0/5ی(…» → جدا به‌صورت «ی) »
                    mlab = re.match(r'^\s*(?:[0-9]+\s*/\s*[0-9]+\s*)?\(?\s*([ا-ی]{1,3})\s*\)?\s*(.*)$', mt)
                    if mlab and re.sub(r'[^ا-ی]', '', mlab.group(1)) == lab_txt and lab_txt:
                        txt = lab_txt + ') ' + mlab.group(2).strip()
                    elif lab_txt and lab_txt not in mt:
                        txt = lab_txt + ') ' + mt
                    else:
                        txt = mt
                    # پرانتز باز یتیم ابتدای عبارت (مرز برچسب): «د) (- 1 + 4 =» → حذف
                    mhead = re.match(r'^(\s*[ا-ی]{1,3}\s*\)\s*)\((.*)$', txt)
                    if mhead:
                        rest = mhead.group(2)
                        if rest.count('(') + 1 == rest.count(')') + 1 and rest.count('(') == rest.count(')') and rest.rstrip().endswith('='):
                            txt = mhead.group(1) + rest.lstrip()
        garb = is_garbled(txt)
        # v4.109.0: سطر فارسی غیرریاضی → بازسازی متن از سطح کاراکتر (فاصله‌گذاری واقعی گلیف‌ها)
        row_fracs = [s0 for s0 in row if s0['f'] == 'synth-frac']
        txt_nofrac = re.sub(r'[0-9۰-۹]+\s*\u2044\s*[0-9۰-۹]+', '', txt)
        if (not garb and FA_LETTER.search(txt)
                and not re.search(r'[=×÷−±√]', txt_nofrac)
                and not re.search(r'[a-zA-Z\U0001D400-\U0001D7FF]\s*[-+]|[-+]\s*[a-zA-Z\U0001D400-\U0001D7FF]', txt_nofrac)
                and txt.count('(') + txt.count(')') <= 2):
            ct = row_chars_text(page, R, frac_spans=[{'r': s0['r'], 't': s0['t']} for s0 in row_fracs])
            if ct:
                # صحت: چندمجموعه حروف (بدون فاصله/اعراب/ارقام) باید یکسان بماند
                a = sorted(re.sub(r'[\s0-9۰-۹\u2044/.]+', '', strip_diac(txt)))
                b = sorted(re.sub(r'[\s0-9۰-۹\u2044/.]+', '', strip_diac(ct)))
                if a == b:
                    txt = ct
        txt = txt.replace('\x00', '□')
        txt, conf = fix_rtl(txt)
        if not conf: garb = True
        # اعتبارسنجی عبارت ریاضی برچسب‌دار/خالص: نامعتبر → برش تصویری همان خط
        fa_only = re.sub(r'[^\u0600-\u06FF]', '', txt)
        if not garb and MATHY_SEG.search(txt) and len(fa_only) <= 3 and re.search(r'[()=]', txt):
            if not valid_math_line(txt): garb = True
        # برچسب گزینه انتهایی در سطرهای ریاضی: «t + 3a (1» → «1) t + 3a»
        if not FA_LETTER.search(txt):
            mt2 = re.match(r'^(.*?)[\s]*\(\s*([1-4۱-۴])\s*$', txt)
            if mt2 and re.search(r'[0-9a-zA-Z]', mt2.group(1)):
                txt = fa2en(mt2.group(2)) + ') ' + mt2.group(1).strip()
        if (HAS_FA.search(txt) and re.search(r'[0-9]{2,}', txt)
                and not re.search(r'[=×÷]', txt) and txt.count('(') + txt.count(')') <= 1):
            txt = fix_digit_runs(page, R, txt)
        t0 = txt.strip()
        if R.y0 > ph - 45 and (FOOTER_PAT.search(t0) or len(t0) < 30):
            continue
        if WATERMARK_PAT.match(t0) or re.fullmatch(r'[.\s]*موفق باشید[.\s]*|[.\s]*دیشاب قفوم[.\s]*', t0):
            continue
        if re.search(r'ادامه سو ?ا ?لات|ادامه سواالت|تلااوس همادا', t0) or re.fullmatch(r'صفحه (اول|دوم|سوم|چهارم|پنجم)', t0) or re.fullmatch(r'(یادتون نره )?دوست ?تون دارم[.\s]*', t0):
            continue
        out.append({'text': t0, 'rect': R, 'garbled': garb})
    # برچسب یتیم «(الف» کنار قطعه ریاضی همان ردیف → ادغام
    consumed = set()
    replace_map = {}
    for i, l in enumerate(out):
        lab = re.sub(r'[^ا-ی]', '', l['text'])
        if 1 <= len(lab) <= 3 and re.fullmatch(r'[\s()ا-ی\-–]+', l['text']) and lab in ('الف','ب','ج','د','ه','هـ','و','ی'):
            best = None; bestgap = 1e9
            for k, o in enumerate(out):
                if k == i or k in consumed: continue
                if re.match(r'^\s*[ا-ی]{1,3}\s*\)', o['text']): continue   # قبلاً برچسب دارد
                ov = min(l['rect'].y1, o['rect'].y1) - max(l['rect'].y0, o['rect'].y0)
                if ov < 0.25 * min(l['rect'].height, o['rect'].height) and abs((l['rect'].y0+l['rect'].y1)/2-(o['rect'].y0+o['rect'].y1)/2) > 8: continue
                if not MATHY_SEG.search(o['text']) or FA_LETTER.search(o['text']): continue
                gap = min(abs(l['rect'].x0 - o['rect'].x1), abs(o['rect'].x0 - l['rect'].x1))
                if gap < bestgap: bestgap, best = gap, k
            if best is not None and bestgap < 40:
                o = out[best]
                replace_map[best] = {'text': lab + ') ' + o['text'].lstrip('= ').strip(),
                                     'rect': rect_union(l['rect'], o['rect']),
                                     'garbled': l['garbled'] or o['garbled']}
                consumed.add(i); consumed.add(best)
    merged_out = []
    for i, l in enumerate(out):
        if i in replace_map: merged_out.append(replace_map[i])
        elif i in consumed: continue
        else: merged_out.append(l)
    out = merged_out
    out.sort(key=lambda l: (round(l['rect'].y0, 1), -l['rect'].x1))
    return out

# ---------------------------------------------------------------- تحلیل اصلی
def analyze(pdf_path, subject='', grade='', dpi=200):
    doc = fitz.open(pdf_path)
    # پیش‌اسکن: متن‌هایی که در ≥۳ صفحه تکرار می‌شوند = سربرگ/پاصفحه/واترمارک متنی
    page_rows = [collect_rows(pg) for pg in doc]
    seen_pages = {}
    for pi, rows0 in enumerate(page_rows):
        for l in rows0:
            k = re.sub(r'\s+', ' ', l['text']).strip()
            if len(k) < 4: continue
            seen_pages.setdefault(k, set()).add(pi)
    repeated = {k for k, ps in seen_pages.items() if len(ps) >= 3}
    questions = []
    cur = None
    stop_all = False
    scan_qs = []
    for pno, page in enumerate(doc):
        if stop_all: break
        lines = [l for l in page_rows[pno]
                 if re.sub(r'\s+', ' ', l['text']).strip() not in repeated]
        pw = page.rect.width
        if not lines and page.get_image_info():
            for blk in split_scanned_page(page, dpi):
                scan_qs.append(blk)
            continue
        # حالت جدول برگه ایرانی: ستون «ردیف» راست + ستون «بارم» چپ
        cand_marks = [l for l in lines
                      if re.fullmatch(r'[0-9]{1,3}', fa2en(l['text']).strip())
                      and l['rect'].x0 >= pw * 0.85]
        # مارکر چسبیده به متن سوال: «N متن...» که انتهایش (x1) داخل ستون ردیف است
        for l in lines:
            en_l = fa2en(l['text'])
            mm = re.match(r'^([0-9]{1,2})\s{1,3}(\S.*)$', en_l)
            if mm and l['rect'].x1 >= pw * 0.9 and re.search(r'[\u0600-\u06FF]', l['text']):
                virt = {'text': mm.group(1), 'rect': fitz.Rect(l['rect'].x1 - 8, l['rect'].y0, l['rect'].x1, l['rect'].y1),
                        'garbled': False, '_host': l, '_body': mm.group(2)}
                cand_marks.append(virt)
        row_marks = []
        if cand_marks:
            clusters = []
            for l in sorted(cand_marks, key=lambda l: -l['rect'].x0):
                placed = False
                for cl in clusters:
                    if abs(cl[0]['rect'].x0 - l['rect'].x0) <= 12: cl.append(l); placed = True; break
                if not placed: clusters.append([l])
            clusters.sort(key=lambda cl: (len(cl), cl[0]['rect'].x0), reverse=True)
            best = clusters[0]
            if len(best) >= 2 or (len(best) == 1 and cur is not None):
                row_marks = best
        score_toks = [l for l in lines
                      if re.fullmatch(r'[0-9]{1,3}\s*(?:[./,⁄]\s*[0-9]{1,2})?', fa2en(l['text']).strip())
                      and l['rect'].x1 <= pw * 0.19]
        table_mode = len(row_marks) >= 2 or (len(row_marks) >= 1 and cur is not None)
        marks = sorted(row_marks, key=lambda l: l['rect'].y0) if table_mode else []
        if table_mode:
            drop = set(id(l) for l in row_marks) | set(id(l) for l in score_toks)
            # مارکر مجازی: شماره را از متن میزبان جدا کن
            for mk in row_marks:
                if '_host' in mk and id(mk['_host']) not in drop:
                    mk['_host']['text'] = mk['_body']
            lines = [l for l in lines if id(l) not in drop]
        first_q_y = None
        if table_mode:
            first_q_y = marks[0]['rect'].y0
        else:
            for l in lines:
                en0 = fa2en(l['text'])
                m = Q_START.match(en0) or re.match(r'^(.{6,}?)\s*[-–—ـ]\s*([0-9]{1,3})\s*$', en0)
                if m and (not OPT_START.match(l['text']) or looks_like_question_start(l['text'])):
                    first_q_y = l['rect'].y0; break
        if first_q_y is None:
            first_q_y = 0 if cur is not None else page.rect.height
        mi = 0
        for l in lines:
            plain = l['text']
            en = fa2en(plain)
            if ANSWER_KEY.search(plain) or ANSWER_KEY.search(plain[::-1]) or ANSWER_SOL.search(plain):
                stop_all = True; answer_y = l['rect'].y0; break
            if table_mode:
                while mi < len(marks) and (l['rect'].y0 + l['rect'].y1) / 2 >= marks[mi]['rect'].y0 - 2:
                    if cur: questions.append(cur)
                    cur = {'no': fa2en(marks[mi]['text']).strip(), 'items': [],
                           'rect': fitz.Rect(marks[mi]['rect']), 'page': pno}
                    mi += 1
                if cur is None: continue
                if l['garbled']:
                    b64, w, h = crop_b64(page, fitz.Rect(l['rect'].x0 - 2, l['rect'].y0 - 2, l['rect'].x1 + 2, l['rect'].y1 + 2), dpi)
                    if b64: cur['items'].append(('img', b64, w, h))
                    cur['rect'] = rect_union(cur['rect'], l['rect'])
                    continue
                if re.fullmatch(r'[0-9۰-۹]{1,3}\s*(?:[./,⁄]\s*[0-9۰-۹]{1,2})?', fa2en(plain).strip()) and l['rect'].x1 <= pw * 0.25:
                    cur['rect'] = rect_union(cur['rect'], l['rect']); continue
                opts_in_line = split_options(plain)
                if opts_in_line:
                    for o in opts_in_line: cur['items'].append(('opt', o))
                elif OPT_START.match(plain) or re.match(r'^\s*[1-4۱-۴]\s*\)', plain):
                    cur['items'].append(('opt', norm_opt(plain.strip())))
                else:
                    cur['items'].append(('text', plain.strip()))
                cur['rect'] = rect_union(cur['rect'], l['rect'])
                continue
            mq = Q_START.match(en)
            if not mq:
                # خط منفرد «- N» (فقط شماره سوال، متن در خطوط بعدی)
                msolo = re.fullmatch(r'\s*[-–—ـ]?\s*([0-9]{1,3})\s*[-–—ـ]?\s*[◯○]?\s*', en)
                if msolo:
                    prev_solo = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                    if prev_solo + 1 <= int(msolo.group(1)) <= prev_solo + 2 and l['rect'].x1 >= pw * 0.5:
                        class _M5:
                            def __init__(self, no, body): self._g = {1: no, 2: body}
                            def group(self, i): return self._g[i]
                        mq = _M5(msolo.group(1), '')
            if not mq:
                me = re.match(r'^(.{6,}?)\s*[-–—ـ]\s*([0-9]{1,3})\s*$', en)
                if me and re.search(r'[\u0600-\u06FF]', plain) and not SCORE_PAT.search(me.group(1)[-14:]):
                    class _M:
                        def __init__(self, no, body): self._g = {1: no, 2: body}
                        def group(self, i): return self._g[i]
                    mq = _M(me.group(2), me.group(1).strip())
            if not mq:
                # شماره سوال داخل متن: «... اگر- N» یا «... است- N» که N دنباله بعدی است
                prev_no1 = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                mi2 = re.search(r'[-–—ـ]\s*([0-9]{1,9})(?![0-9])', en)
                no_pick = None; rest_digits = ''
                if mi2:
                    dg = mi2.group(1)
                    if len(dg) <= 3 and prev_no1 + 1 <= int(dg) <= prev_no1 + 2:
                        no_pick = dg
                    else:
                        for cut in (1, 2):
                            if cut < len(dg) and prev_no1 + 1 <= int(dg[:cut]) <= prev_no1 + 2:
                                no_pick = dg[:cut]; rest_digits = dg[cut:]
                                break
                if mi2 and no_pick:
                    fa_txt = (plain[:mi2.start()] + ' ' + rest_digits + ('' if not rest_digits else ' ') + plain[mi2.end():]).strip() if len(plain) == len(en) else (en[:mi2.start()] + ' ' + rest_digits + ' ' + en[mi2.end():]).strip()
                    if re.search(r'[\u0600-\u06FF]', fa_txt) and len(fa_txt) >= 10:
                        class _M4:
                            def __init__(self, no, body): self._g = {1: no, 2: body}
                            def group(self, i): return self._g[i]
                        mq = _M4(no_pick, fa_txt)
            if not mq:
                # bidi سخت: «متن سوال ... -N ریاضی» (شماره وسط، دنباله عبارت ریاضی)
                me2 = re.match(r'^(.{6,}?[\u0600-\u06FF][^-–—ـ]*)[-–—ـ]\s*([0-9]{1,3})(?![0-9])\s*(.*)$', en)
                if me2 and re.search(r'[\u0600-\u06FF]', me2.group(1)):
                    prev_no0 = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                    digits = me2.group(2); tail = me2.group(3).strip()
                    cand = int(digits)
                    if not (prev_no0 + 1 <= cand <= prev_no0 + 3):
                        # شماره چسبیده به عدد عبارت (مثل 51− که 5 شماره است) — برش پیشوندی
                        for cut in (1, 2):
                            if cut < len(digits) and prev_no0 + 1 <= int(digits[:cut]) <= prev_no0 + 3:
                                tail = (digits[cut:] + ' ' + tail).strip()
                                cand = int(digits[:cut])
                                break
                    if prev_no0 + 1 <= cand <= prev_no0 + 3 and (not tail or MATHY_SEG.search(tail) or re.fullmatch(r'[0-9−+\-. ]+', tail)):
                        class _M3:
                            def __init__(self, no, body): self._g = {1: no, 2: body}
                            def group(self, i): return self._g[i]
                        mq = _M3(str(cand), (me2.group(1) + (' ' + tail if tail else '')).strip())
            opt_m = OPT_START.match(plain)
            if opt_m and mq and re.fullmatch(r'[1-4۱-۴]', opt_m.group(1) or ''):
                # ابهام «۳-»: دنباله شماره سوال یا جمله بلند سوال → سوال است نه گزینه
                prev_q = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                cand_no = int(fa2en(opt_m.group(1)))
                if (prev_q + 1 <= cand_no <= prev_q + 2 and re.match(r'^\s*[0-9۰-۹]{1,3}\s*[-–—ـ]', fa2en(plain))) or looks_like_question_start(plain):
                    opt_m = None
            is_q_start = bool(mq) and not opt_m and l['rect'].y0 >= first_q_y - 1
            if is_q_start:
                raw_no = fa2en(str(mq.group(1)))
                body0 = str(mq.group(2)).strip()
                prev_no = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                if len(raw_no) >= 2 and int(raw_no) > prev_no + 3:
                    # شماره چسبیده به عدد متن (bidi مثل «620-») — پیشوند سازگار با دنباله را جدا کن
                    for cut in (1, 2):
                        head = raw_no[:cut]
                        if head.isdigit() and len(raw_no) > cut and prev_no + 1 <= int(head) <= prev_no + 3:
                            body0 = (raw_no[cut:] + ' ' + body0).strip()
                            raw_no = head
                            break
                class _MM:
                    def __init__(self, no, body): self._g = {1: no, 2: body}
                    def group(self, i): return self._g[i]
                mq = _MM(raw_no, body0)
                new_no = int(raw_no or 0)
                max_no = max([int(q['no']) for q in questions if str(q.get('no','')).isdigit()] + ([int(cur['no'])] if cur and str(cur.get('no','')).isdigit() else [0]) + [0])
                # ریست شماره به ≤2 بعد از رسیدن به ≥4 → شروع بخش پاسخ تشریحی
                if max_no >= 4 and new_no <= 2:
                    stop_all = True; answer_y = l['rect'].y0; break
                if cur: questions.append(cur)
                m2 = Q_START.match(fa2en(plain))
                body = m2.group(2).strip() if m2 else str(mq.group(2)).strip()
                cur = {'no': fa2en(mq.group(1)), 'items': [], 'rect': fitz.Rect(l['rect']), 'page': pno}
                if body: cur['items'].append(('text', body))
                continue
            if cur is None: continue
            if l['garbled']:
                b64, w, h = crop_b64(page, fitz.Rect(l['rect'].x0 - 2, l['rect'].y0 - 2, l['rect'].x1 + 2, l['rect'].y1 + 2), dpi)
                if b64: cur['items'].append(('img', b64, w, h))
                cur['rect'] = rect_union(cur['rect'], l['rect'])
                continue
            opts_in_line = split_options(plain)
            if opts_in_line:
                for o in opts_in_line: cur['items'].append(('opt', o))
            elif OPT_START.match(plain):
                cur['items'].append(('opt', norm_opt(plain.strip())))
            else:
                cur['items'].append(('text', plain.strip()))
            cur['rect'] = rect_union(cur['rect'], l['rect'])
        # بارم ستون چپ → سوال هم‌ردیف
        if table_mode:
            page_qs = [q for q in questions if q['page'] == pno] + ([cur] if cur and cur['page'] == pno else [])
            for st in score_toks:
                cy = (st['rect'].y0 + st['rect'].y1) / 2
                for q in page_qs:
                    if q['rect'].y0 - 6 <= cy <= q['rect'].y1 + 20:
                        sc = fa2en(st['text']).strip().replace(',', '.').replace('⁄', '.')
                        m = re.fullmatch(r'([0-9]+)\s*[./]\s*([0-9]+)', sc)
                        if m: sc = m.group(2) + '.' + m.group(1)
                        q.setdefault('score_col', sc)
                        break
        # شکل‌های واقعی صفحه → سوال هم‌ناحیه
        figs = figure_rects(page, first_q_y if not table_mode else 0)
        all_q = [q for q in questions if q['page'] == pno] + ([cur] if cur and cur['page'] == pno else [])
        ans_y = locals().get('answer_y', None) if stop_all else None
        for fr in figs:
            if ans_y is not None and (fr.y0 + fr.y1) / 2 >= ans_y: continue
            cy = (fr.y0 + fr.y1) / 2
            target = None
            for q in all_q:
                if q['rect'].y0 - 6 <= cy <= q['rect'].y1 + 60: target = q
            if target is None and all_q:
                above = [q for q in all_q if q['rect'].y0 <= cy]
                target = above[-1] if above else all_q[0]
            if target is not None:
                target.setdefault('fig_rects', []).append(fitz.Rect(fr))
                target['rect'] = rect_union(target['rect'], fr)
        # برش نهایی شکل‌ها: نواحی نزدیک (فاصله <25pt) یکی می‌شوند تا شکل تکه‌تکه نشود
        for q in all_q:
            frs = q.pop('fig_rects', None)
            if not frs: continue
            merged = []
            for r in sorted(frs, key=lambda r: (r.y0, r.x0)):
                if merged:
                    m = merged[-1]
                    infl = fitz.Rect(m.x0 - 25, m.y0 - 25, m.x1 + 25, m.y1 + 25)
                    if infl.intersects(r):
                        merged[-1] = rect_union(m, r); continue
                merged.append(fitz.Rect(r))
            # دور دوم ادغام (نواحی غیرمجاور در لیست)
            changed = True
            while changed:
                changed = False
                for i in range(len(merged)):
                    for k in range(i + 1, len(merged)):
                        infl = fitz.Rect(merged[i].x0 - 25, merged[i].y0 - 25, merged[i].x1 + 25, merged[i].y1 + 25)
                        if infl.intersects(merged[k]):
                            merged[i] = rect_union(merged[i], merged[k])
                            del merged[k]; changed = True; break
                    if changed: break
            for r in merged:
                # برچسب‌های متنی چسبیده به شکل (اندازه ضلع/متغیر) داخل برش بیایند
                grow = fitz.Rect(r)
                for tl in page_rows[pno]:
                    tr = tl['rect']
                    infl = fitz.Rect(grow.x0 - 10, grow.y0 - 10, grow.x1 + 10, grow.y1 + 10)
                    if infl.intersects(tr) and tr.width < 90 and tr.height < 22:
                        grow = rect_union(grow, tr)
                b64, w, h = crop_b64(page, fitz.Rect(grow.x0 - 8, grow.y0 - 12, grow.x1 + 8, grow.y1 + 12), dpi)
                if b64: q['items'].append(('img', b64, w, h))
    if cur and not any(q is cur for q in questions): questions.append(cur)
    for blk in scan_qs:
        questions.append({'no': '', 'items': [('img', blk['b64'], blk['w'], blk['h'])],
                          'rect': fitz.Rect(0, 0, 1, 1), 'page': -1, 'scan': True})
    doc.close()

    # بازشماری در صورت تکرار شماره
    seen = set(); renum = False
    for q in questions:
        n0 = q.get('no', '')
        if n0 in seen and n0 != '': renum = True; break
        if n0: seen.add(n0)
    if renum:
        for i, q in enumerate(questions): q['no'] = str(i + 1)

    out_qs = []
    for q in questions:
        texts = [it for it in q['items'] if it[0] == 'text']
        opts  = [it for it in q['items'] if it[0] == 'opt']
        imgs  = [it for it in q['items'] if it[0] == 'img']
        raw_all = ' '.join(t[1] for t in texts) + ' ' + ' '.join(o[1] for o in opts)
        score = ''
        # بارم bidi چسبیده به انتهای اولین خط متن: «... 5 /1» = 1.5
        tail_score = ''
        if texts:
            mt0 = re.search(r'\s([0-9۰-۹])\s*/\s*([0-9۰-۹])\s*$', texts[0][1])
            if mt0:
                tail_score = fa2en(mt0.group(2)) + '.' + fa2en(mt0.group(1))
                fixed = texts[0][1][:mt0.start()].rstrip()
                q['items'][q['items'].index(texts[0])] = ('text', fixed)
                texts = [it for it in q['items'] if it[0] == 'text']
        ms = SCORE_PAT.search(fa2en(raw_all))
        if ms: score = (ms.group(1) or ms.group(2) or '').replace('⁄', '.').replace(',', '.').replace('/', '.')
        if not score and q.get('score_col'): score = q['score_col']
        if not score and tail_score: score = tail_score
        # نرمال‌سازی بارم وارونه bidi: اعشار بارم ایرانی معمولاً ۲۵/۵/۷۵ صدم است
        mrev = re.fullmatch(r'([0-9]+)\.([0-9]+)', score or '')
        if mrev:
            frac = mrev.group(2)
            swapped = mrev.group(2) + '.' + mrev.group(1)
            if frac not in ('25', '5', '75', '0') and re.fullmatch(r'[0-9]+\.(25|5|75)', swapped):
                score = swapped
        # مرتب‌سازی زیربخش‌ها بر اساس برچسب (الف، ب، ج، د، ه، و، ی)
        LAB_ORD = {'الف':0,'ب':1,'ج':2,'د':3,'ه':4,'هـ':4,'و':5,'ز':6,'ی':7,'1':0,'2':1,'3':2,'4':3,'۱':0,'۲':1,'۳':2,'۴':3}
        def opt_key(o):
            m0 = re.match(r'^\s*([ا-ی0-9۰-۹]{1,3})\s*[)\-–.]', o[1])
            return LAB_ORD.get(m0.group(1), 99) if m0 else 99
        if opts and all(opt_key(o) != 99 for o in opts):
            opts = sorted(opts, key=opt_key)
        labs_used = set()
        for o in opts:
            m0 = re.match(r'^\s*([ا-ی0-9۰-۹]{1,3})\s*[)\-–.]', o[1])
            if m0: labs_used.add(m0.group(1))
        pure_mcq = 2 <= len(opts) <= 4 and labs_used <= {'الف','ب','ج','د','1','2','3','4','۱','۲','۳','۴'}
        if pure_mcq: qtype = 'mcq'
        elif TF_HINT.search(raw_all): qtype = 'tf'
        elif DOTS_RUN.search(raw_all): qtype = 'blank'
        else: qtype = 'text'
        parts = []
        line_h = 17
        text_lines = 0
        for t in texts:
            body = tidy_text(t[1])
            body = render_line_html(body)
            body = DOTS_RUN.sub('<span class="blank-line"></span>', body)
            body = re.sub(r'[□◻☐]', '<span class="tf-square"></span>', body)
            parts.append(f'<p>{body}</p>')
            text_lines += 1
        if opts and pure_mcq:
            spans = ''.join(f'<span>{render_line_html(tidy_text(o[1]))}</span>' for o in opts)
            parts.append(f'<div class="q-options">{spans}</div>')
            text_lines += (len(opts) + 1) // 2
        elif opts:
            for o in opts:
                body = render_line_html(tidy_text(o[1]))
                body = DOTS_RUN.sub('<span class="blank-line"></span>', body)
                parts.append(f'<p>{body}</p>')
                text_lines += 1
        img_top = text_lines * line_h + 6 if text_lines else 2
        img_total_h = 0
        for i, im in enumerate(imgs):
            b64, w, h = im[1], im[2], im[3]
            disp_w = min(420, int(w * 96 / dpi))
            disp_h = int(h * disp_w / max(1, w))
            imgid = f'imp_{len(out_qs)+1}_{i+1}'
            parts.append(f'<img src="data:image/png;base64,{b64}" data-imgid="{imgid}" '
                         f'style="width:{disp_w}px;height:auto;position:absolute;left:0px;top:{img_top + img_total_h}px" '
                         f'onclick="selectQuestionImage(\'{imgid}\',this)">')
            img_total_h += disp_h + 6
        html = ''.join(parts)
        if not html.strip(): continue
        height_mm = max(12, min(120, round((text_lines * line_h + img_total_h + 14) * 25.4 / 96)))
        out_qs.append({'no': q['no'], 'question_type': qtype, 'score': score,
                       'height': height_mm, 'question_html': html})
    return {'format': 'baci-bank-import@1',
            'source_pdf': os.path.basename(pdf_path),
            'subject_name': subject, 'grade_level': grade,
            'questions': out_qs}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('pdf'); ap.add_argument('-o', '--out', default='')
    ap.add_argument('--subject', default=''); ap.add_argument('--grade', default='')
    ap.add_argument('--dpi', type=int, default=200)
    a = ap.parse_args()
    res = analyze(a.pdf, a.subject, a.grade, a.dpi)
    out = a.out or (os.path.splitext(a.pdf)[0] + '-import.json')
    with open(out, 'w', encoding='utf-8') as f:
        json.dump(res, f, ensure_ascii=False)
    print(f"{len(res['questions'])} questions -> {out}")

if __name__ == '__main__':
    main()
