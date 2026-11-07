<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice, ?string $locale = null)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
        $this->locale($locale ?? 'nl');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;
        $amount = number_format($invoice->gross_cents / 100, 2, ',', '.');

        $this->invoice->forceFill(['emailed_at' => now()])->save();

        return (new MailMessage)
            ->subject(__('Your ReviewPilot invoice :number', ['number' => $invoice->number]))
            ->line(__('Thank you for your payment of € :amount. Your invoice is attached.', ['amount' => $amount]))
            ->action(__('View invoices'), route('billing.index'))
            ->attachData(app(InvoiceService::class)->pdf($invoice), $invoice->filename(), ['mime' => 'application/pdf']);
    }
}
