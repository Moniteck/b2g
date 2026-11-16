<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\TrainingProgressRequest;
use App\Models\TrainingProgress;
use App\Support\Permissions;
use App\Support\Tools\TrainingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Capacitación: cursos de lectura con progreso por usuario.
 */
class TrainingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permissions::gate(Permissions::VIEW));

        $progress = TrainingProgress::query()->where('user_id', $request->user()->id)
            ->whereIn('tutorial_key', TrainingCatalog::keys())
            ->get(['tutorial_key', 'progress', 'updated_at'])->keyBy('tutorial_key');

        $courses = collect(TrainingCatalog::all())->map(function (array $course) use ($progress) {
            $record = $progress->get($course['key']);
            $count = count($course['lessons']);
            $percent = (int) ($record?->progress ?? 0);

            return $course + [
                'progress' => $percent,
                'completed_lessons' => min($count, (int) round($percent / 100 * $count)),
                'updated_at' => $record?->updated_at?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('Training/Index', ['courses' => $courses]);
    }

    /**
     * Guarda cuántas lecciones del curso completó el usuario. Es idempotente.
     */
    public function update(TrainingProgressRequest $request): RedirectResponse
    {
        $this->authorize(Permissions::gate(Permissions::VIEW));

        $data = $request->validated();
        $course = TrainingCatalog::find($data['tutorial_key']);
        $count = count($course['lessons']);
        $done = min($count, (int) $data['completed_lessons']);

        TrainingProgress::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'tutorial_key' => $course['key']],
            ['progress' => $count > 0 ? (int) round($done / $count * 100) : 0],
        );

        return back()->with('success', $done >= $count ? "Completaste el curso «{$course['title']}»." : 'Progreso guardado.');
    }
}
