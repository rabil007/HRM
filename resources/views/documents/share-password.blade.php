<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Document Share</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
    </style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-[radial-gradient(ellipse_80%_80%_at_50%_-20%,rgba(120,119,198,0.15),rgba(255,255,255,0))]">
    <div class="w-full max-w-md bg-zinc-900/60 backdrop-blur-xl border border-zinc-800/80 rounded-3xl p-8 shadow-2xl space-y-6">
        <div class="flex flex-col items-center text-center space-y-3">
            <div class="h-12 w-12 rounded-2xl bg-zinc-800/80 border border-zinc-700/50 flex items-center justify-center text-zinc-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
            </div>
            <div class="space-y-1">
                <h1 class="text-xl font-semibold text-zinc-100">Secure Document Share</h1>
                <p class="text-xs text-zinc-400">This link is password protected</p>
            </div>
        </div>

        <div class="border-t border-zinc-800/60 pt-4 text-center">
            <p class="text-sm font-medium text-zinc-300">{{ $document_name }}</p>
            @if($file_size)
                <p class="text-xs text-zinc-500 mt-1">{{ $file_size }}</p>
            @endif
        </div>

        @if($error)
        <div class="bg-red-500/10 border border-red-500/20 text-red-400 text-xs px-4 py-3 rounded-2xl text-center">
            {{ $error }}
        </div>
        @endif

        <form method="POST" action="{{ request()->fullUrl() }}" class="space-y-4">
            @csrf
            <div class="space-y-2">
                <label for="password" class="text-xs font-medium text-zinc-400">Password</label>
                <input type="password" name="password" id="password" required autofocus
                    placeholder="Enter link password"
                    class="w-full bg-zinc-950/50 border border-zinc-800 rounded-2xl px-4 py-3 text-sm text-zinc-100 placeholder-zinc-600 focus:outline-none focus:ring-1 focus:ring-zinc-700 focus:border-zinc-700 transition-colors" />
            </div>

            <button type="submit" 
                class="w-full bg-zinc-100 hover:bg-zinc-200 text-zinc-950 font-medium py-3 px-4 rounded-2xl text-sm transition-all shadow-lg hover:shadow-zinc-100/10 flex items-center justify-center gap-2">
                <span>Decrypt & Download</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                </svg>
            </button>
        </form>
    </div>
</body>
</html>
