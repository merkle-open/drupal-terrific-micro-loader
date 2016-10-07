<?php

namespace Drupal\terrific_integration;

use JShrink\Minifier;

/**
 * Asset manager for terrific integration.
 */
class AssetManager {

  private $urlPatterns;
  private $assetName;
  private $fileType;
  private $basePath;
  private $output = '';
  private $cacheKey;
  private $cachedAssetsDir;
  private $frontendDir;

  /**
   * AssetManager constructor.
   */
  public function __construct($basePath, array $urlPatterns, $assetName) {
    $this->basePath = $basePath;
    $this->frontendDir = $basePath . '/../frontend/';
    $this->cachedAssetsDir = $basePath . '/sites/default/files/terrific/';
    $this->urlPatterns = $urlPatterns;
    $this->assetName = $assetName;
    $this->fileType = substr(strrchr($assetName, '.'), 1);
    $this->cacheKey = \Drupal::state()->get('system.css_js_query_string');
  }

  /**
   * Dump.
   */
  public function dump() {
    $excludes = $dependencies = $patterns = array();

    // Create caching dir.
    if (!is_dir($this->cachedAssetsDir)) {
      mkdir($this->cachedAssetsDir, 0755);
    }

    $cacheFile = $this->getCacheFile();

    if (!file_exists($cacheFile)) {
      // TODO: refactor.
      foreach ($this->urlPatterns as $urlPattern) {
        $firstchar = substr($urlPattern, 0, 1);
        if ($firstchar === '!') {
          $excludes[] = substr($urlPattern, 1);
        }
        else {
          if ($firstchar === '+') {
            $dependencies[] = substr($urlPattern, 1);
          }
          else {
            $patterns[] = $urlPattern;
          }
        }
      }

      $dependencies = $this->getFilesByPatterns($dependencies);
      $excludes = array_merge($dependencies, $excludes);
      $files = $this->getFilesByPatterns($patterns, $excludes);

      $rawOutput = '';
      foreach ($files as $entry) {
        $rawOutput .= $this->compile($this->frontendDir . $entry, $dependencies);
      }

      $this->output = $this->minify($rawOutput);

      // TODO: END refactor.
      // TODO: carbage collector.
      file_put_contents($cacheFile, $this->output);
    }
    else {
      $this->output = file_get_contents($cacheFile);
    }
  }

  /**
   * Return files by patterns.
   */
  private function getFilesByPatterns($patterns, $excludes = array()) {
    $files = array();
    foreach ($patterns as $pattern) {
      foreach (glob($this->basePath . $pattern) as $uri) {
        $file = str_replace($this->basePath, '', $uri);
        if (is_file($uri) && !$this->isExcludedFile($file, $excludes)) {
          $files[] = $file;
        }
      }
    }
    return array_unique($files);
  }

  /**
   * Compile.
   */
  private function compile($fileName, $dependencies = array()) {
    $cachedir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : $this->frontendDir . 'app/cache';
    $extension = substr(strrchr($fileName, '.'), 1);

    switch ($extension) {
      case 'less':
        $modified = filemtime($fileName);
        foreach ($dependencies as $dep) {
          if (substr(strrchr($dep, '.'), 1) == $extension && filemtime($this->frontendDir . $dep) > $modified) {
            $modified = filemtime($this->frontendDir . $dep);
          }
        }
        $cachefile = $cachedir . '/terrific-' . md5($this->frontendDir . implode('', $dependencies) . $fileName) . '.css';
        if (!is_file($cachefile) || (filemtime($cachefile) != $modified)) {
          $filecontents = '';
          foreach ($dependencies as $dep) {
            if (substr(strrchr($dep, '.'), 1) == $extension) {
              $filecontents .= file_get_contents($this->frontendDir . $dep);
            }
          }
          $filecontents .= file_get_contents($fileName);

          $less = $this->getLessParser();
          try {
            $content = $less->compile($filecontents);
            file_put_contents($cachefile, $content);
            touch($cachefile, $modified);
          }
          catch (\Exception $e) {
            $content = $e->getMessage();
          }
        }
        else {
          $content = file_get_contents($cachefile);
        }
        break;

      default:
        $content = file_get_contents($fileName);
        break;
    }

    return $content . PHP_EOL;
  }

  /**
   * Helper function to check if pattern is an exclude.
   */
  private function isExcludedFile($filename, $excludes = array()) {
    foreach ($excludes as $exclude) {
      if (fnmatch($exclude, $filename)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get the headers.
   */
  public function getHeaders() {
    $headers = array();
    switch ($this->fileType) {
      case 'css':
        $headers['Content-Type'] = 'text/css';
        break;

      case 'js':
        $headers['Content-Type'] = 'application/javascript';
        break;
    }
    $headers['Cache-Control'] = 'public, max-age=' . 2 * 7 * 24 * 60 * 60;
    return $headers;
  }

  /**
   * Get output.
   */
  public function getOutput() {
    return $this->output;
  }

  /**
   * Helper function to get less parser.
   */
  private function getLessParser() {
    require_once $this->basePath . 'app/library/lessphp/lessc.inc.php';
    $less = new \lessc();
    return $less;
  }

  /**
   * Helper function to get cache file.
   */
  private function getCacheFile() {
    return $this->cachedAssetsDir . $this->cacheKey . '-' . $this->assetName;
  }

  /**
   * Helper function to minify raw output.
   */
  private function minify($rawOutput) {
    switch ($this->fileType) {
      case 'css':
        require $this->basePath . 'app/library/cssmin/CssMin.php';
        return \CssMin::minify($rawOutput);

      case 'js':
        require $this->basePath . 'app/library/jshrink/Minifier.php';
        return Minifier::minify($rawOutput);
    }
    return '';
  }

}
