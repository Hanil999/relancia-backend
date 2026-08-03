<?php

namespace App\Mail;

use App\Models\Facture;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class FactureMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Facture $facture)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Votre facture {$this->facture->numero}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.facture',
            with: ['facture' => $this->facture->load('commande.client', 'commande.items')],
        );
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromStorageDisk('public', $this->facture->chemin_pdf)
                ->as("{$this->facture->numero}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
