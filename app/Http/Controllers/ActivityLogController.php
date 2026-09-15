<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    /**
     * Mostrar el registro de actividades.
     */
    public function index()
    {
        $activities = ActivityLog::with('user')
            ->latest()
            ->paginate(20);

        return view('activity_logs.index', compact('activities'));
    }

    /**
     * Mostrar el detalle de una actividad.
     */
    public function show(ActivityLog $activityLog)
    {
        $activityLog->load('user');

        return view('activity_logs.show', compact('activityLog'));
    }
}