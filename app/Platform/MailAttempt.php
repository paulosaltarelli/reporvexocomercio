<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

final class MailAttempt extends Model
{
    protected $connection = 'central';
    protected $table = 'platform_mail_attempts';
    protected $fillable = ['tenant_id', 'company_name', 'recipient', 'subject', 'type', 'source_id', 'status', 'finished_at'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public static function begin(int $tenantId, string $companyName, string $recipient, string $subject, string $type, int $sourceId): self
    {
        return self::create([
            'tenant_id' => $tenantId,
            'company_name' => $companyName,
            'recipient' => $recipient,
            'subject' => $subject,
            'type' => $type,
            'source_id' => $sourceId,
            'status' => 'pending',
        ]);
    }

    public function finish(bool $sent): void
    {
        $this->update(['status' => $sent ? 'sent' : 'failed', 'finished_at' => now()]);
    }
}
