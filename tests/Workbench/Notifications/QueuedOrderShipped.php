<?php

namespace Workbench\App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedOrderShipped extends OrderShipped implements ShouldQueue
{
    use Queueable;
}
