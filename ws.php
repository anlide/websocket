<?php
class ws {
  private static $hvaltr = ['; ' => '&', ';' => '&', ' ' => '%20'];

  const maxAllowedPacket = 1024 * 1024 * 1024;
  const MAX_BUFFER_SIZE = 1024 * 1024;

  protected $socket;

  /**
   * @var array _SERVER
   */
  public $server = [];

  protected $on_frame_user = null;

  protected $handshaked = false;

  protected $headers = [];
  protected $headers_sent = false;

  protected $closed = false;
  protected $unparsed_data = '';
  private $current_header;
  private $unread_lines = array();
  /**
   * @var ws|null
   */
  private $new_instance = null;

  protected $extensions = [];
  protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';

  /**
   * @var integer Current state
   */
  protected $state = 0; // stream state of the connection (application protocol level)

  /**
   * Alias of STATE_STANDBY
   */
  const STATE_ROOT = 0;

  /**
   * Standby state (default state)
   */
  const STATE_STANDBY = 0;

  /**
   * State: first line
   */
  const STATE_FIRSTLINE  = 1;

  /**
   * State: headers
   */
  const STATE_HEADERS    = 2;

  /**
   * State: content
   */
  const STATE_CONTENT    = 3;

  /**
   * State: prehandshake
   */
  const STATE_PREHANDSHAKE = 5;

  /**
   * State: handshaked
   */
  const STATE_HANDSHAKED = 6;

  public function get_state() {
    return $this->state;
  }

  public function get_new_instance() {
    return $this->new_instance;
  }

  public function closed() {
    return $this->closed;
  }

  protected function close() {
    if ($this->closed) return;
    var_dump('self close');
    fclose($this->socket);
    $this->closed = true;
  }
  public function __construct($socket, $on_frame_user = null) {
    stream_set_blocking($socket, false);
    $this->socket = $socket;
    $this->on_frame_user = $on_frame_user;
  }

