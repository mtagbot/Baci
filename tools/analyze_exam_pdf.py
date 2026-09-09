#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
analyze_exam_pdf.py — تحلیل PDF نمونه سوالات آزمون (سیستم آموزشی ایران) و ساخت
فایل ایمپورت بانک سوالات (فرمت baci-bank-import@1) برای exam-bank-import.php

قابلیت‌ها:
  • تشخیص شروع هر سوال (شماره فارسی/لاتین با جداکننده‌های رایج)
  • حذف سربرگ/تبلیغ (هر چیز قبل از اولین سوال) و پاصفحه‌های تکراری
  • توقف روی پاسخ‌نامه/کلید سوالات (وارد بانک نمی‌شود)
  • گزینه‌های چندگزینه‌ای (الف/ب/ج/د یا ۱ تا ۴) → div.q-options سازگار با طراحی زنده
  • جای خالی (نقطه‌چین) → span.blank-line ، صحیح/غلط → tf-square
  • برش تصویر شکل‌ها/نمودارها و متن‌های غیرقابل استخراج (فرمول با گلیف بدون یونی‌کد)
    به‌صورت PNG base64 داخل سوال (نظیر‌به‌نظیر PDF)
  • تشخیص بارم از متن سوال (x نمره / بارم x)
  • پشتیبانی صفحات دو ستونه (ستون راست اول — RTL)

استفاده:
  python3 tools/analyze_exam_pdf.py exams/file.pdf [-o exams/file-import.json]
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

# واژگان رایج فارسی برای تشخیص جهت درست متن استخراج‌شده (visual vs logical)
_FA_WORDS = {'را','است','که','از','به','در','با','هر','این','آن','کدام','نمره','بارم','گزینه',
             'عدد','حاصل','زیر','کنید','دهید','باشید','صفحه','سوال','پاسخ','درست','غلط','صحیح',
             'الف','چند','چیست','کدامیک','مقدار','عبارت','جمله','کلمه','متن','شکل','تصویر',
             'نام','برای','یک','دو','سه','چهار','اگر','باشد','شود','می','های','ها','ترین'}

def _fa_score(t):
    toks = re.split(r'[^\w\u0600-\u06FF\u200c]+', t)
    return sum(1 for w in toks if w in _FA_WORDS)

def _pattern_bonus(t):
    b = 0
    en = fa2en(t)
    if re.match(r'^\s*(?:سوال\s*)?[0-9]{1,3}\s*[-–—ـ.)(]', en): b += 3
    if re.match(r'^\s*(الف|ب|ج|د)\s*[-–—.)(]', t): b += 3
    if len(re.findall(r'(الف|ب|ج|د|هـ)\s*[()]', t)) >= 2: b += 4  # چند گزینه در یک خط (هر جهتی)
    if re.search(r'\(\s*[0-9]+(?:[./][0-9]+)?\s*نمره\s*\)', en): b += 2
    if re.search(r'(کنید|دهید|چیست|است|بنویسید|آورید)\s*[.؟?]?', t): b += 1
    return b

def fix_rtl(text):
    """(متن اصلاح‌شده, اطمینان) — اگر متن visual (معکوس) باشد به منطقی برمی‌گردد؛
    اگر هیچ‌کدام از دو جهت متن فارسی معناداری نداشت، اطمینان False است تا
    آن خط به‌صورت تصویر (نظیر‌به‌نظیر PDF) برش بخورد."""
    if not re.search(r'[\u0600-\u06FF\uFB50-\uFEFF]', text):
        return text, True
    # NFKC: presentation forms عربی → حروف پایه
    base = unicodedata.normalize('NFKC', text)
    try:
        disp = get_display(base)
    except Exception:
        return base, True
    s0 = _fa_score(base) * 2 + _pattern_bonus(base)
    s1 = _fa_score(disp) * 2 + _pattern_bonus(disp)
    best = disp if s1 > s0 else base
    fa_chars = len(re.findall(r'[\u0600-\u06FF]', text))
    confident = max(s0, s1) >= 2 or fa_chars < 6
    return best, confident

FOOTER_PAT = re.compile(r'صفحه\s*[0-9۰-۹٠-٩]|موفق باشید|@[A-Za-z0-9_]{3,}|www\.|http')
WATERMARK_PAT = re.compile(r'(?i)^\s*(?:www[-.][\w.-]*|[\w-]*kanoon[\w.-]*|[\w.-]+\.(?:ir|com)|t\.me/\S+|@[A-Za-z0-9_]{3,})\s*$')

FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹'
AR_DIGITS = '٠١٢٣٤٥٦٧٨٩'

def fa2en(s):
    for i, d in enumerate(FA_DIGITS): s = s.replace(d, str(i))
    for i, d in enumerate(AR_DIGITS): s = s.replace(d, str(i))
    return s

# شروع سوال: «۱-»، «1)»، «سوال ۳»، «۱ .» و…
Q_START = re.compile(r'^\s*(?:سوال\s*)?([0-9۰-۹٠-٩]{1,3})\s*[-–—ـ.)("::؛]\s*(.*)$')
# گزینه‌ها: الف) ب- ج. د) یا ۱) تا ۴)
OPT_START = re.compile(r'^\s*(الف|ب|ج|د|هـ?|[1-4۱-۴])\s*[-–—.)("]\s*(.*)$')
ANSWER_KEY = re.compile(r'پاسخ\s*[‌\s]*نامه|پاسخنامه|همانخساپ|همان\s*خساپ|کلید\s*(سوالات|پاسخ|آزمون)|تالاوس\s*دیلک|جواب\s*نامه|همان\s*باوج')
SCORE_PAT = re.compile(r'([0-9۰-۹٠-٩]+(?:[./⁄,][0-9۰-۹٠-٩]+)?)\s*(?:نمره|بارم)|(?:بارم|نمره)\s*[():«»]?\s*([0-9۰-۹٠-٩]+(?:[./⁄,][0-9۰-۹٠-٩]+)?)')
DOTS_RUN = re.compile(r'(\.{4,}|…{2,}|ـ{4,})')
TF_HINT = re.compile(r'(صحیح|درست)\s*[\s□◻☐]*\s*(غلط|نادرست)|ص\s*[□◻☐]\s*غ')

OPT_ORDER = {'الف': 0, 'ب': 1, 'ج': 2, 'د': 3, 'هـ': 4, 'ه': 4,
             '1': 0, '2': 1, '3': 2, '4': 3, '۱': 0, '۲': 1, '۳': 2, '۴': 3}

def norm_opt(t):
    """پرانتز آینه‌شده bidi «الف( ۹» → «الف) ۹»"""
    return re.sub(r'^\s*(الف|ب|ج|د|هـ?|[1-4۱-۴])\s*[()]\s*', lambda m: m.group(1) + ') ', t).strip()

def split_options(line):
    """چند گزینه در یک خط → لیست گزینه‌های جدا و مرتب (الف، ب، ج، د).
    دو حالت: عادی «الف) ۹ ب) ۱۵» و آینه‌شده bidi «۱۵ب( ۹الف(»."""
    labs = list(re.finditer(r'(الف|ب|ج|د|هـ)\s*[()]', line))
    if len(labs) < 2: return None
    parts = re.split(r'(الف|ب|ج|د|هـ)\s*[()]', line)
    # parts = [pre, lab1, mid1, lab2, mid2, ...]
    pre = parts[0].strip()
    pairs = []
    if pre:   # آینه‌شده: مقدار قبل از برچسبش می‌آید
        vals = [pre] + [parts[i].strip() for i in range(2, len(parts), 2)]
        for i, li in enumerate(range(1, len(parts), 2)):
            pairs.append((parts[li], vals[i] if i < len(vals) else ''))
    else:     # عادی: مقدار بعد از برچسب
        for li in range(1, len(parts), 2):
            v = parts[li + 1].strip() if li + 1 < len(parts) else ''
            pairs.append((parts[li], v))
    pairs.sort(key=lambda pv: OPT_ORDER.get(pv[0], 9))
    return [f"{lab}) {val}".strip() for lab, val in pairs if val or lab]

def tidy_text(t):
    """پاک‌سازی آثار bidi: بارم آینه‌شده و پرانتز یتیم."""
    t = t.strip()
    # «نمره( 1متن...» یا «نم 1/5متن...» در ابتدای خط → بارم به انتهای جمله
    m = re.match(r'^(?:نمره|نم|بارم)\s*[()]?\s*([0-9۰-۹٠-٩]+(?:[./⁄][0-9۰-۹٠-٩]+)?)\s*(.+?)\s*[()]?\s*$', t)
    if m and re.search(r'[\u0600-\u06FF]', m.group(2)):
        t = m.group(2).strip() + ' (' + m.group(1) + ' نمره)'
    # پرانتز باز/بسته تکیِ انتهای خط
    t = re.sub(r'\s*[()]\s*$', '', t) if re.search(r'[()]\s*$', t) and t.count('(') != t.count(')') else t
    return t

