<?php

namespace App\Mail;

use App\Models\VideoUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoUploadedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public VideoUpload $upload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Video Upload Completed: '.$this->upload->original_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.video-uploaded',
        );
    }
}
