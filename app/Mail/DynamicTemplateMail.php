<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DynamicTemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedHtmlBody,
        public string $storeName,
        public ?string $logoUrl = null,
        public string $primaryColor = '#1C1917',
        public ?string $footerText = null,
        public ?string $quoteNumber = null,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public bool $isFullDocument = false, // true = renderedHtmlBody is already a complete <html> doc
        public ?string $pdfContents = null,  // raw PDF bytes to attach, if any
        public ?string $pdfFilename = null,
    ) {}

    public function build(): self
    {
        $this->subject($this->renderedSubject);

        if ($this->pdfContents && $this->pdfFilename) {
            $this->attachData($this->pdfContents, $this->pdfFilename, [
                'mime' => 'application/pdf',
            ]);
        }

        // Merchant designed their own full HTML email (own header/footer/table
        // structure) - send it as-is, skip the shared layout wrapper entirely.
        if ($this->isFullDocument) {
            return $this->html($this->renderedHtmlBody);
        }

        return $this->view('emails.layout')->with([
            'subject' => $this->renderedSubject,
            'bodyHtml' => $this->renderedHtmlBody,
            'storeName' => $this->storeName,
            'logoUrl' => $this->logoUrl,
            'primaryColor' => $this->primaryColor,
            'footerText' => $this->footerText,
            'quoteNumber' => $this->quoteNumber,
            'actionUrl' => $this->actionUrl,
            'actionLabel' => $this->actionLabel,
        ]);
    }
}
