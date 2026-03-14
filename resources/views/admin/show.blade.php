@extends('admin.layout')

@section('title', 'Upload Detail')

@section('content')
{{-- uploadId is the only value passed from the controller --}}
<div id="app" data-upload-id="{{ $uploadId }}">

    {{-- Loading --}}
    <div id="loading-state" class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
        <svg class="mx-auto w-6 h-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
        </svg>
        <p class="mt-3 text-sm text-gray-400">Loading upload details…</p>
    </div>

    {{-- Content (hidden until data loads) --}}
    <div id="content" class="hidden">

        {{-- Top bar --}}
        <div class="mb-6 flex items-center justify-between">
            <a href="{{ route('admin.dashboard') }}" class="text-sm text-indigo-600 hover:underline">&larr; Back to Dashboard</a>
            <a id="download-btn"
               class="hidden inline-flex items-center gap-1.5 px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download from S3
            </a>
        </div>

        {{-- Header card --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 id="original-name" class="text-xl font-bold text-gray-900 mb-1"></h1>
                    <p class="text-sm text-gray-500">Upload ID: <code id="upload-id-display" class="bg-gray-100 px-1 rounded text-xs"></code></p>
                </div>
                <span id="status-badge" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"></span>
            </div>
        </div>

        {{-- Upload info --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Upload Info</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">File Size</dt>
                    <dd id="file-size" class="font-medium text-gray-900"></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Chunks</dt>
                    <dd id="chunks-count" class="font-medium text-gray-900"></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Progress</dt>
                    <dd id="progress-pct" class="font-medium text-gray-900"></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Created At</dt>
                    <dd id="created-at" class="font-medium text-gray-900"></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Last Updated</dt>
                    <dd id="updated-at" class="font-medium text-gray-900"></dd>
                </div>
            </dl>
        </div>

        {{-- Error --}}
        <div id="error-section" class="hidden bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
            <h2 class="text-sm font-semibold text-red-700 mb-2">Error Details</h2>
            <pre id="error-message" class="text-xs text-red-600 whitespace-pre-wrap"></pre>
        </div>

        {{-- Chunks list --}}
        <div id="chunks-section" class="hidden bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 id="chunks-title" class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4"></h2>
            <div id="chunks-list" class="flex flex-wrap gap-1.5"></div>
        </div>

    </div>
</div>

<script>
(function () {
    const POLL_MS   = 2000;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const uploadId  = document.getElementById('app').dataset.uploadId;

    let pollTimer = null;

    const badgeColors = {
        pending:    'bg-gray-100 text-gray-700',
        uploading:  'bg-blue-100 text-blue-700',
        processing: 'bg-yellow-100 text-yellow-700',
        completed:  'bg-green-100 text-green-700',
        failed:     'bg-red-100 text-red-700',
        cancelled:  'bg-gray-100 text-gray-400',
    };

    const spinnerSvg = `<svg class="ml-1 w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
    </svg>`;

    // ── Initial load ──────────────────────────────────────────────────────────

    async function load() {
        const response = await apiFetch(`/api/v1/admin/uploads/${uploadId}`);
        render(response.data);

        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('content').classList.remove('hidden');

        if (['pending', 'uploading', 'processing'].includes(response.data.status)) {
            startPolling();
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    function render(data) {
        document.getElementById('original-name').textContent    = data.original_name;
        document.getElementById('upload-id-display').textContent = data.upload_id;
        document.getElementById('file-size').textContent        = data.file_size_human;
        document.getElementById('chunks-count').textContent     = `${data.received_chunks} / ${data.total_chunks}`;
        document.getElementById('progress-pct').textContent     = `${data.progress_percent}%`;
        document.getElementById('created-at').textContent       = data.created_at;
        document.getElementById('updated-at').textContent       = data.updated_at;

        // Status badge
        const badge = document.getElementById('status-badge');
        badge.className = `inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${badgeColors[data.status] || 'bg-gray-100 text-gray-700'}`;
        badge.innerHTML = cap(data.status) + (['processing', 'uploading'].includes(data.status) ? spinnerSvg : '');

        // Download button (when completed)
        const dlBtn = document.getElementById('download-btn');
        if (data.status === 'completed') {
            dlBtn.href = `/api/v1/admin/${uploadId}/download`;
            dlBtn.classList.remove('hidden');
        } else {
            dlBtn.classList.add('hidden');
        }

        // Error
        const errSection = document.getElementById('error-section');
        if (data.error_message) {
            document.getElementById('error-message').textContent = data.error_message;
            errSection.classList.remove('hidden');
        } else {
            errSection.classList.add('hidden');
        }

        // Chunks list
        const chunksSection = document.getElementById('chunks-section');
        if (data.chunks && data.chunks.length > 0) {
            document.getElementById('chunks-title').textContent = `Received Chunks (${data.chunks.length})`;
            document.getElementById('chunks-list').innerHTML = data.chunks
                .map(c => `<span class="inline-flex items-center px-2 py-0.5 rounded bg-green-100 text-green-700 text-xs font-mono">#${c.chunk_index}</span>`)
                .join('');
            chunksSection.classList.remove('hidden');
        } else {
            chunksSection.classList.add('hidden');
        }
    }

    // ── Polling (live status updates) ─────────────────────────────────────────

    function startPolling() {
        pollTimer = setInterval(async () => {
            try {
                const res    = await apiFetch(`/api/v1/upload/${uploadId}/status`);
                const upload = res.data;

                // Update only the fields that change during processing
                const badge = document.getElementById('status-badge');
                badge.className = `inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${badgeColors[upload.status] || 'bg-gray-100 text-gray-700'}`;
                badge.innerHTML = cap(upload.status) + (['processing', 'uploading'].includes(upload.status) ? spinnerSvg : '');

                document.getElementById('updated-at').textContent = new Date().toLocaleString();

                if (upload.status === 'completed' || upload.status === 'failed') {
                    clearInterval(pollTimer);
                    const full = await apiFetch(`/api/v1/admin/uploads/${uploadId}`);
                    render(full.data);
                }
            } catch (_) {}
        }, POLL_MS);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    async function apiFetch(url) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    load();
})();
</script>
@endsection
