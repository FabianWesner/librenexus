<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued appointment reminder for the customer (FR-COMMS-3, Epic 08).
 * Mirrors the other appointment mailables: details are captured as scalars
 * at dispatch time because queue workers have no tenant context
 * (SEC-TENANT); replies go to the tenant when it has a contact email.
 * Reminders carry no manage link: the raw token is never stored
 * (SEC-TOKEN-1), so the mail points the customer at the link from their
 * confirmation email instead (docs/assumptions.md §Emails).
 */
class AppointmentReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $teamName;

    public ?string $teamContactEmail;

    public string $serviceName;

    public string $staffName;

    public string $localStartsAt;

    public string $timezone;

    public string $customerName;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment)
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
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: your appointment at :team', ['team' => $this->teamName]),
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
            markdown: 'mail.appointments.reminder',
        );
    }
}
