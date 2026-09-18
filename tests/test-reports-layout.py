"""Execute production migration C with POSIX-backed Win32 file mocks; no Windows GUI claim."""
from pathlib import Path
import subprocess,tempfile,hashlib,shutil,fcntl
ROOT=Path(__file__).resolve().parents[1]
CACHE=ROOT/'.cache/reports-root';CACHE.mkdir(parents=True,exist_ok=True)
src=r'''
#include <stdio.h>
#include <string.h>
#include <stdint.h>
#include <stdlib.h>
#include <sys/stat.h>
#include <sys/file.h>
#include <fcntl.h>
#include <unistd.h>
typedef unsigned long DWORD;typedef intptr_t HANDLE;typedef int OVERLAPPED;
#define MAX_PATH 260
#define INVALID_FILE_ATTRIBUTES ((DWORD)-1)
#define FILE_ATTRIBUTE_DIRECTORY 16
#define INVALID_HANDLE_VALUE (-1)
#define GENERIC_READ 1
#define GENERIC_WRITE 2
#define FILE_SHARE_READ 1
#define FILE_SHARE_WRITE 2
#define OPEN_ALWAYS 1
#define FILE_ATTRIBUTE_NORMAL 0
#define LOCKFILE_EXCLUSIVE_LOCK 1
#define LOCKFILE_FAIL_IMMEDIATELY 2
#define MOVEFILE_WRITE_THROUGH 1
#define MOVEFILE_REPLACE_EXISTING 2
#define FALSE 0
#define TRUE 1
static int mode=0;
static void conv(const char *p,char *o){strcpy(o,p);for(;*o;o++)if(*o=='\\')*o='/';}
static DWORD GetFileAttributesA(const char*p){char b[2048];conv(p,b);struct stat s;if(stat(b,&s))return INVALID_FILE_ATTRIBUTES;return S_ISDIR(s.st_mode)?FILE_ATTRIBUTE_DIRECTORY:0;}
static int CreateDirectoryA(const char*p,void*x){char b[2048];conv(p,b);return !mkdir(b,0700);}
static int DeleteFileA(const char*p){char b[2048];conv(p,b);if(mode==4&&strstr(b,"reports-layout-update"))return 0;if(mode==6&&strstr(b,"app-alive"))return 0;return !unlink(b);}
static HANDLE CreateFileA(const char*p,int a,int b,void*c,int d,int e,void*f){char q[2048];conv(p,q);return open(q,O_CREAT|O_RDWR,0600);}
static int LockFileEx(HANDLE h,int a,int b,int c,int d,OVERLAPPED*o){return mode!=1&&flock(h,LOCK_EX|LOCK_NB)==0;}
static void UnlockFileEx(HANDLE h,int a,int b,int c,OVERLAPPED*o){flock(h,LOCK_UN);}
static void CloseHandle(HANDLE h){close(h);}
static int MoveFileExA(const char*a,const char*b,int flags){char x[2048],y[2048];conv(a,x);conv(b,y);if(mode==2&&strstr(x,"/www"))return 0;if(mode==5&&strstr(x,".router-reports.pending"))return 0;if(!(flags&MOVEFILE_REPLACE_EXISTING)&&access(y,F_OK)==0)return 0;return !rename(x,y);}
static int CopyFileA(const char*a,const char*b,int exclusive){char x[2048],y[2048],buf[8192];conv(a,x);conv(b,y);if(mode==3&&strstr(y,".router-reports.pending"))return 0;if(exclusive&&access(y,F_OK)==0)return 0;FILE*i=fopen(x,"rb"),*o=fopen(y,"wb");if(!i||!o)return 0;size_t n;while((n=fread(buf,1,sizeof(buf),i)))fwrite(buf,1,n,o);fclose(i);fclose(o);return 1;}
static FILE *mock_fopen(const char*p,const char*m){char b[2048];conv(p,b);return fopen(b,m);}
#define fopen mock_fopen
#include "reports-layout.h"
int main(int argc,char**argv){mode=argc>2?atoi(argv[2]):0;printf("%d",sdp_prepare_reports(argv[1]));return 0;}
'''
(CACHE/'migration-test.c').write_text(src)
subprocess.run(['gcc','-I',str(ROOT/'desktop-app-v2/launcher'),str(CACHE/'migration-test.c'),'-o',str(CACHE/'migration-test')],check=True)
checks=0
def check(v):
 global checks
 assert v;checks+=1
