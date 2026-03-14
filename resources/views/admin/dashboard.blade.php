@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Uploaded Videos</h1>
    <a href="{{ route('admin.upload') }}"
       class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
        + Upload Video
    </a>
</div>

{{-- Loading skeleton --}}
<div id="loading-state" class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
    <svg class="mx-auto w-6 h-6 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
    </svg>
    <p class="mt-3 text-sm text-gray-400">Loading uploads…</p>
</div>

{{-- Empty state --}}
<div id="empty-state" class="hidden bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center">
    <p class="text-gray-500 text-lg">No videos uploaded yet.</p>
    <a href="{{ route('admin.upload') }}" class="mt-4 inline-block text-indigo-600 hover:underline text-sm">
        Upload your first video
    </a>
</div>

{{-- Table --}}
<div id="table-state" class="hidden">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Filename</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Size</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Uploaded At</th>
                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody id="uploads-tbody" class="divide-y divide-gray-100"></tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div id="pagination" class="mt-4 flex items-center justify-between text-sm text-gray-600"></div>
</div>

<script>
(function () {
    const POLL_MS   = 3000;
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const loadingEl = document.getElementById('loading-state');
    const emptyEl   = document.getElementById('empty-state');
    const tableEl   = document.getElementById('table-state');
    const tbody     = document.getElementById('uploads-tbody');
    const paginEl   = document.getElementById('pagination');

    let currentPage = 1;
    let pollTimer   = null;
    let watching    = new Set();

    const badgeColors = {
        pending:    'bg-gray-100 text-gray-700',
        uploading:  'bg-blue-100 text-blue-700',
        processing: 'bg-yellow-100 text-yellow-700',
        completed:  'bg-green-100 text-green-700',
        failed:     'bg-red-100 text-red-700',
        cancelled:  'bg-gray-100 text-gray-400',
    };

    const spinnerSvg = `<svg class="ml-1 w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
    </svg>`;

    // ── Fetch & render ────────────────────────────────────────────────────────

    async function loadUploads(page) {
        try {
            const res = await apiFetch(`/api/v1/admin/uploads?page=${page}`);
            // ResourceCollection shape: { data: [...], links: {...}, meta: {...} }
            currentPage = res.meta.current_page;

            show(loadingEl, false);

            if (res.data.length === 0) {
                show(emptyEl, true);
                show(tableEl, false);
                return;
            }

            show(emptyEl, false);
            show(tableEl, true);
            renderRows(res.data);
            renderPagination(res);
            startPolling(res.data);

        } catch (e) {
            show(loadingEl, false);
            show(emptyEl, true);
        }
    }

    function renderRows(uploads) {
        tbody.innerHTML = uploads.map(u => `
            <tr class="hover:bg-gray-50 transition" id="row-${u.upload_id}" data-status="${u.status}">
                <td class="px-6 py-4 font-medium text-gray-900 max-w-xs truncate">${esc(u.original_name)}</td>
                <td class="px-6 py-4">${badgeHtml(u.status)}</td>
                <td class="px-6 py-4 text-gray-600">${esc(u.file_size_human || '—')}</td>
                <td class="px-6 py-4 text-gray-500">${esc(u.created_at)}</td>
                <td class="px-6 py-4 text-right">
                    <div class="actions flex items-center justify-end gap-3">
                        <a href="/admin/${u.upload_id}" class="text-indigo-600 hover:text-indigo-800 font-medium text-xs">View</a>
                        ${u.status === 'completed'
                            ? `<a href="/api/v1/admin/${u.upload_id}/download" class="download-btn text-green-600 hover:text-green-800 font-medium text-xs">Download</a>`
                            : ''}
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function renderPagination(res) {
        const { current_page, last_page, from, to, total } = res.meta;
        const { prev, next } = res.links;

        if (last_page <= 1) { paginEl.innerHTML = ''; return; }

        paginEl.innerHTML = `
            <span class="text-gray-500 text-xs">Showing ${from}–${to} of ${total}</span>
            <div class="flex gap-2">
                <button onclick="window._loadPage(${current_page - 1})"
                    class="px-3 py-1 rounded border text-xs ${prev ? 'hover:bg-gray-50 border-gray-300' : 'opacity-40 cursor-not-allowed border-gray-200'}"
                    ${prev ? '' : 'disabled'}>
                    &laquo; Prev
                </button>
                <span class="px-3 py-1 text-xs text-gray-600">Page ${current_page} / ${last_page}</span>
                <button onclick="window._loadPage(${current_page + 1})"
                    class="px-3 py-1 rounded border text-xs ${next ? 'hover:bg-gray-50 border-gray-300' : 'opacity-40 cursor-not-allowed border-gray-200'}"
                    ${next ? '' : 'disabled'}>
                    Next &raquo;
                </button>
            </div>
        `;
    }

    window._loadPage = function (page) {
        clearInterval(pollTimer);
        watching.clear();
        show(loadingEl, true);
        show(tableEl, false);
        show(emptyEl, false);
        loadUploads(page);
    };

    // ── Polling ───────────────────────────────────────────────────────────────

    function startPolling(uploads) {
        clearInterval(pollTimer);
        watching = new Set(
            uploads
                .filter(u => ['pending', 'uploading', 'processing'].includes(u.status))
                .map(u => u.upload_id)
        );
        if (watching.size === 0) return;

        pollTimer = setInterval(pollAll, POLL_MS);
    }

    async function pollAll() {
        if (watching.size === 0) { clearInterval(pollTimer); return; }

        for (const uploadId of [...watching]) {
            try {
                const res    = await apiFetch(`/api/v1/upload/${uploadId}/status`);
                const upload = res.data;
                updateRow(uploadId, upload);
                if (upload.status === 'completed' || upload.status === 'failed' || upload.status === 'cancelled') {
                    watching.delete(uploadId);
                }
            } catch (_) {}
        }
    }

    function updateRow(uploadId, data) {
        const row = document.getElementById(`row-${uploadId}`);
        if (!row) return;

        row.querySelector('.status-badge').outerHTML = badgeHtml(data.status);

        if (data.status === 'completed') {
            const actions = row.querySelector('.actions');
            if (actions && !actions.querySelector('.download-btn')) {
                actions.insertAdjacentHTML('beforeend',
                    `<a href="/api/v1/admin/${uploadId}/download" class="download-btn text-green-600 hover:text-green-800 font-medium text-xs">Download</a>`
                );
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function badgeHtml(status) {
        const color = badgeColors[status] || 'bg-gray-100 text-gray-700';
        return `<span class="status-badge inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${color}">
            ${cap(status)}${status === 'processing' ? spinnerSvg : ''}
        </span>`;
    }

    function cap(s) { return s.charAt(0).toUpperCase() + s.slice(1); }
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function show(el, visible) { el.classList.toggle('hidden', !visible); }

    async function apiFetch(url) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    loadUploads(1);
})();
</script>
@endsection
