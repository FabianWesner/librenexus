<?php

namespace App\Mail;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued booking confirmation for the customer (FR-BOOK-4, Epic 06). All
 * appointment details are captured as scalars at dispatch time, while the
 * tenant context of the booking request is still active; the queue worker
 * has no tenant context, so tenant-scoped models must not be (de)serialized
 * here (SEC-TENANT). The manage link carries the raw token, which is never
 * stored anywhere else (SEC-TOKEN-1). The from address stays the
 * application default; replies go to the tenant when it has a contact email.
 */
class AppointmentConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $teamName;

    public ?string $teamContactEmail;

    public string $serviceName;

    public string $staffName;

    public string $localStartsAt;

    public string $timezone;

    public string $customerName;

    public bool $isPendingApproval;

    public string $manageUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, string $rawManageToken)
    {
        $team = $appointment->team;

        $this->teamName = $team->name;
        $this->teamContactEmail = $team->contact_email;
        $this->serviceName = $appointment->service->name;
        $this->staffName = $appointment->staff->name;
        $this->localStartsAt = $appointment->starts_at
            ->setTimezone($team->timezone)
            ->isoFormat('dddd, MMMM D, YYYY [at] HH:mm');
        $this->timezone = $team->timezone;
        $this->customerName = $appointment->customer->name;
        $this->isPendingApproval = $appointment->status === AppointmentStatus::Pending;
        $this->manageUrl = route('booking.manage', ['token' => $rawManageToken]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isPendingApproval
                ? __('Your appointment request at :team', ['team' => $this->teamName])
                : __('Your appointment at :team is confirmed', ['team' => $this->teamName]),
            replyTo: $this->teamContactEmail !== null
                ? [new Address($this->teamContactEmail, $this->teamName)]
                : [],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.appointments.confirmation',
        );
    }
}
