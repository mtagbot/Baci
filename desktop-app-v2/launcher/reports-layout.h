/* One-time, fail-closed migration. Never merge two web roots or rewrite school data/config. */
#ifndef SDP_REPORTS_LAYOUT_H
#define SDP_REPORTS_LAYOUT_H
static int sdp_layout_exists(const char *p) { return GetFileAttributesA(p)!=INVALID_FILE_ATTRIBUTES; }
static int sdp_layout_dir(const char *p) { DWORD a=GetFileAttributesA(p);return a!=INVALID_FILE_ATTRIBUTES&&(a&FILE_ATTRIBUTE_DIRECTORY); }
static int sdp_router_ready(const char *p) {
    char b[512];FILE *f=fopen(p,"rb");if(!f)return 0;size_t n=fread(b,1,sizeof(b)-1,f);fclose(f);b[n]=0;
    return strstr(b,"SDP_REPORTS_ROOT_V1")!=NULL;
}
/* 0 success; 1 two roots; 2 incomplete install/update; 3 worker busy; 4 filesystem error. */
static int sdp_prepare_reports(const char *base) {
    char old[MAX_PATH*2],web[MAX_PATH*2],pending[MAX_PATH*2],router[MAX_PATH*2],data[MAX_PATH*2],lock[MAX_PATH*2],tmp[MAX_PATH*2],backup[MAX_PATH*2],probe[MAX_PATH*2];
    snprintf(old,sizeof(old),"%s\\www",base);snprintf(web,sizeof(web),"%s\\reports",base);
    snprintf(pending,sizeof(pending),"%s\\reports-layout-update\\router.php",base);
    int hasOld=sdp_layout_exists(old),hasWeb=sdp_layout_exists(web);
    if(hasOld&&hasWeb)return 1; /* No guesses about which installation contains the user's data. */
    if((hasOld&&!sdp_layout_dir(old))||(hasWeb&&!sdp_layout_dir(web))||(!hasOld&&!hasWeb))return 2;
    snprintf(probe,sizeof(probe),"%s\\index.php",hasOld?old:web);if(!sdp_layout_exists(probe))return 2;
    snprintf(probe,sizeof(probe),"%s\\includes\\functions.php",hasOld?old:web);if(!sdp_layout_exists(probe))return 2;
    snprintf(router,sizeof(router),"%s\\router.php",web);
    int patch=sdp_layout_exists(pending);
    if(!patch)return (!hasOld&&sdp_router_ready(router))?0:2;
    if(!sdp_router_ready(pending))return 2;
    snprintf(data,sizeof(data),"%s\\data",base);CreateDirectoryA(data,NULL);
    // Ask the previous worker to stop AFTER its current sync transaction, never terminate it mid-write.
    snprintf(probe,sizeof(probe),"%s\\app-alive.txt",data);if(sdp_layout_exists(probe)&&!DeleteFileA(probe))return 4;
    snprintf(lock,sizeof(lock),"%s\\sync-daemon.lock",data);
    HANDLE h=CreateFileA(lock,GENERIC_READ|GENERIC_WRITE,FILE_SHARE_READ|FILE_SHARE_WRITE,NULL,OPEN_ALWAYS,FILE_ATTRIBUTE_NORMAL,NULL);
    if(h==INVALID_HANDLE_VALUE)return 4;
    OVERLAPPED ov;memset(&ov,0,sizeof(ov));
    if(!LockFileEx(h,LOCKFILE_EXCLUSIVE_LOCK|LOCKFILE_FAIL_IMMEDIATELY,0,1,0,&ov)){CloseHandle(h);return 3;}
    int moved=0,result=4;
    if(hasOld){if(!MoveFileExA(old,web,MOVEFILE_WRITE_THROUGH))goto done;moved=1;}
    snprintf(backup,sizeof(backup),"%s\\router-before-reports.php",data);
    if(sdp_layout_exists(router)&&!sdp_layout_exists(backup)&&!CopyFileA(router,backup,TRUE))goto rollback;
    snprintf(tmp,sizeof(tmp),"%s\\.router-reports.pending",web);
    if(!CopyFileA(pending,tmp,FALSE))goto rollback;
    if(!MoveFileExA(tmp,router,MOVEFILE_REPLACE_EXISTING|MOVEFILE_WRITE_THROUGH)){DeleteFileA(tmp);goto rollback;}
    // Retiring the staged file makes later launches non-destructive. Do not reapply a patch every boot.
    if(!DeleteFileA(pending))goto done; // valid reports/ remains; retry is safe, no server yet
    result=0;goto done;
rollback:
    if(moved)MoveFileExA(web,old,MOVEFILE_WRITE_THROUGH); // if this fails, both data and old router still exist under reports/
done:
    UnlockFileEx(h,0,1,0,&ov);CloseHandle(h);return result;
}
#endif
