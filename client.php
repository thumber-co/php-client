<?php

define('THUMBER_CLIENT_USER_AGENT', 'Thumber Client 1.0 (PHP ' . phpversion() . '; ' . php_uname() . ')');

define('THUMBER_SERVER_HOST', 'api.thumber.co');
define('THUMBER_SERVER_PATH', '/');

define('THUMBER_CLIENT_PATH', dirname(__FILE__) . '/');

ThumberClient::init();

/**
 * Hanlde processing POST callbacks (which will include the generated thumbnail or an error msg).
 */
if (isset($_GET['response'])) {
   ThumberClient::receiveResponse();
}

/**
 * Class to process sending requests and receiving responses.
 */
class ThumberClient
{
   /**
    * Initializes ThumberClient.
    */
   public static function init() {
      // these must be updated to real values before use
      self::$uid = 'user ID';
      self::$userSecret = 'user secret';
      self::$directoryUrl = 'directory URL'; // include trailing slash
      self::$callback = 'to be invoked when response arrives'; // takes single parameter of type ThumberResp
   }
   
   /**
    * @var string UID for the user accessing the Thumber API.
    */
   private static $uid;
   
   /**
    * @var string The user secret assoicataed with the UID for the user
    * accessing the Thumber API.
    */
   private static $userSecret;
   
   /**
    * @var string The URL pointing to this file's directory. Must be publically
    * accessible for Thumebr API to send generated thumbnail following request.
    */
   private static $directoryUrl;
   
   /**
    * @var callable The method to be invoked when response arrives.
    */
   private static $callback;
   
   /**
    * Sends the provided request to the API endpoint.
    * 
    * @param ThumberReq $req The request to be sent. UID, callback, and timestamp 
    * will be written by client. Additionally, nonce will be set if not already set.
    * @return Array containing data about success of the cURL request.
    */
   public static function sendRequest($req) {
      include_once THUMBER_CLIENT_PATH . 'request.php';
      
      if (!($req instanceof ThumberReq)) {
         die('Request must be of type ThumberReq.');
      }
      
      $req->setTimestamp(gmmktime());

      if (empty($req->getUid())) {
         $req->setUid(self::$uid);
      }
      if (empty($req->getCallback())) {
         $req->setCallback(self::$relativeUrl . 'client.php?response=1');
      }
      if (empty($req->getNonce())) {
         $req->setNonce();
      }
      
      $req->setChecksum($req->computeChecksum(self::$userSecret));
      
      if (!$req->isValid(self::$userSecret)) {
         die('Invalid request provided.');
      }

      $json = $req->toJson();
      
      // open connection
      $ch = curl_init();
      
      // build curl req
      curl_setopt($ch, CURLOPT_USERAGENT,      THUMBER_CLIENT_USER_AGENT);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_URL,            'http://' . THUMBER_SERVER_HOST . THUMBER_SERVER_PATH);
      curl_setopt($ch, CURLOPT_POSTFIELDS,     $json);
      curl_setopt($ch, CURLOPT_HTTPHEADER,     array(
                                                   'Content-Type: application/json',
                                                   'Content-Length: ' . strlen($json)));
         
      // execute post, storing useful information about result
      $response = curl_exec($ch);
      $error = curl_error($ch);
      $result = array (
            'header'          => '',
            'body'            => '',
            'curl_error'      => '',
            'http_code'       => '',
            'last_url'        => '',
            'nonce'           => ''
      );
      
      if ($error === '') {
         $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
         
         $result['header']    = substr($response, 0, $header_size);
         $result['body']      = substr($response, $header_size);
         $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $result['last_url']  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
         $result['nonce']     = $req->getNonce();
      } else {
         $result ['curl_error'] = $error;
      }
      
      curl_close($ch);
      
      // caller should handle failures sensibly
      return $result;
   }
   
   /**
    * Processes the POST request, generating a ThumberResponse, validating, and passing the result to $callback.
    */
   public static function receiveResponse() {
      include_once THUMBER_CLIENT_PATH . 'response.php';
      
      if (!isset(self::$callback)) {
         die(__CLASS__ . '::$callback must be initialized.');
      }
      
      $json = stream_get_contents(STDIN);
      $resp = ThumberResp::parseJson($json);
      
      if (is_null($resp)) {
         die('Failed to parse JSON in POST body: ' . $json);
      }
      
      if (!$resp->isValid(self::$userSecret)) {
         die('Received invalid response: ' . $json);
      }
      
      // response passed validation -- relay to callback function
      call_user_func(self::$callback, $resp);
   }
}