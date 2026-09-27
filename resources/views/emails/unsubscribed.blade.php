<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Unsubscribed — Batayan</title>
</head>
<body style="margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: #F7EAE0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; color: #5E3122;">
<main style="max-width: 28rem; padding: 2rem; text-align: center;">
    <h1 style="font-family: 'Fraunces', Georgia, serif; color: #1D4533; font-size: 1.75rem; margin: 0 0 0.75rem;">You're unsubscribed</h1>
    <p style="line-height: 1.6; margin: 0 0 1.5rem;">We won't send you any more tips or onboarding emails. Emails about your account, billing, and deadlines will still arrive.</p>
    <a href="{{ rtrim((string) config('app.frontend_url'), '/') }}" style="color: #1D4533; font-weight: 600;">Back to Batayan</a>
</main>
</body>
</html>
