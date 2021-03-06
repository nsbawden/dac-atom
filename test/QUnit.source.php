<?php
/**
 * Copyright 2010 Daniel Pupius (http://code.google.com/p/php-closure/)
 * Copyright 2015 dArtagnans Code (http://code.playnexus.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


/**
 * PHP wrapper for the Google Closure JS Compiler web service.
 *
 * Handles caching and recompilation of sources.  A recompilation will occur
 * whenever a source file, or the script calling the compiler, changes.
 *
 * The class will handle the Last-Modified and 304 redirects for you, but if
 * you should set Cache-Control headers appropriate for your needs.
 *
 * Example usage:
 *
 * include("php-closure.php");
 *
 * $c = new PhpClosure();
 * $c->add("my-app.js")
 *   ->add("popup.js")
 *   ->advancedMode()
 *   ->useClosureLibrary()
 *   ->cacheDir("/tmp/js-cache/")
 *   ->write();
 * 
 * See http://code.google.com/closure/compiler/docs/api-ref.html for more
 * details on the compiler options.
 */
 
class PhpClosure {

  var $_srcs = array();
  var $_mode = "WHITESPACE_ONLY";
  var $_warning_level = "DEFAULT";
  var $_use_closure_library = false;
  var $_pretty_print = false;
  var $_debug = true;
  var $_encrypt_strings = true;
  var $_cache_dir = "";
  var $_code_url_prefix = "";
  var $_externs = "QUnit.externs.js";
  var $_copyright = <<<EOT
// Copyright 2010 Daniel Pupius (http://code.google.com/p/php-closure/)
// Copyright 2015 dArtagnans Code (http://code.playnexus.com/)
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
EOT;

  function PhpClosure() { }

  /**
   * Outputs all source files as seperate script file loads without compiling
   * code when specified sha1 encoded password is passed in as the cookie 'GOD'
   * or when it === true.
   * Use for secure debugging.
   */
  function godMode($sha1Password) {
	if ($sha1Password === true || sha1($_COOKIE['GOD']) === $sha1Password ) {
		foreach ($this->_srcs as $file) {
			echo "\tdocument.write('<script src=\"" . $file . "?_=" . time() . "\" type=\"text/javascript\"></script>')\n";
		}
		exit(0);
	}
    return $this;
  }

  /**
   * Adds a source file to the list of files to compile.  Files will be
   * concatenated in the order they are added.
   */
  function add($file) {
    $this->_srcs[] = $file;
    return $this;
  }

  /**
   * Sets the directory where the compilation results should be cached, if
   * not set then caching will be disabled and the compiler will be invoked
   * for every request (NOTE: this will hit ratelimits pretty fast!)
   */
  function cacheDir($dir) {
    $this->_cache_dir = $dir;
    return $this;
  }

  /**
   * Sets whether to use the Closure Library.  i.e. goog.requires will be
   * resolved and the library code made available.
   */
  function useClosureLibrary() {
    $this->_use_closure_library = true;
    return $this;
  }

  /**
   * Sets the URL prefix to use with the Closure Compiler service's code_url
   * parameter.
   *
   * By default PHP-Closure posts the scripts to the compiler service, however,
   * this is subject to a 200000-byte size limit for the whole post request.
   *
   * Using code_url tells the compiler service the URLs of the scripts to
   * fetch.  The file paths added in add() must therefore be relative to this
   * URL.
   *
   * Example usage:
   *
   * $c->add("js/my-app.js")
   *   ->add("js/popup.js")
   *   ->useCodeUrl('http://www.example.com/app/')
   *   ->cacheDir("/tmp/js-cache/")
   *   ->write();
   *
   * This assumes your PHP script is in a directory /app/ and that the JS is in
   * /app/js/ and accessible via HTTP.
   */
  function useCodeUrl($code_url_prefix) {
    $this->_code_url_prefix = $code_url_prefix;
    return $this;
  }

  /**
   * Tells the compiler to pretty print the output.
   */
  function prettyPrint() {
    $this->_pretty_print = true;
    return $this;
  }

  /**
   * Turns of the debug info.
   * By default statistics, errors and warnings are logged to the console.
   */
  function hideDebugInfo() {
    $this->_debug = false;
    return $this;
  }

