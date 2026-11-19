<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationConversation extends Model
{
    protected $fillable = ['last_message_at'];

    protected function casts(): array
    {
        return [
            'applicant_read_through_id' => 'integer',
            'provider_read_through_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ApplicationMessage::class);
    }

    public function readCursor(string $side): int
    {
        return $this->{self::readCursorColumn($side)};
    }

    public static function unreadMessageFilter(string $side): Closure
    {
        return fn (Builder $query) => $query->where('sender_side', self::incomingSide($side))
            ->whereColumn('application_messages.id', '>', 'application_conversations.'.self::readCursorColumn($side));
    }

    public function unreadMessages(string $side): Builder
    {
        return $this->messages()->getQuery()
            ->where('sender_side', self::incomingSide($side))
            ->where('id', '>', $this->readCursor($side));
    }

    public function advanceReadCursor(string $side, int $messageId): void
    {
        if ($messageId > $this->readCursor($side)) {
            $this->forceFill([self::readCursorColumn($side) => $messageId])->save();
        }
    }

    private static function incomingSide(string $side): string
    {
        return $side === ApplicationMessage::PROVIDER ? ApplicationMessage::APPLICANT : ApplicationMessage::PROVIDER;
    }

    private static function readCursorColumn(string $side): string
    {
        return $side === ApplicationMessage::PROVIDER ? 'provider_read_through_id' : 'applicant_read_through_id';
    }
}
