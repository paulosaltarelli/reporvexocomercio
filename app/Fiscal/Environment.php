<?php

namespace App\Fiscal;

enum Environment: string
{
    case Homologation = 'homologation';
    case Production = 'production';
}