  /**
   * Sets the compilation mode to optimize whitespace only.
   */
  function whitespaceOnly() {
    $this->_mode = "WHITESPACE_ONLY";
    return $this;
  }

  /**
   * Sets the compilation mode to simple optimizations.
   */
  function simpleMode() {
    $this->_mode = "SIMPLE_OPTIMIZATIONS";
    return $this;
  }
  
  /**
   * Sets the compilation mode to advanced optimizations (recommended).
   */
  function advancedMode() {
    $this->_mode = "ADVANCED_OPTIMIZATIONS";
    return $this;
  }

  /**
   * Gets the compilation mode from the URL, set the mode param to
   * 'w', 's' or 'a'.
   */
  function getModeFromUrl() {
    if ($_GET['mode'] == 's') $this->simpleMode();
    else if ($_GET['mode'] == 'a') $this->advancedMode();
    else $this->whitespaceOnly();
    return $this;
  }

  /**
   * Sets the warning level to QUIET.
   */
  function quiet() {
    $this->_warning_level = "QUIET";
    return $this;
  }

  /**
   * Sets the default warning level.
   */
  function defaultWarnings() {
    $this->_warning_level = "DEFAULT";
    return $this;
  }

  /**
   * Sets the warning level to VERBOSE.
   */
  function verbose() {
    $this->_warning_level = "VERBOSE";
    return $this;
  }

  /**
   * Writes the compiled response.  Reading from either the cache, or
   * invoking a recompile, if necessary.
   */
  function write() {
    header("Content-Type: text/javascript");

    // No cache directory so just dump the output.
    if ($this->_cache_dir == "") {
      	$this->_jqWrap($this->_compile());
    } else {
      $cache_file = $this->_getCacheFileName();
      if ($this->_isRecompileNeeded($cache_file)) {
        $result = $this->_compile();
        file_put_contents($cache_file, $result);
      	$this->_jqWrap($result);
      } else {
        // No recompile needed, but see if we can send a 304 to the browser.
        $cache_mtime = filemtime($cache_file);
        $etag = md5_file($cache_file);
        header("Last-Modified: ".gmdate("D, d M Y H:i:s", $cache_mtime)." GMT"); 
        header("Etag: $etag"); 
        if (@strtotime(@$_SERVER['HTTP_IF_MODIFIED_SINCE']) == $cache_mtime || 
            @trim(@$_SERVER['HTTP_IF_NONE_MATCH']) == $etag) { 
          header("HTTP/1.1 304 Not Modified"); 
        } else {
          	// Read the cache file and send it to the client.
          	$this->_jqWrap(file_get_contents($cache_file));
        }
      }
    }
  }

	function _jqWrap($txt) {
		if (!empty($this->_copyright)) {
			echo "//\n";
			echo trim($this->_copyright) . "\n";
			echo "//\n";
		}
		echo "(function($,undefined) {\n";
		echo trim($txt);
		echo "\n})(jQuery);\n";
	}

  // ----- Privates -----
  
  function _isRecompileNeeded($cache_file) {
    // If there is no cache file, we obviously need to recompile.
    if ($_GET['cache'] === 'false' || !file_exists($cache_file)) return true;

    $cache_mtime = filemtime($cache_file);

    // If the source files are newer than the cache file, recompile.
    foreach ($this->_srcs as $src) {
      if (filemtime($src) > $cache_mtime) return true;
    }

    // If this script calling the compiler is newer than the cache file,
    // recompile.  Note, this might not be accurate if the file doing the
    // compilation is loaded via an include().
    if (filemtime($_SERVER["SCRIPT_FILENAME"]) > $cache_mtime) return true;

    // Cache is up to date.
    return false;
  }

