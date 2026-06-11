<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;

beforeEach(function () {
    $this->inviter = User::factory()->create(['name' => 'Ina Inviter']);
    $this->team = Team::factory()->create(['name' => 'Notified Practice']);
    $this->team->members()->attach($this->inviter, ['role' => TeamRole::Owner->value]);

    $this->invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Staff,
        'invited_by' => $this->inviter->id,
    ]);

    $this->notification = new TeamInvitationNotification($this->invitation);
});

test('the invitation notification is queued and sent by mail', function () {
    expect($this->notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($this->notification->via(new AnonymousNotifiable))->toBe(['mail']);
});

test('the invitation mail renders the team, inviter, and login link', function () {
    $mail = $this->notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toContain('Notified Practice');

    $rendered = $mail->render()->toHtml();

    expect($rendered)->toContain('Ina Inviter')
        ->toContain('Notified Practice')
        ->toContain(route('login', ['invitation' => $this->invitation->code]));
});

test('the invitation notification array payload identifies the invitation', function () {
    expect($this->notification->toArray(new AnonymousNotifiable))->toBe([
        'invitation_id' => $this->invitation->id,
        'team_id' => $this->team->id,
        'team_name' => 'Notified Practice',
        'role' => TeamRole::Staff->value,
    ]);
});
