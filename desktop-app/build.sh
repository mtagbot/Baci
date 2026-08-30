#!/bin/sh
# SchoolDesk build script — cross-compiles the portable Windows EXEs with zig cc.
# Usage: ./build.sh <build-dir-with-third-party> <out-dir>
set -e
SRC="$(cd "$(dirname "$0")/src" && pwd)"
TP="${1:-/tmp/build}"
OUT="${2:-/tmp/build/out}"
mkdir -p "$OUT"

# 1) generate blobs.h from UI + fonts
python3 - "$SRC" "$TP" <<'EOF'
import sys, os
src, tp = sys.argv[1], sys.argv[2]

def dump(name, data, text=False):
    out = [f"static const unsigned char {name}[] = {{"]
    row = []
    for b in data:
        row.append(str(b))
        if len(row) == 24:
            out.append(",".join(row) + ",")
            row = []
    if row:
        out.append(",".join(row) + ("," if text else ""))
    if text:
        out.append("0")
    out.append("};")
    return "\n".join(out)

html = open(os.path.join(src, "ui", "index.html"), "rb").read()
# .woff (not woff2): the embedded MSHTML control on Win7/8 doesn't do woff2
reg  = open(os.path.join(tp, "Vazirmatn-Regular.woff"), "rb").read()
bold = open(os.path.join(tp, "Vazirmatn-Bold.woff"), "rb").read()

with open(os.path.join(tp, "blobs.h"), "w") as f:
    f.write("/* generated — do not edit */\n")
    f.write(dump("BLOB_UI_HTML", html, text=True) + "\n")
    f.write(dump("BLOB_FONT_REG", reg) + "\n")
    f.write(dump("BLOB_FONT_BOLD", bold) + "\n")
print("blobs.h:", os.path.getsize(os.path.join(tp, "blobs.h")), "bytes")
EOF

CFLAGS="-Os -DNDEBUG -DMG_ENABLE_MD5=0 -DMG_ENABLE_SSI=0 -DMG_ENABLE_DIRLIST=0 \
 -DSQLITE_OMIT_LOAD_EXTENSION -DSQLITE_ENABLE_JSON1 -DSQLITE_THREADSAFE=1 \
 -DSQLITE_DEFAULT_MEMSTATUS=0 -DSQLITE_OMIT_DEPRECATED -DSQLITE_OMIT_PROGRESS_CALLBACK \
 -DSQLITE_LIKE_DOESNT_MATCH_BLOBS -DSQLITE_MAX_EXPR_DEPTH=0 -I$TP -I$SRC"

WINLIBS="-lws2_32 -lwinhttp -lshell32 -ladvapi32 -lole32 -loleaut32 -luuid -lgdi32 -luser32"
RES=""
[ -f "$TP/app.res" ] && RES="$TP/app.res"

echo "== Building x64 (Windows 7+ 64-bit) =="
python3 -m ziglang cc -target x86_64-windows-gnu $CFLAGS \
  "$SRC/main.c" "$SRC/webwin.c" "$TP/mongoose.c" "$TP/sqlite3.c" $RES $WINLIBS \
  -Wl,--subsystem,windows -o "$OUT/SchoolDesk-x64.exe"

echo "== Building x86 (Windows 7+ 32-bit / old PCs) =="
python3 -m ziglang cc -target x86-windows-gnu $CFLAGS \
  "$SRC/main.c" "$SRC/webwin.c" "$TP/mongoose.c" "$TP/sqlite3.c" $RES $WINLIBS \
  -Wl,--subsystem,windows -o "$OUT/SchoolDesk-x86.exe"

ls -la "$OUT"
