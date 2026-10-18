<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketCommentService
{
    public function __construct(private TicketMutation $mutation) {}

    public function create(User $actor, int|string $ticketId, string $content): TicketComment
    {
        return $this->mutation->run($actor, $ticketId, function (Ticket $ticket, User $currentActor) use ($content): TicketComment {
            Gate::forUser($currentActor)->authorize('comment', $ticket);

            if ($ticket->status === 'closed') {
                throw ValidationException::withMessages([
                    'content' => ['Un ticket clôturé ne peut plus recevoir de commentaire.'],
                ]);
            }

            $validated = Validator::make(['content' => Str::trim($content)], [
                'content' => ['required', 'string', 'min:1', 'max:5000'],
            ], [
                'content.required' => 'Le commentaire ne peut pas être vide.',
                'content.max' => 'Le commentaire ne doit pas dépasser :max caractères.',
            ])->validate();

            $comment = $ticket->comments()->create([
                'author_id' => $currentActor->getKey(),
                'content' => $validated['content'],
            ]);

            $this->mutation->record($ticket, $currentActor, 'comment_added', [], [
                'comment_id' => $comment->getKey(),
            ]);

            return $comment;
        });
    }
}
