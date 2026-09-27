<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * The unsubscribe link in lifecycle emails. The URL is signed, so possessing
 * it is the authorization — the recipient is usually not logged in when they
 * click it, and mail clients send the one-click POST with no session at all.
 */
class EmailPreferenceController extends Controller
{
    public function unsubscribe(User $user): Response
    {
        if ($user->lifecycle_emails_opted_out_at === null) {
            $user->forceFill(['lifecycle_emails_opted_out_at' => now()])->save();
        }

        if (request()->isMethod('post')) {
            return response()->noContent();
        }

        return response()->view('emails.unsubscribed');
    }
}
