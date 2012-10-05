<?php
/**
 * TentApp - A PHP-class that talks with Tent.io-servers.
 *
 * @package TentApp
 * @license http://opensource.org/licenses/MIT MIT
 * @author Johan K. Jensen
 **/

/**
 * This class requires the Requests class.
 */
if (!class_exists('Requests')) {
	require_once 'Requests/library/Requests.php';
	Requests::register_autoloader();
}

/**
 * TentApp
 *
 * @package TentApp
 * @author Johan K. Jensen
 **/
class TentApp
{
	
	public $entityURL;
	public $apiRootURLs;
	
	private $appID;
	private $mac_key_id;
	private $mac_key;
	private $mac_algorithm;
	
	static $HEADERS = array(
				'Accept' => 'application/vnd.tent.v0+json',
	);
	
	function __construct($entityURL)
	{
		$this->entityURL = $entityURL;
		
		$headers = self::$HEADERS;
		$headers['Host'] = parse_url($entityURL, PHP_URL_HOST);
		
		// $options = array(
		// 	'auth' => new TentApp_Auth('mac_key_id', 'mac_key')
		// );
		// $response = Requests::head($this->entityURL, array(), $options);
		
		$this->_discoverAPIRootURLs($this->entityURL);
		
	}
	/**
	 * Performs discovery on the given Tent identifier to find the Tent API root.
	 *
	 * @return void
	 **/
	private function _discoverAPIRootURLs($entityURL)
	{
		$response = $this->_genericRequestHEAD($entityURL);
		// IF the Link-headers exists, use it.
		if (isset($response->headers['Link']) && !empty($response->headers['Link'])) {
			
			preg_match_all('/<(?P<profile>.*)>; rel="(?P<rel>.*)"/i', $response->headers['Link'], $matches);
			// TODO: ?? Check if rel is a Tent.io-specification ?? Perhaps in the above regex.
			$profileURL = $matches['profile'];
			
			// TODO: Implement support for relative URLs.
			
			for ($i = 0, $l = count($profileURL); $i < $l; $i++) {
				$response = $this->_genericRequestGET($profileURL[$i]);
				break; // TODO: Implement fallback if the first one fails / is down.
			}
			$profile = json_decode($response->body, true); // Return json as an array instead of an object.
			
			$this->entityURL = $profile['https://tent.io/types/info/core/v0.1.0']['entity'];
			$this->apiRootURLs = $profile['https://tent.io/types/info/core/v0.1.0']['servers'];
			
		} else {
			// ELSE scrape the HTML (new request).
			// TODO: Implement HTML-scraping of the <link>-tag.
		    throw new Exception("Didn't find a Link-headers. Scraping of HTML ins't supported yet.");
		}
		
	}
	
	public function authenticate($keys)
	{
		if (isset($keys['mac_key_id']) && isset($keys['mac_key'])) {
			$this->mac_key_id = $keys['mac_key_id'];
			$this->mac_key = $keys['mac_key'];
			$this->mac_algorithm = (isset($keys['mac_algorithm']) ? $keys['mac_algorithm'] : 'sha256');
		} else {
			throw new Exception("Can't authenticate. Missing mac_key_id and mac_key");
		}
	}
	
	/**
	 * Checks if mac_key_id and mac_key is set.
	 *
	 * @return bool
	 * @author Johan K. Jensen
	 **/
	public function isAuthenticated()
	{
		return (isset($this->mac_key_id) && isset($this->mac_key));
	}
	
	private function _prepareHeaders($headers)
	{
		$headers = array_merge(self::$HEADERS, $headers); // Append custom headers to the default headers (overwriting default).
		$headers = array_filter($headers); // Remove empty key->values.
		return $headers;
	}
	
	private function _genericRequestGET($url, $headers=array(), $options=array()) {
		$headers = array_merge(array('Host' => parse_url($url, PHP_URL_HOST), $headers)); // Append Host if not already defined.
		$headers = self::_prepareHeaders($headers);
		$response = Requests::get($url, $headers, $options);
		return $response;
	}
	
	private function _genericRequestHEAD($url, $headers=array(), $options = array()) {
		$headers = array_merge(array('Host' => parse_url($url, PHP_URL_HOST), $headers)); // Append Host if not already defined
		$headers = self::_prepareHeaders($headers);
		$response = Requests::head($url, $headers, $options);
		return $response;
	}
	
