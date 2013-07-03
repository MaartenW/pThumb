<?php
/**
 * pThumb
 *
 * Copyright 2009-2012 by Shaun McCormick <shaun@modx.com>
 * Further modifications Copyright 2013 Jason Grant
 *
 * Please see the GitHub page for documentation or to report bugs:
 * https://github.com/oo12/phpThumbOf
 *
 * phpThumbOf is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option) any
 * later version.
 *
 * phpThumbOf is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * phpThumbOf; if not, write to the Free Software Foundation, Inc., 59 Temple
 * Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @package phpthumbof
 */
/**
 * @package phpThumbOf
 */
class phpThumbOf {
	/** @var modX $modx */
	public $modx;
	/** @var modAws $aws */
	public $aws;
	/** @var modPhpThumb $phpThumb */
	public $phpThumb;
	/** @var array $config */
	public $config = array();
	/** @var int $debugTimeStart */
	public $debugTimeStart = 0;
	/** @var string $oldLogTarget */
	public $oldLogTarget = 'FILE';
	/** @var int $oldLogLevel */
	public $oldLogLevel = 1;

	function __construct(modX $modx,array $config = array()) {
		$this->modx =& $modx;
		$corePath = $this->modx->getOption('phpthumbof.core_path',$this->config,$this->modx->getOption('core_path').'components/phpthumbof/');
		$assetsPath = $this->modx->getOption('phpthumbof.assets_path',$this->config,$this->modx->getOption('assets_path').'components/phpthumbof/');
		$assetsUrl = $this->modx->getOption('phpthumbof.assets_url',$this->config,$this->modx->getOption('assets_url').'components/phpthumbof/');

		$this->config = array_merge(array(
			'debug' => false,
			'options' => false,

			'corePath' => $corePath,
			'modelPath' => $corePath.'model/',
			'assetsPath' => $assetsPath,
			'assetsUrl' => $assetsUrl,

			'cachePath' => $modx->context->getOption('phpthumbof.cache_path','',$this->config),
			'cachePathUrl' => $modx->context->getOption('phpthumbof.cache_url',$assetsUrl.'cache/',$this->config),
			'checkRemotelyIfNotFound' => $modx->context->getOption('phpthumbof.check_remotely_if_not_found',false,$this->config),
		),$config);
		if (empty($this->config['cachePathUrl'])) $this->config['cachePathUrl'] = $assetsUrl.'cache/';
	}

	/**
	 * Get the parsed cachePath
	 * @return mixed
	 */
	public function getCacheDirectory() {
		if (empty($this->config['cachePath'])) {
			$this->config['cachePath'] = $this->config['assetsPath'].'cache/';
		} else {
			$this->config['cachePath'] = str_replace(array(
				'[[+core_path]]',
				'[[+assets_path]]',
				'[[+base_path]]',
				'[[+manager_path]]',
			),array(
				$this->modx->getOption('core_path',null,MODX_CORE_PATH),
				$this->modx->getOption('assets_path',null,MODX_ASSETS_PATH),
				$this->modx->getOption('base_path',null,MODX_BASE_PATH),
				$this->modx->getOption('manager_path',null,MODX_MANAGER_PATH),
			),$this->config['cachePath']);
		}
		return $this->config['cachePath'];
	}

	/**
	 * Check to make sure cache path is writable
	 * @return boolean
	 */
	public function ensureCacheDirectoryIsWritable() {
		$writable = true;
		if (!is_writable($this->config['cachePath'])) {
			if (!$this->modx->cacheManager->writeTree($this->config['cachePath'])) {
				$this->modx->log(modX::LOG_LEVEL_ERROR,'[phpThumbOf] Cache path not writable: '.$this->config['cachePath']);
				$writable = false;
			}
		}
		return $writable;
	}

	/**
	 * Create a Thumbnail object for this source
	 *
	 * @return ptThumbnail
	 */
	public function createThumbnailObject() {
		return new ptThumbnail($this,$this->config);
	}

