@extends('admin.layout')

@section('title', 'Upload Video')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Upload Video</h1>

    {{-- Upload Form --}}
    <div id="upload-form" class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
        <label class="block mb-4">
            <span class="text-sm font-medium text-gray-700 mb-2 block">Select Video File</span>
            <input type="file" id="video-file" accept="video/*"
                   class="block w-full text-sm text-gray-600 border border-gray-300 rounded-lg cursor-pointer
                          file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0
                          file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700
                          hover:file:bg-indigo-100 focus:outline-none">
        </label>

        <div id="file-info" class="hidden mb-4 text-sm text-gray-600">
            <span id="file-name" class="font-medium"></span>
            <span id="file-size" class="text-gray-400 ml-2"></span>
        </div>

        <button id="upload-btn" disabled
                class="w-full py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-lg
                       hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition">
            Start Upload
        </button>
    </div>

    {{-- Progress Section --}}
    <div id="progress-section" class="hidden mt-6 bg-white rounded-xl shadow-sm border border-gray-200 p-8">
        <div class="flex items-center justify-between mb-3">
            <span id="status-label" class="text-sm font-semibold text-gray-700">Uploading chunks…</span>
            <span id="percent-label" class="text-sm font-bold text-indigo-600">0%</span>
        </div>

        <div class="w-full bg-gray-200 rounded-full h-3 mb-4 overflow-hidden">
            <div id="progress-bar"
                 class="bg-indigo-500 h-3 rounded-full transition-all duration-300 ease-out"
                 style="width: 0%"></div>
        </div>

        <p id="chunk-info" class="text-xs text-gray-500 mb-4"></p>

        {{-- Success state --}}
        <div id="success-msg" class="hidden flex items-center gap-2 text-green-600 text-sm font-medium">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Upload complete! Redirecting to dashboard…
        </div>

        {{-- Error state --}}
        <div id="error-msg" class="hidden">
            <p class="text-red-600 text-sm font-medium mb-3" id="error-text"></p>
            <button id="retry-btn"
                    class="text-sm text-indigo-600 hover:text-indigo-800 font-medium underline">
                Retry Upload
            </button>
        </div>

        {{-- Pause / Continue / Cancel --}}
        <div class="mt-4 flex items-center gap-4">
            <button id="pause-btn"
                    class="hidden px-4 py-1.5 bg-yellow-100 text-yellow-700 text-xs font-medium rounded-lg hover:bg-yellow-200 transition">
                ⏸ Pause
            </button>
            <button id="continue-btn"
                    class="hidden px-4 py-1.5 bg-indigo-100 text-indigo-700 text-xs font-medium rounded-lg hover:bg-indigo-200 transition">
                ▶ Continue
            </button>
            <button id="cancel-btn"
                    class="text-xs text-gray-400 hover:text-red-500 transition">
                Cancel Upload
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const uploadConfig = @json($uploadConfig ?? []);
    const CHUNK_SIZE      = uploadConfig.chunk_size_bytes ?? (10 * 1024 * 1024);
    const MAX_FILE_SIZE   = uploadConfig.max_file_size_bytes ?? (300 * 1024 * 1024);
    const MAX_RETRIES     = uploadConfig.max_retries ?? 3;
    const POLL_INTERVAL_MS = uploadConfig.poll_interval_ms ?? 2000;
    const STORAGE_KEY     = 'video_upload_id';

    let uploadId       = null;
    let file           = null;
    let totalChunks    = 0;
    let sentChunks     = new Set();
    let polling        = null;
    let cancelled      = false;
    let paused         = false;
    let chunksInFlight = false; // true only while chunks are being sent to backend

    const fileInput    = document.getElementById('video-file');
    const uploadBtn    = document.getElementById('upload-btn');
    const uploadForm   = document.getElementById('upload-form');
    const progressSec  = document.getElementById('progress-section');
    const progressBar  = document.getElementById('progress-bar');
    const percentLabel = document.getElementById('percent-label');
    const statusLabel  = document.getElementById('status-label');
    const chunkInfo    = document.getElementById('chunk-info');
    const fileInfo     = document.getElementById('file-info');
    const fileName     = document.getElementById('file-name');
    const fileSize     = document.getElementById('file-size');
    const successMsg   = document.getElementById('success-msg');
    const errorMsg     = document.getElementById('error-msg');
    const errorText    = document.getElementById('error-text');
    const retryBtn     = document.getElementById('retry-btn');
    const cancelBtn    = document.getElementById('cancel-btn');
    const pauseBtn     = document.getElementById('pause-btn');
    const continueBtn  = document.getElementById('continue-btn');

    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Warn before leaving while chunks are still in-flight
    window.addEventListener('beforeunload', (e) => {
        if (!chunksInFlight) return;
        e.preventDefault();
        e.returnValue = 'Chunks are still uploading. If you leave now the upload will be cancelled.';
        return e.returnValue;
    });

    // If the user confirms "Leave site", fire a beacon to mark the record as cancelled.
    // sendBeacon() is guaranteed to complete even as the page unloads.
    // It sends session cookies automatically, so auth works.
    // CSRF is included in the FormData body (_token) which Laravel accepts.
    window.addEventListener('pagehide', () => {
        if (!chunksInFlight || !uploadId) return;
        const form = new FormData();
        form.append('_token', csrfToken);
        navigator.sendBeacon(`/api/v1/upload/${uploadId}/abandon`, form);
    });

    function formatBytes(bytes) {
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    fileInput.addEventListener('change', () => {
        file = fileInput.files[0];
        if (!file) { uploadBtn.disabled = true; return; }

        // --- Frontend size validation (temporarily disabled for backend validation testing) ---
        // if (file.size > MAX_FILE_SIZE) {
        //     const humanSize = formatBytes(file.size);
        //     fileInput.value  = '';
        //     file             = null;
        //     uploadBtn.disabled = true;
        //     fileInfo.classList.add('hidden');
        //     document.getElementById('size-error')?.remove();
        //     const err       = document.createElement('p');
        //     err.id          = 'size-error';
        //     err.className   = 'mt-2 text-sm text-red-600 font-medium';
        //     err.textContent = `File too large (${humanSize}). Maximum allowed size is 300 MB.`;
        //     fileInput.closest('label').after(err);
        //     return;
        // }
        // document.getElementById('size-error')?.remove();
        // --- end frontend size validation ---

        fileName.textContent = file.name;
        fileSize.textContent = '(' + formatBytes(file.size) + ')';
        fileInfo.classList.remove('hidden');
        uploadBtn.disabled = false;
    });

    uploadBtn.addEventListener('click', startUpload);
    retryBtn.addEventListener('click', () => { errorMsg.classList.add('hidden'); startUpload(); });
    cancelBtn.addEventListener('click', cancelUpload);

    pauseBtn.addEventListener('click', () => {
        paused = true;
        pauseBtn.classList.add('hidden');
        continueBtn.classList.remove('hidden');
        statusLabel.textContent = 'Paused — click Continue to resume…';
        progressBar.classList.replace('bg-indigo-500', 'bg-yellow-400');
    });

    continueBtn.addEventListener('click', () => {
        paused = false;
        continueBtn.classList.add('hidden');
        pauseBtn.classList.remove('hidden');
        statusLabel.textContent = 'Uploading chunks…';
        progressBar.classList.replace('bg-yellow-400', 'bg-indigo-500');
    });

    async function startUpload() {
        if (!file) return;
        cancelled = false;
        paused    = false;
        sentChunks.clear();
        totalChunks = Math.ceil(file.size / CHUNK_SIZE);

        uploadForm.classList.add('hidden');
        progressSec.classList.remove('hidden');
        successMsg.classList.add('hidden');
        errorMsg.classList.add('hidden');
        pauseBtn.classList.remove('hidden');
        continueBtn.classList.add('hidden');
        cancelBtn.classList.remove('hidden');
        setProgress(0);

        try {
            // --- INIT (with resume support) ---
            statusLabel.textContent = 'Initialising upload…';
            const storedId = localStorage.getItem(STORAGE_KEY);
            const initRes  = await apiFetch('/api/v1/upload/init', 'POST', {
                original_name: file.name,
                file_size:     file.size,
                total_chunks:  totalChunks,
                upload_id:     storedId || null,   // pass existing id for resume
            });

            uploadId = initRes.data.upload_id;
            localStorage.setItem(STORAGE_KEY, uploadId);

            if (initRes.data.resumed) {
                statusLabel.textContent = 'Resuming upload…';
            }

            // Mark already-received chunks so we skip them
            (initRes.data.received_chunks || []).forEach(i => sentChunks.add(i));

            // --- SEND CHUNKS ---
            chunksInFlight = true; // 🔒 block navigation from here
            statusLabel.textContent = 'Uploading chunks…';
            for (let i = 0; i < totalChunks; i++) {
                if (cancelled) { chunksInFlight = false; return; }

                // Wait while paused — poll every 300ms until resumed or cancelled
                while (paused) {
                    await sleep(300);
                    if (cancelled) { chunksInFlight = false; return; }
                }

                if (sentChunks.has(i)) {
                    setProgress(Math.round(((sentChunks.size) / totalChunks) * 100));
                    continue;
                }
                await sendChunkWithRetry(i);
                sentChunks.add(i);
                setProgress(Math.round((sentChunks.size / totalChunks) * 100));
                chunkInfo.textContent = `Chunk ${sentChunks.size} of ${totalChunks}`;
            }

            // --- FINALIZE ---
            statusLabel.textContent = 'Finalising…';
            pauseBtn.classList.add('hidden');    // no point pausing during finalize
            continueBtn.classList.add('hidden');
            await apiFetch('/api/v1/upload/finalize', 'POST', { upload_id: uploadId });
            chunksInFlight = false; // 🔓 all chunks on server — safe to navigate away

            // --- POLL STATUS ---
            statusLabel.textContent = 'Processing on server…';
            setProgress(100);
            pollStatus();

        } catch (err) {
            chunksInFlight = false; // 🔓 release on error so user isn't trapped
            paused = false;
            pauseBtn.classList.add('hidden');
            continueBtn.classList.add('hidden');
            showError(err.message || 'Upload failed.');
        }
    }

    async function sendChunkWithRetry(index) {
        const start = index * CHUNK_SIZE;
        const blob  = file.slice(start, start + CHUNK_SIZE);

        for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
            try {
                const form = new FormData();
                form.append('upload_id',   uploadId);
                form.append('chunk_index', index);
                form.append('chunk',       blob, `chunk_${index}`);

                console.log('[Chunk Upload]', {
                    chunk_index: index,
                    upload_id:   uploadId,
                    blob_size:   blob.size,
                    blob_type:   blob.type,
                });

                const res = await fetch('/api/v1/upload/chunk', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body:    form,
                });

                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    throw new Error(err.message || `HTTP ${res.status}`);
                }
                return;
            } catch (err) {
                if (attempt === MAX_RETRIES) throw err;
                await sleep(1000 * Math.pow(2, attempt));
            }
        }
    }

    function pollStatus() {
        polling = setInterval(async () => {
            try {
                const res    = await apiFetch(`/api/v1/upload/${uploadId}/status`, 'GET');
                const upload = res.data;

                if (upload.status === 'processing') {
                    statusLabel.textContent = 'Checking status from S3…';
                } else if (upload.status === 'completed') {
                    clearInterval(polling);
                    localStorage.removeItem(STORAGE_KEY);
                    statusLabel.textContent = 'Completed!';
                    progressBar.classList.replace('bg-indigo-500', 'bg-green-500');
                    successMsg.classList.remove('hidden');
                    cancelBtn.classList.add('hidden');
                    pauseBtn.classList.add('hidden');
                    continueBtn.classList.add('hidden');
                    setTimeout(() => { window.location.href = '/admin'; }, 2000);
                } else if (upload.status === 'failed') {
                    clearInterval(polling);
                    showError(upload.error_message || 'Processing failed on server.');
                }
            } catch (_) {}
        }, POLL_INTERVAL_MS);
    }

    async function cancelUpload() {
        cancelled = true;
        paused    = false;
        clearInterval(polling);
        pauseBtn.classList.add('hidden');
        continueBtn.classList.add('hidden');
        if (uploadId) {
            await apiFetch(`/api/v1/upload/${uploadId}`, 'DELETE').catch(() => {});
            localStorage.removeItem(STORAGE_KEY);
            uploadId = null;
        }
        progressSec.classList.add('hidden');
        uploadForm.classList.remove('hidden');
        setProgress(0);
        fileInput.value = '';
        uploadBtn.disabled = true;
        fileInfo.classList.add('hidden');
    }

    function setProgress(pct) {
        progressBar.style.width = pct + '%';
        percentLabel.textContent = pct + '%';
    }

    function showError(msg) {
        clearInterval(polling);
        statusLabel.textContent = 'Upload failed.';
        errorText.textContent   = msg;
        errorMsg.classList.remove('hidden');
    }

    async function apiFetch(url, method, body = null) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        };
        if (body && method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, opts);
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.message || `HTTP ${res.status}`);
        }
        return res.json();
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
})();
</script>
@endsection
