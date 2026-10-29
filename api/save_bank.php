<?php
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
  'success'=>false,
  'message'=>'Manual question-bank saving is disabled. Use the structured question entry workflow at modules/teaching/question-entry.php.'
],JSON_UNESCAPED_UNICODE);