	/**
	 * Start the debug trail
	 */
	public function startDebug() {
		if ($this->modx->getOption('debug',$this->config,false)) {
			$mtime = microtime();
			$mtime = explode(" ", $mtime);
			$mtime = $mtime[1] + $mtime[0];
			$this->debugTimeStart = $mtime;
			set_time_limit(0);

			$this->oldLogTarget = $this->modx->getLogTarget();
			$this->oldLogLevel = $this->modx->getLogLevel();
			$this->modx->setLogLevel(modX::LOG_LEVEL_DEBUG);
			$logTarget = $this->modx->getOption('debugTarget',$this->config,'');
			if (!empty($logTarget)) {
				$this->modx->setLogTarget();
			}
		}
	}

	/**
	 * End the debug trail
	 */
	public function endDebug() {
		if ($this->modx->getOption('debug',$this->config,false)) {
			$mtime= microtime();
			$mtime= explode(" ", $mtime);
			$mtime= $mtime[1] + $mtime[0];
			$tend= $mtime;
			$totalTime= ($tend - $this->debugTimeStart);
			$totalTime= sprintf("%2.4f s", $totalTime);

			$this->modx->log(modX::LOG_LEVEL_DEBUG,"\n<br />Execution time: {$totalTime}\n<br />");
			$this->modx->setLogLevel($this->oldLogLevel);
			$this->modx->setLogTarget($this->oldLogTarget);
		}
	}
}

/**
 * Thumbnail class for generating a thumbnail from an input source
 *
 * @package phpthumbof
 */
class ptThumbnail {
	/** @var modX $modx */
	public $modx;
	/** @var phpThumbOf $phpThumbOf */
	public $phpThumbOf;
	/** @var modPhpThumb $phpThumb */
	public $phpThumb;
	/** @var modAws $aws */
	public $aws;
	/** @var array $config */
	public $config = array();

	/** @var string $input The file to make a thumbnail from */
	public $input = '';
	/** @var array $options */
	public $options = array();
	public $cacheKey = '';
	public $cacheUrl = '';
	public $cacheFilename = '';
	public $expired = false;

	function __construct(phpThumbOf $phpThumbOf,array $config = array()) {
		$this->phpThumbOf =& $phpThumbOf;
		$this->modx =& $phpThumbOf->modx;
		$this->config = array_merge(array(
			'cache' => true,

			'useS3' => $this->modx->context->getOption('phpthumbof.use_s3',false,$this->config),
			's3path' => $this->modx->context->getOption('phpthumbof.s3_path','phpthumbof/',$this->config),
			's3bucket' => $this->modx->context->getOption('phpthumbof.s3_bucket','',$this->config),
			's3hostAlias' => $this->modx->context->getOption('phpthumbof.s3_host_alias','',$this->config),
			's3headersCheck' => $this->modx->context->getOption('phpthumbof.s3_headers_check',false,$this->config),

		),$config);
		$this->config = array_merge(array(
			's3hostDefault' => $this->config['s3bucket'].'.s3.amazonaws.com/',
		),$this->config);

		$this->phpThumb = new modPhpThumb($this->modx);
		if (!empty($this->config['useS3'])) {
			$this->aws = $this->modx->getService('modaws','modAws',$this->config['modelPath'].'aws/',$this->config);
		}
	}

	/**
	 * Startup the phpThumb service. Must run setInput and setOptions first.
	 */
	public function initializeService() {
		$this->phpThumb->config = array_merge($this->phpThumb->config,$this->options);
		$this->phpThumb->initialize();
		$this->phpThumb->setParameter('config_cache_directory',$this->config['cachePath']);
		$this->phpThumb->setParameter('config_allow_src_above_phpthumb',true);
		$this->phpThumb->setParameter('allow_local_http_src',true);
		$this->phpThumb->setCacheDirectory();
		$this->phpThumb->set($this->input);
	}

	/**
	 * Set the input source
	 * @param string $input
	 * @return string
	 */
	public function setInput($input) {
		/* get absolute url of image */

		$basepath = $this->modx->context->getOption('base_path', MODX_BASE_PATH, $this->config);
		$this->phpThumb->setParameter('config_document_root', $basepath);

		if ( !preg_match('/^(?:https?:)?\/\/.+/i', $input) && !file_exists($input)) {  // if we've got a local image to work with
			if ($this->modx->context->getOption('phpthumbof.fix_dup_subdir', TRUE, $this->config)) {
				$topdir = substr($input, 0, strpos($input, '/', 1) + 1);
				$topdir_len = strlen($topdir);
				if ($topdir === substr($basepath, -$topdir_len)) {
					$input = substr($input, $topdir_len);
				}
			}
			$input = $basepath . ltrim($input, '/');
		}

		$this->input = $input;
		return $input;
	}

