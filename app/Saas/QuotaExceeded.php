<?php

namespace App\Saas;

use Symfony\Component\HttpKernel\Exception\HttpException;

class QuotaExceeded extends HttpException
{
    public function __construct(string $what, int $limit)
    {
        parent::__construct(402, "Plan limit reached: {$limit} {$what}. Upgrade your plan to continue.");
    }
}
