<?php

namespace App\Http\Middleware;

use App\Services\Channels\NotificationChannels;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an administrator pick the channels (push, e-mail, SMS) for the task they are performing: any request may carry
 * `notify_channels` and every notification raised while it runs follows that choice. Without it, the defaults apply.
 */
class ChooseNotifyChannels
{
    public function handle(Request $request, Closure $next): Response
    {
        $picked = $request->input('notify_channels');
        if (is_array($picked)) {
            app(NotificationChannels::class)->choose(array_values(array_filter($picked, 'is_string')));
        }

        return $next($request);
    }
}
