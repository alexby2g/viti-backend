<?php

namespace Tests\Unit;

use App\Models\{Conversacion,Mensaje};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ConversationMessageOrderTest extends TestCase
{
    public function test_loaded_messages_are_sorted_by_creation_time_and_id(): void
    {
        $conversation = new Conversacion();

        $late = new Mensaje(['mensaje' => '18:28']);
        $late->id = 10;
        $late->created_at = Carbon::parse('2026-08-10 18:28:00');

        $early = new Mensaje(['mensaje' => '09:31']);
        $early->id = 30;
        $early->created_at = Carbon::parse('2026-08-10 09:31:00');

        $sameMinuteFirst = new Mensaje(['mensaje' => '10:03 A']);
        $sameMinuteFirst->id = 40;
        $sameMinuteFirst->created_at = Carbon::parse('2026-08-11 10:03:00');

        $sameMinuteSecond = new Mensaje(['mensaje' => '10:03 B']);
        $sameMinuteSecond->id = 41;
        $sameMinuteSecond->created_at = Carbon::parse('2026-08-11 10:03:00');

        $nextMinute = new Mensaje(['mensaje' => '10:04']);
        $nextMinute->id = 50;
        $nextMinute->created_at = Carbon::parse('2026-08-11 10:04:00');

        $conversation->setRelation('mensajes', new Collection([
            $late,
            $nextMinute,
            $sameMinuteSecond,
            $early,
            $sameMinuteFirst,
        ]));

        $this->assertSame([30, 10, 40, 41, 50], $conversation->mensajes->pluck('id')->all());
    }
}