def fixture(name,web='www'):
 p=CACHE/name
 if p.exists():shutil.rmtree(p)
 for d in [web+'/includes',web+'/config',web+'/uploads',web+'/assets/logo','data','reports-layout-update']:(p/d).mkdir(parents=True,exist_ok=True)
 for f,b in {web+'/index.php':b'<?php echo "app";',web+'/includes/functions.php':b'PHP',web+'/router.php':b'ORIGINAL_ROUTER',web+'/config/database.php':b'SECRET-CONFIG',web+'/assets/logo/logo.png':b'LOGO',web+'/uploads/old.png':b'UPLOAD','data/school.sqlite':b'SQLITE-SENTINEL','data/browser-profile.txt':b'PROFILE','data/app-alive.txt':b'ALIVE'}.items():(p/f).write_bytes(b)
 shutil.copyfile(ROOT/'desktop-app-v2/patch/reports-router.php',p/'reports-layout-update/router.php')
 return p
def run(p,mode=0):return int(subprocess.check_output([str(CACHE/'migration-test'),str(p),str(mode)]))
p=fixture('success');check(run(p)==0);check(not (p/'www').exists());check((p/'reports/config/database.php').read_bytes()==b'SECRET-CONFIG');check((p/'data/school.sqlite').read_bytes()==b'SQLITE-SENTINEL');check((p/'reports/uploads/old.png').read_bytes()==b'UPLOAD');check((p/'reports/assets/logo/logo.png').read_bytes()==b'LOGO');check((p/'data/browser-profile.txt').read_bytes()==b'PROFILE');check((p/'data/router-before-reports.php').read_bytes()==b'ORIGINAL_ROUTER');check(not(p/'reports-layout-update/router.php').exists());check(run(p)==0)
# Subsequent boots must not overwrite a newer router.
(p/'reports/router.php').write_bytes(b'<?php // SDP_REPORTS_ROOT_V1 NEWER');check(run(p)==0);check(b'NEWER' in (p/'reports/router.php').read_bytes())
p=fixture('both');(p/'reports').mkdir();check(run(p)==1);check((p/'www/config/database.php').read_bytes()==b'SECRET-CONFIG')
p=fixture('busy');check(run(p,1)==3);check((p/'www').exists() and not(p/'reports').exists());check(not(p/'data/app-alive.txt').exists());check(run(p)==0)
p=fixture('rename-failure');check(run(p,2)==4);check((p/'www/router.php').read_bytes()==b'ORIGINAL_ROUTER')
p=fixture('copy-failure');check(run(p,3)==4);check((p/'www/router.php').read_bytes()==b'ORIGINAL_ROUTER');check(not(p/'reports').exists());check(run(p)==0)
p=fixture('already-renamed','reports');check(run(p)==0);check((p/'reports/config/database.php').read_bytes()==b'SECRET-CONFIG')
p=fixture('missing-patch');(p/'reports-layout-update/router.php').unlink();check(run(p)==2);check((p/'www').exists())
p=fixture('bad-patch');(p/'reports-layout-update/router.php').write_bytes(b'NOT A ROUTER');check(run(p)==2)
p=fixture('retire-failure');check(run(p,4)==4);check((p/'reports/router.php').exists());check(run(p)==0);check((p/'data/router-before-reports.php').read_bytes()==b'ORIGINAL_ROUTER')
p=fixture('atomic-replace-failure');check(run(p,5)==4);check((p/'www/router.php').read_bytes()==b'ORIGINAL_ROUTER');check(not(p/'www/.router-reports.pending').exists());check(run(p)==0)
p=fixture('alive-delete-failure');check(run(p,6)==4);check((p/'www').exists());check((p/'data/app-alive.txt').read_bytes()==b'ALIVE')
p=fixture('old-router-no-patch','reports');(p/'reports-layout-update/router.php').unlink();check(run(p)==2)
p=fixture('held-posix-lock')
with (p/'data/sync-daemon.lock').open('w') as f:
 fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB);check(run(p)==3);check((p/'www').exists());fcntl.flock(f,fcntl.LOCK_UN)
check(run(p)==0)
print('PASS',checks,'migration checks (production C, mocked Win32 filesystem/lock APIs)')
