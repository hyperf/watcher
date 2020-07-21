<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Watcher\Driver;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Utils\Filesystem\Filesystem;
use Hyperf\Utils\Str;
use Hyperf\Watcher\Option;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Symfony\Component\Finder\SplFileInfo;

class ScanFileDriver implements DriverInterface
{
    /**
     * @var int
     */
    const FINDER_MODE = 1;

    /**
     * @var int
     */
    const SHELL_MODE = 2;

    /**
     * @var Option
     */
    protected $option;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @Inject
     * @var StdoutLoggerInterface
     */
    private $logger;

    public function __construct(Option $option)
    {
        $this->option = $option;
        $this->filesystem = new Filesystem();
    }

    public function watch(Channel $channel): void
    {
        $ms = $this->option->getScanInterval() > 0 ? $this->option->getScanInterval() : 2000;
        Timer::tick($ms, function () use ($channel, $ms) {
            if ($this->option->getWatchModel() == self::FINDER_MODE) {
                global $lastMD5;
                $files = [];
                $currentMD5 = $this->getWatchMD5($files);
                if ($lastMD5 && $lastMD5 !== $currentMD5) {
                    $changeFilesMD5 = array_diff(array_values($lastMD5), array_values($currentMD5));
                    $addFiles = array_diff(array_keys($currentMD5), array_keys($lastMD5));
                    foreach ($addFiles as $file) {
                        $channel->push($file);
                    }
                    $deleteFiles = array_diff(array_keys($lastMD5), array_keys($currentMD5));
                    $deleteCount = count($deleteFiles);
    
                    $watchingLog = sprintf('%s Watching: Total:%d, Change:%d, Add:%d, Delete:%d.', __CLASS__, count($currentMD5), count($changeFilesMD5), count($addFiles), $deleteCount);
                    $this->logger->debug($watchingLog);
    
                    if ($deleteCount == 0) {
                        $changeFilesIdx = array_keys($changeFilesMD5);
                        foreach ($changeFilesIdx as $idx) {
                            isset($files[$idx]) && $channel->push($files[$idx]);
                        }
                    } else {
                        $this->logger->warning('Delete files must be restarted manually to take effect.');
                    }
                    $lastMD5 = $currentMD5;
                } else {
                    $lastMD5 = $currentMD5;
                }
                
            } else {
                global $updateFiles;
                // $sTime = microtime(true);
      
                $pushFiles = $this->shellWatch($updateFiles, $ms);
                foreach ($pushFiles as $file) {
                    $channel->push($file);
                }
                
                // var_dump('watch files use time:'. (microtime(true) - $sTime));
            }
        });
    }

    protected function getWatchMD5(&$files): array
    {
        $filesMD5 = [];
        $filesObj = [];
        $dir = $this->option->getWatchDir();
        $ext = $this->option->getExt();
        // Scan all watch dirs.
        foreach ($dir as $d) {
            $filesObj = array_merge($filesObj, $this->filesystem->allFiles(BASE_PATH . '/' . $d));
        }
        /** @var SplFileInfo $obj */
        foreach ($filesObj as $obj) {
            $pathName = $obj->getPathName();
            if (Str::endsWith($pathName, $ext)) {
                $files[] = $pathName;
                $contents = file_get_contents($pathName);
                $filesMD5[$pathName] = md5($contents);
            }
        }
        // Scan all watch files.
        $file = $this->option->getWatchFile();
        $filesObj = $this->filesystem->files(BASE_PATH, true);
        /** @var SplFileInfo $obj */
        foreach ($filesObj as $obj) {
            $pathName = $obj->getPathName();
            if (Str::endsWith($pathName, $file)) {
                $files[] = $pathName;
                $contents = file_get_contents($pathName);
                $filesMD5[$pathName] = md5($contents);
            }
        }
        return $filesMD5;
    }

    protected function shellWatch(&$updateFiles, $ms): array
    {
        $pushFiles = [];
        $dirs = $this->option->getWatchDir();
        $files = $this->option->getWatchFile();
        $ext = $this->option->getExt();

        $dirs = array_map(function($dir){
            return BASE_PATH . '/' . $dir;
        }, $dirs);
        $seconds = ceil(($ms + 1000)/1000);
    
        $minutes = sprintf("%.2f", $seconds/60);
        // scan directory files
        $dest = implode(' ', $dirs);
        $stdout = shell_exec('find '.$dest.' -mmin '.$minutes.' -type f -printf "%p %T+'.PHP_EOL.'"');
        if(is_string($stdout)){
            $lineArr = explode(PHP_EOL, $stdout);
            foreach($lineArr as $line){
                $fileArr = explode(' ', $line);
                if(count($fileArr) == 2) {
                    $pathName = $fileArr[0];
                    $modifyTime = $fileArr[1];

                    if (Str::endsWith($pathName, $ext)) {
                        if(isset($updateFiles[$pathName]) && $updateFiles[$pathName] == $modifyTime){
                            continue;
                        }else{
                            $updateFiles[$pathName] = $modifyTime;
                            $pushFiles[] = $pathName;
                        }
                    }
                }
            }
        }
        // scan specify files
        $files = array_map(function($file) {
            return BASE_PATH . '/' . $file;
        }, $files);
        $dest = implode(' ', $files);
        $stdout = shell_exec('find '.$dest.' -mmin '.$minutes.' -type f -printf "%p %T+'.PHP_EOL.'"');
        if(is_string($stdout)){
            $lineArr = explode(PHP_EOL, $stdout);
            foreach($lineArr as $line){
                $fileArr = explode(' ', $line);
                if(count($fileArr) == 2) {
                    $pathName = $fileArr[0];
                    $modifyTime = $fileArr[1];

                    if(isset($updateFiles[$pathName]) && $updateFiles[$pathName] == $modifyTime){
                        continue;
                    }else{
                        $updateFiles[$pathName] = $modifyTime;
                        $pushFiles[] = $pathName;
                    }
                }
            }
        }

        return $pushFiles;
    }
}
