<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Checking your browser</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6f7f9; color: #1f2933; }
        main { max-width: 420px; padding: 32px; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { margin: 0 0 20px; color: #52606d; line-height: 1.5; }
        .error { color: #b42318; }
        button { margin-top: 16px; padding: 8px 16px; border: 0; border-radius: 6px; background: #1f2933; color: #fff; font: inherit; cursor: pointer; }
        @media (prefers-color-scheme: dark) { body { background: #111418; color: #e4e7eb; } p { color: #9aa5b1; } button { background: #e4e7eb; color: #111418; } }
    </style>
</head>
<body>
<main>
    <h1>One moment</h1>
    @if ($failed)
        <p class="error">The check didn't succeed. Please try again.</p>
    @else
        <p>Please confirm you're human to continue.</p>
    @endif
    <form id="wt-shield-form" method="POST" action="{{ $action }}">
        <input type="hidden" name="return" value="{{ $returnTo }}">
        <div class="cf-turnstile" data-sitekey="{{ $siteKey }}" data-callback="wtShieldSolved"></div>
        <noscript><p>JavaScript is required to continue.</p></noscript>
        <button type="submit">Continue</button>
    </form>
</main>
<script>
    function wtShieldSolved() { document.getElementById('wt-shield-form').submit(); }
</script>
</body>
</html>
