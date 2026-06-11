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
 * Queued reschedule notice for the customer (FR-APPT-5, FR-COMMS-4,
 * Epic 08). Mirrors the other appointment mailables: details are captured
 * as scalars at dispatch time because queue workers have no tenant context
 * (SEC-TENANT); replies go to the tenant when it has a contact email. The
 * manage link is only included when the caller has the raw token (the
 * customer self-service path); the admin path has no raw token, so the
 * customer keeps the link from their confirmation email instead.
 */
class AppointmentRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $teamName;

    public ?string $teamContactEmail;

    public string $serviceName;

    public string $staffName;

    public string $localStartsAt;

    public string $timezone;

    public string $customerName;

    public ?string $manageUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, ?string $rawManageToken = null)
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
        $this->manageUrl = $rawManageToken === null
            ? null
            : route('booking.manage', ['token' => $rawManageToken]);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your appointment at :team was rescheduled', ['team' => $this->teamName]),
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
            markdown: 'mail.appointments.rescheduled',
        );
    }
}
