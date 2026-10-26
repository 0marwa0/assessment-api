<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * R12: one notification class for every Academy event (bell + email).
 *
 * The in-app row shows "<b>title</b> text" with a meta line under it; the
 * email uses the same branded template for every event (design N-Panel §2).
 * Emails respect the user's email_notifications setting and a daily cap.
 */
class AcademyNotification extends Notification
{
    /** "One email per event, never more than [n] a day." */
    public const DAILY_EMAIL_CAP = 6;

    /**
     * @param  array{subject: string, heading: string, card_title: string, card_text?: string|null, button?: string}|null  $email
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $text,
        public ?string $meta,
        public string $link,
        public ?array $email = null,
        public bool $viaApp = true,
        public bool $viaEmail = true,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = $this->viaApp ? ['database'] : [];

        if ($this->viaEmail && $this->email !== null && $notifiable->email_notifications !== false && filled($notifiable->email)) {
            $key = 'academy-emails:'.$notifiable->id.':'.now()->toDateString();
            $sent = (int) Cache::get($key, 0);
            if ($sent < self::DAILY_EMAIL_CAP) {
                Cache::put($key, $sent + 1, now()->endOfDay());
                $channels[] = 'mail';
            }
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'text' => $this->text,
            'meta' => $this->meta,
            'link' => $this->link,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $email = $this->email ?? [];
        $base = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject($email['subject'] ?? $this->title)
            ->view('mail.academy', [
                'heading' => $email['heading'] ?? $this->title,
                'cardTitle' => $email['card_title'] ?? $this->text,
                'cardText' => $email['card_text'] ?? $this->meta,
                'button' => $email['button'] ?? 'Open in Academy',
                'url' => $base.$this->link,
                'company' => config('app.name'),
            ]);
    }
}