  function _compile() {
    // Quieten strict notices.
    $code = $originalSize = $originalGzipSize = $compressedSize = $compressedGzipSize = $compileTime = '';

    $tree = $this->_parseXml($this->_makeRequest());

    $result = $tree;
    foreach ($result as $node) {
      switch ($node["tag"]) {
        case "compiledCode": $code = $node["value"]; break;
        case "warnings": $warnings = $node["value"]; break;
        case "errors": $errors = $node["value"]; break;
        case "statistics":
          foreach ($node["value"] as $stat) {
            switch ($stat["tag"]) {
              case "originalSize": $originalSize = $stat["value"]; break;
              case "originalGzipSize": $originalGzipSize = $stat["value"]; break;
              case "compressedSize": $compressedSize = $stat["value"]; break;
              case "compressedGzipSize": $compressedGzipSize = $stat["value"]; break;
              case "compileTime": $compileTime = $stat["value"]; break;
            }
          }
          break;
      }
    }
    
    $result = "";
    if ($this->_debug) {
      $result = "if(window.console&&window.console.log){\r\n" .
                "window.console.log('Closure Compiler Stats:\\n" .
                "-----------------------\\n" .
                "Original Size: $originalSize\\n" .
                "Original Gzip Size: $originalGzipSize\\n" .
                "Compressed Size: $compressedSize\\n" .
                "Compressed Gzip Size: $compressedGzipSize\\n" .
                "Compile Time: $compileTime\\n" .
                "Generated: " . Date("Y/m/d H:i:s T") . "');\r\n";
      if (isset($errors)) $result .= $this->_printWarnings($errors, "error");
      if (isset($warnings)) $result .= $this->_printWarnings($warnings, "warn");
      $result .= "}\r\n\r\n";
    }
    $result .= "$code \r\n";

    return $result;
  }
    
  function _printWarnings($warnings, $level="log") {
    $result = "";
    foreach ($warnings as $warning) {
      $desc = addslashes($warning["value"]);
      $type = $warning["attributes"]["type"];
      $lineno = $warning["attributes"]["lineno"];
      $charno = $warning["attributes"]["charno"];
      $line = addslashes($warning["attributes"]["line"]);
      $result .= "window.console.$level('$type: $desc\\nLine: $lineno\\nChar: $charno\\nLine: $line');\r\n";
    }
    return $result;
  }

  function _getCacheFileName() {
    return $this->_cache_dir . $this->_getHash() . ".js";
  }

  function _getHash() {
    return md5(implode(",", $this->_srcs) . "-" .
        $this->_mode . "-" .
        $this->_warning_level . "-" .
        $this->_use_closure_library . "-" .
        $this->_pretty_print . "-" .
        $this->_debug);
  }

  function _getParams() {
    $params = array();
    foreach ($this->_getParamList() as $key => $value) {
      $params[] = preg_replace("/_[0-9]$/", "", $key) . "=" . urlencode($value);
    }
    return implode("&", $params);
  }

  function _getParamList() {
    $params = array();
    if ($this->_code_url_prefix) {
      // Send the URL to each source file instead of the raw source.
      $i = 0;
      foreach($this->_srcs as $file){
        $params["code_url_$i"] = $this->_code_url_prefix . $file;
        $i++;
      }
    } else {
      $params["js_code"] = $this->_readSources();
      if (file_exists($this->_externs))
	      $params["js_externs"] = file_get_contents($this->_externs);
    }
    $params["compilation_level"] = $this->_mode;
    $params["output_format"] = "xml";
    $params["warning_level"] = $this->_warning_level;
    if ($this->_pretty_print) $params["formatting"] = "pretty_print";
    if ($this->_use_closure_library) $params["use_closure_library"] = "true";
    $params["output_info_1"] = "compiled_code";
    $params["output_info_2"] = "statistics";
    $params["output_info_3"] = "warnings";
    $params["output_info_4"] = "errors";
    return $params;
  }

  function _readSources() {
    $code = "";
    foreach ($this->_srcs as $src) {
      $code .= file_get_contents($src) . "\n\n";
    }
  	if ($_GET['scuba'] === 'true')
  		file_put_contents($this->_cache_dir . 'scuba.js', $code);
    return $code;
  }

  function _makeRequest() {
    $data = $this->_getParams();
    $referer = @$_SERVER["HTTP_REFERER"] or "";

    $fp = fsockopen("closure-compiler.appspot.com", 80) or die("Unable to open socket");;

    if ($fp) {
      fputs($fp, "POST /compile HTTP/1.1\r\n");
      fputs($fp, "Host: closure-compiler.appspot.com\r\n");
      fputs($fp, "Referer: $referer\r\n");
      fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
      fputs($fp, "Content-length: ". strlen($data) ."\r\n");
      fputs($fp, "Connection: close\r\n\r\n");
      fputs($fp, $data); 

      $result = ""; 
      while (!feof($fp)) {
        $result .= fgets($fp, 128);
      }

      fclose($fp);
    }

    $data = substr($result, (strpos($result, "\r\n\r\n")+4));
    if (strpos(strtolower($result), "transfer-encoding: chunked") !== FALSE) {
      $data = $this->_unchunk($data);
    }

    return $data;
  }

