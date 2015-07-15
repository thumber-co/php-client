<?php

ThumberClient::init();

/**
 * Handle processing POST callbacks (which will include the generated thumbnail or an error msg).
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
    * The Thumber.co API subdomain.
    */
   const ThumberServerHost = 'api.thumber.co';

   /**
    * The path for creating a new thumbnail.
    */
   const ThumberServerCreatePath = '/create.json';

   /**
    * The path for retrieving the supported MIME types.
    */
   const ThumberServerMimeTypesPath = '/mime_types.json';

   /**
    * Initializes ThumberClient.
    */
   public static function init() {
      if (!isset(self::$thumberUserAgent)) {
         self::$thumberUserAgent = 'Thumber Client 1.0 (PHP ' . phpversion() . '; ' . php_uname() . ')';
         self::$thumberClientPath = dirname(__FILE__) . '/';
      }
   }

   /**
    * @var string UID for the user accessing the Thumber API.
    */
   private static $uid;

   /**
    * @param $uid string Sets the UID.
    */
   public static function setUid($uid) {
      self::$uid = $uid;
   }

   /**
    * @var string The user secret assoicataed with the UID for the user
    * accessing the Thumber API.
    */
   private static $userSecret;

   /**
    * @param $userSecret string Sets the user secret.
    */
   public static function setUserSecret($userSecret) {
      self::$userSecret = $userSecret;
   }

   /**
    * @var string The URL pointing to this file's directory. Must be publically
    * accessible for Thumebr API to send generated thumbnail following request.
    */
   private static $directoryUrl;

   /**
    * @param $directoryUrl string Sets the directory URL.
    */
   public static function setDirectoryUrl($directoryUrl) {
      self::$directoryUrl = $directoryUrl;
   }
   
   /**
    * @var callable The method to be invoked when response arrives. Callable should accept
    * one ThumberResp object.
    */
   private static $callback;

   /**
    * @param $callback callable Sets the callback.
    */
   public static function setCallback($callback) {
      self::$callback = $callback;
   }

   /**
    * @var callable Replace the cURL HTTP transfer with your own method.
    * Callable must use the same interface as self::sendToThumber.
    */
   private static $httpSendOverride;

   /**
    * @param $httpSendOverride callable Sets the HTTP send override.
    */
   public static function setHttpSendOverride($httpSendOverride) {
      self::$httpSendOverride = $httpSendOverride;
   }

   /**
    * @var callable If set, any errors will invoke this method passing a string describing the error.
    */
   private static $errorHandler;

   /**
    * @param $errorHandler callable Sets the error handler.
    */
   public static function setErrorHandler($errorHandler) {
      self::$errorHandler = $errorHandler;
   }

   /**
    * @var string The user agent to send HTTP requests as.
    */
   private static $thumberUserAgent;

   /**
    * @var string Fully-qualified system path to this file.
    */
   private static $thumberClientPath;
   
   /**
    * Sends the provided request to the API endpoint.
    * 
    * @param ThumberReq $req The request to be sent. UID, callback, and timestamp 
    * will be written by client. Additionally, nonce will be set if not already set.
    * @return array containing data about success of the cURL request.
    */
   public static function sendRequest($req) {
      include_once self::$thumberClientPath . 'request.php';
      
      if (!($req instanceof ThumberReq)) {
         self::handleError('Request must be of type ThumberReq.');
      }
      
      $req->setTimestamp(time());

      $uid = $req->getUid();
      if (empty($uid)) {
         $req->setUid(self::$uid);
      }

      $callback = $req->getCallback();
      if (empty($callback)) {
         $req->setCallback(self::$directoryUrl . 'client.php?response=1');
      }

      $nonce = $req->getNonce();
      if (empty($nonce)) {
         $req->setNonce();
      }
      
      $req->setChecksum($req->computeChecksum(self::$userSecret));
      
      if (!$req->isValid(self::$userSecret)) {
         self::handleError('Invalid request provided.');
      }

      $json = $req->toJson();
      $url = 'http://' . self::ThumberServerHost . self::ThumberServerCreatePath;
      $headers = array('Content-Type: application/json', 'Content-Length: ' . strlen($json));
      $result = self::sendToThumber('POST', $url, $headers, $json);
      $result['nonce'] = !array_key_exists('curl_error', $result) ? $req->getNonce() : '';

      // caller should handle errors sensibly
      return $result;
   }
   
   /**
    * Processes the POST request, generating a ThumberResponse, validating, and passing the result to $callback.
    * If not using client.php as the webhook, whoever receives webhook response should first invoke this method to
    * validate response.
    */
   public static function receiveResponse() {
      include_once self::$thumberClientPath . 'response.php';
      
      if (!isset(self::$callback)) {
         self::handleError(__CLASS__ . '::$callback must be initialized.');
      }

      $json = stream_get_contents(fopen('php://input', 'r'));
      $resp = ThumberResp::parseJson($json);
      
      if (is_null($resp)) {
         self::handleError('Failed to parse JSON in POST body: ' . $json);
      }
      
      if (!$resp->isValid(self::$userSecret)) {
         self::handleError('Received invalid response: ' . $json);
      }
      
      // response passed validation -- relay to callback function
      call_user_func(self::$callback, $resp);
   }

   /**
    * @return array The supported MIME types reported by the Thumber server.
    */
   public static function getMimeTypes() {
      $headers = array('Content-Type: application/json', 'Content-Length: 0');
      $url = 'http://' . self::ThumberServerHost . self::ThumberServerMimeTypesPath;
      $result = self::sendToThumber('GET', $url, $headers);
      return !array_key_exists('curl_error', $result) ? json_decode($result['body'], true) : array();
   }

   /**
    * Sends cURL request to Thumber server.
    * @param $type string GET or POST
    * @param $url string The URL endpoint being targeted.
    * @param $httpHeaders array The headers to be sent.
    * @param $body string The POST body. Ignored if type is GET.
    * @return array The result of the request.
    */
   private static function sendToThumber($type, $url, $httpHeaders, $body = '') {
      // invoke other HTTP send implementation
      if (isset(self::$httpSendOverride)) {
         return call_user_func(self::$httpSendOverride, $type, $url, $httpHeaders, $body);
      }

      // open connection
      $ch = curl_init();

      curl_setopt($ch, CURLOPT_USERAGENT,      self::$thumberUserAgent);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_URL,            $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER,     $httpHeaders);
      if ($type == 'POST' && strlen($body) != 0) {
         curl_setopt($ch, CURLOPT_POSTFIELDS,  $body);
      }

      // execute post, storing useful information about result
      $response = curl_exec($ch);
      $error = curl_error($ch);
      $result = array (
          'header'          => '',
          'body'            => '',
          'curl_error'      => '',
          'http_code'       => '',
          'last_url'        => ''
      );

      if ($error === '') {
         $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

         $result['header']    = substr($response, 0, $header_size);
         $result['body']      = substr($response, $header_size);
         $result['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $result['last_url']  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
      } else {
         $result ['curl_error'] = $error;
      }

      curl_close($ch);

      return $result;
   }

   /**
    * @param $err string Fires on fatal error.
    */
   private static function handleError($err) {
      if (isset(self::$errorHandler)) {
         call_user_func(self::$errorHandler, $err);
      }

      die($err);
   }
}