<?php
/**
 * Translation adapter. Storage remains the original UTF-8 question text.
 * Define QPS_TRANSLATOR_CALLBACK as a callable accepting ($text,$sourceCode,$targetCode)
 * to connect a translation provider without changing database persistence.
 */
function qps_convert_question_text(string $text, string $sourceCode, string $targetCode): array {
    $sourceCode = strtoupper($sourceCode);
    $targetCode = strtoupper($targetCode);
    if ($sourceCode === $targetCode) return ['text'=>$text,'translated'=>false,'source'=>$sourceCode,'target'=>$targetCode];
    if (defined('QPS_TRANSLATOR_CALLBACK') && is_callable(QPS_TRANSLATOR_CALLBACK)) {
        $translated = call_user_func(QPS_TRANSLATOR_CALLBACK, $text, $sourceCode, $targetCode);
        if (is_string($translated) && $translated !== '') return ['text'=>$translated,'translated'=>true,'source'=>$sourceCode,'target'=>$targetCode];
    }
    // Safe fallback: never overwrite or misrepresent the stored original text.
    return ['text'=>$text,'translated'=>false,'source'=>$sourceCode,'target'=>$targetCode,'message'=>'Translation provider is not configured.'];
}
