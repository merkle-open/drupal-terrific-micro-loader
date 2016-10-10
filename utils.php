<?php

define('BASE', DRUPAL_ROOT . '/' . variable_get('terrific_frontend_path', '../../frontend/'));

include_once( BASE . 'project/index.project.php' ); // use this file for all your customisations


/**
 * Compiles a CSS/LESS/SCSS/JS file.
 *
 * @param $filename
 * @param $extension
 * @param array $dependencies
 *
 * @return string
 */
function micro_compile($filename, $extension, $dependencies = array() ) {

  $nocache = false;
  $tmp_directory = file_directory_temp();

  switch ( $extension ) {
    case 'less':
      $modified = filemtime( $filename );
      foreach ( $dependencies as $dep ) {
        if ( substr( strrchr( $dep, '.' ), 1 ) == $extension && filemtime(BASE .  $dep ) > $modified ) {
          $modified = filemtime(BASE . $dep );
        }
      }

      $cachefile = $tmp_directory . '/terrific-' . md5( BASE . implode( '', $dependencies ) . $filename ) . '.css';
      if ( $nocache || !is_file( $cachefile ) || ( filemtime( $cachefile ) != $modified ) ) {

        $filecontents = '';
        foreach ( $dependencies as $dep ) {
          if ( substr( strrchr( $dep, '.' ), 1 ) == $extension ) {
            $filecontents .= file_get_contents( BASE . $dep );
          }
        }
        $filecontents .= file_get_contents( $filename );

        $less = get_less_parser();
        try {
          $content = $less->compile( $filecontents );
          file_put_contents( $cachefile, $content );
          touch( $cachefile, $modified );
        } catch ( Exception $e ) {
          $content = get_compile_error_css( $e, $filename, 'lessphp' );
        }
      }
      else {
        $content = file_get_contents( $cachefile );
      }
      break;

    case 'scss':
      $modified = filemtime( $filename );
      foreach ( $dependencies as $dep ) {
        if ( substr( strrchr( $dep, '.' ), 1 ) == $extension && filemtime(BASE .  $dep ) > $modified ) {
          $modified = filemtime( $dep );
        }
      }

      $cachefile = $tmp_directory . '/terrific-' . md5( BASE . implode( '', $dependencies ) . $filename ) . '.css';
      if ( $nocache || !is_file( $cachefile ) || ( filemtime( $cachefile ) != $modified ) ) {

        $filecontents = '';
        foreach ( $dependencies as $dep ) {
          if ( substr( strrchr( $dep, '.' ), 1 ) == $extension ) {
            $filecontents .= file_get_contents(BASE .  $dep );
          }
        }
        $filecontents .= file_get_contents( $filename );

        $scss = get_scss_parser();
        try {
          $content = $scss->compile( $filecontents );
          file_put_contents( $cachefile, $content );
          touch( $cachefile, $modified );
        } catch ( Exception $e ) {
          $content = get_compile_error_css( $e, $filename, 'scssphp' );
        }
      }
      else {
        $content = file_get_contents( $cachefile );
      }
      break;

    default:
      $content = file_get_contents( $filename );
      break;
  }

  return $content . PHP_EOL;
}


/**
 * Dumps a CSS/JS file
 *
 * @param $name
 * @param $cache_key
 * @param bool $minify
 *
 * @return string
 */
