<?php
/**
 * Standalone 404 (Not Found) page. See errors/403.php for why this is
 * deliberately self-contained rather than routed through the app layer.
 */
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="text-center" style="max-width: 480px;">
            <div class="display-1 fw-bold text-primary mb-2">404</div>
            <h1 class="h4 mb-3">Page Not Found</h1>
            <p class="text-muted mb-4">The page you're looking for doesn't exist or may have moved.</p>
            <a href="./" class="btn btn-primary px-4">Go Back</a>
        </div>
    </div>
</body>
</html>