	/**
	 * Set the options for the thumbnail
	 * @param array|string $options
	 */
	public function setOptions($options) {
		/* explode tag options */
		if (!is_array($options)) {  // convert options string to array
			parse_str($options, $ptOptions);
		}
		else {  // otherwise use the original phpThumbOf code
			$ptOptions = array();
			$eoptions = $options;
			foreach ($eoptions as $opt) {
				$opt = explode('=',$opt);
				$key = str_replace('[]','',$opt[0]);
				if (!empty($key)) {
					/* allow arrays of options */
					if (isset($ptOptions[$key])) {
						if (is_string($ptOptions[$key])) {
							$ptOptions[$key] = array($ptOptions[$key]);
						}
						$ptOptions[$key][] = $opt[1];
					} else { /* otherwise pass in as string */
						$ptOptions[$key] = $opt[1];
					}
				}
			}
		}

		if (empty($ptOptions['f'])) {
			$ext = strtolower( pathinfo($this->input, PATHINFO_EXTENSION) );
			$ptOptions['f'] = ($ext === 'png' || $ext === 'gif') ? $ext : 'jpeg';
		}

		if (($ptOptions['f'] === 'jpeg' || $ptOptions['f'] === 'jpg') && empty($ptOptions['q'])) {  // use global jpeg quality if needed
			$ptOptions['q'] = $this->modx->context->getOption('phpthumbof.jpeg_quality', 75, $this->config);
		}

		$this->options = array_merge($this->options, $ptOptions);
	}

	/**
	 * Set up a cache filename that is unique to the tag parsed
	 * @return string
	 */
	public function getCacheFilename() {
		$modtime = $this->modx->context->getOption('phpthumbof.check_mod_time', false, $this->config) ? @filemtime($this->input) : '';
		/* either hash the filename */
		if ($this->modx->context->getOption('phpthumbof.hash_thumbnail_names', false, $this->config)) {
			$this->cacheFilename = md5($this->input . $modtime) . '.' . md5(serialize($this->options)) . '.' . $this->options['f'];
		} else { /* or attempt to preserve the filename */
			$this->cacheFilename = basename($this->input);
			if ($this->modx->context->getOption('phpthumbof.postfix_property_hash', true, $this->config)) {
				$this->cacheFilename = pathinfo($this->cacheFilename, PATHINFO_FILENAME);
				/* for PHP < 5.2 use:
				$cut = strrpos($this->cacheFilename, '.');
				if ($cut) { $this->cacheFilename = substr($this->cacheFilename, 0, $cut); } */
				$this->cacheFilename .= '.' . md5( serialize($this->options) . pathinfo($this->input, PATHINFO_DIRNAME) . $modtime) .
					'.' . ($this->options['f'] === 'jpeg' ? 'jpg' : $this->options['f']);
			}
		}
		$this->cacheKey = $this->config['cachePath'] . $this->cacheFilename;
		return $this->cacheKey;
	}

	/**
	 * Get the cache file URL
	 * @return string
	 */
	public function getCacheUrl() {
		$this->cacheUrl = $this->config['cachePathUrl'].str_replace($this->config['cachePath'],'',$this->cacheKey);
		$this->cacheUrl = $this->stripDoubleSlashes($this->cacheUrl);
		return $this->cacheUrl;
	}

	/**
	 * Properly strip double slashes in a string, protecting :// prefixes (such as http://)
	 * @param string $string
	 * @return string
	 */
	protected function stripDoubleSlashes($string) {
		$string = str_replace('//','/',$string);
		if (strpos($string,':/') !== false) {
			$string = str_replace(array(
				':/'
			),array(
				'://',
			),$string);
		}
		return $string;
	}

