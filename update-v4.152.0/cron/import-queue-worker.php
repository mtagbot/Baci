<?php
/** Legacy worker only simulated completion. Never mark unimported grades as completed. */
if (PHP_SAPI!=='cli') { http_response_code(403); exit('دسترسی غیرمجاز'); }
fwrite(STDERR,"Legacy import queue is disabled: no data or status was changed. Preview and confirm the file in the report Import Wizard.\n");
exit(1);
