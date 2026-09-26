<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Actions\BumpRevisionAction;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;

/**
 * Bumps the parent calendar's revision whenever a definition row changes
 * directly (admin panels, raw Eloquent), so cached definitions roll over.
 * Actions suppress this and bump exactly once themselves.
 *
 * @phpstan-require-extends Model
 */
trait BumpsCalendarRevision
{
    public static function bootBumpsCalendarRevision(): void
    {
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static function (Model $model): void {
                if (RevisionGuard::suppressed() || ! method_exists($model, 'bumpCalendarRevision')) {
                    return;
                }

                $model->bumpCalendarRevision();
            });
        }
    }

    public function bumpCalendarRevision(): void
    {
        $calendarId = $this->calendarIdForRevision();

        if ($calendarId !== null) {
            app(BumpRevisionAction::class)->execute($calendarId);
        }
    }

    abstract protected function calendarIdForRevision(): ?int;
}