	/**
	 * Render the thumbnail
	 * @return mixed|string
	 */
	public function render() {
		$this->getCacheFilename();
		$this->getCacheUrl();
		$this->phpThumbOf->startDebug();

		/* if using s3, check for file there */
		$useS3 = $this->modx->getOption('useS3',$this->config,false);
		if ($useS3) {
			$expired = $this->checkForS3Cache();
			if ($expired !== true) {
				return $expired;
			}
		}

		$this->checkCacheFilePermissions();
		$this->phpThumbOf->endDebug();

		if ($cacheUrl = $this->checkForCachedFile()) {
			return $cacheUrl;
		}

		/* actually make the thumbnail */
		if ($this->phpThumb->GenerateThumbnail()) { // this line is VERY important, do not remove it!
			if ($this->phpThumb->RenderToFile($this->cacheKey)) {
				$this->checkCacheFilePermissions();
				if ($useS3) {
					$this->cacheUrl = $this->pushToS3();
				}
				return str_replace(' ','%20',$this->cacheUrl);
			} else {
				$this->modx->log(modX::LOG_LEVEL_ERROR, '[phpThumbOf] Resource ' . $this->modx->resource->get('id') . ' :: Could not cache thumb "' . $this->input . '" to file at: ' . $this->cacheKey . ' - Debug: ' . print_r($this->phpThumb->debugmessages, true));
			}
		} else {
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[phpThumbOf] Resource ' . $this->modx->resource->get('id') . ' :: Could not generate thumbnail: ' . $this->input . ' - Debug: ' . print_r($this->phpThumb->debugmessages, true));
		}
		return '';
	}

	/**
	 * See if the file is cached on S3.
	 * @return mixed
	 */
	public function checkForS3Cache() {
		/* if using a CNAME alias, set here (ensure is postfixed with /) */
		$s3hostAliasLen = strlen($this->config['s3hostAlias']);
		if (!empty($this->config['s3hostAlias'])) {
			$this->config['s3hostAlias'] = str_replace(array('http://','https://'),'',$this->config['s3hostAlias']);
			if (substr($this->config['s3hostAlias'],$s3hostAliasLen-1,$s3hostAliasLen) != '/') {
				$this->config['s3hostAlias'] .= '/';
			}
		}
		$s3host = !empty($this->config['s3hostAlias']) ? $this->config['s3hostAlias'] : $this->config['s3hostDefault'];

		/* calc relative path of image in s3 bucket */
		$path = str_replace('//','/',$this->config['s3path'].$this->cacheFilename);
		$this->expired = true;
		$lastModified = 0;
		$s3imageUrl = '';

		/* check with php's get_headers (slower) */
		if ($this->config['s3headersCheck']) {
			$this->modx->log(modX::LOG_LEVEL_DEBUG,'[phpthumbof] Using get_headers to check modified.');
			$s3imageUrl = 'http://'.str_replace('//','/',$s3host.urlencode($path));
			$headers = get_headers($s3imageUrl,1);

			if (!empty($headers) && !empty($headers[0]) && $headers[0] == 'HTTP/1.1 200 OK') {
				if (empty($headers['Last-Modified'])) {
					$this->expired = true;
				} else {
					$this->expired = false;
					$lastModified = $headers['Last-Modified'];
					$lastModified = strtotime(trim($lastModified[1]));
				}
			} else {
				$this->expired = true;
			}

		} else { /* otherwise use amazon's (faster) get object info */
			$this->modx->log(modX::LOG_LEVEL_DEBUG,'[phpthumbof] Using get_object_url to check modified.');
			$s3response = $this->aws->getFileUrl($path);
			if (!empty($s3response) && is_object($s3response) && !empty($s3response->body) && !empty($s3response->status) && $s3response->status == 200) {
				/* check expiry for image */
				$this->expired = false;
				$lastModified = strtotime($s3response->header['last-modified']);
				$s3imageUrl = $s3response->header['_info']['url'];

				if (!empty($this->config['s3hostAlias'])) {
					$s3imageUrl = str_replace($this->config['s3hostDefault'],$this->config['s3hostAlias'],$s3imageUrl);
				}
			}
		}

		/* check to see if expired */
		if (!empty($lastModified)) {
			/* use last-modified to determine age */
			$maxAge = (int)$this->modx->getOption('phpthumbof.s3_cache_time',null,24) * 60 * 60;
			$now = time();
			if (($now - $lastModified) > $maxAge) {
				$this->expired = true;
			}
		}
		/* if not expired past the cache time, use that url. otherwise, delete from S3 */
		if (!$this->expired) {
			$this->phpThumbOf->endDebug();
			return $s3imageUrl;
		}
		$this->aws->deleteObject($path);
		return true;
	}

