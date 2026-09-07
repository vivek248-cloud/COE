<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/question_bank_helpers.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    redirect(url('modules/coe/question-bank/'));
}

$stmt = $pdo->prepare('DELETE FROM question_banks WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'Question bank deleted successfully.');
redirect(url('modules/coe/question-bank/'));
