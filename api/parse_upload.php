<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Please sign in.']); exit; }
http_response_code(410);
echo json_encode(['success'=>false,'message'=>"File-based question-bank import is retired. Use the structured question-entry form."], JSON_UNESCAPED_UNICODE);
