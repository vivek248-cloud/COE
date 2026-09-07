<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

/*
|--------------------------------------------------------------------------
| Composer / PHPWord
|--------------------------------------------------------------------------
*/

$composerAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

$docxSupport = class_exists(\PhpOffice\PhpWord\IOFactory::class);

/*
|--------------------------------------------------------------------------
| Question Bank
|--------------------------------------------------------------------------
*/

$bankId = (int) ($_GET['bank_id'] ?? $_POST['bank_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT * FROM question_banks WHERE id = :id LIMIT 1'
);
$stmt->execute(['id' => $bankId]);

$bank = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bank) {
    http_response_code(404);
    exit('Question bank not found.');
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

$page_title = 'Import Questions';
$current_page = 'question-bank';

$preview = [];
$errors = [];
$preview_path = null;
$file_type = null;

/*
|--------------------------------------------------------------------------
| CSV columns
|--------------------------------------------------------------------------
|
| These match the actual questions table:
|
| question_no
| question_text
| unit
| question_format
| bloom_level
| marks
|
*/

$headers = [
    'question_no',
    'question_text',
    'unit',
    'question_format',
    'bloom_level',
    'marks',
];

/*
|--------------------------------------------------------------------------
| Local helpers
|--------------------------------------------------------------------------
*/

function qbank_upload_path_is_safe(string $path): bool
{
    $realPath = realpath($path);
    $uploadRoot = realpath(UPLOADS_PATH);

    if ($realPath === false || $uploadRoot === false) {
        return false;
    }

    $root = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return strncmp($realPath, $root, strlen($root)) === 0;
}

function qbank_prepare_csv_rows(string $path, array &$errors): array
{
    try {
        $rows = qbank_parse_csv($path);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        return [];
    }

    return $rows;
}

function qbank_prepare_docx_rows(string $path, array &$errors): array
{
    try {
        $rows = qbank_parse_docx($path);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        return [];
    }

    return $rows;
}

/*
|--------------------------------------------------------------------------
| Upload -> Preview
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_FILES['question_file']) &&
    !isset($_POST['confirm_import'])
) {
    $file = $_FILES['question_file'];

    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        $errors[] = 'Upload failed.';
    } elseif (
        !isset($file['size']) ||
        (int) $file['size'] <= 0
    ) {
        $errors[] = 'The uploaded file is empty.';
    } elseif (
        (int) $file['size'] > MAX_CSV_FILE_SIZE
    ) {
        $errors[] = 'Uploaded file exceeds the 5 MB limit.';
    } else {
        $originalName = (string) ($file['name'] ?? '');

        $extension = strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );

        if (!in_array($extension, ['csv', 'docx'], true)) {
            $errors[] = 'Only .csv and .docx files are allowed.';
        } else {
            if ($extension === 'csv') {
                $file_type = 'csv';

                $preview = qbank_prepare_csv_rows(
                    $file['tmp_name'],
                    $errors
                );
            } else {
                $file_type = 'docx';

                if (!$docxSupport) {
                    $errors[] =
                        'DOCX support is unavailable. PHPWord is not loaded.';
                } else {
                    $preview = qbank_prepare_docx_rows(
                        $file['tmp_name'],
                        $errors
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate rows against the current bank
            |--------------------------------------------------------------------------
            */

            if (!$errors && $preview) {
                $validation = qbank_validate_rows(
                    $pdo,
                    $preview,
                    $bankId
                );

                $errors = array_merge(
                    $errors,
                    $validation['errors']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Save the original upload for confirmation
            |--------------------------------------------------------------------------
            */

            if (!$errors && $preview) {
                if (
                    !is_dir(UPLOADS_PATH) &&
                    !mkdir(UPLOADS_PATH, 0750, true) &&
                    !is_dir(UPLOADS_PATH)
                ) {
                    $errors[] =
                        'Unable to create upload storage directory.';
                } else {
                    $savedName =
                        bin2hex(random_bytes(16)) .
                        '.' .
                        $extension;

                    $savedPath =
                        rtrim(UPLOADS_PATH, DIRECTORY_SEPARATOR) .
                        DIRECTORY_SEPARATOR .
                        $savedName;

                    if (
                        !move_uploaded_file(
                            $file['tmp_name'],
                            $savedPath
                        )
                    ) {
                        $errors[] =
                            'Unable to save the uploaded file.';
                    } else {
                        $preview_path = $savedPath;
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Confirm Import
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_import'])
) {
    $path = trim(
        (string) ($_POST['preview_path'] ?? '')
    );

    $fileType = strtolower(
        trim((string) ($_POST['file_type'] ?? ''))
    );

    $preview_path = $path;
    $file_type = $fileType;

    /*
    |--------------------------------------------------------------------------
    | Validate saved path
    |--------------------------------------------------------------------------
    */

    if ($path === '' || !is_file($path)) {
        $errors[] =
            'Preview expired. Please upload the file again.';
    } elseif (!qbank_upload_path_is_safe($path)) {
        $errors[] = 'Invalid import file.';
    } elseif (!in_array($fileType, ['csv', 'docx'], true)) {
        $errors[] = 'Invalid import file type.';
    }

    /*
    |--------------------------------------------------------------------------
    | Re-read saved file
    |--------------------------------------------------------------------------
    */

    if (!$errors) {
        $recheckErrors = [];

        if ($fileType === 'csv') {
            $importRows = qbank_prepare_csv_rows(
                $path,
                $recheckErrors
            );
        } else {
            if (!$docxSupport) {
                $recheckErrors[] =
                    'DOCX support is unavailable. PHPWord is not loaded.';
                $importRows = [];
            } else {
                $importRows = qbank_prepare_docx_rows(
                    $path,
                    $recheckErrors
                );
            }
        }

        $errors = array_merge(
            $errors,
            $recheckErrors
        );

        if (!$errors && $importRows) {
            $validation = qbank_validate_rows(
                $pdo,
                $importRows,
                $bankId
            );

            $errors = array_merge(
                $errors,
                $validation['errors']
            );
        }

        if (!$errors && !$importRows) {
            $errors[] = 'No questions were found in the uploaded file.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Import transaction
    |--------------------------------------------------------------------------
    */

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                '
                INSERT INTO questions (
                    question_bank_id,
                    question_no,
                    question_text,
                    unit,
                    question_format,
                    bloom_level,
                    marks,
                    normalized_text,
                    question_hash
                ) VALUES (
                    :question_bank_id,
                    :question_no,
                    :question_text,
                    :unit,
                    :question_format,
                    :bloom_level,
                    :marks,
                    :normalized_text,
                    :question_hash
                )
                '
            );

            $count = 0;

            foreach ($importRows as $index => $row) {
                $questionText = trim(
                    (string) ($row['question_text'] ?? '')
                );

                if ($questionText === '') {
                    continue;
                }

                $questionNoRaw = trim(
                    (string) ($row['question_no'] ?? '')
                );

                /*
                | DOCX parser always supplies a number.
                | CSV without a number gets the next available
                | number inside this import.
                */
                $questionNo = $questionNoRaw !== ''
                    ? (int) $questionNoRaw
                    : ($index + 1);

                if ($questionNo <= 0) {
                    throw new RuntimeException(
                        'Invalid question number at import row ' .
                        ($index + 1) .
                        '.'
                    );
                }

                $unit = trim(
                    (string) ($row['unit'] ?? '')
                );

                $format = trim(
                    (string) ($row['question_format'] ?? '')
                );

                $bloom = strtoupper(
                    trim(
                        (string) ($row['bloom_level'] ?? '')
                    )
                );

                $marksRaw = trim(
                    (string) ($row['marks'] ?? '')
                );

                $marks = $marksRaw !== ''
                    ? (float) $marksRaw
                    : (float) (
                        qbank_default_marks($format) ?? 0
                    );

                $normalized = qbank_normalize_text(
                    $questionText
                );

                $hash = qbank_hash($questionText);

                $insert->execute([
                    'question_bank_id' => $bankId,
                    'question_no' => $questionNo,
                    'question_text' => $questionText,
                    'unit' => $unit,
                    'question_format' => $format,
                    'bloom_level' => $bloom,
                    'marks' => $marks,
                    'normalized_text' => $normalized,
                    'question_hash' => $hash,
                ]);

                $count++;
            }

            /*
            |--------------------------------------------------------------------------
            | Keep question-bank count synchronized
            |--------------------------------------------------------------------------
            */

            $countStmt = $pdo->prepare(
                '
                UPDATE question_banks
                SET total_questions = (
                    SELECT COUNT(*)
                    FROM questions
                    WHERE question_bank_id = :bank
                )
                WHERE id = :id
                '
            );

            $countStmt->execute([
                'bank' => $bankId,
                'id' => $bankId,
            ]);

            $pdo->commit();

            @unlink($path);

            set_flash(
                'success',
                "Imported {$count} questions successfully."
            );

            redirect(
                'modules/coe/question-bank/view.php?id=' .
                $bankId
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                'Import failed. No questions were saved.';

            error_log(
                'Question import failed: ' .
                $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rebuild preview for display after failed confirmation
    |--------------------------------------------------------------------------
    */

    if (!$errors) {
        $preview = [];
    } elseif (
        isset($importRows) &&
        is_array($importRows) &&
        $importRows
    ) {
        $preview = $importRows;
    }
}

/*
|--------------------------------------------------------------------------
| View
|--------------------------------------------------------------------------
*/

require dirname(__DIR__, 3) . '/includes/layout/header.php';
require dirname(__DIR__, 3) . '/includes/layout/sidebar.php';
?>

<div class="app-main">

    <?php
    require dirname(__DIR__, 3) . '/includes/layout/navbar.php';
    ?>

    <main class="app-content">

        <div class="page-heading">

            <h1>Import Questions</h1>

            <p>
                Import questions into
                <strong>
                    <?= e($bank['course_code'] ?? '') ?>
                </strong>.
                Upload CSV or DOCX, preview and validate
                before insertion.
            </p>

        </div>

        <?php foreach ($errors as $err): ?>

            <div class="alert alert-danger">
                <?= e($err) ?>
            </div>

        <?php endforeach; ?>

        <div class="card-panel mb-4">

            <div class="card-panel-body">

                <form
                    method="post"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        name="bank_id"
                        value="<?= e($bankId) ?>"
                    >

                    <label class="form-label">
                        CSV / DOCX File
                    </label>

                    <input
                        class="form-control"
                        type="file"
                        name="question_file"
                        accept=".csv,.docx,text/csv,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                        required
                    >

                    <div class="form-text">

                        Maximum 5 MB.

                        <br>

                        <strong>CSV required columns:</strong>

                        <?= e(
                            implode(', ', $headers)
                        ) ?>

                        <br>

                        <strong>CSV aliases supported:</strong>
                        question,
                        question text,
                        qno,
                        q.no,
                        format,
                        bloom,
                        bloom level.

                        <br>

                        <strong>DOCX:</strong>
                        each numbered paragraph is treated
                        as one question. DOCX questions currently
                        require the question metadata expected by
                        the validation rules.

                    </div>

                    <?php if (!$docxSupport): ?>

                        <div class="alert alert-warning mt-3">
                            DOCX support is currently unavailable.
                            PHPWord could not be loaded.
                        </div>

                    <?php endif; ?>

                    <button
                        class="btn btn-primary mt-3"
                        type="submit"
                    >
                        Validate &amp; Preview
                    </button>

                </form>

            </div>

        </div>

        <?php if ($preview): ?>

            <div class="card-panel">

                <div class="card-panel-header">

                    <h5>
                        Preview
                        (<?= count($preview) ?> rows)
                    </h5>

                </div>

                <div class="card-panel-body">

                    <div class="table-responsive">

                        <table class="table table-sm">

                            <thead>

                                <tr>
                                    <th>#</th>
                                    <th>Question</th>
                                    <th>Format</th>
                                    <th>Bloom</th>
                                    <th>Marks</th>
                                    <th>Unit</th>
                                    <th>Status</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $preview as $i => $r
                                ): ?>

                                    <?php
                                    $rowNumber =
                                        $r['question_no'] ??
                                        ($i + 1);

                                    $rowError =
                                        $r['_error'] ??
                                        null;
                                    ?>

                                    <tr>

                                        <td>
                                            <?= e($rowNumber) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $r['question_text'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $r['question_format'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $r['bloom_level'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $r['marks'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $r['unit'] ?? ''
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if ($rowError !== null): ?>

                                                <span class="badge text-bg-danger">
                                                    <?= e($rowError) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="badge text-bg-success">
                                                    Valid
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php
                    $hasInvalidRows = (bool) array_filter(
                        $preview,
                        static fn ($r) =>
                            isset($r['_error']) &&
                            $r['_error'] !== null
                    );
                    ?>

                    <?php if ($preview_path): ?>

                        <form
                            method="post"
                            class="mt-3"
                        >

                            <input
                                type="hidden"
                                name="bank_id"
                                value="<?= e($bankId) ?>"
                            >

                            <input
                                type="hidden"
                                name="confirm_import"
                                value="1"
                            >

                            <input
                                type="hidden"
                                name="preview_path"
                                value="<?= e($preview_path) ?>"
                            >

                            <input
                                type="hidden"
                                name="file_type"
                                value="<?= e(
                                    $file_type ??
                                    pathinfo(
                                        $preview_path,
                                        PATHINFO_EXTENSION
                                    )
                                ) ?>"
                            >

                            <button
                                class="btn btn-success"
                                type="submit"
                                <?= $hasInvalidRows
                                    ? 'disabled'
                                    : '' ?>
                            >
                                Confirm Import
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </main>

    <?php
    require dirname(__DIR__, 3) . '/includes/layout/footer.php';
    ?>

</div>

<?php
require dirname(__DIR__, 3) . '/includes/layout/scripts.php';
?>
