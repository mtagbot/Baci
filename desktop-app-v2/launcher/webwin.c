/*
 * webwin.c — Native Win32 window hosting the MSHTML browser control (OLE).
 * Turns SchoolDesk into a real desktop application window:
 *   - own window, own icon, own taskbar entry (no browser chrome)
 *   - tray icon with menu (show/hide, sync now, exit)
 *   - works on Windows 7/8/10/11 with zero dependencies (MSHTML ships with OS)
 *
 * Pure C COM embedding: IOleClientSite + IOleInPlaceSite + IOleInPlaceFrame
 * around the "Shell.Explorer" (WebBrowser) ActiveX object.
 */
#ifdef _WIN32

#define COBJMACROS
#include <stdio.h>
#include <windows.h>
#include <exdisp.h>   /* IWebBrowser2 */
#include <mshtml.h>
#include <mshtmhst.h> /* IDocHostUIHandler */
#include <oleidl.h>
#include <shellapi.h>

#define WM_TRAY (WM_APP + 1)
#define ID_TRAY_SHOW 40001
#define ID_TRAY_SYNC 40002
#define ID_TRAY_EXIT 40003

extern volatile int g_quit;      /* from main.c */
extern volatile int g_sync_req;  /* from main.c */

static HWND g_hwnd = NULL;
static IWebBrowser2 *g_wb = NULL;
static IOleObject *g_ole = NULL;
static IOleInPlaceActiveObject *g_ao = NULL; /* keyboard accelerator routing */
static char g_nav_url[256];

/* ------------------------------------------------------------------ */
/* Minimal COM host objects (static, refcount no-ops)                  */
/* ------------------------------------------------------------------ */

typedef struct {
  IOleClientSite site;        /* vtable 1 */
  IOleInPlaceSite inplace;    /* vtable 2 */
  IDocHostUIHandler uihandler;/* vtable 3 */
} Host;

static Host g_host;

