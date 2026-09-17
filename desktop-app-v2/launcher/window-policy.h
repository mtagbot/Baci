/* Two size states: maximized work area or minimized. Close/Alt+F4 remain available.
 * Only the isolated app browser PID is observed; never alter unrelated browsers,
 * print/file dialogs or owned popup windows. No injection into another process.
 */
static DWORD sdp_browser_pid;
static int sdp_enforcing;
static BOOL CALLBACK sdp_lock_window(HWND hwnd, LPARAM unused) {
    (void)unused;
    DWORD pid=0; char cls[80];
    GetWindowThreadProcessId(hwnd,&pid);
    if(pid!=sdp_browser_pid || !IsWindowVisible(hwnd) || GetWindow(hwnd,GW_OWNER))return TRUE;
    GetClassNameA(hwnd,cls,sizeof(cls));
    if(strcmp(cls,"Chrome_WidgetWin_1")!=0)return TRUE;
    if(sdp_enforcing)return TRUE;
    sdp_enforcing=1;
    SetPropW(hwnd,L"SchoolDeskProManaged",(HANDLE)(ULONG_PTR)sdp_browser_pid);
    LONG_PTR style=GetWindowLongPtrW(hwnd,GWL_STYLE);
    LONG_PTR fixed=(style & ~(WS_THICKFRAME|WS_MAXIMIZEBOX)) | WS_MINIMIZEBOX | WS_SYSMENU;
    if(style!=fixed){
        SetWindowLongPtrW(hwnd,GWL_STYLE,fixed);
        SetWindowPos(hwnd,NULL,0,0,0,0,SWP_FRAMECHANGED|SWP_NOMOVE|SWP_NOSIZE|SWP_NOZORDER|SWP_NOACTIVATE);
    }
    HMENU menu=GetSystemMenu(hwnd,FALSE);
    if(menu){
        EnableMenuItem(menu,SC_RESTORE,MF_BYCOMMAND|(IsIconic(hwnd)?MF_ENABLED:MF_GRAYED));
        EnableMenuItem(menu,SC_SIZE,MF_BYCOMMAND|MF_GRAYED);
        EnableMenuItem(menu,SC_MOVE,MF_BYCOMMAND|MF_GRAYED);
        EnableMenuItem(menu,SC_MAXIMIZE,MF_BYCOMMAND|MF_GRAYED);
        EnableMenuItem(menu,SC_MINIMIZE,MF_BYCOMMAND|MF_ENABLED);
    }
    if(!IsIconic(hwnd)&&!IsZoomed(hwnd))ShowWindow(hwnd,SW_MAXIMIZE);
    sdp_enforcing=0;
    return TRUE;
}
static void CALLBACK sdp_window_event(HWINEVENTHOOK hook,DWORD event,HWND hwnd,LONG object,LONG child,DWORD thread,DWORD time) {
    (void)hook;(void)child;(void)thread;(void)time;
    if(!hwnd)return;
    if(event==EVENT_SYSTEM_MINIMIZEEND || ((event==EVENT_OBJECT_SHOW || event==EVENT_OBJECT_LOCATIONCHANGE)&&object==OBJID_WINDOW))sdp_lock_window(hwnd,0);
}
static void sdp_wait_locked_browser(HANDLE process) {
    sdp_browser_pid=GetProcessId(process);
    HWINEVENTHOOK objects=SetWinEventHook(EVENT_OBJECT_SHOW,EVENT_OBJECT_LOCATIONCHANGE,NULL,sdp_window_event,sdp_browser_pid,0,WINEVENT_OUTOFCONTEXT);
    HWINEVENTHOOK restore=SetWinEventHook(EVENT_SYSTEM_MINIMIZEEND,EVENT_SYSTEM_MINIMIZEEND,NULL,sdp_window_event,sdp_browser_pid,0,WINEVENT_OUTOFCONTEXT);
    EnumWindows(sdp_lock_window,0);
    DWORD begin=GetTickCount();
    for(;;){
        DWORD delay=(GetTickCount()-begin<10000 || !objects || !restore)?500:INFINITE;
        DWORD result=MsgWaitForMultipleObjects(1,&process,FALSE,delay,QS_ALLINPUT);
        if(result==WAIT_OBJECT_0 || result==WAIT_FAILED)break;
        MSG message;
        while(PeekMessageW(&message,NULL,0,0,PM_REMOVE)){TranslateMessage(&message);DispatchMessageW(&message);}
        if(result==WAIT_TIMEOUT)EnumWindows(sdp_lock_window,0);
    }
    if(objects)UnhookWinEvent(objects);
    if(restore)UnhookWinEvent(restore);
    sdp_browser_pid=0;
}

static BOOL CALLBACK sdp_restore_existing(HWND hwnd,LPARAM result){
    HANDLE marker=GetPropW(hwnd,L"SchoolDeskProManaged");DWORD pid=0;
    GetWindowThreadProcessId(hwnd,&pid);
    if(marker && (DWORD)(ULONG_PTR)marker==pid){ShowWindow(hwnd,SW_MAXIMIZE);SetForegroundWindow(hwnd);*(int*)result=1;return FALSE;}
    return TRUE;
}
