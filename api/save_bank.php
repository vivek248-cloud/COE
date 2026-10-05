<?php
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
  'success'=>false,
  'message'=>'Manual question-bank saving is disabled. Use the upload -> extract -> preview -> submit workflow at modules/teaching/upload.php.'
],JSON_UNESCAPED_UNICODE);
