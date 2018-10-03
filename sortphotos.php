<?php

$conf = parse_ini_file('config.ini');
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($conf['source']));

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
        /*$mime = mime_content_type($fullPath);
        switch ($mime) {
            case 'image/jpeg':
            case 'video/mp4':
                break;
            case 'application/octet-stream':
                $it->next();
                continue 2;
            default:
                // Skipping unknown file
                $it->next();
                continue 2;
        }*/
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
            if (@$conf['process_with_no_date'] AND $conf['process_with_no_date'] = 0) {
                echo "Skipping as no date set\n";
                $it->next();
                continue;
            } else {
                @file_put_contents($log, "{$subPath} > {$targetDir}\n", FILE_APPEND);
            }
        }

        echo "Target dir: $targetDir  " . ($exactDate ? '' : '??????') . " \n";
    }
    $it->next();
}
file_put_contents($log, "\n\n", FILE_APPEND);