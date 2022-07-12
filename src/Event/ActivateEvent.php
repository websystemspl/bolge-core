<?php

namespace Websystems\BolgeCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

class ActivateEvent extends Event
{
    public const NAME = 'activate.event'; 
}