<?php

namespace App\Http\Resources;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailLog
 */
class EmailLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'receiver_id' => $this->receiver_id,
            'receiver_name' => $this->whenLoaded('receiver', fn () => $this->receiver?->name),
            'receiver_email' => $this->whenLoaded('receiver', fn () => $this->receiver?->email),
            'type' => $this->type,
            'subject' => $this->subject,
            // The exact rendered HTML that was (or would have been) sent —
            // see the model's own docblock. Included even in the paginated
            // list (not gated behind a separate show()) so
            // ResendEmailModal.vue's editable body field never needs a
            // second round trip — same reasoning AuditResource includes
            // old_values/new_values unconditionally.
            'body' => $this->body,
            'is_sent' => (bool) $this->is_sent,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
