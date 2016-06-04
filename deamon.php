<?php
require('ws.php');
require('ws_v0.php');
require('ws_v13.php');
require('ws_ve.php');
$master = stream_socket_server("tcp://127.0.0.1:8081", $errno, $errstr);
if (!$master) die("$errstr ($errno)\n");
$sockets = array($master);
/**
 * @var ws[] $connections
 */
$connections = array();
stream_set_blocking($master, false);
/**
 * @param ws $connection
 * @param $data
 * @param $type
 */
$my_callback = function($connection, $data, $type) {
  var_dump('my ws data: ['.$data.'/'.$type.']');
  $connection->send_frame('test '.time());
};
while (true) {
  $read = $sockets;
  $write = $except = array();
  if (($num_changed_streams = stream_select($read, $write, $except, 0, 1000000)) === false) {
    var_dump('stream_select error');
    break;
  }
  foreach ($read as $socket) {
    $index_socket = array_search($socket, $sockets);
    if ($index_socket == 0) {
      // Новое соединение
      if ($socket_new = stream_socket_accept($master)) {
        $sockets[] = $socket_new;
        $index_new_socket = array_search($socket_new, $sockets);
        $connections[$index_new_socket] = new ws($socket_new, $my_callback, $index_new_socket);
        $index_socket = $index_new_socket;
      } else {
        // Я так и не понял что в этом случае надо делать
        error_log('stream_socket_accept');
        var_dump('error stream_socket_accept');
        continue;
      }
    }
    $connection = &$connections[$index_socket];
    $connection->on_receive_data();
    $connection->on_read();
    $new_instance = $connection->get_new_instance();
    if ($new_instance !== null) {
      $connections[$index_socket] = clone $new_instance;
      $connection = &$connections[$index_socket];
      $connection->on_read();
    }
    if ($connection->closed()) {
      unset($sockets[$index_socket]);
      unset($connections[$index_socket]);
      unset($connection);
      var_dump('close '.$index_socket);
    }
  }
}