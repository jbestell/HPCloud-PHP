<?php
/**
 * @file
 * Implements a transporter with CURL.
 */

namespace HPCloud\Transport;

/**
 * Provide HTTP transport with CURL.
 *
 * You should choose the Curl backend if...
 *
 * - You KNOW Curl support is compiled into your PHP version
 * - You do not like the built-in PHP HTTP handler
 * - Performance is a big deal to you
 * - You will be sending large objects (>2M)
 * - Or PHP stream wrappers for URLs are not supported on your system.
 *
 * CURL is demonstrably faster than the built-in PHP HTTP handling, so
 * ths library gives a performance boost. Error reporting is slightly
 * better too.
 *
 * But the real strong point to Curl is that it can take file objects
 * and send them over HTTP without having to buffer them into strings
 * first. This saves memory and processing.
 *
 * The only downside to Curl is that it is not available on all hosts.
 * Some installations of PHP do not compile support.
 */
class CURLTransport implements Transporter {


  const HTTP_USER_AGENT_SUFFIX = ' (c93c0a) CURL/1.0';

  public function doRequest($uri, $method = 'GET', $headers = array(), $body = '') {

    $in = NULL;
    if (!empty($body)) {
      // First we turn our body into a temp-backed buffer.
      $in = fopen('php://temp', 'wr', FALSE);
      fwrite($in, $body, strlen($body));
      rewind($in);
    }
    return $this->handleDoRequest($uri, $method, $headers, $in);

  }

  public function doRequestWithResource($uri, $method, $headers, $resource) {
    if (is_string($resource)) {
      $in = open($resource, 'rb', FALSE);
    }
    else {
      $in = $resource;
    }
    return $this->handleDoRequest($uri, $method, $headers, $resource);
  }

  /**
   * Internal workhorse.
   */
  protected function handleDoRequest($uri, $method, $headers, $in = NULL) {

    syslog(LOG_WARNING, "Real Operation: $method $uri");

    //$urlParts = parse_url($uri);


    // Write to in-mem handle backed by a temp file.
    $out = fopen('php://temp', 'wrb');
    $headerFile = fopen('php://temp', 'wr');

    $curl = curl_init($uri);

    // Set method
    $this->determineMethod($curl, $method);

    // Set headers
    $this->setHeaders($curl, $headers);

    // Set the upload
    if (!empty($in)) {
      curl_setopt($curl, CURLOPT_INFILE, $in);

      // Tell CURL about the content length if we know it.
      if (!empty($headers['Content-Length'])) {
        curl_setopt($curl, CURLOPT_INFILESIZE, $headers['Content-Length']);
      }
    }

    // Get the output.
    curl_setopt($curl, CURLOPT_FILE, $out);

    // We need to capture the headers, too.
    curl_setopt($curl, CURLOPT_WRITEHEADER, $headerFile);

    // Show me the money!
    // Results are now buffered into a tmpfile.
    //curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);

    $opts = array(
      CURLOPT_USERAGENT => self::HTTP_USER_AGENT . self::HTTP_USER_AGENT_SUFFIX,
      // CURLOPT_RETURNTRANSFER => TRUE, // Make curl_exec return the results.
      // CURLOPT_BINARYTRANSFER => TRUE, // Raw output if RETURNTRANSFER is TRUE.

      // Put the headers in the output.
      CURLOPT_HEADER => TRUE,

      // Get the final header string sent to the remote.
      CURLINFO_HEADER_OUT => TRUE,

      // Timeout if the remote has not connected in 30 sec.
      CURLOPT_CONNECTTIMEOUT => 30,

      // Max time to allow CURL to do the transaction.
      // CURLOPT_TIMEOUT => 120,

      // If this is set, CURL will auto-deflate any encoding it can.
      // CURLOPT_ENCODING => '',

      // Later, we may want to do this to support range-based
      // fetching of large objects.
      // CURLOPT_RANGE => 'X-Y',

      // Limit curl to only these protos.
      // CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,

      // I don't really need this, do I?
      // CURLOPT_HTTP200ALIASES => array(200, 201, 202, 203, 204),
    );

    $ret = curl_exec($curl);
    $info = curl_getinfo($curl);
    $status = $info['http_code'];

    rewind($headerFile);
    $responseHeaders = $this->fetchHeaders($headerFile);
    fclose($headerFile);

    if (!$ret || $status < 200 || $status > 299) {
      if (empty($responseHeaders)) {
        $err = 'Unknown (non-HTTP) error: ' . $status;
      }
      else {
        $err = $responseHeaders[0];
      }
      Response::failure($status, $err, $info['url'], $method, $info);
    }


    rewind($out);
    // Now we need to build a response.
    $resp = new Response($out, $info, $responseHeaders);

    curl_close($curl);

    /* Don't close this!
    if (is_resource($in)) {
      fclose($in);
    }
     */

    //throw new \Exception(print_r($resp, TRUE));

    return $resp;
  }

  /**
   * This function reads the header file into an array.
   *
   * This format mataches the format returned by the stream handlers, so
   * we can re-use the header parsing logic in Response.
   *
   * @param resource $file
   *   A file pointer to the file that has the headers.
   * @return array
   *   An array of headers, one header per line.
   */
  protected function fetchHeaders($file) {
    $buffer = array();
    while ($header = fgets($file)) {
      $header = trim($header);
      if (!empty($header)) {
        $buffer[] = $header;
      }
    }
    return $buffer;
  }

  /**
   * Set the appropriate constant on the CURL object.
   *
   * Curl handles method name setting in a slightly counter-intuitive 
   * way, so we have a special function for setting the method 
   * correctly. Note that since we do not POST as www-form-*, we 
   * use a custom post.
   *
   * @param resource $curl
   *   A curl object.
   * @param string $method
   *   An HTTP method name.
   */
  protected function determineMethod($curl, $method) {
    $method = strtoupper($method);

    switch ($method) {
      case 'GET':
        curl_setopt($curl, CURLOPT_HTTPGET, TRUE);
        break;
      case 'HEAD':
        curl_setopt($curl, CURLOPT_NOBODY, TRUE);
        break;

      // Put is problematic: Some PUT requests might not have
      // a body.
      case 'PUT':
        curl_setopt($curl, CURLOPT_PUT, TRUE);
        break;

      // We use customrequest for post because we are
      // not submitting form data.
      case 'POST':
      case 'DELETE':
      case 'COPY':
      default:
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    }

  }

  public function setHeaders($curl, $headers) {
    $buffer = array();
    $format = '%s: %s';

    foreach ($headers as $name => $value) {
      $buffer[] = sprintf($format, $name, $value);
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $buffer);
  }
}
