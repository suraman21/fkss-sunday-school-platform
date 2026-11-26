<?php

// =========================================================
// FELEGE KIDUSAN - EXCEL QUESTION BANK IMPORT
// =========================================================
// POST /api/questions/import
// Upload field: file
// Supported format: .xlsx
// =========================================================

function handleQuestionImportRouteApi($pdo, $uri)
{
    if ($uri !== "/api/questions/import") {
        return false;
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJson([
            "success" => false,
            "message" => "Method not allowed."
        ], 405);
    }

    if (!isset($_FILES["file"]) || !is_array($_FILES["file"])) {
        sendJson([
            "success" => false,
            "message" => "Please upload an Excel file using the field name 'file'."
        ], 400);
    }

    $file = $_FILES["file"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        sendJson([
            "success" => false,
            "message" => "Excel file upload failed."
        ], 400);
    }

    $maxFileSize = 5 * 1024 * 1024;

    if ((int) $file["size"] > $maxFileSize) {
        sendJson([
            "success" => false,
            "message" => "Excel file is too large. Maximum size is 5 MB."
        ], 400);
    }

    $originalName = basename((string) $file["name"]);
    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    if ($extension !== "xlsx") {
        sendJson([
            "success" => false,
            "message" => "Please upload an .xlsx Excel file."
        ], 400);
    }

    if (!class_exists("ZipArchive")) {
        sendJson([
            "success" => false,
            "message" => "Excel import requires the PHP Zip extension (ZipArchive). Please enable the PHP Zip extension."
        ], 500);
    }

    $tmpPath = $file["tmp_name"];

    try {
        $rows = questionImportReadXlsx($tmpPath);
    } catch (Throwable $e) {
        sendJson([
            "success" => false,
            "message" => "Could not read the Excel workbook.",
            "error" => $e->getMessage()
        ], 400);
    }

    if (count($rows) < 2) {
        sendJson([
            "success" => false,
            "message" => "The Questions sheet must contain a header row and at least one question."
        ], 400);
    }

    $headers = array_shift($rows);
    $headers = array_map("questionImportNormalizeHeader", $headers);

    $headerMap = [];

    foreach ($headers as $index => $header) {
        if ($header === "") {
            continue;
        }

        if (!isset($headerMap[$header])) {
            $headerMap[$header] = $index;
        }
    }

    $requiredColumns = [
        "unit",
        "question",
        "option_a",
        "option_b",
        "option_c",
        "option_d",
        "correct",
        "explanation"
    ];

    $missing = [];

    foreach ($requiredColumns as $column) {
        if (!array_key_exists($column, $headerMap)) {
            $missing[] = $column;
        }
    }

    if (!empty($missing)) {
        sendJson([
            "success" => false,
            "message" => "Missing required Excel columns.",
            "missing_columns" => $missing
        ], 400);
    }

    $rows = array_values(
        array_filter(
            $rows,
            function ($row) {
                foreach ($row as $value) {
                    if (trim((string) $value) !== "") {
                        return true;
                    }
                }

                return false;
            }
        )
    );

    if (count($rows) > 2000) {
        sendJson([
            "success" => false,
            "message" => "The Excel file contains too many question rows. Maximum is 2000."
        ], 400);
    }

    // Current demo mapping.
    $defaultSubjectId = 1;

    $preparedRows = [];
    $errors = [];
    $seenQuestions = [];

    foreach ($rows as $rowIndex => $row) {
        $excelRowNumber = $rowIndex + 2;

        $get = function ($names, $default = "") use ($row, $headerMap) {
            foreach ($names as $name) {
                if (isset($headerMap[$name])) {
                    $index = $headerMap[$name];
                    return trim((string) ($row[$index] ?? $default));
                }
            }

            return $default;
        };

        $unitValue = $get(["unit"]);
        $lessonValue = $get(["lesson"]);
        $questionText = $get(["question"]);
        $optionA = $get(["option_a"]);
        $optionB = $get(["option_b"]);
        $optionC = $get(["option_c"]);
        $optionD = $get(["option_d"]);
        $optionE = $get(["option_e"]);
        $optionF = $get(["option_f"]);
        $correct = strtoupper($get(["correct"]));
        $difficultyValue = strtolower($get(["difficulty"], ""));
        $language = strtolower($get(["language"], "am"));
        $explanation = $get(["explanation"]);
        $activeValue = $get(["active"], "1");

        $rowErrors = [];

        if ($unitValue === "") {
            $rowErrors[] = "unit is required";
        }

        if ($questionText === "") {
            $rowErrors[] = "question is required";
        }

        if ($optionA === "" || $optionB === "" || $optionC === "" || $optionD === "") {
            $rowErrors[] = "options A-D are required";
        }

        $providedOptions = [
            "A" => $optionA,
            "B" => $optionB,
            "C" => $optionC,
            "D" => $optionD,
            "E" => $optionE,
            "F" => $optionF
        ];

        $validLetters = [];

        foreach ($providedOptions as $letter => $text) {
            if ($text !== "") {
                $validLetters[] = $letter;
            }
        }

        if (!in_array($correct, $validLetters, true)) {
            $rowErrors[] = "correct must point to one provided option (A-F)";
        }

        if ($explanation === "") {
            $rowErrors[] = "explanation is required";
        }

        if ($difficultyValue === "") {
            $difficulty = "medium";
        } else {
            $difficulty = $difficultyValue;

            if (!in_array($difficulty, ["easy", "medium", "hard"], true)) {
                $rowErrors[] = "difficulty must be easy, medium, or hard";
            }
        }

        if ($language === "") {
            $language = "am";
        }

        if (!in_array($language, ["am", "en"], true)) {
            $rowErrors[] = "language must be am or en";
        }

        if ($activeValue === "") {
            $active = 1;
        } elseif ($activeValue === "0" || $activeValue === "1") {
            $active = (int) $activeValue;
        } else {
            $active = 1;
            $rowErrors[] = "active must be 1 or 0";
        }

        $chapterId = null;
        $lessonId = null;
        $subjectId = $defaultSubjectId;

        if (isset($headerMap["subject_id"])) {
            $subjectCandidate = $get(["subject_id"], "");

            if ($subjectCandidate !== "") {
                if (!ctype_digit($subjectCandidate)) {
                    $rowErrors[] = "subject_id must be a valid numeric ID";
                } else {
                    $subjectId = (int) $subjectCandidate;
                }
            }
        }

        if ($unitValue !== "") {
            $chapterStmt = $pdo->prepare("
                SELECT id
                FROM chapters
                WHERE subject_id = ?
                  AND chapter_number = ?
                LIMIT 1
            ");

            $chapterStmt->execute([
                $subjectId,
                $unitValue
            ]);

            $chapter = $chapterStmt->fetch(PDO::FETCH_ASSOC);

            if (!$chapter) {
                $rowErrors[] = "unit '" . $unitValue . "' was not found for the selected subject";
            } else {
                $chapterId = (int) $chapter["id"];

                if ($lessonValue !== "") {
                    $lessonStmt = $pdo->prepare("
                        SELECT id
                        FROM lessons
                        WHERE chapter_id = ?
                          AND lesson_number = ?
                        LIMIT 1
                    ");

                    $lessonStmt->execute([
                        $chapterId,
                        $lessonValue
                    ]);

                    $lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$lesson) {
                        $rowErrors[] = "lesson '" . $lessonValue . "' was not found inside unit '" . $unitValue . "'";
                    } else {
                        $lessonId = (int) $lesson["id"];
                    }
                }
            }
        }

        $questionKey = md5(
            strtolower(trim($questionText)) . "|" .
            $subjectId . "|" .
            ($language ?: "am")
        );

        if (isset($seenQuestions[$questionKey])) {
            $rowErrors[] = "duplicate question in this Excel file (also row " . $seenQuestions[$questionKey] . ")";
        } else {
            $seenQuestions[$questionKey] = $excelRowNumber;
        }

        if (empty($rowErrors)) {
            $preparedRows[] = [
                "subject_id" => $subjectId,
                "chapter_id" => $chapterId,
                "lesson_id" => $lessonId,
                "question_text" => $questionText,
                "difficulty" => $difficulty,
                "language" => $language,
                "explanation" => $explanation,
                "active" => $active,
                "options" => [
                    ["label" => "A", "text" => $optionA, "correct" => $correct === "A"],
                    ["label" => "B", "text" => $optionB, "correct" => $correct === "B"],
                    ["label" => "C", "text" => $optionC, "correct" => $correct === "C"],
                    ["label" => "D", "text" => $optionD, "correct" => $correct === "D"]
                ]
            ];

            if ($optionE !== "") {
                $preparedRows[count($preparedRows) - 1]["options"][] = [
                    "label" => "E",
                    "text" => $optionE,
                    "correct" => $correct === "E"
                ];
            }

            if ($optionF !== "") {
                $preparedRows[count($preparedRows) - 1]["options"][] = [
                    "label" => "F",
                    "text" => $optionF,
                    "correct" => $correct === "F"
                ];
            }
        } else {
            $errors[] = [
                "row" => $excelRowNumber,
                "errors" => $rowErrors
            ];
        }
    }

    if (!empty($errors)) {
        sendJson([
            "success" => false,
            "message" => "Excel validation failed. Nothing was imported.",
            "errors" => $errors
        ], 400);
    }

    $dryRun = isset($_POST["dry_run"]) && (string) $_POST["dry_run"] === "1";

    if ($dryRun) {
        sendJson([
            "success" => true,
            "dry_run" => true,
            "message" => "Excel validation passed. Nothing was saved.",
            "row_count" => count($preparedRows)
        ]);
    }

    try {
        $pdo->beginTransaction();

        $questionStmt = $pdo->prepare("
            INSERT INTO questions (
                chapter_id,
                lesson_id,
                question_text,
                difficulty,
                language,
                explanation,
                active
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $optionStmt = $pdo->prepare("
            INSERT INTO question_options (
                question_id,
                option_label,
                option_text,
                is_correct
            ) VALUES (?, ?, ?, ?)
        ");

        $imported = 0;
        $skippedDuplicates = [];

        foreach ($preparedRows as $prepared) {
            $duplicateStmt = $pdo->prepare("
                SELECT q.id
                FROM questions q
                INNER JOIN chapters c
                    ON c.id = q.chapter_id
                INNER JOIN subjects s
                    ON s.id = c.subject_id
                WHERE s.id = ?
                  AND q.question_text = ?
                  AND q.language = ?
                LIMIT 1
            ");

            $duplicateStmt->execute([
                $prepared["subject_id"],
                $prepared["question_text"],
                $prepared["language"]
            ]);

            if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
                $skippedDuplicates[] = $prepared["question_text"];
                continue;
            }

            $questionStmt->execute([
                $prepared["chapter_id"],
                $prepared["lesson_id"],
                $prepared["question_text"],
                $prepared["difficulty"],
                $prepared["language"],
                $prepared["explanation"],
                $prepared["active"]
            ]);

            $questionId = (int) $pdo->lastInsertId();

            foreach ($prepared["options"] as $option) {
                $optionStmt->execute([
                    $questionId,
                    $option["label"],
                    $option["text"],
                    $option["correct"] ? 1 : 0
                ]);
            }

            $imported++;
        }

        $pdo->commit();

        sendJson([
            "success" => true,
            "message" => "Excel question bank import completed.",
            "rows_validated" => count($preparedRows),
            "questions_imported" => $imported,
            "duplicates_skipped" => count($skippedDuplicates),
            "skipped_questions" => $skippedDuplicates
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        sendJson([
            "success" => false,
            "message" => "The Excel import failed. Nothing was saved.",
            "error" => $e->getMessage()
        ], 500);
    }
}


function questionImportNormalizeHeader($header)
{
    $header = trim((string) $header);
    $header = preg_replace('/^\\xEF\\xBB\\xBF/', '', $header);
    $header = strtolower($header);
    $header = preg_replace('/\\s+/', '_', $header);
    return trim($header, "_ ");
}


function questionImportColumnNumberFromReference($reference)
{
    preg_match('/([A-Z]+)[0-9]+/i', $reference, $matches);

    if (!isset($matches[1])) {
        return 0;
    }

    $letters = strtoupper($matches[1]);
    $number = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $number = ($number * 26) + (ord($letters[$i]) - 64);
    }

    return $number - 1;
}


function questionImportReadXlsx($filePath)
{
    $zip = new ZipArchive();

    if ($zip->open($filePath) !== true) {
        throw new RuntimeException("The workbook could not be opened.");
    }

    $workbookXml = $zip->getFromName("xl/workbook.xml");
    $relsXml = $zip->getFromName("xl/_rels/workbook.xml.rels");

    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        throw new RuntimeException("Invalid Excel workbook structure.");
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);

    if ($workbook === false || $rels === false) {
        $zip->close();
        throw new RuntimeException("Could not parse Excel workbook XML.");
    }

    $workbookNamespaces = $workbook->getNamespaces(true);
    $relsNamespaces = $rels->getNamespaces(true);

    $mainNs = $workbookNamespaces["main"] ?? null;
    $relNs = $workbookNamespaces["r"] ?? "http://schemas.openxmlformats.org/officeDocument/2006/relationships";

    $workbook->registerXPathNamespace("x", $mainNs ?: "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
    $workbook->registerXPathNamespace("r", $relNs);

    $relationshipMap = [];

    foreach ($rels->Relationship as $relationship) {
        $id = (string) $relationship["Id"];
        $target = (string) $relationship["Target"];

        if ($id !== "") {
            $target = ltrim($target, "/");

            if (!str_starts_with($target, "xl/")) {
                $target = "xl/" . $target;
            }

            $relationshipMap[$id] = $target;
        }
    }

    $sheetPath = null;
    $sheetName = null;

    $sheets = $workbook->xpath('//x:sheets/x:sheet');

    foreach ($sheets ?: [] as $sheet) {
        $name = (string) $sheet["name"];
        $rid = (string) $sheet->attributes($relNs)["id"];

        if (strcasecmp(trim($name), "Questions") === 0) {
            $sheetName = $name;

            if (isset($relationshipMap[$rid])) {
                $sheetPath = $relationshipMap[$rid];
            }

            break;
        }
    }

    if ($sheetPath === null) {
        // Fallback to the first worksheet if a Questions sheet was renamed.
        $firstSheet = $sheets[0] ?? null;

        if ($firstSheet !== null) {
            $sheetName = (string) $firstSheet["name"];
            $rid = (string) $firstSheet->attributes($relNs)["id"];
            $sheetPath = $relationshipMap[$rid] ?? null;
        }
    }

    if ($sheetPath === null) {
        $zip->close();
        throw new RuntimeException("Could not find the Questions worksheet.");
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName("xl/sharedStrings.xml");

    if ($sharedStringsXml !== false) {
        $shared = simplexml_load_string($sharedStringsXml);

        if ($shared !== false) {
            $sharedNs = $shared->getNamespaces(true);
            $shared->registerXPathNamespace(
                "x",
                $sharedNs["main"] ?? "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
            );

            $items = $shared->xpath("//x:si") ?: [];

            foreach ($items as $item) {
                $textParts = $item->xpath(".//x:t") ?: [];
                $value = "";

                foreach ($textParts as $textPart) {
                    $value .= (string) $textPart;
                }

                $sharedStrings[] = $value;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException("Could not read the Questions worksheet.");
    }

    $sheet = simplexml_load_string($sheetXml);

    if ($sheet === false) {
        throw new RuntimeException("Could not parse the Questions worksheet.");
    }

    $sheetNamespaces = $sheet->getNamespaces(true);
    $sheet->registerXPathNamespace(
        "x",
        $sheetNamespaces["main"] ?? "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    );

    $rowNodes = $sheet->xpath("//x:sheetData/x:row") ?: [];
    $rows = [];

    foreach ($rowNodes as $rowNode) {
        $cells = $rowNode->xpath("./x:c") ?: [];
        $row = [];

        foreach ($cells as $cell) {
            $ref = (string) $cell["r"];
            $columnIndex = questionImportColumnNumberFromReference($ref);
            $type = (string) $cell["t"];

            $value = "";

            if ($type === "inlineStr") {
                $textNodes = $cell->xpath("./x:is//x:t") ?: [];

                foreach ($textNodes as $textNode) {
                    $value .= (string) $textNode;
                }
            } else {
                $valueNode = $cell->xpath("./x:v");
                $rawValue = isset($valueNode[0]) ? (string) $valueNode[0] : "";

                if ($type === "s") {
                    $sharedIndex = (int) $rawValue;
                    $value = $sharedStrings[$sharedIndex] ?? "";
                } elseif ($type === "b") {
                    $value = $rawValue === "1" ? "1" : "0";
                } else {
                    $value = $rawValue;
                }
            }

            $row[$columnIndex] = $value;
        }

        if (!empty($row)) {
            ksort($row);
            $maxIndex = max(array_keys($row));
            $normalizedRow = array_fill(0, $maxIndex + 1, "");

            foreach ($row as $index => $value) {
                $normalizedRow[$index] = $value;
            }

            $rows[] = $normalizedRow;
        }
    }

    return $rows;
}
