<?php
/**
 * SchoolDesk Pro — shared ZIP reader for update packages (site + desktop).
 *
 * This class is shipped byte-identical in two places:
 *   - desktop: www/includes/desk_update_zip.php   (used by desk_update.php)
 *   - site:    includes/desk_update_zip.php       (used by desk_updates_store.php)
 * tests/test-desk-update.mjs asserts the two copies stay identical.
 *
 * Why it exists: the bundled Windows PHP runtime has no php_zip.dll and some
 * shared hosts disable ZipArchive. ZipArchive is used when available,
 * otherwise a dependency-free central-directory reader handles the two methods
 * a normal ZIP writer produces: stored (0) and deflate (8). Nothing is ever
 * extracted to disk here — entries are read into memory one at a time.
 */
if (!class_exists('DeskUpdateZip')) {

final class DeskUpdateZip {
    private string $path;
    private $handle;
    private ?ZipArchive $archive = null;
    private array $entries = [];
    public const MAX_ENTRIES = 5000;
    public const MAX_FILE_BYTES = 33554432;   // 32 MiB per entry
    public const MAX_TOTAL_BYTES = 314572800; // 300 MiB expanded

    public function __construct(string $path, bool $forcePure = false) {
        $this->path = $path;
        if (!$forcePure && class_exists('ZipArchive')) {
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) throw new RuntimeException('فایل بستهٔ ZIP قابل خواندن نیست.');
            $this->archive = $archive;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $this->entries[] = ['name' => (string)$stat['name'], 'size' => (int)$stat['size'], 'comp' => (int)$stat['comp_size'], 'method' => (int)$stat['comp_method']];
            }
            return;
        }
        $this->handle = fopen($path, 'rb');
        if (!$this->handle) throw new RuntimeException('فایل بستهٔ ZIP قابل خواندن نیست.');
        $this->readCentralDirectory();
    }

    private function readCentralDirectory(): void {
        $size = (int)filesize($this->path);
        if ($size < 22) throw new RuntimeException('ساختار ZIP معتبر نیست.');
        $tailLength = min($size, 66000);
        fseek($this->handle, $size - $tailLength);
        $tail = (string)fread($this->handle, $tailLength);
        $position = strrpos($tail, "PK\x05\x06");
        if ($position === false) throw new RuntimeException('ساختار ZIP معتبر نیست.');
        $record = substr($tail, $position, 22);
        // EOCD: 8 = entries on this disk, 10 = total entries (both 16-bit).
        $total = (int)unpack('v', substr($record, 10, 2))[1];
        $offset = unpack('V', substr($record, 16, 4))[1];
        if ($total < 1 || $total > self::MAX_ENTRIES) throw new RuntimeException('تعداد فایل‌های بسته مجاز نیست.');
        if ($offset >= $size) throw new RuntimeException('ساختار ZIP معتبر نیست.');
        fseek($this->handle, (int)$offset);
        $directory = (string)fread($this->handle, $size - (int)$offset);
        $cursor = 0; $totalBytes = 0;
        for ($index = 0; $index < $total; $index++) {
            if (substr($directory, $cursor, 4) !== "PK\x01\x02") throw new RuntimeException('فهرست ZIP معتبر نیست.');
            $head = unpack('vflag/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen/vcommentlen/vdiskvdisk/vinternal/Vexternal/Vlocal', substr($directory, $cursor + 8, 42));
            $name = substr($directory, $cursor + 46, (int)$head['namelen']);
            $uncompressed = (int)$head['uncompressed'];
            if ($uncompressed > self::MAX_FILE_BYTES) throw new RuntimeException('یک فایل بسته بیش از حد بزرگ است.');
            $totalBytes += $uncompressed;
            if ($totalBytes > self::MAX_TOTAL_BYTES) throw new RuntimeException('حجم باز‌شدهٔ بسته بیش از حد است.');
            $this->entries[] = [
                'name' => $name, 'size' => $uncompressed, 'comp' => (int)$head['compressed'],
                'method' => (int)$head['method'], 'local' => (int)$head['local'],
                'crc' => (int)$head['crc'], 'flag' => (int)$head['flag'],
            ];
            $cursor += 46 + (int)$head['namelen'] + (int)$head['extralen'] + (int)$head['commentlen'];
        }
    }

    /** @return array<int,string> */
    public function names(): array { $names = []; foreach ($this->entries as $entry) $names[] = $entry['name']; return $names; }
    public function count(): int { return count($this->entries); }
    public function size(string $name) {
        foreach ($this->entries as $entry) if ($entry['name'] === $name) return (int)$entry['size'];
        return null;
    }
    public function has(string $name): bool { return $this->size($name) !== null; }

    public function read(string $name): string {
        if ($this->archive) {
            $data = $this->archive->getFromName($name);
            if ($data === false) throw new RuntimeException('فایل داخل بسته خوانده نشد: ' . $name);
            return $data;
        }
        foreach ($this->entries as $entry) {
            if ($entry['name'] !== $name) continue;
            fseek($this->handle, (int)$entry['local']);
            $head = (string)fread($this->handle, 30);
            if (substr($head, 0, 4) !== "PK\x03\x04") throw new RuntimeException('ساختار فایل داخل بسته معتبر نیست.');
            $parts = unpack('vnamelen/vextralen', substr($head, 26, 4));
            $skip = (int)$parts['namelen'] + (int)$parts['extralen'];
            if ($skip > 0) fread($this->handle, $skip);
            $data = '';
            $needed = (int)$entry['comp'];
            if ($needed > 0) $data = (string)fread($this->handle, $needed);
            if ((int)$entry['method'] === 0) return $data;
            if ((int)$entry['method'] !== 8) throw new RuntimeException('روش فشرده‌سازی این بسته پشتیبانی نمی‌شود.');
            if (!function_exists('gzinflate')) throw new RuntimeException('این نسخهٔ PHP توان بازکردن بستهٔ فشرده را ندارد.');
            $plain = @gzinflate($data);
            if ($plain === false) throw new RuntimeException('بازکردن فایل فشردهٔ بسته ناموفق بود.');
            return $plain;
        }
        throw new RuntimeException('فایل داخل بسته پیدا نشد: ' . $name);
    }

    public function close(): void {
        if ($this->archive) { $this->archive->close(); $this->archive = null; }
        if ($this->handle) { fclose($this->handle); $this->handle = null; }
    }
}
}
