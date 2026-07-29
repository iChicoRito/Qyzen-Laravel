<?php

namespace App\Http\Requests;

// Create and edit validate identically — kept as a distinct type so the controller signatures
// stay explicit (and so an edit-only rule has somewhere to live later).
class UpdateAnnouncementRequest extends StoreAnnouncementRequest {}
