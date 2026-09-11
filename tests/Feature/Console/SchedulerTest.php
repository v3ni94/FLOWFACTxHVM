<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Scheduler-Definitionen aus routes/console.php (ADR-006).
 *
 * Prüfbericht 2026-09-11, Befund 8: runInBackground() kennt keinen Parameter
 * und schaltet die Hintergrundausführung immer ein; der Queue-Worker muss im
 * Vordergrund laufen.
 */
final class SchedulerTest extends TestCase
{
    private function event(string $befehl): Event
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, $befehl));

        self::assertCount(1, $events, 'Genau ein Scheduler-Eintrag für '.$befehl);

        return $events->first();
    }

    public function test_queue_worker_laeuft_jede_minute_im_vordergrund_ohne_ueberlappung(): void
    {
        $event = $this->event('queue:work');

        self::assertFalse($event->runInBackground, 'Der Worker darf nicht als Shell-Hintergrundprozess gestartet werden.');
        self::assertTrue($event->withoutOverlapping);
        self::assertSame('* * * * *', $event->expression);
        self::assertStringContainsString('--stop-when-empty', (string) $event->command);
        self::assertStringContainsString('--max-time=45', (string) $event->command);
        $shell = trim($event->buildCommand());
        self::assertStringNotContainsString('schedule:finish', $shell, 'Kein Hintergrundlauf mit schedule:finish.');
        self::assertFalse(str_ends_with($shell, '&'), 'Kein Hintergrundoperator am Ende des erzeugten Shellbefehls.');
    }

    public function test_kein_scheduler_eintrag_laeuft_im_hintergrund(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            self::assertFalse($event->runInBackground, 'Hintergrundausführung in: '.($event->command ?? $event->description ?? 'closure'));
        }
    }
}
