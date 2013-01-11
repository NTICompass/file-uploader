<?php
/**
 * FineUploader PHP server-side script
 *
 * Just set the options, point the JavaScript at this file,
 * and watch the magic :-)
 *
 * For PHP 5.4's session progress, use:
 * ?action=progress, ?action=element, or ?action=cancel
 */

// That's it.  One line!  It's all magic! :-D
echo json_encode(qqFileUploader::getInstance()->go());


/**
 * Factory for creating the uploader class
 */
class qqFileUploader{
	// list of valid extensions, ex. array("jpeg", "xml", "bmp")
	private $allowedExtensions = array();
	// max file size in bytes
	private $sizeLimit = 10485760; //10 * 1024 * 1024
	// path to store uploaded files in
	private $uploadPath = 'upload/files/';
	// session name to use, NULL for defualt
	private $sessionName = NULL;

	// Singletons are cool :)
	private static $instance;

	private function __construct(){
		// Absolute paths FTW
		$this->uploadPath = $_SERVER['DOCUMENT_ROOT'].$this->uploadPath;
	}

	public static function getInstance(){
		if(is_null(self::$instance)){
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function getUploader(){
		$uploader = strpos(strtolower($_SERVER['CONTENT_TYPE']), 'multipart/') === 0
			? 'qqUploadedFileForm' : 'qqUploadedFileXhr';
		return new $uploader($this->allowedExtensions, $this->sizeLimit);
	}

	/**
	 * Return session upload progress
	 */
	private function sessionProgress(){
		$upload_progress = ini_get('session.upload_progress.enabled');
		$ret = array();

		if(!!$upload_progress){
			$key = ini_get('session.upload_progress.prefix') . 'qqUploader';
			$ret = $_SESSION[$key];
		}

		return $ret;
	}

	/**
	 * Cancel an upload via session
	 */
	private function sessionProgressCancel(){
		$upload_progress = ini_get('session.upload_progress.enabled');
		$ret = array();

		if(!!$upload_progress){
			$key = ini_get('session.upload_progress.prefix') . 'qqUploader';
			$_SESSION[$key]['cancel_upload'] = TRUE;
			$ret = array('success'=>true);
		}
		else{
			$ret = array('success'=>false);
		}

		return $ret;
	}

	/**
	 * Get data needed for session upload progress
	 */
	private function sessionProgressElement(){
		$upload_progress = ini_get('session.upload_progress.enabled');
		$ret = array();

		if(!!$upload_progress){
			$ret['upload_name'] = ini_get('session.upload_progress.name');
			$ret['upload_value'] = 'qqUploader';
			$ret['upload_freq'] = ini_get('session.upload_progress.min_freq');
		}

		return $ret;
	}


	/**
	 * Here we go!  This function figures out what to do and then does it.
	 */
	public function go(){
		if(is_null($this->sessionName)){
			session_name($this->sessionName);
		}
		session_start();
		$ret = NULL;
		// $_GET['action'] ?: ''
		switch($_GET['action'] ? $_GET['action'] : ''){
			case 'progress':
				$ret = $this->sessionProgress();
				break;
			case 'element':
				$ret = $this->sessionProgressElement();
				break;
			case 'cancel':
				$ret = $this->sessionProgressCancel();
				break;
			default:
				$up = $this->getUploader();
				$ret = $up->handleUpload($this->uploadPath);
				break;
		}
		return $ret;
	}
}

/**
 * All the upload types extend off of this.
 * They just need to implement save, getName, and getSize
 */
abstract class qqUploadedFile{
	private $allowedExtensions, $sizeLimit, $uploadName;

	// Override these
	abstract protected function save($path, $replace);
	abstract protected function getName();
	abstract protected function getSize();

	// Constructor to setup upload options
	final public function __construct($types=array(), $maxSize=FALSE){
		$this->allowedExtensions = array_map('strtolower', $types);
		$this->sizeLimit = $maxSize;

		// Parse php.ini settings
		$postSize = $this->toBytes(ini_get('post_max_size'));
		$uploadSize = $this->toBytes(ini_get('upload_max_filesize'));

		// Make sure PHP allows us to upload the right size
		if($postSize < $this->sizeLimit || $uploadSize < $this->sizeLimit){
			$size = max(1, $this->sizeLimit / 1024 / 1024) . 'M';  // at least 1mb
			return $this->error("increase post_max_size and upload_max_filesize to $size");
		}
	}

	// Upload our file.  Let the child class figure out how.
	final public function handleUpload($path, $replace=FALSE){
		$size = $this->getSize();
		$name = $this->getName();
		$ext = pathinfo($name, PATHINFO_EXTENSION);

		// Make sure $path ends with a /
		if(substr($path, -1) !== '/'){
			$path .= '/';
		}

		// Checks to make sure we can upload the file, and it's allowed
		if(!is_writable($path)){
			return $this->error("Server error. Upload directory isn't writable.");
		}
		elseif($this->sizeLimit && $size == 0){
			return $this->error('File is empty');
		}
		elseif($this->sizeLimit && $size > $this->sizeLimit){
			return $this->error('File is too large');
		}
		elseif($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)){
			$these = implode(', ', $this->allowedExtensions);
			return $this->error('File has an invalid extension, it should be one of '. $these . '.');
		}

		// Upload the file
		if(($this->uploadName = $this->save($path.$name, $replace)) !== FALSE){
			return $this->success();
		}
		else{
			return $this->error('Could not save uploaded file.  The upload was cancelled, or server error encountered');
		}
	}

	/**
	 * Rename file to a unique one
	 */
	final protected function renameUpload($path){
		if(is_file($path)){
			$pathinfo = pathinfo($path);

			// Don't add a '.' to the end of files with no extension
			$ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? ('.'.$pathinfo['extension']) : '';
			$name = isset($pathinfo['filename']) ? $pathinfo['filename'] :
				(strlen($ext) ? substr($pathinfo['basename'], 0, -(strlen($ext))) : $pathinfo['basename']);
			$folder = $pathinfo['dirname'];

			$counter = 1;

			do{
				$path = $folder.'/'.$name.'_'.($counter++).$ext;
			} while(is_file($path));
		}

		return $path;
	}

	/**
	 * The name the file was uploaded as (after being renamed)
	 */
	public function getUploadName(){
		return $this->uploadName;
	}

	/**
	 * Converts strings like "10M" to bytes (10485760)
	 *
	 * Used to "parse" php.ini settings
	 */
	final protected function toBytes($str){
		$val = trim($str);
		$last = strtolower($str[strlen($str)-1]);
		switch($last) {
			case 'g':
				$val *= 1024;
			case 'm':
				$val *= 1024;
			case 'k':
				$val *= 1024;
		}
		return $val;
	}

	/**
	 * Helper methods to return JSON
	 */
	final protected function success(){
		return $this->_retArray();
	}
	final protected function error($msg){
		return $this->_retArray($msg, TRUE);
	}
	final protected function _retArray($msg=TRUE, $error=FALSE){
		return array(
			$error ? 'error' : 'success' => $msg
		);
	}
}

/**
 * If your browser supports XMLHttpRequest 2 :-D
 */
class qqUploadedFileXhr extends qqUploadedFile{
	function save($path, $replace=FALSE){
		$input = fopen("php://input", 'r'); // Stream the file from the POST body
		$ram = fopen("php://temp/maxmemory:5242880", 'w+'); // 5Mb max, then use temp file
		$realSize = stream_copy_to_stream($input, $ram); // Copy to RAM
		fclose($input);

		// Make sure the file size matches
		if($realSize !== $this->getSize()){
			return false;
		}

		// Put the file in the right spot
		$path = !$replace ? $this->renameUpload($path) : $path;
		$target = fopen($path, 'w');
		rewind($ram);
		stream_copy_to_stream($ram, $target);
		fclose($target);
		fclose($ram);

		return $path;
	}

	function getName(){
		return $_GET['qqfile'];
	}

	function getSize(){
		if(isset($_SERVER['CONTENT_LENGTH'])){
			return (int)$_SERVER['CONTENT_LENGTH'];
		}
		else {
			throw new Exception('Getting content length is not supported.');
		}
	}
}

/**
 * If your browser only supports $_FILES uploading (hidden iframe method)
 */
class qqUploadedFileForm extends qqUploadedFile{
	function save($path, $replace=FALSE){
		$path = !$replace ? $this->renameUpload($path) : $path;
		return move_uploaded_file($_FILES['qqfile']['tmp_name'], $path) ? $path : FALSE;
	}

	function getName(){
		return $_FILES['qqfile']['name'];
	}

	function getSize(){
		return $_FILES['qqfile']['size'];
	}
}

?>