def split_scanned_page(page, dpi):
    """صفحه اسکن‌شده → بلوک‌های سوال بر اساس نوارهای سفید افقی.
    خروجی: لیست دیکشنری‌های {'b64','w','h'} به ترتیب بالا به پایین."""
    zoom = dpi / 72.0
    pm = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), alpha=False)
    W, H, n = pm.width, pm.height, pm.n
    buf = pm.samples
    step = 2  # نمونه‌برداری ستون‌ها
    xs = list(range(0, W, step))
    # ۱) شمارش تیرگی ستونی برای یافتن خطوط عمودی جدول/قاب
    col_dark = [0] * len(xs)
    for y in range(0, H, 4):
        row = buf[y * W * n:(y + 1) * W * n]
        for i, x in enumerate(xs):
            o = x * n
            if (row[o] + row[o + 1] + row[o + 2]) < 450: col_dark[i] += 1
    rows_sampled = len(range(0, H, 4))
    vline = {i for i, c in enumerate(col_dark) if c > 0.28 * rows_sampled}  # ستون خط عمودی (اسکن‌ها بریدگی دارند)
    # حاشیه‌های قاب (۳٪ کناری) هم حذف
    margin = max(1, int(0.03 * len(xs)))
    for i in range(margin): vline.add(i); vline.add(len(xs) - 1 - i)
    keep = [i for i in range(len(xs)) if i not in vline]
    # ۲) پروفایل تیرگی هر ردیف فقط روی ستون‌های محتوا
    dark = []
    for y in range(H):
        row = buf[y * W * n:(y + 1) * W * n]
        c = 0
        for i in keep:
            o = xs[i] * n
            if (row[o] + row[o + 1] + row[o + 2]) < 450: c += 1
        dark.append(c)
    thr = max(2, len(keep) // 300)  # حداقل پیکسل تیره تا «محتوا» حساب شود
    # نوارهای محتوا
    blocks = []
    y = 0
    min_gap = max(6, int(0.008 * H))  # نوار سفید حداقل ~۰.۸٪ ارتفاع
    min_blk = max(10, int(0.012 * H))  # بلوک حداقل ~۱.۲٪ ارتفاع
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
    # گروه‌بندی خطوط به سوال: فاصله سفید بزرگ (>~۳٪ ارتفاع یا ۱.۷×میانه) = مرز سوال
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
    # گروه اول اگر کاملاً بالای صفحه و کوتاه باشد سربرگ است → حذف
    if groups and groups[0][-1][1] / H < 0.15 and len(groups) > 1:
        groups = groups[1:]
    out = []
    for g in groups:
        y0, y1 = g[0][0], g[-1][1]
        clip = fitz.Rect(0, y0 / zoom - 3, page.rect.width, y1 / zoom + 3) & page.rect
        pmc = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=clip, alpha=False)
        out.append({'b64': base64.b64encode(pmc.tobytes('png')).decode(),
                    'w': pmc.width, 'h': pmc.height,
                    'top_frac': y0 / H, 'h_frac': (y1 - y0) / H})
    return out

