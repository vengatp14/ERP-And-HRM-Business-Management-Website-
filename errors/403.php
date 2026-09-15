<?php
/**
 * Standalone 403 (Forbidden) page.
 *
 * Deliberately self-contained (no require of app/ bootstrap, config, or
 * DB) so it still renders correctly even if the database is down or the
 * application layer itself is what's failing — the two situations where
 * an error page matters most. In normal operation, 403s are actually
 * produced by the application layer (see app/Helpers/ErrorResponse.php),
 * which is subfolder-deployment-safe; this file exists to satisfy the
 * required project structure and as a static fallback.
 */
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="text-center" style="max-width: 480px;">
            <div class="display-1 fw-bold text-primary mb-2">403</div>
            <h1 class="h4 mb-3">Access Denied</h1>
            <p class="text-muted mb-4">You don't have permission to access this resource.</p>
            <a href="./" class="btn btn-primary px-4">Go Back</a>
        </div>
    </div>
</body>
</html>
