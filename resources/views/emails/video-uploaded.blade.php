<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Video Upload Completed</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 30px; }
        .card { background: #fff; border-radius: 8px; max-width: 560px; margin: 0 auto; padding: 32px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        h1 { font-size: 20px; color: #111827; margin-top: 0; }
        .label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
        .value { font-size: 14px; color: #111827; margin-bottom: 16px; word-break: break-all; }
        .badge { display: inline-block; background: #d1fae5; color: #065f46; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
        .btn { display: inline-block; margin-top: 24px; padding: 10px 22px; background: #4f46e5; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .footer { margin-top: 32px; font-size: 12px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
<div class="card">
    <h1>Video Upload Completed</h1>
    <p style="color:#6b7280;font-size:14px;">A new video has been successfully processed and stored in S3.</p>

    <div class="label">Filename</div>
    <div class="value">{{ $upload->original_name }}</div>

    <div class="label">File Size</div>
    <div class="value">{{ $upload->file_size_human ?? '—' }}</div>

    <div class="label">Completed At</div>
    <div class="value">{{ $upload->updated_at->format('M d, Y \a\t H:i:s T') }}</div>

    <div class="label">Status</div>
    <div class="value"><span class="badge">Completed</span></div>
</div>
<div class="footer">This is an automated notification from {{ config('app.name') }}.</div>
</body>
</html>
