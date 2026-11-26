<?php

// =========================================
// FELEGE KIDUSAN PHP ROUTER
// =========================================

$backendPath = __DIR__;
$projectPath = dirname(__DIR__);

$frontendPath =
    $projectPath .
    DIRECTORY_SEPARATOR .
    'frontend';

$uploadPath =
    $projectPath .
    DIRECTORY_SEPARATOR .
    'uploads';

$uri = parse_url(
    $_SERVER['REQUEST_URI'],
    PHP_URL_PATH
);

if ($uri === false || $uri === null) {
    $uri = '/';
}


// =========================================
// API ROUTES
// =========================================

if (str_starts_with($uri, '/api/')) {

    require $backendPath .
        DIRECTORY_SEPARATOR .
        'api.php';

    exit;
}


// =========================================
// UPLOAD FILES
// =========================================
// Example:
// /uploads/task_12345.pdf
// =========================================

if (str_starts_with($uri, '/uploads/')) {
    // Uploaded files are only for signed-in users.
    require_once $backendPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'security.php';
    if (fk_current_user() === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Please sign in.';
        exit;
    }
    header('X-Content-Type-Options: nosniff');

    $requestPath = substr(
        $uri,
        strlen('/uploads/')
    );

    $requestPath = ltrim(
        $requestPath,
        '/'
    );

    $filePath =
        $uploadPath .
        DIRECTORY_SEPARATOR .
        $requestPath;

    $realUploadPath = realpath(
        $uploadPath
    );

    $realFilePath = realpath(
        $filePath
    );

    // =========================================
    // SECURITY CHECK
    // =========================================

    if (
        $realUploadPath === false ||
        $realFilePath === false ||
        !str_starts_with(
            $realFilePath,
            $realUploadPath .
            DIRECTORY_SEPARATOR
        ) ||
        !is_file($realFilePath)
    ) {

        http_response_code(404);

        header(
            'Content-Type: text/plain; charset=utf-8'
        );

        echo 'File not found.';

        exit;
    }


    // =========================================
    // FILE CONTENT TYPE
    // =========================================

    $extension = strtolower(
        pathinfo(
            $realFilePath,
            PATHINFO_EXTENSION
        )
    );

    $contentTypes = [

        'pdf' =>
            'application/pdf',

        'jpg' =>
            'image/jpeg',

        'jpeg' =>
            'image/jpeg',

        'png' =>
            'image/png',

        'webp' =>
            'image/webp',

        'doc' =>
            'application/msword',

        'docx' =>
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $contentType =
        $contentTypes[$extension]
        ?? 'application/octet-stream';


    // =========================================
    // SERVE FILE
    // =========================================

    header(
        'Content-Type: ' .
        $contentType
    );

    header(
        'Content-Length: ' .
        filesize($realFilePath)
    );

    readfile($realFilePath);

    exit;
}


// =========================================
// FRONTEND FILES
// =========================================

$requestPath = ltrim(
    $uri,
    '/'
);

if ($requestPath === '') {
    $requestPath = 'index.html';
}

$filePath =
    $frontendPath .
    DIRECTORY_SEPARATOR .
    $requestPath;

$realFrontendPath = realpath(
    $frontendPath
);

$realFilePath = realpath(
    $filePath
);


// =========================================
// SECURITY CHECK
// =========================================

if (
    $realFrontendPath === false ||
    $realFilePath === false ||
    !str_starts_with(
        $realFilePath,
        $realFrontendPath .
        DIRECTORY_SEPARATOR
    ) ||
    !is_file($realFilePath)
) {

    http_response_code(404);

    header(
        'Content-Type: text/plain; charset=utf-8'
    );

    echo 'Page not found.';

    exit;
}


// =========================================
// FRONTEND CONTENT TYPE
// =========================================

$extension = strtolower(
    pathinfo(
        $realFilePath,
        PATHINFO_EXTENSION
    )
);

$contentTypes = [

    'html' =>
        'text/html; charset=utf-8',

    'css' =>
        'text/css; charset=utf-8',

    'js' =>
        'text/javascript; charset=utf-8',

    'json' =>
        'application/json; charset=utf-8',

    'png' =>
        'image/png',

    'jpg' =>
        'image/jpeg',

    'jpeg' =>
        'image/jpeg',

    'gif' =>
        'image/gif',

    'svg' =>
        'image/svg+xml',

    'pdf' =>
        'application/pdf'
];

$contentType =
    $contentTypes[$extension]
    ?? 'application/octet-stream';

header(
    'Content-Type: ' .
    $contentType
);

readfile($realFilePath);