	/**
	 * Push the cached file to S3
	 *
	 * @return string The URL returned by S3
	 */
	public function pushToS3() {
		$response = $this->aws->upload($this->cacheKey,$this->config['s3path']);
		if (!empty($response)) {
			if (!empty($this->config['s3hostAlias'])) {
				$this->cacheUrl = str_replace($this->config['s3hostDefault'],$this->config['s3hostAlias'],$response);
			} else {
				$this->cacheUrl = $response;
			}
			@unlink($this->cacheKey);
		}
		return $this->cacheUrl;
	}

	/**
	 * Ensure the cache file permissions are correct
	 */
	public function checkCacheFilePermissions() {
		if (!empty($this->cacheKey)) {
			$filePerm = (int)$this->modx->context->getOption('new_file_permissions','0664',$this->config);
			$permissions = @fileperms($this->cacheKey);
			if ($permissions != $filePerm) {
				@chmod($this->cacheKey, octdec($filePerm));
			}
		}
	}

	/**
	 * Check to see if there's a cached file of this thumbnail already
	 *
	 * @return boolean|string
	 */
	public function checkForCachedFile() {
		if (file_exists($this->cacheKey) && !$this->modx->getOption('useS3',$this->config,false) && !$this->expired && $this->modx->getOption('cache',$this->config,true)) {
			$this->modx->log(modX::LOG_LEVEL_DEBUG,'[phpThumbOf] Using cached file found for thumb: '.$this->cacheKey);
			return str_replace(' ','%20',$this->cacheUrl);
		}
		return false;
	}