  function _unchunk($data) {
    $fp = 0;
    $outData = "";
    while ($fp < strlen($data)) {
      $rawnum = substr($data, $fp, strpos(substr($data, $fp), "\r\n") + 2);
      $num = hexdec(trim($rawnum));
      $fp += strlen($rawnum);
      $chunk = substr($data, $fp, $num);
      $outData .= $chunk;
      $fp += strlen($chunk);
    }
    return $outData;
  }

  function _parseXml($data) {
    $xml = new SimpleXMLElement($data);
    return $this->_parseXmlHelper($xml);
  }

  function _parseXmlHelper($xml) {
    $tree = null;
    foreach ($xml->children() as $name => $child) {
      $value = (string)$child;
      $node = array('tag' => $name, 'value' => count($child->children()) == 0 ? $value : $this->_parseXmlHelper($child));

      foreach ($child->attributes() as $attr => $value) {
        $node['attributes'][$attr] = $value[0];
      }
      $tree[] = $node;
    }
    return $tree;
  }
  
}

/** LZW compression
* @param string data to compress
* @return string binary data
*/
function old_lzw_compress($string) {
        // compression
        $dictionary = array_flip(range("\0", "\xFF"));
        $word = "";
        $codes = array();
        for ($i=0; $i <= strlen($string); $i++) {
                $x = substr($string, $i, 1);
                if (strlen($x) && isset($dictionary[$word . $x])) {
                        $word .= $x;
                } elseif ($i) {
                        $codes[] = $dictionary[$word];
                        $dictionary[$word . $x] = count($dictionary);
                        $word = $x;
                }
        }
        
        // convert codes to binary string
        $dictionary_count = 256;
        $bits = 8; // ceil(log($dictionary_count, 2))
        $return = "";
        $rest = 0;
        $rest_length = 0;
        foreach ($codes as $code) {
                $rest = ($rest << $bits) + $code;
                $rest_length += $bits;
                $dictionary_count++;
                if ($dictionary_count >> $bits) {
                        $bits++;
                }
                while ($rest_length > 7) {
                        $rest_length -= 8;
                        $return .= chr($rest >> $rest_length);
                        $rest &= (1 << $rest_length) - 1;
                }
        }
        return $return . ($rest_length ? chr($rest << (8 - $rest_length)) : "");
}
function lzw_compress($string) {
        // compression
        $dictionary = array_flip(range("\0", "\xFF"));
        $word = "";
        $codes = array();
        for ($i=0; $i <= strlen($string); $i++) {
                $x = substr($string, $i, 1);
                if (strlen($x) && isset($dictionary[$word . $x])) {
                        $word .= $x;
                } elseif ($i) {
                        $codes[] = $dictionary[$word];
                        $dictionary[$word . $x] = count($dictionary);
                        $word = $x;
                }
        }
       
        // convert codes to binary string
        $dictionary_count = 256;
        $bits = 8; // ceil(log($dictionary_count, 2))
        $return = "";
        $rest = 0;
        $rest_length = 0;
        foreach ($codes as $code) {
                $rest = ($rest << $bits) + $code;
                $rest_length += $bits;
                $dictionary_count++;
                if ($dictionary_count >> $bits) {
                        $bits++;
                }
                while ($rest_length > 7) {
                        $rest_length -= 8;
                        $return .= chr($rest >> $rest_length);
                        $rest &= (1 << $rest_length) - 1;
                }
        }
        return $return . ($rest_length ? chr($rest << (8 - $rest_length)) : "");
}

//
// Now my stuff
//

$c = new PhpClosure();

if (!file_exists(sys_get_temp_dir() . "/js-cache/"))
	@mkdir(sys_get_temp_dir() . "/js-cache/");

$c
	->add("../dAc-atom.js")
	->add("QUnit.zag.js")
	->add("QUnit.tests.js")
	->godMode(false)
	->advancedMode()
	->cacheDir(sys_get_temp_dir() . "/js-cache/")
	->write();
