<?php

return [
    // A document becomes idle when no routing or processing action is recorded for this many days.
    'idle_notification_days' => env('DOCUMENT_IDLE_NOTIFICATION_DAYS', 3),
];