def esc(t):
    return (t.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;'))

def is_garbled(text):
    """متنی که استخراجش خراب است (فرمول/فونت بدون نگاشت یونی‌کد) → باید تصویر شود."""
    if not text.strip(): return False
    bad = ok = 0
    for ch in text:
        if ch.isspace(): continue
        o = ord(ch)
        if ch == '\ufffd' or 0xE000 <= o <= 0xF8FF or o in (0xFFFE, 0xFFFF): bad += 1
        elif unicodedata.category(ch).startswith(('C',)) and ch not in '\u200c\u200d\u200e\u200f': bad += 1
        else: ok += 1
    tot = bad + ok
    return tot > 0 and bad / tot > 0.34

def rect_union(a, b):
    return fitz.Rect(min(a.x0, b.x0), min(a.y0, b.y0), max(a.x1, b.x1), max(a.y1, b.y1))

def collect_lines(page):
    """خطوط متن با bbox — مرتب برای RTL (بالا→پایین؛ در صفحات دو ستونه: راست اول)."""
    d = page.get_text('dict')
    lines = []
    ph = page.rect.height
    for blk in d.get('blocks', []):
        if blk.get('type') != 0: continue
        for ln in blk.get('lines', []):
            spans = ln.get('spans', [])
            txt = ''.join(sp.get('text', '') for sp in spans)
            if not txt.strip(): continue
            r = fitz.Rect(ln['bbox'])
            garb = is_garbled(txt)
            txt = txt.replace('\x00', '□')
            txt, conf = fix_rtl(txt)
            if not conf: garb = True  # بازسازی مطمئن نبود → برش تصویری نظیر‌به‌نظیر
            # خط ریاضی برهنه (فرمول/کسر/عملیات) بدون جمله فارسی → تصویر نظیر‌به‌نظیر
            if not garb and re.search(r'[=+×÷−]|\([^)]*\)', txt):
                fa_words = re.findall(r'[\u0600-\u06FF\u200c]{2,}', txt)
                if len(fa_words) <= 1 and len(txt.strip()) >= 2:
                    garb = True
            # پاصفحه (شماره صفحه/تبلیغ/شعار) در ۴۵pt پایین صفحه → حذف
            if r.y0 > ph - 45 and (FOOTER_PAT.search(txt) or len(txt.strip()) < 30):
                continue
            # واترمارک/تبلیغ (kanoon و…) و شعار پایانی در هر جای صفحه → حذف
            if WATERMARK_PAT.match(txt.strip()) or re.fullmatch(r'[.\s]*موفق باشید[.\s]*|[.\s]*دیشاب قفوم[.\s]*', txt.strip()):
                continue
            lines.append({'text': txt.strip(), 'rect': r, 'garbled': garb})
    if not lines: return []
    # ادغام تکه‌های یک ردیف (فرگمنت‌های bidi) — راست به چپ
    lines.sort(key=lambda l: (l['rect'].y0, -l['rect'].x1))
    merged = []
    for l in lines:
        if merged:
            m = merged[-1]
            ov = min(m['rect'].y1, l['rect'].y1) - max(m['rect'].y0, l['rect'].y0)
            hh = min(m['rect'].height, l['rect'].height)
            gap = m['rect'].x0 - l['rect'].x1
            if ov > 0.55 * max(1, hh) and -6 <= gap <= 14:
                m['text'] = m['text'] + ' ' + l['text']
                m['rect'] = rect_union(m['rect'], l['rect'])
                m['garbled'] = m['garbled'] and l['garbled']
                continue
        merged.append(dict(l))
    lines = merged
    pw = page.rect.width
    mid = pw / 2
    narrow = [l for l in lines if l['rect'].width < 0.46 * pw]
    right = [l for l in narrow if l['rect'].x0 >= mid - 8]
    left = [l for l in narrow if l['rect'].x1 <= mid + 8]
    two_col = (len(narrow) > 0.62 * len(lines)) and len(right) >= 3 and len(left) >= 3
    if two_col:
        wide = [l for l in lines if l not in narrow]
        top_wide = [l for l in wide]
        cols = sorted(right, key=lambda l: l['rect'].y0) + sorted(left, key=lambda l: l['rect'].y0)
        out = sorted(top_wide, key=lambda l: l['rect'].y0) + cols
        return out
    return sorted(lines, key=lambda l: (round(l['rect'].y0, 1), -l['rect'].x1))

def figure_rects(page, first_q_y):
    """ناحیه شکل‌ها: تصاویر جاسازی‌شده + خوشه‌های وکتور (بعد از شروع سوالات)."""
    rects = []
    pw, ph = page.rect.width, page.rect.height
    for info in page.get_image_info():
        r = fitz.Rect(info['bbox'])
        if r.width < 14 or r.height < 14: continue          # آیکون‌های ریز
        if r.y1 <= first_q_y + 2: continue                   # داخل سربرگ/تبلیغ
        if r.width > 0.97 * pw and r.height > 0.97 * ph: continue  # واترمارک تمام‌صفحه
        rects.append(r)
    # وکتورها (نمودار/شکل هندسی)
    try: draws = page.get_drawings()
    except Exception: draws = []
    boxes = []
    for dr in draws:
        r = fitz.Rect(dr['rect'])
        if r.y1 <= first_q_y + 2: continue
        if r.width >= 0.9 * pw:  continue                    # خطوط جدول/کادر صفحه
        if r.width < 8 and r.height < 8: continue
        if r.height < 3 and r.width > 0.35 * pw: continue    # خط جداکننده
        if (r.width < 3 and r.height > 60) or (r.height < 3 and r.width > 60): continue  # خطوط جدول
        if r.width * r.height > 0.5 * pw * ph: continue      # قاب کل صفحه
        boxes.append(r)
    # ادغام خوشه‌های نزدیک
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
    # ادغام تصاویر هم‌پوشان
    out = []
    for r in rects:
        merged = False
        for i, o in enumerate(out):
            if r.intersects(o): out[i] = rect_union(o, r); merged = True; break
        if not merged: out.append(r)
    return out

def crop_b64(page, rect, dpi):
    zoom = dpi / 72.0
    r = fitz.Rect(rect) & page.rect
    if r.is_empty or r.width < 2 or r.height < 2: return None, 0, 0
    pm = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=r, alpha=False)
    png = pm.tobytes('png')
    return base64.b64encode(png).decode(), pm.width, pm.height

