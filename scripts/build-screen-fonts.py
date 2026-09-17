#!/usr/bin/env python3
"""Optional build-time conversion of existing, bundled fonts. No server dependency.
Requires fonttools + brotli. Does not download fonts, modify originals or touch exports.
"""
from pathlib import Path
from zipfile import ZipFile
from io import BytesIO
from fontTools.ttLib import TTFont
import hashlib,json
ROOT=Path(__file__).resolve().parents[1]
DEST=ROOT/'update-v4.152.0/assets/fonts/screen';DEST.mkdir(parents=True,exist_ok=True)
manifest={}
with ZipFile(ROOT/'Release_V1.0-Site.zip') as z:
 for rel in ['Vazirmatn/Vazirmatn-Regular.ttf','Vazirmatn/Vazirmatn-Bold.ttf','Sahel/Sahel.ttf','Yekan/Yekan.ttf']:
  src=z.read('uploads/'+rel);path=DEST/(Path(rel).stem+'.woff2')
  font=TTFont(BytesIO(src),recalcTimestamp=False);font.flavor='woff2';font.save(path)
  manifest['uploads/'+rel]={'sha256':hashlib.sha256(src).hexdigest(),'url':'assets/fonts/screen/'+path.name}
(DEST/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
