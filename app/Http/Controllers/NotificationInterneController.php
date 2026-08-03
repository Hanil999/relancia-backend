<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Entreprise;
use App\Models\NotificationInterne;
use Illuminate\Http\Request;

class NotificationInterneController extends Controller
{
    public function index(Request $request, Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $notifications = $entreprise->notificationsInternes()
            ->when($request->boolean('non_lues'), fn ($q) => $q->whereNull('lu_le'))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return NotificationResource::collection($notifications);
    }

    public function marquerLue(Entreprise $entreprise, NotificationInterne $notification)
    {
        $this->authorize('voirCommandes', $entreprise);
        abort_if($notification->entreprise_id !== $entreprise->id, 404);

        $notification->update(['lu_le' => now()]);

        return new NotificationResource($notification);
    }

    public function marquerToutesLues(Entreprise $entreprise)
    {
        $this->authorize('voirCommandes', $entreprise);

        $entreprise->notificationsInternes()->whereNull('lu_le')->update(['lu_le' => now()]);

        return response()->json(['message' => 'Notifications marquées comme lues.']);
    }
}
