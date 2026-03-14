# Upload flow (high level)

**At a glance:** Browser (init → chunks → finalize) → Controller/ChunkUploadService → Queue: ProcessVideoUpload (assemble) → UploadVideoToS3 (S3, cleanup, email) → Browser polls status.

How the file moves from browser to S3:

1. **Browser** — User picks a file. JS splits it into chunks (size from config), then:
   - **Init** — Sends filename, file size, total chunks (optional upload_id to resume). UploadController → ChunkUploadService::initOrResume → creates or resumes a VideoUpload (pending), returns upload_id and received_chunks.
   - **Chunks** — Sends each chunk (upload_id, chunk_index, file). UploadController::storeChunk → ChunkUploadService::storeChunk → saves chunk to local disk, records in video_upload_chunks, increments received_chunks.
   - **Finalize** — Sends upload_id. UploadController::finalize → ChunkUploadService::finalize → verifies all chunks received, sets status to processing, dispatches ProcessVideoUpload job.

2. **Queue (uploads)** — ProcessVideoUpload runs: VideoAssemblyService::assemble stitches chunks into one file on disk, then dispatches UploadVideoToS3.

3. **Queue (uploads)** — UploadVideoToS3 runs: VideoAssemblyService::uploadToS3 sends the assembled file to S3 (currently a single put); for higher scalability this could be switched to S3 multipart upload. Then upload is marked completed with s3_path, VideoAssemblyService::cleanup removes local chunks and assembled file, and the admin notification email is queued.

4. **Browser** — Polls status until completed or failed, then redirects or shows error.

**Other:** Cancel deletes chunks and upload record. Abandon marks an in-progress upload as abandoned when the user leaves the page.