	private function _genericGET($resource, $data=array())
	{
		$requestURL = $this->apiRootURLs[0].$resource;
		$headers = self::_prepareHeaders(array());
		$options = array();
		if ($this->isAuthenticated()) {
			$options['auth'] = new TentApp_Auth($this->mac_key_id, $this->mac_key, $this->mac_algorithm);
		}
		$r = Requests::request($requestURL, $headers, $data, Requests::GET, $options);
		
		return $this->_postResponse($r);
	}
	
	private function _postResponse($r)
	{
		if ($r->success) {
			$json = json_decode($r->body, true);
			$json_status = json_last_error();
			if ($json_status == JSON_ERROR_NONE) {
				return json_decode($r->body, true);
			} else {
				throw new Exception("JSON Error: ".$json_status);
			}
		} else {
			var_dump($r);
			throw new Exception("Request failed with status code ".$r->status_code);
		}
	}
	
	public function getPosts($id=null)
	{
		if ($id == null) {
			return self::_genericGET('/posts');
		} else {
			return self::_genericGET('/posts/'.$id);
		}
	}
	
	public function getFollowings($id=null)
	{
		if ($id == null) {
			return self::_genericGET('/followings');
		} else {
			return self::_genericGET('/followings/'.$id);
		}
	}
}

/**
 * A Requests_Auth-class that implements the MAC Access Authentication protocol.
 *
 * @package TentApp
 * @subpackage Authentication
 * @author Johan K. Jensen
 **/
class TentApp_Auth implements Requests_Auth {
	protected $mac_key_id;
	protected $mac_key;
	protected $mac_algorithm;

	public function __construct($mac_key_id, $mac_key, $mac_algorithm) {
		$this->mac_key_id = $mac_key_id;
		$this->mac_key = $mac_key;
		$this->mac_algorithm = $mac_algorithm;
	}

	public function register(Requests_Hooks &$hooks) {
		$hooks->register('requests.before_request', array(&$this, 'before_request'));
	}

	public function before_request(&$url, &$headers, &$data, &$type, &$options) {
		// MAC Access Authentication protocol per 
		// http://tools.ietf.org/html/draft-ietf-oauth-v2-http-mac-01
		
		$time = time();
		$nonce = bin2hex($this->secure_rand(5));
		$request_string = $this->build_request_string($time, $nonce, $type, $url);
		$signature = base64_encode(hash_hmac($this->mac_algorithm, $request_string, $this->mac_key, true));

		$headers['Authorization'] = $this->build_auth_header($time, $nonce, $signature);
		
	}
	
	public function build_request_string($time, $nonce, $type, $url)
	{
		$parsed_url = parse_url($url);
		$request_string = array(
			$time,
			$nonce,
			strtoupper($type),
			$parsed_url['path'].(isset($parsed_url['query']) && $parsed_url['query'] != '' ? '?'.$parsed_url['query'] : ''),
			$parsed_url['host'],
			(isset($parsed_url['port']) && $parsed_url['port'] != '' ? $parsed_url['port'] : ($parsed_url['scheme'] == 'https' ? 443 : 80)),
			'', '' // Extra empty (per the draft) because ext is empty
		);
		return implode($request_string, "\n");
	}
	
	public function build_auth_header($time, $nonce, $signature)
	{
        return 'MAC id="'.$this->mac_key_id.'", ts="'.$time.'", nonce="'.$nonce.'", mac="'.$signature.'")';
	}
	
	/**
	 * Function to generate a secure random int without accessing /dev/urandom
	 * Created by Enrico Zimuel (http://www.zimuel.it/en/strong-cryptography-in-php/)
	 */
	public function secure_rand($length) {
		if(function_exists('openssl_random_pseudo_bytes')) { // Requires PHP 5.3
			$rnd = openssl_random_pseudo_bytes($length, $strong);
			if ($strong === TRUE)
				return $rnd;
		}
		$sha =''; $rnd ='';
		for ($i = 0; $i < $size; $i++) {
			$sha = hash('sha256', $sha.mt_rand());
			$char = mt_rand(0,62);
			$rnd .= chr(hexdec($sha[$char].$sha[$char+1]));
		}
		return $rnd;
	}
	
}