def analyze(pdf_path, subject='', grade='', dpi=200):
    doc = fitz.open(pdf_path)
    questions = []      # هر مورد: {'no','lines':[...],'rects':[per-page rects],'page_figs':[]}
    cur = None
    stop_all = False
    scan_qs = []  # سوالات صفحات اسکن‌شده
    for pno, page in enumerate(doc):
        if stop_all: break
        lines = collect_lines(page)
        pw = page.rect.width
        # صفحه اسکن‌شده: هیچ متن قابل استخراج ولی تصویر تمام‌صفحه دارد
        if not lines and page.get_image_info():
            for blk in split_scanned_page(page, dpi):
                scan_qs.append(blk)
            continue
        # حالت جدول برگه ایرانی: ستون «ردیف» سمت راست (شماره تنها) + ستون «بارم» چپ
        cand_marks = [l for l in lines
                      if re.fullmatch(r'[0-9]{1,3}', fa2en(l['text']).strip())
                      and l['rect'].x0 >= pw * 0.85]
        # خوشه‌بندی بر اساس x0 — ستون واقعی «ردیف» راست‌ترین خوشه با بیشترین عضو است
        row_marks = []
        if cand_marks:
            clusters = []
            for l in sorted(cand_marks, key=lambda l: -l['rect'].x0):
                placed = False
                for cl in clusters:
                    if abs(cl[0][ 'rect'].x0 - l['rect'].x0) <= 5: cl.append(l); placed = True; break
                if not placed: clusters.append([l])
            clusters.sort(key=lambda cl: (len(cl), cl[0]['rect'].x0), reverse=True)
            best = clusters[0]
            if len(best) >= 2 or (len(best) == 1 and cur is not None):
                row_marks = best
        score_toks = [l for l in lines
                      if re.fullmatch(r'[0-9]{1,3}\s*(?:[./,⁄]\s*[0-9]{1,2})?', fa2en(l['text']).strip())
                      and l['rect'].x1 <= pw * 0.19]
        table_mode = len(row_marks) >= 2 or (len(row_marks) >= 1 and cur is not None)
        if table_mode:
            lines = sorted(lines, key=lambda l: (round(l['rect'].y0, 1), -l['rect'].x1))
            tbl_x_right = min(m['rect'].x0 for m in marks) - 3 if marks else pw * 0.94
            tbl_x_left = pw * 0.035  # لبه داخلی کادر جدول — عبارات ریاضی تا نزدیک ستون بارم می‌روند
        marks = sorted(row_marks, key=lambda l: l['rect'].y0) if table_mode else []
        if table_mode:
            drop = set(id(l) for l in row_marks) | set(id(l) for l in score_toks)
            lines = [l for l in lines if id(l) not in drop]
        # اولین شروع سوال در صفحه → قبل از آن سربرگ/تبلیغ است
        first_q_y = None
        if table_mode:
            first_q_y = marks[0]['rect'].y0
        else:
            for l in lines:
                en0 = fa2en(l['text'])
                m = Q_START.match(en0) or re.match(r'^(.{6,}?)\s*[-–—ـ]\s*([0-9]{1,3})\s*$', en0)
                if m and not OPT_START.match(l['text']):
                    first_q_y = l['rect'].y0; break
        if first_q_y is None:
            first_q_y = 0 if cur is not None else page.rect.height  # ادامه سوال از صفحه قبل
        mi = 0  # اشاره‌گر مارکرهای جدول
        figs = figure_rects(page, first_q_y if cur is None else 0)
        used_figs = set()
        for l in lines:
            plain = l['text']
            en = fa2en(plain)
            if ANSWER_KEY.search(plain) or ANSWER_KEY.search(plain[::-1]):
                stop_all = True; answer_y = l['rect'].y0; break
            if table_mode:
                while mi < len(marks) and l['rect'].y0 >= marks[mi]['rect'].y0 - 4:
                    if cur: questions.append(cur)
                    cur = {'no': fa2en(marks[mi]['text']).strip(), 'items': [],
                           'rect': fitz.Rect(marks[mi]['rect']), 'page': pno,
                           'clip_x': (tbl_x_left, tbl_x_right), 'garb': 0}
                    mi += 1
                if cur is None: continue
                if l['garbled']:
                    b64, w, h = crop_b64(page, fitz.Rect(l['rect'].x0 - 2, l['rect'].y0 - 2, l['rect'].x1 + 2, l['rect'].y1 + 2), dpi)
                    if b64: cur['items'].append(('img', b64, w, h))
                    cur['rect'] = rect_union(cur['rect'], l['rect'])
                    cur['garb'] = cur.get('garb', 0) + 1
                    continue
                if re.fullmatch(r'[0-9۰-۹]{1,3}\s*(?:[./,⁄]\s*[0-9۰-۹]{1,2})?', fa2en(plain).strip()) and l['rect'].x1 <= pw * 0.25:
                    cur['rect'] = rect_union(cur['rect'], l['rect']); continue  # عدد بارم داخل ستون چپ
                opts_in_line = split_options(plain)
                if opts_in_line:
                    for o in opts_in_line: cur['items'].append(('opt', o))
                elif OPT_START.match(plain):
                    cur['items'].append(('opt', norm_opt(plain.strip())))
                else:
                    cur['items'].append(('text', plain.strip()))
                cur['rect'] = rect_union(cur['rect'], l['rect'])
                continue
            mq = Q_START.match(en)
            if not mq:
                # حالت bidi: «متن سوال ... -۱» (شماره در انتهای خط)
                me = re.match(r'^(.{6,}?)\s*[-–—ـ]\s*([0-9]{1,3})\s*$', en)
                if me and re.search(r'[\u0600-\u06FF]', plain) and not SCORE_PAT.search(me.group(1)[-14:]):
                    class _M:  # شبیه match اصلی
                        def __init__(self, no, body): self._g = {1: no, 2: body}
                        def group(self, i): return self._g[i]
                    mq = _M(me.group(2), me.group(1).strip())
            is_q_start = bool(mq) and not OPT_START.match(plain) and l['rect'].y0 >= first_q_y - 1
            if is_q_start:
                # سوال جدید
                if cur: questions.append(cur)
                # متن بعد از شماره و جداکننده
                m2 = Q_START.match(fa2en(plain))
                body = m2.group(2).strip() if m2 else str(mq.group(2)).strip()
                cur = {'no': fa2en(mq.group(1)), 'items': [], 'rect': fitz.Rect(l['rect']), 'page': pno}
                if body: cur['items'].append(('text', body))
                continue
            if cur is None: continue  # هنوز داخل سربرگ
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
        # بارم ستون چپ جدول → سوال هم‌ردیف
        if table_mode:
            page_qs = [q for q in questions if q['page'] == pno] + ([cur] if cur and cur['page'] == pno else [])
            for st in score_toks:
                cy = (st['rect'].y0 + st['rect'].y1) / 2
                for q in page_qs:
                    if q['rect'].y0 - 6 <= cy <= q['rect'].y1 + 20:
                        sc = fa2en(st['text']).strip().replace(',', '.').replace('⁄', '.')
                        m = re.fullmatch(r'([0-9]+)\s*[./]\s*([0-9]+)', sc)
                        if m: sc = m.group(2) + '.' + m.group(1)  # bidi: «5 /1» = 1.5
                        q.setdefault('score_col', sc)
                        break
        # شکل‌های صفحه → سوالی که ناحیه‌اش شامل مرکز شکل است (یا نزدیک‌ترین سوال بالایی)
        if True:
            all_q = [q for q in questions if q['page'] == pno] + ([cur] if cur and cur['page'] == pno else [])
            ans_y = locals().get('answer_y', None) if stop_all else None
            for fr in figs:
                if ans_y is not None and (fr.y0 + fr.y1) / 2 >= ans_y: continue  # شکل داخل پاسخ‌نامه
                cy = (fr.y0 + fr.y1) / 2
                target = None
                for q in all_q:
                    if q['rect'].y0 - 6 <= cy <= q['rect'].y1 + 60: target = q
                if target is None and all_q:
                    above = [q for q in all_q if q['rect'].y0 <= cy]
                    target = above[-1] if above else all_q[0]
                if target is not None:
                    b64, w, h = crop_b64(page, fitz.Rect(fr.x0 - 3, fr.y0 - 3, fr.x1 + 3, fr.y1 + 3), dpi)
                    if b64:
                        target['items'].append(('img', b64, w, h))
                        target['rect'] = rect_union(target['rect'], fr)
    if cur and not any(q is cur for q in questions): questions.append(cur)
    # صفحات اسکن‌شده: هر بلوک یک سوال تصویری کامل (نظیر‌به‌نظیر)
    for i, blk in enumerate(scan_qs):
        questions.append({'no': '', 'items': [('img', blk['b64'], blk['w'], blk['h'])],
                          'rect': fitz.Rect(0, 0, 1, 1), 'page': -1, 'scan': True})

    # پاس نهایی نظیر‌به‌نظیر: سوال دارای فرمول/کسر تکه‌تکه‌شده → برش کامل ناحیه سوال
    OPS = re.compile(r'[=×÷]|(?<![\w])[+−]\s*[0-9]|[0-9]\s*[+−](?![\w])|[0-9]\s*-\s|\s-\s*[0-9]')
    for q in questions:
        if 'clip_x' not in q: continue
        texts = [it[1] for it in q['items'] if it[0] == 'text']
        shorts = sum(1 for t in texts if re.fullmatch(r'[\s0-9()+=×÷−./\-]{1,12}', fa2en(t)))
        mathy = q.get('garb', 0) >= 1 or shorts >= 1 or any(OPS.search(fa2en(t)) for t in texts)
        if not mathy: continue
        page = doc[q['page']]
        clip = fitz.Rect(q['clip_x'][0], q['rect'].y0 - 4, q['clip_x'][1], min(q['rect'].y1 + 6, page.rect.height - 12))
        b64, w, h = crop_b64(page, clip, dpi)
        if b64:
            score_keep = q.get('score_col', '')
            raw = ' '.join(texts)
            ms0 = SCORE_PAT.search(fa2en(raw))
            q['items'] = [('img', b64, w, h)]
            q['whole_row'] = True
            if not score_keep and ms0: q['score_col'] = (ms0.group(1) or ms0.group(2) or '').replace('⁄', '.').replace(',', '.')
    doc.close()

    # بازشماری: اگر شماره‌ها تکراری/نامرتب بودند (مارکر جدولی اشتباه)، شماره متوالی جایگزین می‌شود
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
        # بارم
        score = ''
        ms = SCORE_PAT.search(fa2en(raw_all))
        if ms: score = (ms.group(1) or ms.group(2) or '').replace('⁄', '.').replace(',', '.')
        if not score and q.get('score_col'): score = q['score_col']
        # نوع سوال
        if len(opts) >= 2: qtype = 'mcq'
        elif TF_HINT.search(raw_all): qtype = 'tf'
        elif DOTS_RUN.search(raw_all): qtype = 'blank'
        else: qtype = 'text'
        # HTML
        parts = []
        line_h = 17  # px تقریبی هر خط متن با فونت ۱۱
        text_lines = 0
        for t in texts:
            body = tidy_text(t[1])
            body = esc(body)
            body = DOTS_RUN.sub('<span class="blank-line"></span>', body)
            body = re.sub(r'[□◻☐]', '<span class="tf-square"></span>', body)
            parts.append(f'<p>{body}</p>')
            text_lines += 1
        if opts:
            spans = ''.join(f'<span>{esc(o[1])}</span>' for o in opts)
            parts.append(f'<div class="q-options">{spans}</div>')
            text_lines += (len(opts) + 1) // 2
        if q.get('whole_row'):
            parts = []; text_lines = 0
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
