<?php
// /home/bot.dailymu.com/private/src/CacheHandler.php

class CacheHandler {
    private $cacheDir;
    private $cacheDuration;

    public function __construct() {
        $this->cacheDir = Config::CACHE_DIRECTORY;
        $this->cacheDuration = Config::CACHE_DURATION;
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public function get($key) {
        $filename = $this->getCacheFilePath($key);
        
        if (!file_exists($filename)) {
            return null;
        }

        $content = file_get_contents($filename);
        $data = unserialize($content);
        
        if (time() > $data['expires']) {
            $this->delete($key);
            return null;
        }

        return $data['value'];
    }

    public function set($key, $value) {
        $data = [
            'expires' => time() + $this->cacheDuration,
            'value' => $value
        ];

        $filename = $this->getCacheFilePath($key);
        return file_put_contents($filename, serialize($data));
    }

    public function delete($key) {
        $filename = $this->getCacheFilePath($key);
        if (file_exists($filename)) {
            return unlink($filename);
        }
        return true;
    }

    private function getCacheFilePath($key) {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    public function clear() {
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
}