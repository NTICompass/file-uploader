<?php
/**
 * How to use this PHP file:
 * Uncomment the following lines
 * OR: move these lines into a new file, and add require_once('file-uploader/server/php.php');
 * Enjoy :)
 
// list of valid extensions, ex. array("jpeg", "xml", "bmp")
$allowedExtensions = array();
// max file size in bytes
$sizeLimit = 10 * 1024 * 1024;

$uploader = qqFileUploader::getUploader($allowedExtensions, $sizeLimit);
echo $uploader->handleUpload('uploads/');

*/

/**
 * Factory for creating the uploader class
 */
class qqFileUploader{
	public static function getUploader($types=array(), $maxSize=FALSE){
		$uploader = strpos(strtolower($_SERVER['CONTENT_TYPE']), 'multipart/') === 0
			? 'qqUploadedFileForm' : 'qqUploadedFileXhr';
		return new $uploader($types, $maxSize);
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
		return $this->_retJSON();
	}
	final protected function error($msg){
		return $this->_retJSON($msg, TRUE);
	}
	final protected function _retJSON($msg=TRUE, $error=FALSE){
		return (json_encode(array(
			$error ? 'error' : 'success' => $msg
		)));
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
