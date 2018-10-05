<?php

$conf = parse_ini_file('config.ini');
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($conf['source']));

$targetBase = rtrim(trim($conf['target']), '/') . '/';

$log = @$conf['log'] ?: 'log.txt';

$it->rewind();

$tmpExtensions = explode(' ', $conf['extensions']);
foreach ($tmpExtensions as $extension) {
    $allowedExtensions[] = strtolower(trim(trim($extension, '.,')));
}
unset($tmpExtensions);

@file_put_contents($log, "------------------------------ " . date('Y-m-d h:m:s') . " -----------------------------\n\n", FILE_APPEND);

while ($it->valid()) {
    if (!$it->isDot()) {
        echo $it->getSubPathName() . "\n";


        $subPath = $it->getSubPathName();
        $extension = substr($subPath, strrpos($subPath, '.') + 1);
        if (!in_array(strtolower($extension), $allowedExtensions)) {
            $it->next();
            echo "Skipping by extension\n";
            continue;
        }
        $fullPath = rtrim($conf['source'], '/') . '/' . $subPath;

        $targetDir = false;
        $exactDate = true;
        $exif = @exif_read_data($fullPath);
        if ($exif) {
            foreach (['DateTime', 'DateTimeOriginal', 'DateTimeDigitized'] as $key) {
                if (array_key_exists($key, $exif)) {
                    $targetDir = date($conf['template'], strtotime($exif[$key]));
                    if ($targetDir) {
                        break;
                    }
                }
            }
        }
        if (!$targetDir) {
            // Trying to get from name
            if (preg_match('/((19|20)\d\d)[-_]?(0[1-9]|1[012])[-_]?(0[1-9]|[12][0-9]|3[01])/', basename($subPath), $matches)) {
                $date = new DateTime();
                $date->setDate($matches[1], $matches[3], $matches[4]);
                $time = $date->getTimestamp();
                if ($time > strtotime('1826-01-01') AND $time < time()) {
                    $targetDir = date($conf['template'], $time);
                }

            } else {
                echo '';
            }

        }
        if (!$targetDir) {
            $exactDate = false;
            // take file creation time
            $stat = stat($fullPath);
            $time = min($stat['mtime'], $stat['ctime']);
            if (@$exif['FileDateTime']) {
                $time = min($time, $exif['FileDateTime']);
            }
            $targetDir = date($conf['template'], $time);

        }
        if (!$exactDate) {
            if (array_key_exists('process_with_no_date', $conf) AND $conf['process_with_no_date'] == 0) {
                echo "Skipping as no date set\n";
                $it->next();
                continue;
            } else {
                @file_put_contents($log, "{$subPath} > {$targetDir}\n", FILE_APPEND);
            }
        }

        echo "Target dir: $targetDir  " . ($exactDate ? '' : '??????') . " \n";
        $dest = $targetBase . $targetDir;
        $newFullPath = $dest . '/' . basename($fullPath);
        if (is_file($newFullPath)) {
            if (filesize($newFullPath) == filesize($fullPath) AND md5_file($newFullPath) == md5_file($fullPath)) {
                @file_put_contents($log, "{$subPath} is identical to {$newFullPath}\n", FILE_APPEND);
                echo "Removing already existing identical file\n";
                unlink($fullPath);
                $it->next();
                continue;
            } else {
                // Adding index to file name
                $i = 1;
                do {
                    $copyPath = $newFullPath . '_' . $i++;
                } while (!is_file($copyPath));
                $newFullPath = $copyPath;
                @file_put_contents($log, "{$subPath} already exists. Renamed to {$newFullPath}\n", FILE_APPEND);
            }
        }
        @mkdir($dest, 0777, true);
        if (!rename($fullPath, $newFullPath)) {
            die($php_errormsg);
        }
    }

    $it->next();
}
file_put_contents($log, "\n\n", FILE_APPEND);

if (array_key_exists('remove_empty_folders', $conf) AND $conf['remove_empty_folders'] == 1) {
    RemoveEmptySubFolders($conf['source']);
}

/**
 * @param $path
 * @param bool $root
 * @return bool
 */
function RemoveEmptySubFolders($path, $root = true)
{
    $empty = true;
    foreach (glob($path . DIRECTORY_SEPARATOR . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) as $file) {
        $empty &= is_dir($file) && RemoveEmptySubFolders($file);
        if(!$empty) {
            break;
        }
    }
    return $empty AND (!$root) AND rmdir($path);
}