<?php
/**
 * Standalone 500 (Internal Server Error) page. Deliberately self-contained
 * and free of any dynamic error detail — this is the page shown when
 * something has already gone wrong, so it must not depend on the thing
 * that might be broken (DB, app bootstrap), and must never leak stack
 * traces, file paths, or query details to the visitor.
 */
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 Internal Server Error</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="text-center" style="max-width: 480px;">
            <div class="display-1 fw-bold text-primary mb-2">500</div>
            <h1 class="h4 mb-3">Something Went Wrong</h1>
            <p class="text-muted mb-4">An unexpected error occurred. Our team has been notified. Please try again shortly.</p>
            <a href="./" class="btn btn-primary px-4">Go Back</a>
        </div>
    </div>
</body>
</html>
