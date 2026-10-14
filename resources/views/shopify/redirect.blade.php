<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Redirecting...</title>
</head>
<body>
    <script>
        // If we're inside Shopify admin's iframe, redirect the TOP window
        // (keeps the user in the same tab). If we're already top-level
        // (e.g. testing directly), just redirect normally.
        var redirectUrl = @json($url);

        if (window.top === window.self) {
            window.location.href = redirectUrl;
        } else {
            window.top.location.href = redirectUrl;
        }
    </script>
    <noscript>
        <p>Redirecting... if nothing happens, <a href="{{ $url }}">click here</a>.</p>
    </noscript>
</body>
</html>
