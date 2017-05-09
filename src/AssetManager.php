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
  private $cachedAssetsDir;

  /**
   * AssetManager constructor.
   */
  public function __construct($basePath) {
    $this->basePath = $basePath;
    $this->cachedAssetsDir = \Drupal::service('file_system')->realpath('public://terrific') . '/';
  }

  /**
   * Dump.
   */
  public function dump(array $urlPatterns, $assetName) {
    $this->urlPatterns = $urlPatterns;
    $this->assetName = $assetName;
    $this->fileType = substr(strrchr($assetName, '.'), 1);

    $excludes = $dependencies = $patterns = [];

    // Create caching dir.
    if (!is_dir($this->cachedAssetsDir)) {
      mkdir($this->cachedAssetsDir, 0755);
    }

    $cacheFile = $this->cachedAssetsDir . $this->assetName;

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
        $rawOutput .= $this->compile($this->basePath . $entry, $dependencies);
      }

      $this->output = $this->minify($rawOutput);

      // TODO: END refactor.
      // TODO: carbage collector.
      file_put_contents($cacheFile, $this->output);
    }
  }

  /**
   * Return files by patterns.
   */
  private function getFilesByPatterns($patterns, $excludes = []) {
    $files = [];
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
  private function compile($fileName, $dependencies = []) {
    $cachedir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : $this->basePath . 'app/cache';
    $extension = substr(strrchr($fileName, '.'), 1);

    switch ($extension) {
      case 'less':
        $modified = filemtime($fileName);
        foreach ($dependencies as $dep) {
          if (substr(strrchr($dep, '.'), 1) == $extension && filemtime($this->basePath . $dep) > $modified) {
            $modified = filemtime($this->basePath . $dep);
          }
        }
        $cachefile = $cachedir . '/terrific-' . md5($this->basePath . implode('', $dependencies) . $fileName) . '.css';
        if (!is_file($cachefile) || (filemtime($cachefile) != $modified)) {
          $filecontents = '';
          foreach ($dependencies as $dep) {
            if (substr(strrchr($dep, '.'), 1) == $extension) {
              $filecontents .= file_get_contents($this->basePath . $dep);
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
  private function isExcludedFile($filename, $excludes = []) {
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
    $headers = [];
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
   * Helper function to minify raw output.
   */
  private function minify($rawOutput) {
    switch ($this->fileType) {
      case 'css':
        require_once $this->basePath . 'app/library/cssmin/CssMin.php';
        return \CssMin::minify($rawOutput);

      case 'js':
        require_once $this->basePath . 'app/library/jshrink/Minifier.php';
        return Minifier::minify($rawOutput);
    }
    return '';
  }

}