function micro_dump($name, $cache_key, $minify = TRUE ) {

  $drupal_cache_directory = variable_get('file_public_path', conf_path() . '/files') . '/terrific';

  $config = json_decode( file_get_contents( BASE . 'config.json' ) );
  $nocache = false;

  $starttime = microtime( true );

  $excludes     = array();
  $dependencies = array();
  $patterns     = array();

  $filetype = substr( strrchr( $name, '.' ), 1 );

  $output   = '';


  $debugjavascript = $filetype === 'js' && isset( $_REQUEST['debug'] );
  if ( $debugjavascript ) {
    $output .= '// load js files in a synchronous way' . PHP_EOL;
  }

  // check whether the file is in drupal cache
  if(!is_dir($drupal_cache_directory)) {
    mkdir($drupal_cache_directory, 0755);
  }

  $cache_file = $drupal_cache_directory . '/terrific-' . $cache_key . '-' . $name;

  if ($nocache || !is_file($cache_file)) {

    // collect excluded pattern & (less/scss) dependencies & patterns
    foreach ($config->assets->$name as $pattern) {
      $firstchar = substr($pattern, 0, 1);
      if ($firstchar === '!') {
        $excludes[] = substr($pattern, 1);
      }
      else {
        if ($firstchar === '+') {
          $dependencies[] = substr($pattern, 1);
        }
        else {
          $patterns[] = $pattern;
        }
      }
    }

    $dependencies = get_files($dependencies);
    $excludes = array_merge($dependencies, $excludes);
    $files = get_files($patterns, $excludes);

    foreach ($files as $entry) {
      if (!$debugjavascript) {
        $format = substr(strrchr($entry, '.'), 1);
        $output .= micro_compile(BASE . $entry, $format, $dependencies);
      }
      else {
        $output .= "document.write('<script type=\"text/javascript\" src=\"$entry\"><\/script>');" . PHP_EOL;
      }
    }

    if ($minify) {
      switch ($filetype) {
        case 'css':
          require BASE . 'app/library/cssmin/cssmin.php';
          $output = CssMin::minify($output);
          break;
        case 'js':
          require BASE . 'app/library/jshrink/Minifier.php';
          $output = \JShrink\Minifier::minify($output);
          break;
      }
    }

    $time_taken = microtime(TRUE) - $starttime;
    $output = get_asset_banner($name, $filetype, $minify, $time_taken) . $output;
    file_put_contents($cache_file, $output);
  }

  return $cache_file;
}

/**
 * Gets an array of files with given glob patterns
 *
 * @param $patterns
 * @param array $excludes
 *
 * @return array
 */
function get_files( $patterns, $excludes = array() ) {
  $files = array();
  foreach ( $patterns as $pattern ) {
    foreach ( glob( BASE . $pattern ) as $uri ) {
      $file = str_replace( BASE, '', $uri );
      if ( is_file( $uri ) && !is_excluded_file( $file, $excludes ) ) {
        $files[] = $file;
      }
    }
  }

  return array_unique( $files );
}

/**
 * Checks if a file matches an exclude pattern
 *
 * @param $filename
 * @param array $excludes
 *
 * @return bool
 */
function is_excluded_file( $filename, $excludes = array() ) {
  foreach ( $excludes as $exclude ) {
    if ( fnmatch( $exclude, $filename ) ) {
      return true;
      break;
    }
  }

  return false;
}


/**
 * Returns the less parser
 *
 * @return lessc
 */
function get_less_parser() {

  require_once BASE . 'app/library/lessphp/lessc.inc.php';
  $less = new lessc;

  //$less->setImportDir( array( '' ) ); // default
  //$less->addImportDir( 'assets/bootstrap' );

  return $less;
}

/**
 * Returns the scss parser
 *
 * @return scssc
 */
function get_scss_parser() {

  require_once BASE . 'app/library/scssphp/scss.inc.php';
  $scss = new scssc;

  //$scss->setImportPaths( array( '' ) ); // default
  //$scss->addImportPath( 'assets/bootstrap' );

  return $scss;
}

/**
 * Processes a requested asset (from config.json)
 */
function process_asset() {
  global $config;

  foreach ( $config->assets as $asset => $value ) {
    if ( preg_match( '/\/' . $asset . '/', $_SERVER['REQUEST_URI'] ) ) {
      $filetype = substr( strrchr( $asset, '.' ), 1 );
      switch ( $filetype ) {
        case 'css':
          $mimetype = 'text/css';
          break;
        case 'js':
          $mimetype = 'text/javascript';
          break;
        default:
          $mimetype = '';
          break;
      }
      micro_dump( $asset, $mimetype, '' );
      exit();
    }
  }
}

/**
 * Gets a header string for a processed asset
 *
 * @param string $filename
 * @param string $filetype
 * @param bool $minified
 * @param $duration
 *
 * @return string
 */
function get_asset_banner( $filename = '', $filetype = '', $minified = false, $duration ) {
  $ret = '';
  if ( isset( $duration ) ) {
    $time_taken = round( $duration * 1000 );
    $ret .= '/* time taken: ' . $time_taken . ' ms';
    $ret .= $minified ? ' (minified)' : '';
    $ret .= ' */' . PHP_EOL;
  }

  return $ret;
}