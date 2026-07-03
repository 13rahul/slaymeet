<?php
declare(strict_types=1);

return [
    'id' => 'slaymeet',
    'label' => 'SlayMeet',
    'api_prefix' => '/api/slaymeet/',
    'dashboard' => ['/meet.php'],
    'env' => ['LIVEKIT_URL', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET'],
];
