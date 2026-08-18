<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Document Share</title>
    <style>
        html, body { height: 100%; margin: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif;
            background: #09090b;
            color: #f4f4f5;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            width: 100%;
            max-width: 28rem;
            background: rgba(24, 24, 27, 0.7);
            border: 1px solid rgba(39, 39, 42, 0.8);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4);
        }
        .header { text-align: center; margin-bottom: 1.5rem; }
        .icon {
            width: 3rem;
            height: 3rem;
            margin: 0 auto 0.75rem;
            border-radius: 1rem;
            border: 1px solid #3f3f46;
            background: #27272a;
            color: #a1a1aa;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
        .muted { color: #a1a1aa; font-size: 0.75rem; }
        .file {
            border-top: 1px solid rgba(39, 39, 42, 0.8);
            padding-top: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        .error {
            background: rgb(239 68 68 / 0.1);
            border: 1px solid rgb(239 68 68 / 0.2);
            color: #f87171;
            font-size: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        label { display: block; font-size: 0.75rem; color: #a1a1aa; margin-bottom: 0.5rem; }
        input[type="password"] {
            width: 100%;
            box-sizing: border-box;
            background: rgb(9 9 11 / 0.5);
            border: 1px solid #27272a;
            border-radius: 1rem;
            padding: 0.75rem 1rem;
            color: #f4f4f5;
        }
        button {
            width: 100%;
            margin-top: 1rem;
            background: #f4f4f5;
            color: #09090b;
            border: 0;
            border-radius: 1rem;
            padding: 0.75rem 1rem;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="24" height="24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
            </div>
            <h1>Secure Document Share</h1>
            <p class="muted">This link is password protected</p>
        </div>

        <div class="file">
            <p>{{ $document_name }}</p>
            @if($file_size)
                <p class="muted">{{ $file_size }}</p>
            @endif
        </div>

        @if($error)
        <div class="error">{{ $error }}</div>
        @endif

        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <label for="password">Password</label>
            <input type="password" name="password" id="password" required autofocus placeholder="Enter link password">
            <button type="submit">Decrypt &amp; Download</button>
        </form>
    </div>
</body>
</html>