  private function read_line() {
    $lines = explode(PHP_EOL, $this->unparsed_data);
    $last_line = $lines[count($lines)-1];
    unset($lines[count($lines) - 1]);
    foreach ($lines as $line) {
      $this->unread_lines[] = $line;
    }
    $this->unparsed_data = $last_line;
    if (count($this->unread_lines) != 0) {
      return array_shift($this->unread_lines);
    } else {
      return null;
    }
  }
  public function on_receive_data() {
    if ($this->closed) return;
    $data = stream_socket_recvfrom($this->socket, self::MAX_BUFFER_SIZE);
    if (is_string($data)) {
      $this->unparsed_data .= $data;
    }
  }
  /**
   * Called when new data received.
   * @return void
   */
  public function on_read() {
    if ($this->closed) return;
    if ($this->state === self::STATE_STANDBY) {
      $this->state = self::STATE_FIRSTLINE;
    }
    if ($this->state === self::STATE_FIRSTLINE) {
      if (!$this->http_read_first_line()) {
        return;
      }
      $this->state = self::STATE_HEADERS;
    }

    if ($this->state === self::STATE_HEADERS) {
      if (!$this->http_read_headers()) {
        return;
      }
      if (!$this->http_process_headers()) {
        $this->close();
        return;
      }
      $this->state = self::STATE_CONTENT;
    }
    if ($this->state === self::STATE_CONTENT) {
      $this->state = self::STATE_PREHANDSHAKE;
    }
  }
  /**
   * Read first line of HTTP request
   * @return boolean|null Success
   */
  protected function http_read_first_line() {
    if (($l = $this->read_line()) === null) {
      return null;
    }
    $e = explode(' ', $l);
    $u = isset($e[1]) ? parse_url($e[1]) : false;
    if ($u === false) {
      $this->bad_request();
      return false;
    }
    if (!isset($u['path'])) {
      $u['path'] = null;
    }
    if (isset($u['host'])) {
      $this->server['HTTP_HOST'] = $u['host'];
    }
    $address = explode(':', stream_socket_get_name($this->socket, true)); //получаем адрес клиента
    $srv                       = & $this->server;
    $srv['REQUEST_METHOD']     = $e[0];
    $srv['REQUEST_TIME']       = time();
    $srv['REQUEST_TIME_FLOAT'] = microtime(true);
    $srv['REQUEST_URI']        = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
    $srv['DOCUMENT_URI']       = $u['path'];
    $srv['PHP_SELF']           = $u['path'];
    $srv['QUERY_STRING']       = isset($u['query']) ? $u['query'] : null;
    $srv['SCRIPT_NAME']        = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
    $srv['SERVER_PROTOCOL']    = isset($e[2]) ? $e[2] : 'HTTP/1.1';
    $srv['REMOTE_ADDR']        = $address[0];
    $srv['REMOTE_PORT']        = $address[1];
    return true;
  }
  /**
   * Read headers line-by-line
   * @return boolean|null Success
   */
  protected function http_read_headers() {
    while (($l = $this->read_line()) !== null) {
      if ($l === '') {
        return true;
      }
      $e = explode(': ', $l);
      if (isset($e[1])) {
        $this->current_header                = 'HTTP_' . strtoupper(strtr($e[0], ['-' => '_']));
        $this->server[$this->current_header] = $e[1];
      } elseif (($e[0][0] === "\t" || $e[0][0] === "\x20") && $this->current_header) {
        // multiline header continued
        $this->server[$this->current_header] .= $e[0];
      } else {
        // whatever client speaks is not HTTP anymore
        $this->bad_request();
        return false;
      }
    }
  }
  /**
   * Process headers
   * @return bool
   */
  protected function http_process_headers() {
    $this->state = self::STATE_PREHANDSHAKE;
    if (isset($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS'])) {
      $str              = strtolower($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS']);
      $str              = preg_replace($this->extensionsCleanRegex, '', $str);
      $this->extensions = explode(', ', $str);
    }
    if (!isset($this->server['HTTP_CONNECTION'])
      || (!preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->server['HTTP_CONNECTION'])) // "Upgrade" is not always alone (ie. "Connection: Keep-alive, Upgrade")
      || !isset($this->server['HTTP_UPGRADE'])
      || (strtolower($this->server['HTTP_UPGRADE']) !== 'websocket') // Lowercase comparison iss important
    ) {
      $this->close();
      return false;
    }
    /*
    if (isset($this->server['HTTP_COOKIE'])) {
      self::parse_str(strtr($this->server['HTTP_COOKIE'], self::$hvaltr), $this->cookie);
    }
    if (isset($this->server['QUERY_STRING'])) {
      self::parse_str($this->server['QUERY_STRING'], $this->get);
    }
    */
    // ----------------------------------------------------------
    // Protocol discovery, based on HTTP headers...
    // ----------------------------------------------------------
    if (isset($this->server['HTTP_SEC_WEBSOCKET_VERSION'])) { // HYBI
      if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '8') { // Version 8 (FF7, Chrome14)
        $this->switch_to_protocol('v13');
      } elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '13') { // newest protocol
        $this->switch_to_protocol('v13');
      } else {
        error_log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "addr"'); // $this->addr
        $this->close();
        return false;
      }
    } elseif (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
      $this->switch_to_protocol('ve');
    } else { // Defaulting to HIXIE (Safari5 and many non-browser clients...)
      $this->switch_to_protocol('v0');
    }
    // ----------------------------------------------------------
    // End of protocol discovery
    // ----------------------------------------------------------
    return true;
  }
  private function switch_to_protocol($protocol) {
    $class = 'ws_'.$protocol;
    $this->new_instance = new $class($this->socket);
    $this->new_instance->state = $this->state;
    $this->new_instance->unparsed_data = $this->unparsed_data;
    $this->new_instance->server = $this->server;
    $this->new_instance->on_frame_user = $this->on_frame_user;
  }
  /**
   * Send Bad request
   * @return void
   */
  public function bad_request() {
    $this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
    $this->close();
  }
  /**
   * Replacement for default parse_str(), it supoorts UCS-2 like this: %uXXXX
   * @param  string  $s      String to parse
   * @param  array   &$var   Reference to the resulting array
   * @param  boolean $header Header-style string
   * @return void
   */
  public static function parse_str($s, &$var, $header = false) {
    static $cb;
    if ($cb === null) {
      $cb = function ($m) {
        return urlencode(html_entity_decode('&#' . hexdec($m[1]) . ';', ENT_NOQUOTES, 'utf-8'));
      };
    }
    if ($header) {
      $s = strtr($s, self::$hvaltr);
    }
    if (
      (stripos($s, '%u') !== false)
      && preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is', $s, $m)
    ) {
      $s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i', $cb, $s);
    }
    parse_str($s, $var);
  }
  /**
   * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
   * @param  string  $data Data to send
   * @return boolean       Success
   */
  public function write($data) {
    if ($this->closed) return false;
    return stream_socket_sendto($this->socket, $data) == 0;
  }

  /**
   * Будте любезны в отнаследованном классе реализовать этот метод
   * @return bool
   */
  protected function send_handshake_reply() {
    return false;
  }
  /**
   * Called when we're going to handshake.
   * @return boolean               Handshake status
   */
  public function handshake() {
    $extra_headers = '';
    foreach ($this->headers as $k => $line) {
      if ($k !== 'STATUS') {
        $extra_headers .= $line . "\r\n";
      }
    }

    if (!$this->send_handshake_reply($extra_headers)) {
      error_log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client ""'); // $this->addr
      $this->close();
      return false;
    }

    $this->handshaked = true;
    $this->headers_sent = true;
    $this->state = static::STATE_HANDSHAKED;
    return true;
  }
  /**
   * Read from buffer without draining
   * @param integer $n Number of bytes to read
   * @param integer $o Offset
   * @return string|false
   */
  public function look($n, $o = 0) {
    if (strlen($this->unparsed_data) <= $o) {
      return '';
    }
    return substr($this->unparsed_data, $o, $n);
  }
  /**
   * Convert bytes into integer
   * @param  string  $str Bytes
   * @param  boolean $l   Little endian? Default is false
   * @return integer
   */
  public static function bytes2int($str, $l = false) {
    if ($l) {
      $str = strrev($str);
    }
    $dec = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; ++$i) {
      $dec += ord(substr($str, $i, 1)) * pow(0x100, $len - $i - 1);
    }
    return $dec;
  }
  /**
   * Drains buffer
   * @param  integer $n Numbers of bytes to drain
   * @return boolean    Success
   */
  public function drain($n) {
    $ret = substr($this->unparsed_data, 0, $n);
    $this->unparsed_data = substr($this->unparsed_data, $n);
    return $ret;
  }
  /**
   * Read data from the connection's buffer
   * @param  integer      $n Max. number of bytes to read
   * @return string|false    Readed data
   */
  public function read($n) {
    if ($n <= 0) {
      return '';
    }
    $read = $this->drain($n);
    if ($read === '') {
      return false;
    }
    return $read;
  }
  /**
   * Reads all data from the connection's buffer
   * @return string Readed data
   */
  public function read_unlimited() {
    $ret = $this->unparsed_data;
    $this->unparsed_data = '';
    return $ret;
  }
  /**
   * Searches first occurence of the string in input buffer
   * @param  string  $what  Needle
   * @param  integer $start Offset start
   * @param  integer $end   Offset end
   * @return integer        Position
   */
  public function search($what, $start = 0, $end = -1) {
    return strpos($this->unparsed_data, $what, $start);
  }
  /**
   * Called when new frame received.
   * @param  string $data Frame's data.
   * @param  string $type Frame's type ("STRING" OR "BINARY").
   * @return boolean      Success.
   */
  public function on_frame($data, $type) {
    if (is_callable($this->on_frame_user)) {
      call_user_func($this->on_frame_user, $this, $data, $type);
    }
    return true;
  }
  public function send_frame($data, $type = null, $cb = null) {
    return false;
  }
  /**
   * Get real frame type identificator
   * @param $type
   * @return integer
   */
  public function get_frame_type($type) {
    if (is_int($type)) {
      return $type;
    }
    if ($type === null) {
      $type = 'STRING';
    }
    $frametype = @constant(get_class($this) . '::' . $type);
    if ($frametype === null) {
      error_log(__METHOD__ . ' : Undefined frametype "' . $type . '"');
    }
    return $frametype;
  }
}
