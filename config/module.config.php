<?php
$listeners = [Slack\Listener\SlackActivityListener::class];

return [
    'listeners' => $listeners,
    'service_manager' =>[
        'factories' => array_fill_keys(
            $listeners,
            Events\Listener\ListenerFactory::class
        )
    ],
    Events\Listener\ListenerFactory::EVENT_LISTENER_CONFIG => [
        Events\Listener\ListenerFactory::ALL => [
            Slack\Listener\SlackActivityListener::class => [
                [
                    Events\Listener\ListenerFactory::PRIORITY => Events\Listener\ListenerFactory::HANDLE_MAIL_PRIORITY + 1,
                    Events\Listener\ListenerFactory::CALLBACK => 'handleEvent',
                    Events\Listener\ListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
    ],
    'slack' => [
        'swarm_host' => 'https://swarm.yourcompany.tld/',
        'bot_token' => 'xoxb-2...',
        'channel' => 'C...'
    ]
];