/* ---- IOleClientSite ---- */
static HRESULT STDMETHODCALLTYPE Site_QueryInterface(IOleClientSite *self, REFIID riid, void **ppv) {
  (void) self;
  if (IsEqualIID(riid, &IID_IUnknown) || IsEqualIID(riid, &IID_IOleClientSite)) *ppv = &g_host.site;
  else if (IsEqualIID(riid, &IID_IOleInPlaceSite) || IsEqualIID(riid, &IID_IOleWindow)) *ppv = &g_host.inplace;
  else if (IsEqualIID(riid, &IID_IDocHostUIHandler)) *ppv = &g_host.uihandler;
  else { *ppv = NULL; return E_NOINTERFACE; }
  return S_OK;
}
static ULONG STDMETHODCALLTYPE Site_AddRef(IOleClientSite *self) { (void) self; return 1; }
static ULONG STDMETHODCALLTYPE Site_Release(IOleClientSite *self) { (void) self; return 1; }
static HRESULT STDMETHODCALLTYPE Site_SaveObject(IOleClientSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE Site_GetMoniker(IOleClientSite *self, DWORD a, DWORD w, IMoniker **pm) {
  (void) self; (void) a; (void) w; *pm = NULL; return E_NOTIMPL;
}
static HRESULT STDMETHODCALLTYPE Site_GetContainer(IOleClientSite *self, IOleContainer **pc) {
  (void) self; *pc = NULL; return E_NOINTERFACE;
}
static HRESULT STDMETHODCALLTYPE Site_ShowObject(IOleClientSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE Site_OnShowWindow(IOleClientSite *self, BOOL f) { (void) self; (void) f; return S_OK; }
static HRESULT STDMETHODCALLTYPE Site_RequestNewObjectLayout(IOleClientSite *self) { (void) self; return E_NOTIMPL; }

static IOleClientSiteVtbl g_site_vtbl = {
  Site_QueryInterface, Site_AddRef, Site_Release, Site_SaveObject,
  Site_GetMoniker, Site_GetContainer, Site_ShowObject, Site_OnShowWindow,
  Site_RequestNewObjectLayout
};

/* ---- IOleInPlaceSite ---- */
static HRESULT STDMETHODCALLTYPE IP_QueryInterface(IOleInPlaceSite *self, REFIID riid, void **ppv) {
  (void) self; return Site_QueryInterface(&g_host.site, riid, ppv);
}
static ULONG STDMETHODCALLTYPE IP_AddRef(IOleInPlaceSite *self) { (void) self; return 1; }
static ULONG STDMETHODCALLTYPE IP_Release(IOleInPlaceSite *self) { (void) self; return 1; }
static HRESULT STDMETHODCALLTYPE IP_GetWindow(IOleInPlaceSite *self, HWND *ph) { (void) self; *ph = g_hwnd; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_ContextSensitiveHelp(IOleInPlaceSite *self, BOOL f) { (void) self; (void) f; return E_NOTIMPL; }
static HRESULT STDMETHODCALLTYPE IP_CanInPlaceActivate(IOleInPlaceSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_OnInPlaceActivate(IOleInPlaceSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_OnUIActivate(IOleInPlaceSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_GetWindowContext(IOleInPlaceSite *self, IOleInPlaceFrame **ppFrame,
    IOleInPlaceUIWindow **ppDoc, LPRECT rc, LPRECT clip, LPOLEINPLACEFRAMEINFO fi) {
  (void) self;
  *ppFrame = NULL; *ppDoc = NULL;
  GetClientRect(g_hwnd, rc);
  GetClientRect(g_hwnd, clip);
  fi->fMDIApp = FALSE; fi->hwndFrame = g_hwnd; fi->haccel = NULL; fi->cAccelEntries = 0;
  return S_OK;
}
static HRESULT STDMETHODCALLTYPE IP_Scroll(IOleInPlaceSite *self, SIZE s) { (void) self; (void) s; return E_NOTIMPL; }
static HRESULT STDMETHODCALLTYPE IP_OnUIDeactivate(IOleInPlaceSite *self, BOOL f) { (void) self; (void) f; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_OnInPlaceDeactivate(IOleInPlaceSite *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE IP_DiscardUndoState(IOleInPlaceSite *self) { (void) self; return E_NOTIMPL; }
static HRESULT STDMETHODCALLTYPE IP_DeactivateAndUndo(IOleInPlaceSite *self) { (void) self; return E_NOTIMPL; }
static HRESULT STDMETHODCALLTYPE IP_OnPosRectChange(IOleInPlaceSite *self, LPCRECT rc) {
  (void) self; (void) rc; return S_OK;
}
static IOleInPlaceSiteVtbl g_ip_vtbl = {
  IP_QueryInterface, IP_AddRef, IP_Release, IP_GetWindow, IP_ContextSensitiveHelp,
  IP_CanInPlaceActivate, IP_OnInPlaceActivate, IP_OnUIActivate, IP_GetWindowContext,
  IP_Scroll, IP_OnUIDeactivate, IP_OnInPlaceDeactivate, IP_DiscardUndoState,
  IP_DeactivateAndUndo, IP_OnPosRectChange
};

/* ---- run a JS snippet inside the hosted page (keyboard bridge) ---- */
static void exec_script(const wchar_t *code) {
  IWebBrowser2 *wb = g_wb;
  if (!wb) return;
  IDispatch *dd = NULL;
  if (FAILED(IWebBrowser2_get_Document(wb, &dd)) || !dd) return;
  IHTMLDocument2 *doc = NULL;
  if (SUCCEEDED(IDispatch_QueryInterface(dd, &IID_IHTMLDocument2, (void **) &doc)) && doc) {
    IHTMLWindow2 *win = NULL;
    if (SUCCEEDED(IHTMLDocument2_get_parentWindow(doc, &win)) && win) {
      BSTR c = SysAllocString(code), l = SysAllocString(L"JavaScript");
      VARIANT v;
      VariantInit(&v);
      IHTMLWindow2_execScript(win, c, l, &v);
      VariantClear(&v);
      SysFreeString(c);
      SysFreeString(l);
      IHTMLWindow2_Release(win);
    }
    IHTMLDocument2_Release(doc);
  }
  IDispatch_Release(dd);
}

/* ---- IDocHostUIHandler: kill context menu / borders, force modern look ---- */
static HRESULT STDMETHODCALLTYPE UI_QueryInterface(IDocHostUIHandler *self, REFIID riid, void **ppv) {
  (void) self; return Site_QueryInterface(&g_host.site, riid, ppv);
}
static ULONG STDMETHODCALLTYPE UI_AddRef(IDocHostUIHandler *self) { (void) self; return 1; }
static ULONG STDMETHODCALLTYPE UI_Release(IDocHostUIHandler *self) { (void) self; return 1; }
static HRESULT STDMETHODCALLTYPE UI_ShowContextMenu(IDocHostUIHandler *self, DWORD id, POINT *pt,
    IUnknown *pu, IDispatch *pd) {
  (void) self; (void) pt; (void) pu; (void) pd;
  /* allow the standard copy/cut/paste menu on text inputs (2) and on
   * selected text (4); suppress the full IE menu everywhere else */
  if (id == 2 /*CONTEXT_MENU_CONTROL*/ || id == 4 /*CONTEXT_MENU_TEXTSELECT*/)
    return S_FALSE;
  return S_OK; /* S_OK = we handled it -> no IE context menu */
}
static HRESULT STDMETHODCALLTYPE UI_GetHostInfo(IDocHostUIHandler *self, DOCHOSTUIINFO *info) {
  (void) self;
  info->cbSize = sizeof(*info);
  info->dwFlags = DOCHOSTUIFLAG_NO3DBORDER | DOCHOSTUIFLAG_SCROLL_NO |
                  DOCHOSTUIFLAG_THEME | DOCHOSTUIFLAG_DPI_AWARE;
  info->dwDoubleClick = DOCHOSTUIDBLCLK_DEFAULT;
  return S_OK;
}
static HRESULT STDMETHODCALLTYPE UI_ShowUI(IDocHostUIHandler *self, DWORD id, IOleInPlaceActiveObject *ao,
    IOleCommandTarget *ct, IOleInPlaceFrame *fr, IOleInPlaceUIWindow *dw) {
  (void) self; (void) id; (void) ao; (void) ct; (void) fr; (void) dw; return S_OK;
}
static HRESULT STDMETHODCALLTYPE UI_HideUI(IDocHostUIHandler *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE UI_UpdateUI(IDocHostUIHandler *self) { (void) self; return S_OK; }
static HRESULT STDMETHODCALLTYPE UI_EnableModeless(IDocHostUIHandler *self, BOOL f) { (void) self; (void) f; return S_OK; }
static HRESULT STDMETHODCALLTYPE UI_OnDocWindowActivate(IDocHostUIHandler *self, BOOL f) { (void) self; (void) f; return S_OK; }
static HRESULT STDMETHODCALLTYPE UI_OnFrameWindowActivate(IDocHostUIHandler *self, BOOL f) { (void) self; (void) f; return S_OK; }
static HRESULT STDMETHODCALLTYPE UI_ResizeBorder(IDocHostUIHandler *self, LPCRECT rc, IOleInPlaceUIWindow *w, BOOL f) {
  (void) self; (void) rc; (void) w; (void) f; return S_OK;
}
/* forward an app-level shortcut into the page: window.sdKey('name') */
static void bridge_key(const wchar_t *name) {
  wchar_t js[128];
  _snwprintf(js, 128, L"try{window.sdKey&&window.sdKey('%s')}catch(e){}", name);
  js[127] = 0;
  exec_script(js);
}

static HRESULT STDMETHODCALLTYPE UI_TranslateAccelerator(IDocHostUIHandler *self, LPMSG msg, const GUID *g, DWORD cmd) {
  (void) self; (void) g; (void) cmd;
  /* App-level shortcuts: intercept BEFORE MSHTML so IE dialogs never
   * appear; forward them into the page as window.sdKey('...').
   * Everything else — Ctrl+C/X/V/A/Z/Y, Tab, arrows, Home/End,
   * Delete, Enter... — passes through to MSHTML so text editing
   * behaves exactly like any Windows program. */
  if (msg && msg->message == WM_KEYDOWN) {
    int ctrl = (GetKeyState(VK_CONTROL) & 0x8000) != 0;
    if (ctrl) {
      switch (msg->wParam) {
        case 'S': bridge_key(L"save");   return S_OK; /* save open form   */
        case 'N': bridge_key(L"new");    return S_OK; /* new record       */
        case 'F': bridge_key(L"find");   return S_OK; /* focus search box */
        case 'P': bridge_key(L"print");  return S_OK; /* app print        */
        case 'R': bridge_key(L"refresh");return S_OK; /* reload view data */
        case 'O': case 'L': case 'J': case 'H': case 'E':
          return S_OK; /* IE open/url/downloads/history/search — block */
      }
      if (msg->wParam >= '1' && msg->wParam <= '9') { /* Ctrl+1..9 views */
        wchar_t nm[8] = { L'v', L'i', L'e', L'w', (wchar_t) msg->wParam, 0 };
        bridge_key(nm);
        return S_OK;
      }
    }
    if (msg->wParam == VK_F5) { bridge_key(L"refresh"); return S_OK; }
    if (msg->wParam == VK_F1) { bridge_key(L"help"); return S_OK; }
    if (msg->wParam == VK_F3) { bridge_key(L"find"); return S_OK; }
    if (msg->wParam == VK_F4 || msg->wParam == VK_F6 || msg->wParam == VK_F10)
      return S_OK; /* IE address dropdown / pane cycle / menu — block */
  }
  return S_FALSE;
}
static HRESULT STDMETHODCALLTYPE UI_GetOptionKeyPath(IDocHostUIHandler *self, LPOLESTR *k, DWORD d) {
  (void) self; (void) d; *k = NULL; return E_NOTIMPL;
}
static HRESULT STDMETHODCALLTYPE UI_GetDropTarget(IDocHostUIHandler *self, IDropTarget *dt, IDropTarget **pdt) {
  (void) self; (void) dt; *pdt = NULL; return E_NOTIMPL;
}
static HRESULT STDMETHODCALLTYPE UI_GetExternal(IDocHostUIHandler *self, IDispatch **pd) {
  (void) self; *pd = NULL; return E_NOTIMPL;
}
static HRESULT STDMETHODCALLTYPE UI_TranslateUrl(IDocHostUIHandler *self, DWORD d, LPWSTR in, LPWSTR *out) {
  (void) self; (void) d; (void) in; *out = NULL; return E_NOTIMPL;
}
static HRESULT STDMETHODCALLTYPE UI_FilterDataObject(IDocHostUIHandler *self, IDataObject *in, IDataObject **out) {
  (void) self; (void) in; *out = NULL; return E_NOTIMPL;
}
static IDocHostUIHandlerVtbl g_ui_vtbl = {
  UI_QueryInterface, UI_AddRef, UI_Release, UI_ShowContextMenu, UI_GetHostInfo,
  UI_ShowUI, UI_HideUI, UI_UpdateUI, UI_EnableModeless, UI_OnDocWindowActivate,
  UI_OnFrameWindowActivate, UI_ResizeBorder, UI_TranslateAccelerator,
  UI_GetOptionKeyPath, UI_GetDropTarget, UI_GetExternal, UI_TranslateUrl,
  UI_FilterDataObject
};

/* ------------------------------------------------------------------ */
/* Browser embedding                                                   */
/* ------------------------------------------------------------------ */

static void browser_resize(void) {
  if (!g_wb) return;
  RECT rc;
  GetClientRect(g_hwnd, &rc);
  IWebBrowser2_put_Left(g_wb, 0);
  IWebBrowser2_put_Top(g_wb, 0);
  IWebBrowser2_put_Width(g_wb, rc.right);
  IWebBrowser2_put_Height(g_wb, rc.bottom);
}

static int browser_create(const char *url) {
  g_host.site.lpVtbl = &g_site_vtbl;
  g_host.inplace.lpVtbl = &g_ip_vtbl;
  g_host.uihandler.lpVtbl = &g_ui_vtbl;

  if (FAILED(CoCreateInstance(&CLSID_WebBrowser, NULL, CLSCTX_INPROC_SERVER,
                              &IID_IOleObject, (void **) &g_ole)))
    return -1;

  IOleObject_SetClientSite(g_ole, &g_host.site);
  RECT rc;
  GetClientRect(g_hwnd, &rc);
  IOleObject_DoVerb(g_ole, OLEIVERB_INPLACEACTIVATE, NULL, &g_host.site, 0, g_hwnd, &rc);

  if (FAILED(IOleObject_QueryInterface(g_ole, &IID_IWebBrowser2, (void **) &g_wb)))
    return -1;
  IWebBrowser2_put_Silent(g_wb, VARIANT_TRUE); /* no script error popups */
  /* keyboard: Ctrl+C/V/X/A/Z, Tab, arrows, Home/End... must be routed
   * through the control's TranslateAccelerator in the message loop */
  IOleObject_QueryInterface(g_ole, &IID_IOleInPlaceActiveObject, (void **) &g_ao);

  wchar_t wurl[256];
  MultiByteToWideChar(CP_UTF8, 0, url, -1, wurl, 256);
  VARIANT vv;
  VariantInit(&vv);
  BSTR b = SysAllocString(wurl);
  IWebBrowser2_Navigate(g_wb, b, &vv, &vv, &vv, &vv);
  SysFreeString(b);
  browser_resize();
  return 0;
}

/* ------------------------------------------------------------------ */
/* Tray icon                                                           */
/* ------------------------------------------------------------------ */

static NOTIFYICONDATAW g_nid;

static void tray_add(HINSTANCE hi) {
  memset(&g_nid, 0, sizeof(g_nid));
  g_nid.cbSize = sizeof(g_nid);
  g_nid.hWnd = g_hwnd;
  g_nid.uID = 1;
  g_nid.uFlags = NIF_ICON | NIF_MESSAGE | NIF_TIP;
  g_nid.uCallbackMessage = WM_TRAY;
  g_nid.hIcon = LoadIconW(hi, MAKEINTRESOURCEW(1));
  if (!g_nid.hIcon) g_nid.hIcon = LoadIconW(NULL, (LPCWSTR) IDI_APPLICATION);
  lstrcpyW(g_nid.szTip, L"SchoolDesk Pro");
  Shell_NotifyIconW(NIM_ADD, &g_nid);
}

static void tray_menu(void) {
  POINT pt;
  GetCursorPos(&pt);
  HMENU m = CreatePopupMenu();
  AppendMenuW(m, MF_STRING, ID_TRAY_SHOW, L"\x0646\x0645\x0627\x06CC\x0634 \x0628\x0631\x0646\x0627\x0645\x0647"); /* نمایش برنامه */
  AppendMenuW(m, MF_SEPARATOR, 0, NULL);
  AppendMenuW(m, MF_STRING, ID_TRAY_EXIT, L"\x062E\x0631\x0648\x062C"); /* خروج */
  SetForegroundWindow(g_hwnd);
  TrackPopupMenu(m, TPM_RIGHTBUTTON | TPM_BOTTOMALIGN, pt.x, pt.y, 0, g_hwnd, NULL);
  DestroyMenu(m);
}

/* ------------------------------------------------------------------ */
/* Window proc                                                         */
/* ------------------------------------------------------------------ */

static LRESULT CALLBACK wndproc(HWND h, UINT msg, WPARAM wp, LPARAM lp) {
  switch (msg) {
    case WM_SIZE:
      browser_resize();
      return 0;
    case WM_SETFOCUS: { /* hand keyboard focus to the embedded browser */
      HWND child = GetWindow(h, GW_CHILD);
      if (child) SetFocus(child);
      return 0;
    }
    case WM_CLOSE: /* minimize to tray instead of exit */
      ShowWindow(h, SW_HIDE);
      return 0;
    case WM_TRAY:
      if (lp == WM_LBUTTONDBLCLK || lp == WM_LBUTTONUP) {
        ShowWindow(h, SW_SHOW);
        ShowWindow(h, SW_RESTORE);
        SetForegroundWindow(h);
      } else if (lp == WM_RBUTTONUP) {
        tray_menu();
      }
      return 0;
    case WM_COMMAND:
      switch (LOWORD(wp)) {
        case ID_TRAY_SHOW:
          ShowWindow(h, SW_SHOW); ShowWindow(h, SW_RESTORE); SetForegroundWindow(h);
          return 0;
        case ID_TRAY_EXIT:
          g_quit = 1;
          DestroyWindow(h);
          return 0;
      }
      break;
    case WM_DESTROY:
      Shell_NotifyIconW(NIM_DELETE, &g_nid);
      g_quit = 1;
      PostQuitMessage(0);
      return 0;
  }
  return DefWindowProcW(h, msg, wp, lp);
}

/* ------------------------------------------------------------------ */
/* Public entry: run the native window (blocks until exit)             */
/* ------------------------------------------------------------------ */

/* Ask the embedded MSHTML control to run in IE11/edge document mode
 * (default is IE7 emulation!). HKCU write needs no admin rights. */
static void enable_modern_mshtml(void) {
  wchar_t path[MAX_PATH], *name;
  if (!GetModuleFileNameW(NULL, path, MAX_PATH)) return;
  name = wcsrchr(path, L'\\');
  name = name ? name + 1 : path;
  HKEY k;
  if (RegCreateKeyExW(HKEY_CURRENT_USER,
        L"Software\\Microsoft\\Internet Explorer\\Main\\FeatureControl\\FEATURE_BROWSER_EMULATION",
        0, NULL, 0, KEY_SET_VALUE, NULL, &k, NULL) == ERROR_SUCCESS) {
    DWORD v = 11001; /* IE11 edge mode, ignores doctype quirks */
    RegSetValueExW(k, name, 0, REG_DWORD, (const BYTE *) &v, sizeof(v));
    RegCloseKey(k);
  }
  if (RegCreateKeyExW(HKEY_CURRENT_USER,
        L"Software\\Microsoft\\Internet Explorer\\Main\\FeatureControl\\FEATURE_GPU_RENDERING",
        0, NULL, 0, KEY_SET_VALUE, NULL, &k, NULL) == ERROR_SUCCESS) {
    DWORD v = 1;
    RegSetValueExW(k, name, 0, REG_DWORD, (const BYTE *) &v, sizeof(v));
    RegCloseKey(k);
  }
}

int webwin_run(const char *url) {
  snprintf(g_nav_url, sizeof(g_nav_url), "%s", url);
  enable_modern_mshtml();
  HINSTANCE hi = GetModuleHandleW(NULL);

  /* Per-monitor DPI awareness where available (Win 8.1+), fallback Vista+ */
  HMODULE user32 = GetModuleHandleW(L"user32.dll");
  typedef BOOL(WINAPI * SPDA)(void *);
  SPDA setCtx = (SPDA) (void *) GetProcAddress(user32, "SetProcessDpiAwarenessContext");
  if (setCtx) setCtx((void *) -4 /* PER_MONITOR_AWARE_V2 */);
  else {
    typedef BOOL(WINAPI * SPD)(void);
    SPD setAware = (SPD) (void *) GetProcAddress(user32, "SetProcessDPIAware");
    if (setAware) setAware();
  }

  OleInitialize(NULL);

  WNDCLASSW wc;
  memset(&wc, 0, sizeof(wc));
  wc.lpfnWndProc = wndproc;
  wc.hInstance = hi;
  wc.hIcon = LoadIconW(hi, MAKEINTRESOURCEW(1));
  wc.hCursor = LoadCursorW(NULL, (LPCWSTR) IDC_ARROW);
  wc.hbrBackground = (HBRUSH) (COLOR_WINDOW + 1);
  wc.lpszClassName = L"SchoolDeskWnd";
  RegisterClassW(&wc);

  int sw = GetSystemMetrics(SM_CXSCREEN), sh = GetSystemMetrics(SM_CYSCREEN);
  int w = sw * 86 / 100, h = sh * 88 / 100;
  if (w > 1480) w = 1480;
  if (h > 940) h = 940;

  g_hwnd = CreateWindowExW(0, L"SchoolDeskWnd",
      L"SchoolDesk Pro \x2014 \x0633\x0627\x0645\x0627\x0646\x0647 \x06A9\x0627\x0645\x0644 \x0645\x062F\x0631\x0633\x0647", /* SchoolDesk — پنل مدیریت مدرسه */
      WS_OVERLAPPEDWINDOW, (sw - w) / 2, (sh - h) / 2, w, h, NULL, NULL, hi, NULL);
  if (!g_hwnd) { OleUninitialize(); return -1; }

  if (browser_create(g_nav_url) != 0) {
    /* MSHTML unavailable — fall back to the default browser */
    ShellExecuteA(NULL, "open", g_nav_url, NULL, NULL, SW_SHOWNORMAL);
    DestroyWindow(g_hwnd);
    OleUninitialize();
    return 1;
  }

  tray_add(hi);
  ShowWindow(g_hwnd, SW_SHOW);
  UpdateWindow(g_hwnd);

  MSG m;
  while (GetMessageW(&m, NULL, 0, 0) > 0) {
    /* Route keystrokes to the embedded MSHTML control FIRST so every
     * standard shortcut works exactly like a native Windows app:
     *   Ctrl+C / Ctrl+X / Ctrl+V / Ctrl+A / Ctrl+Z / Ctrl+Y
     *   Tab / Shift+Tab (field navigation), arrows, Home/End/PgUp/PgDn,
     *   Delete/Backspace, Enter, F5 (refresh) ... */
    if (g_ao && (m.message == WM_KEYDOWN || m.message == WM_KEYUP ||
                 m.message == WM_SYSKEYDOWN || m.message == WM_SYSKEYUP ||
                 m.message == WM_CHAR || m.message == WM_SYSCHAR)) {
      if (IOleInPlaceActiveObject_TranslateAccelerator(g_ao, &m) == S_OK)
        continue; /* handled by the browser control */
    }
    TranslateMessage(&m);
    DispatchMessageW(&m);
  }

  if (g_ao) IOleInPlaceActiveObject_Release(g_ao);
  if (g_wb) IWebBrowser2_Release(g_wb);
  if (g_ole) { IOleObject_Close(g_ole, OLECLOSE_NOSAVE); IOleObject_Release(g_ole); }
  OleUninitialize();
  g_quit = 1;
  return 0;
}

#endif /* _WIN32 */
