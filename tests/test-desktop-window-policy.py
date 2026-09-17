"""Execute the actual window policy against a small Win32 mock, NOT a Windows GUI test."""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[1]
header=(ROOT/'desktop-app-v2/launcher/window-policy.h').read_text()
policy=header[:header.index('static void CALLBACK sdp_window_event')]
policy+=header[header.index('static BOOL CALLBACK sdp_restore_existing'):]
p=ROOT/'.cache/student-workflow';p.mkdir(parents=True,exist_ok=True)
stubs=r'''
#include <assert.h>
#include <stdint.h>
#include <string.h>
#include <stdio.h>
typedef unsigned long DWORD;typedef intptr_t LONG_PTR;typedef intptr_t LPARAM;typedef uintptr_t ULONG_PTR;
typedef int BOOL;typedef void* HANDLE;typedef void* HWND;typedef void* HMENU;
#define CALLBACK
#define TRUE 1
#define FALSE 0
#define GW_OWNER 1
#define GWL_STYLE 2
#define WS_THICKFRAME 0x00040000L
#define WS_MAXIMIZEBOX 0x00010000L
#define WS_MINIMIZEBOX 0x00020000L
#define WS_SYSMENU 0x00080000L
#define SWP_FRAMECHANGED 1
#define SWP_NOMOVE 2
#define SWP_NOSIZE 4
#define SWP_NOZORDER 8
#define SWP_NOACTIVATE 16
#define MF_BYCOMMAND 0
#define MF_GRAYED 1
#define MF_ENABLED 0
#define SC_RESTORE 1
#define SC_SIZE 2
#define SC_MOVE 3
#define SC_MAXIMIZE 4
#define SC_MINIMIZE 5
#define SW_MAXIMIZE 3
struct Window {DWORD pid;int visible,owned,minimized,maximized,shows;LONG_PTR style;char cls[80];HANDLE marker;int menu[6];};
void GetWindowThreadProcessId(HWND h,DWORD*p){*p=((struct Window*)h)->pid;}
int IsWindowVisible(HWND h){return ((struct Window*)h)->visible;}
HWND GetWindow(HWND h,int x){return ((struct Window*)h)->owned?h:NULL;}
void GetClassNameA(HWND h,char*s,int n){strncpy(s,((struct Window*)h)->cls,n);}
void SetPropW(HWND h,const void*n,HANDLE v){((struct Window*)h)->marker=v;}
HANDLE GetPropW(HWND h,const void*n){return ((struct Window*)h)->marker;}
LONG_PTR GetWindowLongPtrW(HWND h,int x){return ((struct Window*)h)->style;}
void SetWindowLongPtrW(HWND h,int x,LONG_PTR s){((struct Window*)h)->style=s;}
void SetWindowPos(HWND h,void*a,int b,int c,int d,int e,int f){}
HMENU GetSystemMenu(HWND h,int x){return h;}
void EnableMenuItem(HMENU h,int c,int f){((struct Window*)h)->menu[c]=f;}
int IsIconic(HWND h){return ((struct Window*)h)->minimized;}
int IsZoomed(HWND h){return ((struct Window*)h)->maximized;}
void ShowWindow(HWND h,int s){struct Window*w=h;w->maximized=1;w->minimized=0;w->shows++;}
void SetForegroundWindow(HWND h){}
'''
tests=r'''
int main(void){
 sdp_browser_pid=42;
 struct Window base={.pid=42,.visible=1,.style=WS_THICKFRAME|WS_MAXIMIZEBOX,.cls="Chrome_WidgetWin_1"},w;
 w=base;sdp_lock_window(&w,0);assert(w.maximized&&w.shows==1);assert(!(w.style&(WS_THICKFRAME|WS_MAXIMIZEBOX)));assert(w.style&WS_MINIMIZEBOX);assert(w.style&WS_SYSMENU);assert(w.menu[SC_RESTORE]==MF_GRAYED);
 w=base;w.minimized=1;sdp_lock_window(&w,0);assert(w.minimized&&w.shows==0);assert(w.menu[SC_RESTORE]==MF_ENABLED);
 w=base;w.maximized=1;sdp_lock_window(&w,0);assert(w.shows==0);
 w=base;w.pid=88;sdp_lock_window(&w,0);assert(w.style==base.style&&w.shows==0);
 w=base;w.owned=1;sdp_lock_window(&w,0);assert(w.shows==0);
 w=base;strcpy(w.cls,"#32770");sdp_lock_window(&w,0);assert(w.shows==0);
 w=base;w.visible=0;sdp_lock_window(&w,0);assert(w.shows==0);
 w=base;sdp_lock_window(&w,0);w.minimized=1;int found=0;sdp_restore_existing(&w,(LPARAM)&found);assert(found&&w.maximized&&!w.minimized);
 puts("PASS 8 native policy mock cases; Windows/Chromium GUI still requires on-device validation");
}
'''
(p/'policy-test.c').write_text(stubs+policy+tests)
subprocess.run(['cc','-std=c99',str(p/'policy-test.c'),'-o',str(p/'policy-test')],check=True)
subprocess.run([str(p/'policy-test')],check=True)
