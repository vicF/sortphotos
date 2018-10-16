<?php

$conf = parse_ini_file('config.ini');

$existingFiles = [];
if ($conf['check_for_duplicates']) {
    $checkFolders[rtrim($conf['target'], '/')] = true;
    if (array_key_exists('targets_to_check_for_duplicates', $conf)) {
        foreach (explode(',', $conf['targets_to_check_for_duplicates']) as $folder) {
            $folder = trim($folder);
            if (empty($folder)) {
                continue;
            } else {
                if ($folder != '/') {
                    rtrim($folder, '/');  // Remove ending slash for all folders except root ("/")
                }
                $checkFolders[$folder] = true;
            }
        }
    }
    $checkFolders = array_keys($checkFolders);

    foreach ($checkFolders as $checkFolder) {
        echo "Scanning folder: {$checkFolder}\n";
        $targetIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($checkFolder, FilesystemIterator::KEY_AS_PATHNAME));
        while ($targetIterator->valid()) {
            if (!$targetIterator->isDot()) {
                $existingFiles[filesize($targetIterator->key())][] = $targetIterator->key();
            }
            $targetIterator->next();
        }
    }
}


$sourceIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($conf['source'], FilesystemIterator::KEY_AS_PATHNAME));

$targetBase = rtrim(trim($conf['target']), '/') . '/';

$log = @$conf['log'] ?: 'log.txt';

$sourceIterator->rewind();

$allowedExtensions = getExtensionsArray($conf['extensions']);
$deleteExtensions = getExtensionsArray($conf['extensions_to_delete']);

@file_put_contents($log, "------------------------------ " . date('Y-m-d h:m:s') . " -----------------------------\n\n", FILE_APPEND);

while ($sourceIterator->valid()) {
    if (!$sourceIterator->isDot()) {
        echo $sourceIterator->getSubPathName() . "\n";


        $subPath = $sourceIterator->getSubPathName();
        $extension = substr($subPath, strrpos($subPath, '.') + 1);
        if (!in_array(strtolower($extension), $allowedExtensions)) {
            if (in_array(strtolower($extension), $deleteExtensions)) {
                echo "Deleting: {$subPath}\n";
                unlink($sourceIterator->key());
            } else {
                echo "Skipping by extension\n";
            }
            $sourceIterator->next();
            continue;
        }
        $fullPath = $sourceIterator->key();
        if ($conf['check_for_duplicates']) {
            $size = filesize($fullPath);
            if ($size == 0) {
                // This is bad zero size file
                echo "File {$fullPath} has zero length!\n";
                @file_put_contents($log, "File \n{$fullPath} has zero length!\n", FILE_APPEND);
                if (@$conf['remove_existing_files']) {
                    echo("Removing zero length source file\n");
                    unlink($fullPath);
                }
                $sourceIterator->next();
                continue;
            }
            if (array_key_exists($size, $existingFiles)) {
                foreach ($existingFiles[$size] as $existingFile) {
                    if (filesAreEqual($fullPath, $existingFile)) {
                        echo "File {$fullPath} already exists as {$existingFile}\n";
                        @file_put_contents($log, "File \n{$fullPath} already exists as \n{$existingFile}\n", FILE_APPEND);
                        if (@$conf['remove_existing_files']) {
                            echo("Removing source file\n");
                            unlink($fullPath);
                        }
                        $sourceIterator->next();
                        continue 2;
                    }
                }
            }
        }

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
                $sourceIterator->next();
                continue;
            } else {
                @file_put_contents($log, "{$subPath} > {$targetDir}\n", FILE_APPEND);
            }
        }

        echo "Target dir: $targetDir  " . ($exactDate ? '' : '??????') . " \n";
        $dest = $targetBase . $targetDir;
        $newFullPath = $dest . '/' . basename($fullPath);
        if (is_file($newFullPath)) {
            if (filesAreEqual($newFullPath, $fullPath)) {
                @file_put_contents($log, "{$subPath} is identical to {$newFullPath}\n", FILE_APPEND);
                if (@$conf['remove_existing_files']) {
                    echo "Removing already existing identical file\n";
                    unlink($fullPath);
                }
                $sourceIterator->next();
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

    $sourceIterator->next();
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
        $empty &= is_dir($file) && RemoveEmptySubFolders($file, false);
        /*if (!$empty) {
            break;
        }*/
    }
    return $empty AND (!$root) AND rmdir($path);
}

/**
 * @param $a
 * @param $b
 * @return bool
 */
function filesAreEqual($a, $b)
{
    // Check if filesize is different
    if (filesize($a) !== filesize($b))
        return false;

    // Check if content is different
    $ah = fopen($a, 'rb');
    $bh = fopen($b, 'rb');

    $result = true;
    while (!feof($ah)) {
        if (fread($ah, 8192) != fread($bh, 8192)) {
            $result = false;
            break;
        }
    }

    fclose($ah);
    fclose($bh);

    return $result;
}

/**
 * @param $a
 * @param $b
 * @return string
 */
function mergePaths($a, $b)
{
    return rtrim($a, '/') . '/' . $b;
}

/**
 * @param $conf
 * @return array
 */
function getExtensionsArray($conf)
{
    $tmpExtensions = explode(' ', $conf);
    $allowedExtensions = [];
    foreach ($tmpExtensions as $extension) {
        $allowedExtensions[] = strtolower(trim(trim($extension, '.,')));
    }
    return $allowedExtensions;
}