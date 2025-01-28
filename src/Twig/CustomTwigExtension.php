<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CustomTwigExtension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('bin2hex', [$this, 'bin2hex']),
        ];
    }

    public function bin2hex($param)
    {
        return bin2hex($param);
    }
}
