<?php

namespace App\Services;

use App\Mail\DynamicTemplateMail;
use App\Models\ShopEmailAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Talks to Google: OAuth (connect / refresh / revoke) and sending mail
 * through the Gmail API as the merchant's connected account.
 * Uses Laravel's HTTP client only - no extra composer packages needed.
 */
class GmailService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    private const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    /** Refresh the access token this many seconds before it actually expires. */
    private const REFRESH_BUFFER_SECONDS = 300;

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /** URL of Google's consent screen. */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',   // we need a refresh_token
            'prompt' => 'consent',        // always return the refresh_token
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the OAuth `code` for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            Log::error('Google token exchange failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('Could not connect to Google. Please try again.');
        }

        return $response->json();
    }

    /** Email address of the Google account that granted access. */
    public function fetchEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->timeout(20)->get(self::USERINFO_URL);
        $email = $response->json('email');

        if ($response->failed() || ! $email) {
            throw new RuntimeException('Could not read the email address of the Google account.');
        }

        return strtolower($email);
    }

    /** Returns a valid access token, refreshing it first when needed. */
    public function accessTokenFor(ShopEmailAccount $account, bool $force = false): string
    {
        if (! $force && ! $this->needsRefresh($account)) {
            return $account->access_token;
        }

        // refresh_token handling is not safe to run twice at once, so lock per account.
        return Cache::lock("gmail-refresh:{$account->id}", 15)->block(10, function () use ($account, $force) {
            $account->refresh();

            // Another request may have refreshed it while we waited for the lock.
            if (! $force && ! $this->needsRefresh($account)) {
                return $account->access_token;
            }

            return $this->refresh($account);
        });
    }

    private function needsRefresh(ShopEmailAccount $account): bool
    {
        return $account->expires_at === null
            || $account->expires_at->lte(now()->addSeconds(self::REFRESH_BUFFER_SECONDS));
    }

    private function refresh(ShopEmailAccount $account): string
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            $revoked = $response->json('error') === 'invalid_grant';

            Log::warning('Gmail token refresh failed', [
                'shop_id' => $account->shop_id,
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            if ($revoked) {
                $account->update([
                    'status' => ShopEmailAccount::STATUS_REAUTH_REQUIRED,
                    'last_error' => 'Google access was revoked or expired.',
                ]);

                throw new RuntimeException('Google access was revoked. Please reconnect your email account.');
            }

            throw new RuntimeException('Could not refresh the Google access token. Please try again.');
        }

        $account->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'status' => ShopEmailAccount::STATUS_ACTIVE,
            'last_error' => null,
        ]);

        return $account->access_token;
    }

    /**
     * Sends a rendered quote email (with optional PDF attachment) through
     * the merchant's Gmail account.
     */
    public function sendTemplateMail(ShopEmailAccount $account, string $recipient, DynamicTemplateMail $mail, ?string $fromName = null): void
    {
        $email = (new Email())
            ->from(new Address($account->email, $fromName ?: ''))
            ->to($recipient)
            ->subject($mail->renderedSubject)
            ->html($mail->render());

        if ($mail->pdfContents && $mail->pdfFilename) {
            $email->attach($mail->pdfContents, $mail->pdfFilename, 'application/pdf');
        }

        $this->send($account, $email);
    }

    public function send(ShopEmailAccount $account, Email $email): void
    {
        $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');

        $response = Http::withToken($this->accessTokenFor($account))
            ->timeout(30)
            ->post(self::SEND_URL, ['raw' => $raw]);

        // Token rejected -> force one refresh and retry once.
        if ($response->status() === 401) {
            $response = Http::withToken($this->accessTokenFor($account, true))
                ->timeout(30)
                ->post(self::SEND_URL, ['raw' => $raw]);
        }

        if ($response->failed()) {
            $status = $response->status();
            $message = (string) ($response->json('error.message') ?: $response->body());
            $reason = (string) ($response->json('error.errors.0.reason') ?: $response->json('error.details.0.reason'));

            Log::error('Gmail send failed', [
                'shop_id' => $account->shop_id,
                'status' => $status,
                'body' => $response->body(),
            ]);

            // The merchant unticked the "send email" permission on Google's consent screen.
            if ($status === 403 && (
                $reason === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'
                || $reason === 'insufficientPermissions'
                || stripos($message, 'insufficient authentication scopes') !== false
            )) {
                $account->update([
                    'status' => ShopEmailAccount::STATUS_REAUTH_REQUIRED,
                    'last_error' => 'The Gmail send permission was not granted.',
                ]);

                throw new RuntimeException('Gmail permission was not granted. Disconnect the account, connect it again and allow "Send email on your behalf".');
            }

            // Gmail API is not enabled in the Google Cloud project.
            if ($status === 403 && (
                $reason === 'accessNotConfigured'
                || $reason === 'SERVICE_DISABLED'
                || stripos($message, 'has not been used in project') !== false
                || stripos($message, 'is disabled') !== false
            )) {
                throw new RuntimeException('The Gmail API is not enabled in your Google Cloud project. Enable it under APIs & Services > Library and try again.');
            }

            throw new RuntimeException('Gmail could not send the email: ' . $message);
        }
    }

    /** Best-effort: tell Google to revoke the grant. Never throws. */
    public function revoke(ShopEmailAccount $account): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $account->refresh_token]);
        } catch (\Throwable $e) {
            Log::warning('Gmail token revoke failed', ['shop_id' => $account->shop_id, 'error' => $e->getMessage()]);
        }
    }
}
