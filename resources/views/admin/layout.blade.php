<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — Video Uploader</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
            <div class="flex items-center gap-6">
                <span class="text-lg font-bold text-gray-800">Video Uploader</span>
                <a href="{{ route('admin.dashboard') }}"
                   class="text-sm font-medium {{ request()->routeIs('admin.dashboard') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-600' }}">
                    Dashboard
                </a>
                <a href="{{ route('admin.upload') }}"
                   class="text-sm font-medium {{ request()->routeIs('admin.upload') ? 'text-indigo-600' : 'text-gray-600 hover:text-indigo-600' }}">
                    Upload Video
                </a>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="text-sm text-gray-600 hover:text-red-600 font-medium">
                    Logout
                </button>
            </form>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @yield('content')
</main>

</body>
</html>