	/**
	 * Keep the cache directory nice and clean
	 */
	public function cleanCache() {
		$cache_maxage = $this->modx->getOption('phpthumb_cache_maxage', NULL, 30) * 86400;
		$cache_maxsize = $this->modx->getOption('phpthumb_cache_maxsize', NULL, 100) * 1048576;
		$cache_maxfiles = (int) $this->modx->getOption('phpthumb_cache_maxfiles', NULL, 10000);
		$this->modx->log(modX::LOG_LEVEL_INFO, 'phpThumbOfCacheManager: Cleaning phpThumbOf cache...');
		$this->modx->log(modX::LOG_LEVEL_INFO, ":: Max Age: $cache_maxage seconds || Max Size: $cache_maxsize bytes || Max Files: $cache_maxfiles");

		if (!($cache_maxage || $cache_maxsize || $cache_maxfiles)) {
			return;
		}

		$DeletedKeys = array();
		$AllFilesInCacheDirectory = array();
		$dirname = rtrim(realpath($this->config['cachePath']), '/\\');
		if ($dirhandle = @opendir($dirname)) {
			while (($file = readdir($dirhandle)) !== false) {
				$fullfilename = $dirname . DIRECTORY_SEPARATOR . $file;
				if (is_file($fullfilename) && preg_match('/(jpe?g|png|gif)$/', $file)) {
					$AllFilesInCacheDirectory[] = $fullfilename;
				}
			}
			closedir($dirhandle);
		}
		$totalimages = count($AllFilesInCacheDirectory);
		$this->modx->log(modX::LOG_LEVEL_INFO, ":: $totalimages images in the phpThumbOf cache");

		if (empty($AllFilesInCacheDirectory)) {
			return;
		}

		$CacheDirOldFilesAge  = array();
		$CacheDirOldFilesSize = array();
		foreach ($AllFilesInCacheDirectory as $fullfilename) {
			$CacheDirOldFilesAge[$fullfilename] = @fileatime($fullfilename);
			if ($CacheDirOldFilesAge[$fullfilename] == 0) {
				$CacheDirOldFilesAge[$fullfilename] = @filemtime($fullfilename);
			}
			$CacheDirOldFilesSize[$fullfilename] = @filesize($fullfilename);
		}
		$DeletedKeys['zerobyte'] = array();
		foreach ($CacheDirOldFilesSize as $fullfilename => $filesize) {
			// purge all zero-size files more than an hour old (to prevent trying to delete just-created and/or in-use files)
			$cutofftime = time() - 3600;
			if (($filesize == 0) && ($CacheDirOldFilesAge[$fullfilename] < $cutofftime)) {
				if (@unlink($fullfilename)) {
					$DeletedKeys['zerobyte'][] = $fullfilename;
					unset($CacheDirOldFilesSize[$fullfilename]);
					unset($CacheDirOldFilesAge[$fullfilename]);
				}
			}
		}
		$this->modx->log(modX::LOG_LEVEL_INFO, ':: Purged ' . count($DeletedKeys['zerobyte']) . ' zero-byte images');
		asort($CacheDirOldFilesAge);

		if ($cache_maxfiles > 0) {
			$TotalCachedFiles = count($CacheDirOldFilesAge);
			$DeletedKeys['maxfiles'] = array();
			foreach ($CacheDirOldFilesAge as $fullfilename => $filedate) {
				if ($TotalCachedFiles > $cache_maxfiles) {
					if (@unlink($fullfilename)) {
						--$TotalCachedFiles;
						$DeletedKeys['maxfiles'][] = $fullfilename;
					}
				} else {
					// there are few enough files to keep the rest
					break;
				}
			}
			$this->modx->log(modX::LOG_LEVEL_INFO, ':: Purged ' . count($DeletedKeys['maxfiles']) . " images based on (cache_maxfiles=$cache_maxfiles)");
			foreach ($DeletedKeys['maxfiles'] as $fullfilename) {
				unset($CacheDirOldFilesAge[$fullfilename]);
				unset($CacheDirOldFilesSize[$fullfilename]);
			}
		}

		if ($cache_maxage > 0) {
			$mindate = time() - $cache_maxage;
			$DeletedKeys['maxage'] = array();
			foreach ($CacheDirOldFilesAge as $fullfilename => $filedate) {
				if ($filedate) {
					if ($filedate < $mindate) {
						if (@unlink($fullfilename)) {
							$DeletedKeys['maxage'][] = $fullfilename;
						}
					} else {
						// the rest of the files are new enough to keep
						break;
					}
				}
			}
			$this->modx->log(modX::LOG_LEVEL_INFO, ':: Purged ' . count($DeletedKeys['maxage']) . ' images based on (cache_maxage='. $cache_maxage / 86400 .' days)');
			foreach ($DeletedKeys['maxage'] as $fullfilename) {
				unset($CacheDirOldFilesAge[$fullfilename]);
				unset($CacheDirOldFilesSize[$fullfilename]);
			}
		}

		if ($cache_maxsize > 0) {
			$TotalCachedFileSize = array_sum($CacheDirOldFilesSize);
			$DeletedKeys['maxsize'] = array();
			foreach ($CacheDirOldFilesAge as $fullfilename => $filedate) {
				if ($TotalCachedFileSize > $cache_maxsize) {
					if (@unlink($fullfilename)) {
						$TotalCachedFileSize -= $CacheDirOldFilesSize[$fullfilename];
						$DeletedKeys['maxsize'][] = $fullfilename;
					}
				} else {
					// the total filesizes are small enough to keep the rest of the files
					break;
				}
			}
			$this->modx->log(modX::LOG_LEVEL_INFO, ':: Purged ' . count($DeletedKeys['maxsize']) . ' images based on (cache_maxsize=' . $cache_maxsize / 1048576 . ' MB)');
			foreach ($DeletedKeys['maxsize'] as $fullfilename) {
				unset($CacheDirOldFilesAge[$fullfilename]);
				unset($CacheDirOldFilesSize[$fullfilename]);
			}
		}

		$totalpurged = 0;
		foreach ($DeletedKeys as $key => $value) {
			$totalpurged += count($value);
		}
		$this->modx->log(modX::LOG_LEVEL_INFO, ":: Purged $totalpurged images out of $totalimages");
	}

}