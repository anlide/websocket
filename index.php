<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>WebSocket test page</title>
</head>
<body onload="create();">
<script type="text/javascript">
  function create() {
    // Example
    ws = new WebSocket('ws://'+document.domain+':8081/');
    ws.onopen = function () {document.getElementById('log').innerHTML += 'WebSocket opened <br/>';}
    ws.onmessage = function (e) {document.getElementById('log').innerHTML += 'WebSocket message: '+e.data+' <br/>';}
    ws.onclose = function () {document.getElementById('log').innerHTML += 'WebSocket closed <br/>';}
  }
</script>
<button onclick="create();">Create WebSocket</button>
<button onclick="ws.send('ping');">Send ping</button>
<button onclick="ws.close();">Close WebSocket</button>
<div id="log" style="width:300px; height: 300px; border: 1px solid #999999; overflow:auto;"></div>
</body>
</html>