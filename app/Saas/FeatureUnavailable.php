<?php

namespace App\Saas;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureUnavailable extends HttpException
{
    public function __construct(string $feature)
    {
        parent::__construct(402, 'Your plan does not include '.str_replace('_', ' ', $feature).'. Upgrade to unlock it.');
    }
}
