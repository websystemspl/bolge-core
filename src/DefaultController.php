<?php
declare(strict_types = 1);

namespace Websystems\BolgeCore;

use Websystems\BolgeCore\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default controller is for avoid symfony notification
 */
class DefaultController extends Controller
{
    public function defaultAction()
    {
        return new Response();
    }
}
